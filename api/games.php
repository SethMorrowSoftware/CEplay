<?php
/**
 * API: Proxy game and category data from CenterEdge.
 * GET    /api/games                    — Cached games list (auto-syncs if empty)
 * GET    /api/games/categories         — Live categories from CenterEdge
 * GET    /api/games/transactions/recent — Recent plays (cached locally)
 * GET    /api/games/transactions/top    — Top-games aggregation (cached locally)
 * GET    /api/games/transactions/stats  — Per-game ticket/play stats across time windows
 * GET    /api/games/transactions/payout — Ticket payout % (tickets/points) per window
 * GET    /api/games/transactions/ticket-watch — Top ticket earners today with farming signals
 * POST   /api/games/sync               — Force sync game states from CenterEdge
 * POST   /api/games/transactions/poll  — Force-poll the play feed (manual catch-up)
 * GET    /api/games/{id}               — Single game (live from CenterEdge)
 * POST   /api/games/{id}/action        — RPC perform-action (e.g. reboot)
 * PATCH  /api/games                    — Bulk operationStatus PATCH passthrough
 */

require_once __DIR__ . '/../lib/centeredge_client.php';
require_once __DIR__ . '/../lib/scheduler.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/reporting.php';

/**
 * Build a bound IN-clause placeholder list "(:pN,:pN+1,…)" for the redemption
 * game IDs, starting at positional index $startIdx (after the query's other
 * binds). Returns [placeholders, values] to merge into the params array.
 */
function payoutInClause(array $ids, int $startIdx): array {
    $ph = [];
    $i = $startIdx;
    foreach ($ids as $id) { $ph[] = ':p' . $i; $i++; }
    return ['(' . implode(',', $ph) . ')', array_values($ids)];
}

/**
 * POST /api/games/unpause-all — unpause every currently-paused game, leaving
 * tagged-out (outOfService) games untouched.
 *
 * A bare per-game PATCH to 'enabled' is NOT enough here: state enforcement
 * (watchdog + the per-request safety net) re-pauses any game whose ACTIVE
 * pause group's schedule currently wants it paused, usually within a minute.
 * So paused games inside an active group are unpaused the way the dashboard
 * does it — Scheduler::executeImmediate(group, 'unpause', 'manual'), which
 * patches the group, skips outOfService members, and records the manual
 * override enforcement respects until the group's next scheduled transition.
 * Paused games in NO active group get a direct patch (enforcement never
 * touches them).
 */
