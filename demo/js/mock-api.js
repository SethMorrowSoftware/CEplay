/**
 * Mock API for the demo.
 *
 * Drop-in replacement for public/js/api.js — all UI modules call
 * API.get/post/put/patch/del and receive the same response shapes
 * as the real backend, but everything is computed in-memory from
 * window.MockData. Stateful so that pause/unpause, group edits,
 * schedule edits, etc. persist for the page session.
 */
(function() {
    var data = window.MockData;
    var DELAY_MIN = 60;     // ms — small delay to feel "live"
    var DELAY_MAX = 220;

    function delay() {
        var ms = DELAY_MIN + Math.floor(Math.random() * (DELAY_MAX - DELAY_MIN));
        return new Promise(function(r) { setTimeout(r, ms); });
    }

    function clone(v) { return v == null ? v : JSON.parse(JSON.stringify(v)); }

    function err(status, message) {
        var e = new Error(message);
        e.status = status;
        return e;
    }

    function findGroup(id) {
        id = parseInt(id, 10);
        return data.groups.find(function(g) { return g.id === id; });
    }

    function findGame(id) {
        return data.games.find(function(g) { return String(g.game_id) === String(id); });
    }

    function findKiosk(id) {
        return data.kiosks.find(function(k) { return String(k.id) === String(id); });
    }

    function findSchedule(id) {
        id = parseInt(id, 10);
        return data.schedules.find(function(s) { return s.id === id; });
    }

    function findUser(id) {
        id = parseInt(id, 10);
        return data.users.find(function(u) { return u.id === id; });
    }

    // -------------------------------------------------------------------
    // Role-based access mirror
    //
    // Mirrors the server's Auth::ACCESS_MATRIX. The mock dispatcher
    // returns 403 for any role not in the allow-list so the demo behaves
    // exactly like the live backend when the role switcher flips between
    // admin / manager / tech.
    // -------------------------------------------------------------------
    // Permission catalog + role store — mirrors Auth::PERMISSIONS and the
    // seeded roles table. Roles are mutable in-memory so the Settings →
    // Roles editor works in the demo; reload to reset.
    var PERMISSIONS = {
        analytics:        'View Analytics & Performance reporting (plays and tickets)',
        view_revenue:     'See cash / revenue figures in reporting',
        cards:            'Card lookup: balances, transaction history, PIN checks',
        manual_control:   'Operate the floor: pause/unpause groups, kiosk & game actions, force syncs',
        overrides_manage: 'Create and delete schedule overrides',
        groups_manage:    'Create, edit, and delete pause groups',
        schedules_manage: 'Create, edit, and delete recurring schedules',
        settings:         'System settings: CenterEdge API credentials, timezone',
        users:            'Manage user accounts (admin accounts always excluded)'
    };
    var rolesStore = [
        { slug: 'admin', name: 'Administrator', is_system: 1,
          description: 'Full access to every feature, including settings, user and role management.',
          permissions: Object.keys(PERMISSIONS) },
        { slug: 'manager', name: 'Manager', is_system: 1,
          description: 'Runs the floor and the automation: full analytics with revenue, card lookup, and group/schedule management. No system settings or user management.',
          permissions: ['analytics', 'view_revenue', 'cards', 'manual_control', 'overrides_manage', 'groups_manage', 'schedules_manage'] },
        { slug: 'tech', name: 'Technician', is_system: 1,
          description: 'Keeps machines running: pause/unpause, kiosk actions, overrides, and system settings. No sales data, card lookup, or group/schedule editing.',
          permissions: ['analytics', 'manual_control', 'overrides_manage', 'settings', 'users'] }
    ];

    function findRole(slug) {
        slug = String(slug || '').toLowerCase().trim();
        return rolesStore.filter(function(r) { return r.slug === slug; })[0] || null;
    }

    function permissionsFor(slug) {
        if (slug === 'admin') return Object.keys(PERMISSIONS);
        var r = findRole(slug);
        if (!r) return []; // unknown role: fail closed to nothing
        return r.permissions.filter(function(p) { return PERMISSIONS[p]; });
    }

    function canAssignRole(callerRole, targetSlug) {
        if (!findRole(targetSlug)) return false;
        if (callerRole === 'admin') return true;
        if (targetSlug === 'admin') return false;
        var mine = permissionsFor(callerRole);
        return permissionsFor(targetSlug).every(function(p) { return mine.indexOf(p) !== -1; });
    }

    function normalizeRole(role) {
        role = String(role || '').toLowerCase().trim();
        // Keep existing slugs; unknown slugs resolve to zero permissions.
        return findRole(role) ? role : (role || 'tech');
    }

    function buildRolesPayload() {
        var callerRole = currentRole();
        var isAdmin = callerRole === 'admin';
        return {
            roles: rolesStore.map(function(r) {
                var userCount = data.users.filter(function(u) { return normalizeRole(u.role) === r.slug; }).length;
                return {
                    slug: r.slug, name: r.name, description: r.description,
                    permissions: permissionsFor(r.slug),
                    is_system: r.is_system, user_count: userCount,
                    assignable: canAssignRole(callerRole, r.slug),
                    editable: r.slug !== 'admin' && isAdmin,
                    deletable: !r.is_system && userCount === 0 && isAdmin
                };
            }),
            permissions: PERMISSIONS
        };
    }

    function currentUserObject() {
        return (window.APP_CONFIG && window.APP_CONFIG.user) || null;
    }

    function currentRole() {
        var u = currentUserObject();
        return normalizeRole(u && u.role);
    }

    function canAccess(area) {
        if (!PERMISSIONS[area]) return true;
        return permissionsFor(currentRole()).indexOf(area) !== -1;
    }

    function requireAccess(area) {
        if (!canAccess(area)) {
            throw err(403, 'You do not have permission to access this resource.');
        }
    }

    function countActiveAdmins(excludeId) {
        return data.users.filter(function(u) {
            return u.is_active && normalizeRole(u.role) === 'admin' && u.id !== excludeId;
        }).length;
    }

    /**
     * Build the augmented group payload the dashboard / groups list expects:
     * stats, effective state, manual override, active override pointer, next
     * scheduled transition.
     */
    function decorateGroup(g) {
        var gameIds = data.expandGroupGames(g);
        var kioskIds = data.expandGroupKiosks(g);

        var gEnabled = 0, gPaused = 0, gOos = 0;
        gameIds.forEach(function(id) {
            var game = findGame(id);
            if (!game) return;
            if (game.operation_status === 'enabled') gEnabled++;
            else if (game.operation_status === 'paused') gPaused++;
            else if (game.operation_status === 'outOfService') gOos++;
        });
        var kEnabled = 0, kPaused = 0, kOos = 0, kUnknown = 0;
        kioskIds.forEach(function(id) {
            var k = findKiosk(id);
            if (!k) return;
            if (k.operationStatus === 'enabled') kEnabled++;
            else if (k.operationStatus === 'paused') kPaused++;
            else if (k.operationStatus === 'outOfService') kOos++;
            else kUnknown++;
        });

        var gameStats = { total: gEnabled + gPaused + gOos, enabled: gEnabled, paused: gPaused, out_of_service: gOos };
        var kioskStats = { total: kEnabled + kPaused + kOos + kUnknown, enabled: kEnabled, paused: kPaused, out_of_service: kOos, unknown: kUnknown };
        var combinedTotal = gameStats.total + kioskStats.total;
        var combinedEnabled = gEnabled + kEnabled;
        var combinedPaused = gPaused + kPaused;
        var combinedOos = gOos + kOos;
        var combined = { total: combinedTotal, enabled: combinedEnabled, paused: combinedPaused, out_of_service: combinedOos };

        var effectiveState;
        if (combinedTotal === 0) effectiveState = 'empty';
        else if (combinedEnabled === 0 && combinedPaused > 0) effectiveState = 'paused';
        else if (combinedPaused === 0 && combinedEnabled > 0) effectiveState = 'enabled';
        else effectiveState = 'mixed';

        // Active override (any in data.overrides.active matching this group)
        var activeOverride = data.overrides.active.find(function(o) { return o.pause_group_id === g.id; }) || null;

        // Manual override (group-scoped, set when an operator clicked Pause/Unpause)
        var manualOverride = data.manualOverrides[g.id] || null;

        // Find next scheduled transition today
        var nextTransition = null;
        var todayDow = new Date().getDay();
        var nowHHMM = (function() { var d = new Date(); return [d.getHours(), d.getMinutes()]; })();
        var todays = data.schedules.filter(function(s) { return s.pause_group_id === g.id && s.day_of_week === todayDow && s.is_active; });
        todays.forEach(function(s) {
            // Active window: [start, end]. Generates two transitions a day:
            // start=unpause, end=pause.
            [
                { time: s.start_time, action: 'unpause' },
                { time: s.end_time, action: 'pause' }
            ].forEach(function(t) {
                var parts = t.time.split(':').map(Number);
                if (parts[0] > nowHHMM[0] || (parts[0] === nowHHMM[0] && parts[1] > nowHHMM[1])) {
                    if (!nextTransition || t.time < nextTransition.time) {
                        nextTransition = t;
                    }
                }
            });
        });

        return {
            id: g.id,
            name: g.name,
            description: g.description,
            is_active: g.is_active,
            game_ids: gameIds,
            kiosk_ids: kioskIds,
            game_stats: gameStats,
            kiosk_stats: kioskStats,
            combined_stats: combined,
            effective_state: effectiveState,
            active_override: activeOverride,
            manual_override: manualOverride,
            next_transition: nextTransition,
            schedule_count: data.schedules.filter(function(s) { return s.pause_group_id === g.id; }).length,
            category_count: (g.category_ids || []).length
        };
    }

    function getGroupDetail(g) {
        var d = decorateGroup(g);
        d.games = data.expandGroupGames(g).map(function(id) {
            var game = findGame(id);
            return game ? { game_id: game.game_id, game_name: game.game_name } : null;
        }).filter(Boolean);
        d.kiosks = data.expandGroupKiosks(g).map(function(id) {
            var k = findKiosk(id);
            return k ? { kiosk_id: k.id, name: k.name } : null;
        }).filter(Boolean);
        d.categories = (g.category_ids || []).map(function(cid) {
            var c = data.categories.find(function(c) { return c.id === cid; });
            return c ? { category_id: c.id, name: c.name } : null;
        }).filter(Boolean);
        return d;
    }

    /**
     * Apply a pause/unpause action against a group. Returns the
     * {success, action, changed, errors, details} envelope the real
     * groups handler returns.
     */
    function pauseGroup(g, action) {
        var desired = action === 'pause' ? 'paused' : 'enabled';
        var changed = [];
        data.expandGroupGames(g).forEach(function(id) {
            var game = findGame(id);
            if (!game) return;
            if (game.operation_status === 'outOfService') return;
            if (game.operation_status !== desired) {
                game.operation_status = desired;
                changed.push({ game_id: game.game_id, new_status: desired });
            }
        });
        data.expandGroupKiosks(g).forEach(function(id) {
            var k = findKiosk(id);
            if (!k) return;
            if (k.operationStatus === 'outOfService' || !k.operationStatus) return;
            if (k.operationStatus !== desired) {
                k.operationStatus = desired;
            }
        });

        // Record a manual override stamp. The real backend tracks this so
        // schedule transitions don't undo a manager's intent immediately.
        data.manualOverrides[g.id] = { action: action, at: data.isoUtc(new Date()) };

        // Append a log entry
        data.logs.unshift({
            id: 99000 + Math.floor(Math.random() * 1000),
            timestamp: data.sqlUtc(new Date()),
            source: 'manual',
            action: action,
            group_name: g.name,
            game_name: null,
            error_message: null,
            success: 1,
            triggered_by: 'Demo Operator'
        });

        return {
            success: true,
            action: action,
            changed: changed.length,
            errors: 0,
            details: { changed: changed, errors: [] }
        };
    }

    function clearManual(g) {
        delete data.manualOverrides[g.id];
        return { success: true, enforced: { changed: [] } };
    }

    /**
     * Build the analytics/overview payload from the in-memory store.
     * Mirrors the shape produced by api/analytics.php so analytics.js renders
     * the same KPI cards, fleet tiles, and charts in the demo.
     */
    function buildAnalyticsOverview(rangeKey, fromQ, toQ) {
        var allowed = ['today','7d','30d','90d','all','custom'];
        if (allowed.indexOf(rangeKey) === -1) rangeKey = '7d';

        var now = new Date();
        var startMs, endMs = now.getTime();
        var startD = new Date(now);

        if (rangeKey === 'today') {
            startD = new Date(now); startD.setHours(0, 0, 0, 0);
            startMs = startD.getTime();
        } else if (rangeKey === '30d') {
            startMs = now.getTime() - 30 * 86400000;
            startD = new Date(startMs);
        } else if (rangeKey === '90d') {
            startMs = now.getTime() - 90 * 86400000;
            startD = new Date(startMs);
        } else if (rangeKey === 'all') {
            // Earliest transaction or 1 year back, whichever is sooner.
            var earliest = data.transactions.reduce(function(min, t) {
                var ms = new Date(t.transaction_time).getTime();
                return (min === null || ms < min) ? ms : min;
            }, null);
            if (earliest === null) earliest = now.getTime() - 365 * 86400000;
            startMs = earliest;
            startD = new Date(startMs);
        } else if (rangeKey === 'custom' && fromQ && toQ) {
            var f = new Date(fromQ + 'T00:00:00');
            var t = new Date(toQ + 'T00:00:00'); t.setDate(t.getDate() + 1);
            startMs = f.getTime();
            endMs = t.getTime();
            startD = f;
        } else {
            // Default 7d
            startMs = now.getTime() - 7 * 86400000;
            startD = new Date(startMs);
        }

        var rangeMs = Math.max(1, endMs - startMs);
        var prevEndMs = startMs;
        var prevStartMs = startMs - rangeMs;

        function kpisFor(s, e) {
            var plays = 0, tickets = 0, cash = 0, points = 0, bonus = 0;
            var creditCard = 0, cardPlays = 0, timePlays = 0, privPlays = 0;
            var cards = {};
            data.transactions.forEach(function(t) {
                var ms = new Date(t.transaction_time).getTime();
                if (ms < s || ms >= e) return;
                plays += 1;
                tickets += t.redemption_tickets || 0;
                cash    += 0; // demo data has no cash field; leave at 0
                points  += t.regular_points || 0;
                bonus   += t.bonus_points || 0;
                if (t.no_card || !t.card_number) {
                    creditCard += 1;
                } else {
                    cardPlays += 1;
                    cards[t.card_number] = true;
                }
                if (t.used_time_play) timePlays += 1;
                if (t.used_play_privilege) privPlays += 1;
            });
            return {
                plays: plays,
                unique_cards: Object.keys(cards).length,
                tickets: tickets,
                cash: Math.round(cash * 100) / 100,
                points: points,
                bonus_points: bonus,
                credit_card_plays: creditCard,
                card_plays: cardPlays,
                time_plays: timePlays,
                privilege_plays: privPlays,
                avg_tickets_per_play: plays > 0 ? Math.round((tickets / plays) * 100) / 100 : 0,
                avg_cash_per_play:    plays > 0 ? Math.round((cash / plays) * 100) / 100 : 0
            };
        }

        var kpis = kpisFor(startMs, endMs);
        var previousKpis = kpisFor(prevStartMs, prevEndMs);

        // Fleet posture (live snapshot, not range-scoped)
        var gameMix = { enabled: 0, paused: 0, outOfService: 0 };
        data.games.forEach(function(g) {
            if (g.operation_status === 'enabled') gameMix.enabled += 1;
            else if (g.operation_status === 'paused') gameMix.paused += 1;
            else if (g.operation_status === 'outOfService') gameMix.outOfService += 1;
        });
        var kioskMix = { enabled: 0, paused: 0, outOfService: 0, unknown: 0 };
        data.kiosks.forEach(function(k) {
            var s = k.operationStatus || '';
            if (s === 'enabled') kioskMix.enabled += 1;
            else if (s === 'paused') kioskMix.paused += 1;
            else if (s === 'outOfService') kioskMix.outOfService += 1;
            else kioskMix.unknown += 1;
        });
        var activeOverrides = (data.overrides.active || []).length;
        var manualOverrideGroups = Object.keys(data.manualOverrides || {}).length;
        var fleet = {
            total_games: gameMix.enabled + gameMix.paused + gameMix.outOfService,
            enabled_games: gameMix.enabled,
            paused_games: gameMix.paused,
            out_of_service_games: gameMix.outOfService,
            total_kiosks: kioskMix.enabled + kioskMix.paused + kioskMix.outOfService + kioskMix.unknown,
            enabled_kiosks: kioskMix.enabled,
            paused_kiosks: kioskMix.paused,
            out_of_service_kiosks: kioskMix.outOfService,
            unknown_kiosks: kioskMix.unknown,
            active_groups: data.groups.filter(function(g) { return g.is_active; }).length,
            groups_with_manual_override: manualOverrideGroups,
            active_overrides: activeOverrides,
            pending_retries: 0
        };

        // Time-bucketed series in local time
        var hourBuckets = new Array(24).fill(0);
        var dowBuckets = new Array(7).fill(0);
        var ticketsByHour = new Array(24).fill(0);
        var dailyMap = {};
        data.transactions.forEach(function(t) {
            var ms = new Date(t.transaction_time).getTime();
            if (ms < startMs || ms >= endMs) return;
            var d = new Date(ms);
            var h = d.getHours();
            var dow = d.getDay();
            var key = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            hourBuckets[h] += 1;
            dowBuckets[dow] += 1;
            ticketsByHour[h] += t.redemption_tickets || 0;
            if (!dailyMap[key]) dailyMap[key] = { date: key, plays: 0, tickets: 0, cash: 0 };
            dailyMap[key].plays += 1;
            dailyMap[key].tickets += t.redemption_tickets || 0;
        });

        // Gap-fill daily series
        var daily = [];
        var cursor = new Date(startD); cursor.setHours(0, 0, 0, 0);
        var stop = new Date(endMs);
        var safety = 400;
        while (cursor.getTime() < stop.getTime() && safety-- > 0) {
            var k = cursor.getFullYear() + '-' + pad(cursor.getMonth() + 1) + '-' + pad(cursor.getDate());
            daily.push(dailyMap[k] || { date: k, plays: 0, tickets: 0, cash: 0 });
            cursor.setDate(cursor.getDate() + 1);
        }

        // Top-N games leaderboards
        var byGame = {};
        data.transactions.forEach(function(t) {
            var ms = new Date(t.transaction_time).getTime();
            if (ms < startMs || ms >= endMs) return;
            if (!t.game_id) return;
            var b = byGame[t.game_id] || (byGame[t.game_id] = {
                game_id: t.game_id, game_name: t.game_name, plays: 0, tickets: 0, cash: 0
            });
            b.plays += 1;
            b.tickets += t.redemption_tickets || 0;
        });
        var games = Object.keys(byGame).map(function(id) { return byGame[id]; });
        var topByPlays   = games.slice().sort(function(a, b) { return b.plays - a.plays; }).slice(0, 10);
        var topByTickets = games.slice().sort(function(a, b) { return b.tickets - a.tickets; }).slice(0, 10);
        var topByCash    = games.slice().sort(function(a, b) { return b.cash - a.cash; }).slice(0, 10);

        // Pause-action breakdowns from the demo log
        var bySource = { cron: 0, manual: 0, override: 0, schedule: 0, watchdog: 0, expired_override: 0 };
        var byAction = { pause: 0, unpause: 0 };
        var successFail = { success: 0, fail: 0 };
        var byGroup = {};
        data.logs.forEach(function(l) {
            if (l.action !== 'pause' && l.action !== 'unpause') return;
            var ms = new Date(l.timestamp.replace(' ', 'T') + 'Z').getTime();
            if (ms < startMs || ms >= endMs) return;
            if (bySource[l.source] === undefined) bySource[l.source] = 0;
            bySource[l.source] += 1;
            byAction[l.action] = (byAction[l.action] || 0) + 1;
            if (l.success) successFail.success += 1;
            else successFail.fail += 1;
            if (l.group_name) {
                byGroup[l.group_name] = (byGroup[l.group_name] || 0) + 1;
            }
        });
        var topGroups = Object.keys(byGroup)
            .map(function(name) { return { name: name, actions: byGroup[name] }; })
            .sort(function(a, b) { return b.actions - a.actions; })
            .slice(0, 8);

        // Recent failures (most recent 10, log-wide)
        var recentFailures = data.logs
            .filter(function(l) { return l.success === 0 || l.success === false; })
            .slice(0, 10)
            .map(function(l) {
                return {
                    timestamp: l.timestamp,
                    source: l.source,
                    action: l.action,
                    group_name: l.group_name,
                    game_name: l.game_name,
                    error_message: l.error_message
                };
            });

        // Feed metadata
        var feedMin = null, feedMax = null;
        data.transactions.forEach(function(t) {
            if (!feedMin || t.transaction_time < feedMin) feedMin = t.transaction_time;
            if (!feedMax || t.transaction_time > feedMax) feedMax = t.transaction_time;
        });

        return {
            range: {
                key: rangeKey,
                timezone: data.settings.timezone || 'America/New_York',
                from: pad4(startD.getFullYear()) + '-' + pad(startD.getMonth() + 1) + '-' + pad(startD.getDate()),
                to:   pad4(now.getFullYear())    + '-' + pad(now.getMonth() + 1)    + '-' + pad(now.getDate()),
                from_iso: new Date(startMs).toISOString(),
                to_iso:   new Date(endMs).toISOString()
            },
            kpis: kpis,
            previous_kpis: previousKpis,
            fleet: fleet,
            charts: {
                plays_by_hour:    hourBuckets,
                tickets_by_hour:  ticketsByHour,
                plays_by_dow:     dowBuckets,
                tickets_by_dow:   new Array(7).fill(0),
                daily:            daily,
                top_games_plays:  topByPlays,
                top_games_tickets: topByTickets,
                top_games_cash:   topByCash,
                actions_by_source: bySource,
                actions_by_action: byAction,
                actions_success_fail: successFail,
                top_groups_actions: topGroups,
                top_failures: recentFailures
            },
            feed: {
                total_cached_transactions: data.transactions.length,
                earliest_transaction_at: feedMin,
                latest_transaction_at: feedMax,
                last_poll_at: data.lastPollAt
            },
            generated_at: new Date().toISOString()
        };
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function pad4(n) { return ('0000' + n).slice(-4); }

    // --- Route table ---
    // ---- Performance reporting engine (mirrors api/analytics.php perf*) ----
    var PERF_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var PERF_DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    // Arcade daypart curve (local hours 0-23) used to shape the single-day
    // hourly view. Overnight closed; afternoon/evening peak.
    var PERF_HOUR_WEIGHTS = [0,0,0,0,0,0,0,0,0,0,3,4,6,7,8,7,8,10,12,13,11,7,4,1];

    function perfPad(n) { return (n < 10 ? '0' : '') + n; }
    function perfDateStr(d) { return d.getFullYear() + '-' + perfPad(d.getMonth() + 1) + '-' + perfPad(d.getDate()); }
    function perfParseDate(s) { var p = String(s).split('-'); return new Date(+p[0], (+p[1]) - 1, +p[2]); }
    function perfAddDays(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
    function perfLabelDate(d) { return PERF_MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
    function perfHourLabel(h) { var s = h < 12 ? 'a' : 'p'; var h12 = h % 12; if (h12 === 0) h12 = 12; return h12 + s; }
    function perfTodayStr() { var n = new Date(); return perfDateStr(new Date(n.getFullYear(), n.getMonth(), n.getDate())); }

    function perfResolveWindow(path) {
        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var range = getQuery(path, 'range') || 'week';
        if (['day','week','month','year','custom'].indexOf(range) === -1) range = 'week';
        var offset = parseInt(getQuery(path, 'offset') || '0', 10);
        if (isNaN(offset)) offset = 0;
        offset = Math.max(-1200, Math.min(1200, offset));
        var anchorQ = getQuery(path, 'anchor');
        var base = (anchorQ && /^\d{4}-\d{2}-\d{2}$/.test(anchorQ)) ? perfParseDate(anchorQ) : new Date(today);

        var start, endExcl, gran = 'day', label, prevStart, prevEndExcl;
        if (range === 'custom') {
            var fromQ = getQuery(path, 'from'), toQ = getQuery(path, 'to');
            if (!/^\d{4}-\d{2}-\d{2}$/.test(fromQ || '') || !/^\d{4}-\d{2}-\d{2}$/.test(toQ || ''))
                throw err(422, 'Custom range requires from/to in YYYY-MM-DD format.');
            start = perfParseDate(fromQ);
            var endIncl = perfParseDate(toQ);
            endExcl = perfAddDays(endIncl, 1);
            if (endExcl <= start) throw err(422, 'Custom range "to" must be on or after "from".');
            var spanDays = Math.round((endExcl - start) / 86400000);
            if (spanDays > 1830) throw err(422, 'Custom range is too large (max ~5 years).');
            gran = spanDays <= 1 ? 'hour' : (spanDays <= 92 ? 'day' : 'month');
            label = perfLabelDate(start) + ' – ' + perfLabelDate(endIncl);
            prevStart = perfAddDays(start, -spanDays); prevEndExcl = new Date(start);
        } else if (range === 'day') {
            var dd = perfAddDays(base, offset);
            start = new Date(dd); endExcl = perfAddDays(dd, 1); gran = 'hour';
            label = PERF_DAYS[dd.getDay()] + ', ' + perfLabelDate(dd);
            prevStart = perfAddDays(start, -1); prevEndExcl = new Date(start);
        } else if (range === 'week') {
            var ws = perfAddDays(base, -base.getDay() + offset * 7);
            start = ws; endExcl = perfAddDays(ws, 7); gran = 'day';
            label = perfLabelDate(start) + ' – ' + perfLabelDate(perfAddDays(endExcl, -1));
            prevStart = perfAddDays(start, -7); prevEndExcl = new Date(start);
        } else if (range === 'month') {
            var ms = new Date(base.getFullYear(), base.getMonth() + offset, 1);
            start = ms; endExcl = new Date(base.getFullYear(), base.getMonth() + offset + 1, 1); gran = 'day';
            label = PERF_MONTHS[ms.getMonth()] + ' ' + ms.getFullYear();
            prevStart = new Date(base.getFullYear(), base.getMonth() + offset - 1, 1); prevEndExcl = new Date(ms);
        } else { // year
            var ys = new Date(base.getFullYear() + offset, 0, 1);
            start = ys; endExcl = new Date(base.getFullYear() + offset + 1, 0, 1); gran = 'month';
            label = String(ys.getFullYear());
            prevStart = new Date(base.getFullYear() + offset - 1, 0, 1); prevEndExcl = new Date(ys);
        }
        return {
            range: range, offset: offset, granularity: gran, label: label,
            start: start, endExcl: endExcl,
            from: perfDateStr(start), to: perfDateStr(perfAddDays(endExcl, -1)),
            prevStart: prevStart, prevEndExcl: prevEndExcl,
            prev_from: perfDateStr(prevStart), prev_to: perfDateStr(perfAddDays(prevEndExcl, -1))
        };
    }

    function perfRowsInWindow(fromStr, toInclStr) {
        var today = perfTodayStr();
        if (toInclStr > today) toInclStr = today;
        var out = [], rows = data.perfDailyStats;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            if (r.stat_date >= fromStr && r.stat_date <= toInclStr) out.push(r);
        }
        return out;
    }

    function perfPerGame(rows) {
        var m = {};
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i], gid = r.game_id;
            if (!m[gid]) m[gid] = { plays: 0, tickets: 0, cash: 0, name: r.game_name };
            m[gid].plays += r.plays; m[gid].tickets += r.tickets; m[gid].cash += r.cash;
        }
        return m;
    }

    function perfVenueTotals(perGame) {
        var plays = 0, tickets = 0, cash = 0, n = 0;
        for (var k in perGame) {
            if (!perGame.hasOwnProperty(k)) continue;
            plays += perGame[k].plays; tickets += perGame[k].tickets; cash += perGame[k].cash; n++;
        }
        return {
            plays: plays, tickets: Math.round(tickets), cash: Math.round(cash * 100) / 100,
            points: 0, active_games: n,
            avg_tickets_per_play: plays > 0 ? Math.round(tickets / plays * 100) / 100 : 0,
            avg_cash_per_play: plays > 0 ? Math.round(cash / plays * 100) / 100 : 0
        };
    }

    function perfSeries(win, gid) {
        var today = perfTodayStr();
        var pts = [];
        if (win.granularity === 'hour') {
            var rows = perfRowsInWindow(win.from, win.to);
            var tot = { plays: 0, tickets: 0, cash: 0 };
            for (var i = 0; i < rows.length; i++) {
                if (gid && rows[i].game_id !== gid) continue;
                tot.plays += rows[i].plays; tot.tickets += rows[i].tickets; tot.cash += rows[i].cash;
            }
            var wsum = 0; for (var h = 0; h < 24; h++) wsum += PERF_HOUR_WEIGHTS[h];
            for (var h2 = 0; h2 < 24; h2++) {
                var f = wsum > 0 ? PERF_HOUR_WEIGHTS[h2] / wsum : 0;
                pts.push({ label: perfHourLabel(h2), plays: Math.round(tot.plays * f), tickets: Math.round(tot.tickets * f), cash: Math.round(tot.cash * f * 100) / 100 });
            }
            return { granularity: 'hour', points: pts };
        }
        var rowsW = perfRowsInWindow(win.from, win.to);
        var byDate = {};
        for (var j = 0; j < rowsW.length; j++) {
            var rw = rowsW[j];
            if (gid && rw.game_id !== gid) continue;
            if (!byDate[rw.stat_date]) byDate[rw.stat_date] = { plays: 0, tickets: 0, cash: 0 };
            byDate[rw.stat_date].plays += rw.plays; byDate[rw.stat_date].tickets += rw.tickets; byDate[rw.stat_date].cash += rw.cash;
        }
        if (win.granularity === 'day') {
            var cur = new Date(win.start), endE = new Date(win.endExcl);
            while (cur < endE) {
                var ds = perfDateStr(cur);
                if (ds <= today) {
                    var b = byDate[ds] || { plays: 0, tickets: 0, cash: 0 };
                    pts.push({ label: PERF_MONTHS[cur.getMonth()] + ' ' + cur.getDate(), date: ds, plays: b.plays, tickets: Math.round(b.tickets), cash: Math.round(b.cash * 100) / 100 });
                }
                cur = perfAddDays(cur, 1);
            }
        } else {
            var mc = new Date(win.start.getFullYear(), win.start.getMonth(), 1), endM = new Date(win.endExcl);
            while (mc < endM) {
                var mEnd = new Date(mc.getFullYear(), mc.getMonth() + 1, 1);
                if (perfDateStr(mc) <= today) {
                    var p = 0, t = 0, c = 0, dc = new Date(mc);
                    while (dc < mEnd) { var dds = perfDateStr(dc); if (dds <= today) { var bb = byDate[dds]; if (bb) { p += bb.plays; t += bb.tickets; c += bb.cash; } } dc = perfAddDays(dc, 1); }
                    pts.push({ label: PERF_MONTHS[mc.getMonth()], month: mc.getFullYear() + '-' + perfPad(mc.getMonth() + 1), plays: p, tickets: Math.round(t), cash: Math.round(c * 100) / 100 });
                }
                mc = new Date(mc.getFullYear(), mc.getMonth() + 1, 1);
            }
        }
        return { granularity: win.granularity, points: pts };
    }

    function perfRangeMeta(win) {
        return {
            range: win.range, offset: win.offset, granularity: win.granularity, label: win.label,
            from: win.from, to: win.to, prev_from: win.prev_from, prev_to: win.prev_to, timezone: 'America/New_York'
        };
    }

    function perfScrub(payload) {
        ['totals','previous_totals'].forEach(function(k) {
            if (payload[k]) { if ('cash' in payload[k]) payload[k].cash = 0; if ('avg_cash_per_play' in payload[k]) payload[k].avg_cash_per_play = 0; }
        });
        if (payload.games) payload.games.forEach(function(g) { if ('cash' in g) g.cash = 0; if ('prev_cash' in g) g.prev_cash = 0; });
        if (payload.series && payload.series.points) payload.series.points.forEach(function(p) { if ('cash' in p) p.cash = 0; });
    }

    function perfGamesLeaderboard(path) {
        var win = perfResolveWindow(path);
        var cur = perfPerGame(perfRowsInWindow(win.from, win.to));
        var prev = perfPerGame(perfRowsInWindow(win.prev_from, win.prev_to));

        var search = (getQuery(path, 'search') || '').toLowerCase();
        var sort = getQuery(path, 'sort') || 'tickets';
        if (['tickets','plays','cash','name'].indexOf(sort) === -1) sort = 'tickets';
        var hideMoney = !canAccess('view_revenue');
        if (hideMoney && sort === 'cash') sort = 'tickets';
        var page = Math.max(1, parseInt(getQuery(path, 'page') || '1', 10));
        var pageSize = Math.max(1, Math.min(200, parseInt(getQuery(path, 'page_size') || '25', 10)));

        var byId = {};
        data.games.forEach(function(g) { byId[g.game_id] = { name: g.game_name, status: g.operation_status }; });
        var ids = Object.keys(byId);
        Object.keys(cur).forEach(function(id) { if (!byId[id]) { byId[id] = { name: cur[id].name, status: null }; ids.push(id); } });

        var rows = ids.map(function(id) {
            var c = cur[id] || { plays: 0, tickets: 0, cash: 0 };
            var p = prev[id] || { plays: 0, tickets: 0, cash: 0 };
            return {
                game_id: id, game_name: byId[id].name || id, status: byId[id].status || null,
                plays: c.plays, tickets: Math.round(c.tickets), cash: Math.round(c.cash * 100) / 100,
                points: 0, avg_tickets_per_play: c.plays > 0 ? Math.round(c.tickets / c.plays * 100) / 100 : 0,
                prev_plays: p.plays, prev_tickets: Math.round(p.tickets), prev_cash: Math.round(p.cash * 100) / 100
            };
        });
        if (search) rows = rows.filter(function(r) { return r.game_name.toLowerCase().indexOf(search) !== -1 || r.game_id.toLowerCase().indexOf(search) !== -1; });
        rows.sort(function(a, b) {
            if (sort === 'name') return a.game_name.localeCompare(b.game_name);
            var key = sort === 'plays' ? 'plays' : (sort === 'cash' ? 'cash' : 'tickets');
            if (a[key] === b[key]) return b.plays - a.plays;
            return b[key] - a[key];
        });
        var total = rows.length;
        var pageRows = rows.slice((page - 1) * pageSize, (page - 1) * pageSize + pageSize);

        var payload = {
            range: perfRangeMeta(win),
            totals: perfVenueTotals(cur),
            previous_totals: perfVenueTotals(prev),
            series: perfSeries(win, null),
            games: pageRows,
            sort: sort, search: getQuery(path, 'search') || '',
            pagination: { page: page, page_size: pageSize, total: total, total_pages: Math.ceil(Math.max(1, total) / pageSize) },
            hide_money: hideMoney
        };
        if (hideMoney) perfScrub(payload);
        return payload;
    }

    function perfGameDetail(path) {
        var gid = getQuery(path, 'game_id');
        if (!gid) throw err(422, 'game_id is required.');
        var win = perfResolveWindow(path);
        var cur = perfPerGame(perfRowsInWindow(win.from, win.to));
        var prev = perfPerGame(perfRowsInWindow(win.prev_from, win.prev_to));
        var c = cur[gid] || { plays: 0, tickets: 0, cash: 0 };
        var p = prev[gid] || { plays: 0, tickets: 0, cash: 0 };
        var gmeta = data.games.filter(function(g) { return g.game_id === gid; })[0];
        var hideMoney = !canAccess('view_revenue');
        function kpi(x) {
            return {
                plays: x.plays, tickets: Math.round(x.tickets), cash: Math.round(x.cash * 100) / 100, points: 0,
                avg_tickets_per_play: x.plays > 0 ? Math.round(x.tickets / x.plays * 100) / 100 : 0,
                avg_cash_per_play: x.plays > 0 ? Math.round(x.cash / x.plays * 100) / 100 : 0
            };
        }
        var payload = {
            game: { game_id: gid, game_name: (gmeta ? gmeta.game_name : (c.name || gid)), status: gmeta ? gmeta.operation_status : null },
            range: perfRangeMeta(win),
            totals: kpi(c), previous_totals: kpi(p),
            series: perfSeries(win, gid),
            hide_money: hideMoney
        };
        if (hideMoney) perfScrub(payload);
        return payload;
    }

    var routes = [
        // --- Auth ---
        ['POST', /^auth\/login$/, function(_p, body) {
            if (!body || !body.username || !body.password) {
                throw err(400, 'Username and password are required.');
            }
            // Match against the seeded users so the demo logs in as the
            // role implied by the username (admin / morgan / casey). Any
            // unknown username falls back to an admin session so unscripted
            // visitors still see the full app.
            var match = data.users.find(function(u) {
                return u.username === body.username && u.is_active;
            });
            var u = match || data.users[0];
            return {
                user: {
                    id: u.id,
                    username: u.username,
                    display_name: u.display_name,
                    role: normalizeRole(u.role)
                },
                csrf_token: 'demo-csrf-token-' + Date.now()
            };
        }],
        ['POST', /^auth\/logout$/, function() {
            return { success: true };
        }],

        // --- Health & capabilities ---
        ['GET', /^health$/, function() { return data.buildHealth(); }],
        ['GET', /^capabilities$/, function() { return clone(data.capabilities); }],

        // --- Settings ---
        ['GET', /^settings$/, function() {
            requireAccess('settings');
            return { base_url: data.settings.base_url, username: data.settings.username,
                     password: '', api_key: data.settings.api_key, timezone: data.settings.timezone };
        }],
        ['PUT', /^settings$/, function(_p, body) {
            requireAccess('settings');
            Object.assign(data.settings, body || {});
            return { success: true };
        }],
        ['POST', /^settings\/test$/, function() {
            requireAccess('settings');
            return {
                success: true,
                system_name: 'Castle Fun Center (Demo)',
                game_count: data.games.length,
                category_count: data.categories.length,
                supports_operation_status: true
            };
        }],

        // --- Games ---
        ['GET', /^games$/, function() {
            return { games: clone(data.games), last_synced: data.lastSync.games };
        }],
        ['POST', /^games\/sync$/, function() {
            requireAccess('manual_control');
            data.lastSync.games = data.sqlUtc(new Date());
            return { success: true, synced: data.games.length };
        }],
        ['GET', /^games\/categories$/, function() {
            return { categories: clone(data.categories) };
        }],
        ['GET', /^games\/transactions\/recent/, function(path) {
            requireAccess('analytics');
            var limit = parseInt(getQuery(path, 'limit') || '30', 10);
            return {
                transactions: clone(data.transactions.slice(0, limit)),
                last_poll_at: data.lastPollAt,
                total_cached: data.transactions.length
            };
        }],
        ['GET', /^games\/transactions\/top/, function(path) {
            requireAccess('analytics');
            var window = getQuery(path, 'window') || 'today';
            var sort = getQuery(path, 'sort') || 'tickets';
            var limit = parseInt(getQuery(path, 'limit') || '5', 10);
            return {
                window: window,
                sort: sort,
                top: data.topGames(window, sort).slice(0, limit)
            };
        }],
        ['GET', /^games\/transactions\/stats/, function() {
            requireAccess('analytics');
            return data.ticketStats();
        }],
        ['POST', /^games\/transactions\/poll$/, function() {
            requireAccess('analytics');
            data.lastPollAt = data.isoUtc(new Date());
            // Sometimes pretend we fetched a few new plays
            var fetched = Math.floor(Math.random() * 4);
            return { fetched: fetched };
        }],
        ['GET', /^games\/[^\/]+$/, function(path) {
            var id = path.split('/')[1];
            var g = data.gameDetails[id];
            if (!g) throw err(404, 'Game not found');
            // Mirror the live operation_status from the games list
            var live = findGame(id);
            return Object.assign({}, clone(g), { operationStatus: live ? live.operation_status : g.operationStatus });
        }],
        ['POST', /^games\/[^\/]+\/action$/, function(path, body) {
            requireAccess('manual_control');
            return { success: true, actionId: body && body.actionId };
        }],
        ['PATCH', /^games$/, function(_p, body) {
            // Bulk operationStatus PATCH passthrough — same JSON Patch shape as
            // the live API. Each game maps to an array of replace ops on
            // /operationStatus. The simulator already skips outOfService games
            // in its scheduler tick, so out-of-service is naturally sticky here.
            var allowed = ['enabled', 'paused', 'outOfService'];
            var gamesPayload = (body && body.games) || {};
            var updated = [];
            var errors = {};
            Object.keys(gamesPayload).forEach(function(gid) {
                var patches = gamesPayload[gid] || [];
                patches.forEach(function(patch) {
                    if (!patch || patch.op !== 'replace' || patch.path !== '/operationStatus') return;
                    if (allowed.indexOf(patch.value) === -1) {
                        errors[gid] = 'Invalid operationStatus: ' + patch.value;
                        return;
                    }
                    var g = findGame(gid);
                    if (!g) {
                        errors[gid] = 'Game not found';
                        return;
                    }
                    g.operation_status = patch.value;
                    updated.push({ id: gid, operationStatus: patch.value });
                });
            });
            return { games: updated, errors: errors };
        }],

        // --- Cards ---
        ['GET', /^cards\/[^\/]+\/transactions/, function(path) {
            requireAccess('cards');
            var skip = parseInt(getQuery(path, 'skip') || '0', 10);
            var take = parseInt(getQuery(path, 'take') || '25', 10);
            var slice = data.sampleCardTransactions.slice(skip, skip + take);
            return {
                transactions: clone(slice),
                totalCount: data.sampleCardTransactions.length,
                skipped: skip
            };
        }],
        ['GET', /^cards\/[^\/]+\/pin/, function(path) {
            requireAccess('cards');
            var num = path.split('/')[1];
            if (num === '80009100') {
                var validate = getQuery(path, 'validate');
                return { isPinValid: validate === '1234' };
            }
            throw err(404, 'No PIN');
        }],
        ['GET', /^cards\/[^\/]+$/, function(path) {
            requireAccess('cards');
            var num = decodeURIComponent(path.split('/')[1]);
            var card = data.sampleCards[num];
            if (!card) throw err(404, 'Card not found');
            return clone(card);
        }],

        // --- Groups ---
        ['GET', /^groups$/, function() {
            return { groups: data.groups.map(decorateGroup) };
        }],
        ['POST', /^groups$/, function(_p, body) {
            requireAccess('groups_manage');
            var newId = Math.max.apply(Math, data.groups.map(function(g) { return g.id; })) + 1;
            var g = {
                id: newId,
                name: body.name,
                description: body.description || '',
                is_active: body.is_active ? 1 : 0,
                game_ids: body.game_ids || [],
                kiosk_ids: body.kiosk_ids || [],
                category_ids: body.category_ids || [],
                schedule_count: 0
            };
            data.groups.push(g);
            return { success: true, id: newId };
        }],
        ['GET', /^groups\/\d+$/, function(path) {
            var g = findGroup(path.split('/')[1]);
            if (!g) throw err(404, 'Group not found');
            return getGroupDetail(g);
        }],
        ['PUT', /^groups\/\d+$/, function(path, body) {
            requireAccess('groups_manage');
            var g = findGroup(path.split('/')[1]);
            if (!g) throw err(404, 'Group not found');
            Object.assign(g, {
                name: body.name,
                description: body.description || '',
                is_active: body.is_active ? 1 : 0,
                game_ids: body.game_ids || [],
                kiosk_ids: body.kiosk_ids || [],
                category_ids: body.category_ids || []
            });
            return { success: true };
        }],
        ['DELETE', /^groups\/\d+$/, function(path) {
            requireAccess('groups_manage');
            var id = parseInt(path.split('/')[1], 10);
            var idx = data.groups.findIndex(function(g) { return g.id === id; });
            if (idx === -1) throw err(404, 'Group not found');
            data.groups.splice(idx, 1);
            data.schedules = data.schedules.filter(function(s) { return s.pause_group_id !== id; });
            ['active','upcoming','expired'].forEach(function(k) {
                data.overrides[k] = data.overrides[k].filter(function(o) { return o.pause_group_id !== id; });
            });
            return { success: true };
        }],
        ['POST', /^groups\/\d+\/pause$/, function(path) {
            requireAccess('manual_control');
            var g = findGroup(path.split('/')[1]);
            if (!g) throw err(404, 'Group not found');
            return pauseGroup(g, 'pause');
        }],
        ['POST', /^groups\/\d+\/unpause$/, function(path) {
            requireAccess('manual_control');
            var g = findGroup(path.split('/')[1]);
            if (!g) throw err(404, 'Group not found');
            return pauseGroup(g, 'unpause');
        }],
        ['POST', /^groups\/\d+\/clear-manual-override$/, function(path) {
            requireAccess('manual_control');
            var g = findGroup(path.split('/')[1]);
            if (!g) throw err(404, 'Group not found');
            return clearManual(g);
        }],
        ['POST', /^groups\/\d+\/enforce$/, function(path) {
            requireAccess('manual_control');
            var g = findGroup(path.split('/')[1]);
            if (!g) throw err(404, 'Group not found');
            return { success: true, enforced: { changed: [] } };
        }],

        // --- Kiosks ---
        ['GET', /^kiosks$/, function() {
            return { kiosks: clone(data.kiosks), last_synced: data.lastSync.kiosks };
        }],
        ['POST', /^kiosks\/sync$/, function() {
            requireAccess('manual_control');
            data.lastSync.kiosks = data.sqlUtc(new Date());
            return { success: true };
        }],
        ['POST', /^kiosks\/[^\/]+\/pause$/, function(path) {
            requireAccess('manual_control');
            var k = findKiosk(path.split('/')[1]);
            if (!k) throw err(404, 'Kiosk not found');
            k.operationStatus = 'paused';
            return { success: true };
        }],
        ['POST', /^kiosks\/[^\/]+\/unpause$/, function(path) {
            requireAccess('manual_control');
            var k = findKiosk(path.split('/')[1]);
            if (!k) throw err(404, 'Kiosk not found');
            k.operationStatus = 'enabled';
            return { success: true };
        }],
        ['POST', /^kiosks\/[^\/]+\/out-of-service$/, function(path) {
            requireAccess('manual_control');
            var k = findKiosk(path.split('/')[1]);
            if (!k) throw err(404, 'Kiosk not found');
            k.operationStatus = 'outOfService';
            return { success: true };
        }],
        ['POST', /^kiosks\/[^\/]+\/action$/, function() {
            requireAccess('manual_control');
            return { success: true };
        }],

        // --- Schedules ---
        ['GET', /^schedules$/, function() {
            return { schedules: clone(data.schedules) };
        }],
        ['POST', /^schedules$/, function(_p, body) {
            requireAccess('schedules_manage');
            var days = body.days_of_week || [];
            var nextId = Math.max.apply(Math, data.schedules.map(function(s) { return s.id; })) + 1;
            var groupName = (findGroup(body.pause_group_id) || {}).name || ('Group #' + body.pause_group_id);
            days.forEach(function(d) {
                data.schedules.push({
                    id: nextId++,
                    pause_group_id: body.pause_group_id,
                    group_name: groupName,
                    day_of_week: d,
                    start_time: body.start_time,
                    end_time: body.end_time,
                    is_active: body.is_active ? 1 : 0
                });
            });
            return { success: true };
        }],
        ['PUT', /^schedules\/\d+$/, function(path, body) {
            requireAccess('schedules_manage');
            var s = findSchedule(path.split('/')[1]);
            if (!s) throw err(404, 'Schedule not found');
            Object.assign(s, body);
            return { success: true };
        }],
        ['DELETE', /^schedules\/\d+$/, function(path) {
            requireAccess('schedules_manage');
            var id = parseInt(path.split('/')[1], 10);
            var idx = data.schedules.findIndex(function(s) { return s.id === id; });
            if (idx === -1) throw err(404, 'Schedule not found');
            data.schedules.splice(idx, 1);
            return { success: true };
        }],

        // --- Overrides ---
        ['GET', /^overrides$/, function() {
            return clone(data.overrides);
        }],
        ['POST', /^overrides$/, function(_p, body) {
            requireAccess('overrides_manage');
            var newId = 1000 + Math.floor(Math.random() * 9000);
            var groupName = (findGroup(body.pause_group_id) || {}).name || ('Group #' + body.pause_group_id);
            var newOvr = {
                id: newId,
                pause_group_id: body.pause_group_id,
                group_name: groupName,
                name: body.name,
                action: body.action,
                start_datetime: body.start_datetime,
                end_datetime: body.end_datetime,
                created_by_name: 'Demo Operator'
            };
            // Bucket by start time vs now
            var startMs = new Date(body.start_datetime.replace(' ', 'T') + 'Z').getTime();
            var endMs = new Date(body.end_datetime.replace(' ', 'T') + 'Z').getTime();
            var nowMs = Date.now();
            if (endMs < nowMs) data.overrides.expired.unshift(newOvr);
            else if (startMs > nowMs) data.overrides.upcoming.unshift(newOvr);
            else data.overrides.active.unshift(newOvr);
            return { success: true, id: newId };
        }],
        ['DELETE', /^overrides\/\d+$/, function(path) {
            requireAccess('overrides_manage');
            var id = parseInt(path.split('/')[1], 10);
            ['active','upcoming','expired'].forEach(function(k) {
                data.overrides[k] = data.overrides[k].filter(function(o) { return o.id !== id; });
            });
            return { success: true };
        }],

        // --- Logs ---
        ['GET', /^logs/, function(path) {
            var page = parseInt(getQuery(path, 'page') || '1', 10);
            var per = parseInt(getQuery(path, 'per_page') || '50', 10);
            var src = getQuery(path, 'source');
            var act = getQuery(path, 'action');
            var success = getQuery(path, 'success');
            var from = getQuery(path, 'from');
            var to = getQuery(path, 'to');

            var filtered = data.logs.slice();
            if (src) filtered = filtered.filter(function(l) { return l.source === src; });
            if (act) filtered = filtered.filter(function(l) { return l.action === act; });
            if (success !== null && success !== undefined && success !== '') {
                filtered = filtered.filter(function(l) { return String(l.success) === String(success); });
            }
            if (from) {
                var fromMs = new Date(from + 'T00:00:00Z').getTime();
                filtered = filtered.filter(function(l) { return new Date(l.timestamp.replace(' ', 'T') + 'Z').getTime() >= fromMs; });
            }
            if (to) {
                var toMs = new Date(to + 'T23:59:59Z').getTime();
                filtered = filtered.filter(function(l) { return new Date(l.timestamp.replace(' ', 'T') + 'Z').getTime() <= toMs; });
            }
            var total = filtered.length;
            var start = (page - 1) * per;
            return {
                logs: clone(filtered.slice(start, start + per)),
                total: total,
                page: page,
                per_page: per
            };
        }],

        // --- Analytics ---
        ['GET', /^analytics\/overview/, function(path) {
            requireAccess('analytics');
            var rangeKey = getQuery(path, 'range') || '7d';
            var fromQ = getQuery(path, 'from');
            var toQ = getQuery(path, 'to');
            var payload = buildAnalyticsOverview(rangeKey, fromQ, toQ);
            // Match api/analytics.php — strip monetary figures for techs.
            payload.hide_money = !canAccess('view_revenue');
            if (payload.hide_money) {
                if (payload.kpis) {
                    payload.kpis.cash = 0;
                    payload.kpis.avg_cash_per_play = 0;
                }
                if (payload.previous_kpis) {
                    payload.previous_kpis.cash = 0;
                    payload.previous_kpis.avg_cash_per_play = 0;
                }
                if (payload.charts) {
                    payload.charts.top_games_cash = [];
                }
            }
            return payload;
        }],
        ['GET', /^analytics\/games/, function(path) {
            requireAccess('analytics');
            return perfGamesLeaderboard(path);
        }],
        ['GET', /^analytics\/game\b/, function(path) {
            requireAccess('analytics');
            return perfGameDetail(path);
        }],

        // --- Roles (custom RBAC) ---
        ['GET', /^roles$/, function() {
            requireAccess('users');
            return buildRolesPayload();
        }],
        ['POST', /^roles$/, function(_p, body) {
            requireAccess('users');
            if (currentRole() !== 'admin') throw err(403, 'Only an administrator can manage roles.');
            if (!body || !body.name) throw err(422, 'Role name is required.');
            var slug = String(body.slug || body.name).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            if (!slug) throw err(422, 'Role slug must contain letters or numbers.');
            if (findRole(slug)) throw err(422, 'A role with slug "' + slug + '" already exists.');
            rolesStore.push({
                slug: slug, name: body.name, description: body.description || '',
                is_system: 0,
                permissions: (body.permissions || []).filter(function(p) { return PERMISSIONS[p]; })
            });
            return buildRolesPayload();
        }],
        ['PUT', /^roles\/[a-z0-9_]+$/, function(path, body) {
            requireAccess('users');
            if (currentRole() !== 'admin') throw err(403, 'Only an administrator can manage roles.');
            var slug = stripQuery(path).split('/')[1];
            var r = findRole(slug);
            if (!r) throw err(404, 'Role not found');
            if (slug === 'admin') throw err(422, 'The admin role cannot be modified.');
            if (body.name !== undefined) r.name = body.name;
            if (body.description !== undefined) r.description = body.description;
            if (body.permissions !== undefined) {
                r.permissions = (body.permissions || []).filter(function(p) { return PERMISSIONS[p]; });
            }
            return buildRolesPayload();
        }],
        ['DELETE', /^roles\/[a-z0-9_]+$/, function(path) {
            requireAccess('users');
            if (currentRole() !== 'admin') throw err(403, 'Only an administrator can manage roles.');
            var slug = stripQuery(path).split('/')[1];
            var r = findRole(slug);
            if (!r) throw err(404, 'Role not found');
            if (r.is_system) throw err(422, 'System roles (admin, manager, tech) cannot be deleted.');
            var inUse = data.users.filter(function(u) { return normalizeRole(u.role) === slug; }).length;
            if (inUse > 0) throw err(422, 'Role "' + r.name + '" is assigned to ' + inUse + ' user(s). Reassign them first.');
            rolesStore = rolesStore.filter(function(x) { return x.slug !== slug; });
            return buildRolesPayload();
        }],

        // --- Users ---
        // Mirrors api/users.php: GET returns the list plus the role catalog
        // and the caller's role; POST/PUT enforce the same escalation guards
        // (only admins can grant the admin role; the only remaining admin
        // can't be demoted/deactivated; you can't deactivate yourself).
        ['GET', /^users$/, function() {
            requireAccess('users');
            var callerRole = currentRole();
            return {
                users: clone(data.users).map(function(u) {
                    u.role = normalizeRole(u.role);
                    return u;
                }),
                roles: rolesStore.map(function(r) { return r.slug; }),
                role_catalog: rolesStore.map(function(r) {
                    return {
                        slug: r.slug, name: r.name, description: r.description,
                        is_system: r.is_system, assignable: canAssignRole(callerRole, r.slug)
                    };
                }),
                current_role: callerRole
            };
        }],
        ['POST', /^users$/, function(_p, body) {
            requireAccess('users');
            var role = normalizeRole(body && body.role);
            if (!findRole(role)) throw err(422, 'Unknown role: ' + role);
            if (!canAssignRole(currentRole(), role)) {
                throw err(422, 'You cannot assign a role with permissions beyond your own.');
            }
            if (!body || !body.username) throw err(422, 'Username is required.');
            if (data.users.some(function(u) { return u.username === body.username; })) {
                throw err(422, 'Username already exists.');
            }
            if (!body.password || String(body.password).length < 8) {
                throw err(422, 'Password must be at least 8 characters.');
            }
            var newId = Math.max.apply(Math, data.users.map(function(u) { return u.id; })) + 1;
            data.users.push({
                id: newId,
                username: body.username,
                display_name: body.display_name || body.username,
                role: role,
                is_active: 1,
                created_at: data.sqlUtc(new Date())
            });
            return { success: true, id: newId };
        }],
        ['PUT', /^users\/\d+$/, function(path, body) {
            requireAccess('users');
            var u = findUser(stripQuery(path).split('/')[1]);
            if (!u) throw err(404, 'User not found');
            var caller = currentUserObject();
            var isSelf = caller && caller.id === u.id;
            var callerIsAdmin = currentRole() === 'admin';
            var existingRole = normalizeRole(u.role);

            if (body.is_active !== undefined && !body.is_active && isSelf) {
                throw err(422, 'You cannot deactivate your own account.');
            }
            var nextRole = existingRole;
            if (body.role !== undefined) {
                nextRole = normalizeRole(body.role);
                if (!callerIsAdmin && nextRole !== existingRole) {
                    throw err(422, 'Only an administrator can change a user\'s role.');
                }
                if (!callerIsAdmin && nextRole === 'admin') {
                    throw err(422, 'Only an administrator can grant the admin role.');
                }
                if (isSelf && existingRole === 'admin' && nextRole !== 'admin') {
                    throw err(422, 'You cannot remove your own admin role.');
                }
                if (existingRole === 'admin' && nextRole !== 'admin' && countActiveAdmins(u.id) === 0) {
                    throw err(422, 'Cannot demote the only remaining administrator.');
                }
            }
            if (body.is_active !== undefined && !body.is_active
                && existingRole === 'admin' && u.is_active && countActiveAdmins(u.id) === 0) {
                throw err(422, 'Cannot deactivate the only remaining administrator.');
            }

            if (body.display_name !== undefined) u.display_name = body.display_name;
            if (body.is_active !== undefined) u.is_active = body.is_active ? 1 : 0;
            u.role = nextRole;
            return { success: true };
        }],
        ['DELETE', /^users\/\d+$/, function(path) {
            requireAccess('users');
            if (currentRole() !== 'admin') {
                throw err(403, 'Only an administrator can delete user accounts.');
            }
            var u = findUser(stripQuery(path).split('/')[1]);
            if (!u) throw err(404, 'User not found');
            var caller = currentUserObject();
            if (caller && caller.id === u.id) {
                throw err(422, 'You cannot delete your own account.');
            }
            if (normalizeRole(u.role) === 'admin' && countActiveAdmins(u.id) === 0) {
                throw err(422, 'Cannot delete the only remaining administrator.');
            }
            data.users = data.users.filter(function(x) { return x.id !== u.id; });
            return { success: true };
        }]
    ];

    function getQuery(path, key) {
        var qIdx = path.indexOf('?');
        if (qIdx === -1) return null;
        var qs = path.substring(qIdx + 1);
        var parts = qs.split('&');
        for (var i = 0; i < parts.length; i++) {
            var kv = parts[i].split('=');
            if (decodeURIComponent(kv[0]) === key) return decodeURIComponent(kv[1] || '');
        }
        return null;
    }

    function stripQuery(path) {
        var i = path.indexOf('?');
        return i === -1 ? path : path.substring(0, i);
    }

    function dispatch(method, path, body) {
        var bare = stripQuery(path);
        for (var i = 0; i < routes.length; i++) {
            var r = routes[i];
            if (r[0] === method && r[1].test(bare)) {
                return r[2](path, body);
            }
        }
        throw err(404, 'No mock route for ' + method + ' ' + path);
    }

    // Public API matching api.js
    window.API = {
        basePath: '',
        csrfToken: 'demo',
        init: function() {},
        setCsrfToken: function() {},

        request: async function(method, path, body) {
            await delay();
            try {
                var result = dispatch(method, path, body);
                return clone(result);
            } catch (e) {
                if (!e.status) e.status = 500;
                throw e;
            }
        },

        get: function(path) { return this.request('GET', path); },
        post: function(path, body) { return this.request('POST', path, body); },
        put: function(path, body) { return this.request('PUT', path, body); },
        patch: function(path, body) { return this.request('PATCH', path, body); },
        del: function(path) { return this.request('DELETE', path); }
    };

    // ApiError shim — login.js etc. don't import it but check `err.status`.
    window.ApiError = function ApiError(status, message) {
        var e = new Error(message);
        e.status = status;
        return e;
    };
})();
