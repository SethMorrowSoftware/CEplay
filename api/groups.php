<?php
/**
 * API: Pause group CRUD + manual actions.
 * GET    /api/groups              — List all groups with member counts and current state
 * GET    /api/groups/{id}         — Single group with categories + games
 * POST   /api/groups              — Create group
 * POST   /api/groups/{id}/pause   — Immediately pause all games in group
 * POST   /api/groups/{id}/unpause — Immediately unpause all games in group
 * PUT    /api/groups/{id}         — Update group
 * DELETE /api/groups/{id}         — Delete group
 */

require_once __DIR__ . '/../lib/validator.php';

function handleGroups(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $groupId = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;
    $action = $parts[1] ?? null;

    // Handle POST /api/groups/{id}/pause or /unpause
    if ($method === 'POST' && $groupId && in_array($action, ['pause', 'unpause'], true)) {
        manualGroupAction($groupId, $action);
        return;
    }

    // Handle POST /api/groups/{id}/enforce — immediate state enforcement
    if ($method === 'POST' && $groupId && $action === 'enforce') {
        enforceGroupAction($groupId);
        return;
    }

    // Handle POST /api/groups/{id}/clear-manual-override
    if ($method === 'POST' && $groupId && $action === 'clear-manual-override') {
        clearManualOverrideAction($groupId);
        return;
    }

    switch ($method) {
        case 'GET':
            if ($groupId) {
                getGroup($groupId);
            } else {
                listGroups();
            }
            break;
        case 'POST':
            createGroup($input);
            break;
        case 'PUT':
            if (!$groupId) {
                http_response_code(400);
                echo json_encode(['error' => 'Group ID required']);
                return;
            }
            updateGroup($groupId, $input);
            break;
        case 'DELETE':
            if (!$groupId) {
                http_response_code(400);
                echo json_encode(['error' => 'Group ID required']);
                return;
            }
            deleteGroup($groupId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function listGroups(): void {
    $groups = DB::query(
        'SELECT g.id, g.name, g.description, g.is_active, g.manual_override_action, g.manual_override_at, g.created_at, g.updated_at,
                (SELECT COUNT(*) FROM pause_group_categories WHERE pause_group_id = g.id) as category_count,
                (SELECT COUNT(*) FROM pause_group_games WHERE pause_group_id = g.id) as game_count,
                (SELECT COUNT(*) FROM schedules WHERE pause_group_id = g.id AND is_active = 1) as schedule_count
         FROM pause_groups g
         ORDER BY g.name ASC'
    );

    // Enrich each group with live state, schedule context, and override info
    require_once __DIR__ . '/../lib/centeredge_client.php';
    require_once __DIR__ . '/../lib/scheduler.php';

    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $__savedTz = date_default_timezone_get();
    date_default_timezone_set($tz);
    $now = new DateTime();
    $todayDow = (int)$now->format('w');
    // Schedules/overrides are stored with minute precision (YYYY-MM-DD HH:MM / HH:MM).
    // Match that format so string comparisons stay consistent at boundary minutes.
    $currentTime = $now->format('H:i');
    $currentDatetime = $now->format('Y-m-d H:i');

    foreach ($groups as &$group) {
        $gid = (int)$group['id'];

        // Resolve full member lists (games via categories + individual; kiosks).
        // Game and kiosk counts are kept separate so the UI can present them
        // distinctly. The combined `effective_state` aggregates across both
        // pools so a kiosk-only or mixed group still reports a meaningful
        // pause state to the dashboard's "Pause Group" button.
        $gameIds = Scheduler::resolveGroupGames($gid);
        $kioskIds = Scheduler::resolveGroupKiosks($gid);

        $gameEnabled = 0;
        $gamePaused = 0;
        $gameOos = 0;
        foreach ($gameIds as $gameId) {
            $cached = DB::queryOne('SELECT operation_status FROM game_state_cache WHERE game_id = :p0', [$gameId]);
            if (!$cached) continue;
            if ($cached['operation_status'] === 'enabled') $gameEnabled++;
            elseif ($cached['operation_status'] === 'paused') $gamePaused++;
            elseif ($cached['operation_status'] === 'outOfService') $gameOos++;
        }

        $kioskEnabled = 0;
        $kioskPaused = 0;
        $kioskOos = 0;
        $kioskUnknown = 0;
        foreach ($kioskIds as $kioskId) {
            $cached = DB::queryOne('SELECT operation_status FROM kiosk_state_cache WHERE kiosk_id = :p0', [$kioskId]);
            if (!$cached) continue;
            $st = $cached['operation_status'];
            if ($st === 'enabled') $kioskEnabled++;
            elseif ($st === 'paused') $kioskPaused++;
            elseif ($st === 'outOfService') $kioskOos++;
            else $kioskUnknown++;
        }

        $combinedEnabled = $gameEnabled + $kioskEnabled;
        $combinedPaused = $gamePaused + $kioskPaused;
        $combinedOos = $gameOos + $kioskOos;
        $combinedTotal = $combinedEnabled + $combinedPaused + $combinedOos;

        $group['effective_state'] = $combinedTotal === 0 ? 'empty'
            : ($combinedPaused > 0 && $combinedEnabled === 0 ? 'paused'
            : ($combinedEnabled > 0 && $combinedPaused === 0 ? 'enabled' : 'mixed'));
        $group['game_stats'] = [
            'total' => $gameEnabled + $gamePaused + $gameOos,
            'enabled' => $gameEnabled,
            'paused' => $gamePaused,
            'out_of_service' => $gameOos,
        ];
        $group['kiosk_stats'] = [
            'total' => count($kioskIds),
            'enabled' => $kioskEnabled,
            'paused' => $kioskPaused,
            'out_of_service' => $kioskOos,
            'unknown' => $kioskUnknown,
        ];
        $group['combined_stats'] = [
            'total' => $combinedTotal,
            'enabled' => $combinedEnabled,
            'paused' => $combinedPaused,
            'out_of_service' => $combinedOos,
        ];
        $group['game_ids'] = array_values($gameIds);
        $group['kiosk_ids'] = array_values($kioskIds);

        // Next scheduled transition today.
        // Schedule windows define active (unpaused) hours:
        //   start_time → unpause (games become active)
        //   end_time   → pause   (active window ends)
        $nextTransition = null;
        if ($group['is_active']) {
            $todaySchedules = DB::query(
                'SELECT start_time, end_time FROM schedules
                 WHERE pause_group_id = :p0 AND day_of_week = :p1 AND is_active = 1
                 ORDER BY start_time ASC',
                [$gid, $todayDow]
            );
            foreach ($todaySchedules as $sched) {
                if ($sched['start_time'] > $currentTime) {
                    $nextTransition = ['time' => $sched['start_time'], 'action' => 'unpause'];
                    break;
                }
                if ($sched['end_time'] > $currentTime) {
                    $nextTransition = ['time' => $sched['end_time'], 'action' => 'pause'];
                    break;
                }
            }
        }
        $group['next_transition'] = $nextTransition;

        // Active override
        $activeOverride = null;
        if ($group['is_active']) {
            $activeOverride = DB::queryOne(
                'SELECT name, action, end_datetime FROM schedule_overrides
                 WHERE pause_group_id = :p0 AND start_datetime <= :p1 AND end_datetime > :p1
                 ORDER BY end_datetime DESC LIMIT 1',
                [$gid, $currentDatetime]
            );
        }
        $group['active_override'] = $activeOverride ?: null;

        // Manual override
        $group['manual_override'] = null;
        if ($group['manual_override_action']) {
            $group['manual_override'] = [
                'action' => $group['manual_override_action'],
                'at'     => $group['manual_override_at'],
            ];
        }
        unset($group['manual_override_action'], $group['manual_override_at']);
    }
    unset($group);
    date_default_timezone_set($__savedTz);

    echo json_encode(['groups' => $groups]);
}

/**
 * Immediately pause or unpause all games in a group.
 */
function manualGroupAction(int $groupId, string $action): void {
    $group = DB::queryOne('SELECT id, name, is_active FROM pause_groups WHERE id = :p0', [$groupId]);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }

    require_once __DIR__ . '/../lib/centeredge_client.php';
    require_once __DIR__ . '/../lib/scheduler.php';

    $results = Scheduler::executeImmediate($groupId, $action, 'manual');

    echo json_encode([
        'success' => empty($results['errors']),
        'action'  => $action,
        'group_id' => $groupId,
        'group_name' => $group['name'],
        'changed' => count($results['changed']),
        'skipped' => count($results['skipped']),
        'errors'  => count($results['errors']),
        'details' => $results,
    ]);
}

/**
 * Immediately enforce the correct state for a group based on current
 * schedules and overrides.  Called by the frontend when an override
 * expires so the transition happens within seconds rather than waiting
 * for the next watchdog cycle.
 */
function enforceGroupAction(int $groupId): void {
    $group = DB::queryOne('SELECT id, name, is_active FROM pause_groups WHERE id = :p0', [$groupId]);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }

    require_once __DIR__ . '/../lib/centeredge_client.php';
    require_once __DIR__ . '/../lib/scheduler.php';

    $results = Scheduler::enforceGroupState($groupId);

    echo json_encode([
        'success' => empty($results['errors']),
        'group_id' => $groupId,
        'group_name' => $group['name'],
        'changed' => count($results['changed'] ?? []),
        'skipped' => count($results['skipped'] ?? []),
        'errors'  => count($results['errors'] ?? []),
        'details' => $results,
    ]);
}

/**
 * Clear a manual override for a group and enforce the scheduled state.
 */
function clearManualOverrideAction(int $groupId): void {
    $group = DB::queryOne('SELECT id, name FROM pause_groups WHERE id = :p0', [$groupId]);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }

    require_once __DIR__ . '/../lib/centeredge_client.php';
    require_once __DIR__ . '/../lib/scheduler.php';

    Scheduler::clearManualOverride($groupId);
    $results = Scheduler::enforceGroupState($groupId, false);

    echo json_encode([
        'success' => true,
        'group_id' => $groupId,
        'group_name' => $group['name'],
        'enforced' => $results,
    ]);
}