function gamesUnpauseAll(): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    // Everything currently paused, per the state cache the board renders.
    $paused = [];
    foreach (DB::query(
        "SELECT game_id, game_name FROM game_state_cache WHERE operation_status = 'paused'"
    ) as $r) {
        $paused[(string)$r['game_id']] = (string)$r['game_name'];
    }
    $actorId = Auth::check()['id'] ?? null;

    if (empty($paused)) {
        echo json_encode(['unpaused' => 0, 'groups_overridden' => 0, 'errors' => []]);
        return;
    }

    // One lock for the whole sweep (re-entrant, so executeImmediate's own
    // acquire succeeds instantly) — the watchdog can't interleave and re-pause
    // half the floor mid-operation.
    if (!Scheduler::acquireLock(10)) {
        throw new RuntimeException(
            'The scheduler is busy applying another change. Please try again in a few seconds.'
        );
    }
    $unpausedIds = [];
    $errors = [];
    $groupsOverridden = 0;
    try {
        // Group pass: EVERY active group containing a paused game gets the
        // manual unpause — including groups sharing a game, or the second
        // group would re-pause it within a minute.
        $handledByGroup = [];
        foreach (DB::query('SELECT id, name FROM pause_groups WHERE is_active = 1') as $g) {
            $gid = (int)$g['id'];
            $members = array_map('strval', Scheduler::resolveGroupGames($gid));
            $pausedMembers = array_values(array_intersect($members, array_keys($paused)));
            if (empty($pausedMembers)) {
                continue;
            }
            try {
                $result = Scheduler::executeImmediate($gid, 'unpause', 'manual');
                $groupsOverridden++;
                foreach (($result['changed'] ?? []) as $c) {
                    if (isset($c['game_id'])) {
                        $unpausedIds[(string)$c['game_id']] = true;
                    }
                }
                foreach (($result['errors'] ?? []) as $e) {
                    $eid = (string)($e['game_id'] ?? '');
                    if ($eid !== '' && isset($paused[$eid])) {
                        $errors[$eid] = (string)($e['error'] ?? 'unknown error');
                    }
                }
            } catch (Exception $e) {
                foreach ($pausedMembers as $pm) {
                    $errors[$pm] = $e->getMessage();
                }
            }
            foreach ($pausedMembers as $pm) {
                $handledByGroup[$pm] = true;
            }
        }

        // Direct pass: paused games in no active group (or no group at all).
        $direct = array_diff_key($paused, $handledByGroup);
        if (!empty($direct)) {
            $changes = array_fill_keys(array_keys($direct), 'enabled');
            $result = $client->patchGames($changes);
            $patchErrors = $result['errors'] ?? [];
            foreach ($changes as $gameId => $status) {
                $gid = (string)$gameId;
                if (!isset($patchErrors[$gameId]) && !isset($patchErrors[$gid])) {
                    DB::execute(
                        'UPDATE game_state_cache SET operation_status = :p0, last_synced_at = datetime(\'now\') WHERE game_id = :p1',
                        [$status, $gid]
                    );
                    Scheduler::clearRetry('game', $gid);
                    $unpausedIds[$gid] = true;
                } else {
                    $err = $patchErrors[$gameId] ?? $patchErrors[$gid];
                    $errors[$gid] = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
                    Scheduler::queueRetry('game', $gid, $status, 'manual', null, $errors[$gid]);
                }
            }
        }
    } finally {
        Scheduler::releaseLock();
    }

    // One summary audit row for the sweep (the per-game changes are already
    // logged by executeStateChange / the retry queue with source 'manual').
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details)
             VALUES (:p0, :p1, :p2, :p3)',
            [
                'game-status',
                'unpause_all',
                empty($errors) ? 1 : 0,
                json_encode([
                    'actor_user_id'     => $actorId,
                    'paused_found'      => count($paused),
                    'unpaused'          => count($unpausedIds),
                    'groups_overridden' => $groupsOverridden,
                    'failed'            => count($errors),
                ]),
            ]
        );
    } catch (Exception $e) {
        error_log('unpause_all audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'unpaused'          => count($unpausedIds),
        'groups_overridden' => $groupsOverridden,
        'errors'            => $errors,
    ]);
}

