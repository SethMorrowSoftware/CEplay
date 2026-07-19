/**
 * Ticket Trends page — redemption tickets earned by AREA (division) over time,
 * from the venue's CenterEdge MSSQL PlayerCardTrans ledger. Browsable by
 * Day / Week / Month / Year / Custom (same period model as Performance / Labor /
 * Card Loads).
 *
 * Why by area and not by game: tickets post to the card with a division but no
 * reader/game (every ticket credit has rdrkey 0), so the division is the finest
 * attribution the POS data supports. The page leads with insight cards, then a
 * tickets-by-day trend, then a per-division breakdown. Admins (settings) get the
 * editable query + test button.
 */
(function() {
    'use strict';

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };

    var state = {
        range: 'week',
        offset: 0,
        custom: { from: '', to: '' },
        data: null,
        settings: null
    };
    var loadSeq = 0;

    function fmtInt(v) { return v == null ? '—' : Number(v).toLocaleString(); }
    function fmtPct(v) {
        if (v == null) return '—';
        return (v > 0 ? '+' : '') + (v * 100).toFixed(1) + '%';
    }
    function dayLabel(iso) {
        var d = new Date(iso + 'T12:00:00');
        return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    }
    function monthLabel(ym) {
        var d = new Date(ym + '-15T12:00:00');
        return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    }
    function divLabel(d) {
        return (d.name && d.name !== '') ? d.name : ('Division ' + d.div);
    }

    // ------------------------------------------------------------------
    async function renderTickets(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Ticket Trends' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'Redemption tickets earned by area — by day, week, month, or year.' })
            ])
        ]));
        container.appendChild(buildControls());
        container.appendChild(App.el('div', { id: 'tickets-results' }));
        container.appendChild(App.el('div', { id: 'tickets-admin' }));
        load();
        if (App.canAccess('settings')) loadAdmin();
    }

    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'tickets-presets' },
            Object.keys(RANGE_LABELS).map(function(key) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (key === state.range ? 'btn-primary' : 'btn-ghost'),
                    textContent: RANGE_LABELS[key],
                    onClick: function() {
                        if (state.range === key && key !== 'custom') return;
                        state.range = key;
                        state.offset = 0;
                        refreshPresetButtons();
                        toggleCustomRow();
                        load();
                    }
                });
            })
        );
        var nav = App.el('div', { className: 'perf-nav', id: 'tickets-nav' }, [
            App.el('button', { className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '‹',
                title: 'Previous period', 'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; load(); } }),
            App.el('div', { className: 'perf-nav-label', id: 'tickets-nav-label', textContent: '…' }),
            App.el('button', { className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '›',
                id: 'tickets-nav-next', title: 'Next period', 'aria-label': 'Next period',
                disabled: state.range === 'custom' || state.offset >= 0,
                onClick: function() { if (state.range === 'custom' || state.offset >= 0) return; state.offset += 1; load(); } }),
            App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Today',
                id: 'tickets-nav-today', title: 'Jump to the current period',
                disabled: state.offset === 0,
                onClick: function() { if (state.offset === 0) return; state.offset = 0; load(); } })
        ]);
        var custom = App.el('div', { className: 'perf-custom', id: 'tickets-custom',
            style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', 'for': 'tickets-custom-from', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'tickets-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', 'for': 'tickets-custom-to', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'tickets-custom-to', value: state.custom.to }),
            App.el('button', { className: 'btn btn-sm btn-primary', textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('tickets-custom-from').value;
                    var to = document.getElementById('tickets-custom-to').value;
                    if (!from || !to) { App.toast('Pick both a start and end date.', 'warning'); return; }
                    if (from > to) { App.toast('"From" must be on or before "To".', 'warning'); return; }
                    state.custom.from = from; state.custom.to = to; load();
                } }),
            App.el('span', { className: 'text-sm text-secondary', id: 'tickets-custom-label' })
        ]);
        return App.el('div', { className: 'card perf-controls' }, [
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom])
        ]);
    }

    function refreshPresetButtons() {
        var wrap = document.getElementById('tickets-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            btn.className = 'btn btn-sm ' + (keys[i] === state.range ? 'btn-primary' : 'btn-ghost');
        });
    }
    function toggleCustomRow() {
        var custom = document.getElementById('tickets-custom');
        var nav = document.getElementById('tickets-nav');
        if (custom) custom.style.display = state.range === 'custom' ? '' : 'none';
        if (nav) nav.style.display = state.range === 'custom' ? 'none' : '';
    }
    function updateNav() {
        var next = document.getElementById('tickets-nav-next');
        if (next) next.disabled = (state.range === 'custom' || state.offset >= 0);
        var today = document.getElementById('tickets-nav-today');
        if (today) today.disabled = (state.offset === 0);
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

    // ------------------------------------------------------------------
    async function load() {
        var box = document.getElementById('tickets-results');
        if (!box) return;
        updateNav();
        var seq = ++loadSeq;
        box.innerHTML = '';
        box.appendChild(App.loading());
        var qs = 'range=' + encodeURIComponent(state.range) + '&offset=' + encodeURIComponent(state.offset);
        if (state.range === 'custom') {
            if (!state.custom.from || !state.custom.to) {
                var customLabel = document.getElementById('tickets-custom-label');
                if (customLabel) customLabel.textContent = '';
                box.innerHTML = '';
                box.appendChild(App.el('div', { className: 'card' }, [
                    App.el('div', { className: 'card-body' }, [
                        App.el('p', { className: 'text-secondary', textContent: 'Pick a From and To date above, then press Apply.' })
                    ])
                ]));
                return;
            }
            qs += '&from=' + encodeURIComponent(state.custom.from) + '&to=' + encodeURIComponent(state.custom.to);
        }
        try {
            var data = await API.get('tickets/data?' + qs);
            if (seq !== loadSeq) return;
            state.data = data;
            renderResults(data);
        } catch (err) {
            if (seq !== loadSeq) return;
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'Could not load ticket data: ' + (err && err.message ? err.message : 'unknown error') })
                ])
            ]));
        }
    }

    function renderResults(data) {
        var box = document.getElementById('tickets-results');
        if (!box) return;
        box.innerHTML = '';
        var navLabel = document.getElementById('tickets-nav-label');
        if (navLabel && data.window && data.window.label) navLabel.textContent = data.window.label;
        var customLabel = document.getElementById('tickets-custom-label');
        if (customLabel) customLabel.textContent = (data.window && data.window.label) ? data.window.label : '';

        if (!data.configured) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('h3', { textContent: 'Not connected yet' }),
                    App.el('p', { className: 'text-secondary', style: { marginTop: '0.4rem' }, textContent:
                        'This report reads the same CenterEdge MSSQL database as the Go-Kart Labor page. Set up that connection there, then this report comes alive.' })
                ])
            ]));
            return;
        }
        if (data.error) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [App.el('p', { className: 'text-secondary', textContent: '⚠ ' + data.error })])
            ]));
            return;
        }

        var s = data.summary || {};
        var days = data.days || [];
        var divisions = data.divisions || [];

        // ---- Insight cards ----
        var cards = [];
        cards.push(insightCard('🎟️', 'insight-accent', 'Tickets this period',
            fmtInt(s.tickets),
            (s.per_day != null ? fmtInt(s.per_day) + '/day' : '') + (s.credits ? ' · ' + fmtInt(s.credits) + ' payouts' : '')));
        if (s.delta_pct != null) {
            var up = s.delta_pct >= 0;
            cards.push(insightCard(up ? '📈' : '📉', up ? 'insight-good' : 'insight-warn',
                'vs previous period', fmtPct(s.delta_pct), 'was ' + fmtInt(s.prior_tickets)));
        }
        if (s.top_div_name || s.top_div != null) {
            cards.push(insightCard('🏆', 'insight-quiet', 'Top area',
                (s.top_div_name && s.top_div_name !== '') ? s.top_div_name : ('Division ' + s.top_div),
                s.top_div_share != null ? (s.top_div_share * 100).toFixed(0) + '% of tickets' : ''));
        }
        if (cards.length) box.appendChild(App.el('div', { className: 'insight-row' }, cards));

        // ---- Tickets by day (or month, on a Year view) ----
        var granularity = (data.window && data.window.granularity) || 'day';
        var rows = granularity === 'month' ? aggregateMonths(days) : days;
        buildTrendCard(box, rows, granularity === 'month' ? 'month' : 'day');

        // ---- Per-division breakdown ----
        if (!divisions.length) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [App.el('p', { className: 'text-secondary', textContent: 'No tickets recorded in this period yet.' })])
            ]));
            return;
        }
        var maxTix = divisions.reduce(function(m, d) { return Math.max(m, d.tickets || 0); }, 0);
        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [App.el('tr', {}, [
                App.el('th', { scope: 'col', textContent: 'Area' }),
                App.el('th', { scope: 'col', textContent: 'Tickets' }),
                App.el('th', { scope: 'col', textContent: 'Share' }),
                App.el('th', { scope: 'col', textContent: 'Payouts' }),
                App.el('th', { scope: 'col', 'aria-label': 'Share of tickets (bar)', 'data-nosort': '' })
            ])]),
            App.el('tbody', {}, divisions.map(function(d) {
                var w = maxTix > 0 ? Math.max(d.tickets > 0 ? 2 : 0, Math.round((d.tickets || 0) / maxTix * 100)) : 0;
                return App.el('tr', {}, [
                    App.el('td', { 'data-sort': divLabel(d) }, [App.el('strong', { textContent: divLabel(d) }),
                        App.el('span', { className: 'text-muted text-xs', textContent: ' #' + d.div })]),
                    App.el('td', { 'data-sort': d.tickets == null ? null : d.tickets, textContent: fmtInt(d.tickets) }),
                    App.el('td', { 'data-sort': d.share == null ? null : d.share, textContent: (d.share * 100).toFixed(1) + '%' }),
                    App.el('td', { 'data-sort': d.credits == null ? null : d.credits }, [App.el('span', { className: 'text-muted', textContent: fmtInt(d.credits) })]),
                    App.el('td', { style: { width: '30%' } }, [
                        App.el('div', { className: 'labor-bar-track', title: fmtInt(d.tickets) + ' tickets' }, [
                            App.el('div', { className: 'tickets-bar-fill', style: { width: w + '%' } })
                        ])
                    ])
                ]);
            }))
        ]);
        App.enhanceTableSort(table, { defaultSort: { index: 1, dir: 'desc' } });
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Tickets by area' })]),
            App.el('div', { className: 'card-body' }, [App.el('div', { className: 'table-scroll' }, [table]),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Tickets are attributed to an area (division), not an individual game — the POS records no game on a ticket credit. Ticket counts come from the card ledger (ValueNo 3).' })
            ])
        ]));
    }

    function buildTrendCard(box, rows, grain) {
        if (rows.length < 2) return; // a one-row "trend" just duplicates the insight card
        var maxTix = rows.reduce(function(m, r) { return Math.max(m, r.tickets || 0); }, 0);
        if (maxTix <= 0) return;
        var list = rows.map(function(r) {
            var w = Math.max(r.tickets > 0 ? 1 : 0, Math.round((r.tickets || 0) / maxTix * 100));
            var label = r.month ? monthLabel(r.month) : dayLabel(r.date);
            return App.el('div', { className: 'labor-hour-row', title: fmtInt(r.tickets) + ' tickets' }, [
                App.el('span', { className: 'labor-hour-label', textContent: label }),
                App.el('span', { className: 'labor-hour-track' }, [
                    App.el('span', { className: 'tickets-bar-fill', style: { width: w + '%' } })
                ]),
                App.el('span', { className: 'labor-hour-val', textContent: fmtInt(r.tickets) })
            ]);
        });
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Tickets by ' + grain })]),
            App.el('div', { className: 'card-body' }, [App.el('div', { className: 'labor-hour-list tickets-trend-list' }, list)])
        ]));
    }

    function aggregateMonths(days) {
        var byMonth = {}, order = [];
        days.forEach(function(d) {
            var ym = (d.date || '').slice(0, 7);
            if (!ym) return;
            if (!byMonth[ym]) { byMonth[ym] = { month: ym, tickets: 0, credits: 0 }; order.push(ym); }
            byMonth[ym].tickets += d.tickets || 0;
            byMonth[ym].credits += d.credits || 0;
        });
        return order.map(function(ym) { return byMonth[ym]; });
    }

    // ------------------------------------------------------------------
    // Admin: editable query + test (settings only)
    // ------------------------------------------------------------------
    async function loadAdmin() {
        try { state.settings = await API.get('tickets/settings'); renderAdmin(); }
        catch (err) {
            console.error('tickets settings load failed:', err);
            var box = document.getElementById('tickets-admin');
            if (!box) return;
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent:
                        'Could not load the admin query settings: ' + (err && err.message ? err.message : 'unknown error') })
                ])
            ]));
        }
    }

    function renderAdmin() {
        var box = document.getElementById('tickets-admin');
        if (!box || !state.settings) return;
        var s = state.settings;
        box.innerHTML = '';
        var rangeTa = App.el('textarea', { className: 'form-input labor-sql', id: 'tickets-range-sql', rows: 11 });
        rangeTa.value = s.range_sql || '';
        var statusEl = App.el('span', { className: 'text-sm text-secondary' });

        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Save query',
            onClick: async function() {
                statusEl.textContent = 'Saving…';
                try { await API.put('tickets/settings', { range_sql: rangeTa.value });
                    statusEl.textContent = 'Saved.'; App.toast('Ticket-trends query saved.', 'success'); load();
                } catch (err) { statusEl.textContent = ''; App.toast('Save failed: ' + (err && err.message ? err.message : 'unknown error'), 'error'); }
            } });
        var resetBtn = App.el('button', { className: 'btn btn-ghost', textContent: 'Reset to default',
            title: 'Fill the box with the shipped ValueNo-3 (tickets) query. Nothing is saved until you press Save query.',
            onClick: function() { if (s.defaults && s.defaults.range_sql) { rangeTa.value = s.defaults.range_sql; statusEl.textContent = 'Default restored — review, then Save query.'; } } });
        var diagEl = App.el('pre', { className: 'labor-diagnostics', style: { display: 'none' } });
        var probeDateIn = App.el('input', { className: 'form-input', type: 'date',
            'aria-label': 'Probe date to reconcile (blank = yesterday)',
            title: 'Reconcile this date (blank = yesterday)', style: { maxWidth: '10.5rem' } });

        var testBtn = App.el('button', { className: 'btn btn-secondary', textContent: 'Test & reconcile',
            onClick: async function() {
                if (!App.canAccess('data_explorer')) { App.toast('Testing needs the Database Explorer permission.', 'warning'); return; }
                statusEl.textContent = 'Testing…'; diagEl.style.display = 'none';
                try {
                    var body = {}; if (probeDateIn.value) body.probe_date = probeDateIn.value;
                    var r = await API.post('tickets/test', body);
                    if (r.success) {
                        statusEl.textContent = '✓ Connected via ' + r.driver + ' — ' + r.probe_date + ': ' + fmtInt(r.tickets) + ' tickets (' + fmtInt(r.credits) + ' payouts).';
                        if (r.diagnostics) {
                            var lines = [];
                            Object.keys(r.diagnostics).forEach(function(k) {
                                var v = r.diagnostics[k];
                                if (Array.isArray(v)) { lines.push(k + ':'); v.forEach(function(x) { lines.push('    ' + x); }); }
                                else { lines.push(k + ': ' + v); }
                            });
                            diagEl.textContent = lines.join('\n'); diagEl.style.display = '';
                        }
                        App.toast('Query works.', 'success');
                    } else { statusEl.textContent = '✗ ' + r.error; }
                } catch (err) { statusEl.textContent = '✗ ' + (err && err.message ? err.message : 'test failed'); }
            } });

        var connNote = s.connection && s.connection.host
            ? 'Using the MSSQL connection configured on the Go-Kart Labor page (database: ' + (s.connection.database || 'CenterEdge') + ').'
            : 'No MSSQL connection yet — set it up on the Go-Kart Labor page first; this report shares it.';

        function field(label, el) {
            return App.el('div', { className: 'form-group' }, [App.el('label', { className: 'form-label', 'for': el.id || null, textContent: label }), el]);
        }
        box.appendChild(App.el('details', { className: 'card labor-admin-details' }, [
            App.el('summary', { className: 'labor-admin-summary', textContent: '⚙️ Ticket query (admin setup)' }),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-sm text-secondary', textContent: connNote }),
                field('Tickets by day & area (:from … :to) — one row per (day, DivNo) with tickets + credits', rangeTa),
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Must be a single SELECT and contain :from and :to. Runs read-only with the shared SQL login. Default: PlayerCardTrans ValueNo 3 (tickets). Adjust here if this install differs, then reconcile with Test.' }),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem', flexWrap: 'wrap' } }, [saveBtn, testBtn, probeDateIn, resetBtn, statusEl]),
                diagEl
            ])
        ]));
    }

    App.registerRoute('#/tickets', { render: renderTickets });
})();
