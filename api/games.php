<?php
/**
 * API: Proxy game and category data from CenterEdge.
 * GET    /api/games                    — Cached games list (auto-syncs if empty)
 * GET    /api/games/categories         — Live categories from CenterEdge
 * GET    /api/games/analytics          — Aggregated play analytics for a window
 * GET    /api/games/transactions/recent — Recent plays (cached locally)
 * GET    /api/games/transactions/top    — Top-games aggregation (cached locally)
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
    if ($method === 'GET' && $action === 'transactions' && $sub === 'recent') {
        gamesRecentTransactions();
        return;
    }
    if ($method === 'GET' && $action === 'transactions' && $sub === 'top') {
        gamesTopTransactions();
        return;
    }
    if ($method === 'POST' && $action === 'transactions' && $sub === 'poll') {
        gamesPollTransactions();
        return;
    }

    if ($method === 'GET' && $action === 'analytics') {
        gamesAnalytics();
        return;
    }

    // GET /api/games/{id}  and  POST /api/games/{id}/action
    // We treat any /api/games/<value> where value is not a reserved word as a
    // single-game lookup. Reserved: 'categories', 'sync', 'transactions', 'analytics'.
    $reservedActions = ['categories', 'sync', 'transactions', 'analytics', ''];
    if ($action !== '' && !in_array($action, $reservedActions, true)) {
        $gameId = $action;
        if ($method === 'GET' && $sub === '') {
            gamesGetOne($gameId);
            return;
        }
        if ($method === 'POST' && $sub === 'action') {
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
        // Categories rarely change. Serve from the 1-hour server-side cache
        // (refreshed by the daily cron) unless the caller explicitly asks
        // for a fresh fetch via ?refresh=1.
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        $forceRefresh = !empty($_GET['refresh']);
        $categories = $client->getCategoriesCached($forceRefresh);
        echo json_encode(['categories' => $categories]);
        return;
    }

    if ($method === 'PATCH' && $action === '') {
        // Patch game operation statuses via CenterEdge API
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

        // Manual click is a fresh intent. Wipe any prior retry rows (including
        // gave-up markers) for the games we're about to patch so a failure
        // here starts a clean 10-attempt window instead of inheriting the
        // earlier give-up state.
        foreach ($changes as $gameId => $_status) {
            Scheduler::clearRetry('game', (string)$gameId);
        }

        $result = $client->patchGames($changes);

        // Update cache only for games that actually succeeded, and reconcile
        // the retry queue: nothing to do on success (we already cleared above),
        // queue a fresh retry on failure. Older code wrote optimistically for
        // every requested change, which meant the cache would lie if the
        // upstream API rejected the patch.
        $errors = $result['errors'] ?? [];
        foreach ($changes as $gameId => $status) {
            $gid = (string)$gameId;
            if (!isset($errors[$gameId]) && !isset($errors[$gid])) {
                DB::execute(
                    'UPDATE game_state_cache SET operation_status = :p0, last_synced_at = datetime(\'now\') WHERE game_id = :p1',
                    [$status, $gid]
                );
            } else {
                $err = $errors[$gameId] ?? $errors[$gid];
                $errorText = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
                Scheduler::queueRetry('game', $gid, $status, 'manual', null, $errorText);
            }
        }

        $errorCount = count($errors);
        DB::auditLog('game-patch', 'games_bulk_patch', null, [
            'requested' => count($changes),
            'errors' => $errorCount,
            'changes' => $changes,
        ], $errorCount === 0, $errorCount === 0 ? null : ($errorCount . ' game(s) failed to update'));

        echo json_encode($result);
        return;
    }

    if ($method === 'POST' && $action === 'sync') {
        // Force sync
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            DB::auditLog('game-sync', 'games_sync_triggered', null,
                [], false, 'CenterEdge API is not configured');
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        try {
            $count = $client->syncGamesToCache();
        } catch (Exception $e) {
            DB::auditLog('game-sync', 'games_sync_triggered', null,
                [], false, $e->getMessage());
            throw $e;
        }

        DB::auditLog('game-sync', 'games_sync_triggered', null, [
            'game_count' => $count,
        ]);

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
        DB::auditLog('game-action', 'game_action_performed', null, [
            'game_id' => $gameId,
            'action_id' => $actionId,
        ], false, $msg);
        $code = strpos($msg, 'HTTP 400') !== false ? 400 : 500;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($msg)]);
        return;
    }

    DB::auditLog('game-action', 'game_action_performed', null, [
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
function gamesRecentTransactions(): void {
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 50)));
    $since = isset($_GET['since']) && is_string($_GET['since']) ? trim($_GET['since']) : '';

    $sql = 'SELECT t.transaction_id, t.feed_name, t.card_number, t.type, t.game_id,
                   COALESCE(NULLIF(t.game_description, \'\'), c.game_name) AS game_name,
                   t.transaction_time, t.regular_points, t.bonus_points, t.redemption_tickets,
                   t.cash_amount, t.used_time_play, t.used_play_privilege, t.fetched_at
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
    $rows = array_map(function ($r) {
        if (($r['card_number'] ?? '') === '000000') {
            $r['no_card'] = true;
        }
        $r['transaction_id'] = (int)$r['transaction_id'];
        $r['used_time_play'] = (bool)$r['used_time_play'];
        $r['used_play_privilege'] = (bool)$r['used_play_privilege'];
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
 *        ?limit=10  (default 10, max 100)
 *
 * Cutoffs are formatted in the venue's timezone offset so the lexical
 * comparison against transaction_time (which CenterEdge emits with the
 * venue's offset per the OpenAPI spec) is correct. Mixing UTC and
 * venue-offset strings here silently dropped boundary rows on any system
 * whose offset wasn't UTC.
 */
