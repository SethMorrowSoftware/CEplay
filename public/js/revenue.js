/**
 * Revenue Mix page — sales dollars by CATEGORY (area/department) over time,
 * from the venue's CenterEdge MSSQL `Sales` table. Browsable by
 * Day / Week / Month / Year / Custom (same period model as Performance / Labor /
 * Card Loads / Ticket Trends).
 *
 * This is the "where does the money come from" roll-up: attractions vs food vs
 * groups vs card fees, the mix shift over time, and the discount rate per
 * category (margin leakage). Grain is DAY — Sales.ShiftDate is a business day at
 * midnight, so there is no honest hour-of-day here (hence no heatmap). The page
 * leads with insight cards, then a revenue-by-day trend, then a per-category
 * breakdown. Admins (settings) get the editable query + test button.
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

    function fmtMoney(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function fmtMoney2(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtInt(v) { return v == null ? '—' : Number(v).toLocaleString(); }
    function fmtPct(v) {
        if (v == null) return '—';
        return (v > 0 ? '+' : '') + (v * 100).toFixed(1) + '%';
    }
    function fmtRate(v) {
        if (v == null) return '—';
        return (v * 100).toFixed(1) + '%';
    }
    function dayLabel(iso) {
        var d = new Date(iso + 'T12:00:00');
        return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    }
    function monthLabel(ym) {
        var d = new Date(ym + '-15T12:00:00');
        return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    }
    function catLabel(c) {
        return (c.name && c.name !== '') ? c.name : ('Category ' + c.cat);
    }
    // "Jan 1 – Aug 14, 2025" — plain ISO strings, no timezone round-trip.
    function rangeLabel(from, to) {
        if (!from || !to) return '';
        if (from === to) return App.formatPlainDate(from);
        var sameYear = from.slice(0, 4) === to.slice(0, 4);
        return App.formatPlainDate(from, !sameYear) + ' – ' + App.formatPlainDate(to);
    }

    // ------------------------------------------------------------------
    async function renderRevenue(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Revenue Mix' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'Where the money comes from — sales by category, by day, week, month, or year.' })
            ])
        ]));
        container.appendChild(buildControls());
        container.appendChild(App.el('div', { id: 'revenue-results' }));
        container.appendChild(App.el('div', { id: 'revenue-admin' }));
        load();
        if (App.canAccess('settings')) loadAdmin();
    }

    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'revenue-presets' },
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
                        if (key !== 'custom') load();
                    }
                });
            })
        );
        var nav = App.el('div', { className: 'perf-nav', id: 'revenue-nav' }, [
            App.el('button', { className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '‹',
                title: 'Previous period', 'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; load(); } }),
            App.el('div', { className: 'perf-nav-label', id: 'revenue-nav-label', textContent: '…' }),
            App.el('button', { className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '›',
                id: 'revenue-nav-next', title: 'Next period', 'aria-label': 'Next period',
                disabled: state.range === 'custom' || state.offset >= 0,
                onClick: function() { if (state.range === 'custom' || state.offset >= 0) return; state.offset += 1; load(); } }),
            App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Today',
                id: 'revenue-nav-today', title: 'Jump to the current period',
                disabled: state.offset === 0,
                onClick: function() { if (state.offset === 0) return; state.offset = 0; load(); } })
        ]);
        var custom = App.el('div', { className: 'perf-custom', id: 'revenue-custom',
            style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', 'for': 'revenue-custom-from', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'revenue-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', 'for': 'revenue-custom-to', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'revenue-custom-to', value: state.custom.to }),
            App.el('button', { className: 'btn btn-sm btn-primary', textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('revenue-custom-from').value;
                    var to = document.getElementById('revenue-custom-to').value;
                    if (!from || !to) { App.toast('Pick both a start and end date.', 'warning'); return; }
                    if (from > to) { App.toast('"From" must be on or before "To".', 'warning'); return; }
                    state.custom.from = from; state.custom.to = to; load();
                } })
        ]);
        return App.el('div', { className: 'card perf-controls' }, [
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom])
        ]);
    }

    function refreshPresetButtons() {
        var wrap = document.getElementById('revenue-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            btn.className = 'btn btn-sm ' + (keys[i] === state.range ? 'btn-primary' : 'btn-ghost');
        });
    }
    function toggleCustomRow() {
        var custom = document.getElementById('revenue-custom');
        var nav = document.getElementById('revenue-nav');
        if (custom) custom.style.display = state.range === 'custom' ? '' : 'none';
        if (nav) nav.style.display = state.range === 'custom' ? 'none' : '';
    }
    function updateNav() {
        var next = document.getElementById('revenue-nav-next');
        if (next) next.disabled = (state.range === 'custom' || state.offset >= 0);
        var today = document.getElementById('revenue-nav-today');
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
        var box = document.getElementById('revenue-results');
        if (!box) return;
        updateNav();
        var seq = ++loadSeq;
        box.innerHTML = '';
        box.appendChild(App.loading());
        var qs = 'range=' + encodeURIComponent(state.range) + '&offset=' + encodeURIComponent(state.offset);
        if (state.range === 'custom') {
            if (!state.custom.from || !state.custom.to) {
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
            var data = await API.get('revenue/data?' + qs);
            if (seq !== loadSeq) return;
            state.data = data;
            renderResults(data);
        } catch (err) {
            if (seq !== loadSeq) return;
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'Could not load revenue data: ' + (err && err.message ? err.message : 'unknown error') })
                ])
            ]));
        }
    }

    function renderResults(data) {
        var box = document.getElementById('revenue-results');
        if (!box) return;
        box.innerHTML = '';
        var navLabel = document.getElementById('revenue-nav-label');
        if (navLabel && data.window && data.window.label) navLabel.textContent = data.window.label;

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
        var categories = data.categories || [];

        // ---- Insight cards ----
        var cards = [];
        var win = data.window || {};
        var revSub = (s.per_day != null ? fmtMoney(s.per_day) + '/day' : '')
            + (s.num_cats ? ' · ' + fmtInt(s.num_cats) + ' categories' : '');
        // The period is still running — say where the total stops, so it reads
        // as year to date rather than as a whole year that came up short.
        if (win.prev_aligned && win.to) revSub += ' · through ' + App.formatPlainDate(win.to);
        cards.push(insightCard('💰', 'insight-accent', 'Revenue this period', fmtMoney(s.revenue), revSub));
        if (s.delta_pct != null) {
            var up = s.delta_pct >= 0;
            // An in-progress period is compared against the SAME STRETCH of the
            // previous one (year to date vs last year to the same date), so name
            // the span the prior figure actually covers.
            var sub = 'was ' + fmtMoney(s.prior_revenue);
            if (s.prior_from && s.prior_to) sub += ' · ' + rangeLabel(s.prior_from, s.prior_to);
            cards.push(insightCard(up ? '📈' : '📉', up ? 'insight-good' : 'insight-warn',
                s.compare_label || 'vs previous period', fmtPct(s.delta_pct), sub));
        }
        if (s.top_cat_name || s.top_cat != null) {
            cards.push(insightCard('🏆', 'insight-quiet', 'Top category',
                (s.top_cat_name && s.top_cat_name !== '') ? s.top_cat_name : ('Category ' + s.top_cat),
                s.top_cat_share != null ? (s.top_cat_share * 100).toFixed(0) + '% of revenue' : ''));
        }
        if (s.discounts != null && s.discounts > 0) {
            cards.push(insightCard('🏷️', 'insight-quiet', 'Discounts given',
                fmtMoney(s.discounts),
                s.discount_pct != null ? fmtRate(s.discount_pct) + ' of gross' : ''));
        }
        if (cards.length) box.appendChild(App.el('div', { className: 'insight-row' }, cards));

        // ---- Revenue by day (or month, on a Year view) ----
        var granularity = (data.window && data.window.granularity) || 'day';
        var rows = granularity === 'month' ? aggregateMonths(days) : days;
        buildTrendCard(box, rows, granularity === 'month' ? 'month' : 'day');

        // ---- Per-category breakdown ----
        if (!categories.length) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [App.el('p', { className: 'text-secondary', textContent: 'No sales recorded in this period yet.' })])
            ]));
            return;
        }
        var maxAmt = categories.reduce(function(m, c) { return Math.max(m, c.amount || 0); }, 0);
        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [App.el('tr', {}, [
                App.el('th', { scope: 'col', textContent: 'Category' }),
                App.el('th', { scope: 'col', textContent: 'Revenue' }),
                App.el('th', { scope: 'col', textContent: 'Share' }),
                App.el('th', { scope: 'col', textContent: 'Discount rate', title: 'Discount dollars as a share of gross (revenue + discounts)' }),
                App.el('th', { scope: 'col', textContent: 'Units' }),
                App.el('th', { scope: 'col', 'aria-label': 'Relative revenue (bar)', 'data-nosort': '' })
            ])]),
            App.el('tbody', {}, categories.map(function(c) {
                var w = maxAmt > 0 ? Math.max(c.amount > 0 ? 2 : 0, Math.round((c.amount || 0) / maxAmt * 100)) : 0;
                var dr = (c.discount_pct != null && c.discount_pct > 0) ? fmtRate(c.discount_pct) : '—';
                return App.el('tr', {}, [
                    App.el('td', { 'data-sort': catLabel(c) }, [App.el('strong', { textContent: catLabel(c) }),
                        App.el('span', { className: 'text-muted text-xs', textContent: ' #' + c.cat })]),
                    App.el('td', { 'data-sort': c.amount == null ? null : c.amount }, [App.el('span', { className: 'revenue-amount', textContent: fmtMoney(c.amount) })]),
                    App.el('td', { 'data-sort': c.share == null ? null : c.share, textContent: (c.share * 100).toFixed(1) + '%' }),
                    App.el('td', { 'data-sort': (c.discount_pct != null && c.discount_pct > 0) ? c.discount_pct : null }, [App.el('span', { className: (c.discount_pct > 0.1 ? 'revenue-discount-hot' : 'text-muted'), textContent: dr })]),
                    App.el('td', { 'data-sort': c.qty == null ? null : c.qty }, [App.el('span', { className: 'text-muted', textContent: fmtInt(Math.round(c.qty)) })]),
                    App.el('td', { style: { width: '28%' } }, [
                        App.el('div', { className: 'labor-bar-track', title: fmtMoney2(c.amount) }, [
                            App.el('div', { className: 'revenue-bar-fill', style: { width: w + '%' } })
                        ])
                    ])
                ]);
            }))
        ]);
        // Fully rendered, non-paginated — headers sort in place. The server's
        // revenue-DESC arrival order stays the default (stable sort).
        App.enhanceTableSort(table, { defaultSort: { index: 1, dir: 'desc' } });
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Revenue by category' })]),
            App.el('div', { className: 'card-body' }, [App.el('div', { className: 'table-scroll' }, [table]),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Sales dollars come from the POS Sales table, grouped by category (CatNo). This is a business-day roll-up (ShiftDate), so there is no hour-of-day here.' })
            ])
        ]));
    }

    function buildTrendCard(box, rows, grain) {
        if (!rows.length) return;
        var maxAmt = rows.reduce(function(m, r) { return Math.max(m, r.amount || 0); }, 0);
        if (maxAmt <= 0) return;
        var list = rows.map(function(r) {
            var w = Math.max(r.amount > 0 ? 1 : 0, Math.round((r.amount || 0) / maxAmt * 100));
            var label = r.month ? monthLabel(r.month) : dayLabel(r.date);
            return App.el('div', { className: 'labor-hour-row', title: fmtMoney2(r.amount) }, [
                App.el('span', { className: 'labor-hour-label', textContent: label }),
                App.el('span', { className: 'labor-hour-track' }, [
                    App.el('span', { className: 'revenue-bar-fill', style: { width: w + '%' } })
                ]),
                App.el('span', { className: 'labor-hour-val', textContent: fmtMoney(r.amount) })
            ]);
        });
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Revenue by ' + grain })]),
            App.el('div', { className: 'card-body' }, [App.el('div', { className: 'labor-hour-list revenue-trend-list' }, list)])
        ]));
    }

    function aggregateMonths(days) {
        var byMonth = {}, order = [];
        days.forEach(function(d) {
            var ym = (d.date || '').slice(0, 7);
            if (!ym) return;
            if (!byMonth[ym]) { byMonth[ym] = { month: ym, amount: 0 }; order.push(ym); }
            byMonth[ym].amount += d.amount || 0;
        });
        return order.map(function(ym) { return byMonth[ym]; });
    }

    // ------------------------------------------------------------------
    // Admin: editable query + test (settings only)
    // ------------------------------------------------------------------
    async function loadAdmin() {
        var gen = App.navGeneration();
        try {
            var settings = await API.get('revenue/settings');
            if (App.navGeneration() !== gen) return;
            state.settings = settings;
            renderAdmin();
        } catch (err) {
            if (App.navGeneration() !== gen) return;
            console.error('revenue settings load failed:', err);
            var box = document.getElementById('revenue-admin');
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
        var box = document.getElementById('revenue-admin');
        if (!box || !state.settings) return;
        var s = state.settings;
        box.innerHTML = '';
        var rangeTa = App.el('textarea', { className: 'form-input labor-sql', id: 'revenue-range-sql', rows: 11 });
        rangeTa.value = s.range_sql || '';
        var statusEl = App.el('span', { className: 'text-sm text-secondary', 'aria-live': 'polite' });

        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Save query',
            onClick: async function() {
                saveBtn.disabled = true;
                statusEl.textContent = 'Saving…';
                try { await API.put('revenue/settings', { range_sql: rangeTa.value });
                    statusEl.textContent = 'Saved.'; App.toast('Revenue-mix query saved.', 'success'); load();
                } catch (err) { statusEl.textContent = ''; App.toast('Save failed: ' + (err && err.message ? err.message : 'unknown error'), 'error'); }
                finally { saveBtn.disabled = false; }
            } });
        var resetBtn = App.el('button', { className: 'btn btn-ghost', textContent: 'Reset to default',
            title: 'Fill the box with the shipped Sales-by-category query. Nothing is saved until you press Save query.',
            onClick: function() { if (s.defaults && s.defaults.range_sql) { rangeTa.value = s.defaults.range_sql; statusEl.textContent = 'Default restored — review, then Save query.'; } } });
        var diagEl = App.el('pre', { className: 'labor-diagnostics', style: { display: 'none' } });
        var probeDateIn = App.el('input', { className: 'form-input', type: 'date',
            'aria-label': 'Probe date to reconcile (blank = yesterday)',
            title: 'Reconcile this date (blank = yesterday)', style: { maxWidth: '10.5rem' } });

        var testBtn = App.el('button', { className: 'btn btn-secondary', textContent: 'Test & reconcile',
            onClick: async function() {
                testBtn.disabled = true;
                statusEl.textContent = 'Testing…'; diagEl.style.display = 'none';
                try {
                    var body = {}; if (probeDateIn.value) body.probe_date = probeDateIn.value;
                    var r = await API.post('revenue/test', body);
                    if (r.success) {
                        statusEl.textContent = '✓ Connected via ' + r.driver + ' — ' + r.probe_date + ': ' + fmtMoney2(r.revenue) + ' revenue'
                            + (r.discounts ? ' (' + fmtMoney2(r.discounts) + ' discounts)' : '') + '.';
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
                finally { testBtn.disabled = false; }
            } });

        var connNote = s.connection && s.connection.host
            ? 'Using the MSSQL connection configured on the Go-Kart Labor page (database: ' + (s.connection.database || 'CenterEdge') + ').'
            : 'No MSSQL connection yet — set it up on the Go-Kart Labor page first; this report shares it.';

        function field(label, el) {
            return App.el('div', { className: 'form-group' }, [App.el('label', { className: 'form-label', 'for': el.id || null, textContent: label }), el]);
        }
        // The test endpoint needs data_explorer (settings alone isn't enough) —
        // don't show a button that can only fail.
        var canTest = App.canAccess('data_explorer');
        box.appendChild(App.el('details', { className: 'card labor-admin-details' }, [
            App.el('summary', { className: 'labor-admin-summary', textContent: '⚙️ Revenue query (admin setup)' }),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-sm text-secondary', textContent: connNote }),
                field('Revenue by day & category (:from … :to) — one row per (day, CatNo) with amount, discounts, qty', rangeTa),
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Must be a single SELECT and contain :from and :to. Runs read-only with the shared SQL login. Default: Sales grouped by CatNo. Adjust here if this install differs, then reconcile with Test.' }),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem', flexWrap: 'wrap' } },
                    canTest ? [saveBtn, testBtn, probeDateIn, resetBtn, statusEl] : [saveBtn, resetBtn, statusEl]),
                diagEl
            ])
        ]));
    }

    App.registerRoute('#/revenue', { render: renderRevenue });
})();
