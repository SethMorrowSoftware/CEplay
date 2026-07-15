/**
 * Go-Kart Labor page — sales vs staff wages from the venue's CenterEdge
 * MSSQL database, browsable by Day / Week / Month / Year / Custom range
 * (same period model as the Performance page).
 *
 * The page leads with plain-language insight cards, then the swipes-by-
 * hour panel (real reader-feed counts), then the per-day (or per-month,
 * on a Year view) table with the red/green split bars. Admins (settings
 * permission) also see the connection / query editor here — kept on this
 * page rather than Settings so the report and its plumbing live together.
 */
(function() {
    'use strict';

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };

    var state = {
        range: 'week',
        offset: 0,
        custom: { from: '', to: '' },
        includeTimePlays: true, // toggle: count time-pass swipes in ride counts
        moneyHeatMetric: 'money', // heatmap metric: money | wages | rate
        data: null,      // last /labor/rate payload
        settings: null   // /labor/settings payload (admins only)
    };

    // Monotonic load token: MSSQL round-trips can take seconds, so a rapid
    // ‹ ‹ click must not let the older response paint over the newer one.
    var loadSeq = 0;

    function fmtMoney(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fmtMoney0(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function fmtPct(v) {
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

    function hourLabel(h) {
        var h12 = h % 12 === 0 ? 12 : h % 12;
        return h12 + (h < 12 ? ' AM' : ' PM');
    }

    // ------------------------------------------------------------------
    async function renderLabor(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Go-Kart Labor' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'Go-kart sales vs staff wages — by day, week, month, or year.' })
            ])
        ]));

        container.appendChild(buildControls());
        container.appendChild(App.el('div', { id: 'labor-results' }));
        container.appendChild(App.el('div', { id: 'labor-admin' }));

        load();
        if (App.canAccess('settings')) loadAdmin();
    }

    // ------------------------------------------------------------------
    // Range controls — same look and semantics as the Performance page.
    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'labor-presets' },
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

        var nav = App.el('div', { className: 'perf-nav', id: 'labor-nav' }, [
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '‹',
                title: 'Previous period', 'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; load(); }
            }),
            App.el('div', { className: 'perf-nav-label', id: 'labor-nav-label', textContent: '…' }),
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

        var custom = App.el('div', { className: 'perf-custom', id: 'labor-custom',
            style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'labor-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'labor-custom-to', value: state.custom.to }),
            App.el('button', {
                className: 'btn btn-sm btn-primary', textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('labor-custom-from').value;
                    var to = document.getElementById('labor-custom-to').value;
                    if (!from || !to) { App.toast('Pick both a start and end date.', 'warning'); return; }
                    if (from > to) { App.toast('"From" must be on or before "To".', 'warning'); return; }
                    state.custom.from = from;
                    state.custom.to = to;
                    load();
                }
            })
        ]);

        // Time-pass toggle — same control as Analytics / Reader Groups.
        // Swipe COUNTS switch between all swipes and paid-only; dollars
        // never move (passes don't post money at the readers, so the
        // sales/labor figures are pass-free either way). The payload
        // carries both counts, so flipping re-renders without a refetch.
        var tpInput = App.el('input', { className: 'toggle-input', type: 'checkbox', checked: state.includeTimePlays });
        tpInput.addEventListener('change', function() {
            state.includeTimePlays = tpInput.checked;
            if (state.data) renderResults(state.data);
        });
        var tpToggle = App.el('label', { className: 'toggle-label',
            title: 'Include or hide time-pass swipes in the ride counts. Dollars never change — passes don’t post money at the readers.' }, [
            tpInput,
            App.el('span', { className: 'toggle-switch' }),
            App.el('span', { textContent: 'Time-pass plays' })
        ]);

        return App.el('div', { className: 'card perf-controls' }, [
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom, tpToggle])
        ]);
    }

    function refreshPresetButtons() {
        var wrap = document.getElementById('labor-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            btn.className = 'btn btn-sm ' + (keys[i] === state.range ? 'btn-primary' : 'btn-ghost');
        });
    }

    function toggleCustomRow() {
        var custom = document.getElementById('labor-custom');
        var nav = document.getElementById('labor-nav');
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
        var box = document.getElementById('labor-results');
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
            var data = await API.get('labor/rate?' + qs);
            if (seq !== loadSeq) return; // a newer load superseded this one
            state.data = data;
            renderResults(data);
        } catch (err) {
            if (seq !== loadSeq) return;
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

        var navLabel = document.getElementById('labor-nav-label');
        if (navLabel && data.window && data.window.label) navLabel.textContent = data.window.label;

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

        if (data.error) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: '⚠ ' + data.error })
                ])
            ]));
            return;
        }

        var days = data.days || [];

        // ---- Plain-language summary ----
        // With the time-pass toggle off, every swipe COUNT on the page
        // switches to paid-only. Dollars are pass-free either way.
        var excludePass = !state.includeTimePlays;
        var rideCount = function(d) {
            if (d.rides == null) return null;
            if (!excludePass) return d.rides;
            return d.paid_rides != null ? d.paid_rides : Math.max(0, d.rides - (d.pass_rides || 0));
        };
        var sumSales = 0, sumLabor = 0, sumRides = 0, haveRides = false;
        days.forEach(function(d) {
            sumSales += d.sales || 0; sumLabor += d.labor || 0;
            var rc = rideCount(d);
            if (rc != null) { haveRides = true; sumRides += rc; }
        });
        var avg = sumSales > 0 ? sumLabor / sumSales : null;

        var rated = days.filter(function(d) { return d.rate != null; });
        var cards = [];
        if (rated.length >= 1 || sumLabor > 0) {
            cards.push(insightCard('🏁', avg != null && avg <= 0.4 ? (avg <= 0.25 ? 'insight-good' : 'insight-accent') : 'insight-warn',
                'This period', avg != null ? fmtPct(avg) + ' labor rate' : '—',
                fmtMoney(sumLabor) + ' in wages against ' + fmtMoney(sumSales) + ' in go-kart sales'));
        }
        if (rated.length >= 2) {
            var best = rated.reduce(function(a, b) { return b.rate < a.rate ? b : a; });
            var worst = rated.reduce(function(a, b) { return b.rate > a.rate ? b : a; });
            cards.push(insightCard('✅', 'insight-good', 'Best day',
                dayLabel(best.date) + ' — ' + fmtPct(best.rate),
                fmtMoney(best.sales) + ' sales vs ' + fmtMoney(best.labor) + ' wages'));
            cards.push(insightCard('⚠️', 'insight-warn', 'Needs a look',
                dayLabel(worst.date) + ' — ' + fmtPct(worst.rate),
                fmtMoney(worst.sales) + ' sales vs ' + fmtMoney(worst.labor) + ' wages'));
        }
        if (haveRides && sumRides > 0 && sumLabor > 0) {
            cards.push(insightCard('🏎️', 'insight-quiet', 'Wages per ride',
                fmtMoney(sumLabor / sumRides),
                'across ' + sumRides.toLocaleString()
                    + (excludePass ? ' paid rides (passes hidden)' : ' rides (passes included)')));
        }
        // Peak money hour — from the REAL hourly ledger dollars, when available.
        var mny = data.money;
        if (mny && mny.hours && mny.totals && mny.totals.dollars > 0) {
            var peak = null;
            mny.hours.forEach(function(h) {
                if (!peak || (h.dollars_avg || 0) > (peak.dollars_avg || 0)) peak = h;
            });
            if (peak && peak.dollars_avg > 0) {
                cards.push(insightCard('💵', 'insight-accent', 'Peak money hour',
                    hourLabel(peak.hour) + ' — ' + fmtMoney0(peak.dollars_avg),
                    'avg taken that hour · ' + fmtMoney0(peak.wages_avg) + ' wages'
                        + (peak.rate != null ? ' · ' + fmtPct(peak.rate) + ' labor rate' : '')));
            }
        }
        if (cards.length) box.appendChild(App.el('div', { className: 'insight-row' }, cards));

        // ---- Hour-of-day panels ----
        buildMoneyPanels(box, data);
        buildHourlyCards(box, data);

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
                    App.el('p', { className: 'text-secondary', textContent: 'No data in this period yet.' })
                ])
            ]));
            return;
        }

        var showRides = rows.some(function(d) { return d.rides != null; });
        var headCells = [App.el('th', { textContent: rowLabel })];
        if (showRides) {
            headCells.push(App.el('th', {
                textContent: excludePass ? 'Paid rides' : 'Rides',
                title: excludePass ? 'Kart swipes minus time-pass swipes' : 'Total kart swipes from the reader feed' }));
            if (!excludePass) {
                headCells.push(App.el('th', { textContent: 'Pass', title: 'Time-pass swipes — they never post money at the reader' }));
            }
        }
        headCells.push(App.el('th', { textContent: 'Sales' }),
                       App.el('th', { textContent: 'Labor' }),
                       App.el('th', { textContent: 'Labor rate' }),
                       App.el('th', { textContent: '' }));

        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [App.el('tr', {}, headCells)]),
            App.el('tbody', {}, rows.map(function(d) {
                // Every bar spans the full track (100% of the period's
                // sales); the red share IS the labor rate, the green share
                // is what was left. Wages beating sales — or wages with no
                // sales at all — reads as a solid red bar.
                var rowSales = d.sales || 0, rowLabor = d.labor || 0;
                var rowProfit = Math.max(0, rowSales - rowLabor);
                var laborW = rowSales > 0
                    ? Math.min(100, rowLabor / rowSales * 100)
                    : (rowLabor > 0 ? 100 : 0);
                var profitW = rowSales > 0 ? Math.max(0, 100 - laborW) : 0;
                if (rowLabor > 0 && rowSales > 0 && laborW < 1.5) { laborW = 1.5; profitW = 98.5; }
                var rateClass = d.rate == null ? '' : (d.rate <= 0.25 ? 'labor-rate-good' : (d.rate <= 0.4 ? 'labor-rate-warn' : 'labor-rate-bad'));
                var cells = [
                    App.el('td', {}, d.month
                        ? [App.el('strong', { textContent: monthLabel(d.month) })]
                        : [App.el('strong', { textContent: dayLabel(d.date) }),
                           App.el('span', { className: 'text-muted text-xs', textContent: ' ' + d.date })])
                ];
                if (showRides) {
                    var rc = rideCount(d);
                    cells.push(App.el('td', { textContent: rc != null ? rc.toLocaleString() : '—' }));
                    if (!excludePass) {
                        cells.push(App.el('td', {}, [App.el('span', { className: 'text-muted', textContent: d.pass_rides != null ? d.pass_rides.toLocaleString() : '—' })]));
                    }
                }
                cells.push(
                    App.el('td', { textContent: fmtMoney(d.sales) }),
                    App.el('td', { textContent: fmtMoney(d.labor) }),
                    App.el('td', {}, [App.el('span', { className: 'labor-rate ' + rateClass, textContent: fmtPct(d.rate) })]),
                    App.el('td', { style: { width: '26%' } }, [
                        App.el('div', {
                            className: 'labor-bar-track',
                            title: fmtMoney(rowLabor) + ' wages'
                                + (rowSales >= rowLabor
                                    ? ' · ' + fmtMoney(rowProfit) + ' left after wages'
                                    : (rowSales > 0
                                        ? ' — wages exceeded sales by ' + fmtMoney(rowLabor - rowSales)
                                        : ' — no sales recorded'))
                        }, [
                            App.el('div', { className: 'labor-bar-seg labor-bar-labor', style: { width: laborW + '%' } }),
                            App.el('div', { className: 'labor-bar-seg labor-bar-profit', style: { width: profitW + '%' } })
                        ])
                    ])
                );
                return App.el('tr', {}, cells);
            }))
        ]);

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { textContent: 'Labor rate by ' + rowLabel.toLowerCase() })
            ]),
            App.el('div', { className: 'card-body', style: { overflowX: 'auto' } }, [table,
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Labor rate = wages ÷ go-kart sales. '
                    + (state.data && state.data.ride_valuation && state.data.ride_valuation.add_ride_value
                        ? 'Sales are estimated as paid rides × each track’s price plus the sales query; time-pass swipes are not counted as money. '
                        : 'Sales are the real dollars guests spent at the kart readers (time passes never post money there). '
                            + (excludePass
                                ? 'Ride counts hide time-pass swipes; dollars are unchanged either way. '
                                : 'Rides and Pass show how busy the track was. '))
                    + 'Today includes staff currently on the clock. A punch that was never clocked out counts zero on past days — fix it in CenterEdge and the day recalculates.' })
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
                byMonth[ym] = { month: ym, sales: 0, labor: 0, rides: null, pass_rides: null };
                order.push(ym);
            }
            var m = byMonth[ym];
            m.sales += d.sales || 0;
            m.labor += d.labor || 0;
            if (d.rides != null) {
                m.rides = (m.rides || 0) + d.rides;
                m.pass_rides = (m.pass_rides || 0) + (d.pass_rides || 0);
            }
        });
        return order.map(function(ym) {
            var m = byMonth[ym];
            m.sales = Math.round(m.sales * 100) / 100;
            m.labor = Math.round(m.labor * 100) / 100;
            m.rate = m.sales > 0 ? m.labor / m.sales : null;
            return m;
        });
    }

    // ------------------------------------------------------------------
    // Money vs wages by the hour — REAL dollars from the card ledger vs
    // punch-split wages — plus the weekday × hour heatmap (numbers in cells,
    // Card Loads style) with a Money / Wages / Labor-rate toggle.
    // ------------------------------------------------------------------
    function buildMoneyPanels(box, data) {
        var m = data.money;
        if (!m) return;
        if (m.error) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent:
                        '⚠ Hourly money panel unavailable: ' + m.error
                        + ' (check the hourly queries in the admin section below).' })
                ])
            ]));
            return;
        }
        if (!m.hours) return;

        // Trim to the venue's active window: hours with any money or wages.
        var lo = -1, hi = -1;
        m.hours.forEach(function(h, i) {
            if ((h.dollars || 0) > 0 || (h.wages || 0) > 0) { if (lo === -1) lo = i; hi = i; }
        });
        if (lo === -1) return;
        var slice = m.hours.slice(lo, hi + 1);
        var maxDol = slice.reduce(function(x, h) { return Math.max(x, h.dollars_avg || 0); }, 0);
        var maxWag = slice.reduce(function(x, h) { return Math.max(x, h.wages_avg || 0); }, 0);
        if (maxDol <= 0 && maxWag <= 0) return;

        // ---- Money vs wages by the hour (avg per day in the window) ----
        var rows = slice.map(function(h) {
            var dW = maxDol > 0 ? Math.max((h.dollars_avg || 0) > 0 ? 1 : 0, Math.round((h.dollars_avg || 0) / maxDol * 100)) : 0;
            var wW = maxDol > 0 ? Math.max((h.wages_avg || 0) > 0 ? 1 : 0, Math.round((h.wages_avg || 0) / maxDol * 100)) : 0; // same scale: wages vs money visually comparable
            var rateClass = h.rate == null ? '' : (h.rate <= 0.25 ? 'labor-rate-good' : (h.rate <= 0.4 ? 'labor-rate-warn' : 'labor-rate-bad'));
            var tip = fmtMoney(h.dollars_avg) + ' avg taken · ' + fmtMoney(h.wages_avg) + ' avg wages'
                + (h.rate != null ? ' · ' + fmtPct(h.rate) + ' of revenue' : '')
                + ' · ≈' + Math.round((h.plays || 0) / Math.max(1, (data.days || []).length)) + ' swipes on an average day';
            return App.el('div', { className: 'labor-hour-row', title: tip }, [
                App.el('span', { className: 'labor-hour-label', textContent: hourLabel(h.hour) }),
                App.el('span', { className: 'labor-money-track' }, [
                    App.el('span', { className: 'labor-money-bar' }, [
                        App.el('span', { className: 'labor-money-fill-rev', style: { width: dW + '%' } })
                    ]),
                    App.el('span', { className: 'labor-money-bar' }, [
                        App.el('span', { className: 'labor-money-fill-wag', style: { width: Math.min(100, wW) + '%' } })
                    ])
                ]),
                App.el('span', { className: 'labor-hour-val', textContent: fmtMoney0(h.dollars_avg) + ' / ' + fmtMoney0(h.wages_avg) }),
                App.el('span', { className: 'labor-rate-chip ' + rateClass, textContent: h.rate != null ? fmtPct(h.rate) : '—' })
            ]);
        });
        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { textContent: 'Money in vs wages out, by the hour' })
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'labor-hour-list' }, rows),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Average dollars on a typical day in this period. Blue = real money deducted at the kart readers (card ledger, true clock times); red = wages, each punch split across the hours actually worked. The % chip is wages ÷ revenue for that hour.' })
            ])
        ]));

        // ---- Weekday × hour heatmap with metric toggle ----
        buildMoneyHeatmap(box, m);
    }

    function buildMoneyHeatmap(box, m) {
        var hm = m.heatmap;
        if (!hm || !hm.rows || !hm.rows.length) return;
        if (!(hm.max_dollars_avg > 0) && !(hm.max_wages_avg > 0)) return;

        // Active hour columns: any weekday with money or wages in that hour.
        var lo = 24, hi = -1;
        hm.rows.forEach(function(r) {
            r.cells.forEach(function(c) {
                if ((c.dollars_avg || 0) > 0 || (c.wages_avg || 0) > 0) {
                    if (c.hour < lo) lo = c.hour;
                    if (c.hour > hi) hi = c.hour;
                }
            });
        });
        if (hi < 0) return;

        var metric = state.moneyHeatMetric;
        var METRICS = [
            { key: 'money', label: 'Money in' },
            { key: 'wages', label: 'Wages' },
            { key: 'rate',  label: 'Labor rate' }
        ];
        var toggle = App.el('div', { className: 'flex gap-sm' }, METRICS.map(function(mt) {
            return App.el('button', {
                className: 'btn btn-sm ' + (mt.key === metric ? 'btn-primary' : 'btn-ghost'),
                textContent: mt.label,
                onClick: function() {
                    if (state.moneyHeatMetric === mt.key) return;
                    state.moneyHeatMetric = mt.key;
                    if (state.data) renderResults(state.data);
                }
            });
        }));

        var headCells = [App.el('th', { className: 'cardloads-heat-corner', textContent: '' })];
        for (var h = lo; h <= hi; h++) {
            headCells.push(App.el('th', { className: 'cardloads-heat-hhead', textContent: hourLabel(h).replace(' ', '') }));
        }
        var bodyRows = hm.rows.map(function(r) {
            var tds = [App.el('th', { className: 'cardloads-heat-dow', textContent: r.label })];
            for (var h = lo; h <= hi; h++) {
                var c = r.cells[h] || {};
                var text = '', bg = 'transparent', fg = 'inherit', intensity = 0;
                if (metric === 'money') {
                    var v = c.dollars_avg || 0;
                    intensity = hm.max_dollars_avg > 0 ? v / hm.max_dollars_avg : 0;
                    if (v > 0) { bg = 'rgba(91, 141, 239, ' + (0.12 + intensity * 0.78).toFixed(3) + ')'; text = v >= 1 ? fmtMoney0(v) : ''; }
                } else if (metric === 'wages') {
                    var w = c.wages_avg || 0;
                    intensity = hm.max_wages_avg > 0 ? w / hm.max_wages_avg : 0;
                    if (w > 0) { bg = 'rgba(224, 93, 93, ' + (0.12 + intensity * 0.78).toFixed(3) + ')'; text = w >= 1 ? fmtMoney0(w) : ''; }
                } else { // rate: red = costly (wages eating revenue)
                    if (c.rate != null) {
                        intensity = Math.min(1, c.rate / 0.6);
                        bg = 'rgba(224, 93, 93, ' + (0.10 + intensity * 0.8).toFixed(3) + ')';
                        text = Math.round(c.rate * 100) + '%';
                    } else if ((c.wages_avg || 0) > 0) {
                        text = '—'; // wages with zero revenue that hour
                    }
                }
                var tip = r.label + ' ' + hourLabel(h) + ' — ' + fmtMoney(c.dollars_avg || 0) + ' avg in, '
                    + fmtMoney(c.wages_avg || 0) + ' avg wages'
                    + (c.rate != null ? ', ' + fmtPct(c.rate) + ' labor rate' : '')
                    + (r.occurrences ? ' (' + r.occurrences + ' ' + r.label + (r.occurrences > 1 ? 's' : '') + ')' : '');
                tds.push(App.el('td', {
                    className: 'cardloads-heat-cell',
                    style: { backgroundColor: bg, color: intensity > 0.55 ? '#fff' : fg },
                    title: tip,
                    textContent: text
                }));
            }
            return App.el('tr', {}, tds);
        });
        var table = App.el('table', { className: 'cardloads-heatmap' }, [
            App.el('thead', {}, [App.el('tr', {}, headCells)]),
            App.el('tbody', {}, bodyRows)
        ]);

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header', style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.5rem' } }, [
                App.el('h3', { textContent: 'When the karts earn — day of week × hour' }),
                toggle
            ]),
            App.el('div', { className: 'card-body', style: { overflowX: 'auto' } }, [table,
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'Each cell is the average for that weekday/hour slot across every occurrence in the period. Money and wages are real (card ledger + punches); labor rate = wages ÷ money for the slot — red cells are hours where staffing eats the revenue.' })
            ])
        ]));
    }

    // ------------------------------------------------------------------
    // Hour-of-day panels: swipes; wages vs (estimated) sales
    // ------------------------------------------------------------------
    function buildHourlyCards(box, data) {
        var hourly = data.hourly;
        if (!hourly || !hourly.hours) return;
        var hours = hourly.hours;

        // The time-pass toggle applies here too: bars show paid swipes
        // only when passes are excluded.
        var excludePass = !state.includeTimePlays;
        var hourCount = function(h) {
            var total = h.rides || 0;
            if (!excludePass) return total;
            return Math.max(0, total - (h.pass_rides || 0));
        };

        // Trim to the venue's active window: first to last hour with any
        // swipes under the current view.
        var lo = -1, hi = -1;
        hours.forEach(function(h, i) {
            if (hourCount(h) > 0) { if (lo === -1) lo = i; hi = i; }
        });
        if (lo === -1) return;
        var slice = hours.slice(lo, hi + 1);

        // ---- Swipes by the hour ----
        var maxRides = slice.reduce(function(m, h) { return Math.max(m, hourCount(h)); }, 0);
        if (maxRides > 0) {
            var swipeRows = slice.map(function(h) {
                var n = hourCount(h);
                var w = Math.max(1, Math.round(n / maxRides * 100));
                var pass = h.pass_rides || 0;
                var tip = excludePass
                    ? n.toLocaleString() + ' paid swipes' + (pass > 0 ? ' (' + pass.toLocaleString() + ' passes hidden)' : '')
                    : (h.rides || 0).toLocaleString() + ' swipes' + (pass > 0 ? ' · ' + pass.toLocaleString() + ' passes' : '');
                return App.el('div', { className: 'labor-hour-row', title: tip }, [
                    App.el('span', { className: 'labor-hour-label', textContent: hourLabel(h.hour) }),
                    App.el('span', { className: 'labor-hour-track' }, [
                        App.el('span', { className: 'labor-hour-fill', style: { width: (n > 0 ? w : 0) + '%' } })
                    ]),
                    App.el('span', { className: 'labor-hour-val', textContent: n.toLocaleString() })
                ]);
            });
            var notes = [];
            if (excludePass) {
                notes.push('Time-pass swipes are hidden from these counts.');
            }
            if (!hourly.swipes_full && hourly.swipes_from) {
                notes.push('Hour-by-hour swipe history starts ' + dayLabel(hourly.swipes_from) + ' — earlier days count in the totals above but not in this panel.');
            }
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-header' }, [
                    App.el('h3', { textContent: excludePass ? 'Paid swipes by the hour' : 'Swipes by the hour' })
                ]),
                App.el('div', { className: 'card-body' }, [
                    App.el('div', { className: 'labor-hour-list' }, swipeRows)
                ].concat(notes.length ? [App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent: notes.join(' ') })] : []))
            ]));
        }

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
            [App.el('option', { value: '', textContent: '— none (sales query only) —' })].concat(
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

        var salesTa = App.el('textarea', { className: 'form-input labor-sql', rows: 7 });
        salesTa.value = s.sales_range_sql || '';
        var laborTa = App.el('textarea', { className: 'form-input labor-sql', rows: 10 });
        laborTa.value = s.labor_range_sql || '';
        var hourlyTa = App.el('textarea', { className: 'form-input labor-sql', rows: 8 });
        hourlyTa.value = s.hourly_sales_range_sql || '';
        var punchesTa = App.el('textarea', { className: 'form-input labor-sql', rows: 8 });
        punchesTa.value = s.punches_range_sql || '';

        var statusEl = App.el('span', { className: 'text-sm text-secondary' });

        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Save settings',
            onClick: async function() {
                statusEl.textContent = 'Saving…';
                try {
                    var payload = {
                        host: hostIn.value.trim(), port: parseInt(portIn.value, 10) || 1433,
                        database: dbIn.value.trim(), username: userIn.value.trim(),
                        sales_range_sql: salesTa.value, labor_range_sql: laborTa.value,
                        hourly_sales_range_sql: hourlyTa.value, punches_range_sql: punchesTa.value,
                        reader_group_id: groupSel.value === '' ? null : parseInt(groupSel.value, 10),
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
                    // A blank/invalid price is "leave it alone", never $0 —
                    // an accidental $0 would silently zero estimate-mode sales.
                    var priceVal = parseFloat(priceIn.value);
                    if (priceIn.value !== '' && isFinite(priceVal)) payload.price_per_ride = priceVal;
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
            title: 'Fill both query boxes with the shipped go-kart defaults (Go Kart Readers division sales; Go-Karts wages). Nothing is saved until you press Save settings.',
            onClick: function() {
                if (!s.defaults) return;
                salesTa.value = s.defaults.sales_range_sql || salesTa.value;
                laborTa.value = s.defaults.labor_range_sql || laborTa.value;
                hourlyTa.value = s.defaults.hourly_sales_range_sql || hourlyTa.value;
                punchesTa.value = s.defaults.punches_range_sql || punchesTa.value;
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
        // summary and the panels above.
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
                field('Kart sales by day (:from … :to) — one (day, total) row per day', salesTa),
                field('Kart wages by day (:from … :to) — one (day, total) row per day; the database computes the dollars', laborTa),
                field('Kart revenue by HOUR (:from … :to) — one (day, hour, dollars, plays) row; real card-ledger swipes, drives the hourly money panel + heatmap', hourlyTa),
                field('Kart-crew punches (:from … :to) — one row per punch (day, time_in, day_out, time_out, pay_rate); wages are split across the hours actually worked', punchesTa),
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Queries must be a single SELECT and contain :from and :to. They run with this SQL account’s privileges — use a read-only login. The password is stored encrypted.' }),
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
