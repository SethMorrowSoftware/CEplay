/**
 * Go-Kart Labor page — compares sales vs labor cost per selected day,
 * pulled live from the venue's CenterEdge MSSQL database.
 *
 * Pick up to 14 days; each renders as a row with Sales, Labor, and the
 * labor rate (labor ÷ sales) plus a bar so out-of-line days jump out.
 * Admins (settings permission) also see the connection / query editor here
 * — kept on this page rather than Settings so the report and its plumbing
 * live together.
 */
(function() {
    'use strict';

    var state = {
        dates: [],       // selected ISO dates, newest first
        data: null,      // last /labor/rate payload
        settings: null,  // /labor/settings payload (admins only)
        loading: false
    };

    function todayISO() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function shiftDays(iso, delta) {
        var d = new Date(iso + 'T12:00:00');
        d.setDate(d.getDate() + delta);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function fmtMoney(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtPct(v) {
        if (v == null) return '—';
        return (v * 100).toFixed(1) + '%';
    }

    function dayLabel(iso) {
        var d = new Date(iso + 'T12:00:00');
        return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    }

    // ------------------------------------------------------------------
    async function renderLabor(container) {
        // Default view: the last 7 days — the "how are we doing?" answer
        // with zero clicks.
        state.dates = [];
        for (var i = 0; i < 7; i++) state.dates.push(shiftDays(todayISO(), -i));
        state.data = null;

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Go-Kart Labor' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'How much of each day’s go-kart money goes to staff wages. Lower is better — under 25% is great, over 40% deserves a look.' })
            ])
        ]));

        var picker = App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-body', id: 'labor-picker' })
        ]);
        container.appendChild(picker);

        container.appendChild(App.el('div', { id: 'labor-results' }));
        container.appendChild(App.el('div', { id: 'labor-admin' }));

        renderPicker();
        load();
        if (App.canAccess('settings')) loadAdmin();
    }

    // ------------------------------------------------------------------
    // Date picker: chips for chosen days + input to add more
    // ------------------------------------------------------------------
    function renderPicker() {
        var box = document.getElementById('labor-picker');
        if (!box) return;
        box.innerHTML = '';

        var chips = App.el('div', { className: 'labor-chiprow' });
        state.dates.forEach(function(d) {
            chips.appendChild(App.el('span', { className: 'labor-chip' }, [
                App.el('span', { textContent: dayLabel(d) + ' (' + d + ')' }),
                App.el('button', {
                    className: 'labor-chip-x', title: 'Remove ' + d, 'aria-label': 'Remove ' + d,
                    textContent: '×',
                    onClick: function() {
                        state.dates = state.dates.filter(function(x) { return x !== d; });
                        renderPicker(); load();
                    }
                })
            ]));
        });

        var input = App.el('input', { className: 'form-input', type: 'date', style: { maxWidth: '11rem' } });
        var addBtn = App.el('button', {
            className: 'btn btn-secondary btn-sm', textContent: '+ Add day',
            onClick: function() {
                var v = input.value;
                if (!v) return;
                if (state.dates.indexOf(v) !== -1) { App.toast('That day is already selected.', 'info'); return; }
                if (state.dates.length >= 14) { App.toast('At most 14 days at a time.', 'warning'); return; }
                state.dates.push(v);
                state.dates.sort().reverse();
                renderPicker(); load();
            }
        });

        // One-click comparison starters
        var presets = App.el('div', { className: 'labor-presets' }, [
            presetBtn('Last 7 days', function() {
                var out = [], t = todayISO();
                for (var i = 0; i < 7; i++) out.push(shiftDays(t, -i));
                return out;
            }),
            presetBtn('Last 4 Saturdays', function() {
                var out = [], d = new Date();
                d.setDate(d.getDate() - ((d.getDay() + 1) % 7)); // most recent Saturday
                for (var i = 0; i < 4; i++) { out.push(d.toISOString().slice(0, 10)); d.setDate(d.getDate() - 7); }
                return out;
            }),
            presetBtn('Today vs last week', function() {
                var t = todayISO();
                return [t, shiftDays(t, -7)];
            })
        ]);

        box.appendChild(App.el('div', { className: 'labor-picker-row' }, [chips, input, addBtn]));
        box.appendChild(presets);
    }

    function insightCard(emoji, cls, label, value, sub) {
        return App.el('div', { className: 'insight-card ' + cls }, [
            App.el('div', { className: 'insight-icon', 'aria-hidden': 'true', textContent: emoji }),
            App.el('div', { className: 'insight-body' }, [
                App.el('div', { className: 'insight-label', textContent: label }),
                App.el('div', { className: 'insight-value', textContent: value }),
                App.el('div', { className: 'insight-sub', textContent: sub })
            ])
        ]);
    }

    function presetBtn(label, datesFn) {
        return App.el('button', {
            className: 'btn btn-ghost btn-sm', textContent: label,
            onClick: function() {
                state.dates = datesFn().slice(0, 14);
                renderPicker(); load();
            }
        });
    }

    // ------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------
    async function load() {
        var box = document.getElementById('labor-results');
        if (!box) return;
        if (!state.dates.length) { box.innerHTML = ''; return; }
        box.innerHTML = '';
        box.appendChild(App.loading());
        try {
            var data = await API.get('labor/rate?dates=' + encodeURIComponent(state.dates.join(',')));
            state.data = data;
            renderResults(data);
        } catch (err) {
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'Could not load labor data: ' + (err && err.message ? err.message : 'unknown error') })
                ])
            ]));
        }
    }

    function renderResults(data) {
        var box = document.getElementById('labor-results');
        if (!box) return;
        box.innerHTML = '';

        if (!data.configured) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('h3', { textContent: 'Not connected yet' }),
                    App.el('p', { className: 'text-secondary', style: { marginTop: '0.4rem' }, textContent:
                        App.canAccess('settings')
                            ? 'Enter the MSSQL connection below, test it, and this report comes alive.'
                            : 'The MSSQL connection has not been configured. Ask an administrator to set it up on this page.' })
                ])
            ]));
            return;
        }

        var days = data.days || [];
        var errors = days.filter(function(d) { return d.error; });
        var good = days.filter(function(d) { return !d.error; });

        if (errors.length) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: '⚠ ' + errors[0].error })
                ])
            ]));
            if (!good.length) return;
        }

        // Bar scale: worst (highest) labor rate = full width
        var maxRate = 0;
        good.forEach(function(d) { if (d.rate != null && d.rate > maxRate) maxRate = d.rate; });

        var showRides = good.some(function(d) { return d.rides != null; });
        var headCells = [App.el('th', { textContent: 'Day' })];
        if (showRides) {
            headCells.push(App.el('th', { textContent: 'Rides', title: 'Total kart swipes from the reader feed' }));
            headCells.push(App.el('th', { textContent: 'Pass', title: 'Time-pass swipes — omitted from the sales value' }));
        }
        headCells.push(App.el('th', { textContent: 'Sales' }),
                       App.el('th', { textContent: 'Labor' }),
                       App.el('th', { textContent: 'Labor rate' }),
                       App.el('th', { textContent: '' }));

        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [App.el('tr', {}, headCells)]),
            App.el('tbody', {}, good.map(function(d) {
                var pctW = (d.rate != null && maxRate > 0) ? Math.max(4, Math.round(d.rate / maxRate * 100)) : 0;
                var rateClass = d.rate == null ? '' : (d.rate <= 0.25 ? 'labor-rate-good' : (d.rate <= 0.4 ? 'labor-rate-warn' : 'labor-rate-bad'));
                var cells = [
                    App.el('td', {}, [App.el('strong', { textContent: dayLabel(d.date) }),
                                      App.el('span', { className: 'text-muted text-xs', textContent: ' ' + d.date })])
                ];
                if (showRides) {
                    cells.push(App.el('td', { textContent: d.rides != null ? String(d.rides) : '—' }));
                    cells.push(App.el('td', {}, [App.el('span', { className: 'text-muted', textContent: d.pass_rides != null ? String(d.pass_rides) : '—' })]));
                }
                cells.push(
                    App.el('td', { title: d.cash != null ? ('includes ' + fmtMoney(d.cash) + ' walk-up cash') : '' , textContent: fmtMoney(d.sales) }),
                    App.el('td', { textContent: fmtMoney(d.labor) }),
                    App.el('td', {}, [App.el('span', { className: 'labor-rate ' + rateClass, textContent: fmtPct(d.rate) })]),
                    App.el('td', { style: { width: '26%' } }, [
                        App.el('div', { className: 'labor-bar-track' }, [
                            App.el('div', { className: 'labor-bar', style: { width: pctW + '%' } })
                        ])
                    ])
                );
                return App.el('tr', {}, cells);
            }))
        ]);

        var avg = null;
        var sumSales = 0, sumLabor = 0, sumRides = 0, haveRides = false;
        good.forEach(function(d) {
            sumSales += d.sales || 0; sumLabor += d.labor || 0;
            if (d.rides != null) { haveRides = true; sumRides += d.rides; }
        });
        if (sumSales > 0) avg = sumLabor / sumSales;

        // Plain-language summary for a non-technical reader: the period
        // rate, the day that worked best, the day that needs a look, and
        // what one ride costs in wages.
        var summary = null;
        var rated = good.filter(function(d) { return d.rate != null; });
        if (rated.length >= 2) {
            var best = rated.reduce(function(a, b) { return b.rate < a.rate ? b : a; });
            var worst = rated.reduce(function(a, b) { return b.rate > a.rate ? b : a; });
            var cards = [
                insightCard('🏁', avg != null && avg <= 0.4 ? (avg <= 0.25 ? 'insight-good' : 'insight-accent') : 'insight-warn',
                    'This period', avg != null ? fmtPct(avg) + ' labor rate' : '—',
                    fmtMoney(sumLabor) + ' in wages earned ' + fmtMoney(sumSales) + ' in go-kart sales'),
                insightCard('✅', 'insight-good', 'Best day',
                    dayLabel(best.date) + ' — ' + fmtPct(best.rate),
                    fmtMoney(best.sales) + ' sales vs ' + fmtMoney(best.labor) + ' wages'),
                insightCard('⚠️', 'insight-warn', 'Needs a look',
                    dayLabel(worst.date) + ' — ' + fmtPct(worst.rate),
                    fmtMoney(worst.sales) + ' sales vs ' + fmtMoney(worst.labor) + ' wages')
            ];
            if (haveRides && sumRides > 0) {
                cards.push(insightCard('🏎️', 'insight-quiet', 'Wages per ride',
                    fmtMoney(sumLabor / sumRides),
                    'across ' + sumRides.toLocaleString() + ' rides (passes included)'));
            }
            summary = App.el('div', { className: 'insight-row' }, cards);
        }

        if (summary) box.appendChild(summary);

        // Legend in words, so the colors never need explaining in person.
        var legend = App.el('span', { className: 'text-sm labor-legend' }, [
            App.el('span', { className: 'labor-rate labor-rate-good', textContent: 'under 25% great' }),
            App.el('span', { className: 'text-muted', textContent: ' · ' }),
            App.el('span', { className: 'labor-rate labor-rate-warn', textContent: '25–40% watch' }),
            App.el('span', { className: 'text-muted', textContent: ' · ' }),
            App.el('span', { className: 'labor-rate labor-rate-bad', textContent: 'over 40% high' })
        ]);

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { textContent: 'Labor rate by day' }),
                legend
            ]),
            App.el('div', { className: 'card-body', style: { overflowX: 'auto' } }, [table,
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Labor rate = wages ÷ go-kart sales for the same day. '
                    + (state.data && state.data.ride_valuation && state.data.ride_valuation.add_ride_value
                        ? 'Sales are estimated as paid rides × each track’s price (default ' + fmtMoney(state.data.ride_valuation.price_per_ride) + ') plus the MSSQL query; time-pass swipes are not counted as money. '
                        : 'Sales are the real dollars guests spent at the kart readers (time passes never post money there). Rides and Pass show how busy the track was. ')
                    + 'Today includes staff currently on the clock. A punch that was never clocked out counts zero on past days — fix it in CenterEdge and the day recalculates.' })
            ])
        ]));
    }

    // ------------------------------------------------------------------
    // Admin: connection + query editor (settings permission only)
    // ------------------------------------------------------------------
    async function loadAdmin() {
        try {
            state.settings = await API.get('labor/settings');
            renderAdmin();
        } catch (err) {
            console.error('labor settings load failed:', err);
        }
    }

    function renderAdmin() {
        var box = document.getElementById('labor-admin');
        if (!box || !state.settings) return;
        var s = state.settings;
        box.innerHTML = '';

        var hostIn = App.el('input', { className: 'form-input', value: s.host || '', placeholder: '192.168.1.2' });
        var portIn = App.el('input', { className: 'form-input', value: s.port || '1433', style: { maxWidth: '7rem' } });
        var dbIn   = App.el('input', { className: 'form-input', value: s.database || 'CenterEdge' });
        var userIn = App.el('input', { className: 'form-input', value: s.username || '', placeholder: 'SQL login' });
        var passIn = App.el('input', { className: 'form-input', type: 'password',
            placeholder: s.has_password ? '•••••• (leave blank to keep current)' : 'Password' });
        // Ride valuation: which reader-group area counts as "the karts",
        // the default per-ride price, and per-track overrides (the venue
        // runs multiple kart tracks at different price points).
        var groupSel = App.el('select', { className: 'form-input' },
            [App.el('option', { value: '', textContent: '— none (walk-up cash only) —' })].concat(
                (s.reader_groups || []).map(function(g) {
                    var o = App.el('option', { value: String(g.id), textContent: g.name });
                    if (s.reader_group_id != null && String(s.reader_group_id) === String(g.id)) o.selected = true;
                    return o;
                })));
        var priceIn = App.el('input', { className: 'form-input', type: 'number', step: '0.25', min: '0',
            value: s.price_per_ride != null ? String(s.price_per_ride) : '11', style: { maxWidth: '8rem' } });

        // Whether rides × price is ADDED to sales. Off by default: the sales
        // query reads the POS's own "Go Kart Readers" division dollars, and
        // stacking the estimate on top would double count.
        var addValueCb = App.el('input', { className: 'toggle-input', type: 'checkbox', checked: !!s.add_ride_value });
        var addValueToggle = App.el('label', { className: 'toggle-label', style: { marginTop: '0.35rem' },
            title: 'Leave OFF when the sales query already returns real dollars (the Go Kart Readers division). Turn on only if you want sales estimated as paid rides × price instead.' }, [
            addValueCb,
            App.el('span', { className: 'toggle-switch' }),
            App.el('span', { textContent: 'Add paid rides × price to sales (estimate mode)' })
        ]);

        // Per-track price rows, rebuilt whenever the group selection changes.
        var trackPriceInputs = {};
        var trackPricesBox = App.el('div', { className: 'labor-track-prices' });
        function rebuildTrackPrices() {
            trackPricesBox.innerHTML = '';
            trackPriceInputs = {};
            var members = (s.reader_group_members || {})[groupSel.value] || [];
            if (!groupSel.value || !members.length) return;
            trackPricesBox.appendChild(App.el('label', { className: 'form-label', textContent:
                'Per-track prices — blank uses the default price' }));
            members.forEach(function(m) {
                var inp = App.el('input', { className: 'form-input', type: 'number', step: '0.25', min: '0',
                    placeholder: 'default', style: { maxWidth: '7rem' } });
                var saved = (s.ride_prices || {})[m.game_id];
                if (saved != null && saved !== '') inp.value = String(saved);
                trackPriceInputs[m.game_id] = inp;
                trackPricesBox.appendChild(App.el('div', { className: 'labor-track-row' }, [
                    App.el('span', { className: 'labor-track-name', textContent: m.game_name || m.game_id }),
                    inp
                ]));
            });
        }
        groupSel.addEventListener('change', rebuildTrackPrices);
        rebuildTrackPrices();

        var salesTa = App.el('textarea', { className: 'form-input labor-sql', rows: 6 });
        salesTa.value = s.sales_sql || '';
        var laborTa = App.el('textarea', { className: 'form-input labor-sql', rows: 9 });
        laborTa.value = s.labor_sql || '';

        var statusEl = App.el('span', { className: 'text-sm text-secondary' });

        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Save settings',
            onClick: async function() {
                statusEl.textContent = 'Saving…';
                try {
                    var payload = {
                        host: hostIn.value.trim(), port: parseInt(portIn.value, 10) || 1433,
                        database: dbIn.value.trim(), username: userIn.value.trim(),
                        sales_sql: salesTa.value, labor_sql: laborTa.value,
                        reader_group_id: groupSel.value === '' ? null : parseInt(groupSel.value, 10),
                        price_per_ride: parseFloat(priceIn.value) || 0,
                        add_ride_value: addValueCb.checked,
                        ride_prices: (function() {
                            var map = {};
                            Object.keys(trackPriceInputs).forEach(function(gid) {
                                var v = trackPriceInputs[gid].value;
                                if (v !== '') map[gid] = parseFloat(v);
                            });
                            return map;
                        })()
                    };
                    if (passIn.value !== '') payload.password = passIn.value;
                    await API.put('labor/settings', payload);
                    statusEl.textContent = 'Saved.';
                    App.toast('Labor settings saved.', 'success');
                    passIn.value = '';
                    load(); loadAdmin();
                } catch (err) {
                    statusEl.textContent = '';
                    App.toast('Save failed: ' + (err && err.message ? err.message : 'unknown error'), 'error');
                }
            } });

        var resetBtn = App.el('button', { className: 'btn btn-ghost', textContent: 'Reset queries to defaults',
            title: 'Fill both query boxes with the shipped go-kart defaults (CatNo 106 / Karting job code). Nothing is saved until you press Save settings.',
            onClick: function() {
                if (!s.defaults) return;
                salesTa.value = s.defaults.sales_sql || salesTa.value;
                laborTa.value = s.defaults.labor_sql || laborTa.value;
                statusEl.textContent = 'Defaults restored — review, then Save settings.';
            } });

        var diagEl = App.el('pre', { className: 'labor-diagnostics', style: { display: 'none' } });

        // The fingerprint can be aimed at any business day — used to chase a
        // known figure through the category/division dumps.
        var probeDateIn = App.el('input', { className: 'form-input', type: 'date',
            title: 'Fingerprint this date (blank = yesterday)', style: { maxWidth: '10.5rem' } });

        var testBtn = App.el('button', { className: 'btn btn-secondary', textContent: 'Test connection',
            onClick: async function() {
                statusEl.textContent = 'Testing…';
                diagEl.style.display = 'none';
                try {
                    var body = {};
                    if (probeDateIn.value) body.probe_date = probeDateIn.value;
                    var r = await API.post('labor/test', body);
                    if (r.success) {
                        statusEl.textContent = '✓ Connected via ' + r.driver + ' — today: ' + fmtMoney(r.sales) + ' sales, ' + fmtMoney(r.labor) + ' labor.';
                        if (r.diagnostics) {
                            var lines = [];
                            Object.keys(r.diagnostics).forEach(function(k) {
                                var v = r.diagnostics[k];
                                if (Array.isArray(v)) {
                                    lines.push(k + ':');
                                    v.forEach(function(x) { lines.push('    ' + x); });
                                } else {
                                    lines.push(k + ': ' + v);
                                }
                            });
                            diagEl.textContent = lines.join('\n');
                            diagEl.style.display = '';
                        }
                        App.toast('Connection works.', 'success');
                    } else {
                        statusEl.textContent = '✗ ' + r.error;
                    }
                } catch (err) {
                    statusEl.textContent = '✗ ' + (err && err.message ? err.message : 'test failed');
                }
            } });

        var driverNote = (s.drivers && s.drivers.length)
            ? 'Available PHP driver' + (s.drivers.length > 1 ? 's' : '') + ': ' + s.drivers.join(', ')
            : 'No MSSQL PHP driver in this PHP runtime. Containerized host (Fedora CoreOS etc.): rebuild the app image with deploy/Containerfile.mssql — step-by-step in docs/MSSQL_DRIVER.md. Bare installs: pdo_sqlsrv, pdo_dblib (FreeTDS), or pdo_odbc.';

        // Collapsed by default: the SQL and connection plumbing is for
        // admins — a non-technical reader should only ever meet the
        // summary and the table above.
        box.appendChild(App.el('details', { className: 'card labor-admin-details' }, [
            App.el('summary', { className: 'labor-admin-summary', textContent: '⚙️ Connection & queries (admin setup)' }),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-sm ' + ((s.drivers && s.drivers.length) ? 'text-secondary' : 'labor-driver-missing'), textContent: driverNote }),
                App.el('div', { className: 'labor-conn-grid' }, [
                    field('Server', hostIn), field('Port', portIn), field('Database', dbIn),
                    field('Username', userIn), field('Password', passIn)
                ]),
                App.el('div', { className: 'labor-conn-grid', style: { gridTemplateColumns: '2fr 1fr' } }, [
                    field('Show ride counts from this area (time-pass swipes flagged)', groupSel),
                    field('Default price per paid ride ($)', priceIn)
                ]),
                addValueToggle,
                trackPricesBox,
                field('Walk-up cash for a day (:date)', salesTa),
                field('Labor cost for a day (:date) — add your go-kart staff filter', laborTa),
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Queries must be a single SELECT and contain :date. They run with this SQL account’s privileges — use a read-only login. The password is stored encrypted.' }),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem', flexWrap: 'wrap' } }, [saveBtn, testBtn, probeDateIn, resetBtn, statusEl]),
                diagEl
            ])
        ]));

        function field(label, el) {
            return App.el('div', { className: 'form-group' }, [
                App.el('label', { className: 'form-label', textContent: label }), el
            ]);
        }
    }

    App.registerRoute('#/labor', { render: renderLabor });
})();
