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
    var allTransactions = [];   // recent plays cache (drives live feed)
    var feedMeta = null;        // { last_poll_at, total_cached }
    var ticketStats = {};   // Map of game_id -> { tickets_today, plays_today, ... }
    var ticketTotals = null; // Aggregate totals across the venue
    var payoutData = null;   // Ticket payout % per window + target, from /transactions/payout
    var payoutTargetPct = 33; // House payout target — refreshed from ticket-stats on every poll
    var hourlyToday = null;  // Server 24-bin hourly plays/tickets for today (accurate peak hour)
    var hourlyRecent = null; // Server 12-bin last-12-clock-hours (accurate hero sparkline)
    var lastActiveOverrides = []; // Last-seen active overrides — lets optimistic re-renders refresh the insight row
    // Today's birthdays and work anniversaries, for the celebration strip under
    // the header. Fetched on its own slow cadence rather than with the 30s
    // poll — the roster changes once a day at most, and both endpoints behind
    // it read the POS database. See loadCelebrations().
    var CELEBRATE_REFRESH_MS = 600000;   // 10 minutes
    var celebrations = { birthdays: null, anniversaries: null };
    var celebrationsFetchedAt = 0;
    var refreshIntervalCleanup = null;
    var expiryTimers = [];
    var transitionTimers = [];
    var currentInterval = INTERVAL_DEFAULT;

    // Track previous values of the hero KPIs so we can:
    //   - animate count-ups smoothly between polls
    //   - flash the value when it ticks up (a new play was recorded)
    //   - know which transaction IDs are "new" since the last render
    var heroPrev = {
        tickets_today: 0,
        plays_today: 0,
        tickets_hour: 0,
        plays_hour: 0,
        unique_cards_today: 0,
        active_now: 0,
        seenTxnIds: null     // null on first render, then a Set
    };

    // Previous stat-card values (keyed by status filter) so the count-up
    // animation only runs when a count actually changes, not on every poll.
    var statPrev = {};

    // Last load-failure message — suppresses duplicate error toasts while
    // consecutive polls keep failing during an outage.
    var lastLoadError = null;

    // Live feed cap — pull a generous slice from the cache so paging through
    // the dashboard's feed doesn't round-trip per page change.
    var FEED_LIMIT = 500;
    var TOP_LIMIT = 10;

    // View state
    var gameView = 'table'; // 'grid' or 'table'
    var gameStatusFilter = 'all'; // 'all', 'enabled', 'paused', 'outOfService'
    var gameSearchTerm = '';
    var gameSortCol = 'game_name';
    var gameSortDir = 'asc';
    var gamePage = 1;
    var gamePageSize = 25;
    var groupsCollapsed = false;
    var topWindow = 'today'; // 'hour' | 'today' | 'week' | 'all' for top games widget

    // Live-feed search + pagination state — small page sizes because the
    // feed sits next to the top-games widget on the dashboard and shouldn't
    // dominate the viewport.
    var feedSearch = '';
    var feedPaging = { page: 1, pageSize: 10, totalItems: 0 };
    var feedLastRenderKey = null; // page|search|size of the last render — scroll restore only when unchanged

    function scheduleNextPoll() {
        if (refreshIntervalCleanup) refreshIntervalCleanup();
        refreshIntervalCleanup = App.createVisibilityAwareInterval(loadDashboard, currentInterval, {
            runImmediately: false,
            runOnVisible: true
        });
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

        // Page header — title block on the left, live status pills (clock /
        // next-action / last-sync) on the right next to the Sync button so the
        // operator can read the venue's "where are we right now" without
        // scrolling. The pills update via updateHeaderClock() on a 1s tick.
        var headerInfo = App.el('div', { className: 'page-header-info' }, [
            App.el('div', { className: 'header-pill header-pill-clock', id: 'header-clock', title: 'Venue local time' }, [
                App.el('span', { className: 'header-pill-icon', textContent: '⏱', 'aria-hidden': 'true' }),
                App.el('span', { className: 'header-pill-text', id: 'header-clock-text', textContent: '—' })
            ]),
            App.el('div', { className: 'header-pill header-pill-next', id: 'header-next-action',
                title: 'Next scheduled pause / unpause across all groups',
                style: { display: 'none' } }, [
                App.el('span', { className: 'header-pill-icon', textContent: '⏭', 'aria-hidden': 'true' }),
                App.el('span', { className: 'header-pill-text', id: 'header-next-action-text', textContent: '—' })
            ])
        ]);

        container.appendChild(App.el('div', { className: 'page-header dash-page-header' }, [
            App.el('div', { className: 'page-header-titleblock' }, [
                App.el('h1', { className: 'page-title', textContent: 'Command Center' }),
                App.el('p', { className: 'page-subtitle', id: 'last-sync', textContent: 'Getting the latest from the venue…' })
            ]),
            App.el('div', { className: 'page-header-actions' },
                [headerInfo].concat(App.canAccess('manual_control') ? [App.el('button', {
                    className: 'btn btn-secondary', id: 'sync-btn', textContent: 'Sync now',
                    onClick: syncGames
                })] : []))
        ]));

        // Security warnings banner (populated by loadDashboard)
        container.appendChild(App.el('div', { id: 'security-warnings' }));

        // Today's birthdays and work anniversaries. Empty and INVISIBLE on the
        // days when nobody is celebrating — it sits above the fold, so it has
        // to earn its space rather than reserve it. Populated by
        // loadCelebrations(), which runs on its own slow cadence.
        container.appendChild(App.el('div', { id: 'dash-celebrations', style: { display: 'none' } }));

        // Plain-words headline row — is everything running, is anyone
        // holding a manual override, when was the room busiest. Populated
        // by renderDashInsights() on every poll.
        container.appendChild(App.el('div', { id: 'dash-insights' }));

        // Stats cards (game-state counts only — ticket KPIs live in the
        // ticket-summary banner below)
        var statsGrid = App.el('div', { className: 'stats-grid', id: 'stats-grid' });
        container.appendChild(statsGrid);

        // Sales-data widgets (ticket summary, live feed, top games) are
        // hidden for the 'tech' role since those endpoints expose revenue
        // and ticket totals. Admin and manager roles see the full dashboard.
        var canSeeSales = App.canAccess('analytics');

        if (canSeeSales) {
            // Hero "pulse" banner — large animated counter for tickets dispensed
            // today, with secondary stats and an inline 12-hour sparkline so
            // the operator can see the venue's heartbeat at a glance. Populated
            // by renderHeroPulse() on every poll.
            container.appendChild(buildHeroSkeleton());

            // Compact KPI strip below the hero — Plays / Avg per play /
            // Unique cards / 7-day tickets. Populated by renderTicketSummary().
            container.appendChild(App.el('div', { id: 'ticket-summary', className: 'ticket-summary mt-2' }));

            // Year over year — month-to-date and year-to-date ACTUALS against
            // the same stretch of last year. Completed days on both sides, no
            // projection. Populated by loadYoy() on every poll.
            container.appendChild(App.buildYoyCard({
                id: 'yoy-card',
                bodyId: 'yoy-body',
                className: 'mt-2',
                subtitle: 'Where the venue stands this month and this year against the same stretch of last year'
            }));

            // Ticket payout gauge — tickets dispensed per point played, per
            // window, against the house target (default ≤33%). Populated by
            // renderPayout() on every poll.
            container.appendChild(App.el('div', { className: 'card mt-2', id: 'payout-card' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', {}, [
                        App.el('div', { className: 'card-title', textContent: 'Ticket payout %' }),
                        App.el('div', { className: 'text-sm text-secondary', id: 'payout-subtitle',
                            textContent: 'Tickets dispensed per 100 points played on redemption games' })
                    ]),
                    App.el('span', { id: 'payout-caption', className: 'text-sm text-secondary', textContent: 'Loading…' })
                ]),
                App.el('div', { className: 'card-body' }, [
                    App.el('div', { style: { height: '150px' } }, [
                        App.el('canvas', {
                            id: 'payout-canvas',
                            style: { width: '100%', height: '100%', display: 'block' },
                            'aria-label': 'Ticket payout percentage by time window vs target'
                        })
                    ])
                ])
            ]));
        }

        // Two-column row: live play feed on the left, top games on the right.
        // The feed is paginated (5/10/25/50 per page, default 10) so a busy
        // venue's transaction stream doesn't overwhelm the dashboard.
        var feedSearchInput = App.buildSearchInput({
            placeholder: 'Search plays by game or card…',
            ariaLabel: 'Search live play feed',
            value: feedSearch,
            onSearch: function(term) {
                feedSearch = term;
                feedPaging.page = 1;
                renderFeed();
            }
        });

        if (canSeeSales) {
            container.appendChild(App.el('div', { className: 'dash-overview-grid mt-2' }, [
                App.el('div', { id: 'dash-feed-wrap', className: 'card' }, [
                    App.el('div', { className: 'card-header flex-between' }, [
                        App.el('div', { className: 'card-title', textContent: 'Live play feed' }),
                        App.el('div', { className: 'flex-center gap-sm' }, [
                            App.el('span', { id: 'dash-feed-meta', className: 'text-sm text-secondary' }),
                            App.el('button', {
                                className: 'btn btn-ghost btn-sm', textContent: 'Refresh',
                                onClick: function() { manualPoll(); }
                            })
                        ])
                    ]),
                    App.el('div', { className: 'card-body' }, [
                        App.el('div', { className: 'toolbar-row', style: { marginBottom: '0.5rem' } }, [
                            feedSearchInput
                        ]),
                        App.el('div', { id: 'dash-feed-body' }, [App.loading()]),
                        App.el('div', { id: 'dash-feed-pagination' })
                    ])
                ]),
                App.el('div', { id: 'dash-top-wrap', className: 'card' }, [
                    App.el('div', { className: 'card-header flex-between' }, [
                        App.el('div', { className: 'card-title', textContent: 'Top by tickets' }),
                        buildTopWindowSelector()
                    ]),
                    App.el('div', { id: 'dash-top-body', className: 'card-body' }, [App.loading()])
                ]),
                App.el('div', { id: 'dash-topplays-wrap', className: 'card' }, [
                    App.el('div', { className: 'card-header flex-between' }, [
                        App.el('div', { className: 'card-title', textContent: 'Top by plays' }),
                        // Echo the shared window selector's choice so this
                        // card isn't silently re-scoped by the other card.
                        App.el('span', { id: 'dash-topplays-window', className: 'text-sm text-secondary',
                            textContent: topWindowLabel(topWindow) })
                    ]),
                    App.el('div', { id: 'dash-topplays-body', className: 'card-body' }, [App.loading()])
                ])
            ]));

            // Ticket watch — top earners today with farming signals. Card
            // numbers already appear in the live feed for these roles.
            container.appendChild(App.el('div', { className: 'card mt-2', id: 'ticket-watch-card' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', {}, [
                        App.el('div', { className: 'card-title', textContent: 'Ticket watch' }),
                        App.el('div', { className: 'text-sm text-secondary', id: 'ticket-watch-subtitle',
                            textContent: 'Top ticket earners today — ⚠ rows trip two or more farming signals' })
                    ])
                ]),
                App.el('div', { className: 'card-body', id: 'ticket-watch-body' }, [App.loading()])
            ]));
        }

        // Group controls (collapsible)
        container.appendChild(App.el('div', { className: 'card mt-2', id: 'group-controls-card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'flex-center gap-sm' }, [
                    App.el('div', { className: 'card-title', textContent: 'Group controls' }),
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
                App.el('div', { className: 'card-title', textContent: 'Game status' }),
                App.el('span', { className: 'badge badge-info', id: 'game-count-badge', textContent: '0 games' })
            ]),
            App.el('div', { className: 'view-toggle', id: 'view-toggle' }, [
                App.el('button', {
                    className: 'view-toggle-btn' + (gameView === 'table' ? ' active' : ''),
                    textContent: 'Table',
                    'data-view': 'table',
                    'aria-pressed': gameView === 'table' ? 'true' : 'false',
                    onClick: function() { switchView('table'); }
                }),
                App.el('button', {
                    className: 'view-toggle-btn' + (gameView === 'grid' ? ' active' : ''),
                    textContent: 'Grid',
                    'data-view': 'grid',
                    'aria-pressed': gameView === 'grid' ? 'true' : 'false',
                    onClick: function() { switchView('grid'); }
                })
            ])
        ]));

        // Toolbar: search + status filter pills
        var toolbar = App.el('div', { className: 'toolbar-row', id: 'game-toolbar' });
        var searchInput = App.buildSearchInput({
            placeholder: 'Search games...',
            ariaLabel: 'Search games',
            value: gameSearchTerm,
            onSearch: function(term) {
                gameSearchTerm = term.toLowerCase();
                gamePage = 1;
                renderGameView(allGames);
            }
        });
        searchInput.id = 'game-search';
        toolbar.appendChild(searchInput);
        toolbar.appendChild(App.el('div', { className: 'filter-pills', id: 'status-filters' }));
        gameCard.appendChild(toolbar);

        // Game content area
        gameCard.appendChild(App.el('div', { id: 'game-content' }));

        container.appendChild(gameCard);

        // Active overrides section
        container.appendChild(App.el('div', { className: 'card mt-2', id: 'active-overrides-card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', { className: 'card-title', textContent: 'Active overrides' }),
                App.el('a', { className: 'text-xs text-secondary', href: '#/overrides',
                    textContent: 'Manage overrides →' })
            ]),
            App.el('div', { id: 'active-overrides', className: 'mt-1' })
        ]));

        // Bottom row: kiosk health + recent automation activity. The recent-
        // activity feed is shown to every role (techs lean on it heavily),
        // while the kiosk tile is auto-hidden when no kiosks are configured.
        container.appendChild(App.el('div', { className: 'dashboard-footer-grid mt-2' }, [
            App.el('div', { id: 'dash-kiosk-wrap', className: 'card', style: { display: 'none' } }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        App.el('div', { className: 'card-title', textContent: 'Kiosks' }),
                        App.el('span', { className: 'badge badge-info', id: 'dash-kiosk-count', textContent: '0' })
                    ]),
                    App.el('a', { className: 'text-xs text-secondary', href: '#/kiosks', textContent: 'Open kiosks →' })
                ]),
                App.el('div', { id: 'dash-kiosk-body', className: 'card-body' })
            ]),
            App.el('div', { className: 'card', id: 'dash-activity-wrap' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        App.el('div', { className: 'card-title', textContent: 'Recent automation activity' }),
                        App.el('span', { className: 'badge badge-info', textContent: 'last 8' })
                    ]),
                    App.el('a', { className: 'text-xs text-secondary', href: '#/logs', textContent: 'Open action log →' })
                ]),
                App.el('div', { id: 'dash-activity-body', className: 'card-body' }, [App.loading()])
            ])
        ]));

        loadDashboard();
        loadCelebrations();
        scheduleNextPoll();

        // Live venue clock — updates the header pill every second while the
        // tab is visible. Cheap (no layout work, no fetches) and gives the
        // dashboard an unmistakable "this is live" feel.
        updateHeaderClock();
        var clockCleanup = App.createVisibilityAwareInterval(updateHeaderClock, 1000, {
            runImmediately: false, runOnVisible: true
        });

        // Repaint both hand-painted canvases on window resize so they stay
        // crisp at any container width. Debounced to avoid thrashing during
        // drag-resize.
        var repaintCanvases = function() {
            var canvas = document.getElementById('hero-spark');
            if (canvas) paintHeroSparkline(canvas, sparklineBuckets());
            var payoutCanvas = document.getElementById('payout-canvas');
            if (payoutCanvas && payoutData) paintPayoutBars(payoutCanvas, payoutData);
        };
        var resizeTimer = null;
        var onResize = function() {
            if (resizeTimer) clearTimeout(resizeTimer);
            resizeTimer = setTimeout(repaintCanvases, 120);
        };
        window.addEventListener('resize', onResize);

        // Both canvases read theme CSS variables at paint time — repaint on
        // dark/light toggle so they don't keep stale colors until next poll.
        var themeObserver = new MutationObserver(repaintCanvases);
        themeObserver.observe(document.documentElement, {
            attributes: true, attributeFilter: ['data-theme']
        });

        return function cleanup() {
            if (refreshIntervalCleanup) refreshIntervalCleanup();
            refreshIntervalCleanup = null;
            if (clockCleanup) clockCleanup();
            expiryTimers.forEach(function(t) { clearTimeout(t); });
            expiryTimers = [];
            transitionTimers.forEach(function(t) { clearTimeout(t); });
            transitionTimers = [];
            window.removeEventListener('resize', onResize);
            if (resizeTimer) clearTimeout(resizeTimer);
            themeObserver.disconnect();
            // Reset the hero state so a re-mount starts clean.
            heroPrev = {
                tickets_today: 0, plays_today: 0, tickets_hour: 0, plays_hour: 0,
                unique_cards_today: 0, active_now: 0, seenTxnIds: null
            };
            statPrev = {};
            lastLoadError = null;
        };
    }

    /**
     * Tick the venue-local clock pill in the page header. Format:
     * "3:47 PM · Sun May 3" — the date is included so multi-day shifts
     * (open-late venues) can quickly tell which calendar day the system
     * thinks it is.
     */
    function updateHeaderClock() {
        var el = document.getElementById('header-clock-text');
        if (!el) return;
        var now = new Date();
        try {
            var time = new Intl.DateTimeFormat('en-US', {
                timeZone: App.appTimezone, hour: 'numeric', minute: '2-digit', hour12: true
            }).format(now);
            var date = new Intl.DateTimeFormat('en-US', {
                timeZone: App.appTimezone, weekday: 'short', month: 'short', day: 'numeric'
            }).format(now);
            el.textContent = time + ' · ' + date;
        } catch (e) {
            el.textContent = now.toLocaleTimeString();
        }
    }

    /**
     * Update the "next scheduled action" pill based on the soonest upcoming
     * transition across all active groups. Hides the pill when nothing is
     * scheduled to fire today.
     */
    function updateNextActionPill(groups) {
        var pill = document.getElementById('header-next-action');
        var text = document.getElementById('header-next-action-text');
        if (!pill || !text) return;
        if (!groups || groups.length === 0) {
            pill.style.display = 'none';
            return;
        }

        // Find the soonest next_transition across all active groups.
        var soonest = null;
        var now = Date.now();
        for (var i = 0; i < groups.length; i++) {
            var g = groups[i];
            if (!g.is_active || !g.next_transition) continue;
            var ms = todayTimeToMs(g.next_transition.time);
            if (ms === null) continue;
            if (ms <= now) continue;
            if (!soonest || ms < soonest.ms) {
                soonest = { ms: ms, group: g, transition: g.next_transition };
            }
        }
        if (!soonest) {
            pill.style.display = 'none';
            return;
        }
        var verb = soonest.transition.action === 'pause' ? 'Pause' : 'Unpause';
        var when = App.formatTime(soonest.transition.time);
        var name = soonest.group.name;
        // Keep the pill text compact — the full detail lands in the title attr.
        text.textContent = verb + ' ' + name + ' at ' + when;
        pill.title = verb + ' "' + name + '" scheduled at ' + when + ' (' + App.appTimezone + ')';
        pill.style.display = '';
        pill.setAttribute('data-action', soonest.transition.action);
    }

    function switchView(view) {
        gameView = view;
        // Update toggle buttons
        var btns = document.querySelectorAll('#view-toggle .view-toggle-btn');
        btns.forEach(function(btn) {
            var isActive = btn.getAttribute('data-view') === view;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        gamePage = 1;
        renderGameView(allGames);
    }

    async function loadDashboard() {
        var gen = App.navGeneration();
        try {
            var canSeeSales = App.canAccess('analytics');
            var requests = [
                API.get('games'),
                // Never let one denied side-feed take down the whole
                // dashboard — badges just don't render without it.
                API.get('overrides').catch(function() { return {}; }),
                API.get('groups'),
                API.get('health').catch(function() { return {}; })
            ];
            if (canSeeSales) {
                requests.push(API.get('games/transactions/stats').catch(function() { return {}; }));
            }
            var results = await Promise.all(requests);
            // If user navigated away while we were loading, discard results
            if (App.navGeneration() !== gen) return;
            lastLoadError = null;

            var gamesData = results[0] || {};
            var overridesData = results[1] || {};
            var groupsData = results[2] || {};
            var healthData = results[3] || {};
            var statsData = canSeeSales ? (results[4] || {}) : {};
            ticketStats = statsData.stats || {};
            ticketTotals = statsData.totals || null;
            // Server-computed hour bins cover ALL of today's plays — the
            // feed cache is capped at 500 rows, so anything derived from it
            // (peak hour, sparkline, unique cards) goes wrong on busy days.
            hourlyToday = Array.isArray(statsData.hourly_today) ? statsData.hourly_today : null;
            hourlyRecent = Array.isArray(statsData.hourly_recent) ? statsData.hourly_recent : null;
            if (typeof statsData.payout_target_pct === 'number') {
                payoutTargetPct = statsData.payout_target_pct;
            }

            // Render security warnings if present
            var warningsEl = document.getElementById('security-warnings');
            if (warningsEl) {
                warningsEl.innerHTML = '';
                if (healthData.warnings && healthData.warnings.length > 0) {
                    healthData.warnings.forEach(function(msg) {
                        warningsEl.appendChild(App.el('div', {
                            className: 'alert alert-warning',
                            style: { marginBottom: '0.75rem' },
                            textContent: msg
                        }));
                    });
                }
            }

            allGames = gamesData.games || [];
            allGroups = groupsData.groups || [];
            var activeOverrides = overridesData.active || [];

            renderStats(allGames);
            lastActiveOverrides = activeOverrides;
            renderDashInsights(allGames, activeOverrides);
            // Its own ten-minute cadence, not this 30s poll — see the comment
            // above loadCelebrations().
            maybeRefreshCelebrations();
            if (canSeeSales) {
                renderHeroPulse();
                renderTicketSummary();
            }
            updateNextActionPill(allGroups);
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
                    ? 'Live view of every game and kiosk — updated ' + App.formatDatetime(gamesData.last_synced) + ' (' + App.appTimezone + ')'
                    : 'Live view of every game and kiosk — waiting for the first sync';
            }

            // Live feed + top-games widget — fire-and-forget; failure shouldn't
            // break the rest of the dashboard. Only loaded for roles that
            // can see sales data.
            if (canSeeSales) {
                loadFeed();
                loadTopGames();
                loadPayout();
                loadTicketWatch();
                loadYoy();
            }

            // Kiosk health snapshot + recent automation activity feed are
            // available to all roles. Errors are non-fatal — the dashboard
            // still functions if either widget can't be populated.
            loadKioskSnapshot();
            loadRecentActivity();
        } catch (err) {
            if (App.navGeneration() !== gen) return;
            // The widget loaders only run after a successful main load — on
            // failure, swap any body still showing its first-load spinner
            // for an honest inline message instead of spinning forever.
            ['yoy-body', 'dash-feed-body', 'dash-top-body', 'dash-topplays-body',
                'ticket-watch-body', 'dash-activity-body'].forEach(function(id) {
                var widgetBody = document.getElementById(id);
                if (widgetBody && widgetBody.querySelector('.loading-overlay')) {
                    widgetBody.innerHTML = '';
                    widgetBody.appendChild(App.el('p', { className: 'text-sm text-secondary',
                        textContent: 'Unavailable — will retry on the next refresh.' }));
                }
            });
            // Don't stack identical toasts while consecutive polls fail.
            if (err.message !== lastLoadError) {
                lastLoadError = err.message;
                App.toast(err.message, 'error');
            }
        }
    }

    var TOP_WINDOWS = [
        ['hour', 'Last hour'],
        ['today', 'Today'],
        ['week', 'Last 7 days'],
        ['all', 'All cached']
    ];

    function topWindowLabel(win) {
        for (var i = 0; i < TOP_WINDOWS.length; i++) {
            if (TOP_WINDOWS[i][0] === win) return TOP_WINDOWS[i][1];
        }
        return win;
    }

    function buildTopWindowSelector() {
        var sel = App.el('select', {
            className: 'form-input form-input-sm',
            'aria-label': 'Top games time window',
            onChange: function(e) {
                topWindow = e.target.value;
                loadTopGames();
            }
        });
        TOP_WINDOWS.forEach(function(opt) {
            var o = App.el('option', { value: opt[0], textContent: opt[1] });
            if (opt[0] === topWindow) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    async function loadTopGames() {
        // Keep the "Top by plays" header's window label in sync — the
        // selector lives in the "Top by tickets" card but scopes both.
        var windowLabel = document.getElementById('dash-topplays-window');
        if (windowLabel) windowLabel.textContent = topWindowLabel(topWindow);
        // Two compact leaderboards side by side, sharing the window selector.
        await Promise.all([
            renderTopList('dash-top-body', 'tickets'),
            renderTopList('dash-topplays-body', 'plays')
        ]);
    }

    // Render one leaderboard for the given metric ('tickets' | 'plays') into the
    // element with the given id. Both the "Top by tickets" and "Top by plays"
    // cards flow through here so they share one style. The spotlight number, bar
    // fill, and label are the chosen metric; the secondary line shows the other.
    async function renderTopList(bodyId, metric) {
        var body = document.getElementById(bodyId);
        if (!body) return;
        try {
            var data = await API.get('games/transactions/top?window=' + encodeURIComponent(topWindow)
                + '&sort=' + metric + '&limit=' + TOP_LIMIT);
            body.innerHTML = '';
            var rows = data.top || [];
            // A leaderboard row with zero of its own metric is noise — e.g.
            // rides showing "— TICKETS" in Top by tickets. Keep only games
            // that actually scored on the sorted metric.
            rows = rows.filter(function(r) {
                return metric === 'tickets' ? (parseFloat(r.sum_tickets) || 0) > 0 : (r.plays || 0) > 0;
            });
            if (rows.length === 0) {
                body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: metric === 'tickets' ? 'No tickets dispensed in this window.' : 'No plays in this window.' }));
                return;
            }
            var valOf = function(r) { return metric === 'plays' ? (r.plays || 0) : (r.sum_tickets || 0); };
            var maxVal = rows.reduce(function(m, r) { return Math.max(m, valOf(r)); }, 0) || 1;
            var medals = ['🥇', '🥈', '🥉'];

            var list = App.el('ol', { className: 'top-games-list top-games-list-modern' });
            rows.forEach(function(r, i) {
                var name = r.game_name || ('Game ' + r.game_id);
                var val = valOf(r);
                var hasMetric = val > 0;
                var pct = Math.max(4, Math.round((val / maxVal) * 100));

                var rankEl = i < 3
                    ? App.el('div', { className: 'top-games-rank top-games-rank-medal top-games-rank-' + (i + 1) }, [
                          App.el('span', { className: 'top-games-medal', textContent: medals[i] })
                      ])
                    : App.el('div', { className: 'top-games-rank', textContent: '#' + (i + 1) });

                var spotlightValue = hasMetric ? formatBigNumber(Math.round(val)) : '—';
                var spotlightLabel = metric === 'plays' ? (val === 1 ? 'play' : 'plays') : 'tickets';
                var spotlight = App.el('div', { className: 'top-games-spotlight' }, [
                    App.el('div', {
                        className: 'top-games-spotlight-value' + (hasMetric ? ' has-tickets' : ' no-tickets'),
                        textContent: spotlightValue
                    }),
                    App.el('div', { className: 'top-games-spotlight-label', textContent: spotlightLabel })
                ]);

                var otherLine = metric === 'plays'
                    ? ((r.sum_tickets || 0) > 0 ? Math.round(r.sum_tickets).toLocaleString() + ' tickets' : 'no tickets')
                    : ((r.plays || 0).toLocaleString() + ((r.plays || 0) === 1 ? ' play' : ' plays'));

                var item = App.el('li', { className: 'top-games-item top-games-item-modern' }, [
                    rankEl,
                    App.el('div', { className: 'top-games-body' }, [
                        App.el('div', { className: 'top-games-name', textContent: name, title: name }),
                        App.el('div', { className: 'top-games-bar' }, [
                            App.el('div', { className: 'top-games-bar-fill' + (hasMetric ? ' has-tickets' : ''),
                                style: { width: pct + '%' } })
                        ]),
                        App.el('div', { className: 'top-games-meta text-xs text-secondary', textContent: otherLine })
                    ]),
                    spotlight
                ]);
                if (r.game_id) {
                    App.makeCardLink(item, '#/games?game=' + encodeURIComponent(r.game_id),
                        { title: 'Open game detail for ' + name });
                }
                list.appendChild(item);
            });
            body.appendChild(list);
        } catch (err) {
            // Keep the last good leaderboard on a transient poll failure —
            // only show the message when nothing has rendered yet.
            if (body.querySelector('.top-games-list')) return;
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Top games unavailable.' }));
        }
    }

    /**
     * Compact kiosk health snapshot — counts by status with a single inline
     * progress bar. Hides the entire card when no kiosks are configured so
     * venues that don't run kiosks don't see an empty placeholder.
     */
    async function loadKioskSnapshot() {
        var wrap = document.getElementById('dash-kiosk-wrap');
        var body = document.getElementById('dash-kiosk-body');
        var countBadge = document.getElementById('dash-kiosk-count');
        if (!wrap || !body) return;
        try {
            var data = await API.get('kiosks');
            var kiosks = data.kiosks || [];
            if (kiosks.length === 0) {
                wrap.style.display = 'none';
                return;
            }
            wrap.style.display = '';
            if (countBadge) countBadge.textContent = kiosks.length + ' kiosk' + (kiosks.length === 1 ? '' : 's');

            var counts = { enabled: 0, paused: 0, outOfService: 0, unknown: 0 };
            kiosks.forEach(function(k) {
                var s = k.operationStatus;
                if (s === 'enabled') counts.enabled++;
                else if (s === 'paused') counts.paused++;
                else if (s === 'outOfService') counts.outOfService++;
                else counts.unknown++;
            });

            var total = kiosks.length;
            var pct = function(n) { return total > 0 ? (n / total * 100) + '%' : '0%'; };

            body.innerHTML = '';
            body.appendChild(App.el('div', { className: 'kiosk-snapshot' }, [
                App.el('div', { className: 'kiosk-snapshot-row' }, [
                    App.el('div', { className: 'kiosk-snapshot-stat' }, [
                        App.el('span', { className: 'status-dot status-dot-enabled' }),
                        App.el('span', { className: 'kiosk-snapshot-num', textContent: String(counts.enabled) }),
                        App.el('span', { className: 'kiosk-snapshot-label', textContent: 'enabled' })
                    ]),
                    App.el('div', { className: 'kiosk-snapshot-stat' }, [
                        App.el('span', { className: 'status-dot status-dot-paused' }),
                        App.el('span', { className: 'kiosk-snapshot-num', textContent: String(counts.paused) }),
                        App.el('span', { className: 'kiosk-snapshot-label', textContent: 'paused' })
                    ]),
                    counts.outOfService > 0 ? App.el('div', { className: 'kiosk-snapshot-stat' }, [
                        App.el('span', { className: 'status-dot status-dot-paused', style: { background: 'var(--danger)' } }),
                        App.el('span', { className: 'kiosk-snapshot-num', textContent: String(counts.outOfService) }),
                        App.el('span', { className: 'kiosk-snapshot-label', textContent: 'OOS' })
                    ]) : null,
                    counts.unknown > 0 ? App.el('div', { className: 'kiosk-snapshot-stat' }, [
                        App.el('span', { className: 'status-dot', style: { background: 'var(--text-muted)' } }),
                        App.el('span', { className: 'kiosk-snapshot-num', textContent: String(counts.unknown) }),
                        App.el('span', { className: 'kiosk-snapshot-label', textContent: 'unreported' })
                    ]) : null
                ].filter(Boolean)),
                App.el('div', { className: 'progress-bar', style: { marginTop: '0.6rem' } }, [
                    App.el('div', { className: 'progress-fill-enabled', style: { width: pct(counts.enabled) } }),
                    App.el('div', { className: 'progress-fill-paused', style: { width: pct(counts.paused) } }),
                    App.el('div', { className: 'progress-fill-oos', style: { width: pct(counts.outOfService) } })
                ])
            ]));
        } catch (err) {
            // Endpoint may not be available on legacy installs / tech without
            // CenterEdge config — hide rather than show an error. But if a
            // snapshot already rendered, keep it instead of blinking the
            // whole card out on one flaky poll.
            if (!body.querySelector('.kiosk-snapshot')) {
                wrap.style.display = 'none';
            }
        }
    }

    /**
     * Most recent automation actions across all sources (manual, schedule,
     * cron, override, watchdog). Compact 8-row feed with relative time, a
     * source badge, the verb, and the affected group. Visible to all roles.
     */
    var ACTIVITY_SOURCE_LABEL = {
        manual:           'Manual',
        schedule:         'Schedule',
        override:         'Override',
        cron:             'Cron',
        watchdog:         'Watchdog',
        expired_override: 'Override end'
    };

    async function loadRecentActivity() {
        var body = document.getElementById('dash-activity-body');
        if (!body) return;
        try {
            var data = await API.get('logs?per_page=8&page=1');
            var logs = (data && data.logs) || [];
            body.innerHTML = '';

            if (logs.length === 0) {
                body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: 'No automation actions logged yet.' }));
                return;
            }

            var ul = App.el('ul', { className: 'plain-list activity-feed' });
            logs.forEach(function(l) {
                var srcLabel = ACTIVITY_SOURCE_LABEL[l.source] || (l.source || '—');
                var ok = !!parseInt(l.success, 10);
                var actionLabel = (l.action || '').replace(/_/g, ' ');
                if (actionLabel === 'pause') actionLabel = 'Paused';
                else if (actionLabel === 'unpause') actionLabel = 'Unpaused';
                else if (actionLabel === 'plan day') actionLabel = 'Planned day';
                else if (actionLabel === 'execute action') actionLabel = 'Executed action';
                else if (actionLabel) actionLabel = actionLabel.charAt(0).toUpperCase() + actionLabel.slice(1);

                var nameLine = l.group_name || (l.pause_group_id ? 'Group ' + l.pause_group_id : '—');
                var when = l.timestamp ? App.formatRelative(l.timestamp) : '';

                ul.appendChild(App.el('li', { className: 'activity-row' }, [
                    App.el('span', {
                        className: 'activity-badge activity-badge-' + (l.source || 'default') +
                            (ok ? '' : ' activity-badge-fail'),
                        textContent: srcLabel,
                        title: ok ? 'Success' : 'Failed'
                    }),
                    App.el('div', { className: 'activity-body' }, [
                        App.el('div', { className: 'activity-line' }, [
                            App.el('span', { className: 'activity-action', textContent: actionLabel || '—' }),
                            App.el('span', { className: 'activity-target text-secondary',
                                textContent: ' · ' + nameLine })
                        ]),
                        App.el('div', { className: 'activity-time text-xs text-muted',
                            textContent: when, title: l.timestamp ? App.formatDatetime(l.timestamp) : '' })
                    ]),
                    ok ? App.el('span', { className: 'activity-ok', 'aria-label': 'success', textContent: '✓' })
                       : App.el('span', { className: 'activity-fail', 'aria-label': 'failed', textContent: '✕' })
                ]));
            });
            body.appendChild(ul);
        } catch (err) {
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'Activity feed unavailable.' }));
        }
    }

    async function loadFeed() {
        var body = document.getElementById('dash-feed-body');
        if (!body) return;
        try {
            var data = await API.get('games/transactions/recent?limit=' + FEED_LIMIT);
            allTransactions = data.transactions || [];
            feedMeta = {
                last_poll_at: data.last_poll_at || null,
                total_cached: typeof data.total_cached === 'number' ? data.total_cached : null
            };
            renderFeed();
            // Peak-hour tile and the hero sparkline both derive from the
            // feed cache, which lands after the first render of the KPI
            // strip — repaint them now so a fresh page load doesn't show
            // "awaiting first play" with a day of plays on the board.
            renderTicketSummary();
            var heroCanvas = document.getElementById('hero-spark');
            if (heroCanvas) paintHeroSparkline(heroCanvas, sparklineBuckets());
        } catch (err) {
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Feed unavailable: ' + err.message }));
            var pager = document.getElementById('dash-feed-pagination');
            if (pager) pager.innerHTML = '';
        }
    }

    function getFilteredTransactions() {
        if (!feedSearch) return allTransactions;
        return allTransactions.filter(function(t) {
            return App.matchesSearch(t, feedSearch, ['game_name', 'game_id', 'card_number']);
        });
    }

    function renderFeed() {
        var body = document.getElementById('dash-feed-body');
        var meta = document.getElementById('dash-feed-meta');
        var pager = document.getElementById('dash-feed-pagination');
        if (!body) return;

        var filtered = getFilteredTransactions();
        feedPaging.totalItems = filtered.length;
        var page = App.paginate(filtered, feedPaging.page, feedPaging.pageSize);
        feedPaging.page = page.page;

        // Compute today's totals from the visible (filtered) feed for the
        // tiny header badge so the operator sees what's "in scope".
        var matchTickets = 0;
        for (var k = 0; k < filtered.length; k++) {
            matchTickets += parseFloat(filtered[k].redemption_tickets) || 0;
        }

        if (meta) {
            meta.innerHTML = '';
            if (feedMeta && feedMeta.last_poll_at) {
                meta.appendChild(App.el('span', { className: 'feed-meta-pulse-dot' }));
                meta.appendChild(App.el('span', { textContent: 'Polled ' + App.formatRelative(feedMeta.last_poll_at) }));
            }
            if (feedMeta && feedMeta.total_cached !== null) {
                meta.appendChild(App.el('span', { className: 'feed-meta-sep', textContent: ' · ' }));
                meta.appendChild(App.el('span', { textContent: feedMeta.total_cached.toLocaleString() + ' cached' }));
            }
            if (feedSearch) {
                meta.appendChild(App.el('span', { className: 'feed-meta-sep', textContent: ' · ' }));
                meta.appendChild(App.el('span', { textContent: filtered.length.toLocaleString() + ' match' + (filtered.length === 1 ? '' : 'es') }));
            }
        }

        // The list scrolls internally — remember how far the operator had
        // scrolled so a poll-driven rebuild doesn't snatch it back to the
        // top. Restore only when page/search/size are unchanged: a deliberate
        // page turn or new search should still start at the top.
        var prevList = body.querySelector('.feed-list-modern');
        var prevScrollTop = prevList ? prevList.scrollTop : 0;
        var renderKey = page.page + '|' + feedSearch + '|' + feedPaging.pageSize;
        var restoreScroll = prevScrollTop > 0 && renderKey === feedLastRenderKey;
        feedLastRenderKey = renderKey;

        body.innerHTML = '';

        if (allTransactions.length === 0) {
            body.appendChild(App.emptyState('▢',
                'No plays cached yet. The watchdog cron polls every minute; click "Refresh" to force a poll now.'));
            if (pager) pager.innerHTML = '';
            return;
        }

        if (filtered.length === 0) {
            body.appendChild(App.el('p', { className: 'text-sm text-secondary', style: { padding: '1rem 0' },
                textContent: 'No plays match "' + feedSearch + '".' }));
            if (pager) pager.innerHTML = '';
            return;
        }

        // Identify "fresh" rows — transaction IDs we hadn't seen on the
        // previous render. They get a brief slide-in + glow so the operator
        // sees the live pulse of the venue arriving.
        var seen = heroPrev.seenTxnIds; // null on the first paint
        var freshIds = {};
        if (seen) {
            page.items.forEach(function(t) {
                if (t.transaction_id && !seen.has(t.transaction_id)) {
                    freshIds[t.transaction_id] = true;
                }
            });
        }

        var ul = App.el('ul', { className: 'plain-list feed-list-modern' });
        page.items.forEach(function(t) { ul.appendChild(buildFeedRow(t, !!freshIds[t.transaction_id])); });
        body.appendChild(ul);
        if (restoreScroll) ul.scrollTop = prevScrollTop;

        // Re-build the seen-set from the entire (unpaged) cache so a row
        // doesn't re-flash every time it scrolls onto a different page.
        var nextSeen = new Set();
        allTransactions.forEach(function(t) {
            if (t.transaction_id) nextSeen.add(t.transaction_id);
        });
        heroPrev.seenTxnIds = nextSeen;

        if (pager) {
            pager.innerHTML = '';
            pager.appendChild(App.buildPaginationBar(feedPaging, function() { renderFeed(); }, {
                pageSizeOptions: [5, 10, 25, 50],
                itemLabel: 'plays',
                showPageNumbers: true
            }));
        }
    }

    function buildFeedRow(t, isFresh) {
        var time = App.formatDatetime(t.transaction_time);
        var name = t.game_name || ('Game ' + t.game_id);
        var card = t.no_card ? 'no card' : (t.card_number || '-');

        var meta = [];
        if (t.used_time_play) meta.push('time play');
        if (t.used_play_privilege) meta.push('privilege');
        var amt = (parseFloat(t.regular_points) || 0) + (parseFloat(t.bonus_points) || 0);
        var tickets = parseFloat(t.redemption_tickets) || 0;
        if (amt) meta.push(formatPoints(amt) + ' pts');
        // Direct credit-card payment at the reader. The server blanks these
        // fields for roles without view_revenue, so rendering is enough.
        if (t.cc_card_type || t.cc_last4) {
            meta.push((t.cc_card_type || 'card') + (t.cc_last4 ? ' •' + t.cc_last4 : ''));
        } else if ((parseFloat(t.credit_card_amount) || 0) > 0) {
            meta.push('credit card');
        }

        // Tiered ticket-amount class for visual emphasis on big drops.
        // 100+ tix = jackpot tier (gold + glow); 25+ = high; <25 = base.
        var ticketTier = '';
        if (tickets >= 100) ticketTier = 'feed-row-tickets-jackpot';
        else if (tickets >= 25) ticketTier = 'feed-row-tickets-high';
        else if (tickets > 0) ticketTier = 'feed-row-tickets-base';

        // Build secondary line. If a card number is present, expose a small
        // "Lookup" affordance so floor staff can jump straight to the card
        // page with the number pre-filled, without having to retype it.
        var secondary = App.el('div', { className: 'text-sm text-secondary feed-row-meta' });
        secondary.appendChild(App.el('span', { textContent: 'Card ' }));
        if (!t.no_card && t.card_number) {
            secondary.appendChild(App.el('a', {
                href: '#/cards?number=' + encodeURIComponent(t.card_number),
                className: 'feed-row-card-link',
                textContent: t.card_number,
                title: 'Look up card ' + t.card_number,
                onClick: function(e) { e.stopPropagation(); }
            }));
        } else {
            secondary.appendChild(App.el('span', { textContent: card }));
        }
        if (meta.length) {
            secondary.appendChild(App.el('span', { textContent: '  •  ' + meta.join(' • ') }));
        }

        var rowChildren = [
            App.el('div', { className: 'feed-row-time text-sm text-secondary', textContent: time }),
            App.el('div', { className: 'feed-row-main' }, [
                App.el('div', { className: 'plain-list-title', textContent: name }),
                secondary
            ])
        ];

        // Right-side ticket badge — visible only when the play actually
        // dispensed tickets. This is where the eye gets pulled on a busy feed.
        if (tickets > 0) {
            rowChildren.push(App.el('div', { className: 'feed-row-tickets ' + ticketTier }, [
                App.el('span', { className: 'feed-row-tickets-num', textContent: '+' + formatBigNumber(Math.round(tickets)) }),
                App.el('span', { className: 'feed-row-tickets-label', textContent: 'tix' })
            ]));
        }

        var rowCls = 'plain-list-item feed-row feed-row-modern';
        if (isFresh) rowCls += ' feed-row-fresh';
        if (ticketTier === 'feed-row-tickets-jackpot') rowCls += ' feed-row-jackpot';

        var row = App.el('li', { className: rowCls }, rowChildren);
        if (t.game_id) {
            App.makeCardLink(row, '#/games?game=' + encodeURIComponent(t.game_id),
                { title: 'Open game detail for ' + name });
        }
        return row;
    }

    /* ============================================================
     * Hero "pulse" banner
     *
     * Layout (one card, full-width):
     *   [ TICKETS DISPENSED TODAY ]   [ live mini stats ]
     *   [ ##### giant animated #####]
     *   [ trend pill | sparkline 12h | hot game ]
     *
     * Visual goals:
     *   - Make today's ticket count the unmissable focal point of the dashboard
     *   - Communicate "is the venue alive right now?" via the LIVE pill +
     *     hourly sparkline + active-games count
     *   - Smoothly count up between polls so the eye is drawn to changes
     * ============================================================ */
    function buildHeroSkeleton() {
        // Three-column "stat chip" cluster shown to the right of the hero counter
        var statChip = function(key, label) {
            return App.el('div', { className: 'hero-chip', 'data-chip': key }, [
                App.el('div', { className: 'hero-chip-label', textContent: label }),
                App.el('div', { className: 'hero-chip-value', 'data-role': 'value', textContent: '—' }),
                App.el('div', { className: 'hero-chip-detail', 'data-role': 'detail', textContent: '' })
            ]);
        };

        return App.el('div', { className: 'hero-pulse mt-2', id: 'hero-pulse' }, [
            // Animated background glow layer
            App.el('div', { className: 'hero-pulse-glow', 'aria-hidden': 'true' }),

            // Top row: live indicator + section label
            App.el('div', { className: 'hero-pulse-toprow' }, [
                App.el('div', { className: 'hero-live-pill', id: 'hero-live-pill' }, [
                    App.el('span', { className: 'hero-live-dot' }),
                    App.el('span', { className: 'hero-live-text', textContent: 'LIVE' })
                ]),
                App.el('div', { className: 'hero-pulse-eyebrow', textContent: 'Tickets dispensed today' })
            ]),

            // Main row: giant counter on the left, sparkline + stat chips on the right
            App.el('div', { className: 'hero-pulse-mainrow' }, [
                App.el('div', { className: 'hero-pulse-counter' }, [
                    App.el('div', { className: 'hero-pulse-value', id: 'hero-tickets-value', textContent: '0' }),
                    App.el('div', { className: 'hero-pulse-deltarow' }, [
                        App.el('span', { className: 'hero-pulse-delta', id: 'hero-tickets-delta', textContent: '' }),
                        App.el('span', { className: 'hero-pulse-hot', id: 'hero-hot-game', textContent: '' })
                    ])
                ]),

                App.el('div', { className: 'hero-pulse-side' }, [
                    App.el('div', { className: 'hero-pulse-spark' }, [
                        App.el('div', { className: 'hero-pulse-spark-label' }, [
                            App.el('span', { textContent: 'Hourly heat' })
                        ]),
                        App.el('canvas', {
                            id: 'hero-spark',
                            className: 'hero-pulse-spark-canvas',
                            'aria-label': 'Plays per hour for the last 12 hours'
                        }),
                        // Time axis under the sparkline: left end = 12h ago,
                        // right end = the current hour. Two separate spans at
                        // opposite ends so they can't collapse into one
                        // confusing "12h ago now" run.
                        App.el('div', { className: 'hero-pulse-spark-axis' }, [
                            App.el('span', { textContent: '12h ago' }),
                            App.el('span', { textContent: 'now' })
                        ])
                    ]),
                    App.el('div', { className: 'hero-pulse-chips' }, [
                        statChip('plays', 'Plays today'),
                        statChip('avg', 'Avg / play'),
                        statChip('active', 'Active now')
                    ])
                ])
            ])
        ]);
    }

    /**
     * Smoothly animate `el.textContent` from its current value to `target`.
     * Uses requestAnimationFrame and an ease-out curve so big jumps decelerate
     * naturally rather than stopping abruptly.
     */
    function animateNumber(el, target, opts) {
        if (!el) return;
        opts = opts || {};
        var duration = opts.duration || 750;
        var format = opts.format || function(n) { return Math.round(n).toLocaleString(); };
        var current = parseFloat((el.getAttribute('data-anim-value') || '0')) || 0;
        if (target === current) {
            el.textContent = format(target);
            return;
        }
        // Cancel any in-flight animation on this element.
        if (el.__animFrame) cancelAnimationFrame(el.__animFrame);

        var startTs = null;
        var delta = target - current;
        function tick(ts) {
            if (startTs === null) startTs = ts;
            var elapsed = ts - startTs;
            var t = Math.min(1, elapsed / duration);
            // ease-out cubic
            var eased = 1 - Math.pow(1 - t, 3);
            var v = current + delta * eased;
            el.textContent = format(v);
            if (t < 1) {
                el.__animFrame = requestAnimationFrame(tick);
            } else {
                el.setAttribute('data-anim-value', String(target));
                el.__animFrame = null;
            }
        }
        // For users who prefer reduced motion, jump straight to the value.
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            el.textContent = format(target);
            el.setAttribute('data-anim-value', String(target));
            return;
        }
        el.__animFrame = requestAnimationFrame(tick);
    }

    /**
     * Buckets for the hero sparkline: prefer the server's 12-bin histogram
     * (covers EVERY play in the last 12 clock hours), falling back to the
     * legacy feed-cache derivation — which is capped at 500 rows and goes
     * flat/misleading on busy days — only while talking to a stale backend.
     */
    function sparklineBuckets() {
        if (hourlyRecent && hourlyRecent.length) {
            return hourlyRecent.map(function(b) { return b.plays || 0; });
        }
        return buildHourlyBuckets(12);
    }

    /**
     * Build a 12-bin array of plays-per-hour for the most recent 12 wall-clock
     * hours in the venue's timezone, using `allTransactions` (the cached recent
     * feed) as the data source. Bin [0] is 12 hours ago, bin [11] is the
     * current hour.
     *
     * Uses the same Intl-based timezone offset trick as todayTimeToMs() so the
     * bins line up with the venue's wall clock — not the browser's local time.
     */
    function buildHourlyBuckets(hours) {
        hours = hours || 12;
        var buckets = new Array(hours).fill(0);
        if (!allTransactions || allTransactions.length === 0) return buckets;

        var now = new Date();
        // Round "now" down to the start of the current hour, in app TZ.
        // We use the appTimezone offset relative to UTC for the rounding.
        var fmt = new Intl.DateTimeFormat('en-US', {
            timeZone: App.appTimezone, hour12: false,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit'
        });
        var parts = fmt.formatToParts(now);
        var p = {};
        parts.forEach(function(x) { p[x.type] = x.value; });
        var nowAppMs = Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute);
        var offsetMs = nowAppMs - now.getTime();
        var hourMs = 3600000;
        // Start of the current app-hour, expressed as a UTC ms timestamp:
        var nowHourStart = Math.floor((now.getTime() + offsetMs) / hourMs) * hourMs - offsetMs;
        var earliest = nowHourStart - (hours - 1) * hourMs;

        for (var i = 0; i < allTransactions.length; i++) {
            var t = allTransactions[i];
            var d = App.toUtcDate(t.transaction_time);
            if (!d) continue;
            var ms = d.getTime();
            if (ms < earliest) break;       // feed is DESC — stop early
            if (ms > nowHourStart + hourMs) continue; // future-dated weirdness, skip
            var idx = Math.floor((ms - earliest) / hourMs);
            if (idx >= 0 && idx < hours) buckets[idx] += 1;
        }
        return buckets;
    }

    /**
     * Paint a 12-hour sparkline (line + area + dots) onto the hero canvas.
     * Honors device pixel ratio so the line stays crisp on retina displays.
     */
    /**
     * Fetch the month-to-date / year-to-date year-over-year actuals and render
     * the comparison card. Fire-and-forget from the poll loop — a failed fetch
     * leaves whatever was already rendered in place.
     */
    async function loadYoy() {
        var body = document.getElementById('yoy-body');
        if (!body) return;
        var data;
        try {
            data = await API.get('analytics/yoy');
        } catch (err) {
            if (!body.querySelector('.yoy-grid')) {
                body.innerHTML = '';
                body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: 'Year-over-year comparison unavailable.' }));
            }
            return;
        }
        body = document.getElementById('yoy-body');
        if (!body) return; // navigated away mid-fetch
        App.renderYoy(body, data);
    }

    /**
     * Fetch the payout ratios and repaint the gauge. Fire-and-forget from
     * the poll loop — a failed fetch leaves the previous paint in place.
     */
    async function loadPayout() {
        try {
            var data = await API.get('games/transactions/payout');
            if (!document.getElementById('payout-canvas')) return; // navigated away
            payoutData = data;
            renderPayout();
        } catch (err) {
            // Non-fatal: the rest of the dashboard works without the gauge —
            // but say so rather than leaving a silent empty card.
            var cap = document.getElementById('payout-caption');
            if (cap && !payoutData) cap.textContent = 'Payout data unavailable';
        }
    }

    /**
     * Fetch today's top ticket earners + farming signals and render the
     * Ticket Watch card. Fire-and-forget from the poll loop.
     */
    async function loadTicketWatch() {
        var body = document.getElementById('ticket-watch-body');
        if (!body) return;
        var data;
        try {
            data = await API.get('games/transactions/ticket-watch');
        } catch (err) {
            // Keep a previous render if we have one; otherwise replace the
            // initial spinner with an honest message instead of spinning
            // forever.
            if (!body.querySelector('table')) {
                body.innerHTML = '';
                body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                    textContent: 'Ticket watch unavailable.' }));
            }
            return;
        }
        body = document.getElementById('ticket-watch-body');
        if (!body) return; // navigated away mid-fetch
        body.innerHTML = '';

        var cards = (data && data.cards) || [];
        if (cards.length === 0) {
            body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'No ticket-earning cards yet today.' }));
            return;
        }

        var FLAG_LABEL = {
            volume: 'high volume',
            concentration: 'one game',
            hot_ratio: 'above game avg'
        };

        var scroll = App.el('div', { className: 'table-scroll-container' });
        // Same 'table' class as the game-status table so both dashboard
        // tables share sticky headers + row styling in the scroll container.
        var table = App.el('table', { className: 'table' }, [
            App.el('thead', {}, [
                App.el('tr', {}, [
                    App.el('th', { textContent: 'Card' }),
                    App.el('th', { className: 'text-right', textContent: 'Tickets' }),
                    App.el('th', { className: 'text-right', textContent: 'Plays' }),
                    App.el('th', { className: 'text-right', textContent: 'Tix/Play' }),
                    App.el('th', { textContent: 'Top game' }),
                    App.el('th', { className: 'text-right', textContent: 'vs game avg' }),
                    App.el('th', { textContent: 'Signals' })
                ])
            ])
        ]);
        var tbody = App.el('tbody', {});
        cards.forEach(function(c) {
            var cardCell = App.el('td', { 'data-sort': c.card_number }, [
                c.watch ? App.el('span', { textContent: '⚠ ', title: 'Two or more farming signals' }) : null,
                App.el('a', {
                    href: '#/cards?number=' + encodeURIComponent(c.card_number),
                    className: 'feed-row-card-link' + (c.watch ? ' text-danger' : ''),
                    textContent: c.card_number,
                    title: 'Look up card ' + c.card_number
                })
            ].filter(Boolean));

            var topGameTxt = c.top_game_name
                ? c.top_game_name + ' (' + c.top_game_share + '% of plays)'
                : '—';

            var signals = (c.flags || []).map(function(f) { return FLAG_LABEL[f] || f; }).join(' · ') || '—';

            var row = App.el('tr', {}, [
                cardCell,
                App.el('td', { className: 'text-right num-cell', 'data-sort': String(c.tickets),
                    textContent: Math.round(c.tickets).toLocaleString() }),
                App.el('td', { className: 'text-right num-cell', textContent: String(c.plays) }),
                App.el('td', { className: 'text-right num-cell', textContent: String(c.tickets_per_play) }),
                App.el('td', { className: 'text-sm', textContent: topGameTxt,
                    'data-sort': c.top_game_name || '',
                    style: { maxWidth: '220px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
                    title: topGameTxt }),
                App.el('td', { className: 'text-right num-cell' + (c.ratio_multiplier >= 1.5 ? ' text-danger' : ''),
                    'data-sort': String(c.ratio_multiplier),
                    textContent: c.ratio_multiplier + '×',
                    title: 'This card’s tickets-per-play on its top game vs everyone else’s average on that game today' }),
                App.el('td', { className: 'text-sm ' + (c.watch ? 'text-danger' : 'text-secondary'), textContent: signals })
            ]);
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        scroll.appendChild(table);
        body.appendChild(scroll);
        // Small fully-rendered top-10 — headers sort in place; the server's
        // tickets-DESC arrival order stays the default.
        App.enhanceTableSort(table, { defaultSort: { index: 1, dir: 'desc' } });

        var subtitle = document.getElementById('ticket-watch-subtitle');
        if (subtitle && data.min_tickets) {
            subtitle.textContent = 'Top ticket earners today — ⚠ rows trip two or more farming signals'
                + ' (volume threshold ' + Math.round(data.min_tickets).toLocaleString() + ' tix)';
        }
    }

    var PAYOUT_WINDOWS = [
        ['hour',  'Hour'],
        ['today', 'Today'],
        ['week',  'Week'],
        ['month', 'Month'],
        ['all',   'All time']
    ];

    function renderPayout() {
        if (!payoutData) return;
        var canvas = document.getElementById('payout-canvas');
        var caption = document.getElementById('payout-caption');
        if (!canvas) return;

        var target = payoutData.target_pct || 33;
        if (caption) {
            var todayWin = (payoutData.windows || {}).today || {};
            var todayTxt = todayWin.ratio_pct === null || todayWin.ratio_pct === undefined
                ? 'no redemption point-plays yet today'
                : 'today ' + todayWin.ratio_pct + '%';
            caption.textContent = todayTxt + ' · target ≤ ' + target + '%';
        }
        var subtitle = document.getElementById('payout-subtitle');
        if (subtitle && typeof payoutData.redemption_games === 'number') {
            subtitle.textContent = 'Tickets dispensed per 100 points played on redemption games ('
                + payoutData.redemption_games + ' identified)';
        }
        paintPayoutBars(canvas, payoutData);
    }

    /**
     * Hand-painted bar gauge (same approach as the hero sparkline — no
     * chart library): one bar per window, green at/below the target payout,
     * red above it, with a dashed line marking the target itself. Windows
     * with zero points played render as an empty slot with an em-dash.
     */
    function paintPayoutBars(canvas, data) {
        var dpr = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        var W = Math.max(120, Math.floor(rect.width));
        var H = Math.max(80, Math.floor(rect.height));
        canvas.width = W * dpr;
        canvas.height = H * dpr;
        var ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, W, H);

        var styles = getComputedStyle(document.documentElement);
        var okColor     = (styles.getPropertyValue('--success') || '#3dd68c').trim();
        var overColor   = (styles.getPropertyValue('--danger') || '#e5534b').trim();
        var textColor   = (styles.getPropertyValue('--text-secondary') || '#8a93ab').trim();
        var borderColor = (styles.getPropertyValue('--border') || '#1e2638').trim();

        var target = data.target_pct || 33;
        var wins = data.windows || {};
        var ratios = PAYOUT_WINDOWS.map(function(w) {
            var win = wins[w[0]] || {};
            return (win.ratio_pct === null || win.ratio_pct === undefined) ? null : win.ratio_pct;
        });

        // Scale so the target line always sits inside the plot, even when
        // every ratio is far below (or above) it.
        var maxRatio = target;
        ratios.forEach(function(r) { if (r !== null && r > maxRatio) maxRatio = r; });
        var scaleMax = maxRatio * 1.25;

        var padTop = 24;    // room for % labels above bars
        var padBottom = 18; // room for window labels
        var plotH = H - padTop - padBottom;
        var n = PAYOUT_WINDOWS.length;
        var slotW = W / n;
        var barW = Math.min(56, slotW * 0.5);

        var yFor = function(pct) { return padTop + plotH - (pct / scaleMax) * plotH; };

        // Baseline
        ctx.strokeStyle = borderColor;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, padTop + plotH + 0.5);
        ctx.lineTo(W, padTop + plotH + 0.5);
        ctx.stroke();

        // Bars + labels
        ctx.font = '11px system-ui, sans-serif';
        ctx.textAlign = 'center';
        PAYOUT_WINDOWS.forEach(function(w, i) {
            var cx = slotW * i + slotW / 2;
            var r = ratios[i];

            ctx.fillStyle = textColor;
            ctx.fillText(w[1], cx, H - 5);

            if (r === null) {
                ctx.fillText('—', cx, padTop + plotH - 6);
                return;
            }

            var y = yFor(Math.max(0, r));
            var barH = Math.max(2, padTop + plotH - y);
            var color = r <= target ? okColor : overColor;
            ctx.fillStyle = color;
            var radius = Math.min(4, barW / 2, barH);
            var x0 = cx - barW / 2;
            // Rounded-top bar
            ctx.beginPath();
            ctx.moveTo(x0, padTop + plotH);
            ctx.lineTo(x0, y + radius);
            ctx.arcTo(x0, y, x0 + radius, y, radius);
            ctx.lineTo(x0 + barW - radius, y);
            ctx.arcTo(x0 + barW, y, x0 + barW, y + radius, radius);
            ctx.lineTo(x0 + barW, padTop + plotH);
            ctx.closePath();
            ctx.fill();

            ctx.fillStyle = color;
            // Clamp so a full-height bar's label never clips the card edge.
            ctx.fillText(r + '%', cx, Math.max(11, y - 5));
        });

        // Dashed target line, labeled at the right edge.
        var ty = yFor(target);
        ctx.strokeStyle = textColor;
        ctx.setLineDash([4, 4]);
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, ty + 0.5);
        ctx.lineTo(W, ty + 0.5);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.textAlign = 'right';
        ctx.fillStyle = textColor;
        ctx.fillText(target + '%', W - 4, ty - 4);

        // Expose the per-window percentages to non-visual users.
        canvas.setAttribute('aria-label', 'Ticket payout by window: '
            + PAYOUT_WINDOWS.map(function(w, i) {
                return w[1] + ' ' + (ratios[i] === null ? 'n/a' : ratios[i] + '%');
            }).join(', ')
            + ' — target ' + target + '% or less');
    }

    function paintHeroSparkline(canvas, buckets) {
        if (!canvas) return;
        var dpr = window.devicePixelRatio || 1;
        // Match the on-screen rendered size of the canvas before scaling.
        var rect = canvas.getBoundingClientRect();
        var W = Math.max(60, Math.floor(rect.width));
        var H = Math.max(30, Math.floor(rect.height));
        canvas.width = W * dpr;
        canvas.height = H * dpr;
        var ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, W, H);

        var n = buckets.length;
        if (n === 0) return;
        var max = Math.max.apply(null, buckets);
        if (max === 0) max = 1;
        var pad = 4;
        var stepX = (W - pad * 2) / Math.max(1, n - 1);
        var pts = buckets.map(function(v, i) {
            var x = pad + stepX * i;
            var y = H - pad - ((v / max) * (H - pad * 2));
            return [x, y];
        });

        // Pull theme-driven colors so the spark matches dark/light mode.
        var styles = getComputedStyle(document.documentElement);
        var ticketsColor = (styles.getPropertyValue('--tickets') || '#f6c84c').trim();
        var trackColor = (styles.getPropertyValue('--tickets-track') || 'rgba(246,200,76,0.08)').trim();

        // Filled area under the line
        var grad = ctx.createLinearGradient(0, 0, 0, H);
        grad.addColorStop(0, ticketsColor + 'cc');
        grad.addColorStop(1, ticketsColor + '00');
        ctx.beginPath();
        ctx.moveTo(pts[0][0], H - pad);
        pts.forEach(function(pt) { ctx.lineTo(pt[0], pt[1]); });
        ctx.lineTo(pts[pts.length - 1][0], H - pad);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // Track baseline
        ctx.beginPath();
        ctx.moveTo(pad, H - pad - 0.5);
        ctx.lineTo(W - pad, H - pad - 0.5);
        ctx.strokeStyle = trackColor;
        ctx.lineWidth = 1;
        ctx.stroke();

        // The line itself
        ctx.beginPath();
        pts.forEach(function(pt, i) {
            if (i === 0) ctx.moveTo(pt[0], pt[1]); else ctx.lineTo(pt[0], pt[1]);
        });
        ctx.strokeStyle = ticketsColor;
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.stroke();

        // Final-point glow dot (current hour) — only if we actually have data
        var last = pts[pts.length - 1];
        if (buckets[buckets.length - 1] > 0) {
            ctx.beginPath();
            ctx.arc(last[0], last[1], 4, 0, Math.PI * 2);
            ctx.fillStyle = ticketsColor;
            ctx.shadowColor = ticketsColor;
            ctx.shadowBlur = 10;
            ctx.fill();
            ctx.shadowBlur = 0;
        }

        // Give non-visual users the shape of the curve, not just its title.
        var peak = Math.max.apply(null, buckets);
        var summary = 'Plays per hour for the last ' + n + ' hours';
        if (peak > 0) {
            var ago = (n - 1) - buckets.indexOf(peak);
            summary += ' — peak ' + peak + (peak === 1 ? ' play ' : ' plays ')
                + (ago === 0 ? 'this hour' : ago + (ago === 1 ? ' hour ago' : ' hours ago'))
                + ', ' + buckets[n - 1] + ' this hour';
        } else {
            summary += ' — no plays in this window';
        }
        canvas.setAttribute('aria-label', summary);
    }

    function renderHeroPulse() {
        var hero = document.getElementById('hero-pulse');
        if (!hero) return;

        var t = ticketTotals || {
            tickets_today: 0, plays_today: 0, tickets_week: 0,
            plays_week: 0, tickets_hour: 0, plays_hour: 0,
            tickets_all: 0, plays_all: 0
        };

        // Active games right now (any plays in the last hour)
        var activeNow = 0;
        var hottestGame = null;
        var hottestTickets = 0;
        var uniqueCardsToday = {};
        Object.keys(ticketStats).forEach(function(id) {
            var s = ticketStats[id];
            if ((s.plays_hour || 0) > 0) activeNow++;
            if ((s.tickets_today || 0) > hottestTickets) {
                hottestTickets = s.tickets_today || 0;
                hottestGame = id;
            }
        });

        // Prefer the server's count (covers every play today); the feed-cache
        // derivation below undercounts on busy days because the cache holds
        // only the newest 500 rows. Fallback for stale backends only.
        var uniqueCount;
        if (t && typeof t.unique_cards_today === 'number') {
            uniqueCount = t.unique_cards_today;
        } else {
            if (allTransactions && allTransactions.length) {
                allTransactions.forEach(function(tr) {
                    if (tr.no_card || !tr.card_number) return;
                    if (tr.card_number === '000000') return;
                    uniqueCardsToday[tr.card_number] = true;
                });
            }
            uniqueCount = Object.keys(uniqueCardsToday).length;
        }

        // ---- Animate the giant ticket counter ----
        var ticketsValEl = document.getElementById('hero-tickets-value');
        var ticketsToday = Math.round(t.tickets_today || 0);
        animateNumber(ticketsValEl, ticketsToday, { duration: 900 });
        // Empty-state flag so CSS can dim the giant zero on slow days,
        // making clear "no data yet" vs a poll error.
        ticketsValEl.classList.toggle('hero-pulse-value-empty', ticketsToday === 0);
        // Brief flash if the count moved up since the last poll — the eye
        // catches the change without us having to redraw the layout.
        if (ticketsToday > Math.round(heroPrev.tickets_today || 0)) {
            ticketsValEl.classList.remove('hero-flash');
            void ticketsValEl.offsetWidth; // restart animation
            ticketsValEl.classList.add('hero-flash');
        }

        // ---- Delta pill: tickets in last hour vs prior hour (heuristic) ----
        var deltaEl = document.getElementById('hero-tickets-delta');
        if (deltaEl) {
            deltaEl.classList.remove('trend-up', 'trend-down', 'trend-flat');
            if ((t.tickets_hour || 0) > 0) {
                deltaEl.textContent = '↑ ' + formatBigNumber(Math.round(t.tickets_hour)) + ' in the last hour';
                deltaEl.classList.add('trend-up');
            } else if ((t.plays_today || 0) === 0) {
                deltaEl.textContent = 'Awaiting first play of the day';
                deltaEl.classList.add('trend-flat');
            } else {
                deltaEl.textContent = '↓ Quiet hour — no tickets in the last 60 min';
                deltaEl.classList.add('trend-flat');
            }
        }

        // ---- Hot-game chip (small, sits next to delta) ----
        var hotEl = document.getElementById('hero-hot-game');
        if (hotEl) {
            if (hottestGame && hottestTickets > 0) {
                var match = null;
                for (var i = 0; i < allGames.length; i++) {
                    if (allGames[i].game_id === hottestGame) { match = allGames[i]; break; }
                }
                var name = match ? match.game_name : ('Game ' + hottestGame);
                hotEl.innerHTML = '';
                hotEl.appendChild(App.el('span', { className: 'hero-pulse-hot-flame', textContent: '🔥' }));
                hotEl.appendChild(App.el('span', { textContent: 'Hottest ' }));
                hotEl.appendChild(App.el('span', { className: 'hero-pulse-hot-name', textContent: name }));
                hotEl.appendChild(App.el('span', { className: 'hero-pulse-hot-tickets',
                    textContent: '· ' + formatBigNumber(Math.round(hottestTickets)) + ' tickets' }));
                hotEl.style.display = '';
            } else {
                hotEl.style.display = 'none';
            }
        }

        // ---- Stat chips ----
        updateHeroChip('plays',
            formatBigNumber(t.plays_today || 0),
            (t.plays_hour || 0) > 0
                ? formatBigNumber(t.plays_hour) + ' in last hour'
                : 'no plays in last hour');

        var avg = (t.plays_today > 0) ? (t.tickets_today / t.plays_today) : 0;
        // formatPoints keeps the one-decimal precision ("1.6") that the
        // default formatBigNumber would round away.
        updateHeroChip('avg',
            avg > 0 ? formatPoints(avg) : '—',
            avg > 0 ? 'tickets per play' : 'awaiting plays',
            { format: formatPoints });

        updateHeroChip('active',
            String(activeNow),
            activeNow === 0 ? 'no active games right now'
                : activeNow === 1 ? '1 game played in last hour'
                : activeNow + ' games played in last hour');

        // The LIVE pill's text is reactive — gives the operator a one-word
        // read on venue tempo without scanning the whole dashboard.
        //   BUSY   → 5+ plays in the last hour
        //   LIVE   → 1-4 plays in the last hour
        //   QUIET  → no plays in the last hour but plays today
        //   IDLE   → no plays at all today
        var pill = document.getElementById('hero-live-pill');
        if (pill) {
            var pillText = pill.querySelector('.hero-live-text');
            var label, mood;
            var ph = t.plays_hour || 0;
            if (ph >= 5) { label = 'BUSY';  mood = 'busy';  }
            else if (ph >= 1) { label = 'LIVE';  mood = 'live';  }
            else if ((t.plays_today || 0) > 0) { label = 'QUIET'; mood = 'quiet'; }
            else { label = 'IDLE';  mood = 'idle';  }
            if (pillText) pillText.textContent = label;
            pill.classList.toggle('hero-live-pill-active', mood === 'busy' || mood === 'live');
            pill.classList.remove('hero-live-pill-busy', 'hero-live-pill-live', 'hero-live-pill-quiet', 'hero-live-pill-idle');
            pill.classList.add('hero-live-pill-' + mood);
            pill.title = label === 'BUSY'  ? ph + ' plays in the last hour — venue is humming'
                       : label === 'LIVE'  ? ph + ' play' + (ph === 1 ? '' : 's') + ' in the last hour'
                       : label === 'QUIET' ? 'No plays in the last hour — venue has gone quiet'
                       :                     'No plays yet today';
        }

        // ---- Hourly sparkline (12-bin) ----
        var canvas = document.getElementById('hero-spark');
        if (canvas) {
            paintHeroSparkline(canvas, sparklineBuckets());
        }

        // Save for next-cycle delta detection.
        heroPrev.tickets_today = t.tickets_today || 0;
        heroPrev.plays_today = t.plays_today || 0;
        heroPrev.tickets_hour = t.tickets_hour || 0;
        heroPrev.plays_hour = t.plays_hour || 0;
        heroPrev.unique_cards_today = uniqueCount;
        heroPrev.active_now = activeNow;
    }

    function updateHeroChip(key, value, detail, opts) {
        var chip = document.querySelector('.hero-chip[data-chip="' + key + '"]');
        if (!chip) return;
        var valEl = chip.querySelector('[data-role="value"]');
        var detailEl = chip.querySelector('[data-role="detail"]');
        var fmt = (opts && opts.format) || formatBigNumber;
        if (valEl) {
            // For numeric chips, animate the count-up. The chip values are
            // small enough that a parse-then-animate round-trip is fine.
            var numeric = String(value).replace(/[^\d.]/g, '');
            var asNum = parseFloat(numeric);
            if (!isNaN(asNum) && /^[\d,.]+[kKmM]?$/.test(String(value).trim())) {
                animateNumber(valEl, asNum, {
                    duration: 600,
                    format: function(n) { return fmt(n); }
                });
            } else {
                valEl.textContent = value;
            }
        }
        if (detailEl) detailEl.textContent = detail || '';
    }

    /**
     * Compact KPI strip below the hero — secondary stats. We dropped
     * "tickets today" since the hero already shouts that number; instead we
     * surface idle games, the venue's peak hour today, and the last 7 days'
     * ACTUAL ticket / play totals so operators can spot anomalies at a glance.
     * Every tile is a recorded count — nothing here is averaged or projected.
     */
    function renderTicketSummary() {
        var el = document.getElementById('ticket-summary');
        if (!el) return;
        el.innerHTML = '';

        var t = ticketTotals || { tickets_today: 0, plays_today: 0, tickets_week: 0,
            plays_week: 0, tickets_hour: 0, plays_hour: 0, tickets_all: 0, plays_all: 0 };

        // No-plays-today: enabled games that have had zero plays in the
        // current venue day. Surfaces potentially broken or unattractive
        // games and is the single most actionable KPI on this strip.
        var idleCount = 0;
        var totalEnabled = 0;
        allGames.forEach(function(g) {
            if (g.operation_status !== 'enabled') return;
            totalEnabled++;
            var s = ticketStats[g.game_id];
            if (!s || (s.plays_today || 0) === 0) idleCount++;
        });

        // Peak hour today — find the busiest 1-hour window today by ticket
        // dispense, computed from the cached play feed (same source as the
        // hero sparkline). Falls back gracefully when there's no data.
        var peakHour = computePeakHourToday();

        var tiles = [
            {
                label: 'No plays today',
                value: String(idleCount),
                hint: totalEnabled > 0
                    ? 'of ' + totalEnabled + ' enabled · idle today'
                    : 'no enabled games',
                cls: idleCount > 0 ? 'ticket-summary-tile-warn' : ''
            },
            {
                label: 'Peak hour today',
                value: peakHour ? peakHour.label : '—',
                hint: peakHour
                    ? formatBigNumber(Math.round(peakHour.tickets)) + ' tickets · ' + peakHour.plays + (peakHour.plays === 1 ? ' play' : ' plays')
                    : 'awaiting first play of the day',
                cls: peakHour ? 'ticket-summary-tile-tickets' : ''
            },
            {
                label: 'Tickets this week',
                value: formatBigNumber(Math.round(t.tickets_week || 0)),
                hint: 'dispensed over the last 7 days',
                cls: ''
            },
            {
                label: 'Plays this week',
                value: formatBigNumber(t.plays_week || 0),
                hint: 'plays recorded over the last 7 days',
                cls: ''
            },
            {
                label: 'Time-play plays today',
                value: formatBigNumber(t.time_plays_today || 0),
                hint: (t.plays_today || 0) > 0
                    ? Math.round(((t.time_plays_today || 0) / t.plays_today) * 100) + '% of today · wristbands & timed sessions'
                    : 'wristbands & timed sessions',
                cls: ''
            },
            {
                label: 'Privilege plays today',
                value: formatBigNumber(t.privilege_plays_today || 0),
                hint: (t.plays_today || 0) > 0
                    ? Math.round(((t.privilege_plays_today || 0) / t.plays_today) * 100) + '% of today · ride & session passes'
                    : 'ride & session passes',
                cls: ''
            }
        ];

        tiles.forEach(function(tile) {
            var children = [
                App.el('div', { className: 'ticket-summary-label', textContent: tile.label }),
                App.el('div', { className: 'ticket-summary-value', textContent: tile.value }),
                App.el('div', { className: 'ticket-summary-hint' }, [
                    App.el('span', { textContent: tile.hint })
                ])
            ];
            var tileEl = App.el('div', { className: 'ticket-summary-tile ' + (tile.cls || '') }, children);
            App.makeCardLink(tileEl, '#/analytics', { title: 'Open analytics for ' + tile.label.toLowerCase() });
            el.appendChild(tileEl);
        });
    }

    /**
     * Find the busiest hour of the venue's current day from the cached play
     * feed. Returns { label, tickets, plays, hour } or null if no plays today.
     * "label" is a localized "3 PM" / "3 AM" string for direct display.
     */
    function computePeakHourToday() {
        // Preferred source: the server's 24-bin histogram over ALL of
        // today's plays. The legacy path below derives from the feed cache,
        // which is capped at 500 rows — on a busy day it only sees the tail
        // of the evening and reports the wrong peak. Kept as a fallback for
        // a stale backend during a rolling deploy.
        var bins = {};
        if (hourlyToday && hourlyToday.length) {
            hourlyToday.forEach(function(b) {
                if ((b.plays || 0) > 0) {
                    bins[b.hour] = { plays: b.plays || 0, tickets: b.tickets || 0 };
                }
            });
        } else {
            if (!allTransactions || allTransactions.length === 0) return null;

            // Compute the start-of-today in the app timezone, expressed as a
            // browser-comparable UTC ms timestamp.
            var now = new Date();
            var fmt = new Intl.DateTimeFormat('en-CA', {
                timeZone: App.appTimezone,
                year: 'numeric', month: '2-digit', day: '2-digit'
            });
            var todayStr = fmt.format(now);  // YYYY-MM-DD in app TZ
            var hourFmt = new Intl.DateTimeFormat('en-US', {
                timeZone: App.appTimezone, hour12: false,
                hour: '2-digit'
            });
            // Bin txn-time into hour-of-day (0-23) in app TZ.
            for (var i = 0; i < allTransactions.length; i++) {
                var tr = allTransactions[i];
                var d = App.toUtcDate(tr.transaction_time);
                if (!d) continue;
                // Skip rows that aren't from "today" in the venue timezone.
                if (fmt.format(d) !== todayStr) continue;
                var hourLocal = parseInt(hourFmt.format(d), 10);
                if (isNaN(hourLocal)) continue;
                if (!bins[hourLocal]) bins[hourLocal] = { plays: 0, tickets: 0 };
                bins[hourLocal].plays += 1;
                bins[hourLocal].tickets += parseFloat(tr.redemption_tickets) || 0;
            }
        }
        // Find the bin with the most tickets (fall back to plays).
        var bestHour = -1;
        var bestTickets = -1;
        var bestPlays = -1;
        Object.keys(bins).forEach(function(h) {
            var b = bins[h];
            if (b.tickets > bestTickets || (b.tickets === bestTickets && b.plays > bestPlays)) {
                bestHour = parseInt(h, 10);
                bestTickets = b.tickets;
                bestPlays = b.plays;
            }
        });
        if (bestHour < 0) return null;
        var ampm = bestHour >= 12 ? 'PM' : 'AM';
        var h12 = bestHour % 12 || 12;
        return {
            label: h12 + ' ' + ampm,
            hour: bestHour,
            tickets: bestTickets,
            plays: bestPlays
        };
    }

    async function manualPoll() {
        try {
            var result = await API.post('games/transactions/poll');
            if (result && result.fetched > 0) {
                App.toast('Pulled ' + result.fetched + ' new play(s).', 'success');
            } else {
                App.toast('Feed already up to date.', 'info');
            }
        } catch (err) {
            App.toast('Poll failed: ' + err.message, 'error');
            return;
        }
        await Promise.all([loadFeed(), loadTopGames()]);
    }

    /**
     * Plain-words headline row above the stat tiles — the first three
     * questions a non-technical owner asks: is everything on, is a person
     * (not the schedule) steering part of the floor, and when was the room
     * busiest today. Same shared .insight-card look as Labor / Analytics.
     */
    function renderDashInsights(games, activeOverrides) {
        var box = document.getElementById('dash-insights');
        if (!box) return;
        box.innerHTML = '';

        var cards = [];

        var total = games.length;
        var enabled = games.filter(function(g) { return g.operation_status === 'enabled'; }).length;
        var paused = games.filter(function(g) { return g.operation_status === 'paused'; }).length;
        var oos = games.filter(function(g) { return g.operation_status === 'outOfService'; }).length;
        if (total > 0) {
            var bits = [];
            if (paused > 0) bits.push(paused + ' paused');
            if (oos > 0) bits.push(oos + ' out of service');
            cards.push(insightCard('🕹', oos > 0 ? 'insight-warn' : 'insight-good',
                'Games right now', enabled + ' of ' + total + ' running',
                bits.length ? bits.join(' · ') : 'Everything that can run is running'));
        }

        // Active overrides outrank the recurring schedule — worth a headline
        // when a temporary exception, not the plan, is running the floor.
        var ovCount = (activeOverrides || []).length;
        cards.push(insightCard(ovCount > 0 ? '✋' : '🗓',
            ovCount > 0 ? 'insight-accent' : 'insight-quiet',
            'Active overrides', ovCount === 0 ? 'None' : String(ovCount),
            ovCount === 0 ? 'The regular schedule is running the day'
                : (ovCount === 1 ? 'One temporary exception is beating the schedule'
                    : ovCount + ' temporary exceptions are beating the schedule')));

        // (The busiest-hour figure lives in the "Peak hour today" KPI tile —
        // it used to render here too under a different label, which read as
        // two different numbers.)

        if (cards.length === 0) { box.style.display = 'none'; return; }
        box.style.display = '';
        box.appendChild(App.el('div', { className: 'insight-row' }, cards));
    }

    // ------------------------------------------------------------------
    // Today's birthdays and work anniversaries
    //
    // A celebration strip under the header: one group per kind, one chip per
    // person, each group linking to its own page. It is the same selection the
    // Slack bots post (same leap-day rule, same opt-outs, same min_years and
    // milestone mode) — a dashboard listing people Slack said nothing about
    // would only raise "why didn't the bot mention Dana?".
    //
    // Four rules this follows, all of them because it sits above the fold on
    // the page an operator watches while running the floor:
    //
    //   1. Nothing to say, nothing on screen. No empty state, no "no birthdays
    //      today" — that is most days of the year, and it would be noise.
    //   2. It never reports its own failures here. If the POS roster can't be
    //      read, the group simply doesn't render; the Birthdays and
    //      Anniversaries pages are where that gets diagnosed. An optional
    //      accessory must not put a red banner on the floor's main screen.
    //   3. Birthday chips carry a NAME AND NOTHING ELSE. No age, no birth
    //      year: about a fifth of this roster are minors, and the greeting
    //      itself is forbidden from printing either. Years of service are the
    //      opposite case — they are the whole point of an anniversary — so
    //      only those chips carry a number.
    //   4. Its own slow cadence. The dashboard polls every 30 seconds; these
    //      read the employee roster, so they refresh every ten minutes and the
    //      server memoises both on top of that.
    // ------------------------------------------------------------------

    async function loadCelebrations() {
        // Both endpoints are gated server-side; checking here as well means a
        // role without a key never even makes that call. A role with only one
        // of the two gets only that group.
        var wantBirthdays = App.canAccess('view_birthdays');
        var wantAnniversaries = App.canAccess('view_anniversaries');
        if (!wantBirthdays && !wantAnniversaries) return;

        var gen = App.navGeneration();
        // Deliberately silent on failure — see rule 2 above. An error where the
        // game status should be would be worse than showing nothing.
        var quiet = function() { return null; };
        var results = await Promise.all([
            wantBirthdays ? API.get('birthdays/today').catch(quiet) : Promise.resolve(null),
            wantAnniversaries ? API.get('anniversaries/today').catch(quiet) : Promise.resolve(null)
        ]);
        if (App.navGeneration() !== gen) return;

        celebrations.birthdays = results[0];
        celebrations.anniversaries = results[1];
        // Stamped even when both came back null, so an endpoint that is
        // refusing us (a permission change mid-session, say) backs off to the
        // same ten minutes instead of being retried by every 30-second poll.
        celebrationsFetchedAt = Date.now();
        renderCelebrations();
    }

    /** Called from the 30s poll; actually fetches at most every ten minutes. */
    function maybeRefreshCelebrations() {
        if (!App.canAccess('view_birthdays') && !App.canAccess('view_anniversaries')) return;
        if (Date.now() - celebrationsFetchedAt < CELEBRATE_REFRESH_MS) return;
        loadCelebrations();
    }

    /**
     * The people in one `today` payload, or none when it can't be trusted.
     *
     * The date check is what stops a tab left open across midnight showing
     * yesterday's names until the ten-minute refresh catches up: the payload
     * says which day it is about, so it is dropped the moment that stops being
     * today at the venue.
     */
    function celebrationPeople(payload, today) {
        var d = payload || {};
        if (!d.available || !Array.isArray(d.people)) return [];
        if (d.date && today && d.date !== today) return [];
        return d.people;
    }

    function renderCelebrations() {
        var box = document.getElementById('dash-celebrations');
        if (!box) return;

        var today = venueToday();
        var birthdays = celebrationPeople(celebrations.birthdays, today);
        var anniversaries = celebrationPeople(celebrations.anniversaries, today);

        var groups = [];
        // Birthdays lead: they are the more universally recognised of the two,
        // and it is the order the strip's colour wash runs in.
        if (birthdays.length) {
            groups.push({
                kind: 'birthday',
                icon: '🎂',
                label: birthdays.length === 1 ? 'Birthday today' : 'Birthdays today',
                href: '#/birthdays',
                page: 'Birthdays',
                people: birthdays,
                showYears: false
            });
        }
        if (anniversaries.length) {
            groups.push({
                kind: 'anniversary',
                icon: anniversaries.some(function(p) { return p.milestone; }) ? '🏆' : '🎉',
                label: anniversaries.length === 1 ? 'Work anniversary today' : 'Work anniversaries today',
                href: '#/anniversaries',
                page: 'Work Anniversaries',
                people: anniversaries,
                showYears: true
            });
        }

        if (!groups.length) {
            box.innerHTML = '';
            box.style.display = 'none';
            return;
        }

        var kindClass = groups.length === 2 ? 'both' : groups[0].kind;
        var strip = App.el('div', { className: 'celebrate-strip celebrate-strip-' + kindClass },
            groups.map(celebrationGroup));

        box.innerHTML = '';
        box.appendChild(strip);
        box.style.display = '';
    }

    /** One kind of celebration, as a link to the page that explains it. */
    function celebrationGroup(g) {
        var shown = g.people.slice(0, 6);
        var extra = g.people.length - shown.length;

        var chips = shown.map(function(p) {
            var parts = [App.el('span', { className: 'celebrate-chip-name', textContent: p.name })];
            if (g.showYears) {
                // A star as well as the colour: the milestone treatment must
                // not be carried by two shades of green alone.
                if (p.milestone) {
                    parts.push(App.el('span', { className: 'celebrate-chip-star',
                        'aria-hidden': 'true', textContent: '★' }));
                }
                parts.push(App.el('span', { className: 'celebrate-chip-years',
                    textContent: yearsText(p.years) }));
            }
            return App.el('span', {
                className: 'celebrate-chip' + (p.milestone ? ' celebrate-chip-milestone' : ''),
                title: g.showYears
                    ? p.name + ' — ' + yearsText(p.years) + ' today'
                        + (p.milestone ? ' (a milestone year)' : '')
                    : p.name + ' — birthday today'
            }, parts);
        });
        if (extra > 0) {
            chips.push(App.el('span', { className: 'celebrate-chip celebrate-chip-more',
                textContent: '+' + extra + ' more' }));
        }

        // A real anchor rather than a click handler: hash routing works
        // natively, and it keeps middle-click, focus order and keyboard
        // activation without a role/tabindex shim.
        var link = App.el('a', {
            className: 'celebrate-group celebrate-group-' + g.kind,
            href: g.href,
            title: 'Open ' + g.page
        }, [
            App.el('span', { className: 'celebrate-group-icon', 'aria-hidden': 'true', textContent: g.icon }),
            App.el('span', { className: 'celebrate-group-label', textContent: g.label }),
            App.el('span', { className: 'celebrate-chips' }, chips)
        ]);

        // One plain sentence for a screen reader, rather than making it
        // assemble the group out of chips. Names the whole list, including
        // anyone behind "+N more".
        link.setAttribute('aria-label', g.label + ': ' + g.people.map(function(p) {
            return g.showYears
                ? p.name + ', ' + yearsText(p.years) + (p.milestone ? ', a milestone year' : '')
                : p.name;
        }).join('; ') + '. Open the ' + g.page + ' page.');
        return link;
    }

    function yearsText(n) {
        n = Number(n) || 0;
        return n + (n === 1 ? ' year' : ' years');
    }

    /** Today's date in the VENUE's timezone, which is not necessarily the browser's. */
    function venueToday() {
        try {
            return new Intl.DateTimeFormat('en-CA', {
                timeZone: App.appTimezone, year: 'numeric', month: '2-digit', day: '2-digit'
            }).format(new Date());   // yields YYYY-MM-DD
        } catch (e) {
            return null;   // a bad zone must not blank a perfectly good strip
        }
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

    function renderStats(games) {
        var grid = document.getElementById('stats-grid');
        if (!grid) return;
        grid.innerHTML = '';

        // Game-state counts only — venue-wide ticket/plays KPIs render in
        // the ticket-summary banner below this grid.
        var total = games.length;
        var enabled = games.filter(function(g) { return g.operation_status === 'enabled'; }).length;
        var paused = games.filter(function(g) { return g.operation_status === 'paused'; }).length;
        var oos = games.filter(function(g) { return g.operation_status === 'outOfService'; }).length;
        var enabledPct = total > 0 ? Math.round((enabled / total) * 100) : 0;
        var pausedPct = total > 0 ? Math.round((paused / total) * 100) : 0;
        var oosPct = total > 0 ? Math.round((oos / total) * 100) : 0;

        // Each tile deep-links into the Games page with the matching status
        // filter pre-applied — operators glance at the count, click to drill in.
        // The icon + tone class drives the colored accent stripe + glyph in CSS.
        [
            { label: 'Total Games', value: total, tone: 'accent', filter: 'all',
              hint: 'Open the games directory', icon: '◎', detail: total === 0 ? 'no games yet' : 'across all groups' },
            { label: 'Enabled', value: enabled, tone: 'success', filter: 'enabled',
              hint: 'Show only running games', icon: '▶', detail: enabledPct + '% of fleet' },
            { label: 'Paused', value: paused, tone: 'warning', filter: 'paused',
              hint: 'Show only paused games', icon: '❚❚', detail: pausedPct + '% of fleet' },
            { label: 'Out of Service', value: oos, tone: 'danger', filter: 'outOfService',
              hint: 'Show only out-of-service games', icon: '✕', detail: oosPct + '% of fleet' }
        ].forEach(function(s) {
            var valueEl = App.el('div', { className: 'stat-value', textContent: String(s.value) });
            var tile = App.el('div', { className: 'stat-card stat-card-modern stat-card-' + s.tone }, [
                App.el('div', { className: 'stat-card-iconrow' }, [
                    App.el('span', { className: 'stat-card-icon stat-card-icon-' + s.tone, 'aria-hidden': 'true', textContent: s.icon }),
                    App.el('div', { className: 'stat-label', textContent: s.label })
                ]),
                valueEl,
                App.el('div', { className: 'stat-card-detail', textContent: s.detail })
            ]);
            // Animate the count-up on first paint and any subsequent change —
            // the grid is rebuilt each poll, so seed the fresh element with
            // the previous value or every poll would re-count from zero.
            if (statPrev[s.filter] !== undefined) {
                valueEl.setAttribute('data-anim-value', String(statPrev[s.filter]));
            }
            animateNumber(valueEl, s.value, { duration: 600 });
            statPrev[s.filter] = s.value;
            App.makeCardLink(
                tile,
                s.filter === 'all' ? '#/games' : '#/games?status=' + encodeURIComponent(s.filter),
                { title: s.hint }
            );
            grid.appendChild(tile);
        });
    }

    /** Format a numeric value for display in stat cards (compact for thousands). */
    function formatBigNumber(n) {
        n = parseFloat(n) || 0;
        if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (n >= 10000)   return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
        return Math.round(n).toLocaleString();
    }

    /** Format a small numeric value (1 decimal) — used for averages. */
    function formatPoints(n) {
        n = parseFloat(n) || 0;
        if (n === 0) return '0';
        if (Math.abs(n) >= 100) return Math.round(n).toLocaleString();
        return (Math.round(n * 10) / 10).toString();
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

    /**
     * Today's payout % for one game, or null when it doesn't apply: the
     * game isn't a redemption game (never dispensed a ticket) or has no
     * point-plays yet today. Same definition as the venue payout gauge.
     */
    function gamePayoutToday(stats) {
        if (!stats || !stats.is_redemption) return null;
        var pts = stats.points_today || 0;
        if (pts <= 0) return null;
        return ((stats.tickets_today || 0) / pts) * 100;
    }

    var GAME_STATUS_ORDER = { enabled: 0, paused: 1, outOfService: 2 };

    /**
     * Column definitions for the game-status table — shared by the header
     * build (App.sortableTh) and the sorter (App.sortRows). sortValue
     * accessors return the RAW values (from ticketStats) because the cells
     * render formatted strings; payout/last-play return null when not
     * applicable so those rows sink to the bottom in either direction.
     */
    function gameTableColumns() {
        return [
            { key: 'game_name', label: 'Game Name',
              sortValue: function(g) { return g.game_name || ''; } },
            // Enum rank keeps the enabled → paused → outOfService ordering;
            // defaultDir overrides the numeric first-click-desc default.
            { key: 'operation_status', label: 'Status', type: 'number', defaultDir: 'asc',
              sortValue: function(g) {
                  return GAME_STATUS_ORDER[g.operation_status] !== undefined
                      ? GAME_STATUS_ORDER[g.operation_status] : 3;
              } },
            { key: 'plays_today', label: 'Plays Today', type: 'number', className: 'text-right',
              sortValue: function(g) {
                  return (ticketStats[g.game_id] && ticketStats[g.game_id].plays_today) || 0;
              } },
            { key: 'tickets_today', label: 'Tickets Today', type: 'number', className: 'text-right',
              sortValue: function(g) {
                  return (ticketStats[g.game_id] && ticketStats[g.game_id].tickets_today) || 0;
              } },
            { key: 'payout_today', label: 'Payout %', type: 'number', className: 'text-right',
              sortValue: function(g) { return gamePayoutToday(ticketStats[g.game_id]); } },
            { key: 'last_play', label: 'Last Play', type: 'date',
              sortValue: function(g) {
                  return (ticketStats[g.game_id] && ticketStats[g.game_id].last_play) || null;
              } },
            { key: 'categories', label: 'Categories', sortable: false }
        ];
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
        return App.sortRows(filtered, { key: gameSortCol, dir: gameSortDir }, gameTableColumns());
    }

    function renderGameView(games) {
        var el = document.getElementById('game-content');
        if (!el) return;
        el.innerHTML = '';

        if (games.length === 0) {
            el.appendChild(App.emptyState('\uD83C\uDFAE', 'No games found. Configure CenterEdge API in Settings.'));
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
        var slice = App.paginate(filtered, gamePage, gamePageSize);
        gamePage = slice.page;
        gamePageSize = slice.pageSize;
        var totalItems = slice.total;
        var totalPages = slice.totalPages;
        var pageItems = slice.items;

        // Scrollable table
        var scrollContainer = App.el('div', { className: 'table-scroll-container' });
        var table = App.el('table', { className: 'table' });

        // Header
        var thead = App.el('thead');
        var headerRow = App.el('tr');

        // Plays/tickets/last-play columns are sales data — hide them for
        // roles without analytics access. Game name + status + categories
        // remain visible so techs can still see fleet posture.
        var includeSales = App.canAccess('analytics');
        var salesCols = { plays_today: 1, tickets_today: 1, payout_today: 1, last_play: 1 };
        var columns = gameTableColumns().filter(function(col) {
            return includeSales || !salesCols[col.key];
        });

        // If a previously-selected sort column is now hidden (e.g. an admin
        // demoted to tech mid-session), fall back to game_name.
        if (!includeSales && salesCols[gameSortCol]) {
            gameSortCol = 'game_name';
            gameSortDir = 'asc';
        }

        columns.forEach(function(col) {
            headerRow.appendChild(App.sortableTh(col, { key: gameSortCol, dir: gameSortDir }, function(next) {
                gameSortCol = next.key;
                gameSortDir = next.dir;
                renderGameView(allGames);
            }));
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        // Body
        var tbody = App.el('tbody');
        pageItems.forEach(function(game) {
            var row = App.el('tr');
            var stats = ticketStats[game.game_id] || null;
            var plays = stats ? stats.plays_today : 0;
            var tickets = stats ? Math.round(stats.tickets_today || 0) : 0;

            // Game name (deep-links to the game's detail page, matching the
            // grid tiles' affordance) + ID secondary line
            row.appendChild(App.el('td', {}, [
                game.game_id
                    ? App.el('a', {
                        href: '#/games?game=' + encodeURIComponent(game.game_id),
                        className: 'game-table-name-link',
                        textContent: game.game_name,
                        title: 'Open game detail for ' + game.game_name
                    })
                    : App.el('div', { textContent: game.game_name, style: { fontWeight: '500' } }),
                App.el('div', { className: 'text-muted text-xs font-mono', textContent: game.game_id || '-' })
            ]));

            // Status badge
            row.appendChild(App.el('td', {}, [
                App.statusBadge(game.operation_status)
            ]));

            if (includeSales) {
                // Plays today
                row.appendChild(App.el('td', { className: 'text-right num-cell' }, [
                    App.el('span', { className: plays > 0 ? '' : 'text-muted',
                        textContent: plays.toLocaleString() })
                ]));

                // Tickets today (with tiny activity dot if active in last hour)
                var ticketsHour = stats ? (stats.tickets_hour || 0) : 0;
                var ticketCellChildren = [];
                if (ticketsHour > 0) {
                    ticketCellChildren.push(App.el('span', { className: 'tickets-dot', title: Math.round(ticketsHour) + ' in the last hour' }));
                }
                ticketCellChildren.push(App.el('span', {
                    className: tickets > 0 ? 'tickets-amount' : 'text-muted',
                    textContent: tickets.toLocaleString()
                }));
                row.appendChild(App.el('td', { className: 'text-right num-cell' }, ticketCellChildren));

                // Payout % today — red above the house target, otherwise
                // green. '—' for non-redemption games (rides, cages) and
                // redemption games with no point-plays yet today.
                var payout = gamePayoutToday(stats);
                if (payout === null) {
                    row.appendChild(App.el('td', {
                        className: 'text-right num-cell text-muted', textContent: '—',
                        title: stats && !stats.is_redemption
                            ? 'Not a redemption game (never dispensed tickets)'
                            : 'No point-plays yet today'
                    }));
                } else {
                    var over = payout > payoutTargetPct;
                    row.appendChild(App.el('td', { className: 'text-right num-cell' }, [
                        App.el('span', {
                            className: over ? 'text-danger' : 'text-success',
                            style: over ? { fontWeight: '600' } : {},
                            textContent: payout.toFixed(1) + '%',
                            title: Math.round(stats.tickets_today || 0).toLocaleString() + ' tickets / '
                                + Math.round(stats.points_today || 0).toLocaleString() + ' points today · target ≤ '
                                + payoutTargetPct + '%'
                        })
                    ]));
                }

                // Last play
                row.appendChild(App.el('td', {
                    className: 'text-sm text-secondary',
                    textContent: stats && stats.last_play ? App.formatRelative(stats.last_play) : '—',
                    title: stats && stats.last_play ? App.formatDatetime(stats.last_play) : ''
                }));
            }

            // Categories
            var cats = game.categories || [];
            var catText = cats.length > 0
                ? (typeof cats[0] === 'object' ? cats.map(function(c) { return c.name || c; }).join(', ') : cats.join(', '))
                : '-';
            row.appendChild(App.el('td', {
                className: 'text-sm text-secondary',
                textContent: catText,
                style: { maxWidth: '220px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
                title: catText
            }));

            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        scrollContainer.appendChild(table);
        container.appendChild(scrollContainer);

        // Pagination bar
        if (totalPages > 1 || totalItems > 25) {
            container.appendChild(buildGamePagination(totalItems));
        }
    }

    function buildGamePagination(totalItems) {
        var state = { page: gamePage, pageSize: gamePageSize, totalItems: totalItems };
        return App.buildPaginationBar(state, function() {
            gamePage = state.page;
            gamePageSize = state.pageSize;
            renderGameView(allGames);
        }, {
            pageSizeOptions: [25, 50, 100, 200],
            itemLabel: 'games',
            showPageNumbers: true
        });
    }

    function renderGameGrid(container, filtered) {
        // In grid mode, paginate too for large sets
        var slice = App.paginate(filtered, gamePage, gamePageSize);
        gamePage = slice.page;
        gamePageSize = slice.pageSize;
        var totalItems = slice.total;
        var totalPages = slice.totalPages;
        var pageItems = slice.items;

        // Pre-compute the busiest game's ticket count today so we can size each
        // tile's micro-bar relative to the leader. This makes hot games stand
        // out at a glance without a separate chart.
        var maxTickets = 0;
        Object.keys(ticketStats).forEach(function(id) {
            var v = ticketStats[id].tickets_today || 0;
            if (v > maxTickets) maxTickets = v;
        });

        var includeSales = App.canAccess('analytics');

        var grid = App.el('div', { className: 'game-grid' });
        pageItems.forEach(function(game) {
            var stats = ticketStats[game.game_id] || null;
            var plays = stats ? stats.plays_today : 0;
            var tickets = stats ? Math.round(stats.tickets_today || 0) : 0;
            var ticketsHour = stats ? Math.round(stats.tickets_hour || 0) : 0;
            var pct = maxTickets > 0 ? Math.min(100, Math.round((tickets / maxTickets) * 100)) : 0;

            var tileChildren = [
                App.el('div', { className: 'game-tile-header' }, [
                    App.el('div', { className: 'game-tile-name', textContent: game.game_name, title: game.game_name }),
                    App.statusBadge(game.operation_status)
                ])
            ];

            if (includeSales) {
                tileChildren.push(App.el('div', { className: 'game-tile-stats' }, [
                    App.el('div', { className: 'game-tile-metric' }, [
                        App.el('span', { className: 'game-tile-metric-value' + (tickets > 0 ? ' has-tickets' : ''),
                            textContent: tickets > 0 ? tickets.toLocaleString() : '0' }),
                        App.el('span', { className: 'game-tile-metric-label', textContent: 'tickets today' })
                    ]),
                    App.el('div', { className: 'game-tile-metric game-tile-metric-right' }, [
                        App.el('span', { className: 'game-tile-metric-value',
                            textContent: plays.toLocaleString() }),
                        App.el('span', { className: 'game-tile-metric-label', textContent: plays === 1 ? 'play' : 'plays' })
                    ])
                ]));
                tileChildren.push(App.el('div', { className: 'game-tile-bar', title: tickets + ' tickets today' }, [
                    App.el('div', { className: 'game-tile-bar-fill' + (tickets > 0 ? ' has-tickets' : ''),
                        style: { width: Math.max(2, pct) + '%' } })
                ]));
            }

            if (includeSales && ticketsHour > 0) {
                tileChildren.push(App.el('div', { className: 'game-tile-pulse' }, [
                    App.el('span', { className: 'tickets-dot' }),
                    App.el('span', { textContent: ticketsHour.toLocaleString() + ' in last hour' })
                ]));
            } else if (includeSales && stats && stats.last_play) {
                tileChildren.push(App.el('div', { className: 'game-tile-last text-xs text-muted' }, [
                    App.el('span', { textContent: 'last play ' + App.formatRelative(stats.last_play),
                        title: App.formatDatetime(stats.last_play) })
                ]));
            }

            var tile = App.el('div', {
                className: 'game-tile game-tile-rich',
                'data-status': game.operation_status
            }, tileChildren);
            // Tile click → open this game's detail on the Games page.
            App.makeCardLink(tile, '#/games?game=' + encodeURIComponent(game.game_id),
                { title: 'Open game detail for ' + game.game_name });
            grid.appendChild(tile);
        });
        container.appendChild(grid);

        // Pagination bar
        if (totalPages > 1 || totalItems > 25) {
            container.appendChild(buildGamePagination(totalItems));
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
            el.appendChild(App.el('div', { className: 'empty-state', style: { padding: '2rem' } }, [
                App.el('div', { className: 'empty-state-icon', textContent: '\u25CB' }),
                App.el('div', { className: 'empty-state-text', textContent: 'No active groups configured.' })
            ].concat(App.canAccess('groups_manage') ? [
                App.el('div', { className: 'empty-state-action' }, [
                    App.el('button', {
                        className: 'btn btn-primary btn-sm',
                        textContent: 'Create Group',
                        onClick: function() { window.location.hash = '#/groups/new'; }
                    })
                ])
            ] : [])));
            return;
        }

        // Master controls — operating the floor needs manual_control; a
        // view-only role gets the live states with no buttons (the server
        // re-checks every action regardless).
        if (masterEl && activeGroups.length > 1 && App.canAccess('manual_control')) {
            var hasAnyPaused = activeGroups.some(function(g) { return g.effective_state === 'paused' || g.effective_state === 'mixed'; });
            var hasAnyEnabled = activeGroups.some(function(g) { return g.effective_state === 'enabled' || g.effective_state === 'mixed'; });

            if (hasAnyPaused) {
                masterEl.appendChild(App.el('button', {
                    className: 'btn btn-sm btn-success',
                    textContent: 'Unpause All',
                    onClick: function() { doBulkAction('unpause', activeGroups); }
                }));
            }
            if (hasAnyEnabled) {
                masterEl.appendChild(App.el('button', {
                    className: 'btn btn-sm btn-warning',
                    textContent: 'Pause All',
                    onClick: function() { doBulkAction('pause', activeGroups); }
                }));
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
                App.makeCardLink(item, '#/groups/' + encodeURIComponent(group.id),
                    { title: 'Edit group "' + group.name + '"' });
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

            // Header: status dot + name | state badge.
            // The whole card is clickable (card link below) — making the name
            // a focusable element keeps screen readers and keyboard users in
            // sync with the visual affordance.
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

            // The card itself navigates to the group's edit page; nested
            // pause/unpause buttons and "Resume Schedule" links are honored
            // via makeCardLink's interactive-descendant guard.
            App.makeCardLink(card, '#/groups/' + encodeURIComponent(group.id),
                { title: 'Edit group "' + group.name + '"' });

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

            // Group ticket summary — sum tickets and plays today across all
            // games in the group. Helps managers see which sections are busy.
            var groupTickets = aggregateGroupTickets(group);
            if (groupTickets.plays_today > 0 || groupTickets.tickets_today > 0) {
                card.appendChild(App.el('div', { className: 'group-tickets' }, [
                    App.el('div', { className: 'group-tickets-row' }, [
                        App.el('span', { className: 'group-tickets-icon', textContent: '\u{1F39F}' }),
                        App.el('span', { className: 'group-tickets-value', textContent: formatBigNumber(groupTickets.tickets_today) }),
                        App.el('span', { className: 'group-tickets-label', textContent: 'tickets today' })
                    ]),
                    App.el('div', { className: 'group-tickets-row' }, [
                        App.el('span', { className: 'group-tickets-icon', textContent: '▶' }),
                        App.el('span', { className: 'group-tickets-value', textContent: formatBigNumber(groupTickets.plays_today) }),
                        App.el('span', { className: 'group-tickets-label',
                            textContent: groupTickets.plays_today === 1 ? 'play today' : 'plays today' })
                    ])
                ]));
            }

            // Context: manual override, active override, or next scheduled transition
            var manualOvr = group.manual_override;
            if (manualOvr) {
                var manualLabel = manualOvr.action === 'pause' ? 'Manually Paused' : 'Manually Unpaused';
                card.appendChild(App.el('div', { className: 'group-control-context group-control-context-manual' },
                    [
                        App.el('span', { textContent: '\u270B' }),
                        App.el('span', { style: { fontWeight: '500' }, textContent: manualLabel }),
                        App.el('span', { style: { opacity: '0.7' }, textContent: ' \u2022 since ' + App.formatDatetime(manualOvr.at) })
                    ].concat(App.canAccess('manual_control') ? [App.el('button', {
                        className: 'btn btn-ghost btn-xs',
                        textContent: 'Resume Schedule',
                        style: { marginLeft: 'auto', fontSize: '0.72rem' },
                        onClick: function() { clearManualOverride(group.id, group.name); }
                    })] : [])));
            } else if (override) {
                card.appendChild(App.el('div', { className: 'group-control-context group-control-context-override' }, [
                    App.el('span', { textContent: '\u26A1' }),
                    App.el('span', { style: { fontWeight: '500' }, textContent: override.name }),
                    App.el('span', { style: { opacity: '0.7' }, textContent: ' \u2022 ' + override.action + ' \u2022 ends ' + App.formatDatetime(override.end_datetime) })
                ]));
            } else if (nextTrans) {
                card.appendChild(App.el('div', { className: 'group-control-context' }, [
                    App.el('span', { textContent: '\u25F4' }),
                    App.el('span', { textContent: (nextTrans.action === 'pause' ? 'Pause' : 'Unpause') + ' scheduled at ' + App.formatTime(nextTrans.time) })
                ]));
            }

            // Action buttons — hidden entirely for roles without
            // manual_control so a viewer sees clean live status, not
            // buttons that would 403.
            var actionRow = App.el('div', { className: 'group-control-actions' });

            if (!App.canAccess('manual_control')) {
                card.appendChild(actionRow);
                el.appendChild(card);
                return;
            }

            if (isEmpty) {
                actionRow.appendChild(App.el('span', { className: 'text-muted text-xs', style: { padding: '0.35rem 0', display: 'block', textAlign: 'center', width: '100%' }, textContent: 'No games or kiosks assigned to this group' }));
            } else if (state === 'mixed') {
                actionRow.appendChild(App.el('button', {
                    className: 'btn btn-success',
                    textContent: 'Unpause All',
                    onClick: function() { doGroupAction(group.id, 'unpause', group.name, combined.total); }
                }));
                actionRow.appendChild(App.el('button', {
                    className: 'btn btn-warning',
                    textContent: 'Pause All',
                    onClick: function() { doGroupAction(group.id, 'pause', group.name, combined.total); }
                }));
            } else if (isPaused) {
                actionRow.appendChild(App.el('button', {
                    className: 'btn btn-success',
                    textContent: 'Unpause Group',
                    onClick: function() { doGroupAction(group.id, 'unpause', group.name, combined.total); }
                }));
            } else {
                actionRow.appendChild(App.el('button', {
                    className: 'btn btn-warning',
                    textContent: 'Pause Group',
                    onClick: function() { doGroupAction(group.id, 'pause', group.name, combined.total); }
                }));
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
        renderDashInsights(allGames, lastActiveOverrides);
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
            renderDashInsights(allGames, lastActiveOverrides);
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
        renderDashInsights(allGames, lastActiveOverrides);
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
     * Sum today's plays and tickets across all games in a group.
     * Returns { plays_today, tickets_today, tickets_hour }.
     */
    function aggregateGroupTickets(group) {
        var ids = group.game_ids || [];
        var out = { plays_today: 0, tickets_today: 0, tickets_hour: 0 };
        for (var i = 0; i < ids.length; i++) {
            var s = ticketStats[ids[i]];
            if (!s) continue;
            out.plays_today += s.plays_today || 0;
            out.tickets_today += s.tickets_today || 0;
            out.tickets_hour += s.tickets_hour || 0;
        }
        return out;
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
            el.appendChild(App.el('p', { className: 'text-muted text-sm', textContent: 'No active overrides \u2014 schedule and manual actions are in effect.' }));
            return;
        }

        var now = Date.now();
        overrides.forEach(function(o) {
            var startD = App.toUtcDate(o.start_datetime);
            var endD = App.toUtcDate(o.end_datetime);
            var startMs = startD ? startD.getTime() : null;
            var endMs = endD ? endD.getTime() : null;

            // Progress through the override window \u2014 drives the bar fill so
            // operators see at a glance how much of the override is "spent".
            var pct = 0;
            if (startMs !== null && endMs !== null && endMs > startMs) {
                pct = Math.min(100, Math.max(0, ((now - startMs) / (endMs - startMs)) * 100));
            }
            var remainingMs = endMs !== null ? Math.max(0, endMs - now) : 0;
            var imminent = remainingMs > 0 && remainingMs <= 5 * 60 * 1000; // <5 min

            // Action-colour theming + clear verb in the card.
            var actionLabel = o.action === 'pause' ? 'Pausing' : 'Unpausing';
            var card = App.el('div', {
                className: 'override-card override-card-modern' + (imminent ? ' override-card-imminent' : ''),
                'data-action': o.action
            });

            // Header row: action badge + override name on the left, big
            // countdown on the right.
            card.appendChild(App.el('div', { className: 'override-card-headerrow' }, [
                App.el('div', { className: 'override-card-titleblock' }, [
                    App.el('div', { className: 'override-card-actionrow' }, [
                        App.el('span', { className: 'override-card-actionbadge override-card-actionbadge-' + o.action,
                            textContent: actionLabel }),
                        App.el('span', { className: 'override-card-name', textContent: o.name })
                    ]),
                    App.el('div', { className: 'override-card-meta text-sm text-secondary' }, [
                        App.el('span', { textContent: o.group_name }),
                        App.el('span', { className: 'override-card-meta-sep', textContent: ' \u00b7 ' }),
                        App.el('span', { textContent: 'ends ' + App.formatDatetime(o.end_datetime) })
                    ])
                ]),
                App.el('div', { className: 'override-card-countdown' + (imminent ? ' override-card-countdown-imminent' : '') }, [
                    App.el('div', { className: 'override-card-countdown-value',
                        textContent: endMs !== null ? App.formatRelative(o.end_datetime) : '\u2014' }),
                    App.el('div', { className: 'override-card-countdown-label',
                        textContent: 'remaining' })
                ])
            ]));

            // Progress bar showing time elapsed within the override.
            card.appendChild(App.el('div', { className: 'override-card-progress',
                'aria-label': 'Override progress: ' + Math.round(pct) + '% elapsed' }, [
                App.el('div', { className: 'override-card-progress-fill',
                    style: { width: pct + '%' } })
            ]));

            // Click drills into the Overrides page where the operator can
            // delete (revert) or extend the active override.
            App.makeCardLink(card, '#/overrides',
                { title: 'Manage active override "' + o.name + '"' });
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
            renderDashInsights(allGames, lastActiveOverrides);
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
        if (btn) { btn.disabled = true; btn.textContent = 'Syncing...'; }

        try {
            await API.post('games/sync');
            App.toast('Games synced successfully.', 'success');
            await loadDashboard();
        } catch (err) {
            App.toast('Sync failed: ' + err.message, 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Sync now'; }
        }
    }
})();
