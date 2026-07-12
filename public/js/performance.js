/**
 * Performance page — search any game and see how it's performing (plays,
 * tickets, revenue) over a Day / Week / Month / Year, or a custom range.
 *
 * Data comes from two role-gated endpoints (same 'analytics' gate + money
 * scrub the Analytics page uses):
 *   GET /api/analytics/games — venue KPIs + trend + a searchable, sortable,
 *                              paginated per-game leaderboard for the period.
 *   GET /api/analytics/game  — one game's KPIs + trend + prior-period compare.
 *
 * Month/year history is served from the permanent per-game daily rollup, so
 * long-range reporting works even though the raw play feed is short-lived.
 */
(function() {
    App.registerRoute('#/performance', { render: renderPerformancePage });

    var state = {
        range: 'week',
        offset: 0,
        custom: { from: '', to: '' },
        search: '',
        sort: 'tickets',
        page: 1,
        pageSize: 25,
        metric: 'tickets',   // which metric the venue trend chart plots
        data: null,          // last leaderboard payload
        charts: [],          // page-level Chart.js instances
        modalChart: null,    // per-game detail Chart.js instance
        themeObserver: null,
        gen: 0               // guards stale async renders
    };

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };

    async function renderPerformancePage(container, params) {
        var query = (params && params._query) || {};
        if (query.range && RANGE_LABELS[query.range]) state.range = query.range;
        if (query.search != null) state.search = String(query.search);
        state.offset = 0;
        state.page = 1;
        var pendingDetailId = query.game || '';

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Performance' }),
                App.el('p', { className: 'page-subtitle',
                    textContent: 'Search a game and track plays, tickets, and revenue by day, week, month, or year.' })
            ])
        ]));

        // Controls: range presets + period navigator (+ custom date inputs).
        container.appendChild(buildControls());

        // KPI summary + trend chart + leaderboard table shells.
        container.appendChild(App.el('div', { id: 'perf-kpis', className: 'perf-kpi-grid' }, [App.loading()]));

        container.appendChild(App.el('div', { className: 'card', id: 'perf-trend-card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', { className: 'flex-center gap-sm' }, [
                    App.el('div', { className: 'card-title', textContent: 'Trend' }),
                    App.el('span', { id: 'perf-trend-sub', className: 'text-sm text-secondary' })
                ]),
                buildMetricToggle()
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'perf-chart-holder' }, [
                    App.el('canvas', { id: 'perf-trend-canvas' })
                ])
            ])
        ]));

        var searchInput = App.buildSearchInput({
            placeholder: 'Search games by name or ID…',
            ariaLabel: 'Search games',
            value: state.search,
            onSearch: function(term) {
                state.search = term;
                state.page = 1;
                load();
            }
        });
        searchInput.style.flex = '1';

        container.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', {}, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        App.el('div', { className: 'card-title', textContent: 'Game leaderboard' }),
                        App.el('span', { id: 'perf-table-meta', className: 'text-sm text-secondary' })
                    ]),
                    App.el('div', { id: 'perf-payout-summary', className: 'text-sm text-secondary', style: { marginTop: '0.15rem' } })
                ]),
                App.el('span', { className: 'text-xs text-muted', textContent: 'Click any game for its trend' })
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'flex gap-sm', style: { marginBottom: '0.75rem' } }, [searchInput]),
                App.el('div', { id: 'perf-table' }, [App.loading()]),
                App.el('div', { id: 'perf-pagination' })
            ])
        ]));

        // Repaint charts on theme flip (mirrors the Analytics page).
        state.themeObserver = new MutationObserver(function(records) {
            for (var i = 0; i < records.length; i++) {
                if (records[i].attributeName === 'data-theme') {
                    renderTrendChart();
                    break;
                }
            }
        });
        state.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        await load();

        if (pendingDetailId) showGameDetail(pendingDetailId);

        // Cleanup when navigating away.
        return function cleanup() {
            destroyCharts();
            if (state.modalChart) { try { state.modalChart.destroy(); } catch (e) {} state.modalChart = null; }
            if (state.themeObserver) { state.themeObserver.disconnect(); state.themeObserver = null; }
        };
    }

    // ------------------------------------------------------------------
    // Controls
    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'perf-presets' },
            Object.keys(RANGE_LABELS).map(function(key) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (key === state.range ? 'btn-primary' : 'btn-ghost'),
                    textContent: RANGE_LABELS[key],
                    onClick: function() {
                        if (state.range === key && key !== 'custom') return;
                        state.range = key;
                        state.offset = 0;
                        state.page = 1;
                        refreshPresetButtons();
                        toggleCustomRow();
                        if (key !== 'custom') load();
                    }
                });
            })
        );

        var nav = App.el('div', { className: 'perf-nav', id: 'perf-nav' }, [
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn',
                textContent: '‹',
                title: 'Previous period',
                'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; state.page = 1; load(); }
            }),
            App.el('div', { className: 'perf-nav-label', id: 'perf-nav-label', textContent: '…' }),
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn',
                id: 'perf-nav-next',
                textContent: '›',
                title: 'Next period',
                'aria-label': 'Next period',
                onClick: function() { if (state.range === 'custom' || state.offset >= 0) return; state.offset += 1; state.page = 1; load(); }
            }),
            App.el('button', {
                className: 'btn btn-sm btn-ghost',
                id: 'perf-nav-reset',
                textContent: 'Today',
                title: 'Jump to the current period',
                onClick: function() { if (state.offset === 0) return; state.offset = 0; state.page = 1; load(); }
            })
        ]);

        var custom = App.el('div', { className: 'perf-custom', id: 'perf-custom', style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'perf-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'perf-custom-to', value: state.custom.to }),
            App.el('button', {
                className: 'btn btn-sm btn-primary',
                textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('perf-custom-from').value;
                    var to = document.getElementById('perf-custom-to').value;
                    if (!from || !to) { App.toast('Pick both a start and end date.', 'warning'); return; }
                    if (from > to) { App.toast('"From" must be on or before "To".', 'warning'); return; }
                    state.custom.from = from;
                    state.custom.to = to;
                    state.page = 1;
                    load();
                }
            })
        ]);

        return App.el('div', { className: 'card perf-controls' }, [
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom])
        ]);
    }

    function refreshPresetButtons() {
        var wrap = document.getElementById('perf-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            var active = keys[i] === state.range;
            btn.className = 'btn btn-sm ' + (active ? 'btn-primary' : 'btn-ghost');
        });
    }

    function toggleCustomRow() {
        var custom = document.getElementById('perf-custom');
        var nav = document.getElementById('perf-nav');
        if (custom) custom.style.display = state.range === 'custom' ? '' : 'none';
        if (nav) nav.style.display = state.range === 'custom' ? 'none' : '';
    }

    function buildMetricToggle() {
        var metrics = [['tickets', 'Tickets'], ['plays', 'Plays']];
        if (App.canSeeMoney()) metrics.push(['cash', 'Revenue']);
        return App.el('div', { className: 'perf-metric-toggle', id: 'perf-metric-toggle' },
            metrics.map(function(m) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (m[0] === state.metric ? 'btn-secondary' : 'btn-ghost'),
                    textContent: m[1],
                    onClick: function() {
                        state.metric = m[0];
                        var wrap = document.getElementById('perf-metric-toggle');
                        if (wrap) Array.prototype.forEach.call(wrap.children, function(btn) {
                            btn.className = 'btn btn-sm ' + (btn.textContent === m[1] ? 'btn-secondary' : 'btn-ghost');
                        });
                        renderTrendChart();
                    }
                });
            })
        );
    }

    // ------------------------------------------------------------------
    // Data load + render
    // ------------------------------------------------------------------
    function buildQuery() {
        var q = ['range=' + encodeURIComponent(state.range)];
        if (state.range === 'custom') {
            q.push('from=' + encodeURIComponent(state.custom.from));
            q.push('to=' + encodeURIComponent(state.custom.to));
        } else {
            q.push('offset=' + encodeURIComponent(state.offset));
        }
        if (state.search) q.push('search=' + encodeURIComponent(state.search));
        q.push('sort=' + encodeURIComponent(state.sort));
        q.push('page=' + state.page);
        q.push('page_size=' + state.pageSize);
        return q.join('&');
    }

    async function load() {
        var gen = ++state.gen;
        try {
            var data = await API.get('analytics/games?' + buildQuery());
            if (gen !== state.gen) return; // superseded by a newer request
            state.data = data;
            updateNav();
            renderKpis();
            renderTrendChart();
            renderTable();
        } catch (err) {
            if (gen !== state.gen) return;
            var t = document.getElementById('perf-table');
            if (t) { t.innerHTML = ''; t.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Failed to load performance data: ' + err.message })); }
            var k = document.getElementById('perf-kpis');
            if (k) k.innerHTML = '';
        }
    }

    function updateNav() {
        var label = document.getElementById('perf-nav-label');
        if (label && state.data) label.textContent = state.data.range.label;
        var next = document.getElementById('perf-nav-next');
        if (next) next.disabled = (state.range === 'custom' || state.offset >= 0);
        var reset = document.getElementById('perf-nav-reset');
        if (reset) reset.disabled = (state.offset === 0);
        var sub = document.getElementById('perf-trend-sub');
        if (sub && state.data) sub.textContent = granularityNote(state.data.range.granularity);
    }

    function granularityNote(g) {
        if (g === 'hour') return 'by hour';
        if (g === 'month') return 'by month';
        return 'by day';
    }

    function renderKpis() {
        var holder = document.getElementById('perf-kpis');
        if (!holder || !state.data) return;
        holder.innerHTML = '';
        var t = state.data.totals, p = state.data.previous_totals;
        var money = App.canSeeMoney() && !state.data.hide_money;

        var cards = [
            kpiCard('Plays', formatInt(t.plays), delta(t.plays, p.plays)),
            kpiCard('Tickets', formatInt(Math.round(t.tickets)), delta(t.tickets, p.tickets)),
        ];
        if (money) cards.push(kpiCard('Revenue', formatCurrency(t.cash), delta(t.cash, p.cash)));
        cards.push(kpiCard('Avg tickets / play', t.avg_tickets_per_play != null ? Number(t.avg_tickets_per_play).toFixed(1) : '—', null));
        cards.push(kpiCard('Games with plays', formatInt(t.active_games), null));

        cards.forEach(function(c) { holder.appendChild(c); });
    }

    function kpiCard(label, value, deltaEl) {
        var children = [
            App.el('div', { className: 'perf-kpi-label', textContent: label }),
            App.el('div', { className: 'perf-kpi-value', textContent: value })
        ];
        if (deltaEl) children.push(deltaEl);
        return App.el('div', { className: 'perf-kpi' }, children);
    }

    /** Small coloured delta chip comparing current vs previous period. */
    function delta(cur, prev) {
        cur = Number(cur) || 0; prev = Number(prev) || 0;
        var cls, text;
        if (prev === 0 && cur === 0) { cls = 'flat'; text = 'no change'; }
        else if (prev === 0) { cls = 'up'; text = 'new'; }
        else {
            var pct = ((cur - prev) / prev) * 100;
            var rounded = Math.round(pct * 10) / 10;
            if (rounded > 0) { cls = 'up'; text = '▲ ' + rounded.toFixed(1) + '%'; }
            else if (rounded < 0) { cls = 'down'; text = '▼ ' + Math.abs(rounded).toFixed(1) + '%'; }
            else { cls = 'flat'; text = '0%'; }
        }
        return App.el('div', { className: 'perf-kpi-delta perf-delta-' + cls, textContent: text, title: 'vs previous period' });
    }

    // ------------------------------------------------------------------
    // Trend chart
    // ------------------------------------------------------------------
    function destroyCharts() {
        state.charts.forEach(function(c) { try { c.destroy(); } catch (e) {} });
        state.charts = [];
    }

    async function renderTrendChart() {
        if (!state.data) return;
        var ok = await ensureChart();
        var canvas = document.getElementById('perf-trend-canvas');
        if (!ok || !canvas) return;
        destroyCharts();

        var points = (state.data.series && state.data.series.points) || [];
        var labels = points.map(function(p) { return p.label; });
        var metric = state.metric;
        if (metric === 'cash' && !App.canSeeMoney()) metric = 'tickets';
        var values = points.map(function(p) { return metric === 'cash' ? Number(p.cash) : (metric === 'plays' ? Number(p.plays) : Number(p.tickets)); });

        var theme = readThemeColors();
        var barColor = metric === 'plays' ? theme.accent : (metric === 'cash' ? theme.success : theme.tickets);

        var chart = new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: metric === 'plays' ? 'Plays' : (metric === 'cash' ? 'Revenue' : 'Tickets'),
                    data: values,
                    backgroundColor: barColor,
                    borderRadius: 4,
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 300 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: theme.bgCard, titleColor: theme.text,
                        bodyColor: theme.textSecondary, borderColor: theme.border, borderWidth: 1, padding: 8,
                        callbacks: {
                            label: function(ctx) {
                                var v = ctx.parsed.y;
                                if (metric === 'cash') return ' ' + formatCurrency(v);
                                return ' ' + formatInt(v) + ' ' + (metric === 'plays' ? 'plays' : 'tickets');
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: theme.textSecondary, autoSkip: true, maxRotation: 0 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: theme.textSecondary, precision: 0 }, grid: { color: theme.gridLine } }
                }
            }
        });
        state.charts.push(chart);
    }

    // ------------------------------------------------------------------
    // Leaderboard table
    // ------------------------------------------------------------------
    function renderTable() {
        var el = document.getElementById('perf-table');
        var pager = document.getElementById('perf-pagination');
        if (!el || !state.data) return;

        var money = App.canSeeMoney() && !state.data.hide_money;
        var games = state.data.games || [];
        var pg = state.data.pagination || { page: 1, page_size: 25, total: 0, total_pages: 1 };

        var meta = document.getElementById('perf-table-meta');
        if (meta) meta.textContent = formatInt(pg.total) + ' game' + (pg.total === 1 ? '' : 's') + (state.search ? ' matching "' + state.search + '"' : '');

        // Venue payout context for the drill-down: the aggregate the per-game
        // Payout % / % of pts columns break down.
        var target = state.data.payout_target_pct || 33;
        var summary = document.getElementById('perf-payout-summary');
        if (summary) {
            if (state.data.venue_payout_pct === null || state.data.venue_payout_pct === undefined) {
                summary.textContent = 'No redemption plays in this period.';
            } else {
                var vp = state.data.venue_payout_pct;
                summary.innerHTML = '';
                summary.appendChild(App.el('span', { textContent: 'Venue payout ' }));
                summary.appendChild(App.el('span', {
                    className: vp > target ? 'text-danger' : 'text-success',
                    style: { fontWeight: '600' },
                    textContent: vp + '%'
                }));
                summary.appendChild(App.el('span', { textContent: ' · target ≤ ' + target + '% · '
                    + formatInt(state.data.redemption_games || 0) + ' redemption games · sort by % of pts to find what dilutes it' }));
            }
        }

        el.innerHTML = '';
        if (games.length === 0) {
            el.appendChild(App.emptyState('📊', state.search
                ? 'No games match "' + state.search + '" in this period.'
                : 'No play activity recorded in this period yet.'));
            if (pager) pager.innerHTML = '';
            return;
        }

        var target = state.data.payout_target_pct || 33;
        var columns = [
            { key: '_rank', label: '#', sortable: false },
            { key: 'name', label: 'Game', sortable: true },
            { key: 'status', label: 'Status', sortable: false },
            { key: 'plays', label: 'Plays', sortable: true, right: true },
            { key: 'tickets', label: 'Tickets', sortable: true, right: true },
            { key: 'payout', label: 'Payout %', sortable: true, right: true },
            { key: 'points_share', label: '% of pts', sortable: true, right: true },
            { key: 'active_days', label: 'Active', sortable: true, right: true }
        ];
        if (money) columns.push({ key: 'cash', label: 'Revenue', sortable: true, right: true });
        columns.push({ key: 'avg', label: 'Avg tix/play', sortable: false, right: true });
        columns.push({ key: '_delta', label: 'vs prev', sortable: false, right: true });

        var thead = App.el('thead', {}, [
            App.el('tr', {}, columns.map(function(col) {
                var classes = [];
                if (col.sortable) classes.push('sortable');
                if (col.key === state.sort) classes.push('sorted');
                if (col.right) classes.push('text-right');
                var arrow = col.key === state.sort ? (col.key === 'name' ? ' ▲' : ' ▼') : '';
                var th = App.el('th', { className: classes.join(' '), textContent: col.label + arrow });
                if (col.sortable) {
                    th.addEventListener('click', function() {
                        state.sort = col.key;
                        state.page = 1;
                        load();
                    });
                }
                return th;
            }))
        ]);

        var rankBase = (pg.page - 1) * pg.page_size;
        var tbody = App.el('tbody', {}, games.map(function(g, i) {
            var cells = [
                App.el('td', { className: 'text-muted num-cell', textContent: (rankBase + i + 1) }),
                App.el('td', {}, [
                    App.el('div', { textContent: g.game_name || ('Game ' + g.game_id), style: { fontWeight: '500' } }),
                    App.el('div', { className: 'text-xs text-muted font-mono', textContent: g.game_id })
                ]),
                App.el('td', {}, [g.status ? App.statusBadge(g.status) : App.el('span', { className: 'text-muted', textContent: '—' })]),
                App.el('td', { className: 'text-right num-cell', textContent: g.plays > 0 ? formatInt(g.plays) : '—' }),
                App.el('td', { className: 'text-right num-cell' }, [
                    App.el('span', { className: g.tickets > 0 ? 'tickets-amount' : 'text-muted',
                        textContent: g.tickets > 0 ? formatInt(Math.round(g.tickets)) : '—' })
                ])
            ];
            // Payout % — red above target, green at/below, em-dash for
            // non-redemption games and redemption games with no points yet.
            if (g.payout_pct === null || g.payout_pct === undefined) {
                cells.push(App.el('td', { className: 'text-right num-cell text-muted', textContent: '—',
                    title: g.is_redemption ? 'No points played in this period' : 'Not a redemption game' }));
            } else {
                var over = g.payout_pct > target;
                cells.push(App.el('td', { className: 'text-right num-cell' }, [
                    App.el('span', {
                        className: over ? 'text-danger' : 'text-success',
                        style: over ? { fontWeight: '600' } : {},
                        textContent: g.payout_pct + '%',
                        title: formatInt(Math.round(g.tickets)) + ' tickets / ' + formatInt(Math.round(g.points)) + ' points'
                    })
                ]));
            }
            // Share of the venue's redemption-point denominator — a small bar
            // makes the dominant point-burners obvious at a glance.
            if (g.points_share === null || g.points_share === undefined) {
                cells.push(App.el('td', { className: 'text-right num-cell text-muted', textContent: '—' }));
            } else {
                cells.push(App.el('td', { className: 'text-right num-cell' }, [
                    App.el('div', { className: 'perf-share' }, [
                        App.el('span', { className: 'perf-share-val', textContent: g.points_share + '%' }),
                        App.el('span', { className: 'perf-share-bar' }, [
                            App.el('span', { className: 'perf-share-bar-fill',
                                style: { width: Math.min(100, g.points_share) + '%' } })
                        ])
                    ])
                ]));
            }
            // Utilization: active days out of days in the range + last-active.
            var daysIn = state.data.days_in_range || 1;
            cells.push(App.el('td', { className: 'text-right num-cell text-secondary',
                textContent: (g.active_days || 0) + (daysIn > 1 ? '/' + daysIn : ''),
                title: g.last_active_date ? 'Last active ' + g.last_active_date : 'No plays in this period' }));

            if (money) cells.push(App.el('td', { className: 'text-right num-cell', textContent: g.cash > 0 ? formatCurrency(g.cash) : '—' }));
            cells.push(App.el('td', { className: 'text-right num-cell text-secondary',
                textContent: g.avg_tickets_per_play > 0 ? Number(g.avg_tickets_per_play).toFixed(1) : '—' }));
            cells.push(App.el('td', { className: 'text-right' }, [delta(g.tickets, g.prev_tickets)]));

            return App.el('tr', {
                className: 'clickable-row',
                onClick: function() { showGameDetail(g.game_id); }
            }, cells);
        }));

        el.appendChild(App.el('table', { className: 'data-table directory-table' }, [thead, tbody]));

        if (pager) {
            pager.innerHTML = '';
            var pagingState = { page: pg.page, pageSize: pg.page_size, totalItems: pg.total };
            pager.appendChild(App.buildPaginationBar(pagingState, function() {
                state.page = pagingState.page;
                state.pageSize = pagingState.pageSize;
                load();
            }, { pageSizeOptions: [10, 25, 50, 100], itemLabel: 'games', showPageNumbers: true }));
        }
    }

    // ------------------------------------------------------------------
    // Per-game detail modal
    // ------------------------------------------------------------------
    async function showGameDetail(gameId) {
        var body = App.el('div', { id: 'perf-detail-body' }, [App.loading()]);
        var footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-ghost', textContent: 'Open in Games',
                onClick: function() { App.hideModal(); window.location.hash = '#/games?game=' + encodeURIComponent(gameId); } }),
            App.el('button', { className: 'btn btn-secondary', textContent: 'Close', onClick: function() { App.hideModal(); } })
        ]);
        App.showModal('Game performance', body, footer);

        var q = ['game_id=' + encodeURIComponent(gameId), 'range=' + encodeURIComponent(state.range)];
        if (state.range === 'custom') { q.push('from=' + encodeURIComponent(state.custom.from)); q.push('to=' + encodeURIComponent(state.custom.to)); }
        else { q.push('offset=' + encodeURIComponent(state.offset)); }

        try {
            var d = await API.get('analytics/game?' + q.join('&'));
            renderGameDetail(d);
        } catch (err) {
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-secondary', textContent: 'Failed to load: ' + err.message }));
        }
    }

    function renderGameDetail(d) {
        var body = document.getElementById('perf-detail-body');
        if (!body) return;
        body.innerHTML = '';
        var money = App.canSeeMoney() && !d.hide_money;
        var t = d.totals, p = d.previous_totals;

        body.appendChild(App.el('div', { className: 'flex-between' }, [
            App.el('div', {}, [
                App.el('div', { className: 'card-title', textContent: d.game.game_name || ('Game ' + d.game.game_id) }),
                App.el('p', { className: 'text-sm text-secondary', textContent: 'ID: ' + d.game.game_id + '  •  ' + d.range.label })
            ]),
            d.game.status ? App.statusBadge(d.game.status) : App.el('span')
        ]));

        var kpis = [
            ['Plays', formatInt(t.plays), delta(t.plays, p.plays)],
            ['Tickets', formatInt(Math.round(t.tickets)), delta(t.tickets, p.tickets)],
            ['Avg tickets / play', t.avg_tickets_per_play != null ? Number(t.avg_tickets_per_play).toFixed(1) : '—', null]
        ];
        if (money) kpis.splice(2, 0, ['Revenue', formatCurrency(t.cash), delta(t.cash, p.cash)]);

        body.appendChild(App.el('div', { className: 'perf-kpi-grid perf-kpi-grid-compact' }, kpis.map(function(k) {
            var ch = [App.el('div', { className: 'perf-kpi-label', textContent: k[0] }), App.el('div', { className: 'perf-kpi-value', textContent: k[1] })];
            if (k[2]) ch.push(k[2]);
            return App.el('div', { className: 'perf-kpi' }, ch);
        })));

        body.appendChild(App.el('div', { className: 'subsection' }, [
            App.el('div', { className: 'subsection-title', textContent: 'Trend (' + granularityNote(d.range.granularity) + ')' }),
            App.el('div', { className: 'perf-chart-holder perf-chart-holder-sm' }, [App.el('canvas', { id: 'perf-detail-canvas' })])
        ]));

        renderDetailChart(d);
    }

    async function renderDetailChart(d) {
        var ok = await ensureChart();
        var canvas = document.getElementById('perf-detail-canvas');
        if (!ok || !canvas) return;
        if (state.modalChart) { try { state.modalChart.destroy(); } catch (e) {} state.modalChart = null; }

        var points = (d.series && d.series.points) || [];
        var labels = points.map(function(p) { return p.label; });
        var theme = readThemeColors();
        var showCash = App.canSeeMoney() && !d.hide_money;

        var datasets = [
            { type: 'bar', label: 'Tickets', data: points.map(function(p) { return Number(p.tickets); }),
              backgroundColor: theme.tickets, borderRadius: 3, maxBarThickness: 40, yAxisID: 'y', order: 2 },
            { type: 'line', label: 'Plays', data: points.map(function(p) { return Number(p.plays); }),
              borderColor: theme.accent, backgroundColor: theme.accentSubtle, borderWidth: 2, tension: 0.25,
              pointRadius: points.length > 40 ? 0 : 2, yAxisID: 'y1', order: 1 }
        ];

        state.modalChart = new window.Chart(canvas, {
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false, animation: { duration: 300 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { color: theme.text } },
                    tooltip: { backgroundColor: theme.bgCard, titleColor: theme.text, bodyColor: theme.textSecondary, borderColor: theme.border, borderWidth: 1, padding: 8 }
                },
                scales: {
                    x: { ticks: { color: theme.textSecondary, autoSkip: true, maxRotation: 0 }, grid: { display: false } },
                    y: { position: 'left', beginAtZero: true, title: { display: true, text: 'Tickets', color: theme.textSecondary }, ticks: { color: theme.textSecondary, precision: 0 }, grid: { color: theme.gridLine } },
                    y1: { position: 'right', beginAtZero: true, title: { display: true, text: 'Plays', color: theme.textSecondary }, ticks: { color: theme.textSecondary, precision: 0 }, grid: { drawOnChartArea: false } }
                }
            }
        });
        void showCash; // revenue intentionally omitted from the compact detail chart to keep two clean axes
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    function ensureChart() {
        // Chart.js is loaded via a deferred CDN script; on a cold load it may
        // not be ready the instant this page renders. Poll briefly for it.
        return new Promise(function(resolve) {
            if (window.Chart) return resolve(true);
            var tries = 0;
            var timer = setInterval(function() {
                if (window.Chart) { clearInterval(timer); resolve(true); }
                else if (++tries > 50) { clearInterval(timer); resolve(false); } // ~5s
            }, 100);
        });
    }

    function readThemeColors() {
        var styles = getComputedStyle(document.documentElement);
        var get = function(name, fallback) { var v = styles.getPropertyValue(name); return (v && v.trim()) || fallback; };
        var isLight = document.documentElement.getAttribute('data-theme') === 'light';
        return {
            accent:        get('--accent', '#5b8def'),
            accentSubtle:  isLight ? 'rgba(53, 103, 204, 0.18)' : 'rgba(91, 141, 239, 0.32)',
            success:       get('--success', '#3dd68c'),
            tickets:       get('--tickets', '#f5b942'),
            text:          get('--text-primary', '#e1e4ed'),
            textSecondary: get('--text-secondary', '#8891a8'),
            border:        get('--border', '#1e2638'),
            bgCard:        get('--bg-card', '#151a28'),
            gridLine:      isLight ? 'rgba(20, 35, 66, 0.08)' : 'rgba(255, 255, 255, 0.06)'
        };
    }

    function formatInt(n) { n = Number(n) || 0; return Math.round(n).toLocaleString(); }
    function formatCurrency(n) { n = Number(n) || 0; return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
})();
