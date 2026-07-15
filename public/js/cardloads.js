/**
 * Card Loads page — money guests ADD to their Castle Cards, from the venue's
 * CenterEdge MSSQL PlayerCardTrans ledger, browsable by Day / Week / Month /
 * Year / Custom (same period model as the Performance and Go-Kart Labor pages).
 *
 * The page leads with plain-language insight cards (total loaded, average load
 * size, busiest hour, vs the previous period), then the money-loaded-by-hour
 * curve and a day-of-week × hour heatmap for staffing, then the per-day (or
 * per-month) table. Paid loads and comped/bonus value are always shown
 * SEPARATELY. Admins (settings permission) also get the editable load-query
 * box + a test/reconcile button here.
 *
 * Why this isn't in the Sales report: this venue defers card value, so a load
 * is stored value (a liability), never a POS sale — it only exists in the card
 * ledger. See api/cardloads.php.
 */
(function() {
    'use strict';

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };

    var state = {
        range: 'week',
        offset: 0,
        custom: { from: '', to: '' },
        data: null,      // last /cardloads/data payload
        settings: null   // /cardloads/settings payload (admins only)
    };

    // Monotonic load token: MSSQL round-trips can take seconds, so a rapid
    // ‹ ‹ click must not let an older response paint over a newer one.
    var loadSeq = 0;

    function fmtMoney(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtMoney0(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
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
    function hourLabel(h) {
        var h12 = h % 12 === 0 ? 12 : h % 12;
        return h12 + (h < 12 ? ' AM' : ' PM');
    }

    // ------------------------------------------------------------------
    async function renderCardLoads(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Card Loads' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'Money guests add to their cards — by hour, day, week, month, or year.' })
            ])
        ]));

        container.appendChild(buildControls());
        container.appendChild(App.el('div', { id: 'cardloads-results' }));
        container.appendChild(App.el('div', { id: 'cardloads-admin' }));

        load();
        if (App.canAccess('settings')) loadAdmin();
    }

    // ------------------------------------------------------------------
    // Range controls — same look and semantics as the Performance page.
    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'cardloads-presets' },
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

        var nav = App.el('div', { className: 'perf-nav', id: 'cardloads-nav' }, [
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '‹',
                title: 'Previous period', 'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; load(); }
            }),
            App.el('div', { className: 'perf-nav-label', id: 'cardloads-nav-label', textContent: '…' }),
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '›',
                title: 'Next period', 'aria-label': 'Next period',
                onClick: function() { if (state.range === 'custom' || state.offset >= 0) return; state.offset += 1; load(); }
            }),
            App.el('button', {
                className: 'btn btn-sm btn-ghost', textContent: 'Today',
                title: 'Jump to the current period',
                onClick: function() { if (state.offset === 0) return; state.offset = 0; load(); }
            })
        ]);

        var custom = App.el('div', { className: 'perf-custom', id: 'cardloads-custom',
            style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'cardloads-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'cardloads-custom-to', value: state.custom.to }),
            App.el('button', {
                className: 'btn btn-sm btn-primary', textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('cardloads-custom-from').value;
                    var to = document.getElementById('cardloads-custom-to').value;
                    if (!from || !to) { App.toast('Pick both a start and end date.', 'warning'); return; }
                    if (from > to) { App.toast('"From" must be on or before "To".', 'warning'); return; }
                    state.custom.from = from;
                    state.custom.to = to;
                    load();
                }
            })
        ]);

        return App.el('div', { className: 'card perf-controls' }, [
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom])
        ]);
    }

    function refreshPresetButtons() {
        var wrap = document.getElementById('cardloads-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            btn.className = 'btn btn-sm ' + (keys[i] === state.range ? 'btn-primary' : 'btn-ghost');
        });
    }

    function toggleCustomRow() {
        var custom = document.getElementById('cardloads-custom');
        var nav = document.getElementById('cardloads-nav');
        if (custom) custom.style.display = state.range === 'custom' ? '' : 'none';
        if (nav) nav.style.display = state.range === 'custom' ? 'none' : '';
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
    // Results
    // ------------------------------------------------------------------
    async function load() {
        var box = document.getElementById('cardloads-results');
        if (!box) return;
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
            var data = await API.get('cardloads/data?' + qs);
            if (seq !== loadSeq) return; // a newer load superseded this one
            state.data = data;
            renderResults(data);
        } catch (err) {
            if (seq !== loadSeq) return;
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'Could not load card-load data: ' + (err && err.message ? err.message : 'unknown error') })
                ])
            ]));
        }
    }

    function renderResults(data) {
        var box = document.getElementById('cardloads-results');
        if (!box) return;
        box.innerHTML = '';

        var navLabel = document.getElementById('cardloads-nav-label');
        if (navLabel && data.window && data.window.label) navLabel.textContent = data.window.label;

        if (!data.configured) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('h3', { textContent: 'Not connected yet' }),
                    App.el('p', { className: 'text-secondary', style: { marginTop: '0.4rem' }, textContent:
                        'This report reads the same CenterEdge MSSQL database as the Go-Kart Labor page. '
                        + 'Set up (or fix) that connection on the Go-Kart Labor page, then this report comes alive.' })
                ])
            ]));
            return;
        }

        if (data.error) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: '⚠ ' + data.error })
                ])
            ]));
            return;
        }

        var s = data.summary || {};
        var days = data.days || [];

        // ---- Plain-language summary ----
        var cards = [];
        cards.push(insightCard('💳', 'insight-accent', 'Loaded this period',
            fmtMoney(s.paid_dollars),
            fmtInt(s.paid_loads) + ' loads'
                + (s.per_day != null ? ' · ' + fmtMoney0(s.per_day) + '/day' : '')));
        if (s.delta_pct != null) {
            var up = s.delta_pct >= 0;
            cards.push(insightCard(up ? '📈' : '📉', up ? 'insight-good' : 'insight-warn',
                'vs previous period', fmtPct(s.delta_pct),
                'was ' + fmtMoney(s.prior_paid_dollars)));
        }
        if (s.avg_load != null) {
            cards.push(insightCard('🎟️', 'insight-quiet', 'Average load',
                fmtMoney(s.avg_load), 'per paid load'));
        }
        if (s.busiest_hour != null) {
            cards.push(insightCard('⏰', 'insight-quiet', 'Busiest hour',
                hourLabel(s.busiest_hour),
                (s.busiest_dow_label ? 'busiest day ' + s.busiest_dow_label : 'by average dollars loaded')));
        }
        if (s.bonus_dollars > 0) {
            cards.push(insightCard('🎁', 'insight-quiet', 'Bonus / comped value',
                fmtMoney(s.bonus_dollars),
                fmtInt(s.bonus_loads) + ' comped adds — not paid revenue'));
        }
        if (cards.length) box.appendChild(App.el('div', { className: 'insight-row' }, cards));

        // ---- Money loaded by the hour ----
        buildHourlyCard(box, data);

        // ---- Day-of-week × hour heatmap ----
        buildHeatmap(box, data);

        // ---- Per-day (or per-month) table ----
        var granularity = (data.window && data.window.granularity) || 'day';
        var rows, rowLabel;
        if (granularity === 'month') {
            rows = aggregateMonths(days);
            rowLabel = 'Month';
        } else {
            rows = days;
            rowLabel = 'Day';
        }
        if (!rows.length) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'No card loads in this period yet.' })
                ])
            ]));
            return;
        }

        var maxPaid = rows.reduce(function(m, d) { return Math.max(m, d.paid_dollars || 0); }, 0);
        var anyBonus = rows.some(function(d) { return (d.bonus_dollars || 0) > 0; });

        var headCells = [App.el('th', { textContent: rowLabel }),
                         App.el('th', { textContent: 'Loads' }),
                         App.el('th', { textContent: 'Paid $' }),
                         App.el('th', { textContent: 'Avg load' })];
        if (anyBonus) headCells.push(App.el('th', { textContent: 'Bonus $', title: 'Comped / bonus value added — not paid revenue' }));
        headCells.push(App.el('th', { textContent: '' }));

        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [App.el('tr', {}, headCells)]),
            App.el('tbody', {}, rows.map(function(d) {
                var w = maxPaid > 0 ? Math.max(d.paid_dollars > 0 ? 2 : 0, Math.round((d.paid_dollars || 0) / maxPaid * 100)) : 0;
                var cells = [
                    App.el('td', {}, d.month
                        ? [App.el('strong', { textContent: monthLabel(d.month) })]
                        : [App.el('strong', { textContent: dayLabel(d.date) }),
                           App.el('span', { className: 'text-muted text-xs', textContent: ' ' + d.date })]),
                    App.el('td', { textContent: fmtInt(d.paid_loads) }),
                    App.el('td', { textContent: fmtMoney(d.paid_dollars) }),
                    App.el('td', { textContent: d.avg_load != null ? fmtMoney(d.avg_load) : '—' })
                ];
                if (anyBonus) cells.push(App.el('td', {}, [App.el('span', { className: 'text-muted', textContent: (d.bonus_dollars || 0) > 0 ? fmtMoney(d.bonus_dollars) : '—' })]));
                cells.push(App.el('td', { style: { width: '26%' } }, [
                    App.el('div', { className: 'labor-bar-track', title: fmtMoney(d.paid_dollars) + ' loaded' }, [
                        App.el('div', { className: 'labor-bar-seg labor-bar-profit', style: { width: w + '%' } })
                    ])
                ]));
                return App.el('tr', {}, cells);
            }))
        ]);

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { textContent: 'Card loads by ' + rowLabel.toLowerCase() })
            ]),
            App.el('div', { className: 'card-body', style: { overflowX: 'auto' } }, [table,
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Paid $ is real money guests added to cards (deferred stored value — it is not a POS sale until it\'s played off). '
                    + 'Bonus $ is comped/promotional value added with no payment, shown separately and estimated from card value-units.' })
            ])
        ]));
    }

    /** Roll daily rows up into months (Year view). */
    function aggregateMonths(days) {
        var byMonth = {};
        var order = [];
        days.forEach(function(d) {
            var ym = (d.date || '').slice(0, 7);
            if (!ym) return;
            if (!byMonth[ym]) {
                byMonth[ym] = { month: ym, paid_dollars: 0, paid_loads: 0, bonus_dollars: 0, bonus_loads: 0 };
                order.push(ym);
            }
            var m = byMonth[ym];
            m.paid_dollars += d.paid_dollars || 0;
            m.paid_loads += d.paid_loads || 0;
            m.bonus_dollars += d.bonus_dollars || 0;
            m.bonus_loads += d.bonus_loads || 0;
        });
        return order.map(function(ym) {
            var m = byMonth[ym];
            m.paid_dollars = Math.round(m.paid_dollars * 100) / 100;
            m.bonus_dollars = Math.round(m.bonus_dollars * 100) / 100;
            m.avg_load = m.paid_loads > 0 ? Math.round(m.paid_dollars / m.paid_loads * 100) / 100 : null;
            return m;
        });
    }

    // ------------------------------------------------------------------
    // Money loaded by the hour (average per day across the window)
    // ------------------------------------------------------------------
    function buildHourlyCard(box, data) {
        var hours = data.hours || [];
        if (!hours.length) return;

        // Trim to the active window: first to last hour with any paid loads.
        var lo = -1, hi = -1;
        hours.forEach(function(h, i) {
            if ((h.paid_dollars || 0) > 0) { if (lo === -1) lo = i; hi = i; }
        });
        if (lo === -1) return;
        var slice = hours.slice(lo, hi + 1);

        var maxAvg = slice.reduce(function(m, h) { return Math.max(m, h.paid_dollars_avg || 0); }, 0);
        if (maxAvg <= 0) return;

        var rowsEl = slice.map(function(h) {
            var v = h.paid_dollars_avg || 0;
            var w = Math.max(v > 0 ? 1 : 0, Math.round(v / maxAvg * 100));
            var tip = fmtMoney(v) + ' loaded on an average day at ' + hourLabel(h.hour)
                + (h.avg_load != null ? ' · ' + fmtMoney(h.avg_load) + ' avg load' : '');
            return App.el('div', { className: 'labor-hour-row', title: tip }, [
                App.el('span', { className: 'labor-hour-label', textContent: hourLabel(h.hour) }),
                App.el('span', { className: 'labor-hour-track' }, [
                    App.el('span', { className: 'labor-hour-fill', style: { width: w + '%' } })
                ]),
                App.el('span', { className: 'labor-hour-val', textContent: fmtMoney0(v) })
            ]);
        });

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { textContent: 'Money loaded by the hour' })
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'labor-hour-list' }, rowsEl),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Average dollars loaded in each hour on a typical day in this period.' })
            ])
        ]));
    }

    // ------------------------------------------------------------------
    // Day-of-week × hour heatmap (per-occurrence averages, for staffing)
    // ------------------------------------------------------------------
    function buildHeatmap(box, data) {
        var hm = data.heatmap;
        if (!hm || !hm.rows || !hm.rows.length || !(hm.max_avg > 0)) return;

        // Active hour range: any cell across any weekday with value.
        var lo = 24, hi = -1;
        hm.rows.forEach(function(r) {
            r.cells.forEach(function(c) {
                if ((c.paid_dollars_avg || 0) > 0) { if (c.hour < lo) lo = c.hour; if (c.hour > hi) hi = c.hour; }
            });
        });
        if (hi < 0) return;

        var headCells = [App.el('th', { className: 'cardloads-heat-corner', textContent: '' })];
        for (var h = lo; h <= hi; h++) {
            headCells.push(App.el('th', { className: 'cardloads-heat-hhead', textContent: hourLabel(h).replace(' ', '') }));
        }

        var bodyRows = hm.rows.map(function(r) {
            var tds = [App.el('th', { className: 'cardloads-heat-dow', textContent: r.label })];
            for (var h = lo; h <= hi; h++) {
                var cell = r.cells[h] || { paid_dollars_avg: 0 };
                var v = cell.paid_dollars_avg || 0;
                var intensity = hm.max_avg > 0 ? v / hm.max_avg : 0;
                // Accent-blue scale: transparent (0) → solid accent (max).
                var bg = v > 0 ? 'rgba(91, 141, 239, ' + (0.12 + intensity * 0.78).toFixed(3) + ')' : 'transparent';
                tds.push(App.el('td', {
                    className: 'cardloads-heat-cell',
                    style: { backgroundColor: bg, color: intensity > 0.55 ? '#fff' : 'inherit' },
                    title: r.label + ' ' + hourLabel(h) + ' — ' + fmtMoney(v) + ' avg loaded'
                        + (r.occurrences ? ' (' + r.occurrences + ' ' + r.label + (r.occurrences > 1 ? 's' : '') + ')' : ''),
                    textContent: v >= 1 ? fmtMoney0(v) : ''
                }));
            }
            return App.el('tr', {}, tds);
        });

        var table = App.el('table', { className: 'cardloads-heatmap' }, [
            App.el('thead', {}, [App.el('tr', {}, headCells)]),
            App.el('tbody', {}, bodyRows)
        ]);

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { textContent: 'When guests load — day of week × hour' })
            ]),
            App.el('div', { className: 'card-body', style: { overflowX: 'auto' } }, [table,
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Average dollars loaded in each weekday/hour slot, averaged across every occurrence of that weekday in the period — the staffing view.' })
            ])
        ]));
    }

    // ------------------------------------------------------------------
    // Admin: editable load query + test (settings permission only)
    // ------------------------------------------------------------------
    async function loadAdmin() {
        try {
            state.settings = await API.get('cardloads/settings');
            renderAdmin();
        } catch (err) {
            console.error('cardloads settings load failed:', err);
        }
    }

    function renderAdmin() {
        var box = document.getElementById('cardloads-admin');
        if (!box || !state.settings) return;
        var s = state.settings;
        box.innerHTML = '';

        var rangeTa = App.el('textarea', { className: 'form-input labor-sql', rows: 12 });
        rangeTa.value = s.range_sql || '';

        var statusEl = App.el('span', { className: 'text-sm text-secondary' });

        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Save query',
            onClick: async function() {
                statusEl.textContent = 'Saving…';
                try {
                    await API.put('cardloads/settings', { range_sql: rangeTa.value });
                    statusEl.textContent = 'Saved.';
                    App.toast('Card-loads query saved.', 'success');
                    load();
                } catch (err) {
                    statusEl.textContent = '';
                    App.toast('Save failed: ' + (err && err.message ? err.message : 'unknown error'), 'error');
                }
            } });

        var resetBtn = App.el('button', { className: 'btn btn-ghost', textContent: 'Reset to default',
            title: 'Fill the box with the shipped PlayerCardTrans TransType 3 query. Nothing is saved until you press Save query.',
            onClick: function() {
                if (s.defaults && s.defaults.range_sql) {
                    rangeTa.value = s.defaults.range_sql;
                    statusEl.textContent = 'Default restored — review, then Save query.';
                }
            } });

        var diagEl = App.el('pre', { className: 'labor-diagnostics', style: { display: 'none' } });
        var probeDateIn = App.el('input', { className: 'form-input', type: 'date',
            title: 'Reconcile this date (blank = yesterday)', style: { maxWidth: '10.5rem' } });

        var testBtn = App.el('button', { className: 'btn btn-secondary', textContent: 'Test & reconcile',
            onClick: async function() {
                statusEl.textContent = 'Testing…';
                diagEl.style.display = 'none';
                try {
                    var body = {};
                    if (probeDateIn.value) body.probe_date = probeDateIn.value;
                    var r = await API.post('cardloads/test', body);
                    if (r.success) {
                        statusEl.textContent = '✓ Connected via ' + r.driver + ' — ' + r.probe_date + ': '
                            + fmtMoney(r.paid_dollars) + ' paid (' + r.paid_loads + ' loads)'
                            + (r.bonus_dollars > 0 ? ', ' + fmtMoney(r.bonus_dollars) + ' bonus' : '') + '.';
                        if (r.diagnostics) {
                            var lines = [];
                            Object.keys(r.diagnostics).forEach(function(k) {
                                var v = r.diagnostics[k];
                                if (Array.isArray(v)) { lines.push(k + ':'); v.forEach(function(x) { lines.push('    ' + x); }); }
                                else { lines.push(k + ': ' + v); }
                            });
                            diagEl.textContent = lines.join('\n');
                            diagEl.style.display = '';
                        }
                        App.toast('Query works.', 'success');
                    } else {
                        statusEl.textContent = '✗ ' + r.error;
                    }
                } catch (err) {
                    statusEl.textContent = '✗ ' + (err && err.message ? err.message : 'test failed');
                }
            } });

        var connNote = s.connection && s.connection.host
            ? 'Using the MSSQL connection configured on the Go-Kart Labor page (database: ' + (s.connection.database || 'CenterEdge') + ').'
            : 'No MSSQL connection yet — set it up on the Go-Kart Labor page first; this report shares it.';

        box.appendChild(App.el('details', { className: 'card labor-admin-details' }, [
            App.el('summary', { className: 'labor-admin-summary', textContent: '⚙️ Load query (admin setup)' }),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-sm text-secondary', textContent: connNote }),
                field('Card loads by day & hour (:from … :to) — one row per (day, hour) with paid_dollars, paid_loads, bonus_dollars, bonus_loads', rangeTa),
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Must be a single SELECT and contain :from and :to. Runs read-only with the shared SQL login. '
                    + 'Default: PlayerCardTrans TransType 3 (add value). Adjust the bonus definition or value-unit divisor here if this install differs, then reconcile with Test.' }),
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

    App.registerRoute('#/cardloads', { render: renderCardLoads });
})();