function gamesTopTransactions(): void {
    $window = $_GET['window'] ?? 'today';
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));

    $tz = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
    $tzObj = null;
    try {
        $tzObj = new DateTimeZone($tz);
    } catch (Exception $e) {
        $tzObj = new DateTimeZone('UTC');
    }

    $cutoff = null;
    switch ($window) {
        case 'hour':
            $cutoff = (new DateTime('-1 hour', $tzObj))->format('c');
            break;
        case 'today':
            $cutoff = (new DateTime('today 00:00:00', $tzObj))->format('c');
            break;
        case 'week':
            $cutoff = (new DateTime('-7 days', $tzObj))->format('c');
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
                   SUM(t.cash_amount) AS sum_cash,
                   MAX(t.transaction_time) AS last_play
            FROM game_play_transactions t
            LEFT JOIN game_state_cache c ON c.game_id = t.game_id ';
    $params = [];
    if ($cutoff !== null) {
        $sql .= 'WHERE t.transaction_time >= :p0 ';
        $params[] = $cutoff;
    }
    $sql .= 'GROUP BY t.game_id ORDER BY plays DESC LIMIT ' . $limit;

    $rows = DB::query($sql, $params);

    // Cast numeric aggregates so JSON encodes cleanly.
    $rows = array_map(function ($r) {
        $r['plays'] = (int)$r['plays'];
        $r['sum_regular'] = (float)($r['sum_regular'] ?? 0);
        $r['sum_bonus'] = (float)($r['sum_bonus'] ?? 0);
        $r['sum_tickets'] = (float)($r['sum_tickets'] ?? 0);
        $r['sum_cash'] = (float)($r['sum_cash'] ?? 0);
        return $r;
    }, $rows);

    echo json_encode([
        'window' => $window,
        'cutoff' => $cutoff,
        'top' => $rows,
    ]);
}

/**
 * Force-poll the upstream play feed (manual catch-up button).
 * Normally the watchdog cron handles this every minute.
 */