function getGroup(int $groupId): void {
    $group = DB::queryOne('SELECT * FROM pause_groups WHERE id = :p0', [$groupId]);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }

    $group['categories'] = DB::query(
        'SELECT id, category_id, category_name FROM pause_group_categories WHERE pause_group_id = :p0',
        [$groupId]
    );
    $group['games'] = DB::query(
        'SELECT id, game_id, game_name FROM pause_group_games WHERE pause_group_id = :p0',
        [$groupId]
    );
    $group['kiosks'] = DB::query(
        'SELECT id, kiosk_id, kiosk_name FROM pause_group_kiosks WHERE pause_group_id = :p0',
        [$groupId]
    );
    $group['schedules'] = DB::query(
        'SELECT * FROM schedules WHERE pause_group_id = :p0 ORDER BY day_of_week, start_time',
        [$groupId]
    );

    echo json_encode($group);
}

function createGroup(?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $name = Validator::requireString($input, 'name');
    $description = Validator::optionalString($input, 'description');
    $isActive = isset($input['is_active']) ? (filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1;
    $categoryIds = Validator::optionalIntArray($input, 'category_ids');
    $gameIds = Validator::optionalStringArray($input, 'game_ids');
    $kioskIds = Validator::optionalStringArray($input, 'kiosk_ids');

    $db = DB::getInstance();
    $db->exec('BEGIN TRANSACTION');

    try {
        DB::execute(
            'INSERT INTO pause_groups (name, description, is_active) VALUES (:p0, :p1, :p2)',
            [$name, $description, $isActive]
        );
        $groupId = DB::lastInsertId();

        // Insert category links
        foreach ($categoryIds as $catId) {
            $catName = getCategoryName($catId);
            DB::execute(
                'INSERT INTO pause_group_categories (pause_group_id, category_id, category_name) VALUES (:p0, :p1, :p2)',
                [$groupId, $catId, $catName]
            );
        }

        // Insert individual game links
        foreach ($gameIds as $gameId) {
            $gameName = getGameName($gameId);
            DB::execute(
                'INSERT INTO pause_group_games (pause_group_id, game_id, game_name) VALUES (:p0, :p1, :p2)',
                [$groupId, $gameId, $gameName]
            );
        }

        // Insert kiosk links
        foreach ($kioskIds as $kioskId) {
            $kioskName = getKioskName($kioskId);
            DB::execute(
                'INSERT INTO pause_group_kiosks (pause_group_id, kiosk_id, kiosk_name) VALUES (:p0, :p1, :p2)',
                [$groupId, $kioskId, $kioskName]
            );
        }

        $db->exec('COMMIT');

        http_response_code(201);
        getGroup($groupId);
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function updateGroup(int $groupId, ?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $existing = DB::queryOne('SELECT id FROM pause_groups WHERE id = :p0', [$groupId]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }

    $name = Validator::requireString($input, 'name');
    $description = Validator::optionalString($input, 'description');
    $isActive = isset($input['is_active']) ? (filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1;
    $categoryIds = Validator::optionalIntArray($input, 'category_ids');
    $gameIds = Validator::optionalStringArray($input, 'game_ids');
    $kioskIds = Validator::optionalStringArray($input, 'kiosk_ids');

    $db = DB::getInstance();
    $db->exec('BEGIN TRANSACTION');

    try {
        DB::execute(
            'UPDATE pause_groups SET name = :p0, description = :p1, is_active = :p2, updated_at = datetime(\'now\') WHERE id = :p3',
            [$name, $description, $isActive, $groupId]
        );

        // Replace category links
        DB::execute('DELETE FROM pause_group_categories WHERE pause_group_id = :p0', [$groupId]);
        foreach ($categoryIds as $catId) {
            $catName = getCategoryName($catId);
            DB::execute(
                'INSERT INTO pause_group_categories (pause_group_id, category_id, category_name) VALUES (:p0, :p1, :p2)',
                [$groupId, $catId, $catName]
            );
        }

        // Replace individual game links
        DB::execute('DELETE FROM pause_group_games WHERE pause_group_id = :p0', [$groupId]);
        foreach ($gameIds as $gameId) {
            $gameName = getGameName($gameId);
            DB::execute(
                'INSERT INTO pause_group_games (pause_group_id, game_id, game_name) VALUES (:p0, :p1, :p2)',
                [$groupId, $gameId, $gameName]
            );
        }

        // Replace kiosk links
        DB::execute('DELETE FROM pause_group_kiosks WHERE pause_group_id = :p0', [$groupId]);
        foreach ($kioskIds as $kioskId) {
            $kioskName = getKioskName($kioskId);
            DB::execute(
                'INSERT INTO pause_group_kiosks (pause_group_id, kiosk_id, kiosk_name) VALUES (:p0, :p1, :p2)',
                [$groupId, $kioskId, $kioskName]
            );
        }

        $db->exec('COMMIT');
        getGroup($groupId);
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function deleteGroup(int $groupId): void {
    $existing = DB::queryOne('SELECT id, name FROM pause_groups WHERE id = :p0', [$groupId]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }

    DB::execute('DELETE FROM pause_groups WHERE id = :p0', [$groupId]);
    DB::auditLog('admin', 'group_deleted', null, ['group_id' => $groupId, 'group_name' => $existing['name']]);
    echo json_encode(['success' => true]);
}

/**
 * Look up a category name from CenterEdge or return a default.
 * Uses a static cache so repeated calls during group create/update
 * only hit the API once.
 */
function getCategoryName(int $categoryId): string {
    static $categoryMap = null;

    if ($categoryMap === null) {
        $categoryMap = [];
        try {
            $client = new CenterEdgeClient();
            if ($client->isConfigured()) {
                $categories = $client->getCategories();
                foreach ($categories as $cat) {
                    $cid = (int)($cat['id'] ?? 0);
                    $categoryMap[$cid] = $cat['name'] ?? "Category $cid";
                }
            }
        } catch (Exception $e) {
            // Fall through to default name
        }
    }

    return $categoryMap[$categoryId] ?? "Category $categoryId";
}

/**
 * Look up a game name from the game_state_cache.
 */
function getGameName(string $gameId): string {
    $row = DB::queryOne('SELECT game_name FROM game_state_cache WHERE game_id = :p0', [$gameId]);
    return $row ? $row['game_name'] : "Game $gameId";
}

/**
 * Look up a kiosk name from the kiosk_state_cache.
 */
function getKioskName(string $kioskId): string {
    $row = DB::queryOne('SELECT kiosk_name FROM kiosk_state_cache WHERE kiosk_id = :p0', [$kioskId]);
    return $row ? $row['kiosk_name'] : "Kiosk $kioskId";
}
