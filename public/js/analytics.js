/**
 * Analytics page — KPI dashboard, leaderboards and charts powered by the
 * cached game-play feed and action_log. Charts via Chart.js (CDN).
 *
 * Pulls a single /api/analytics/overview payload per refresh, then renders:
 *   - 8 KPI cards with trend deltas vs the previous equivalent period
 *   - Fleet posture (games / kiosks / groups / overrides / retries)
 *   - Daily plays + tickets trend (line)
 *   - Plays by hour of day (bar)  &  Plays by day of week (bar)
 *   - Top games by plays / by tickets (horizontal bars)
 *   - Revenue mix and Pause-action source breakdown (donuts)
 *   - Top groups by automation activity (horizontal bar)
 *   - Recent automation failures (table)
 *
 * Refresh: visibility-aware, default every 60 s. Re-renders charts on theme
 * toggle so axis/grid colors flip cleanly between light/dark.
 */
(function() {
    App.registerRoute('#/analytics', { render: renderAnalytics });

    var REFRESH_MS = 60000;
    var DAYS_SHORT = App.DAYS_SHORT;
    var SOURCE_LABEL = {
        cron: 'Daily plan',
        manual: 'Manual',
        override: 'Override',
        schedule: 'Schedule',
        watchdog: 'Watchdog',
        expired_override: 'Expired override'
    };

    // Module state — reset on every render() call.
    var state;

    function freshState() {
        return {
            rangeKey: '7d',
            from: '',
            to: '',
            overview: null,
            charts: [],
            refreshCleanup: null,
            themeObserver: null,
            inflight: null
        };
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------
    async function renderAnalytics(container) {
        state = freshState();

        container.appendChild(buildHeader());
        container.appendChild(buildKpiSection());
        container.appendChild(buildFleetSection());
        container.appendChild(buildChartsSection());
        container.appendChild(buildBottomSection());

        // First paint: skeleton already in place; fetch then re-render.
        await loadAndRender();

        // Visibility-aware polling
        state.refreshCleanup = App.createVisibilityAwareInterval(
            function() { loadAndRender(false); },
            REFRESH_MS,
            { runImmediately: false, runOnVisible: true }
        );

        // Theme observer — re-paint charts when the theme attribute flips.
        state.themeObserver = new MutationObserver(function(records) {
            for (var i = 0; i < records.length; i++) {
                if (records[i].attributeName === 'data-theme') {
                    repaintCharts();
                    break;
                }
            }
        });
        state.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-theme']
        });

        return cleanup;
    }

    function cleanup() {
        if (!state) return;
        if (state.refreshCleanup) { try { state.refreshCleanup(); } catch (e) {} }
        if (state.themeObserver) { try { state.themeObserver.disconnect(); } catch (e) {} }
        if (state.inflight && typeof state.inflight.abort === 'function') {
            try { state.inflight.abort(); } catch (e) {}
        }
        destroyCharts();
        state = null;
    }

    // ------------------------------------------------------------------
    // Layout scaffolding
    // ------------------------------------------------------------------
    function buildHeader() {
        var rangeSelect = App.el('select', {
            className: 'form-select',
            id: 'analytics-range',
            'aria-label': 'Time range',
            style: { maxWidth: '170px' },
            onChange: function() {
                state.rangeKey = this.value;
                var customRow = document.getElementById('analytics-custom-row');
                if (customRow) customRow.style.display = state.rangeKey === 'custom' ? 'flex' : 'none';
                if (state.rangeKey !== 'custom') loadAndRender();
            }
        });
        [
            { v: 'today', l: 'Today' },
            { v: '7d', l: 'Last 7 days' },
            { v: '30d', l: 'Last 30 days' },
            { v: '90d', l: 'Last 90 days' },
            { v: 'all', l: 'All time' },
            { v: 'custom', l: 'Custom…' }
        ].forEach(function(opt) {
            var o = App.el('option', { value: opt.v, textContent: opt.l });
            if (opt.v === state.rangeKey) o.selected = true;
            rangeSelect.appendChild(o);
        });

        var fromInput = App.el('input', {
            className: 'form-input',
            type: 'date',
            id: 'analytics-from',
            'aria-label': 'Custom range start',
            style: { maxWidth: '160px' }
        });
        var toInput = App.el('input', {
            className: 'form-input',
            type: 'date',
            id: 'analytics-to',
            'aria-label': 'Custom range end',
            style: { maxWidth: '160px' }
        });
        var applyBtn = App.el('button', {
            className: 'btn btn-secondary btn-sm',
            textContent: 'Apply',
            onClick: function() {
                state.from = fromInput.value;
                state.to = toInput.value;
                if (!state.from || !state.to) {
                    App.toast('Choose a from and to date.', 'warning');
                    return;
                }
                loadAndRender();
            }
        });

        var customRow = App.el('div', {
            className: 'flex gap-sm',
            id: 'analytics-custom-row',
            style: { display: 'none', alignItems: 'center', flexWrap: 'wrap' }
        }, [fromInput, App.el('span', { textContent: 'to', className: 'text-muted' }), toInput, applyBtn]);

        var refreshBtn = App.el('button', {
            className: 'btn btn-secondary btn-sm',
            textContent: 'Refresh',
            title: 'Refresh now',
            onClick: function() { loadAndRender(); }
        });

        var lastUpdated = App.el('span', {
            id: 'analytics-last-updated',
            className: 'text-muted text-sm',
            style: { minWidth: '7.5rem', textAlign: 'right' }
        });

        var actions = App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', flexWrap: 'wrap' } }, [
            App.el('label', { className: 'text-sm text-muted', textContent: 'Range:' }),
            rangeSelect,
            customRow,
            refreshBtn,
            lastUpdated
        ]);

        return App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Analytics' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Game plays, ticket flow, fleet posture and automation activity.' })
            ]),
            actions
        ]);
    }

    function buildKpiSection() {
        // Cash revenue and avg cash/play are monetary — hidden from the
        // 'tech' role, which sees plays / tickets / points only.
        var canSeeMoney = App.canSeeMoney();
        var cards = [
            kpiCardSkeleton('plays', 'Plays'),
            kpiCardSkeleton('tickets', 'Tickets dispensed')
        ];
        if (canSeeMoney) cards.push(kpiCardSkeleton('cash', 'Cash revenue'));
        cards.push(kpiCardSkeleton('points', 'Points charged'));
        cards.push(kpiCardSkeleton('avg_tickets', 'Avg tickets / play'));
        if (canSeeMoney) cards.push(kpiCardSkeleton('avg_cash', 'Avg cash / play'));
        cards.push(kpiCardSkeleton('unique_cards', 'Unique cards'));
        cards.push(kpiCardSkeleton('credit_card_share', 'Credit-card plays'));
        // Breakage — value expired off cards by the card system itself.
        // Points/tickets, not dollars, so visible to every analytics role.
        cards.push(kpiCardSkeleton('expired', 'Expired value'));
        return App.el('div', { className: 'stats-grid', id: 'analytics-kpis' }, cards);
    }

    function kpiCardSkeleton(key, label) {
        return App.el('div', { className: 'stat-card analytics-kpi', 'data-kpi': key }, [
            App.el('div', { className: 'stat-label', textContent: label }),
            App.el('div', { className: 'stat-value', 'data-role': 'value', textContent: '—' }),
            App.el('div', { className: 'stat-trend', 'data-role': 'trend' })
        ]);
    }

    function buildFleetSection() {
        var section = App.el('div', { className: 'analytics-fleet-grid', id: 'analytics-fleet' }, [
            fleetTileSkeleton('games', 'Games', '#/games', 'Open the games directory'),
            fleetTileSkeleton('kiosks', 'Kiosks', '#/kiosks', 'Open the kiosks page'),
            fleetTileSkeleton('groups', 'Pause groups', '#/groups', 'Open pause groups'),
            fleetTileSkeleton('overrides', 'Active overrides', '#/overrides', 'Open the overrides page'),
            fleetTileSkeleton('retries', 'Pending retries', '#/logs', 'Open the action log to inspect retries')
        ]);
        return section;
    }

    function fleetTileSkeleton(key, label, href, title) {
        var tile = App.el('div', { className: 'analytics-fleet-tile', 'data-fleet': key }, [
            App.el('div', { className: 'analytics-fleet-label', textContent: label }),
            App.el('div', { className: 'analytics-fleet-value', 'data-role': 'value', textContent: '—' }),
            App.el('div', { className: 'analytics-fleet-detail', 'data-role': 'detail' })
        ]);
        if (href) {
            App.makeCardLink(tile, href, { title: title || ('Open ' + label.toLowerCase()) });
        }
        return tile;
    }

    function buildChartsSection() {
        var grid = App.el('div', { className: 'analytics-grid' });
        var canSeeMoney = App.canSeeMoney();

        grid.appendChild(chartCard('Daily activity', 'analytics-chart-daily', 'analytics-card-wide', 260));
        grid.appendChild(chartCard('Plays by hour of day', 'analytics-chart-hour', '', 220));
        grid.appendChild(chartCard('Plays by day of week', 'analytics-chart-dow', '', 220));
        grid.appendChild(chartCard('Top games — plays', 'analytics-chart-top-plays', '', 280));
        grid.appendChild(chartCard('Top games — tickets', 'analytics-chart-top-tickets', '', 280));
        grid.appendChild(chartCard('Category share — plays', 'analytics-chart-cat-share', '', 240,
            'Games in multiple categories count in each'));
        grid.appendChild(chartCard('Tickets by category', 'analytics-chart-cat-tickets', '', 240));
        grid.appendChild(chartCard('Payment mix — plays', 'analytics-chart-payment-mix', '', 240,
            'Mixed-payment plays count in each method'));
        // Brand data is payment info — server sends it only with view_revenue.
        if (canSeeMoney) {
            grid.appendChild(chartCard('Credit-card brands', 'analytics-chart-cc-brands', '', 240));
        }
        // Revenue mix surfaces cash totals — hidden from the tech role.
        if (canSeeMoney) {
            grid.appendChild(chartCard('Revenue mix', 'analytics-chart-revenue', '', 240));
        }
        grid.appendChild(chartCard('Pause actions by source', 'analytics-chart-actions-source', '', 240));
        grid.appendChild(chartCard('Pause action outcomes', 'analytics-chart-actions-outcome', '', 240));
        grid.appendChild(chartCard('Top groups by automation', 'analytics-chart-top-groups', 'analytics-card-wide', 240));

        return grid;
    }

    function chartCard(title, canvasId, extraClass, height, subtitle) {
        var canvas = App.el('canvas', { id: canvasId });
        // Chart.js sizes from the parent's height; lock min-height for layout.
        var box = App.el('div', { className: 'analytics-chart-box', style: { height: (height || 220) + 'px' } }, [canvas]);
        var header = [App.el('div', { className: 'card-title', textContent: title })];
        if (subtitle) {
            header.push(App.el('div', { className: 'text-muted text-sm', textContent: subtitle }));
        }
        return App.el('div', { className: 'card analytics-card ' + (extraClass || '') }, [
            App.el('div', { className: 'analytics-card-header' }, header),
            box
        ]);
    }

    function buildBottomSection() {
        var wrap = App.el('div', {});

        var box = App.el('div', { className: 'card analytics-card', style: { marginTop: '1rem' } });
        box.appendChild(App.el('div', { className: 'analytics-card-header' }, [
            App.el('div', { className: 'card-title', textContent: 'Recent automation failures' }),
            App.el('div', { className: 'text-muted text-sm', textContent: 'Last 10 — system-wide' })
        ]));
        var tableWrap = App.el('div', { id: 'analytics-failures', className: 'analytics-failures' });
        tableWrap.appendChild(App.loading());
        box.appendChild(tableWrap);
        wrap.appendChild(box);

        // Card-system events: merges + value expirations from the
        // /system/transactions feed (breakage detail behind the KPI).
        var sysBox = App.el('div', { className: 'card analytics-card', style: { marginTop: '1rem' } });
        sysBox.appendChild(App.el('div', { className: 'analytics-card-header' }, [
            App.el('div', { className: 'card-title', textContent: 'Card system events' }),
            App.el('div', { className: 'text-muted text-sm', textContent: 'Last 10 merges & expirations' })
        ]));
        var sysWrap = App.el('div', { id: 'analytics-system-events' });
        sysWrap.appendChild(App.loading());
        sysBox.appendChild(sysWrap);
        wrap.appendChild(sysBox);

        return wrap;
    }

    function renderSystemEvents(data) {
        var box = document.getElementById('analytics-system-events');
        if (!box) return;
        box.innerHTML = '';

        if (data.system_tx_supported === false) {
            box.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'This card system does not report system transactions.' }));
            return;
        }
        var rows = (data.charts && data.charts.system_events) || [];
        if (rows.length === 0) {
            box.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'No merges or expirations recorded yet.' }));
            return;
        }

        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [
                App.el('tr', {}, [
                    App.el('th', { textContent: 'Time' }),
                    App.el('th', { textContent: 'Event' }),
                    App.el('th', { textContent: 'Detail' })
                ])
            ])
        ]);
        var tbody = App.el('tbody', {});
        rows.forEach(function(r) {
            var detail;
            if (r.type === 'merge') {
                detail = 'Card ' + (r.source_card || '?') + ' → ' + (r.destination_card || '?');
            } else {
                var parts = [];
                if (r.expired_points) parts.push(formatInt(Math.round(r.expired_points)) + ' pts');
                if (r.expired_tickets) parts.push(formatInt(Math.round(r.expired_tickets)) + ' tix');
                detail = 'Card ' + (r.card_number || '?')
                    + (parts.length ? ' — ' + parts.join(', ') + ' expired' : '')
                    + (r.is_wiped ? ' · wiped' : '');
            }
            tbody.appendChild(App.el('tr', {}, [
                App.el('td', { textContent: App.formatDatetime(r.transaction_time) }),
                App.el('td', {}, [
                    App.el('span', {
                        className: r.type === 'merge' ? 'badge badge-info' : 'badge badge-paused',
                        textContent: r.type === 'merge' ? 'Merge' : 'Expiration'
                    })
                ]),
                App.el('td', { textContent: detail })
            ]));
        });
        table.appendChild(tbody);
        box.appendChild(table);
    }

    // ------------------------------------------------------------------
    // Data load + render
    // ------------------------------------------------------------------
    async function loadAndRender(showSpinner) {
        if (showSpinner === undefined) showSpinner = true;
        var gen = App.navGeneration();

        var qs = 'range=' + encodeURIComponent(state.rangeKey);
        if (state.rangeKey === 'custom' && state.from && state.to) {
            qs += '&from=' + encodeURIComponent(state.from) + '&to=' + encodeURIComponent(state.to);
        }

        var lastUpdatedEl = document.getElementById('analytics-last-updated');
        if (lastUpdatedEl && showSpinner) lastUpdatedEl.textContent = 'Loading…';

        try {
            var data = await API.get('analytics/overview?' + qs);
            if (App.navGeneration() !== gen) return; // user navigated away
            state.overview = data;
            renderKpis(data);
            renderFleet(data);
            renderCharts(data);
            renderFailures(data);
            renderSystemEvents(data);
            if (lastUpdatedEl) {
                var d = new Date();
                lastUpdatedEl.textContent = 'Updated ' + d.toLocaleTimeString();
            }
        } catch (err) {
            if (App.navGeneration() !== gen) return;
            console.error('Analytics load failed:', err);
            if (lastUpdatedEl) lastUpdatedEl.textContent = 'Load failed';
            App.toast('Failed to load analytics: ' + (err && err.message ? err.message : 'unknown error'), 'error');
        }
    }

    // ------------------------------------------------------------------
    // KPI cards
    // ------------------------------------------------------------------
    function renderKpis(data) {
        var k = data.kpis || {};
        var p = data.previous_kpis || {};
        var canSeeMoney = App.canSeeMoney();

        kpiUpdate('plays',           formatInt(k.plays),                       deltaText(k.plays, p.plays));
        kpiUpdate('tickets',         formatInt(Math.round(k.tickets || 0)),    deltaText(k.tickets, p.tickets));
        if (canSeeMoney) {
            kpiUpdate('cash',        formatCurrency(k.cash),                   deltaText(k.cash, p.cash));
        }
        kpiUpdate('points',          formatInt(Math.round(k.points || 0)),     deltaText(k.points, p.points));
        kpiUpdate('avg_tickets',     (k.avg_tickets_per_play || 0).toFixed(2), deltaText(k.avg_tickets_per_play, p.avg_tickets_per_play));
        if (canSeeMoney) {
            kpiUpdate('avg_cash',    formatCurrency(k.avg_cash_per_play),      deltaText(k.avg_cash_per_play, p.avg_cash_per_play));
        }
        kpiUpdate('unique_cards',    formatInt(k.unique_cards),                deltaText(k.unique_cards, p.unique_cards));

        var cc = k.credit_card_plays || 0;
        var totalPlays = (k.plays || 0);
        var ccShare = totalPlays > 0 ? Math.round((cc / totalPlays) * 100) : 0;
        kpiUpdate('credit_card_share', formatInt(cc),
            ccShare + '% of plays · ' + (k.card_plays || 0).toLocaleString() + ' on cards');

        // Breakage KPI from the system-transaction feed.
        if (data.system_tx_supported === false) {
            kpiUpdate('expired', '—', 'Not reported by this card system');
        } else {
            var expDetail = deltaText(k.expired_points || 0, p.expired_points || 0);
            if ((k.expired_tickets || 0) > 0 || (k.merges || 0) > 0) {
                expDetail = formatInt(Math.round(k.expired_tickets || 0)) + ' tix expired · '
                    + formatInt(k.merges || 0) + ' merges';
            }
            kpiUpdate('expired', formatInt(Math.round(k.expired_points || 0)) + ' pts', expDetail);
        }
    }

    function kpiUpdate(key, value, trend) {
        var card = document.querySelector('.analytics-kpi[data-kpi="' + key + '"]');
        if (!card) return;
        card.querySelector('[data-role="value"]').textContent = value;
        var t = card.querySelector('[data-role="trend"]');
        t.textContent = '';
        if (typeof trend === 'string') {
            t.textContent = trend;
        } else if (trend && trend.text) {
            t.textContent = trend.text;
            t.classList.remove('trend-up', 'trend-down', 'trend-flat');
            if (trend.dir) t.classList.add('trend-' + trend.dir);
        }
    }

    /**
     * Build a "↑ 12% vs previous" string. dir is up/down/flat for color coding.
     */
    function deltaText(current, previous) {
        current = Number(current) || 0;
        previous = Number(previous) || 0;
        if (previous === 0 && current === 0) return { text: 'No prior activity', dir: 'flat' };
        if (previous === 0) return { text: '↑ new activity', dir: 'up' };
        var pct = ((current - previous) / previous) * 100;
        var arrow = pct >= 0.5 ? '↑' : pct <= -0.5 ? '↓' : '→';
        var dir = pct >= 0.5 ? 'up' : pct <= -0.5 ? 'down' : 'flat';
        var sign = pct >= 0 ? '+' : '';
        return { text: arrow + ' ' + sign + pct.toFixed(1) + '% vs prior', dir: dir };
    }

    // ------------------------------------------------------------------
    // Fleet posture
    // ------------------------------------------------------------------
    function renderFleet(data) {
        var f = data.fleet || {};

        fleetUpdate('games',
            (f.enabled_games || 0) + ' / ' + (f.total_games || 0),
            (f.paused_games || 0) + ' paused · ' + (f.out_of_service_games || 0) + ' out of service');

        var kioskDetail = (f.paused_kiosks || 0) + ' paused';
        if ((f.out_of_service_kiosks || 0) > 0) kioskDetail += ' · ' + f.out_of_service_kiosks + ' OOS';
        if ((f.unknown_kiosks || 0) > 0) kioskDetail += ' · ' + f.unknown_kiosks + ' unknown';
        fleetUpdate('kiosks',
            (f.enabled_kiosks || 0) + ' / ' + (f.total_kiosks || 0),
            kioskDetail);

        fleetUpdate('groups',
            String(f.active_groups || 0),
            (f.groups_with_manual_override || 0) + ' on manual override');

        fleetUpdate('overrides',
            String(f.active_overrides || 0),
            f.active_overrides ? 'Currently in effect' : 'None active');

        fleetUpdate('retries',
            String(f.pending_retries || 0),
            f.pending_retries ? 'Will retry on next watchdog' : 'Queue is clear');
    }

    function fleetUpdate(key, value, detail) {
        var tile = document.querySelector('.analytics-fleet-tile[data-fleet="' + key + '"]');
        if (!tile) return;
        tile.querySelector('[data-role="value"]').textContent = value;
        tile.querySelector('[data-role="detail"]').textContent = detail || '';
    }

    // ------------------------------------------------------------------
    // Charts
    // ------------------------------------------------------------------
    function renderCharts(data) {
        if (typeof window.Chart === 'undefined') {
            // Chart.js still loading or blocked. Try once more shortly.
            setTimeout(function() { if (state && state.overview) renderCharts(state.overview); }, 800);
            return;
        }

        destroyCharts();
        var theme = readThemeColors();
        var charts = data.charts || {};

        // Daily trend (combo: bars=plays, line=tickets)
        var dailyLabels = (charts.daily || []).map(function(d) { return formatShortDate(d.date); });
        var dailyPlays = (charts.daily || []).map(function(d) { return d.plays; });
        var dailyTickets = (charts.daily || []).map(function(d) { return Math.round(d.tickets || 0); });
        registerChart('analytics-chart-daily', {
            type: 'bar',
            data: {
                labels: dailyLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Plays',
                        data: dailyPlays,
                        backgroundColor: theme.accentSubtle,
                        borderColor: theme.accent,
                        borderWidth: 1,
                        yAxisID: 'y',
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Tickets',
                        data: dailyTickets,
                        borderColor: theme.tickets,
                        backgroundColor: 'transparent',
                        tension: 0.3,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        yAxisID: 'y1',
                        order: 1
                    }
                ]
            },
            options: dualAxisOptions(theme, 'Plays', 'Tickets')
        });

        // Hour of day
        registerChart('analytics-chart-hour', {
            type: 'bar',
            data: {
                labels: Array.from({ length: 24 }, function(_, i) { return formatHour(i); }),
                datasets: [{
                    label: 'Plays',
                    data: charts.plays_by_hour || [],
                    backgroundColor: theme.accent,
                    borderRadius: 3
                }]
            },
            options: simpleBarOptions(theme)
        });

        // Day of week
        registerChart('analytics-chart-dow', {
            type: 'bar',
            data: {
                labels: DAYS_SHORT,
                datasets: [{
                    label: 'Plays',
                    data: charts.plays_by_dow || [],
                    backgroundColor: theme.accent,
                    borderRadius: 3
                }]
            },
            options: simpleBarOptions(theme)
        });

        // Top games — plays
        registerChart('analytics-chart-top-plays', horizontalBarConfig(
            (charts.top_games_plays || []).map(function(g) { return g.game_name; }),
            (charts.top_games_plays || []).map(function(g) { return g.plays; }),
            'Plays',
            theme.accent,
            theme
        ));

        // Top games — tickets
        registerChart('analytics-chart-top-tickets', horizontalBarConfig(
            (charts.top_games_tickets || []).map(function(g) { return g.game_name; }),
            (charts.top_games_tickets || []).map(function(g) { return Math.round(g.tickets || 0); }),
            'Tickets',
            theme.tickets,
            theme
        ));

        // Category breakdown — plays share donut + tickets bar. Categories
        // come straight from CenterEdge (e.g. Arcade / Rides / Batting Cages).
        var byCat = charts.by_category || [];
        var catPalette = palette(byCat.length, theme);
        registerChart('analytics-chart-cat-share', donutConfig(
            byCat.map(function(c) { return c.name; }),
            byCat.map(function(c) { return c.plays; }),
            catPalette,
            theme,
            'plays'
        ));
        registerChart('analytics-chart-cat-tickets', horizontalBarConfig(
            byCat.map(function(c) { return c.name; }),
            byCat.map(function(c) { return Math.round(c.tickets || 0); }),
            'Tickets',
            theme.tickets,
            theme
        ));

        // Payment mix — how plays were paid (counts; overlaps possible on
        // mixed-payment plays, noted in the card subtitle).
        var pm = charts.payment_mix || {};
        registerChart('analytics-chart-payment-mix', donutConfig(
            ['Points', 'Cash', 'Credit card', 'Time play', 'Privilege'],
            [pm.points_plays || 0, pm.cash_plays || 0, pm.credit_card_plays || 0,
             pm.time_plays || 0, pm.privilege_plays || 0],
            palette(5, theme),
            theme,
            'plays'
        ));

        // Credit-card brand mix — server scrubs this to [] without
        // view_revenue, and the canvas only exists for money-roles anyway.
        if (App.canSeeMoney()) {
            var bm = charts.cc_brand_mix || [];
            registerChart('analytics-chart-cc-brands', donutConfig(
                bm.map(function(b) { return b.brand; }),
                bm.map(function(b) { return b.plays; }),
                palette(Math.max(1, bm.length), theme),
                theme,
                'plays'
            ));
        }

        // Revenue mix donut (cash / points / bonus). Tech doesn't get the
        // canvas so the registerChart call no-ops on a missing node, but
        // skip the work entirely to keep intent explicit.
        if (App.canSeeMoney()) {
            var k = data.kpis || {};
            var revenueLabels = ['Cash', 'Points', 'Bonus points'];
            var revenueData   = [Math.round((k.cash || 0) * 100) / 100, Math.round(k.points || 0), Math.round(k.bonus_points || 0)];
            var revenueColors = [theme.success, theme.accent, theme.tickets];
            registerChart('analytics-chart-revenue', donutConfig(revenueLabels, revenueData, revenueColors, theme, '$/pts'));
        }

        // Pause actions by source
        var sourceMap = charts.actions_by_source || {};
        var sourceKeys = Object.keys(sourceMap).filter(function(k) { return sourceMap[k] > 0; });
        if (sourceKeys.length === 0) sourceKeys = Object.keys(sourceMap);
        var sourceLabels = sourceKeys.map(function(k) { return SOURCE_LABEL[k] || k; });
        var sourceVals   = sourceKeys.map(function(k) { return sourceMap[k] || 0; });
        var sourcePalette = palette(sourceKeys.length, theme);
        registerChart('analytics-chart-actions-source', donutConfig(sourceLabels, sourceVals, sourcePalette, theme));

        // Pause action outcomes (success vs fail)
        var outcome = charts.actions_success_fail || { success: 0, fail: 0 };
        registerChart('analytics-chart-actions-outcome', donutConfig(
            ['Successful', 'Failed'],
            [outcome.success || 0, outcome.fail || 0],
            [theme.success, theme.danger],
            theme
        ));

        // Top groups
        var tg = charts.top_groups_actions || [];
        registerChart('analytics-chart-top-groups', horizontalBarConfig(
            tg.map(function(g) { return g.name; }),
            tg.map(function(g) { return g.actions; }),
            'Pause / unpause actions',
            theme.accent,
            theme
        ));
    }

    function repaintCharts() {
        if (state && state.overview) renderCharts(state.overview);
    }

    function destroyCharts() {
        if (!state || !state.charts) return;
        state.charts.forEach(function(c) {
            try { c.destroy(); } catch (e) {}
        });
        state.charts = [];
    }

    function registerChart(canvasId, config) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        var chart = new window.Chart(canvas, config);
        state.charts.push(chart);
    }

    // ------------------------------------------------------------------
    // Chart.js option helpers
    // ------------------------------------------------------------------
    function baseOptions(theme) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 350 },
            plugins: {
                legend: {
                    display: false,
                    labels: { color: theme.text }
                },
                tooltip: {
                    backgroundColor: theme.bgCard,
                    titleColor: theme.text,
                    bodyColor: theme.textSecondary,
                    borderColor: theme.border,
                    borderWidth: 1,
                    padding: 8
                }
            }
        };
    }

    function simpleBarOptions(theme) {
        var o = baseOptions(theme);
        o.scales = {
            x: { ticks: { color: theme.textSecondary }, grid: { display: false } },
            y: { ticks: { color: theme.textSecondary, precision: 0 }, grid: { color: theme.gridLine } }
        };
        return o;
    }

    function dualAxisOptions(theme, leftLabel, rightLabel) {
        var o = baseOptions(theme);
        o.plugins.legend.display = true;
        o.plugins.legend.position = 'bottom';
        o.interaction = { mode: 'index', intersect: false };
        o.scales = {
            x: { ticks: { color: theme.textSecondary, autoSkip: true, maxRotation: 0 }, grid: { display: false } },
            y: {
                position: 'left',
                title: { display: true, text: leftLabel, color: theme.textSecondary },
                ticks: { color: theme.textSecondary, precision: 0 },
                grid: { color: theme.gridLine },
                beginAtZero: true
            },
            y1: {
                position: 'right',
                title: { display: true, text: rightLabel, color: theme.textSecondary },
                ticks: { color: theme.textSecondary, precision: 0 },
                grid: { drawOnChartArea: false },
                beginAtZero: true
            }
        };
        return o;
    }

    function horizontalBarConfig(labels, data, label, color, theme) {
        var o = baseOptions(theme);
        o.indexAxis = 'y';
        o.scales = {
            x: { ticks: { color: theme.textSecondary, precision: 0 }, grid: { color: theme.gridLine }, beginAtZero: true },
            y: { ticks: { color: theme.textSecondary, autoSkip: false }, grid: { display: false } }
        };
        return {
            type: 'bar',
            data: {
                labels: labels && labels.length ? labels : ['No data'],
                datasets: [{
                    label: label,
                    data: data && data.length ? data : [0],
                    backgroundColor: color,
                    borderRadius: 3,
                    barPercentage: 0.85,
                    categoryPercentage: 0.9
                }]
            },
            options: o
        };
    }

    function donutConfig(labels, data, colors, theme, unitHint) {
        var o = baseOptions(theme);
        o.cutout = '62%';
        o.plugins.legend.display = true;
        o.plugins.legend.position = 'bottom';
        o.plugins.legend.labels = {
            color: theme.text,
            usePointStyle: true,
            boxWidth: 8,
            padding: 12
        };
        o.plugins.tooltip.callbacks = {
            label: function(ctx) {
                var v = ctx.parsed;
                var ds = ctx.dataset.data || [];
                var sum = ds.reduce(function(a, b) { return a + (Number(b) || 0); }, 0);
                var pct = sum > 0 ? Math.round((v / sum) * 100) : 0;
                var formatted = unitHint === '$/pts' && ctx.label === 'Cash' ? formatCurrency(v) : formatInt(Math.round(v));
                return ctx.label + ': ' + formatted + ' (' + pct + '%)';
            }
        };
        var hasData = (data || []).some(function(v) { return Number(v) > 0; });
        return {
            type: 'doughnut',
            data: {
                labels: hasData ? labels : ['No data'],
                datasets: [{
                    data: hasData ? data : [1],
                    backgroundColor: hasData ? colors : [theme.gridLine],
                    borderColor: theme.bgCard,
                    borderWidth: 2
                }]
            },
            options: o
        };
    }

    function palette(n, theme) {
        var base = [theme.accent, theme.success, theme.tickets, theme.warning, theme.danger, '#a26df0', '#4ec9c8'];
        var out = [];
        for (var i = 0; i < n; i++) out.push(base[i % base.length]);
        return out;
    }

    // ------------------------------------------------------------------
    // Recent failures table
    // ------------------------------------------------------------------
    function renderFailures(data) {
        var box = document.getElementById('analytics-failures');
        if (!box) return;
        box.innerHTML = '';
        var rows = (data.charts && data.charts.top_failures) || [];
        if (rows.length === 0) {
            box.appendChild(App.emptyState('✓', 'No automation failures recorded — fleet is healthy.'));
            return;
        }
        var table = App.el('table', { className: 'data-table analytics-failures-table' });
        var thead = App.el('thead', {}, [
            App.el('tr', {}, [
                App.el('th', { textContent: 'When' }),
                App.el('th', { textContent: 'Source' }),
                App.el('th', { textContent: 'Action' }),
                App.el('th', { textContent: 'Group' }),
                App.el('th', { textContent: 'Game' }),
                App.el('th', { textContent: 'Error' })
            ])
        ]);
        table.appendChild(thead);
        var tbody = App.el('tbody');
        rows.forEach(function(r) {
            // Each failure row drills into the action log filtered by the
            // failure's source so the operator sees the full sequence around
            // the error rather than just one cherry-picked entry.
            var row = App.el('tr', { className: 'clickable-row' }, [
                App.el('td', { textContent: App.formatDatetime(r.timestamp) }),
                App.el('td', { textContent: SOURCE_LABEL[r.source] || r.source || '-' }),
                App.el('td', { textContent: r.action || '-' }),
                App.el('td', { textContent: r.group_name || '-' }),
                App.el('td', { textContent: r.game_name || r.game_id || '-' }),
                App.el('td', { className: 'text-danger', textContent: truncate(r.error_message || '-', 140) })
            ]);
            var qs = 'success=0';
            if (r.source) qs += '&source=' + encodeURIComponent(r.source);
            if (r.action) qs += '&action=' + encodeURIComponent(r.action);
            App.makeCardLink(row, '#/logs?' + qs,
                { title: 'Open the action log filtered to this failure' });
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        box.appendChild(table);
    }

    // ------------------------------------------------------------------
    // Theme + formatting helpers
    // ------------------------------------------------------------------
    function readThemeColors() {
        var styles = getComputedStyle(document.documentElement);
        var get = function(name, fallback) {
            var v = styles.getPropertyValue(name);
            return (v && v.trim()) || fallback;
        };
        var isLight = document.documentElement.getAttribute('data-theme') === 'light';
        return {
            accent:        get('--accent', '#5b8def'),
            accentSubtle:  isLight ? 'rgba(53, 103, 204, 0.18)' : 'rgba(91, 141, 239, 0.32)',
            success:       get('--success', '#3dd68c'),
            warning:       get('--warning', '#f0a944'),
            danger:        get('--danger', '#e5534b'),
            tickets:       get('--tickets', '#f5b942'),
            text:          get('--text-primary', '#e1e4ed'),
            textSecondary: get('--text-secondary', '#8891a8'),
            border:        get('--border', '#1e2638'),
            bgCard:        get('--bg-card', '#151a28'),
            gridLine:      isLight ? 'rgba(20, 35, 66, 0.08)' : 'rgba(255, 255, 255, 0.06)'
        };
    }

    function formatInt(n) {
        n = Number(n) || 0;
        return n.toLocaleString();
    }

    function formatCurrency(n) {
        n = Number(n) || 0;
        return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function formatHour(h) {
        var ampm = h >= 12 ? 'p' : 'a';
        var h12 = h % 12 || 12;
        return h12 + ampm;
    }

    function formatShortDate(iso) {
        if (!iso) return '';
        // iso looks like "2026-04-30"
        var parts = iso.split('-');
        if (parts.length !== 3) return iso;
        var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function truncate(str, max) {
        str = String(str || '');
        if (str.length <= max) return str;
        return str.slice(0, max - 1) + '…';
    }
})();
