/**
 * Games Dashboard — analytics + per-game controls.
 *
 * Layout (top to bottom):
 *   1. Page header with window selector (Today / Week / Month / Year / All time)
 *      and Sync / Refresh buttons.
 *   2. Status filter pills (All / Running / Paused / Out of service) with counts.
 *   3. KPI grid: total plays, total tickets, total points, unique cards, etc.
 *   4. Charts row: plays-over-time (line), status distribution (doughnut).
 *   5. Leaderboards row: top by plays, top by tickets, top by points (3 horizontal-bar charts).
 *   6. Live play feed (recent plays from cache).
 *   7. Games directory: searchable, sortable table with per-row pause/unpause/oos buttons.
 *
 * Charts come from Chart.js (loaded via CDN in index.php). The dashboard
 * degrades gracefully if Chart.js fails to load: charts render as text-only
 * fallbacks while everything else (KPIs, table, controls) keeps working.
 *
 * No dollar/cash amounts are displayed — this dashboard is for floor staff.
 * All money fields from the upstream feed are intentionally hidden.
 */
(function() {
    App.registerRoute('#/games', { render: renderGamesPage });

    var ANALYTICS_REFRESH_MS = 30000;
    var FEED_REFRESH_MS = 15000;
    var FEED_LIMIT = 30;

    // Module-level state (persists across re-renders within the page).
    var currentWindow = 'day';
    var allGames = [];
    var statusFilter = 'all';
    var searchTerm = '';
    var gameSortCol = 'game_name';
    var gameSortDir = 'asc';

    var lastAnalytics = null;
    var lastFeedData = null;
    var charts = {}; // { plays: Chart, status: Chart, topPlays: Chart, topTickets: Chart, topPoints: Chart }

    var refreshCleanups = [];
    var pageGen = 0;

    // ---------- Number / formatting helpers ----------

    function fmtNum(n) {
        var v = typeof n === 'number' ? n : parseFloat(n) || 0;
        if (v % 1 !== 0) v = Math.round(v * 100) / 100;
        // Use toLocaleString for thousand separators
        return v.toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    function fmtInt(n) {
        var v = typeof n === 'number' ? n : parseInt(n, 10) || 0;
        return v.toLocaleString('en-US');
    }

    function fmtPoints(n) {
        if (typeof n !== 'number') n = parseFloat(n) || 0;
        return n % 1 === 0 ? fmtInt(n) : fmtNum(n);
    }

    /**
     * Format a bucket key into a human-friendly chart label based on the bucket
     * unit. Bucket keys are emitted by the backend in canonical formats:
     *   hour  → "YYYY-MM-DDTHH:00"
     *   day   → "YYYY-MM-DD"
     *   month → "YYYY-MM"
     */
    function formatBucketLabel(bucket, unit) {
        if (!bucket) return '';
        if (unit === 'hour') {
            // Show "3 PM" style for the day window
            var parts = bucket.split('T');
            if (parts.length !== 2) return bucket;
            var hourStr = parts[1].split(':')[0];
            var h = parseInt(hourStr, 10);
            if (isNaN(h)) return bucket;
            var ampm = h >= 12 ? 'PM' : 'AM';
            var h12 = h % 12 || 12;
            return h12 + ' ' + ampm;
        }
        if (unit === 'month') {
            var ym = bucket.split('-');
            if (ym.length !== 2) return bucket;
            var monthIdx = parseInt(ym[1], 10) - 1;
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            if (monthIdx < 0 || monthIdx > 11) return bucket;
            return months[monthIdx] + " '" + ym[0].slice(2);
        }
        // Day: YYYY-MM-DD → Mon DD
        var d = bucket.split('-');
        if (d.length !== 3) return bucket;
        var monthsShort = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var mIdx = parseInt(d[1], 10) - 1;
        if (mIdx < 0 || mIdx > 11) return bucket;
        return monthsShort[mIdx] + ' ' + parseInt(d[2], 10);
    }

    function statusLabel(status) {
        if (status === 'enabled') return 'Running';
        if (status === 'paused') return 'Paused';
        if (status === 'outOfService') return 'Out of Service';
        if (status === 'unknown' || !status) return 'Unknown';
        return status;
    }

    function chartThemeColors() {
        // Read CSS vars at the time the chart is built so dark/light theme
        // switching is reflected on next render.
        var styles = getComputedStyle(document.documentElement);
        return {
            text: (styles.getPropertyValue('--text-primary') || '#e1e4ed').trim(),
            secondary: (styles.getPropertyValue('--text-secondary') || '#8891a8').trim(),
            muted: (styles.getPropertyValue('--text-muted') || '#6b7394').trim(),
            grid: (styles.getPropertyValue('--border') || '#1e2638').trim(),
            accent: (styles.getPropertyValue('--accent') || '#5b8def').trim(),
            success: (styles.getPropertyValue('--success') || '#3dd68c').trim(),
            warning: (styles.getPropertyValue('--warning') || '#f0a944').trim(),
            danger: (styles.getPropertyValue('--danger') || '#e5534b').trim(),
        };
    }

    // ---------- Top-level render ----------

    async function renderGamesPage(container) {
        pageGen++;
        var myGen = pageGen;
        cleanupCharts();
        cleanupTimers();

        container.appendChild(buildHeader());
        container.appendChild(buildWindowBar());
        container.appendChild(buildKpiGrid());
        container.appendChild(buildChartsRow());
        container.appendChild(buildLeaderboardsRow());
        container.appendChild(buildFeedAndDirectoryRow());

        // Initial loads
        await Promise.all([
            loadAnalytics(myGen),
            loadFeed(myGen),
            loadGames(myGen)
        ]);

        // Periodic refresh of analytics + feed (game directory is refreshed manually).
        var analyticsTimer = App.createVisibilityAwareInterval(function() {
            loadAnalytics(myGen);
        }, ANALYTICS_REFRESH_MS, { runImmediately: false, runOnVisible: true });
        var feedTimer = App.createVisibilityAwareInterval(function() {
            loadFeed(myGen);
        }, FEED_REFRESH_MS, { runImmediately: false, runOnVisible: true });

        refreshCleanups.push(analyticsTimer, feedTimer);

        return function cleanup() {
            cleanupTimers();
            cleanupCharts();
        };
    }

    function cleanupTimers() {
        refreshCleanups.forEach(function(c) { try { c(); } catch (e) {} });
        refreshCleanups = [];
    }

    function cleanupCharts() {
        Object.keys(charts).forEach(function(k) {
            try { charts[k] && charts[k].destroy(); } catch (e) {}
            delete charts[k];
        });
    }

    // ---------- Header & controls ----------

    function buildHeader() {
        return App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Games' }),
                App.el('p', { className: 'page-subtitle',
                    textContent: 'Live status, analytics, and per-game controls.' })
            ]),
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', {
                    className: 'btn btn-ghost',
                    textContent: 'Sync games',
                    title: 'Pull the latest game list and statuses from CenterEdge',
                    onClick: function() { syncGames(); }
                }),
                App.el('button', {
                    className: 'btn btn-primary',
                    textContent: 'Refresh',
                    onClick: function() { manualRefresh(); }
                })
            ])
        ]);
    }

    function buildWindowBar() {
        var bar = App.el('div', { className: 'games-window-bar' });
        var label = App.el('div', { className: 'games-window-bar-label', textContent: 'Window' });
        bar.appendChild(label);

        var pillRow = App.el('div', { className: 'games-window-pills', id: 'games-window-pills' });
        [
            ['day', 'Today'],
            ['week', '7 days'],
            ['month', '30 days'],
            ['year', '12 months'],
            ['all', 'All time']
        ].forEach(function(opt) {
            var pill = App.el('button', {
                className: 'filter-pill' + (currentWindow === opt[0] ? ' active' : ''),
                'data-window': opt[0],
                textContent: opt[1],
                onClick: function() {
                    if (currentWindow === opt[0]) return;
                    currentWindow = opt[0];
                    updateWindowPills();
                    loadAnalytics(pageGen);
                }
            });
            pillRow.appendChild(pill);
        });
        bar.appendChild(pillRow);

        var meta = App.el('div', { className: 'games-window-meta', id: 'games-window-meta',
            textContent: 'Loading…' });
        bar.appendChild(meta);

        return bar;
    }

    function updateWindowPills() {
        var pills = document.querySelectorAll('#games-window-pills .filter-pill');
        pills.forEach(function(p) {
            if (p.getAttribute('data-window') === currentWindow) p.classList.add('active');
            else p.classList.remove('active');
        });
    }

    // ---------- KPI grid ----------

    function buildKpiGrid() {
        return App.el('div', { className: 'kpi-grid', id: 'games-kpi-grid' }, [
            App.loading()
        ]);
    }

    function renderKpiGrid(analytics) {
        var grid = document.getElementById('games-kpi-grid');
        if (!grid) return;
        grid.innerHTML = '';

        var totals = analytics.totals || {};
        var status = analytics.status_breakdown || {};

        var kpis = [
            { label: 'Plays', value: fmtInt(totals.plays || 0), tone: 'accent', icon: '▶' },
            { label: 'Tickets', value: fmtPoints(totals.tickets || 0), tone: 'success', icon: '★' },
            { label: 'Points', value: fmtPoints(totals.total_points || 0), tone: 'accent', icon: '◆' },
            { label: 'Unique games played', value: fmtInt(totals.unique_games || 0), tone: 'muted', icon: '▣' },
            { label: 'Unique cards', value: fmtInt(totals.unique_cards || 0), tone: 'muted', icon: '◫' },
            { label: 'Running', value: fmtInt(status.enabled || 0), tone: 'success', icon: '●' },
            { label: 'Paused', value: fmtInt(status.paused || 0), tone: 'warning', icon: '❚' },
            { label: 'Out of service', value: fmtInt(status.outOfService || 0), tone: 'danger', icon: '✕' },
        ];

        kpis.forEach(function(k) {
            grid.appendChild(App.el('div', { className: 'kpi-card kpi-tone-' + k.tone }, [
                App.el('div', { className: 'kpi-icon', textContent: k.icon }),
                App.el('div', { className: 'kpi-body' }, [
                    App.el('div', { className: 'kpi-label', textContent: k.label }),
                    App.el('div', { className: 'kpi-value', textContent: k.value })
                ])
            ]));
        });
    }

    // ---------- Charts row ----------

    function buildChartsRow() {
        return App.el('div', { className: 'games-charts-row' }, [
            App.el('div', { className: 'card chart-card', id: 'plays-over-time-card' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Plays over time' }),
                    App.el('span', { id: 'plays-over-time-meta', className: 'text-sm text-secondary' })
                ]),
                App.el('div', { className: 'chart-canvas-wrap' }, [
                    App.el('canvas', { id: 'chart-plays-over-time' })
                ])
            ]),
            App.el('div', { className: 'card chart-card chart-card-narrow', id: 'status-distribution-card' }, [
                App.el('div', { className: 'card-header' }, [
                    App.el('div', { className: 'card-title', textContent: 'Status distribution' })
                ]),
                App.el('div', { className: 'chart-canvas-wrap chart-canvas-wrap-narrow' }, [
                    App.el('canvas', { id: 'chart-status-distribution' })
                ]),
                App.el('div', { id: 'status-distribution-legend', className: 'chart-legend' })
            ])
        ]);
    }

    function renderPlaysOverTimeChart(analytics) {
        var meta = document.getElementById('plays-over-time-meta');
        if (meta) {
            var unitLabel = analytics.bucket_unit === 'hour' ? 'per hour'
                : analytics.bucket_unit === 'month' ? 'per month'
                : 'per day';
            meta.textContent = unitLabel;
        }

        var canvas = document.getElementById('chart-plays-over-time');
        if (!canvas) return;
        var series = analytics.timeseries || [];
        var labels = series.map(function(p) { return formatBucketLabel(p.bucket, analytics.bucket_unit); });
        var plays = series.map(function(p) { return p.plays || 0; });
        var tickets = series.map(function(p) { return Math.round((p.tickets || 0) * 100) / 100; });

        if (typeof Chart === 'undefined') {
            renderChartFallback(canvas.parentNode, series.length === 0
                ? 'No play data in this window.'
                : (plays.reduce(function(a, b) { return a + b; }, 0) + ' plays across ' + plays.length + ' buckets'));
            return;
        }

        var colors = chartThemeColors();

        if (charts.plays) {
            // Update in-place for smoother transitions on window changes.
            charts.plays.data.labels = labels;
            charts.plays.data.datasets[0].data = plays;
            charts.plays.data.datasets[1].data = tickets;
            charts.plays.options.scales.x.ticks.color = colors.secondary;
            charts.plays.options.scales.y.ticks.color = colors.secondary;
            charts.plays.options.scales.y.grid.color = colors.grid;
            charts.plays.options.scales.y1.ticks.color = colors.secondary;
            charts.plays.update('none');
            return;
        }

        charts.plays = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Plays',
                        data: plays,
                        borderColor: colors.accent,
                        backgroundColor: hexToRgba(colors.accent, 0.18),
                        borderWidth: 2,
                        fill: true,
                        tension: 0.32,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Tickets',
                        data: tickets,
                        borderColor: colors.success,
                        backgroundColor: hexToRgba(colors.success, 0.0),
                        borderWidth: 2,
                        borderDash: [4, 4],
                        fill: false,
                        tension: 0.32,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: colors.secondary, boxWidth: 14 } },
                    tooltip: {
                        backgroundColor: '#0b0e14',
                        titleColor: '#e1e4ed',
                        bodyColor: '#e1e4ed',
                        borderColor: colors.grid,
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        ticks: { color: colors.secondary, maxRotation: 0, autoSkip: true },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: { color: colors.secondary, precision: 0 },
                        grid: { color: colors.grid }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        ticks: { color: colors.secondary, precision: 0 },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    function renderStatusDistributionChart(analytics) {
        var canvas = document.getElementById('chart-status-distribution');
        if (!canvas) return;

        var status = analytics.status_breakdown || {};
        var labels = ['Running', 'Paused', 'Out of service', 'Unknown'];
        var values = [status.enabled || 0, status.paused || 0, status.outOfService || 0, status.unknown || 0];
        var total = values.reduce(function(a, b) { return a + b; }, 0);

        var legendEl = document.getElementById('status-distribution-legend');
        if (legendEl) {
            legendEl.innerHTML = '';
            ['enabled', 'paused', 'outOfService', 'unknown'].forEach(function(key, idx) {
                var pct = total > 0 ? Math.round((values[idx] / total) * 100) : 0;
                legendEl.appendChild(App.el('div', { className: 'chart-legend-row' }, [
                    App.el('span', { className: 'chart-legend-dot chart-legend-dot-' + key }),
                    App.el('span', { className: 'chart-legend-label', textContent: statusLabel(key) }),
                    App.el('span', { className: 'chart-legend-value',
                        textContent: fmtInt(values[idx]) + ' · ' + pct + '%' })
                ]));
            });
        }

        if (typeof Chart === 'undefined') {
            renderChartFallback(canvas.parentNode, total === 0 ? 'No games in cache.' : '');
            return;
        }

        var colors = chartThemeColors();
        var palette = [colors.success, colors.warning, colors.danger, colors.muted];

        if (charts.status) {
            charts.status.data.datasets[0].data = values;
            charts.status.update('none');
            return;
        }

        charts.status = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: palette,
                    borderColor: 'transparent',
                    borderWidth: 0,
                    spacing: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0b0e14',
                        titleColor: '#e1e4ed',
                        bodyColor: '#e1e4ed',
                        borderColor: colors.grid,
                        borderWidth: 1,
                        callbacks: {
                            label: function(ctx) {
                                var v = ctx.parsed;
                                var pct = total > 0 ? Math.round((v / total) * 100) : 0;
                                return ctx.label + ': ' + fmtInt(v) + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ---------- Leaderboards row ----------

    function buildLeaderboardsRow() {
        return App.el('div', { className: 'games-leaderboards-row' }, [
            buildLeaderboardCard('Top by plays', 'top-plays', 'accent'),
            buildLeaderboardCard('Top by tickets', 'top-tickets', 'success'),
            buildLeaderboardCard('Top by points', 'top-points', 'accent')
        ]);
    }

    function buildLeaderboardCard(title, slug, tone) {
        return App.el('div', { className: 'card chart-card chart-card-leaderboard' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'card-title', textContent: title })
            ]),
            App.el('div', { className: 'chart-canvas-wrap chart-canvas-wrap-leaderboard' }, [
                App.el('canvas', { id: 'chart-' + slug, 'data-tone': tone })
            ]),
            App.el('div', { id: 'fallback-' + slug, className: 'chart-fallback hidden' })
        ]);
    }

    function renderLeaderboardChart(slug, items, valueKey, valueLabel) {
        var canvas = document.getElementById('chart-' + slug);
        if (!canvas) return;
        var fallback = document.getElementById('fallback-' + slug);

        if (!items || items.length === 0) {
            canvas.classList.add('hidden');
            if (fallback) {
                fallback.classList.remove('hidden');
                fallback.innerHTML = '';
                fallback.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: 'No plays in this window.' }));
            }
            if (charts[slug]) { try { charts[slug].destroy(); } catch (e) {} delete charts[slug]; }
            return;
        }

        canvas.classList.remove('hidden');
        if (fallback) fallback.classList.add('hidden');

        var labels = items.map(function(g) { return g.game_name || ('Game ' + g.game_id); });
        var values = items.map(function(g) { return Math.round((g[valueKey] || 0) * 100) / 100; });

        if (typeof Chart === 'undefined') {
            // No chart library — show a simple text leaderboard.
            canvas.classList.add('hidden');
            if (fallback) {
                fallback.classList.remove('hidden');
                fallback.innerHTML = '';
                var ol = App.el('ol', { className: 'top-games-list' });
                items.forEach(function(g, i) {
                    ol.appendChild(App.el('li', { className: 'top-games-item' }, [
                        App.el('div', { className: 'top-games-rank', textContent: '#' + (i + 1) }),
                        App.el('div', { className: 'top-games-body' }, [
                            App.el('div', { className: 'plain-list-title',
                                textContent: g.game_name || ('Game ' + g.game_id) }),
                            App.el('div', { className: 'text-sm text-secondary',
                                textContent: fmtPoints(g[valueKey] || 0) + ' ' + valueLabel })
                        ])
                    ]));
                });
                fallback.appendChild(ol);
            }
            return;
        }

        var tone = canvas.getAttribute('data-tone') || 'accent';
        var colors = chartThemeColors();
        var color = tone === 'success' ? colors.success : colors.accent;

        if (charts[slug]) {
            charts[slug].data.labels = labels;
            charts[slug].data.datasets[0].data = values;
            charts[slug].data.datasets[0].label = valueLabel;
            charts[slug].update('none');
            return;
        }

        charts[slug] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: valueLabel,
                    data: values,
                    backgroundColor: hexToRgba(color, 0.45),
                    borderColor: color,
                    borderWidth: 1,
                    borderRadius: 4,
                    maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0b0e14',
                        titleColor: '#e1e4ed',
                        bodyColor: '#e1e4ed',
                        borderColor: colors.grid,
                        borderWidth: 1,
                        callbacks: {
                            label: function(ctx) { return fmtPoints(ctx.parsed.x) + ' ' + valueLabel; }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { color: colors.secondary, precision: 0 },
                        grid: { color: colors.grid }
                    },
                    y: {
                        ticks: { color: colors.text, autoSkip: false, font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    function renderChartFallback(parent, message) {
        if (!parent) return;
        var existing = parent.querySelector('.chart-fallback-inline');
        if (!existing) {
            existing = App.el('div', { className: 'chart-fallback-inline' });
            parent.appendChild(existing);
        }
        existing.innerHTML = '';
        existing.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: message }));
    }

    // ---------- Feed + games directory ----------

    function buildFeedAndDirectoryRow() {
        return App.el('div', { className: 'games-feed-and-directory' }, [
            App.el('div', { className: 'card', id: 'games-feed-wrap' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Live play feed' }),
                    App.el('span', { id: 'games-feed-meta', className: 'text-sm text-secondary' })
                ]),
                App.el('div', { id: 'games-feed-body', className: 'card-body' }, [App.loading()])
            ]),
            App.el('div', { className: 'card', id: 'games-directory-wrap' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Games directory' }),
                    App.el('span', { id: 'games-directory-meta', className: 'text-sm text-secondary' })
                ]),
                App.el('div', { className: 'card-body' }, [
                    App.el('div', { className: 'toolbar-row' }, [
                        App.el('input', {
                            id: 'games-search',
                            className: 'form-input',
                            type: 'text',
                            placeholder: 'Search games…',
                            style: { flex: '1' },
                            onInput: function(e) {
                                searchTerm = (e.target.value || '').toLowerCase();
                                renderGameTable();
                            }
                        }),
                        App.el('div', { className: 'filter-pills', id: 'games-status-pills' })
                    ]),
                    App.el('div', { id: 'games-table-wrap' }, [App.loading()])
                ])
            ])
        ]);
    }

    function renderStatusPills() {
        var el = document.getElementById('games-status-pills');
        if (!el) return;
        el.innerHTML = '';
        var counts = {
            all: allGames.length,
            enabled: allGames.filter(function(g) { return g.operation_status === 'enabled'; }).length,
            paused: allGames.filter(function(g) { return g.operation_status === 'paused'; }).length,
            outOfService: allGames.filter(function(g) { return g.operation_status === 'outOfService'; }).length,
        };
        var pills = [
            { key: 'all', label: 'All', activeCls: 'active' },
            { key: 'enabled', label: 'Running', activeCls: 'active-enabled' },
            { key: 'paused', label: 'Paused', activeCls: 'active-paused' },
            { key: 'outOfService', label: 'Out of service', activeCls: 'active-oos' }
        ];
        pills.forEach(function(p) {
            el.appendChild(App.el('button', {
                className: 'filter-pill' + (statusFilter === p.key ? ' ' + p.activeCls : ''),
                onClick: function() {
                    statusFilter = p.key;
                    renderStatusPills();
                    renderGameTable();
                }
            }, [
                App.el('span', { textContent: p.label }),
                App.el('span', { className: 'pill-count', textContent: '(' + counts[p.key] + ')' })
            ]));
        });
    }

    function getFilteredGames() {
        var filtered = allGames;
        if (statusFilter !== 'all') {
            filtered = filtered.filter(function(g) { return g.operation_status === statusFilter; });
        }
        if (searchTerm) {
            filtered = filtered.filter(function(g) {
                var name = (g.game_name || '').toLowerCase();
                var id = (g.game_id || '').toLowerCase();
                return name.indexOf(searchTerm) !== -1 || id.indexOf(searchTerm) !== -1;
            });
        }
        // Sort
        var dirMul = gameSortDir === 'asc' ? 1 : -1;
        filtered = filtered.slice().sort(function(a, b) {
            var aV, bV;
            if (gameSortCol === 'operation_status') {
                var order = { enabled: 0, paused: 1, outOfService: 2 };
                aV = order[a.operation_status] !== undefined ? order[a.operation_status] : 3;
                bV = order[b.operation_status] !== undefined ? order[b.operation_status] : 3;
            } else if (gameSortCol === 'plays' || gameSortCol === 'tickets') {
                aV = (a._stats && a._stats[gameSortCol]) || 0;
                bV = (b._stats && b._stats[gameSortCol]) || 0;
            } else {
                aV = (a[gameSortCol] || '').toString().toLowerCase();
                bV = (b[gameSortCol] || '').toString().toLowerCase();
            }
            if (aV < bV) return -1 * dirMul;
            if (aV > bV) return 1 * dirMul;
            return 0;
        });
        return filtered;
    }

    function renderGameTable() {
        var wrap = document.getElementById('games-table-wrap');
        if (!wrap) return;
        wrap.innerHTML = '';

        var filtered = getFilteredGames();
        var meta = document.getElementById('games-directory-meta');
        if (meta) {
            meta.textContent = filtered.length + ' of ' + allGames.length + ' games';
        }

        if (allGames.length === 0) {
            wrap.appendChild(App.emptyState('◆',
                'No games in the cache yet. Click "Sync games" to pull the catalog from CenterEdge.'));
            return;
        }
        if (filtered.length === 0) {
            wrap.appendChild(App.el('p', { className: 'text-sm text-secondary',
                style: { padding: '1rem 0' }, textContent: 'No games match these filters.' }));
            return;
        }

        var scrollContainer = App.el('div', { className: 'table-scroll-container games-table-scroll' });
        var table = App.el('table', { className: 'table games-table' });

        var cols = [
            { key: 'game_name', label: 'Game', sortable: true, cls: '' },
            { key: 'operation_status', label: 'Status', sortable: true, cls: '' },
            { key: 'plays', label: 'Plays', sortable: true, cls: 'text-right' },
            { key: 'tickets', label: 'Tickets', sortable: true, cls: 'text-right' },
            { key: 'actions', label: '', sortable: false, cls: 'text-right games-actions-col' }
        ];

        var thead = App.el('thead');
        var headerRow = App.el('tr');
        cols.forEach(function(col) {
            var th = App.el('th', {
                className: (col.sortable ? 'sortable ' : '') +
                    (gameSortCol === col.key ? 'sorted ' : '') + (col.cls || '')
            }, [App.el('span', { textContent: col.label })]);
            if (col.sortable) {
                var icon = gameSortCol === col.key
                    ? (gameSortDir === 'asc' ? '▲' : '▼')
                    : '▴';
                th.appendChild(App.el('span', { className: 'sort-icon', textContent: icon }));
                th.addEventListener('click', function() {
                    if (gameSortCol === col.key) {
                        gameSortDir = gameSortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        gameSortCol = col.key;
                        gameSortDir = col.key === 'plays' || col.key === 'tickets' ? 'desc' : 'asc';
                    }
                    renderGameTable();
                });
            }
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        var tbody = App.el('tbody');
        filtered.forEach(function(g) {
            tbody.appendChild(buildGameRow(g));
        });
        table.appendChild(tbody);

        scrollContainer.appendChild(table);
        wrap.appendChild(scrollContainer);
    }

    function buildGameRow(g) {
        var stats = g._stats || { plays: 0, tickets: 0 };
        var status = g.operation_status || 'enabled';

        var nameCell = App.el('td', {}, [
            App.el('div', { className: 'games-row-name' }, [
                App.el('span', { className: 'games-row-name-text', textContent: g.game_name || ('Game ' + g.game_id) }),
                App.el('span', { className: 'games-row-id text-muted text-xs font-mono', textContent: g.game_id })
            ])
        ]);

        var statusCell = App.el('td', {}, [App.statusBadge(status)]);
        var playsCell = App.el('td', { className: 'text-right text-sm', textContent: fmtInt(stats.plays || 0) });
        var ticketsCell = App.el('td', { className: 'text-right text-sm', textContent: fmtPoints(stats.tickets || 0) });

        var actionsRow = App.el('div', { className: 'games-row-actions' });
        // Run / unpause
        actionsRow.appendChild(App.el('button', {
            className: 'btn btn-xs btn-success' + (status === 'enabled' ? ' btn-disabled-ish' : ''),
            disabled: status === 'enabled',
            title: status === 'enabled' ? 'Already running' : 'Resume this game',
            textContent: 'Run',
            onClick: function(e) {
                e.stopPropagation();
                changeGameStatus(g, 'enabled');
            }
        }));
        // Pause
        actionsRow.appendChild(App.el('button', {
            className: 'btn btn-xs btn-warning',
            disabled: status === 'paused',
            title: status === 'paused' ? 'Already paused' : 'Pause this game',
            textContent: 'Pause',
            onClick: function(e) {
                e.stopPropagation();
                changeGameStatus(g, 'paused');
            }
        }));
        // Out of service
        actionsRow.appendChild(App.el('button', {
            className: 'btn btn-xs btn-danger',
            disabled: status === 'outOfService',
            title: status === 'outOfService' ? 'Already out of service' : 'Mark out of service',
            textContent: 'OOS',
            onClick: function(e) {
                e.stopPropagation();
                changeGameStatus(g, 'outOfService');
            }
        }));
        // Details
        actionsRow.appendChild(App.el('button', {
            className: 'btn btn-xs btn-ghost',
            textContent: 'Details',
            onClick: function(e) {
                e.stopPropagation();
                showGameDetail(g.game_id);
            }
        }));

        var actionsCell = App.el('td', { className: 'text-right games-actions-col' }, [actionsRow]);

        var row = App.el('tr', { 'data-game-id': g.game_id }, [
            nameCell, statusCell, playsCell, ticketsCell, actionsCell
        ]);
        return row;
    }

    // ---------- Live play feed ----------

    function renderFeed(data) {
        var body = document.getElementById('games-feed-body');
        var meta = document.getElementById('games-feed-meta');
        if (!body) return;
        body.innerHTML = '';

        if (meta) {
            var pieces = [];
            if (data.last_poll_at) pieces.push('Last poll ' + App.formatRelative(data.last_poll_at));
            if (typeof data.total_cached === 'number') pieces.push(fmtInt(data.total_cached) + ' cached');
            meta.textContent = pieces.join('  •  ');
        }

        var txs = data.transactions || [];
        if (txs.length === 0) {
            body.appendChild(App.emptyState('□',
                'No plays cached yet. The watchdog cron polls every minute; click Refresh to force a poll now.'));
            return;
        }

        var ul = App.el('ul', { className: 'plain-list games-feed-list' });
        txs.forEach(function(t) { ul.appendChild(buildFeedRow(t)); });
        body.appendChild(ul);
    }

    function buildFeedRow(t) {
        var time = App.formatDatetime(t.transaction_time);
        var name = t.game_name || ('Game ' + t.game_id);
        var card = t.no_card ? 'no card' : (t.card_number || '-');

        var meta = [];
        if (t.used_time_play) meta.push('time play');
        if (t.used_play_privilege) meta.push('privilege');
        var pts = (parseFloat(t.regular_points) || 0) + (parseFloat(t.bonus_points) || 0);
        var tickets = parseFloat(t.redemption_tickets) || 0;
        if (pts) meta.push(fmtPoints(pts) + ' pts');
        if (tickets) meta.push('+' + fmtPoints(tickets) + ' tix');

        return App.el('li', { className: 'plain-list-item feed-row' }, [
            App.el('div', { className: 'feed-row-time text-sm text-secondary', textContent: time }),
            App.el('div', { className: 'feed-row-main' }, [
                App.el('div', { className: 'plain-list-title', textContent: name }),
                App.el('div', { className: 'text-sm text-secondary',
                    textContent: 'Card ' + card + (meta.length ? '  •  ' + meta.join(' • ') : '') })
            ])
        ]);
    }

    // ---------- Game detail modal ----------

    /**
     * Build a Game-shaped object from a cached row so the modal can render
     * even when the upstream `/games/{id}` endpoint is unavailable. The
     * CenterEdge API treats `getSingleGame` as an optional capability that
     * defaults to false — if a card system doesn't support it, the live
     * lookup 404s. The cached row (loaded by GET /api/games) carries every
     * field the modal actually needs except `supportedActions`.
     */
    function gameFromCache(gameId) {
        var key = String(gameId);
        for (var i = 0; i < allGames.length; i++) {
            var g = allGames[i];
            if (String(g.game_id) === key) {
                return {
                    id: g.game_id,
                    name: g.game_name,
                    operationStatus: g.operation_status,
                    categories: g.categories || [],
                    supportedActions: [],
                    _fromCache: true
                };
            }
        }
        return null;
    }

    async function showGameDetail(gameId) {
        var body = App.el('div', { id: 'game-detail-body' }, [App.loading()]);
        var footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', {
                className: 'btn btn-secondary',
                textContent: 'Close',
                onClick: function() { App.hideModal(); }
            })
        ]);
        App.showModal('Game detail', body, footer);

        try {
            var game = await API.get('games/' + encodeURIComponent(gameId));
            renderGameDetailModal(game);
        } catch (err) {
            // Fallback to the cached row when the upstream doesn't support
            // single-game lookup or the game momentarily can't be reached.
            var cached = gameFromCache(gameId);
            if (cached) {
                renderGameDetailModal(cached);
                return;
            }
            body.innerHTML = '';
            var msg = err.status === 404
                ? 'Game not found in cache. Try Sync games to refresh the catalog.'
                : 'Failed to load: ' + err.message;
            body.appendChild(App.el('p', { className: 'text-secondary', textContent: msg }));
        }
    }

    function renderGameDetailModal(game) {
        var body = document.getElementById('game-detail-body');
        if (!body) return;
        body.innerHTML = '';

        var status = game.operationStatus || 'enabled';

        body.appendChild(App.el('div', { className: 'flex-between' }, [
            App.el('div', {}, [
                App.el('div', { className: 'card-title', textContent: game.name || ('Game ' + game.id) }),
                App.el('p', { className: 'text-sm text-secondary', textContent: 'ID: ' + game.id })
            ]),
            App.statusBadge(status)
        ]));

        if (game._fromCache) {
            body.appendChild(App.el('p', { className: 'text-xs text-muted',
                style: { marginTop: '0.4rem' },
                textContent: 'Showing cached details — this card system doesn’t expose live single-game data.' }));
        }
        if (game.virtualPlayEnabled) {
            body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                style: { marginTop: '0.4rem' }, textContent: 'Virtual play enabled.' }));
        }
        if (game.categories && game.categories.length) {
            var catNames = game.categories.map(function(c) { return typeof c === 'object' ? (c.name || c.id) : c; });
            body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'Categories: ' + catNames.join(', ') }));
        }

        // Status controls
        var controls = App.el('div', { className: 'subsection' }, [
            App.el('div', { className: 'subsection-title', textContent: 'Status control' }),
            App.el('div', { className: 'flex gap-sm games-modal-actions' }, [
                App.el('button', {
                    className: 'btn btn-sm btn-success',
                    disabled: status === 'enabled',
                    textContent: 'Resume',
                    onClick: function() {
                        changeGameStatus({ game_id: game.id, game_name: game.name }, 'enabled', true);
                    }
                }),
                App.el('button', {
                    className: 'btn btn-sm btn-warning',
                    disabled: status === 'paused',
                    textContent: 'Pause',
                    onClick: function() {
                        changeGameStatus({ game_id: game.id, game_name: game.name }, 'paused', true);
                    }
                }),
                App.el('button', {
                    className: 'btn btn-sm btn-danger',
                    disabled: status === 'outOfService',
                    textContent: 'Out of service',
                    onClick: function() {
                        changeGameStatus({ game_id: game.id, game_name: game.name }, 'outOfService', true);
                    }
                })
            ])
        ]);
        body.appendChild(controls);

        // RPC actions (e.g. reboot)
        var actions = game.supportedActions || [];
        if (actions.length) {
            var actionsRow = App.el('div', { className: 'flex gap-sm', style: { flexWrap: 'wrap' } });
            actions.forEach(function(act) {
                actionsRow.appendChild(App.el('button', {
                    className: 'btn btn-sm btn-ghost',
                    textContent: act.name || act.id,
                    title: act.requireManager ? 'Manager only' : '',
                    onClick: function() { runRpcAction(game, act); }
                }));
            });
            body.appendChild(App.el('div', { className: 'subsection' }, [
                App.el('div', { className: 'subsection-title', textContent: 'Device actions' }),
                actionsRow
            ]));
        }

        // Recent plays for this game (filter the cached feed client-side)
        body.appendChild(App.el('div', { className: 'subsection' }, [
            App.el('div', { className: 'subsection-title', textContent: 'Recent plays' }),
            App.el('div', { id: 'game-detail-plays' }, [App.loading()])
        ]));
        loadGamePlays(game.id);
    }

    async function loadGamePlays(gameId) {
        var holder = document.getElementById('game-detail-plays');
        if (!holder) return;
        try {
            var data = await API.get('games/transactions/recent?limit=500');
            holder.innerHTML = '';
            var matched = (data.transactions || []).filter(function(t) {
                return String(t.game_id) === String(gameId);
            }).slice(0, 20);

            if (matched.length === 0) {
                holder.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: 'No recent plays cached for this game.' }));
                return;
            }
            var ul = App.el('ul', { className: 'plain-list' });
            matched.forEach(function(t) { ul.appendChild(buildFeedRow(t)); });
            holder.appendChild(ul);
        } catch (err) {
            holder.innerHTML = '';
            holder.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'Failed to load plays: ' + err.message }));
        }
    }

    async function runRpcAction(game, action) {
        var confirmed = await App.confirm('Run "' + (action.name || action.id) + '" on "' + (game.name || game.id) + '"?');
        if (!confirmed) return;
        try {
            // performAction returns the updated Game object per spec, so we
            // can refresh the modal directly from the response without an
            // extra GET round-trip to CenterEdge.
            var fresh = await API.post('games/' + encodeURIComponent(game.id) + '/action', { actionId: action.id });
            App.toast('Action "' + (action.name || action.id) + '" sent.', 'success');
            if (fresh && fresh.id) {
                renderGameDetailModal(fresh);
            }
        } catch (err) {
            App.toast('Action failed: ' + err.message, 'error');
        }
    }

    // ---------- Status changes (pause/unpause/oos) ----------

    /**
     * Change a single game's operation status. Applies optimistic UI then
     * reconciles with the server response. Reuses the bulk PATCH /api/games
     * endpoint with a single-item payload.
     */
    async function changeGameStatus(game, newStatus, fromModal) {
        // Capture pageGen at entry so any DOM updates after the await chain
        // bail out cleanly if the user has navigated to a different page.
        var myGen = pageGen;
        var verb = newStatus === 'enabled' ? 'Resume' :
                   newStatus === 'paused' ? 'Pause' : 'Mark out of service';
        var confirmMsg = verb + ' "' + (game.game_name || game.game_id) + '"?';
        var confirmed = await App.confirm(confirmMsg);
        if (!confirmed) return;
        if (myGen !== pageGen) return;

        // Optimistic update
        var prev = null;
        var idx = -1;
        for (var i = 0; i < allGames.length; i++) {
            if (allGames[i].game_id === game.game_id) {
                prev = allGames[i].operation_status;
                idx = i;
                allGames[i].operation_status = newStatus;
                break;
            }
        }
        renderStatusPills();
        renderGameTable();
        if (lastAnalytics) {
            // Update status counts optimistically
            applyOptimisticStatusToAnalytics(prev, newStatus);
            renderKpiGrid(lastAnalytics);
            renderStatusDistributionChart(lastAnalytics);
        }

        try {
            var payload = {};
            payload[game.game_id] = [{ op: 'replace', path: '/operationStatus', value: newStatus }];
            var result = await API.patch('games', { games: payload }) || {};
            if (myGen !== pageGen) return;
            var errors = result.errors || {};
            if (errors[game.game_id]) {
                throw new Error(typeof errors[game.game_id] === 'string'
                    ? errors[game.game_id]
                    : (errors[game.game_id].message || 'Upstream rejected the change'));
            }
            App.toast((game.game_name || game.game_id) + ' → ' + statusLabel(newStatus), 'success');

            // Refresh the game from cache so we get whatever the server actually stored
            await loadGames(myGen, /*silent*/ true);
            if (myGen !== pageGen) return;
            if (fromModal) {
                // The PATCH response already contains the updated Game
                // object — use it directly to avoid an extra live GET to
                // CenterEdge. Fall back to the cache row only if the
                // upstream response shape is unexpected.
                var updated = null;
                if (result.games && Array.isArray(result.games)) {
                    for (var gi = 0; gi < result.games.length; gi++) {
                        if (String(result.games[gi].id) === String(game.game_id)) {
                            updated = result.games[gi];
                            break;
                        }
                    }
                }
                if (updated) {
                    renderGameDetailModal(updated);
                } else {
                    var cached = gameFromCache(game.game_id);
                    if (cached) renderGameDetailModal(cached);
                }
            }
        } catch (err) {
            if (myGen !== pageGen) return;
            App.toast(verb + ' failed: ' + err.message, 'error');
            // Revert optimistic update
            if (idx !== -1 && prev !== null) {
                allGames[idx].operation_status = prev;
                renderStatusPills();
                renderGameTable();
                if (lastAnalytics) {
                    applyOptimisticStatusToAnalytics(newStatus, prev);
                    renderKpiGrid(lastAnalytics);
                    renderStatusDistributionChart(lastAnalytics);
                }
            }
        }
    }

    function applyOptimisticStatusToAnalytics(fromStatus, toStatus) {
        if (!lastAnalytics || !lastAnalytics.status_breakdown) return;
        var sb = lastAnalytics.status_breakdown;
        if (fromStatus && sb[fromStatus] !== undefined && sb[fromStatus] > 0) sb[fromStatus]--;
        if (toStatus) {
            if (sb[toStatus] === undefined) sb[toStatus] = 0;
            sb[toStatus]++;
        }
    }

    // ---------- Data loaders ----------

    async function loadAnalytics(captureGen) {
        var meta = document.getElementById('games-window-meta');
        if (meta) meta.textContent = 'Loading…';
        try {
            var data = await API.get('games/analytics?window=' + encodeURIComponent(currentWindow));
            if (captureGen !== pageGen) return; // user navigated away
            lastAnalytics = data;

            renderKpiGrid(data);
            renderPlaysOverTimeChart(data);
            renderStatusDistributionChart(data);
            renderLeaderboardChart('top-plays', data.top_by_plays, 'plays', 'plays');
            renderLeaderboardChart('top-tickets', data.top_by_tickets, 'sum_tickets', 'tickets');
            renderLeaderboardChart('top-points', data.top_by_points, 'sum_total_points', 'points');

            if (meta) {
                meta.textContent = describeWindowMeta(data);
            }
            // Cross-reference: attach per-game stats to the directory rows so
            // the Plays / Tickets columns reflect the current window.
            attachStatsToGames(data);
            renderGameTable();
        } catch (err) {
            if (meta) meta.textContent = 'Analytics unavailable: ' + err.message;
            var grid = document.getElementById('games-kpi-grid');
            if (grid) {
                grid.innerHTML = '';
                grid.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: 'Analytics unavailable: ' + err.message }));
            }
        }
    }

    function describeWindowMeta(data) {
        var totals = data.totals || {};
        var label =
            data.window === 'day' ? 'Today' :
            data.window === 'week' ? 'Last 7 days' :
            data.window === 'month' ? 'Last 30 days' :
            data.window === 'year' ? 'Last 12 months' : 'All cached plays';
        var pieces = [label];
        pieces.push(fmtInt(totals.plays || 0) + ' plays');
        pieces.push(fmtPoints(totals.tickets || 0) + ' tickets');
        pieces.push(fmtPoints(totals.total_points || 0) + ' pts');
        return pieces.join('  ·  ');
    }

    function attachStatsToGames(analytics) {
        // Build a map game_id → { plays, tickets } from the aggregated leaderboards
        // (top_by_plays already covers everything because the SQL aggregates over
        // every game with at least one play in the window).
        var statMap = {};
        var combined = (analytics.top_by_plays || []).concat(analytics.top_by_tickets || [])
            .concat(analytics.top_by_points || []);
        combined.forEach(function(r) {
            if (!r || !r.game_id) return;
            statMap[r.game_id] = {
                plays: r.plays || 0,
                tickets: r.sum_tickets || 0,
                points: r.sum_total_points || 0
            };
        });
        allGames.forEach(function(g) {
            g._stats = statMap[g.game_id] || { plays: 0, tickets: 0, points: 0 };
        });
    }

    async function loadFeed(captureGen) {
        try {
            var data = await API.get('games/transactions/recent?limit=' + FEED_LIMIT);
            if (captureGen !== pageGen) return;
            lastFeedData = data;
            renderFeed(data);
        } catch (err) {
            var body = document.getElementById('games-feed-body');
            if (body) {
                body.innerHTML = '';
                body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: 'Feed unavailable: ' + err.message }));
            }
        }
    }

    async function loadGames(captureGen, silent) {
        try {
            var data = await API.get('games');
            if (captureGen !== pageGen) return;
            allGames = data.games || [];
            // Merge any cached per-game stats from the analytics response.
            if (lastAnalytics) attachStatsToGames(lastAnalytics);
            renderStatusPills();
            renderGameTable();
        } catch (err) {
            if (silent) return;
            var wrap = document.getElementById('games-table-wrap');
            if (wrap) {
                wrap.innerHTML = '';
                wrap.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: 'Failed to load games: ' + err.message }));
            }
        }
    }

    async function manualRefresh() {
        try {
            // Force the watchdog poll on demand so the feed catches up before
            // we re-render. If it fails (CenterEdge unreachable), we still
            // refresh our local view so the operator sees what we have.
            try { await API.post('games/transactions/poll'); } catch (e) {}
            await Promise.all([loadAnalytics(pageGen), loadFeed(pageGen), loadGames(pageGen)]);
            App.toast('Refreshed.', 'success');
        } catch (err) {
            App.toast('Refresh failed: ' + err.message, 'error');
        }
    }

    async function syncGames() {
        try {
            await API.post('games/sync');
            App.toast('Games synced.', 'success');
        } catch (err) {
            App.toast('Sync failed: ' + err.message, 'error');
            return;
        }
        await Promise.all([loadGames(pageGen), loadAnalytics(pageGen)]);
    }

    // ---------- Tiny color helper ----------

    function hexToRgba(hex, alpha) {
        if (!hex) return 'rgba(91,141,239,' + alpha + ')';
        var h = hex.trim();
        if (h.charAt(0) === '#') h = h.slice(1);
        if (h.length === 3) {
            h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        }
        if (h.length !== 6) return 'rgba(91,141,239,' + alpha + ')';
        var r = parseInt(h.slice(0, 2), 16);
        var g = parseInt(h.slice(2, 4), 16);
        var b = parseInt(h.slice(4, 6), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }
})();
