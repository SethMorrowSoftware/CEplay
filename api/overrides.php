<?php
/**
 * API: Schedule override CRUD for temporary pause/unpause overrides.
 * GET    /api/overrides        — List overrides (active/upcoming/expired)
 * POST   /api/overrides        — Create override + immediate execution if active now
 * DELETE /api/overrides/{id}   — Delete override + replan
 */

require_once __DIR__ . '/../lib/validator.php';

function handleOverrides(string $method, array $parts, ?array $input): void {
    $user = Auth::requireAuth();

    $overrideId = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;

    switch ($method) {
        case 'GET':
            listOverrides();
            break;
        case 'POST':
            createOverride($input, $user);
            break;
        case 'DELETE':
            if (!$overrideId) {
                http_response_code(400);
                echo json_encode(['error' => 'Override ID required']);
                return;
            }
            deleteOverride($overrideId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function listOverrides(): void {
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $now = new DateTime('now', new DateTimeZone($tz));
    $nowStr = $now->format('Y-m-d H:i');

    $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

    $baseSql = 'SELECT o.*, g.name as group_name, u.display_name as created_by_name
                FROM schedule_overrides o
                JOIN pause_groups g ON g.id = o.pause_group_id
                LEFT JOIN admin_users u ON u.id = o.created_by';

    $groupFilter = '';
    $groupParam = [];
    if ($groupId) {
        $groupFilter = ' AND o.pause_group_id = :p0';
        $groupParam = [$groupId];
    }

    // Active: start <= now AND end > now
    $active = DB::query(
        $baseSql . " WHERE o.start_datetime <= :p" . count($groupParam) .
        " AND o.end_datetime > :p" . (count($groupParam) + 1) .
        $groupFilter . " ORDER BY o.end_datetime ASC",
        array_merge($groupParam, [$nowStr, $nowStr])
    );

    // Upcoming: start > now
    $upcoming = DB::query(
        $baseSql . " WHERE o.start_datetime > :p" . count($groupParam) .
        $groupFilter . " ORDER BY o.start_datetime ASC",
        array_merge($groupParam, [$nowStr])
    );

    // Expired: end <= now (last 30 days)
    $thirtyDaysAgo = (clone $now)->modify('-30 days')->format('Y-m-d H:i');
    $expired = DB::query(
        $baseSql . " WHERE o.end_datetime <= :p" . count($groupParam) .
        " AND o.end_datetime >= :p" . (count($groupParam) + 1) .
        $groupFilter . " ORDER BY o.end_datetime DESC LIMIT 50",
        array_merge($groupParam, [$nowStr, $thirtyDaysAgo])
    );

    echo json_encode([
        'active'   => $active,
        'upcoming' => $upcoming,
        'expired'  => $expired,
    ]);
}

function createOverride(?array $input, array $user): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $groupId       = Validator::requireInt($input, 'pause_group_id');
    $name          = Validator::requireString($input, 'name');
    $action        = Validator::requireEnum($input, 'action', ['pause', 'unpause']);
    $startDatetime = Validator::requireDatetime($input, 'start_datetime');
    $endDatetime   = Validator::requireDatetime($input, 'end_datetime');

    // Validate group exists
    $group = DB::queryOne('SELECT id FROM pause_groups WHERE id = :p0', [$groupId]);
    if (!$group) {
        throw new RuntimeException('Pause group not found.');
    }

    // Validate start < end
    if ($startDatetime >= $endDatetime) {
        throw new RuntimeException('Start datetime must be before end datetime.');
    }

    DB::execute(
        'INSERT INTO schedule_overrides (pause_group_id, name, action, start_datetime, end_datetime, created_by)
         VALUES (:p0, :p1, :p2, :p3, :p4, :p5)',
        [$groupId, $name, $action, $startDatetime, $endDatetime, $user['id']]
    );
    $overrideId = DB::lastInsertId();
    DB::auditLog('admin', 'override_created', null, [
        'override_id' => $overrideId, 'group_id' => $groupId,
        'action' => $action, 'start' => $startDatetime, 'end' => $endDatetime,
    ]);

    // Check if override is active now — execute immediately
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $now = new DateTime('now', new DateTimeZone($tz));
    $nowStr = $now->format('Y-m-d H:i');

    if ($startDatetime <= $nowStr && $endDatetime > $nowStr) {
        // Execute immediately
        if (file_exists(__DIR__ . '/../lib/scheduler.php')) {
            require_once __DIR__ . '/../lib/scheduler.php';
            try {
                Scheduler::executeImmediate($groupId, $action, 'override');
            } catch (Exception $e) {
                error_log('Immediate override execution failed: ' . $e->getMessage());
            }
        }
    }

    // Replan today so the override's end-time transition is scheduled.
    // If replanToday fails (lock contention), the end-time action won't
    // be in scheduled_actions.  The enforceExpiredOverrides() safety net
    // in index.php and enforceCurrentStates() in the watchdog will still
    // catch it, but we also call enforceGroupState() here as an extra
    // safeguard to make sure the group lands in the right state NOW.
    triggerReplan();

    // Verify the override's end-time action was actually created.
    // If the replan was skipped due to lock contention, ensure the group
    // will still transition correctly by queuing enforcement.
    if (file_exists(__DIR__ . '/../lib/scheduler.php')) {
        require_once __DIR__ . '/../lib/scheduler.php';
        $tz2 = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
        $today = (new DateTime('now', new DateTimeZone($tz2)))->format('Y-m-d');
        $endDate = substr($endDatetime, 0, 10);
        $endTimeOnly = substr($endDatetime, 11, 5);

        if ($endDate === $today) {
            $endAction = DB::queryOne(
                'SELECT id FROM scheduled_actions
                 WHERE pause_group_id = :p0 AND scheduled_date = :p1
                   AND scheduled_time = :p2 AND executed = 0',
                [$groupId, $today, $endTimeOnly]
            );
            if (!$endAction) {
                error_log("Override #{$overrideId}: end-time action at {$endTimeOnly} was NOT created (replan may have failed). Will rely on API safety net.");
            }
        }
    }

    http_response_code(201);
    $override = DB::queryOne(
        'SELECT o.*, g.name as group_name, u.display_name as created_by_name
         FROM schedule_overrides o
         JOIN pause_groups g ON g.id = o.pause_group_id
         LEFT JOIN admin_users u ON u.id = o.created_by
         WHERE o.id = :p0',
        [$overrideId]
    );
    echo json_encode($override);
}

function deleteOverride(int $overrideId): void {
    $existing = DB::queryOne('SELECT * FROM schedule_overrides WHERE id = :p0', [$overrideId]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Override not found']);
        return;
    }

    $groupId = (int)$existing['pause_group_id'];

    // Check if this override is currently active before deleting
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $now = new DateTime('now', new DateTimeZone($tz));
    $nowStr = $now->format('Y-m-d H:i');
    $wasActive = ($existing['start_datetime'] <= $nowStr && $existing['end_datetime'] > $nowStr);

    DB::execute('DELETE FROM schedule_overrides WHERE id = :p0', [$overrideId]);
    DB::auditLog('admin', 'override_deleted', null, [
        'override_id' => $overrideId, 'group_id' => $groupId,
        'was_active' => $wasActive,
    ]);

    // Replan today (updates future at-jobs)
    triggerReplan();

    // If the override was active, immediately enforce the correct state
    // so games don't stay in the override's state until the watchdog runs
    if ($wasActive) {
        if (file_exists(__DIR__ . '/../lib/scheduler.php')) {
            require_once __DIR__ . '/../lib/scheduler.php';
            try {
                Scheduler::enforceGroupState($groupId);
            } catch (Exception $e) {
                error_log('State enforcement after override delete failed: ' . $e->getMessage());
            }
        }
    }

    echo json_encode(['success' => true]);
}

function triggerReplan(): void {
    if (file_exists(__DIR__ . '/../lib/scheduler.php')) {
        require_once __DIR__ . '/../lib/scheduler.php';
        try {
            Scheduler::replanToday();
        } catch (Exception $e) {
            error_log('Replan failed: ' . $e->getMessage());
        }
    }
}
