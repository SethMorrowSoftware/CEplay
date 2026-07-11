<?php
/**
 * API: Proxy game and category data from CenterEdge.
 * GET    /api/games                    — Cached games list (auto-syncs if empty)
 * GET    /api/games/categories         — Live categories from CenterEdge
 * GET    /api/games/transactions/recent — Recent plays (cached locally)
 * GET    /api/games/transactions/top    — Top-games aggregation (cached locally)
 * GET    /api/games/transactions/stats  — Per-game ticket/play stats across time windows
 * GET    /api/games/transactions/payout — Ticket payout % (tickets/points) per window
 * POST   /api/games/sync               — Force sync game states from CenterEdge
 * POST   /api/games/transactions/poll  — Force-poll the play feed (manual catch-up)
 * GET    /api/games/{id}               — Single game (live from CenterEdge)
 * POST   /api/games/{id}/action        — RPC perform-action (e.g. reboot)
 * PATCH  /api/games                    — Bulk operationStatus PATCH passthrough
 */

require_once __DIR__ . '/../lib/centeredge_client.php';
require_once __DIR__ . '/../lib/scheduler.php';
require_once __DIR__ . '/../lib/validator.php';

function handleGames(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $action = $parts[0] ?? '';
    $sub = $parts[1] ?? '';

    // Route the new sub-resources first so the catch-all `$action === '/{id}'`
    // dispatch below doesn't accidentally swallow them.
    // The /transactions/* family exposes ticket and cash totals — sales data
    // the 'tech' role is not permitted to see. Same gate as /api/analytics.
    if ($action === 'transactions') {
        Auth::requireAccess('analytics');
        // Cash fields are scrubbed for roles without view_revenue — same
        // policy as /api/analytics. (Previously these endpoints leaked
        // cash_amount / sum_cash to tech even though analytics scrubbed it.)
        $hideMoney = !Auth::hasPermission('view_revenue');
        if ($method === 'GET' && $sub === 'recent') {
            gamesRecentTransactions($hideMoney);
            return;
        }
        if ($method === 'GET' && $sub === 'top') {
            gamesTopTransactions($hideMoney);
            return;
        }
        if ($method === 'POST' && $sub === 'poll') {
            gamesPollTransactions();
            return;
        }
        if ($method === 'GET' && $sub === 'stats') {
            gamesTicketStats();
            return;
        }
        if ($method === 'GET' && $sub === 'payout') {
            gamesPayoutStats();
            return;
        }
    }

    // GET /api/games/{id}  and  POST /api/games/{id}/action
    // We treat any /api/games/<value> where value is not a reserved word as a
    // single-game lookup. Reserved: 'categories', 'sync', 'transactions'.
    $reservedActions = ['categories', 'sync', 'transactions', ''];
    if ($action !== '' && !in_array($action, $reservedActions, true)) {
        $gameId = $action;
        if ($method === 'GET' && $sub === '') {
            gamesGetOne($gameId);
            return;
        }
        if ($method === 'POST' && $sub === 'action') {
            Auth::requireAccess('manual_control');
            gamesPerformAction($gameId, $input);
            return;
        }
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed for this resource']);
        return;
    }

    if ($method === 'GET' && $action === '') {
        // Return games from cache
        $cached = DB::query(
            'SELECT game_id, game_name, operation_status, categories, last_synced_at
             FROM game_state_cache ORDER BY game_name ASC'
        );

        // Auto-sync if cache is empty
        if (empty($cached)) {
            try {
                $client = new CenterEdgeClient();
                if ($client->isConfigured()) {
                    $client->syncGamesToCache();
                    $cached = DB::query(
                        'SELECT game_id, game_name, operation_status, categories, last_synced_at
                         FROM game_state_cache ORDER BY game_name ASC'
                    );
                }
            } catch (Exception $e) {
                // Ignore sync errors, return empty
            }
        }

        $pendingRetries = Scheduler::getPendingRetriesByType('game');

        // Parse categories JSON for each game; attach pending retry status
        // so the UI can surface "retry 3/10" and the last error.
        $games = array_map(function ($g) use ($pendingRetries) {
            $g['categories'] = json_decode($g['categories'], true) ?: [];
            $g['pending_retry'] = $pendingRetries[$g['game_id']] ?? null;
            return $g;
        }, $cached);

        // Get last sync time
        $lastSync = DB::queryOne('SELECT MAX(last_synced_at) as last_synced FROM game_state_cache');

        echo json_encode([
            'games'       => $games,
            'total'       => count($games),
            'last_synced' => $lastSync['last_synced'] ?? null,
        ]);
        return;
    }

    if ($method === 'GET' && $action === 'categories') {
        // Categories rarely change, so served from the DB cache (6h TTL) with
        // an automatic refetch on miss. Operators can force a refresh via
        // POST /api/games/sync, which invalidates this cache below.
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        $categories = $client->getCategoriesCached();
        echo json_encode(['categories' => $categories]);
        return;
    }

    if ($method === 'PATCH' && $action === '') {
        // Patch game operation statuses via CenterEdge API
        Auth::requireAccess('manual_control');
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }

        $gamesPayload = $input['games'] ?? [];
        if (empty($gamesPayload)) {
            http_response_code(400);
            echo json_encode(['error' => 'No game patches provided.']);
            return;
        }

        // Build simple status map from JSON Patch operations
        $allowedStatuses = ['enabled', 'paused', 'outOfService'];
        $changes = [];
        foreach ($gamesPayload as $gameId => $patches) {
            foreach ($patches as $patch) {
                if (($patch['op'] ?? '') === 'replace' && ($patch['path'] ?? '') === '/operationStatus') {
                    $status = $patch['value'];
                    if (!in_array($status, $allowedStatuses, true)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid operationStatus: ' . $status]);
                        return;
                    }
                    $changes[$gameId] = $status;
                }
            }
        }

        if (empty($changes)) {
            echo json_encode(['games' => [], 'errors' => []]);
            return;
        }

        $result = $client->patchGames($changes);

        // Update cache only for games that actually succeeded, and reconcile
        // the retry queue: clear retries on success, queue/refresh on failure.
        // Older code wrote optimistically for every requested change, which
        // meant the cache would lie if the upstream API rejected the patch.
        $errors = $result['errors'] ?? [];
        foreach ($changes as $gameId => $status) {
            $gid = (string)$gameId;
            if (!isset($errors[$gameId]) && !isset($errors[$gid])) {
                DB::execute(
                    'UPDATE game_state_cache SET operation_status = :p0, last_synced_at = datetime(\'now\') WHERE game_id = :p1',
                    [$status, $gid]
                );
                Scheduler::clearRetry('game', $gid);
            } else {
                $err = $errors[$gameId] ?? $errors[$gid];
                $errorText = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
                Scheduler::queueRetry('game', $gid, $status, 'manual', null, $errorText);
            }
        }

        echo json_encode($result);
        return;
    }

    if ($method === 'POST' && $action === 'sync') {
        // Force sync. Also invalidate the categories cache so the next
        // categories fetch reflects any new/renamed categories.
        Auth::requireAccess('manual_control');
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        $count = $client->syncGamesToCache();
        CenterEdgeClient::invalidateCategoriesCache();

        echo json_encode([
            'success'    => true,
            'game_count' => $count,
            'synced_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

// -----------------------------------------------
// Single Game / Game RPC Action
// -----------------------------------------------

/**
 * Live single-game lookup. Bypasses the cache so the latest state is
 * always returned — useful after pause/unpause or reboot actions.
 */
function gamesGetOne(string $gameId): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    try {
        $game = $client->getGame($gameId);
        echo json_encode($game);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        $code = strpos($msg, 'HTTP 404') !== false ? 404 : 500;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($msg)]);
    }
}