function handleGames(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    // Game reads serve several sections: the Games page itself, the Tag
    // Board, the Dashboard feed, the Pause Groups / Reader Groups member
    // pickers, and the analytics pages' name lookups. Visible via any of
    // those routes; hidden only when a role has none of them.
    if ($method === 'GET') {
        Auth::requireAnyAccess(['view_games', 'view_tags', 'view_dashboard', 'view_groups',
                                'groups_manage', 'reader_groups_manage', 'analytics']);
    }

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
        if ($method === 'GET' && $sub === 'ticket-watch') {
            gamesTicketWatch();
            return;
        }
    }

    // GET /api/games/{id}  and  POST /api/games/{id}/action
    // We treat any /api/games/<value> where value is not a reserved word as a
    // single-game lookup. Reserved: 'categories', 'sync', 'transactions',
    // 'unpause-all'.
    $reservedActions = ['categories', 'sync', 'transactions', 'unpause-all', ''];
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

    if ($method === 'POST' && $action === 'unpause-all' && $sub === '') {
        // One-tap "turn the floor back on" for the Tag Board.
        Auth::requireAccess('manual_control');
        gamesUnpauseAll();
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

        // Game names for the audit entries below — one lookup for the batch
        // so the Action Log reads "Skee Ball 3", not a bare CenterEdge id.
        $gameNames = [];
        list($nameIn, $nameParams) = payoutInClause(array_map('strval', array_keys($changes)), 0);
        foreach (DB::query(
            'SELECT game_id, game_name FROM game_state_cache WHERE game_id IN ' . $nameIn,
            $nameParams
        ) as $nr) {
            $gameNames[(string)$nr['game_id']] = (string)$nr['game_name'];
        }
        $actorId = Auth::check()['id'] ?? null;

        // Update cache only for games that actually succeeded, and reconcile
        // the retry queue: clear retries on success, queue/refresh on failure.
        // Older code wrote optimistically for every requested change, which
        // meant the cache would lie if the upstream API rejected the patch.
        $errors = $result['errors'] ?? [];
        foreach ($changes as $gameId => $status) {
            $gid = (string)$gameId;
            $ok = !isset($errors[$gameId]) && !isset($errors[$gid]);
            $errorText = null;
            if ($ok) {
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
            // Audit every manual status change — floor staff tag games in and
            // out from their phones through this endpoint, so the Action Log
            // must show who changed what (and what the upstream API rejected).
            // Direct INSERT rather than DB::auditLog: the log UI's Details
            // cell renders the top-level game_name / error_message columns,
            // which the generic wrapper never populates.
            try {
                DB::execute(
                    'INSERT INTO action_log (source, action, game_id, game_name, success, error_message, details)
                     VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6)',
                    [
                        'game-status',
                        $status === 'enabled' ? 'game_enabled'
                            : ($status === 'paused' ? 'game_paused' : 'game_tagged_out'),
                        $gid,
                        $gameNames[$gid] ?? ('Game ' . $gid),
                        $ok ? 1 : 0,
                        $errorText,
                        json_encode(['actor_user_id' => $actorId, 'status' => $status]),
                    ]
                );
            } catch (Exception $e) {
                // Auditing must never break the status change itself.
                error_log('game-status audit log failed: ' . $e->getMessage());
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

    // The live GET /games/{id} endpoint is OPTIONAL per the CenterEdge spec
    // (capabilities.games.getSingleGame, default false). Many card systems
    // return HTTP 404 for it — which made the game detail modal show "Game
    // not found" for EVERY game. So we only call it when capabilities
    // actually advertise support, and otherwise (or on any failure) serve
    // the game from the local cache, which holds everything the modal needs.
    if ($client->isConfigured()) {
        $supportsSingle = false;
        try {
            $caps = $client->getCapabilitiesCached();
            $supportsSingle = !empty($caps['games']['getSingleGame']);
        } catch (Exception $e) {
            // Capabilities unavailable — fall back to cache below.
        }

        if ($supportsSingle) {
            try {
                echo json_encode($client->getGame($gameId));
                return;
            } catch (RuntimeException $e) {
                // Best-effort: log non-404s, then fall back to cache so the
                // modal still renders instead of dead-ending on a live error.
                if (strpos($e->getMessage(), 'HTTP 404') === false) {
                    error_log('gamesGetOne live fetch failed, using cache: ' . $e->getMessage());
                }
            }
        }
    }

    // Cache fallback — shape the row exactly like a CenterEdge Game object.
    $row = DB::queryOne(
        'SELECT game_id, game_name, operation_status, categories,
                supported_actions, virtual_play_enabled
         FROM game_state_cache WHERE game_id = :p0',
        [$gameId]
    );
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Game not found', 'code' => 'gameNotFound']);
        return;
    }

    $cats = json_decode((string)($row['categories'] ?? '[]'), true);
    $actions = json_decode((string)($row['supported_actions'] ?? '[]'), true);
    echo json_encode([
        'id'                 => (string)$row['game_id'],
        'name'               => (string)$row['game_name'],
        'operationStatus'    => (string)$row['operation_status'],
        'categories'         => is_array($cats) ? $cats : [],
        'supportedActions'   => is_array($actions) ? $actions : [],
        'virtualPlayEnabled' => (bool)$row['virtual_play_enabled'],
        'from_cache'         => true,
    ]);
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
                   SUM(CASE WHEN t.transaction_time >= :p1 THEN t.regular_points + t.bonus_points ELSE 0 END) AS points_today,
                   MAX(t.transaction_time) AS last_play
            FROM game_play_transactions t
            WHERE t.game_id != \'\'
            GROUP BY t.game_id';

    $rows = DB::query($sql, [$hourCutoff, $todayCutoff, $weekCutoff]);

    // Redemption classification for the per-game payout column — the venue's
    // "Redemption" grouping (category/pause group), shared with the gauge.
    $redemptionIds = Reporting::redemptionGameIds();

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
            'points_today'  => (float)$r['points_today'],
            'is_redemption' => isset($redemptionIds[$gid]),
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

    // Authoritative hourly bins for the dashboard. The peak-hour tile, hero
    // sparkline, and unique-cards chip used to derive these from the
    // recent-feed cache in the browser, which is capped at 500 rows — on a
    // busy day that window only covers the tail of the evening, so "peak
    // hour" silently meant "peak hour among the newest 500 plays". Binning
    // is done in PHP in the venue timezone (same approach as analytics) so
    // hour boundaries match the wall clock.
    $nowLocal = new DateTime('now', $tzObj);
    $curHourStartLocal = (clone $nowLocal)->setTime((int)$nowLocal->format('G'), 0, 0);
    $recentWindowStart = (clone $curHourStartLocal)->modify('-11 hours');
    $recentStartTs = $recentWindowStart->getTimestamp();

    $binCutoffUtc = gmdate(
        'Y-m-d\TH:i:s\Z',
        min($recentStartTs, (new DateTime($todayCutoff))->getTimestamp())
    );
    $hourlyToday = [];
    for ($h = 0; $h < 24; $h++) {
        $hourlyToday[$h] = ['hour' => $h, 'plays' => 0, 'tickets' => 0.0];
    }
    $hourlyRecent = [];
    for ($i = 0; $i < 12; $i++) {
        $hourlyRecent[$i] = ['plays' => 0, 'tickets' => 0.0];
    }
    $uniqueToday = [];
    $todayLocalDate = (new DateTime('now', $tzObj))->format('Y-m-d');
    foreach (DB::query(
        'SELECT transaction_time, redemption_tickets, card_number
         FROM game_play_transactions
         WHERE transaction_time >= :p0',
        [$binCutoffUtc]
    ) as $r) {
        $tt = (string)($r['transaction_time'] ?? '');
        if ($tt === '') continue;
        try {
            $d = new DateTime($tt);
        } catch (Exception $e) {
            continue;
        }
        $ts = $d->getTimestamp();
        $tickets = (float)($r['redemption_tickets'] ?? 0);

        $d->setTimezone($tzObj);
        if ($d->format('Y-m-d') === $todayLocalDate) {
            $h = (int)$d->format('G');
            $hourlyToday[$h]['plays']   += 1;
            $hourlyToday[$h]['tickets'] += $tickets;
            $card = (string)($r['card_number'] ?? '');
            if ($card !== '' && $card !== '000000') {
                $uniqueToday[$card] = true;
            }
        }

        $idx = (int)floor(($ts - $recentStartTs) / 3600);
        if ($idx >= 0 && $idx < 12) {
            $hourlyRecent[$idx]['plays']   += 1;
            $hourlyRecent[$idx]['tickets'] += $tickets;
        }
    }
    foreach ($hourlyToday as &$hb) { $hb['tickets'] = round($hb['tickets'], 2); }
    unset($hb);
    foreach ($hourlyRecent as &$rb) { $rb['tickets'] = round($rb['tickets'], 2); }
    unset($rb);
    $totals['unique_cards_today'] = count($uniqueToday);

    echo json_encode([
        'stats'        => $stats,
        'totals'       => $totals,
        'windows'      => [
            'hour'  => $hourCutoff,
            'today' => $todayCutoff,
            'week'  => $weekCutoff,
        ],
        // 24 venue-local hour bins for today (peak-hour tile) and the last
        // 12 clock hours oldest-first (hero sparkline).
        'hourly_today'  => array_values($hourlyToday),
        'hourly_recent' => array_values($hourlyRecent),
        'last_poll_at' => $meta['last_poll'] ?? null,
        'total_cached' => (int)($meta['total_cached'] ?? 0),
        // For the per-game payout column — same threshold the gauge uses.
        'payout_target_pct' => (float)(DB::getConfig('payout_target_pct') ?: 33),
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

    // Redemption games come from the venue's "Redemption" grouping (see
    // Reporting::redemptionGameIds) — a game category or pause group — not a
    // "dispensed a ticket once" heuristic, so point-heavy non-redemption
    // games can't dilute the denominator.
    $redemptionIds = array_keys(Reporting::redemptionGameIds());
    $redemptionGames = count($redemptionIds);

    if ($redemptionGames === 0) {
        // No redemption games classified — every ratio is undefined.
        $raw = []; $rollMonth = []; $rollAll = [];
    } else {
        // Bind the redemption IDs as :p3.. after the three date cutoffs.
        list($inClause, $idParams) = payoutInClause($redemptionIds, 3);
        $raw = DB::queryOne(
            'SELECT
                SUM(CASE WHEN transaction_time >= :p0 THEN redemption_tickets ELSE 0 END) AS t_hour,
                SUM(CASE WHEN transaction_time >= :p0 THEN regular_points + bonus_points ELSE 0 END) AS p_hour,
                SUM(CASE WHEN transaction_time >= :p1 THEN redemption_tickets ELSE 0 END) AS t_today,
                SUM(CASE WHEN transaction_time >= :p1 THEN regular_points + bonus_points ELSE 0 END) AS p_today,
                SUM(CASE WHEN transaction_time >= :p2 THEN redemption_tickets ELSE 0 END) AS t_week,
                SUM(CASE WHEN transaction_time >= :p2 THEN regular_points + bonus_points ELSE 0 END) AS p_week
             FROM game_play_transactions
             WHERE game_id IN ' . $inClause,
            array_merge([$hourCutoff, $todayCutoff, $weekCutoff], $idParams)
        );

        list($inClauseM, $idParamsM) = payoutInClause($redemptionIds, 2);
        $rollMonth = DB::queryOne(
            'SELECT SUM(tickets) AS t, SUM(regular_points + bonus_points) AS p
             FROM game_daily_stats
             WHERE stat_date >= :p0 AND stat_date < :p1
               AND game_id IN ' . $inClauseM,
            array_merge([$monthStartDate, $todayLocalDate], $idParamsM)
        );

        list($inClauseA, $idParamsA) = payoutInClause($redemptionIds, 1);
        $rollAll = DB::queryOne(
            'SELECT SUM(tickets) AS t, SUM(regular_points + bonus_points) AS p
             FROM game_daily_stats
             WHERE stat_date < :p0
               AND game_id IN ' . $inClauseA,
            array_merge([$todayLocalDate], $idParamsA)
        );
    }

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
 * Ticket-farming watch: today's top ticket-earning cards, each with the
 * context that separates a lucky jackpot from active farming (ticket-jam
 * exploits, misconfigured payouts being hammered):
 *
 *   - plays / tickets / distinct games / tickets-per-play today
 *   - top game and what share of the card's plays it absorbs
 *   - the card's tickets-per-play ON that game vs the game's average
 *     across ALL cards today (ratio_multiplier)
 *
 * Three transparent signals, each reported so the UI can explain itself:
 *   volume        — tickets_today >= threshold (config ticket_watch_min_tickets,
 *                   default 2500)
 *   concentration — >= 8 plays with >= 75% of them on one game
 *   hot_ratio     — >= 5 plays on the top game at >= 1.5x that game's
 *                   average tickets-per-play
 *
 * watch = at least TWO signals, so a single legitimate big win (volume
 * only — one play can't trip the repetition signals) never gets flagged.
 * Card numbers appear here exactly as they already do in the live feed,
 * behind the same analytics gate. No cash fields involved.
 */
function gamesTicketWatch(): void {
    $tz = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
    try {
        $tzObj = new DateTimeZone($tz);
    } catch (Exception $e) {
        $tzObj = new DateTimeZone('UTC');
    }
    $todayCutoff = (new DateTime('today 00:00:00', $tzObj))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

    $minTickets = (float)(DB::getConfig('ticket_watch_min_tickets') ?: 2500);

    // Top earners today. 000000 = cardless/credit-card plays per spec — no
    // card to farm onto, exclude.
    $cards = DB::query(
        'SELECT card_number,
                COUNT(*) AS plays,
                SUM(redemption_tickets) AS tickets,
                COUNT(DISTINCT game_id) AS games,
                MIN(transaction_time) AS first_play,
                MAX(transaction_time) AS last_play
         FROM game_play_transactions
         WHERE transaction_time >= :p0
           AND card_number != \'\' AND card_number != \'000000\'
         GROUP BY card_number
         HAVING tickets > 0
         ORDER BY tickets DESC
         LIMIT 10',
        [$todayCutoff]
    );

    // Per-game averages today are shared across rows — compute once.
    $gameAvg = [];
    foreach (DB::query(
        'SELECT game_id, COUNT(*) AS plays, SUM(redemption_tickets) AS tickets
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND game_id != \'\'
         GROUP BY game_id',
        [$todayCutoff]
    ) as $g) {
        $gameAvg[(string)$g['game_id']] = [
            'plays'   => (int)$g['plays'],
            'tickets' => (float)$g['tickets'],
        ];
    }

    $out = [];
    foreach ($cards as $c) {
        $cardNumber = (string)$c['card_number'];
        $plays = (int)$c['plays'];
        $tickets = (float)$c['tickets'];

        // The card's most ticket-productive game today.
        $top = DB::queryOne(
            'SELECT t.game_id,
                    COALESCE(NULLIF(MAX(t.game_description), \'\'), MAX(c.game_name), t.game_id) AS game_name,
                    COUNT(*) AS plays,
                    SUM(t.redemption_tickets) AS tickets
             FROM game_play_transactions t
             LEFT JOIN game_state_cache c ON c.game_id = t.game_id
             WHERE t.transaction_time >= :p0 AND t.card_number = :p1 AND t.game_id != \'\'
             GROUP BY t.game_id
             ORDER BY tickets DESC
             LIMIT 1',
            [$todayCutoff, $cardNumber]
        );

        $topGameId = (string)($top['game_id'] ?? '');
        $topPlays = (int)($top['plays'] ?? 0);
        $topTickets = (float)($top['tickets'] ?? 0);
        $topShare = $plays > 0 ? $topPlays / $plays : 0;
        $cardTpp = $topPlays > 0 ? $topTickets / $topPlays : 0;

        $avg = $gameAvg[$topGameId] ?? ['plays' => 0, 'tickets' => 0.0];
        $gameTpp = $avg['plays'] > 0 ? $avg['tickets'] / $avg['plays'] : 0;
        $multiplier = $gameTpp > 0 ? $cardTpp / $gameTpp : 1.0;

        $flags = [];
        if ($tickets >= $minTickets) {
            $flags[] = 'volume';
        }
        if ($plays >= 8 && $topShare >= 0.75) {
            $flags[] = 'concentration';
        }
        if ($topPlays >= 5 && $multiplier >= 1.5) {
            $flags[] = 'hot_ratio';
        }

        $out[] = [
            'card_number'      => $cardNumber,
            'plays'            => $plays,
            'tickets'          => round($tickets, 2),
            'games'            => (int)$c['games'],
            'tickets_per_play' => $plays > 0 ? round($tickets / $plays, 1) : 0,
            'top_game_id'      => $topGameId,
            'top_game_name'    => (string)($top['game_name'] ?? ''),
            'top_game_plays'   => $topPlays,
            'top_game_share'   => round($topShare * 100),
            'ratio_multiplier' => round($multiplier, 1),
            'flags'            => $flags,
            'watch'            => count($flags) >= 2,
            'first_play'       => $c['first_play'] ?: null,
            'last_play'        => $c['last_play'] ?: null,
        ];
    }

    echo json_encode([
        'cards'       => $out,
        'min_tickets' => $minTickets,
        'window'      => 'today',
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