function gamesPollTransactions(): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        DB::auditLog('game-poll', 'games_poll_triggered', null,
            [], false, 'CenterEdge API is not configured');
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    try {
        $summary = $client->pollGameTransactions('default');
        DB::auditLog('game-poll', 'games_poll_triggered', null, [
            'fetched' => (int)($summary['fetched'] ?? 0),
            'last_id' => (int)($summary['last_id'] ?? 0),
        ]);
        echo json_encode([
            'success' => true,
            'fetched' => $summary['fetched'],
            'last_id' => $summary['last_id'],
            'polled_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (RuntimeException $e) {
        DB::auditLog('game-poll', 'games_poll_triggered', null,
            [], false, $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}

/**
 * Aggregate analytics across the cached play feed for a configurable window.
 *
 * Query: ?window=day|week|month|year|all (default day)
 *        ?limit=10 (top-N size for leaderboards, default 10, max 50)
 *
 * Response intentionally OMITS dollar/cash amounts — this dashboard is intended
 * for floor-staff use and only exposes plays, points and tickets.
 *
 * Returns:
 *   {
 *     window, cutoff,
 *     totals: { plays, regular_points, bonus_points, total_points, tickets, unique_games, unique_cards, time_plays, privilege_plays },
 *     status_breakdown: { enabled, paused, outOfService, unknown, total },
 *     top_by_plays:   [ { game_id, game_name, plays, sum_tickets, sum_total_points, last_play } ],
 *     top_by_tickets: [ ...sorted by sum_tickets desc ],
 *     top_by_points:  [ ...sorted by sum_total_points desc ],
 *     timeseries:     [ { bucket, plays, tickets, total_points } ],
 *     bucket_unit:    'hour' | 'day' | 'month'
 *   }
 */
function gamesAnalytics(): void {
    $window = $_GET['window'] ?? 'day';
    if (!in_array($window, ['day', 'week', 'month', 'year', 'all'], true)) {
        $window = 'day';
    }
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));

    $tz = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
    try {
        $tzObj = new DateTimeZone($tz);
    } catch (Exception $e) {
        $tzObj = new DateTimeZone('UTC');
    }

    // Pick a cutoff and a sensible bucket size for the time-series chart.
    //
    // The cutoff is formatted with the venue's local offset (DateTime::format('c')
    // → e.g. "2026-05-04T00:00:00-04:00") so it lexically compares correctly
    // against transaction_time, which CenterEdge stores in the same format
    // (per the OpenAPI spec: ISO 8601 with the venue's timezone offset).
    // Mixing UTC-Z and ±HH:MM here would silently drop boundary-day plays
    // because SQLite uses byte-wise string comparison.
    $cutoff = null;
    $bucketUnit = 'day';
    switch ($window) {
        case 'day':
            $cutoff = (new DateTime('today 00:00:00', $tzObj))->format('c');
            $bucketUnit = 'hour';
            break;
        case 'week':
            $cutoff = (new DateTime('-6 days 00:00:00', $tzObj))->format('c');
            $bucketUnit = 'day';
            break;
        case 'month':
            $cutoff = (new DateTime('-29 days 00:00:00', $tzObj))->format('c');
            $bucketUnit = 'day';
            break;
        case 'year':
            $cutoff = (new DateTime('-364 days 00:00:00', $tzObj))->format('c');
            $bucketUnit = 'month';
            break;
        case 'all':
        default:
            $cutoff = null;
            $bucketUnit = 'day';
    }

    // ---- Totals ----
    $totalsSql = 'SELECT
            COUNT(*) AS plays,
            COALESCE(SUM(regular_points), 0) AS regular_points,
            COALESCE(SUM(bonus_points), 0) AS bonus_points,
            COALESCE(SUM(redemption_tickets), 0) AS tickets,
            COUNT(DISTINCT NULLIF(game_id, \'\')) AS unique_games,
            COUNT(DISTINCT CASE WHEN card_number != \'\' AND card_number != \'000000\' THEN card_number END) AS unique_cards,
            COALESCE(SUM(used_time_play), 0) AS time_plays,
            COALESCE(SUM(used_play_privilege), 0) AS privilege_plays
        FROM game_play_transactions ';
    $totalsParams = [];
    if ($cutoff !== null) {
        $totalsSql .= 'WHERE transaction_time >= :p0';
        $totalsParams[] = $cutoff;
    }
    $totalsRow = DB::queryOne($totalsSql, $totalsParams) ?: [];

    $totals = [
        'plays' => (int)($totalsRow['plays'] ?? 0),
        'regular_points' => (float)($totalsRow['regular_points'] ?? 0),
        'bonus_points' => (float)($totalsRow['bonus_points'] ?? 0),
        'total_points' => (float)(($totalsRow['regular_points'] ?? 0) + ($totalsRow['bonus_points'] ?? 0)),
        'tickets' => (float)($totalsRow['tickets'] ?? 0),
        'unique_games' => (int)($totalsRow['unique_games'] ?? 0),
        'unique_cards' => (int)($totalsRow['unique_cards'] ?? 0),
        'time_plays' => (int)($totalsRow['time_plays'] ?? 0),
        'privilege_plays' => (int)($totalsRow['privilege_plays'] ?? 0),
    ];

    // ---- Status breakdown across the cached game directory ----
    // Independent of the play-window — represents the current snapshot.
    $statusRows = DB::query(
        'SELECT operation_status, COUNT(*) AS cnt FROM game_state_cache GROUP BY operation_status'
    );
    $statusBreakdown = ['enabled' => 0, 'paused' => 0, 'outOfService' => 0, 'unknown' => 0, 'total' => 0];
    foreach ($statusRows as $r) {
        $key = $r['operation_status'] ?: 'unknown';
        if (!isset($statusBreakdown[$key])) $statusBreakdown[$key] = 0;
        $statusBreakdown[$key] += (int)$r['cnt'];
        $statusBreakdown['total'] += (int)$r['cnt'];
    }

    // ---- Top-N leaderboards (plays / tickets / points) ----
    $aggSql = 'SELECT t.game_id,
            COALESCE(NULLIF(MAX(t.game_description), \'\'), MAX(c.game_name)) AS game_name,
            COUNT(*) AS plays,
            COALESCE(SUM(t.regular_points), 0) AS sum_regular,
            COALESCE(SUM(t.bonus_points), 0) AS sum_bonus,
            COALESCE(SUM(t.redemption_tickets), 0) AS sum_tickets,
            MAX(t.transaction_time) AS last_play
        FROM game_play_transactions t
        LEFT JOIN game_state_cache c ON c.game_id = t.game_id ';
    $aggParams = [];
    if ($cutoff !== null) {
        $aggSql .= 'WHERE t.transaction_time >= :p0 ';
        $aggParams[] = $cutoff;
    }
    $aggSql .= 'GROUP BY t.game_id';
    $aggregated = DB::query($aggSql, $aggParams);

    $aggregated = array_map(function ($r) {
        $regular = (float)($r['sum_regular'] ?? 0);
        $bonus = (float)($r['sum_bonus'] ?? 0);
        return [
            'game_id' => (string)($r['game_id'] ?? ''),
            'game_name' => $r['game_name'] ?: ('Game ' . ($r['game_id'] ?? '?')),
            'plays' => (int)$r['plays'],
            'sum_tickets' => (float)($r['sum_tickets'] ?? 0),
            'sum_regular' => $regular,
            'sum_bonus' => $bonus,
            'sum_total_points' => $regular + $bonus,
            'last_play' => $r['last_play'] ?? null,
        ];
    }, $aggregated);

    $topByPlays = $aggregated;
    usort($topByPlays, function ($a, $b) {
        $cmp = $b['plays'] <=> $a['plays'];
        return $cmp !== 0 ? $cmp : strcmp($a['game_name'], $b['game_name']);
    });
    $topByPlays = array_slice($topByPlays, 0, $limit);

    $topByTickets = $aggregated;
    usort($topByTickets, function ($a, $b) {
        $cmp = $b['sum_tickets'] <=> $a['sum_tickets'];
        return $cmp !== 0 ? $cmp : strcmp($a['game_name'], $b['game_name']);
    });
    $topByTickets = array_slice($topByTickets, 0, $limit);

    $topByPoints = $aggregated;
    usort($topByPoints, function ($a, $b) {
        $cmp = $b['sum_total_points'] <=> $a['sum_total_points'];
        return $cmp !== 0 ? $cmp : strcmp($a['game_name'], $b['game_name']);
    });
    $topByPoints = array_slice($topByPoints, 0, $limit);

    // ---- Time-series for chart (bucketed in app timezone) ----
    // SQLite doesn't have a clean way to convert from UTC to a named timezone,
    // so we fetch raw rows and bucket in PHP. For the year window, bucket by
    // month; for week/month, bucket by day; for day, bucket by hour.
    $rawSql = 'SELECT transaction_time, regular_points, bonus_points, redemption_tickets
               FROM game_play_transactions ';
    $rawParams = [];
    if ($cutoff !== null) {
        $rawSql .= 'WHERE transaction_time >= :p0 ';
        $rawParams[] = $cutoff;
    }
    $rawSql .= 'ORDER BY transaction_time ASC';
    $rawRows = DB::query($rawSql, $rawParams);

    $buckets = [];
    foreach ($rawRows as $r) {
        $t = $r['transaction_time'] ?? '';
        if ($t === '') continue;
        try {
            $dt = new DateTime($t);
            $dt->setTimezone($tzObj);
        } catch (Exception $e) {
            continue;
        }
        if ($bucketUnit === 'hour') {
            $key = $dt->format('Y-m-d\TH:00');
        } elseif ($bucketUnit === 'month') {
            $key = $dt->format('Y-m');
        } else {
            $key = $dt->format('Y-m-d');
        }
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['bucket' => $key, 'plays' => 0, 'tickets' => 0.0, 'total_points' => 0.0];
        }
        $buckets[$key]['plays']++;
        $buckets[$key]['tickets'] += (float)($r['redemption_tickets'] ?? 0);
        $buckets[$key]['total_points'] += (float)($r['regular_points'] ?? 0) + (float)($r['bonus_points'] ?? 0);
    }

    // Fill gaps so the chart line is continuous (zeros for empty buckets).
    $timeseries = gamesAnalyticsFillBuckets($buckets, $cutoff, $bucketUnit, $tzObj);

    echo json_encode([
        'window' => $window,
        'cutoff' => $cutoff,
        'bucket_unit' => $bucketUnit,
        'timezone' => $tz,
        'totals' => $totals,
        'status_breakdown' => $statusBreakdown,
        'top_by_plays' => $topByPlays,
        'top_by_tickets' => $topByTickets,
        'top_by_points' => $topByPoints,
        'timeseries' => $timeseries,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

/**
 * Build a contiguous bucket timeline (no gaps). For 'all', falls back to whatever
 * buckets we observed in $existing.
 */
function gamesAnalyticsFillBuckets(array $existing, ?string $cutoff, string $unit, DateTimeZone $tz): array {
    if ($cutoff === null) {
        // Sort observed buckets ascending so the chart is ordered.
        $rows = array_values($existing);
        usort($rows, function ($a, $b) { return strcmp($a['bucket'], $b['bucket']); });
        return $rows;
    }

    try {
        $start = new DateTime($cutoff);
        $start->setTimezone($tz);
    } catch (Exception $e) {
        return array_values($existing);
    }

    $now = new DateTime('now', $tz);

    // Snap start to bucket boundary.
    if ($unit === 'hour') {
        $start->setTime((int)$start->format('H'), 0, 0);
        $step = new DateInterval('PT1H');
        $fmt = 'Y-m-d\TH:00';
    } elseif ($unit === 'month') {
        $start->setDate((int)$start->format('Y'), (int)$start->format('m'), 1);
        $start->setTime(0, 0, 0);
        $step = new DateInterval('P1M');
        $fmt = 'Y-m';
    } else {
        $start->setTime(0, 0, 0);
        $step = new DateInterval('P1D');
        $fmt = 'Y-m-d';
    }

    $rows = [];
    $cursor = clone $start;
    $safety = 0;
    while ($cursor <= $now && $safety++ < 1000) {
        $key = $cursor->format($fmt);
        $rows[] = $existing[$key] ?? [
            'bucket' => $key,
            'plays' => 0,
            'tickets' => 0.0,
            'total_points' => 0.0,
        ];
        $cursor->add($step);
    }
    return $rows;
}