/**
 * RPC perform-action passthrough (e.g. reboot a game).
 * Body: { "actionId": "reboot", "operator": { ... } }
 *
 * If operator is omitted we synthesise one from the logged-in admin user
 * — same pattern as kioskPerformAction.
 */
function gamesPerformAction(string $gameId, ?array $input): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $actionId = Validator::requireString($input, 'actionId', 30);

    $operator = $input['operator'] ?? null;
    if (!is_array($operator) || empty($operator)) {
        $user = Auth::check();
        $operator = [
            'employeeName' => $user['display_name'] ?? ($user['username'] ?? 'web'),
            'employeeNumber' => (int)($user['id'] ?? 0),
            'stationName' => 'CEplay Web',
        ];
    }

    try {
        $result = $client->performGameAction($gameId, $actionId, $operator);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        $code = strpos($msg, 'HTTP 400') !== false ? 400 : 500;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($msg)]);
        return;
    }

    $userId = Auth::check()['id'] ?? null;
    DB::auditLog('game-action', 'game_action_performed', $userId, [
        'game_id' => $gameId,
        'action_id' => $actionId,
    ]);

    echo json_encode($result);
}

// -----------------------------------------------
// Game-Play Transactions: live feed & top-games
// -----------------------------------------------

/**
 * Recent plays from the local cache. Joins to the game cache for human-readable
 * names where the upstream feed didn't include a description.
 *
 * Query: ?limit=50 (default 50, max 500)
 *        ?since=ISO8601 (optional — only return plays newer than this)
 */
