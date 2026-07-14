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
        state.dates = [todayISO(), shiftDays(todayISO(), -7)];
        state.data = null;

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Go-Kart Labor' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'Sales vs labor cost per day from CenterEdge — pick days to compare your labor rate.' })
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
            presetBtn('Last 4 Saturdays', function() {
                var out = [], d = new Date();
                d.setDate(d.getDate() - ((d.getDay() + 1) % 7)); // most recent Saturday
                for (var i = 0; i < 4; i++) { out.push(d.toISOString().slice(0, 10)); d.setDate(d.getDate() - 7); }
                return out;
            }),
            presetBtn('Last 7 days', function() {
                var out = [], t = todayISO();
                for (var i = 0; i < 7; i++) out.push(shiftDays(t, -i));
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
        var sumSales = 0, sumLabor = 0;
        good.forEach(function(d) { sumSales += d.sales || 0; sumLabor += d.labor || 0; });
        if (sumSales > 0) avg = sumLabor / sumSales;

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { textContent: 'Labor rate by day' }),
                App.el('span', { className: 'text-sm text-secondary', textContent:
                    avg != null ? 'Combined: ' + fmtPct(avg) + ' (' + fmtMoney(sumLabor) + ' labor on ' + fmtMoney(sumSales) + ' sales)' : '' })
            ]),
            App.el('div', { className: 'card-body', style: { overflowX: 'auto' } }, [table,
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    (state.data && state.data.ride_valuation && state.data.ride_valuation.active
                        ? 'Sales = walk-up cash + paid rides × ' + fmtMoney(state.data.ride_valuation.price_per_ride) + '. Time-pass swipes are counted in Rides but omitted from the sales value. '
                        : 'Sales come from the MSSQL query below (walk-up cash only until a reader group is selected for ride valuation). ')
                    + 'Labor rate = labor cost ÷ sales. Today includes staff currently on the clock at their rate so far. A punch that was never clocked out counts zero on past days — fix missed punch-outs in CenterEdge and the day recalculates on refresh.' })
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
        // and what one paid (non-time-pass) swipe is worth.
        var groupSel = App.el('select', { className: 'form-input' },
            [App.el('option', { value: '', textContent: '— none (walk-up cash only) —' })].concat(
                (s.reader_groups || []).map(function(g) {
                    var o = App.el('option', { value: String(g.id), textContent: g.name });
                    if (s.reader_group_id != null && String(s.reader_group_id) === String(g.id)) o.selected = true;
                    return o;
                })));
        var priceIn = App.el('input', { className: 'form-input', type: 'number', step: '0.25', min: '0',
            value: s.price_per_ride != null ? String(s.price_per_ride) : '11', style: { maxWidth: '8rem' } });

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
                        price_per_ride: parseFloat(priceIn.value) || 0
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

        var testBtn = App.el('button', { className: 'btn btn-secondary', textContent: 'Test connection',
            onClick: async function() {
                statusEl.textContent = 'Testing…';
                diagEl.style.display = 'none';
                try {
                    var r = await API.post('labor/test', {});
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

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Connection & queries (admin)' })]),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-sm ' + ((s.drivers && s.drivers.length) ? 'text-secondary' : 'labor-driver-missing'), textContent: driverNote }),
                App.el('div', { className: 'labor-conn-grid' }, [
                    field('Server', hostIn), field('Port', portIn), field('Database', dbIn),
                    field('Username', userIn), field('Password', passIn)
                ]),
                App.el('div', { className: 'labor-conn-grid', style: { gridTemplateColumns: '2fr 1fr' } }, [
                    field('Value rides from this area (time-pass swipes omitted)', groupSel),
                    field('Price per paid ride ($)', priceIn)
                ]),
                field('Walk-up cash for a day (:date)', salesTa),
                field('Labor cost for a day (:date) — add your go-kart staff filter', laborTa),
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Queries must be a single SELECT and contain :date. They run with this SQL account’s privileges — use a read-only login. The password is stored encrypted.' }),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem', flexWrap: 'wrap' } }, [saveBtn, testBtn, resetBtn, statusEl]),
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
