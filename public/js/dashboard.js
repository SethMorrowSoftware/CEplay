/**
 * Dashboard — Command Center for pause group automation.
 *
 * Polling strategy:
 *   - Default: every 30 seconds
 *   - Scheduled transition imminent (< 2 min): every 5 seconds
 *   - Active override exists: every 10 seconds
 *   - Override about to expire (< 2 min): every 5 seconds
 *   - Override just expired: immediate enforce call + refresh
 *   - Scheduled transition fires: immediate enforce call + refresh
 *
 * Scalable UI: supports hundreds of games with table view,
 * pagination, filtering, and sorting.
 */
(function() {
    App.registerRoute('#/dashboard', { render: renderDashboard });

    // Polling constants
    var INTERVAL_DEFAULT = 30000;
    var INTERVAL_OVERRIDE_ACTIVE = 10000;
    var INTERVAL_TRANSITION_IMMINENT = 5000;
    var INTERVAL_OVERRIDE_EXPIRING = 5000;

    // Module-level state
    var allGames = [];
    var allGroups = [];
    var refreshInterval = null;
    var refreshIntervalCleanup = null;
    var expiryTimers = [];
    var transitionTimers = [];
    var currentInterval = INTERVAL_DEFAULT;

    // Stats polling (independent — runs on its own cadence)
    var statsRefreshCleanup = null;
    var lastStats = null;
    var lastTickerIds = [];

    // View state
    var gameView = 'table'; // 'grid' or 'table'
    var gameStatusFilter = 'all'; // 'all', 'enabled', 'paused', 'outOfService'
    var gameSearchTerm = '';
    var gameSortCol = 'game_name';
    var gameSortDir = 'asc';
    var gamePage = 1;
    var gamePageSize = 25;
    var groupsCollapsed = false;

    function scheduleNextPoll() {
        if (refreshIntervalCleanup) refreshIntervalCleanup();
        refreshIntervalCleanup = App.createVisibilityAwareInterval(loadDashboard, currentInterval, {
            runImmediately: false,
            runOnVisible: true
        });
        refreshInterval = true;
    }

    function adjustPollingRate(activeOverrides, groups) {
        var newInterval = INTERVAL_DEFAULT;
        var now = Date.now();

        // Check override expiry proximity
        if (activeOverrides && activeOverrides.length > 0) {
            var soonestMs = Infinity;
            activeOverrides.forEach(function(o) {
                var endD = App.toUtcDate(o.end_datetime);
                var endMs = endD ? endD.getTime() : NaN;
                var remaining = endMs - now;
                if (remaining < soonestMs) soonestMs = remaining;
            });

            if (soonestMs <= 120000) {
                newInterval = INTERVAL_OVERRIDE_EXPIRING;
            } else {
                newInterval = INTERVAL_OVERRIDE_ACTIVE;
            }
        }

        // Check scheduled transition proximity
        if (groups && groups.length > 0) {
            groups.forEach(function(g) {
                if (!g.next_transition) return;
                var transMs = todayTimeToMs(g.next_transition.time);
                if (transMs === null) return;
                var remaining = transMs - now;
                if (remaining > 0 && remaining <= 120000) {
                    newInterval = Math.min(newInterval, INTERVAL_TRANSITION_IMMINENT);
                }
            });
        }

        if (newInterval !== currentInterval) {
            currentInterval = newInterval;
            scheduleNextPoll();
        }
    }

    /**
     * Convert a HH:MM time string (in app timezone) to a Date.now()-comparable
     * millisecond timestamp for today.
     */
    function todayTimeToMs(timeStr) {
        if (!timeStr || timeStr.indexOf(':') === -1) return null;
        var parts = timeStr.split(':');
        // Build today's date string in the app timezone, then set the time
        var now = new Date();
        var todayStr = new Intl.DateTimeFormat('en-CA', {
            timeZone: App.appTimezone, year: 'numeric', month: '2-digit', day: '2-digit'
        }).format(now); // yields YYYY-MM-DD
        var isoStr = todayStr + 'T' + String(parts[0]).padStart(2, '0') + ':' + String(parts[1]).padStart(2, '0') + ':00';
        // Parse as a time in the app timezone by computing the offset
        var formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: App.appTimezone, hour12: false,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
        // Use a known reference to compute offset between UTC and app timezone
        var ref = new Date(todayStr + 'T12:00:00Z');
        var tzParts = formatter.formatToParts(ref);
        var tzObj = {};
        tzParts.forEach(function(p) { tzObj[p.type] = p.value; });
        var tzHour = parseInt(tzObj.hour, 10);
        var tzMinute = parseInt(tzObj.minute, 10);
        var offsetMs = ((tzHour - 12) * 60 + tzMinute) * 60 * 1000;
        // Target time in UTC = target local time - offset
        var targetLocal = new Date(isoStr + 'Z').getTime();
        return targetLocal - offsetMs;
    }

    function scheduleExpiryTimers(activeOverrides) {
        expiryTimers.forEach(function(t) { clearTimeout(t); });
        expiryTimers = [];

        if (!activeOverrides || activeOverrides.length === 0) return;

        var now = Date.now();
        activeOverrides.forEach(function(o) {
            var endD = App.toUtcDate(o.end_datetime);
            var endMs = endD ? endD.getTime() : NaN;
            var delay = endMs - now;

            if (delay > 0 && delay < 3600000) {
                // Fire right at expiry: call enforce endpoint then refresh
                var timer = setTimeout(function() {
                    onOverrideExpired(o);
                }, delay + 1000); // +1s to ensure server sees it as expired
                expiryTimers.push(timer);

                // Follow-up refresh 3s after expiry for UI consistency
                var timer2 = setTimeout(function() {
                    loadDashboard();
                }, delay + 3000);
                expiryTimers.push(timer2);
            }
        });
    }

    /**
     * Schedule precise timers for upcoming scheduled transitions so the UI
     * refreshes immediately when a scheduled pause/unpause fires, rather
     * than waiting up to 30s for the next poll.
     */
    function scheduleTransitionTimers(groups) {
        transitionTimers.forEach(function(t) { clearTimeout(t); });
        transitionTimers = [];

        if (!groups || groups.length === 0) return;

        var now = Date.now();
        groups.forEach(function(g) {
            if (!g.next_transition || !g.is_active) return;
            var transMs = todayTimeToMs(g.next_transition.time);
            if (transMs === null) return;
            var delay = transMs - now;

            // Only schedule timers for transitions within the next hour
            if (delay > 0 && delay < 3600000) {
                // Fire 1s after scheduled time to let the at-job/server execute first
                var timer = setTimeout(function() {
                    onScheduledTransition(g);
                }, delay + 1000);
                transitionTimers.push(timer);

                // Follow-up refresh 4s after transition for full consistency
                var timer2 = setTimeout(function() {
                    loadDashboard();
                }, delay + 4000);
                transitionTimers.push(timer2);
            }
        });
    }

    async function onScheduledTransition(group) {
        var groupId = group.id;
        if (groupId) {
            try {
                // Enforce correct state — this acts as a safety net in case
                // the at-job hasn't fired yet, and refreshes the cache.
                await API.post('groups/' + encodeURIComponent(groupId) + '/enforce');
            } catch (err) {
                // Enforcement failed — the poll will catch up
            }
        }
        await loadDashboard();
    }

    async function onOverrideExpired(override) {
        var groupId = override.pause_group_id;
        if (groupId) {
            try {
                await API.post('groups/' + encodeURIComponent(groupId) + '/enforce');
            } catch (err) {
                // Enforcement failed — next poll will still trigger server-side safety net
            }
        }
        await loadDashboard();
    }

    function renderDashboard(container) {
        currentInterval = INTERVAL_DEFAULT;
        lastTickerIds = [];

        // Page header with sync button (icon)
        var syncBtn = App.el('button', {
            className: 'btn btn-secondary', id: 'sync-btn',
            onClick: syncGames
        });
        syncBtn.appendChild(App.iconEl('sync', 14));
        syncBtn.appendChild(App.el('span', { textContent: 'Sync Now' }));

        // Hero header: glassy gradient banner with the live status
        var hero = App.el('div', { className: 'hero-banner' }, [
            App.el('div', { className: 'hero-banner-inner' }, [
                App.el('div', { className: 'hero-banner-text' }, [
                    App.el('div', { className: 'hero-eyebrow' }, [
                        App.el('span', { className: 'hero-live-dot' }),
                        App.el('span', { textContent: 'LIVE · CASTLE FUN CENTER' })
                    ]),
                    App.el('h1', { className: 'page-title hero-title', textContent: 'Command Center' }),
                    App.el('p', { className: 'page-subtitle', id: 'last-sync', textContent: 'Loading…' })
                ]),
                App.el('div', { className: 'hero-banner-actions' }, [syncBtn])
            ])
        ]);
        container.appendChild(hero);

        // Security warnings banner (populated by loadDashboard)
        container.appendChild(App.el('div', { id: 'security-warnings' }));

        // Hero stat tiles — plays / tickets / revenue / unique players
        var heroStats = App.el('div', { className: 'hero-stats', id: 'hero-stats' });
        container.appendChild(heroStats);

        // Two-column analytics row: today's hourly chart + top games leaderboard
        container.appendChild(App.el('div', { className: 'analytics-grid', id: 'analytics-grid' }, [
            App.el('div', { className: 'card analytics-card', id: 'hourly-card' }, [
                App.el('div', { className: 'card-header' }, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        (function() {
                            var iconWrap = App.el('span', { className: 'card-icon card-icon-accent' });
                            iconWrap.appendChild(App.iconEl('chart', 16));
                            return iconWrap;
                        })(),
                        App.el('div', { className: 'card-title', textContent: 'Today by Hour' })
                    ]),
                    App.el('span', { className: 'card-subnote', id: 'hourly-subnote', textContent: 'Plays per hour' })
                ]),
                App.el('div', { id: 'hourly-chart', className: 'hourly-chart' })
            ]),
            App.el('div', { className: 'card analytics-card', id: 'top-games-card' }, [
                App.el('div', { className: 'card-header' }, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        (function() {
                            var iconWrap = App.el('span', { className: 'card-icon card-icon-warning' });
                            iconWrap.appendChild(App.iconEl('trophy', 16));
                            return iconWrap;
                        })(),
                        App.el('div', { className: 'card-title', textContent: 'Top Games Today' })
                    ]),
                    App.el('span', { className: 'card-subnote', textContent: 'By plays' })
                ]),
                App.el('div', { id: 'top-games-list', className: 'leaderboard' })
            ])
        ]));

        // Live ticker — recent plays scrolling feed
        container.appendChild(App.el('div', { className: 'card live-ticker-card mt-2' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'flex-center gap-sm' }, [
                    (function() {
                        var iconWrap = App.el('span', { className: 'card-icon card-icon-success' });
                        iconWrap.appendChild(App.iconEl('flame', 16));
                        return iconWrap;
                    })(),
                    App.el('div', { className: 'card-title', textContent: 'Live Activity' })
                ]),
                App.el('span', { className: 'card-subnote', id: 'ticker-subnote', textContent: 'Recent plays' })
            ]),
            App.el('div', { id: 'live-ticker', className: 'live-ticker' })
        ]));

        // Existing operational stats — kept for at-a-glance device counts
        var statsGrid = App.el('div', { className: 'stats-grid mt-2', id: 'stats-grid' });
        container.appendChild(statsGrid);

        // Group controls (collapsible)
        container.appendChild(App.el('div', { className: 'card mt-2', id: 'group-controls-card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'flex-center gap-sm' }, [
                    App.el('div', { className: 'card-title', textContent: 'Group Controls' }),
                    App.el('span', { className: 'badge badge-info', id: 'group-count-badge', textContent: '0 groups' })
                ]),
                App.el('div', { className: 'flex-center gap-sm' }, [
                    App.el('div', { className: 'flex gap-sm', id: 'master-controls' }),
                    App.el('button', {
                        className: 'section-collapse-btn',
                        id: 'groups-collapse-btn',
                        textContent: groupsCollapsed ? 'Expand' : 'Collapse',
                        onClick: function() {
                            groupsCollapsed = !groupsCollapsed;
                            this.textContent = groupsCollapsed ? 'Expand' : 'Collapse';
                            var grid = document.getElementById('group-controls');
                            if (grid) grid.style.display = groupsCollapsed ? 'none' : '';
                            var summary = document.getElementById('groups-summary');
                            if (summary) summary.style.display = groupsCollapsed ? '' : 'none';
                        }
                    })
                ])
            ]),
            App.el('div', { id: 'groups-summary', className: 'groups-summary-bar', style: { display: groupsCollapsed ? '' : 'none' } }),
            App.el('div', { id: 'group-controls', className: 'group-controls-grid', style: { display: groupsCollapsed ? 'none' : '' } })
        ]));

        // Game status section with toolbar
        var gameCard = App.el('div', { className: 'card mt-2', id: 'game-status-card' });

        // Card header with title + view toggle
        gameCard.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('div', { className: 'flex-center gap-sm' }, [
                App.el('div', { className: 'card-title', textContent: 'Game Status' }),
                App.el('span', { className: 'badge badge-info', id: 'game-count-badge', textContent: '0 games' })
            ]),
            App.el('div', { className: 'view-toggle', id: 'view-toggle' }, [
                App.el('button', {
                    className: 'view-toggle-btn' + (gameView === 'table' ? ' active' : ''),
                    textContent: 'Table',
                    'data-view': 'table',
                    onClick: function() { switchView('table'); }
                }),
                App.el('button', {
                    className: 'view-toggle-btn' + (gameView === 'grid' ? ' active' : ''),
                    textContent: 'Grid',
                    'data-view': 'grid',
                    onClick: function() { switchView('grid'); }
                })
            ])
        ]));

        // Toolbar: search + status filter pills
        var toolbar = App.el('div', { className: 'toolbar-row', id: 'game-toolbar' });
        toolbar.appendChild(App.el('input', {
            className: 'form-input', type: 'text', placeholder: 'Search games...',
            id: 'game-search',
            style: { maxWidth: '240px', fontSize: '0.82rem', padding: '0.4rem 0.65rem' },
            onInput: function() {
                gameSearchTerm = this.value.toLowerCase();
                gamePage = 1;
                renderGameView(allGames);
            }
        }));
        toolbar.appendChild(App.el('div', { className: 'filter-pills', id: 'status-filters' }));
        gameCard.appendChild(toolbar);

        // Game content area
        gameCard.appendChild(App.el('div', { id: 'game-content' }));

        container.appendChild(gameCard);

        // Active overrides section
        container.appendChild(App.el('div', { className: 'card mt-2', id: 'active-overrides-card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'card-title', textContent: 'Active Overrides' })
            ]),
            App.el('div', { id: 'active-overrides' })
        ]));

        loadDashboard();
        scheduleNextPoll();

        // Independent stats poller — refreshes the hero/analytics every 20s
        loadStats();
        statsRefreshCleanup = App.createVisibilityAwareInterval(loadStats, 20000, {
            runImmediately: false,
            runOnVisible: true
        });

        return function cleanup() {
            if (refreshIntervalCleanup) refreshIntervalCleanup();
            refreshIntervalCleanup = null;
            refreshInterval = null;
            if (statsRefreshCleanup) statsRefreshCleanup();
            statsRefreshCleanup = null;
            expiryTimers.forEach(function(t) { clearTimeout(t); });
            expiryTimers = [];
            transitionTimers.forEach(function(t) { clearTimeout(t); });
            transitionTimers = [];
        };
    }

    // -----------------------------------------------------------------
    // Stats / Analytics — hero tiles, hourly chart, top games, ticker
    // -----------------------------------------------------------------

    async function loadStats() {
        var gen = App.navGeneration();
        try {
            var data = await API.get('stats/dashboard');
            if (App.navGeneration() !== gen) return;
            lastStats = data || {};
            renderHeroStats(lastStats);
            renderHourlyChart(lastStats);
            renderTopGames(lastStats);
            renderLiveTicker(lastStats);
        } catch (err) {
            // Soft-fail: leave the panels in their last-known state
            // and surface only on first failure to avoid toast spam.
            if (!lastStats) {
                renderHeroStats({});
                renderHourlyChart({});
                renderTopGames({});
                renderLiveTicker({});
            }
        }
    }

    function renderHeroStats(stats) {
        var el = document.getElementById('hero-stats');
        if (!el) return;
        var today = stats.today || { plays: 0, tickets: 0, revenue: 0, unique_cards: 0 };
        var yest  = stats.yesterday_same_time || { plays: 0, tickets: 0, revenue: 0, unique_cards: 0 };

        var tiles = [
            {
                key: 'plays',
                label: 'Plays Today',
                value: today.plays,
                prev: yest.plays,
                icon: 'gamepad',
                accent: 'accent',
                format: function(v) { return App.formatNumber(Math.round(v)); }
            },
            {
                key: 'tickets',
                label: 'Tickets Won',
                value: today.tickets,
                prev: yest.tickets,
                icon: 'ticket',
                accent: 'warning',
                format: function(v) { return App.formatNumber(Math.round(v)); }
            },
            {
                key: 'revenue',
                label: 'Revenue Today',
                value: today.revenue,
                prev: yest.revenue,
                icon: 'coin',
                accent: 'success',
                format: function(v) { return App.formatCurrency(v); }
            },
            {
                key: 'players',
                label: 'Unique Players',
                value: today.unique_cards,
                prev: yest.unique_cards,
                icon: 'users',
                accent: 'purple',
                format: function(v) { return App.formatNumber(Math.round(v)); }
            }
        ];

        // Build sparkline values from the daily series (latest 7 days)
        var series = stats.daily_series || [];
        var seriesByKey = {
            plays:   series.map(function(d) { return d.plays; }),
            tickets: series.map(function(d) { return d.tickets; }),
            revenue: series.map(function(d) { return d.revenue; }),
            players: series.map(function(d) { return d.plays; }) // best proxy
        };

        // Preserve existing tiles for animation if already rendered
        var existing = el.children.length === tiles.length;
        if (!existing) {
            el.innerHTML = '';
            tiles.forEach(function(t) {
                var tile = App.el('div', { className: 'hero-stat hero-stat-' + t.accent, 'data-key': t.key });
                var iconBadge = App.el('div', { className: 'hero-stat-icon' });
                iconBadge.appendChild(App.iconEl(t.icon, 18));
                tile.appendChild(iconBadge);
                tile.appendChild(App.el('div', { className: 'hero-stat-label', textContent: t.label }));
                tile.appendChild(App.el('div', { className: 'hero-stat-value', 'data-counter-current': '0', textContent: t.format(0) }));
                tile.appendChild(App.el('div', { className: 'hero-stat-meta' }, [
                    App.el('span', { className: 'hero-stat-delta', textContent: '—' }),
                    App.el('span', { className: 'hero-stat-vs', textContent: ' vs yesterday' })
                ]));
                tile.appendChild(buildSparkline(seriesByKey[t.key] || [], t.accent));
                el.appendChild(tile);
            });
        }

        // Update values + animate counters + delta + sparklines
        tiles.forEach(function(t, i) {
            var tile = el.children[i];
            if (!tile) return;
            var valueEl = tile.querySelector('.hero-stat-value');
            var deltaEl = tile.querySelector('.hero-stat-delta');
            var sparkHost = tile.querySelector('.hero-sparkline');

            if (valueEl) {
                App.animateCounter(valueEl, Number(t.value || 0), { format: t.format });
            }

            if (deltaEl) {
                var delta = renderDelta(t.value, t.prev);
                deltaEl.innerHTML = '';
                deltaEl.className = 'hero-stat-delta ' + delta.cls;
                deltaEl.textContent = delta.text;
            }

            if (sparkHost && sparkHost.parentNode) {
                var newSpark = buildSparkline(seriesByKey[t.key] || [], t.accent);
                sparkHost.parentNode.replaceChild(newSpark, sparkHost);
            }
        });
    }

    function renderDelta(curr, prev) {
        curr = Number(curr || 0);
        prev = Number(prev || 0);
        if (prev === 0 && curr === 0) return { text: 'No activity yet', cls: 'is-flat' };
        if (prev === 0) return { text: '▲ new', cls: 'is-up' };
        var diff = curr - prev;
        var pct = Math.abs((diff / prev) * 100);
        var pctStr = (pct >= 100 ? Math.round(pct) : pct.toFixed(1)) + '%';
        if (diff > 0) return { text: '▲ ' + pctStr, cls: 'is-up' };
        if (diff < 0) return { text: '▼ ' + pctStr, cls: 'is-down' };
        return { text: '◆ same', cls: 'is-flat' };
    }

    /**
     * Build an inline SVG sparkline. Returns a span wrapper so we can swap it
     * cleanly on each re-render without disrupting the surrounding tile.
     */
    function buildSparkline(values, accent) {
        var wrap = App.el('span', { className: 'hero-sparkline hero-sparkline-' + (accent || 'accent') });
        if (!values || values.length === 0) {
            wrap.innerHTML = '';
            return wrap;
        }

        var w = 110, h = 30, pad = 2;
        var max = Math.max.apply(null, values);
        var min = Math.min.apply(null, values);
        var range = max - min || 1;
        var step = (w - pad * 2) / Math.max(1, values.length - 1);

        var pts = values.map(function(v, i) {
            var x = pad + i * step;
            var y = h - pad - ((v - min) / range) * (h - pad * 2);
            return [x, y];
        });

        var line = pts.map(function(p, i) { return (i === 0 ? 'M' : 'L') + p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
        var areaPath = line + ' L ' + pts[pts.length - 1][0].toFixed(1) + ',' + (h - pad) + ' L ' + pts[0][0].toFixed(1) + ',' + (h - pad) + ' Z';

        var lastX = pts[pts.length - 1][0].toFixed(1);
        var lastY = pts[pts.length - 1][1].toFixed(1);

        wrap.innerHTML =
            '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-hidden="true">' +
                '<defs>' +
                    '<linearGradient id="spark-grad-' + accent + '" x1="0" x2="0" y1="0" y2="1">' +
                        '<stop offset="0%" stop-color="currentColor" stop-opacity="0.45"/>' +
                        '<stop offset="100%" stop-color="currentColor" stop-opacity="0"/>' +
                    '</linearGradient>' +
                '</defs>' +
                '<path d="' + areaPath + '" fill="url(#spark-grad-' + accent + ')"/>' +
                '<path d="' + line + '" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>' +
                '<circle cx="' + lastX + '" cy="' + lastY + '" r="2.2" fill="currentColor"/>' +
            '</svg>';
        return wrap;
    }

    function renderHourlyChart(stats) {
        var el = document.getElementById('hourly-chart');
        if (!el) return;
        var hourly = stats.hourly || [];

        if (!hourly.length || hourly.every(function(h) { return (h.plays || 0) === 0; })) {
            el.innerHTML = '';
            el.appendChild(App.el('div', { className: 'analytics-empty', textContent: 'No plays recorded yet today.' }));
            return;
        }

        // Determine current hour to highlight + max for scaling
        var nowHour = (function() {
            try {
                var fmt = new Intl.DateTimeFormat('en-US', {
                    timeZone: App.appTimezone, hour: 'numeric', hour12: false
                });
                var parts = fmt.formatToParts(new Date());
                var h = parts.filter(function(p) { return p.type === 'hour'; })[0];
                return h ? parseInt(h.value, 10) : (new Date()).getHours();
            } catch (e) { return (new Date()).getHours(); }
        })();

        var max = Math.max(1, hourly.reduce(function(m, h) { return Math.max(m, h.plays || 0); }, 0));

        el.innerHTML = '';
        var bars = App.el('div', { className: 'hourly-bars' });
        hourly.forEach(function(h, i) {
            var pct = ((h.plays || 0) / max) * 100;
            var displayHour = ((i % 12) || 12);
            var ampm = i < 12 ? 'AM' : 'PM';
            var isNow = i === nowHour;
            var isFuture = i > nowHour;

            var barWrap = App.el('div', {
                className: 'hourly-bar' + (isNow ? ' is-now' : '') + (isFuture ? ' is-future' : ''),
                title: displayHour + ' ' + ampm + ' — ' + (h.plays || 0) + ' plays · ' + Math.round(h.tickets || 0) + ' tickets'
            });
            barWrap.appendChild(App.el('div', { className: 'hourly-bar-fill', style: { height: Math.max(2, pct) + '%' } }));
            var labelText = displayHour + (i === 0 || i === 12 ? ampm : '');
            barWrap.appendChild(App.el('div', { className: 'hourly-bar-label', textContent: labelText }));
            bars.appendChild(barWrap);
        });
        el.appendChild(bars);

        var totals = stats.today || {};
        var note = document.getElementById('hourly-subnote');
        if (note) {
            note.textContent = (totals.plays || 0).toLocaleString() + ' plays · ' +
                Math.round(totals.tickets || 0).toLocaleString() + ' tickets';
        }
    }

    function renderTopGames(stats) {
        var el = document.getElementById('top-games-list');
        if (!el) return;
        var games = stats.top_games || [];

        if (games.length === 0) {
            el.innerHTML = '';
            el.appendChild(App.el('div', { className: 'analytics-empty', textContent: 'Once games start logging plays, your top performers will appear here.' }));
            return;
        }

        var max = Math.max(1, games.reduce(function(m, g) { return Math.max(m, Number(g.plays || 0)); }, 0));

        el.innerHTML = '';
        games.forEach(function(g, idx) {
            var pct = (Number(g.plays || 0) / max) * 100;
            var rankCls = idx === 0 ? 'rank-gold' : idx === 1 ? 'rank-silver' : idx === 2 ? 'rank-bronze' : '';

            var row = App.el('div', { className: 'leaderboard-row' });
            row.appendChild(App.el('div', { className: 'leaderboard-rank ' + rankCls, textContent: '#' + (idx + 1) }));
            row.appendChild(App.el('div', { className: 'leaderboard-info' }, [
                App.el('div', { className: 'leaderboard-name', textContent: g.game_description || g.game_id || 'Unknown' }),
                App.el('div', { className: 'leaderboard-meta' }, [
                    App.el('span', { textContent: App.formatNumber(g.plays) + ' plays' }),
                    App.el('span', { className: 'text-muted', textContent: ' · ' }),
                    App.el('span', { textContent: App.formatNumber(Math.round(g.tickets || 0)) + ' tickets' }),
                    Number(g.revenue || 0) > 0 ? App.el('span', { className: 'text-muted', textContent: ' · ' }) : null,
                    Number(g.revenue || 0) > 0 ? App.el('span', { textContent: App.formatCurrency(g.revenue) }) : null
                ].filter(Boolean))
            ]));
            row.appendChild(App.el('div', { className: 'leaderboard-bar' }, [
                App.el('div', { className: 'leaderboard-bar-fill', style: { width: Math.max(4, pct) + '%' } })
            ]));
            el.appendChild(row);
        });
    }

    function renderLiveTicker(stats) {
        var el = document.getElementById('live-ticker');
        if (!el) return;
        var items = stats.ticker || [];

        if (items.length === 0) {
            el.innerHTML = '';
            el.appendChild(App.el('div', { className: 'analytics-empty', textContent: 'Waiting for the first play of the day…' }));
            var subnote = document.getElementById('ticker-subnote');
            if (subnote) subnote.textContent = 'Recent plays';
            return;
        }

        // Detect new entries since last render so we can flash them
        var newIds = {};
        var prevSet = {};
        lastTickerIds.forEach(function(id) { prevSet[id] = true; });
        items.forEach(function(t) {
            if (!prevSet[t.transaction_id]) newIds[t.transaction_id] = true;
        });
        lastTickerIds = items.map(function(t) { return t.transaction_id; });

        el.innerHTML = '';
        items.forEach(function(t) {
            var ticketsNum = Number(t.redemption_tickets || 0);
            var revenueNum = Number(t.revenue || 0);
            var isHot = ticketsNum >= 50;
            var card = (t.card_number && t.card_number !== '000000') ? t.card_number : 'Cash';

            var item = App.el('div', {
                className: 'ticker-item' + (newIds[t.transaction_id] ? ' is-new' : '') + (isHot ? ' is-hot' : '')
            });
            var iconWrap = App.el('div', { className: 'ticker-icon' });
            iconWrap.appendChild(App.iconEl(isHot ? 'flame' : 'spark', 14));
            item.appendChild(iconWrap);

            item.appendChild(App.el('div', { className: 'ticker-body' }, [
                App.el('div', { className: 'ticker-game', textContent: t.game_description || t.game_id || 'Game' }),
                App.el('div', { className: 'ticker-meta' }, [
                    App.el('span', { className: 'ticker-card', textContent: card }),
                    App.el('span', { className: 'ticker-time', textContent: ' · ' + App.formatRelative(t.transaction_time) })
                ])
            ]));

            var rightCol = App.el('div', { className: 'ticker-right' });
            if (ticketsNum > 0) {
                rightCol.appendChild(App.el('span', { className: 'ticker-pill ticker-pill-tickets' }, [
                    App.iconEl('ticket', 11),
                    App.el('span', { textContent: ' ' + App.formatNumber(Math.round(ticketsNum)) })
                ]));
            }
            if (revenueNum > 0) {
                rightCol.appendChild(App.el('span', { className: 'ticker-pill ticker-pill-revenue' }, [
                    App.iconEl('coin', 11),
                    App.el('span', { textContent: ' ' + App.formatCurrency(revenueNum) })
                ]));
            }
            if (t.used_time_play) {
                rightCol.appendChild(App.el('span', { className: 'ticker-pill ticker-pill-timeplay' }, [
                    App.iconEl('timer', 11),
                    App.el('span', { textContent: ' Time' })
                ]));
            } else if (t.used_play_privilege) {
                rightCol.appendChild(App.el('span', { className: 'ticker-pill ticker-pill-priv' }, [
                    App.iconEl('rocket', 11),
                    App.el('span', { textContent: ' Privilege' })
                ]));
            }
            if (rightCol.childNodes.length === 0) {
                rightCol.appendChild(App.el('span', { className: 'ticker-pill ticker-pill-muted', textContent: 'Play' }));
            }

            item.appendChild(rightCol);
            el.appendChild(item);
        });

        var subnote = document.getElementById('ticker-subnote');
        if (subnote && stats.totals) {
            var n = stats.totals.cached_transactions || 0;
            subnote.textContent = App.formatNumber(n) + ' transactions cached';
        }
    }

    function switchView(view) {
        gameView = view;
        // Update toggle buttons
        var btns = document.querySelectorAll('#view-toggle .view-toggle-btn');
        btns.forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-view') === view);
        });
        gamePage = 1;
        renderGameView(allGames);
    }

    async function loadDashboard() {
        var gen = App.navGeneration();
        try {
            var results = await Promise.all([
                API.get('games'),
                API.get('overrides'),
                API.get('groups'),
                API.get('health').catch(function() { return {}; })
            ]);
            // If user navigated away while we were loading, discard results
            if (App.navGeneration() !== gen) return;

            var gamesData = results[0] || {};
            var overridesData = results[1] || {};
            var groupsData = results[2] || {};
            var healthData = results[3] || {};

            // Render security warnings if present
            var warningsEl = document.getElementById('security-warnings');
            if (warningsEl) {
                warningsEl.innerHTML = '';
                if (healthData.warnings && healthData.warnings.length > 0) {
                    healthData.warnings.forEach(function(msg) {
                        var alert = App.el('div', {
                            className: 'alert-warning',
                            style: { marginBottom: '0.75rem' }
                        });
                        alert.appendChild(App.iconEl('warning', 16, 'alert-icon'));
                        alert.appendChild(App.el('span', { textContent: msg }));
                        warningsEl.appendChild(alert);
                    });
                }
            }

            allGames = gamesData.games || [];
            allGroups = groupsData.groups || [];
            var activeOverrides = overridesData.active || [];

            renderStats(allGames);
            renderGroupControls(allGroups);
            renderStatusFilters(allGames);
            renderGameView(allGames);
            renderActiveOverrides(activeOverrides);

            // Update game count badge
            var badge = document.getElementById('game-count-badge');
            if (badge) badge.textContent = allGames.length + ' game' + (allGames.length !== 1 ? 's' : '');

            var groups = allGroups;

            // Adjust polling rate based on active overrides and upcoming transitions
            adjustPollingRate(activeOverrides, groups);

            // Schedule precise timers for override expiry
            scheduleExpiryTimers(activeOverrides);

            // Schedule precise timers for upcoming scheduled transitions
            scheduleTransitionTimers(groups);

            var syncEl = document.getElementById('last-sync');
            if (syncEl) {
                syncEl.textContent = gamesData.last_synced
                    ? 'Last synced ' + App.formatDatetime(gamesData.last_synced) + ' • ' + App.appTimezone
                    : 'Not yet synced — open Settings to configure CenterEdge';
            }
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }

    function renderStats(games) {
        var grid = document.getElementById('stats-grid');
        if (!grid) return;
        grid.innerHTML = '';

        var total = games.length;
        var enabled = games.filter(function(g) { return g.operation_status === 'enabled'; }).length;
        var paused = games.filter(function(g) { return g.operation_status === 'paused'; }).length;
        var oos = games.filter(function(g) { return g.operation_status === 'outOfService'; }).length;

        var stats = [
            { label: 'Total Games', value: total, cls: '' },
            { label: 'Enabled', value: enabled, cls: 'text-success' },
            { label: 'Paused', value: paused, cls: 'text-warning' },
            { label: 'Out of Service', value: oos, cls: 'text-danger' },
        ];

        stats.forEach(function(s) {
            grid.appendChild(App.el('div', { className: 'stat-card' }, [
                App.el('div', { className: 'stat-label', textContent: s.label }),
                App.el('div', { className: 'stat-value ' + s.cls, textContent: String(s.value) })
            ]));
        });
    }

    function renderStatusFilters(games) {
        var el = document.getElementById('status-filters');
        if (!el) return;
        el.innerHTML = '';

        var total = games.length;
        var enabled = games.filter(function(g) { return g.operation_status === 'enabled'; }).length;
        var paused = games.filter(function(g) { return g.operation_status === 'paused'; }).length;
        var oos = games.filter(function(g) { return g.operation_status === 'outOfService'; }).length;

        var filters = [
            { key: 'all', label: 'All', count: total, activeCls: 'active' },
            { key: 'enabled', label: 'Enabled', count: enabled, activeCls: 'active-enabled' },
            { key: 'paused', label: 'Paused', count: paused, activeCls: 'active-paused' },
            { key: 'outOfService', label: 'Out of Service', count: oos, activeCls: 'active-oos' },
        ];

        filters.forEach(function(f) {
            var pill = App.el('button', {
                className: 'filter-pill' + (gameStatusFilter === f.key ? ' ' + f.activeCls : ''),
                onClick: function() {
                    gameStatusFilter = f.key;
                    gamePage = 1;
                    renderStatusFilters(allGames);
                    renderGameView(allGames);
                }
            }, [
                App.el('span', { textContent: f.label }),
                App.el('span', { className: 'pill-count', textContent: '(' + f.count + ')' })
            ]);
            el.appendChild(pill);
        });
    }

    function getFilteredSortedGames(games) {
        // Filter by search
        var filtered = games;
        if (gameSearchTerm) {
            filtered = filtered.filter(function(g) {
                return g.game_name.toLowerCase().includes(gameSearchTerm);
            });
        }
        // Filter by status
        if (gameStatusFilter !== 'all') {
            filtered = filtered.filter(function(g) {
                return g.operation_status === gameStatusFilter;
            });
        }
        // Sort
        filtered.sort(function(a, b) {
            var aVal, bVal;
            if (gameSortCol === 'game_name') {
                aVal = (a.game_name || '').toLowerCase();
                bVal = (b.game_name || '').toLowerCase();
            } else if (gameSortCol === 'operation_status') {
                var order = { enabled: 0, paused: 1, outOfService: 2 };
                aVal = order[a.operation_status] !== undefined ? order[a.operation_status] : 3;
                bVal = order[b.operation_status] !== undefined ? order[b.operation_status] : 3;
            } else if (gameSortCol === 'game_id') {
                aVal = a.game_id || '';
                bVal = b.game_id || '';
            } else {
                aVal = a[gameSortCol] || '';
                bVal = b[gameSortCol] || '';
            }

            if (aVal < bVal) return gameSortDir === 'asc' ? -1 : 1;
            if (aVal > bVal) return gameSortDir === 'asc' ? 1 : -1;
            return 0;
        });

        return filtered;
    }

    function renderGameView(games) {
        var el = document.getElementById('game-content');
        if (!el) return;
        el.innerHTML = '';

        if (games.length === 0) {
            el.appendChild(App.emptyState('gamepad', 'No games found yet. Configure the CenterEdge API in Settings, then run Sync Now.'));
            return;
        }

        var filtered = getFilteredSortedGames(games);

        if (filtered.length === 0) {
            el.appendChild(App.el('div', { className: 'empty-state', style: { padding: '2rem' } }, [
                App.el('div', { className: 'empty-state-text', textContent: 'No games match the current filters.' })
            ]));
            return;
        }

        if (gameView === 'table') {
            renderGameTable(el, filtered);
        } else {
            renderGameGrid(el, filtered);
        }
    }

    function renderGameTable(container, filtered) {
        var totalItems = filtered.length;
        var totalPages = Math.ceil(totalItems / gamePageSize);
        if (gamePage > totalPages) gamePage = totalPages;
        if (gamePage < 1) gamePage = 1;

        var startIdx = (gamePage - 1) * gamePageSize;
        var pageItems = filtered.slice(startIdx, startIdx + gamePageSize);

        // Scrollable table
        var scrollContainer = App.el('div', { className: 'table-scroll-container' });
        var table = App.el('table', { className: 'table' });

        // Header
        var thead = App.el('thead');
        var headerRow = App.el('tr');

        var columns = [
            { key: 'game_name', label: 'Game Name', sortable: true },
            { key: 'game_id', label: 'Game ID', sortable: true },
            { key: 'operation_status', label: 'Status', sortable: true },
            { key: 'categories', label: 'Categories', sortable: false }
        ];

        columns.forEach(function(col) {
            var th = App.el('th', {
                className: (col.sortable ? 'sortable' : '') + (gameSortCol === col.key ? ' sorted' : '')
            });
            th.appendChild(App.el('span', { textContent: col.label }));
            if (col.sortable) {
                var sortIcon = gameSortCol === col.key
                    ? (gameSortDir === 'asc' ? '\u25B2' : '\u25BC')
                    : '\u25B4';
                th.appendChild(App.el('span', { className: 'sort-icon', textContent: sortIcon }));
                th.addEventListener('click', function() {
                    if (gameSortCol === col.key) {
                        gameSortDir = gameSortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        gameSortCol = col.key;
                        gameSortDir = 'asc';
                    }
                    renderGameView(allGames);
                });
            }
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        // Body
        var tbody = App.el('tbody');
        pageItems.forEach(function(game) {
            var row = App.el('tr');

            // Game name
            row.appendChild(App.el('td', {}, [
                App.el('span', { textContent: game.game_name, style: { fontWeight: '500' } })
            ]));

            // Game ID
            row.appendChild(App.el('td', {
                className: 'text-muted text-sm font-mono',
                textContent: game.game_id || '-'
            }));

            // Status badge
            row.appendChild(App.el('td', {}, [
                App.statusBadge(game.operation_status)
            ]));

            // Categories
            var cats = game.categories || [];
            var catText = cats.length > 0
                ? (typeof cats[0] === 'object' ? cats.map(function(c) { return c.name || c; }).join(', ') : cats.join(', '))
                : '-';
            row.appendChild(App.el('td', {
                className: 'text-sm text-secondary',
                textContent: catText,
                style: { maxWidth: '250px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
                title: catText
            }));

            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        scrollContainer.appendChild(table);
        container.appendChild(scrollContainer);

        // Pagination bar
        if (totalPages > 1 || totalItems > 25) {
            container.appendChild(buildGamePagination(totalItems, totalPages));
        }
    }

    function buildGamePagination(totalItems, totalPages) {
        var bar = App.el('div', { className: 'pagination-bar' });

        var startIdx = (gamePage - 1) * gamePageSize + 1;
        var endIdx = Math.min(gamePage * gamePageSize, totalItems);

        bar.appendChild(App.el('div', { className: 'pagination-info' }, [
            App.el('span', { textContent: 'Showing ' + startIdx + '-' + endIdx + ' of ' + totalItems }),
            App.el('select', {
                className: 'page-size-select',
                onChange: function() {
                    gamePageSize = parseInt(this.value);
                    gamePage = 1;
                    renderGameView(allGames);
                }
            }, [25, 50, 100].map(function(size) {
                var opt = App.el('option', { value: String(size), textContent: size + ' / page' });
                if (size === gamePageSize) opt.selected = true;
                return opt;
            }))
        ]));

        var controls = App.el('div', { className: 'pagination-controls' });

        // First
        controls.appendChild(App.el('button', {
            className: 'btn btn-ghost btn-sm',
            textContent: '\u00AB',
            disabled: gamePage <= 1,
            title: 'First page',
            onClick: function() { gamePage = 1; renderGameView(allGames); }
        }));

        // Previous
        controls.appendChild(App.el('button', {
            className: 'btn btn-ghost btn-sm',
            textContent: '\u2039',
            disabled: gamePage <= 1,
            title: 'Previous page',
            onClick: function() { gamePage--; renderGameView(allGames); }
        }));

        // Page indicator
        controls.appendChild(App.el('span', {
            className: 'text-sm',
            style: { padding: '0 0.5rem' },
            textContent: gamePage + ' / ' + totalPages
        }));

        // Next
        controls.appendChild(App.el('button', {
            className: 'btn btn-ghost btn-sm',
            textContent: '\u203A',
            disabled: gamePage >= totalPages,
            title: 'Next page',
            onClick: function() { gamePage++; renderGameView(allGames); }
        }));

        // Last
        controls.appendChild(App.el('button', {
            className: 'btn btn-ghost btn-sm',
            textContent: '\u00BB',
            disabled: gamePage >= totalPages,
            title: 'Last page',
            onClick: function() { gamePage = totalPages; renderGameView(allGames); }
        }));

        bar.appendChild(controls);
        return bar;
    }

    function renderGameGrid(container, filtered) {
        // In grid mode, paginate too for large sets
        var totalItems = filtered.length;
        var totalPages = Math.ceil(totalItems / gamePageSize);
        if (gamePage > totalPages) gamePage = totalPages;
        if (gamePage < 1) gamePage = 1;

        var startIdx = (gamePage - 1) * gamePageSize;
        var pageItems = filtered.slice(startIdx, startIdx + gamePageSize);

        var grid = App.el('div', { className: 'game-grid' });
        pageItems.forEach(function(game) {
            var tile = App.el('div', {
                className: 'game-tile',
                'data-status': game.operation_status
            }, [
                App.el('div', { className: 'game-tile-name', textContent: game.game_name }),
                App.el('div', { className: 'game-tile-status' }, [
                    App.statusBadge(game.operation_status)
                ])
            ]);
            grid.appendChild(tile);
        });
        container.appendChild(grid);

        // Pagination bar
        if (totalPages > 1 || totalItems > 25) {
            container.appendChild(buildGamePagination(totalItems, totalPages));
        }
    }

    function renderGroupControls(groups) {
        var el = document.getElementById('group-controls');
        var masterEl = document.getElementById('master-controls');
        var summaryEl = document.getElementById('groups-summary');
        var countBadge = document.getElementById('group-count-badge');
        if (!el) return;
        el.innerHTML = '';
        if (masterEl) masterEl.innerHTML = '';
        if (summaryEl) summaryEl.innerHTML = '';

        var activeGroups = groups.filter(function(g) { return g.is_active == 1; });

        // Update count badge
        if (countBadge) countBadge.textContent = activeGroups.length + ' active group' + (activeGroups.length !== 1 ? 's' : '');

        if (activeGroups.length === 0) {
            var createBtn = App.el('button', {
                className: 'btn btn-primary btn-sm',
                onClick: function () { window.location.hash = '#/groups/new'; }
            });
            createBtn.appendChild(App.iconEl('plus', 14));
            createBtn.appendChild(App.el('span', { textContent: 'Create your first group' }));
            el.appendChild(App.emptyState('groups', 'No active pause groups yet. Groups bundle games and kiosks so they pause and unpause together.', createBtn));
            return;
        }

        // Master controls
        if (masterEl && activeGroups.length > 1) {
            var hasAnyPaused = activeGroups.some(function(g) { return g.effective_state === 'paused' || g.effective_state === 'mixed'; });
            var hasAnyEnabled = activeGroups.some(function(g) { return g.effective_state === 'enabled' || g.effective_state === 'mixed'; });

            if (hasAnyPaused) {
                var unpauseAllBtn = App.el('button', {
                    className: 'btn btn-sm btn-success',
                    onClick: function() { doBulkAction('unpause', activeGroups); }
                });
                unpauseAllBtn.appendChild(App.iconEl('play', 12));
                unpauseAllBtn.appendChild(App.el('span', { textContent: 'Unpause All' }));
                masterEl.appendChild(unpauseAllBtn);
            }
            if (hasAnyEnabled) {
                var pauseAllBtn = App.el('button', {
                    className: 'btn btn-sm btn-warning',
                    onClick: function() { doBulkAction('pause', activeGroups); }
                });
                pauseAllBtn.appendChild(App.iconEl('pause', 12));
                pauseAllBtn.appendChild(App.el('span', { textContent: 'Pause All' }));
                masterEl.appendChild(pauseAllBtn);
            }
        }

        // Collapsed summary view
        if (summaryEl) {
            activeGroups.forEach(function(group) {
                var state = group.effective_state || 'empty';
                var item = App.el('div', { className: 'groups-summary-item' }, [
                    App.el('span', { className: 'status-dot status-dot-' + state }),
                    App.el('span', { textContent: group.name, style: { fontWeight: '500', fontSize: '0.82rem' } }),
                    App.el('span', {
                        className: 'text-xs text-muted',
                        textContent: formatMemberSummary(group)
                    })
                ]);
                summaryEl.appendChild(item);
            });
        }

        // Full card view
        activeGroups.forEach(function(group) {
            var combined = group.combined_stats || group.game_stats || {};
            var state = group.effective_state || 'empty';
            var override = group.active_override;
            var nextTrans = group.next_transition;

            var stateLabel = state === 'paused' ? 'Paused'
                : state === 'enabled' ? 'Running'
                : state === 'mixed' ? 'Mixed'
                : 'No Members';

            var isPaused = state === 'paused';
            var isEmpty = state === 'empty';

            var card = App.el('div', {
                className: 'group-control-card',
                'data-state': state
            });

            // Header: status dot + name | state badge
            card.appendChild(App.el('div', { className: 'group-control-header' }, [
                App.el('div', { className: 'group-control-title' }, [
                    App.el('span', { className: 'status-dot status-dot-' + state }),
                    App.el('span', { className: 'group-control-name', textContent: group.name })
                ]),
                App.el('span', {
                    className: 'group-control-state group-control-state-' + state,
                    textContent: stateLabel
                })
            ]));

            // Stats: member summary + progress bar + breakdown across games AND kiosks
            if (combined.total > 0) {
                var enabledPct = (combined.enabled / combined.total * 100).toFixed(1);
                var pausedPct = (combined.paused / combined.total * 100).toFixed(1);
                var oosPct = (combined.out_of_service / combined.total * 100).toFixed(1);

                card.appendChild(App.el('div', { className: 'group-control-stats' }, [
                    App.el('span', { className: 'text-muted', textContent: formatMemberSummary(group) }),
                    App.el('div', { className: 'progress-bar' }, [
                        App.el('div', { className: 'progress-fill-enabled', style: { width: enabledPct + '%' } }),
                        App.el('div', { className: 'progress-fill-paused', style: { width: pausedPct + '%' } }),
                        App.el('div', { className: 'progress-fill-oos', style: { width: oosPct + '%' } })
                    ]),
                    App.el('span', { className: 'text-xs' }, [
                        App.el('span', { className: 'text-success', textContent: String(combined.enabled) }),
                        App.el('span', { className: 'text-muted', textContent: ' / ' }),
                        App.el('span', { className: 'text-warning', textContent: String(combined.paused) }),
                        combined.out_of_service > 0
                            ? App.el('span', { className: 'text-muted', textContent: ' / ' })
                            : null,
                        combined.out_of_service > 0
                            ? App.el('span', { className: 'text-danger', textContent: String(combined.out_of_service) })
                            : null
                    ].filter(Boolean))
                ]));
            }

            // Context: manual override, active override, or next scheduled transition
            var manualOvr = group.manual_override;
            if (manualOvr) {
                var manualLabel = manualOvr.action === 'pause' ? 'Manually Paused' : 'Manually Unpaused';
                var ctx = App.el('div', { className: 'group-control-context group-control-context-manual' });
                ctx.appendChild(App.iconEl('hand', 14, 'context-icon'));
                ctx.appendChild(App.el('span', { style: { fontWeight: '600' }, textContent: manualLabel }));
                ctx.appendChild(App.el('span', { style: { opacity: '0.75' }, textContent: ' \u2022 since ' + App.formatDatetime(manualOvr.at) }));
                ctx.appendChild(App.el('button', {
                    className: 'btn btn-ghost btn-xs',
                    textContent: 'Resume',
                    style: { marginLeft: 'auto' },
                    title: 'Clear manual override and resume the schedule',
                    onClick: function() { clearManualOverride(group.id, group.name); }
                }));
                card.appendChild(ctx);
            } else if (override) {
                var ovrCtx = App.el('div', { className: 'group-control-context group-control-context-override' });
                ovrCtx.appendChild(App.iconEl('bolt', 14, 'context-icon'));
                ovrCtx.appendChild(App.el('span', { style: { fontWeight: '600' }, textContent: override.name }));
                ovrCtx.appendChild(App.el('span', { style: { opacity: '0.75' }, textContent: ' \u2022 ' + override.action + ' \u2022 ends ' + App.formatDatetime(override.end_datetime) }));
                card.appendChild(ovrCtx);
            } else if (nextTrans) {
                var transCtx = App.el('div', { className: 'group-control-context' });
                transCtx.appendChild(App.iconEl('clock', 14, 'context-icon'));
                transCtx.appendChild(App.el('span', { textContent: (nextTrans.action === 'pause' ? 'Pause' : 'Unpause') + ' scheduled at ' + App.formatTime(nextTrans.time) }));
                card.appendChild(transCtx);
            }

            // Action buttons (with icons)
            var actionRow = App.el('div', { className: 'group-control-actions' });

            function actionBtn(cls, iconName, label, handler) {
                var b = App.el('button', { className: 'btn ' + cls, type: 'button', onClick: handler });
                b.appendChild(App.iconEl(iconName, 13));
                b.appendChild(App.el('span', { textContent: label }));
                return b;
            }

            if (isEmpty) {
                actionRow.appendChild(App.el('span', {
                    className: 'text-muted text-xs',
                    style: { padding: '0.35rem 0', display: 'block', textAlign: 'center', width: '100%' },
                    textContent: 'No games or kiosks assigned to this group'
                }));
            } else if (state === 'mixed') {
                actionRow.appendChild(actionBtn('btn-success', 'play', 'Unpause All',
                    function() { doGroupAction(group.id, 'unpause', group.name, combined.total); }));
                actionRow.appendChild(actionBtn('btn-warning', 'pause', 'Pause All',
                    function() { doGroupAction(group.id, 'pause', group.name, combined.total); }));
            } else if (isPaused) {
                actionRow.appendChild(actionBtn('btn-success', 'play', 'Unpause Group',
                    function() { doGroupAction(group.id, 'unpause', group.name, combined.total); }));
            } else {
                actionRow.appendChild(actionBtn('btn-warning', 'pause', 'Pause Group',
                    function() { doGroupAction(group.id, 'pause', group.name, combined.total); }));
            }

            card.appendChild(actionRow);
            el.appendChild(card);
        });
    }

    async function doGroupAction(groupId, action, groupName, memberCount) {
        var verb = action === 'pause' ? 'Pause' : 'Unpause';
        var noun = memberCount === 1 ? 'item' : 'items';
        var countLabel = memberCount > 0 ? memberCount + ' ' + noun : 'everything';
        var msg = verb + ' ' + countLabel + ' in "' + groupName + '"?';
        var confirmed = await App.confirm(msg);
        if (!confirmed) return;

        // Optimistic UI update: apply expected state change immediately
        var desiredStatus = action === 'pause' ? 'paused' : 'enabled';
        applyOptimisticGroupAction(groupId, desiredStatus);
        renderStats(allGames);
        renderGroupControls(allGroups);
        renderStatusFilters(allGames);
        renderGameView(allGames);
        setControlsLoading(true);

        try {
            var result = await API.post('groups/' + encodeURIComponent(groupId) + '/' + encodeURIComponent(action)) || {};
            var changed = result.changed || 0;
            var errors = result.errors || 0;

            if (errors > 0) {
                App.toast(verb + ' partially failed: ' + changed + ' changed, ' + errors + ' error(s).', 'warning');
            } else if (changed > 0) {
                App.toast(groupName + ': ' + changed + ' game' + (changed !== 1 ? 's' : '') + ' ' + action + 'd.', 'success');
            } else {
                App.toast(groupName + ': all games already ' + action + 'd.', 'info');
            }

            // Reconcile with actual server response
            applyChangedGames(result.details);
            renderStats(allGames);
            renderStatusFilters(allGames);
            renderGameView(allGames);

            // Background refresh for full consistency
            setControlsLoading(false);
            loadDashboard();
        } catch (err) {
            App.toast(verb + ' failed: ' + err.message, 'error');
            // Revert optimistic update on failure
            setControlsLoading(false);
            loadDashboard();
        }
    }

    async function doBulkAction(action, groups) {
        var verb = action === 'pause' ? 'Pause' : 'Unpause';
        var count = groups.length;
        var confirmed = await App.confirm(verb + ' all games across ' + count + ' group' + (count !== 1 ? 's' : '') + '?');
        if (!confirmed) return;

        // Optimistic UI update: apply expected state to all targeted groups
        var desiredStatus = action === 'pause' ? 'paused' : 'enabled';
        for (var j = 0; j < groups.length; j++) {
            var grp = groups[j];
            if (grp.effective_state === 'empty') continue;
            if (action === 'pause' && grp.effective_state === 'paused') continue;
            if (action === 'unpause' && grp.effective_state === 'enabled') continue;
            applyOptimisticGroupAction(grp.id, desiredStatus);
        }
        renderStats(allGames);
        renderGroupControls(allGroups);
        renderStatusFilters(allGames);
        renderGameView(allGames);
        setControlsLoading(true);

        // Snapshot which groups need action BEFORE optimistic updates change state
        var actionableGroups = groups.filter(function(g) {
            if (g.effective_state === 'empty') return false;
            if (action === 'pause' && g.effective_state === 'paused') return false;
            if (action === 'unpause' && g.effective_state === 'enabled') return false;
            return true;
        });

        var totalChanged = 0;
        var totalErrors = 0;

        try {
            for (var i = 0; i < actionableGroups.length; i++) {
                var g = actionableGroups[i];

                try {
                    var result = await API.post('groups/' + encodeURIComponent(g.id) + '/' + encodeURIComponent(action)) || {};
                    totalChanged += result.changed || 0;
                    totalErrors += result.errors || 0;

                    // Reconcile with actual response
                    applyChangedGames(result.details);
                } catch (err) {
                    totalErrors++;
                }
            }

            if (totalErrors > 0) {
                App.toast(verb + ' completed with errors: ' + totalChanged + ' changed, ' + totalErrors + ' error(s).', 'warning');
            } else if (totalChanged > 0) {
                App.toast('All groups ' + action + 'd: ' + totalChanged + ' game' + (totalChanged !== 1 ? 's' : '') + ' updated.', 'success');
            } else {
                App.toast('All games already ' + action + 'd.', 'info');
            }

            // Background refresh for full consistency
            setControlsLoading(false);
            loadDashboard();
        } catch (err) {
            App.toast('Bulk ' + action + ' failed: ' + err.message, 'error');
            setControlsLoading(false);
            loadDashboard();
        }
    }

    /**
     * Optimistic update: apply expected state change to all games in a group
     * and update the group's local state, before the API call returns.
     * Uses game_ids from the groups API to know which games belong to the group.
     * Kiosks are flipped only at the stats level (we don't track kiosk state
     * locally) so the group card still toggles state immediately.
     */
    function applyOptimisticGroupAction(groupId, desiredStatus) {
        // Find the group and its game IDs
        var group = null;
        for (var i = 0; i < allGroups.length; i++) {
            if (allGroups[i].id == groupId) { group = allGroups[i]; break; }
        }
        if (!group) return;

        // Build a set of game IDs in this group
        var groupGameSet = {};
        (group.game_ids || []).forEach(function(id) { groupGameSet[id] = true; });

        // Update allGames in place
        var gEnabled = 0, gPaused = 0, gOos = 0;
        allGames.forEach(function(game) {
            if (groupGameSet[game.game_id]) {
                // Only change non-outOfService games
                if (game.operation_status !== 'outOfService') {
                    game.operation_status = desiredStatus;
                }
                if (game.operation_status === 'enabled') gEnabled++;
                else if (game.operation_status === 'paused') gPaused++;
                else if (game.operation_status === 'outOfService') gOos++;
            }
        });

        group.game_stats = {
            total: gEnabled + gPaused + gOos,
            enabled: gEnabled,
            paused: gPaused,
            out_of_service: gOos
        };

        // Optimistically flip kiosk stats too (server reconciles on next poll).
        var ks = group.kiosk_stats || { total: 0, enabled: 0, paused: 0, out_of_service: 0, unknown: 0 };
        var kFlippable = ks.total - (ks.out_of_service || 0) - (ks.unknown || 0);
        var kEnabled = desiredStatus === 'paused' ? 0 : kFlippable;
        var kPaused = desiredStatus === 'paused' ? kFlippable : 0;
        group.kiosk_stats = {
            total: ks.total,
            enabled: kEnabled,
            paused: kPaused,
            out_of_service: ks.out_of_service || 0,
            unknown: ks.unknown || 0
        };

        var cEnabled = gEnabled + kEnabled;
        var cPaused = gPaused + kPaused;
        var cOos = gOos + (ks.out_of_service || 0);
        var cTotal = cEnabled + cPaused + cOos;
        group.combined_stats = {
            total: cTotal,
            enabled: cEnabled,
            paused: cPaused,
            out_of_service: cOos
        };
        group.effective_state = cTotal === 0 ? 'empty'
            : (cPaused > 0 && cEnabled === 0 ? 'paused'
            : (cEnabled > 0 && cPaused === 0 ? 'enabled' : 'mixed'));

        // Mark as manually overridden
        group.manual_override = { action: desiredStatus === 'paused' ? 'pause' : 'unpause', at: new Date().toISOString() };
    }

    /**
     * One-line summary of what's in a group, omitting empty parts and
     * pluralizing properly. Example outputs:
     *   "12 games"  /  "2 kiosks"  /  "12 games, 2 kiosks"  /  "no members"
     */
    function formatMemberSummary(group) {
        var gs = group.game_stats || {};
        var ks = group.kiosk_stats || {};
        var games = gs.total || 0;
        var kiosks = ks.total || 0;
        var parts = [];
        if (games > 0) parts.push(games + ' game' + (games !== 1 ? 's' : ''));
        if (kiosks > 0) parts.push(kiosks + ' kiosk' + (kiosks !== 1 ? 's' : ''));
        return parts.length === 0 ? 'no members' : parts.join(', ');
    }

    /**
     * Apply changed game states from an action response to the local allGames
     * array for instant UI feedback without waiting for a full dashboard reload.
     */
    function applyChangedGames(details) {
        if (!details) return;
        var changed = details.changed || [];
        if (changed.length === 0) return;

        // Build a lookup for quick matching
        var changeMap = {};
        changed.forEach(function(c) {
            changeMap[c.game_id] = c.new_status;
        });

        // Update allGames in place
        allGames.forEach(function(game) {
            if (changeMap[game.game_id]) {
                game.operation_status = changeMap[game.game_id];
            }
        });
    }

    function setControlsLoading(loading) {
        var btns = document.querySelectorAll('#group-controls .btn, #master-controls .btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].disabled = loading;
        }
    }

    function renderActiveOverrides(overrides) {
        var el = document.getElementById('active-overrides');
        if (!el) return;
        el.innerHTML = '';

        if (overrides.length === 0) {
            el.appendChild(App.el('p', { className: 'text-muted text-sm', style: { padding: '0.25rem 0' }, textContent: 'No active overrides right now. Schedules and recurring rules are running normally.' }));
            return;
        }

        overrides.forEach(function(o) {
            var actionBadge = App.el('span', {
                className: 'badge ' + (o.action === 'pause' ? 'badge-paused' : 'badge-enabled'),
                textContent: o.action === 'pause' ? 'Pause' : 'Unpause'
            });

            var card = App.el('div', { className: 'override-card' }, [
                App.el('div', { className: 'override-info' }, [
                    App.el('div', { className: 'flex-center gap-sm', style: { marginBottom: '0.2rem' } }, [
                        App.el('span', { className: 'override-name', textContent: o.name }),
                        actionBadge
                    ]),
                    App.el('div', { className: 'override-meta' }, [
                        App.el('span', { textContent: (o.group_name || '\u2014') + ' \u2022 ends ' + App.formatDatetime(o.end_datetime) })
                    ])
                ]),
                App.el('div', { className: 'override-countdown', textContent: App.formatRelative(o.end_datetime) })
            ]);
            el.appendChild(card);
        });
    }

    async function clearManualOverride(groupId, groupName) {
        var confirmed = await App.confirm('Resume automatic schedule for "' + groupName + '"? The group will return to its scheduled state.');
        if (!confirmed) return;

        try {
            var result = await API.post('groups/' + encodeURIComponent(groupId) + '/clear-manual-override') || {};
            App.toast(groupName + ': resumed automatic scheduling.', 'success');

            // Apply any state changes from enforcement for instant UI feedback
            applyChangedGames(result.enforced);
            renderStats(allGames);
            renderStatusFilters(allGames);
            renderGameView(allGames);

            // Background refresh for full consistency
            loadDashboard();
        } catch (err) {
            App.toast('Failed to clear manual override: ' + err.message, 'error');
        }
    }

    async function syncGames() {
        var btn = document.getElementById('sync-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '';
            btn.appendChild(App.el('span', { className: 'spinner spinner-sm' }));
            btn.appendChild(App.el('span', { textContent: 'Syncing…' }));
        }

        try {
            await API.post('games/sync');
            // Best-effort: also pull latest transactions so the hero tiles
            // and live ticker refresh immediately. Don't block on failure.
            try { await API.post('stats/sync'); } catch (e) { /* ignore */ }
            App.toast('Games synced successfully.', 'success');
            await Promise.all([loadDashboard(), loadStats()]);
        } catch (err) {
            App.toast('Sync failed: ' + err.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '';
                btn.appendChild(App.iconEl('sync', 14));
                btn.appendChild(App.el('span', { textContent: 'Sync Now' }));
            }
        }
    }
})();