function gamesRecentTransactions(bool $hideMoney = false): void {
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 50)));
    $since = isset($_GET['since']) && is_string($_GET['since']) ? trim($_GET['since']) : '';

    $sql = 'SELECT t.transaction_id, t.feed_name, t.card_number, t.type, t.game_id,
                   COALESCE(NULLIF(t.game_description, \'\'), c.game_name) AS game_name,
                   t.transaction_time, t.regular_points, t.bonus_points, t.redemption_tickets,
                   t.cash_amount, t.credit_card_amount, t.cc_card_type, t.cc_last4,
                   t.used_time_play, t.used_play_privilege, t.fetched_at
            FROM game_play_transactions t
            LEFT JOIN game_state_cache c ON c.game_id = t.game_id ';

    $params = [];
    if ($since !== '') {
        $sql .= 'WHERE t.transaction_time > :p0 ';
        $params[] = $since;
    }

    $sql .= 'ORDER BY t.transaction_id DESC LIMIT ' . $limit;

    $rows = DB::query($sql, $params);

    // Enrich: cardNumber 000000 means "no card / credit-card-only" per spec
    $rows = array_map(function ($r) use ($hideMoney) {
        if (($r['card_number'] ?? '') === '000000') {
            $r['no_card'] = true;
        }
        $r['transaction_id'] = (int)$r['transaction_id'];
        $r['used_time_play'] = (bool)$r['used_time_play'];
        $r['used_play_privilege'] = (bool)$r['used_play_privilege'];
        if ($hideMoney) {
            // Payment data is view_revenue-gated: zero the amounts AND blank
            // the credit-card brand/last-4 so nothing about how a play was
            // paid reaches roles without that permission.
            $r['cash_amount'] = 0.0;
            $r['credit_card_amount'] = 0.0;
            $r['cc_card_type'] = '';
            $r['cc_last4'] = '';
        }
        return $r;
    }, $rows);

    $stats = DB::queryOne('SELECT COUNT(*) AS total, MAX(transaction_time) AS latest_at, MAX(fetched_at) AS last_poll FROM game_play_transactions');

    echo json_encode([
        'transactions' => $rows,
        'count' => count($rows),
        'total_cached' => (int)($stats['total'] ?? 0),
        'latest_transaction_at' => $stats['latest_at'] ?? null,
        'last_poll_at' => $stats['last_poll'] ?? null,
    ]);
}

/**
 * Top games aggregation off the cached play feed.
 *
 * Query: ?window=hour|today|week|all (default today)
 *        ?sort=tickets|plays           (default tickets — matches the widget label)
 *        ?limit=10                     (default 10, max 100)
 *
 * "today" / "week" use the configured app timezone so the cutoff matches
 * what an operator at the venue would expect.
 */
function gamesTopTransactions(bool $hideMoney = false): void {
    $window = $_GET['window'] ?? 'today';
    $sort = $_GET['sort'] ?? 'tickets';
    if (!in_array($sort, ['tickets', 'plays'], true)) {
        $sort = 'tickets';
    }
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));

    $tz = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
    $tzObj = null;
    try {
        $tzObj = new DateTimeZone($tz);
    } catch (Exception $e) {
        $tzObj = new DateTimeZone('UTC');
    }

    // Same canonical UTC "Z" format transaction_time is stored in — the
    // WHERE below compares strings lexically (see gamesTicketStats).
    $utc = new DateTimeZone('UTC');
    $cutoff = null;
    switch ($window) {
        case 'hour':
            $cutoff = (new DateTime('-1 hour', $utc))->format('Y-m-d\TH:i:s\Z');
            break;
        case 'today':
            $cutoff = (new DateTime('today 00:00:00', $tzObj))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
            break;
        case 'week':
            $cutoff = (new DateTime('-7 days', $utc))->format('Y-m-d\TH:i:s\Z');
            break;
        case 'all':
        default:
            $cutoff = null;
    }

    $sql = 'SELECT t.game_id,
                   COALESCE(NULLIF(MAX(t.game_description), \'\'), MAX(c.game_name)) AS game_name,
                   COUNT(*) AS plays,
                   SUM(t.regular_points) AS sum_regular,
                   SUM(t.bonus_points) AS sum_bonus,
                   SUM(t.redemption_tickets) AS sum_tickets,
                   SUM(t.cash_amount + t.credit_card_amount) AS sum_cash,
                   MAX(t.transaction_time) AS last_play
            FROM game_play_transactions t
            LEFT JOIN game_state_cache c ON c.game_id = t.game_id ';
    $params = [];
    if ($cutoff !== null) {
        $sql .= 'WHERE t.transaction_time >= :p0 ';
        $params[] = $cutoff;
    }
    // Primary sort matches the requested metric; secondary sort breaks ties
    // with the other metric so two games that tied on tickets still rank
    // by plays (and vice versa) instead of an unstable order.
    if ($sort === 'plays') {
        $sql .= 'GROUP BY t.game_id ORDER BY plays DESC, sum_tickets DESC LIMIT ' . $limit;
    } else {
        $sql .= 'GROUP BY t.game_id ORDER BY sum_tickets DESC, plays DESC LIMIT ' . $limit;
    }

    $rows = DB::query($sql, $params);

    // Cast numeric aggregates so JSON encodes cleanly.
    $rows = array_map(function ($r) use ($hideMoney) {
        $r['plays'] = (int)$r['plays'];
        $r['sum_regular'] = (float)($r['sum_regular'] ?? 0);
        $r['sum_bonus'] = (float)($r['sum_bonus'] ?? 0);
        $r['sum_tickets'] = (float)($r['sum_tickets'] ?? 0);
        $r['sum_cash'] = $hideMoney ? 0.0 : (float)($r['sum_cash'] ?? 0);
        return $r;
    }, $rows);

    echo json_encode([
        'window' => $window,
        'sort'   => $sort,
        'cutoff' => $cutoff,
        'top'    => $rows,
    ]);
}

/**
 * Per-game ticket and play stats across multiple windows.
 *
 * Designed to be cheap enough to call from the dashboard polling loop:
 * a single GROUP BY on the indexed game_id column for each window.
 *
 * Returns:
 *   {
 *     "stats": {
 *       "GAME-001": {
 *         "tickets_hour": 0, "plays_hour": 0,
 *         "tickets_today": 412, "plays_today": 17,
 *         "tickets_week": 2941, "plays_week": 121,
 *         "tickets_all": 19288, "plays_all": 803,
 *         "last_play": "2026-04-30T15:42:11Z"
 *       },
 *       ...
 *     },
 *     "totals": { "tickets_today": ..., "plays_today": ..., "tickets_hour": ..., ... },
 *     "windows": { "hour": <iso>, "today": <iso>, "week": <iso> }
 *   }
 */
function gamesTicketStats(): void {
    $tz = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
    try {
        $tzObj = new DateTimeZone($tz);
    } catch (Exception $e) {
        $tzObj = new DateTimeZone('UTC');
    }

    // Cutoffs are compared lexically against transaction_time, which is
    // stored in canonical UTC "YYYY-MM-DDTHH:MM:SSZ" — so they must be
    // emitted in that exact format. format('c') here used to produce
    // "+00:00" / venue-offset strings that compared wrong against the
    // stored values, which zeroed out the dashboard's last-hour stats.
    $utc = new DateTimeZone('UTC');
    $hourCutoff  = (new DateTime('-1 hour', $utc))->format('Y-m-d\TH:i:s\Z');
    $todayCutoff = (new DateTime('today 00:00:00', $tzObj))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
    $weekCutoff  = (new DateTime('-7 days', $utc))->format('Y-m-d\TH:i:s\Z');

    // One pass over the cache: bucket each transaction into hour/today/week/all
    // using SQL CASE expressions so we get all windows in a single query.
    $sql = 'SELECT t.game_id,
                   COUNT(*) AS plays_all,
                   SUM(t.redemption_tickets) AS tickets_all,
                   SUM(CASE WHEN t.transaction_time >= :p0 THEN 1 ELSE 0 END) AS plays_hour,
                   SUM(CASE WHEN t.transaction_time >= :p0 THEN t.redemption_tickets ELSE 0 END) AS tickets_hour,
                   SUM(CASE WHEN t.transaction_time >= :p1 THEN 1 ELSE 0 END) AS plays_today,
                   SUM(CASE WHEN t.transaction_time >= :p1 THEN t.redemption_tickets ELSE 0 END) AS tickets_today,
                   SUM(CASE WHEN t.transaction_time >= :p2 THEN 1 ELSE 0 END) AS plays_week,
                   SUM(CASE WHEN t.transaction_time >= :p2 THEN t.redemption_tickets ELSE 0 END) AS tickets_week,
                   SUM(CASE WHEN t.transaction_time >= :p1 AND t.used_time_play = 1 THEN 1 ELSE 0 END) AS time_plays_today,
                   SUM(CASE WHEN t.transaction_time >= :p1 AND t.used_play_privilege = 1 THEN 1 ELSE 0 END) AS privilege_plays_today,
                   MAX(t.transaction_time) AS last_play
            FROM game_play_transactions t
            WHERE t.game_id != \'\'
            GROUP BY t.game_id';

    $rows = DB::query($sql, [$hourCutoff, $todayCutoff, $weekCutoff]);

    $stats = [];
    $totals = [
        'tickets_hour' => 0.0, 'plays_hour' => 0,
        'tickets_today' => 0.0, 'plays_today' => 0,
        'tickets_week' => 0.0, 'plays_week' => 0,
        'tickets_all' => 0.0, 'plays_all' => 0,
        'time_plays_today' => 0, 'privilege_plays_today' => 0,
    ];
    foreach ($rows as $r) {
        $gid = (string)$r['game_id'];
        $stats[$gid] = [
            'plays_hour'    => (int)$r['plays_hour'],
            'tickets_hour'  => (float)$r['tickets_hour'],
            'plays_today'   => (int)$r['plays_today'],
            'tickets_today' => (float)$r['tickets_today'],
            'plays_week'    => (int)$r['plays_week'],
            'tickets_week'  => (float)$r['tickets_week'],
            'plays_all'     => (int)$r['plays_all'],
            'tickets_all'   => (float)$r['tickets_all'],
            'last_play'     => $r['last_play'] ?: null,
        ];
        $totals['tickets_hour']  += (float)$r['tickets_hour'];
        $totals['plays_hour']    += (int)$r['plays_hour'];
        $totals['tickets_today'] += (float)$r['tickets_today'];
        $totals['plays_today']   += (int)$r['plays_today'];
        $totals['tickets_week']  += (float)$r['tickets_week'];
        $totals['plays_week']    += (int)$r['plays_week'];
        $totals['tickets_all']   += (float)$r['tickets_all'];
        $totals['plays_all']     += (int)$r['plays_all'];
        $totals['time_plays_today']      += (int)$r['time_plays_today'];
        $totals['privilege_plays_today'] += (int)$r['privilege_plays_today'];
    }

    $meta = DB::queryOne('SELECT MAX(fetched_at) AS last_poll, COUNT(*) AS total_cached FROM game_play_transactions');

    echo json_encode([
        'stats'        => $stats,
        'totals'       => $totals,
        'windows'      => [
            'hour'  => $hourCutoff,
            'today' => $todayCutoff,
            'week'  => $weekCutoff,
        ],
        'last_poll_at' => $meta['last_poll'] ?? null,
        'total_cached' => (int)($meta['total_cached'] ?? 0),
    ]);
}

/**
 * Ticket payout ratio (tickets dispensed per point played, as a percent)
 * across five windows: hour / today / week / month / all-time. Powers the
 * dashboard payout gauge — operators watch that this stays at or below the
 * configured target (default 33%).
 *
 * Only REDEMPTION games count: rides, batting cages, and other
 * non-redemption games burn points but can never dispense tickets, so
 * including their points would deflate the ratio and mask a hot payout.
 * A game is classified as redemption when it has EVER dispensed tickets
 * (raw feed or rollup history) — data-driven, no configuration, and it
 * still counts a redemption game's points on a day it pays nothing out.
 *
 * Hour/today/week come from the raw feed. Month (last 30 local days) and
 * all-time stitch the permanent game_daily_stats rollup (dates BEFORE
 * today) with today's raw sums — the nightly rollup may hold a partial
 * row for the current date, so today always comes from the raw feed to
 * avoid double-counting. Points = regular + bonus (both are paid play).
 * No cash fields are involved, so this needs no view_revenue scrub; the
 * route shares the /transactions analytics gate.
 */
function gamesPayoutStats(): void {
    $tz = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
    try {
        $tzObj = new DateTimeZone($tz);
    } catch (Exception $e) {
        $tzObj = new DateTimeZone('UTC');
    }
    $utc = new DateTimeZone('UTC');
    $fmt = 'Y-m-d\TH:i:s\Z';

    $todayStartLocal = new DateTime('today 00:00:00', $tzObj);
    $hourCutoff  = (new DateTime('-1 hour', $utc))->format($fmt);
    $todayCutoff = (clone $todayStartLocal)->setTimezone($utc)->format($fmt);
    $weekCutoff  = (new DateTime('-7 days', $utc))->format($fmt);
    $todayLocalDate = $todayStartLocal->format('Y-m-d');
    $monthStartDate = (clone $todayStartLocal)->modify('-29 days')->format('Y-m-d');

    // Redemption games = any game that has ever dispensed a ticket, across
    // the raw feed and the permanent rollup. UNION dedups the two sources.
    $redemptionSubquery =
        '(SELECT game_id FROM game_play_transactions WHERE redemption_tickets > 0 AND game_id != \'\'
          UNION
          SELECT game_id FROM game_daily_stats WHERE tickets > 0)';

    $redemptionGames = (int)(DB::queryOne(
        'SELECT COUNT(*) AS c FROM ' . $redemptionSubquery
    )['c'] ?? 0);

    $raw = DB::queryOne(
        'SELECT
            SUM(CASE WHEN transaction_time >= :p0 THEN redemption_tickets ELSE 0 END) AS t_hour,
            SUM(CASE WHEN transaction_time >= :p0 THEN regular_points + bonus_points ELSE 0 END) AS p_hour,
            SUM(CASE WHEN transaction_time >= :p1 THEN redemption_tickets ELSE 0 END) AS t_today,
            SUM(CASE WHEN transaction_time >= :p1 THEN regular_points + bonus_points ELSE 0 END) AS p_today,
            SUM(CASE WHEN transaction_time >= :p2 THEN redemption_tickets ELSE 0 END) AS t_week,
            SUM(CASE WHEN transaction_time >= :p2 THEN regular_points + bonus_points ELSE 0 END) AS p_week
         FROM game_play_transactions
         WHERE game_id IN ' . $redemptionSubquery,
        [$hourCutoff, $todayCutoff, $weekCutoff]
    );

    $rollMonth = DB::queryOne(
        'SELECT SUM(tickets) AS t, SUM(regular_points + bonus_points) AS p
         FROM game_daily_stats
         WHERE stat_date >= :p0 AND stat_date < :p1
           AND game_id IN ' . $redemptionSubquery,
        [$monthStartDate, $todayLocalDate]
    );
    $rollAll = DB::queryOne(
        'SELECT SUM(tickets) AS t, SUM(regular_points + bonus_points) AS p
         FROM game_daily_stats
         WHERE stat_date < :p0
           AND game_id IN ' . $redemptionSubquery,
        [$todayLocalDate]
    );

    $mk = function ($tickets, $points) {
        $tickets = (float)($tickets ?? 0);
        $points  = (float)($points ?? 0);
        return [
            'tickets'   => round($tickets, 2),
            'points'    => round($points, 2),
            'ratio_pct' => $points > 0 ? round(($tickets / $points) * 100, 1) : null,
        ];
    };

    $target = (float)(DB::getConfig('payout_target_pct') ?: 33);

    echo json_encode([
        'windows' => [
            'hour'  => $mk($raw['t_hour'] ?? 0, $raw['p_hour'] ?? 0),
            'today' => $mk($raw['t_today'] ?? 0, $raw['p_today'] ?? 0),
            'week'  => $mk($raw['t_week'] ?? 0, $raw['p_week'] ?? 0),
            'month' => $mk(
                (float)($rollMonth['t'] ?? 0) + (float)($raw['t_today'] ?? 0),
                (float)($rollMonth['p'] ?? 0) + (float)($raw['p_today'] ?? 0)
            ),
            'all'   => $mk(
                (float)($rollAll['t'] ?? 0) + (float)($raw['t_today'] ?? 0),
                (float)($rollAll['p'] ?? 0) + (float)($raw['p_today'] ?? 0)
            ),
        ],
        'target_pct' => $target,
        'redemption_games' => $redemptionGames,
    ]);
}

/**
 * Force-poll the upstream play feed (manual catch-up button).
 * Normally the watchdog cron handles this every minute.
 */
function gamesPollTransactions(): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    try {
        // Poll every feed the card system advertises, same as the watchdog.
        $summary = $client->pollAllGameTransactionFeeds();
        echo json_encode([
            'success' => empty($summary['errors']),
            'fetched' => $summary['fetched'],
            'feeds'   => array_keys($summary['feeds']),
            'errors'  => (object)$summary['errors'],
            'polled_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}
