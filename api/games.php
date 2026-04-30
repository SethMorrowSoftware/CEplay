<?php
/**
 * API: Proxy game and category data from CenterEdge.
 * GET    /api/games                    — Cached games list (auto-syncs if empty)
 * GET    /api/games/categories         — Live categories from CenterEdge
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
        // Fetch categories from CenterEdge (always live)
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        $categories = $client->getCategories();
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
        // Force sync
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        $count = $client->syncGamesToCache();

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
 * "today" / "week" use the configured app timezone so the cutoff matches
 * what an operator at the venue would expect.
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
            $cutoff = (new DateTime('-1 hour', new DateTimeZone('UTC')))->format('c');
            break;
        case 'today':
            $cutoff = (new DateTime('today 00:00:00', $tzObj))->format('c');
            break;
        case 'week':
            $cutoff = (new DateTime('-7 days', new DateTimeZone('UTC')))->format('c');
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
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    try {
        $summary = $client->pollGameTransactions('default');
        echo json_encode([
            'success' => true,
            'fetched' => $summary['fetched'],
            'last_id' => $summary['last_id'],
            'polled_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}
