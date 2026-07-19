/**
 * Redemption Economics page — tickets REDEEMED at the prize counter, from the
 * venue's CenterEdge MSSQL RedeemReceipts / RedeemRecItems tables. Browsable by
 * Day / Week / Month / Year / Custom (same period model as the other reports).
 *
 * This is the redemption half of the ticket economy — Ticket Trends reports
 * tickets EARNED; this reports tickets SPENT for prizes. Together they give the
 * REDEMPTION RATE (redeemed ÷ earned) and the period's net change in outstanding
 * ticket liability. The page leads with insight cards (redeemed, redemption rate,
 * busiest hour, top prize), then tickets-by-hour + a weekday × hour heatmap for
 * counter staffing, then per-day and per-prize tables. Admins get the two
 * editable queries + a test/reconcile button.
 *
 * Note: prize MARGIN isn't available — RedeemRecItems has no cost column — so
 * the prize table is a MIX (tickets/qty), not a COGS/margin view.
 */
(function() {
    'use strict';

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };

    var state = { range: 'week', offset: 0, custom: { from: '', to: '' }, data: null, settings: null };
    var loadSeq = 0;

    function fmtInt(v) { return v == null ? '—' : Number(v).toLocaleString(); }
    function fmtPct(v) {
        if (v == null) return '—';
        return (v > 0 ? '+' : '') + (v * 100).toFixed(1) + '%';
    }
    function fmtRate(v) { return v == null ? '—' : (v * 100).toFixed(1) + '%'; }
    function dayLabel(iso) {
        var d = new Date(iso + 'T12:00:00');
        return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    }
    function monthLabel(ym) {
        var d = new Date(ym + '-15T12:00:00');
        return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    }
    function hourLabel(h) {
        var h12 = h % 12 === 0 ? 12 : h % 12;
        return h12 + (h < 12 ? ' AM' : ' PM');
    }
    function prizeLabel(p) {
        return (p.name && p.name !== '') ? p.name : ('Item ' + p.inv);
    }

    // ------------------------------------------------------------------
    async function renderRedemption(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Redemption Economics' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'Tickets redeemed for prizes — redemption rate, staffing, and prize mix.' })
            ])
        ]));
        container.appendChild(buildControls());
        container.appendChild(App.el('div', { id: 'redemption-results' }));
        container.appendChild(App.el('div', { id: 'redemption-admin' }));
        load();
        if (App.canAccess('settings')) loadAdmin();
    }

    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'redemption-presets' },
            Object.keys(RANGE_LABELS).map(function(key) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (key === state.range ? 'btn-primary' : 'btn-ghost'),
                    textContent: RANGE_LABELS[key],
                    onClick: function() {
                        if (state.range === key && key !== 'custom') return;
                        state.range = key; state.offset = 0;
                        refreshPresetButtons(); toggleCustomRow();
                        load();
                    }
                });
            })
        );
        var nav = App.el('div', { className: 'perf-nav', id: 'redemption-nav' }, [
            App.el('button', { className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '‹',
                title: 'Previous period', 'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; load(); } }),
            App.el('div', { className: 'perf-nav-label', id: 'redemption-nav-label', textContent: '…' }),
            App.el('button', { className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '›',
                id: 'redemption-nav-next', title: 'Next period', 'aria-label': 'Next period',
                disabled: state.range === 'custom' || state.offset >= 0,
                onClick: function() { if (state.range === 'custom' || state.offset >= 0) return; state.offset += 1; load(); } }),
            App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Today',
                id: 'redemption-nav-today', title: 'Jump to the current period',
                disabled: state.offset === 0,
                onClick: function() { if (state.offset === 0) return; state.offset = 0; load(); } })
        ]);
        var custom = App.el('div', { className: 'perf-custom', id: 'redemption-custom',
            style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', 'for': 'redemption-custom-from', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'redemption-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', 'for': 'redemption-custom-to', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'redemption-custom-to', value: state.custom.to }),
            App.el('button', { className: 'btn btn-sm btn-primary', textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('redemption-custom-from').value;
                    var to = document.getElementById('redemption-custom-to').value;
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
        var wrap = document.getElementById('redemption-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            btn.className = 'btn btn-sm ' + (keys[i] === state.range ? 'btn-primary' : 'btn-ghost');
        });
    }
    function toggleCustomRow() {
        var custom = document.getElementById('redemption-custom');
        var nav = document.getElementById('redemption-nav');
        if (custom) custom.style.display = state.range === 'custom' ? '' : 'none';
        if (nav) nav.style.display = state.range === 'custom' ? 'none' : '';
    }
    function updateNav() {
        var next = document.getElementById('redemption-nav-next');
        if (next) next.disabled = (state.range === 'custom' || state.offset >= 0);
        var today = document.getElementById('redemption-nav-today');
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
        var box = document.getElementById('redemption-results');
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
            var data = await API.get('redemption/data?' + qs);
            if (seq !== loadSeq) return;
            state.data = data;
            renderResults(data);
        } catch (err) {
            if (seq !== loadSeq) return;
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'Could not load redemption data: ' + (err && err.message ? err.message : 'unknown error') })
                ])
            ]));
        }
    }

    function renderResults(data) {
        var box = document.getElementById('redemption-results');
        if (!box) return;
        box.innerHTML = '';
        var navLabel = document.getElementById('redemption-nav-label');
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
        var prizes = data.prizes || [];

        // ---- Insight cards ----
        var cards = [];
        cards.push(insightCard('🎟️', 'insight-accent', 'Redeemed this period',
            fmtInt(s.redeemed) + ' tix',
            fmtInt(s.receipts) + ' redemptions' + (s.per_day != null ? ' · ' + fmtInt(s.per_day) + '/day' : '')));
        if (s.redemption_pct != null) {
            cards.push(insightCard('♻️', 'insight-good', 'Redemption rate',
                fmtRate(s.redemption_pct),
                'of ' + fmtInt(s.earned) + ' earned · ' + fmtInt(s.net_float) + ' tix net this period'));
        }
        if (s.delta_pct != null) {
            var up = s.delta_pct >= 0;
            cards.push(insightCard(up ? '📈' : '📉', up ? 'insight-good' : 'insight-warn',
                'vs previous period', fmtPct(s.delta_pct), 'was ' + fmtInt(s.prior_redeemed) + ' tix'));
        }
        if (s.busiest_hour != null) {
            cards.push(insightCard('⏰', 'insight-quiet', 'Busiest hour',
                hourLabel(s.busiest_hour),
                (s.busiest_dow_label ? 'busiest day ' + s.busiest_dow_label : 'by average tickets redeemed')));
        }
        if (s.top_prize != null) {
            cards.push(insightCard('🧸', 'insight-quiet', 'Top prize',
                (s.top_prize_name && s.top_prize_name !== '') ? s.top_prize_name : ('Item ' + s.top_prize),
                'by tickets spent'));
        }
        if (cards.length) box.appendChild(App.el('div', { className: 'insight-row' }, cards));

        // ---- Tickets redeemed by the hour ----
        buildHourlyCard(box, data);
        // ---- Weekday × hour heatmap (staffing) ----
        buildHeatmap(box, data);

        // ---- Per-day (or per-month) table ----
        var granularity = (data.window && data.window.granularity) || 'day';
        var rows = granularity === 'month' ? aggregateMonths(days) : days;
        buildDayTable(box, rows, granularity === 'month' ? 'Month' : 'Day');

        // ---- Prize mix ----
        buildPrizeTable(box, prizes);
    }

    function buildDayTable(box, rows, rowLabel) {
        // The API zero-fills every date in the window, so an idle period
        // arrives as all-zero rows, never an empty array.
        if (!rows.length || rows.every(function(d) { return !(d.tickets || d.receipts); })) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [App.el('p', { className: 'text-secondary', textContent: 'No redemptions in this period yet.' })])
            ]));
            return;
        }
        var maxTix = rows.reduce(function(m, d) { return Math.max(m, d.tickets || 0); }, 0);
        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [App.el('tr', {}, [
                App.el('th', { scope: 'col', textContent: rowLabel }),
                App.el('th', { scope: 'col', textContent: 'Redemptions' }),
                App.el('th', { scope: 'col', textContent: 'Tickets' }),
                App.el('th', { scope: 'col', textContent: 'Avg / redemption' }),
                App.el('th', { scope: 'col', 'aria-label': 'Share of busiest ' + rowLabel.toLowerCase() + ' (bar)', 'data-nosort': '' })
            ])]),
            App.el('tbody', {}, rows.map(function(d) {
                var w = maxTix > 0 ? Math.max(d.tickets > 0 ? 2 : 0, Math.round((d.tickets || 0) / maxTix * 100)) : 0;
                return App.el('tr', {}, [
                    App.el('td', { 'data-sort': d.month || d.date }, d.month
                        ? [App.el('strong', { textContent: monthLabel(d.month) })]
                        : [App.el('strong', { textContent: dayLabel(d.date) }),
                           App.el('span', { className: 'text-muted text-xs', textContent: ' ' + d.date })]),
                    App.el('td', { 'data-sort': d.receipts == null ? null : d.receipts, textContent: fmtInt(d.receipts) }),
                    App.el('td', { 'data-sort': d.tickets == null ? null : d.tickets }, [App.el('span', { className: 'redemption-amount', textContent: fmtInt(d.tickets) })]),
                    App.el('td', { 'data-sort': d.avg == null ? null : d.avg, textContent: d.avg != null ? fmtInt(d.avg) : '—' }),
                    App.el('td', { style: { width: '26%' } }, [
                        App.el('div', { className: 'labor-bar-track', 'aria-hidden': 'true', title: fmtInt(d.tickets) + ' tickets' }, [
                            App.el('div', { className: 'redemption-bar-fill', style: { width: w + '%' } })
                        ])
                    ])
                ]);
            }))
        ]);
        App.enhanceTableSort(table, { defaultSort: { index: 0, dir: 'asc' } });
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Redemptions by ' + rowLabel.toLowerCase() })]),
            App.el('div', { className: 'card-body' }, [App.el('div', { className: 'table-scroll' }, [table])])
        ]));
    }

    function buildPrizeTable(box, prizes) {
        if (!prizes.length) return;
        var maxTix = prizes.reduce(function(m, p) { return Math.max(m, p.tickets || 0); }, 0);
        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [App.el('tr', {}, [
                App.el('th', { scope: 'col', textContent: 'Prize' }),
                App.el('th', { scope: 'col', textContent: 'Tickets' }),
                App.el('th', { scope: 'col', textContent: 'Share' }),
                App.el('th', { scope: 'col', textContent: 'Qty' }),
                App.el('th', { scope: 'col', 'aria-label': 'Share of top prize (bar)', 'data-nosort': '' })
            ])]),
            App.el('tbody', {}, prizes.map(function(p) {
                var w = maxTix > 0 ? Math.max(p.tickets > 0 ? 2 : 0, Math.round((p.tickets || 0) / maxTix * 100)) : 0;
                return App.el('tr', {}, [
                    App.el('td', { 'data-sort': prizeLabel(p) }, [App.el('strong', { textContent: prizeLabel(p) }),
                        App.el('span', { className: 'text-muted text-xs', textContent: ' #' + p.inv })]),
                    App.el('td', { 'data-sort': p.tickets == null ? null : p.tickets, textContent: fmtInt(p.tickets) }),
                    App.el('td', { 'data-sort': p.share == null ? null : p.share, textContent: (p.share * 100).toFixed(1) + '%' }),
                    App.el('td', { 'data-sort': p.qty == null ? null : p.qty }, [App.el('span', { className: 'text-muted', textContent: fmtInt(p.qty) })]),
                    App.el('td', { style: { width: '28%' } }, [
                        App.el('div', { className: 'labor-bar-track', 'aria-hidden': 'true', title: fmtInt(p.tickets) + ' tickets' }, [
                            App.el('div', { className: 'redemption-bar-fill', style: { width: w + '%' } })
                        ])
                    ])
                ]);
            }))
        ]);
        App.enhanceTableSort(table, { defaultSort: { index: 1, dir: 'desc' } });
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Prize mix (top by tickets)' })]),
            App.el('div', { className: 'card-body' }, [App.el('div', { className: 'table-scroll' }, [table]),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Ticket cost by prize (from the redemption line items). Prize cost/margin isn\'t available from these tables, so this is a mix view, not a margin view.' })
            ])
        ]));
    }

    function buildHourlyCard(box, data) {
        var hours = data.hours || [];
        if (!hours.length) return;
        var lo = -1, hi = -1;
        hours.forEach(function(h, i) { if ((h.tickets || 0) > 0) { if (lo === -1) lo = i; hi = i; } });
        if (lo === -1) return;
        var slice = hours.slice(lo, hi + 1);
        var maxAvg = slice.reduce(function(m, h) { return Math.max(m, h.tickets_avg || 0); }, 0);
        if (maxAvg <= 0) return;
        var rowsEl = slice.map(function(h) {
            var v = h.tickets_avg || 0;
            var w = Math.max(v > 0 ? 1 : 0, Math.round(v / maxAvg * 100));
            return App.el('div', { className: 'labor-hour-row', title: fmtInt(v) + ' tickets redeemed on an average day at ' + hourLabel(h.hour) }, [
                App.el('span', { className: 'labor-hour-label', textContent: hourLabel(h.hour) }),
                App.el('span', { className: 'labor-hour-track' }, [
                    App.el('span', { className: 'redemption-bar-fill', style: { width: w + '%' } })
                ]),
                App.el('span', { className: 'labor-hour-val', textContent: fmtInt(v) })
            ]);
        });
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Tickets redeemed by the hour' })]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'labor-hour-list' }, rowsEl),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Average tickets redeemed in each hour on a typical day in this period — the counter-staffing view.' })
            ])
        ]));
    }

    function buildHeatmap(box, data) {
        var hm = data.heatmap;
        if (!hm || !hm.rows || !hm.rows.length || !(hm.max_avg > 0)) return;
        var lo = 24, hi = -1;
        hm.rows.forEach(function(r) {
            r.cells.forEach(function(c) { if ((c.tickets_avg || 0) > 0) { if (c.hour < lo) lo = c.hour; if (c.hour > hi) hi = c.hour; } });
        });
        if (hi < 0) return;
        // Title tooltips never show on touch, so a tap (or click) echoes the
        // cell's full detail below the table.
        var inspectEl = App.el('p', { className: 'text-xs text-secondary',
            style: { display: 'none', marginTop: '0.5rem' } });
        // White ink only works over the strong tints in the dark theme; the
        // light theme composites these semi-transparent colors on a white
        // card, where the default (dark) ink keeps contrast at every level.
        var isLight = App.theme === 'light';
        var heatRgb = isLight ? '124, 58, 237' : '139, 92, 246'; // --redemption per theme
        var headCells = [App.el('th', { className: 'cardloads-heat-corner', textContent: '' })];
        for (var h = lo; h <= hi; h++) headCells.push(App.el('th', { scope: 'col', className: 'cardloads-heat-hhead', textContent: hourLabel(h).replace(' ', '') }));
        var bodyRows = hm.rows.map(function(r) {
            var tds = [App.el('th', { scope: 'row', className: 'cardloads-heat-dow', textContent: r.label })];
            for (var h = lo; h <= hi; h++) {
                var cell = r.cells[h] || { tickets_avg: 0 };
                var v = cell.tickets_avg || 0;
                var intensity = hm.max_avg > 0 ? v / hm.max_avg : 0;
                // Violet intensity scale (distinct from the other reports' themes).
                var bg = v > 0 ? 'rgba(' + heatRgb + ', ' + (0.12 + intensity * 0.78).toFixed(3) + ')' : 'transparent';
                var tip = r.label + ' ' + hourLabel(h) + ' — ' + fmtInt(v) + ' avg tickets'
                    + (r.occurrences ? ' (' + r.occurrences + ' ' + r.label + (r.occurrences > 1 ? 's' : '') + ')' : '');
                tds.push(App.el('td', {
                    className: 'cardloads-heat-cell',
                    style: { backgroundColor: bg, color: !isLight && intensity > 0.55 ? '#fff' : 'inherit' },
                    title: tip,
                    'aria-label': tip,
                    textContent: v >= 1 ? fmtInt(v) : '',
                    onClick: function() { inspectEl.textContent = this.title; inspectEl.style.display = ''; }
                }));
            }
            return App.el('tr', {}, tds);
        });
        var table = App.el('table', { className: 'cardloads-heatmap' }, [
            App.el('thead', {}, [App.el('tr', {}, headCells)]),
            App.el('tbody', {}, bodyRows)
        ]);
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'When guests redeem — day of week × hour' })]),
            App.el('div', { className: 'card-body' }, [App.el('div', { className: 'table-scroll' }, [table]),
                inspectEl,
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Average tickets redeemed in each weekday/hour slot, averaged across every occurrence of that weekday in the period. Tap a cell for its full detail.' })
            ])
        ]));
    }

    function aggregateMonths(days) {
        var byMonth = {}, order = [];
        days.forEach(function(d) {
            var ym = (d.date || '').slice(0, 7);
            if (!ym) return;
            if (!byMonth[ym]) { byMonth[ym] = { month: ym, tickets: 0, receipts: 0 }; order.push(ym); }
            byMonth[ym].tickets += d.tickets || 0;
            byMonth[ym].receipts += d.receipts || 0;
        });
        return order.map(function(ym) {
            var m = byMonth[ym];
            m.avg = m.receipts > 0 ? Math.round(m.tickets / m.receipts) : null;
            return m;
        });
    }

    // ------------------------------------------------------------------
    // Admin: editable queries + test (settings only)
    // ------------------------------------------------------------------
    async function loadAdmin() {
        try { state.settings = await API.get('redemption/settings'); renderAdmin(); }
        catch (err) {
            console.error('redemption settings load failed:', err);
            var box = document.getElementById('redemption-admin');
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
        var box = document.getElementById('redemption-admin');
        if (!box || !state.settings) return;
        var s = state.settings;
        box.innerHTML = '';

        var rangeTa = App.el('textarea', { className: 'form-input labor-sql', id: 'redemption-range-sql', rows: 10 });
        rangeTa.value = s.range_sql || '';
        var itemsTa = App.el('textarea', { className: 'form-input labor-sql', id: 'redemption-items-sql', rows: 9 });
        itemsTa.value = s.items_sql || '';
        var statusEl = App.el('span', { className: 'text-sm text-secondary' });

        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Save queries',
            onClick: async function() {
                statusEl.textContent = 'Saving…';
                try {
                    await API.put('redemption/settings', { range_sql: rangeTa.value, items_sql: itemsTa.value });
                    statusEl.textContent = 'Saved.'; App.toast('Redemption queries saved.', 'success'); load();
                } catch (err) { statusEl.textContent = ''; App.toast('Save failed: ' + (err && err.message ? err.message : 'unknown error'), 'error'); }
            } });
        var resetBtn = App.el('button', { className: 'btn btn-ghost', textContent: 'Reset to defaults',
            title: 'Fill both boxes with the shipped queries. Nothing is saved until you press Save.',
            onClick: function() {
                if (s.defaults) {
                    if (s.defaults.range_sql) rangeTa.value = s.defaults.range_sql;
                    if (s.defaults.items_sql) itemsTa.value = s.defaults.items_sql;
                    statusEl.textContent = 'Defaults restored — review, then Save.';
                }
            } });
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
                    var r = await API.post('redemption/test', body);
                    if (r.success) {
                        statusEl.textContent = '✓ Connected via ' + r.driver + ' — ' + r.probe_date + ': '
                            + fmtInt(r.tickets) + ' tickets (' + r.receipts + ' redemptions).';
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
            App.el('summary', { className: 'labor-admin-summary', textContent: '⚙️ Redemption queries (admin setup)' }),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-sm text-secondary', textContent: connNote }),
                field('Redemptions by day & hour (:from … :to) — one row per (day, hour) with tickets + receipts', rangeTa),
                field('Prize line items (:from … :to) — one row per prize (InvNo) with tickets + qty', itemsTa),
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Both must be a single SELECT and contain :from and :to. Runs read-only with the shared SQL login. '
                    + 'Defaults: RedeemReceipts RecType 1 (redemptions on the current CenterEdge/Kiosoft readers) and RedeemRecItems (prizes). '
                    + 'Legacy Embed data (before the ~April 2026 cutover) used RecType 3 — switch only when reconciling pre-cutover history, and verify with Test.' }),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem', flexWrap: 'wrap' } }, [saveBtn, testBtn, probeDateIn, resetBtn, statusEl]),
                diagEl
            ])
        ]));
    }

    App.registerRoute('#/redemption', { render: renderRedemption });
})();
