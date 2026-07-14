<?php
/**
 * API: Schedule CRUD for recurring weekly pause windows.
 * GET    /api/schedules        — List all schedules (optionally filtered by group)
 * POST   /api/schedules        — Create schedule(s) (supports bulk: multiple days)
 * PUT    /api/schedules/{id}   — Update schedule
 * DELETE /api/schedules/{id}   — Delete schedule
 */

require_once __DIR__ . '/../lib/validator.php';

function handleSchedules(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    if ($method === 'GET') {
        Auth::requireAnyAccess(['view_schedules', 'schedules_manage']);
    }

    $scheduleId = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;

    switch ($method) {
        case 'GET':
            listSchedules();
            break;
        case 'POST':
            // Schedule windows define the venue's automation — structural
            // changes are manager/admin only (same policy as pause groups).
            Auth::requireAccess('schedules_manage');
            createSchedules($input);
            break;
        case 'PUT':
            Auth::requireAccess('schedules_manage');
            if (!$scheduleId) {
                http_response_code(400);
                echo json_encode(['error' => 'Schedule ID required']);
                return;
            }
            updateSchedule($scheduleId, $input);
            break;
        case 'DELETE':
            Auth::requireAccess('schedules_manage');
            if (!$scheduleId) {
                http_response_code(400);
                echo json_encode(['error' => 'Schedule ID required']);
                return;
            }
            deleteSchedule($scheduleId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function listSchedules(): void {
    $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

    $sql = 'SELECT s.*, g.name as group_name
            FROM schedules s
            JOIN pause_groups g ON g.id = s.pause_group_id';
    $params = [];

    if ($groupId) {
        $sql .= ' WHERE s.pause_group_id = :p0';
        $params[] = $groupId;
    }

    $sql .= ' ORDER BY s.pause_group_id, s.day_of_week, s.start_time';

    $schedules = DB::query($sql, $params);
    echo json_encode(['schedules' => $schedules]);
}

function createSchedules(?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $groupId   = Validator::requireInt($input, 'pause_group_id');
    $startTime = Validator::requireTime($input, 'start_time');
    $endTime   = Validator::requireTime($input, 'end_time');
    $isActive  = isset($input['is_active']) ? (filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1;

    // Validate group exists
    $group = DB::queryOne('SELECT id FROM pause_groups WHERE id = :p0', [$groupId]);
    if (!$group) {
        throw new RuntimeException('Pause group not found.');
    }

    // Validate start_time < end_time (no midnight crossing)
    if ($startTime >= $endTime) {
        throw new RuntimeException('Start time must be before end time. For overnight schedules, create two entries.');
    }

    // Support bulk creation: days_of_week array or single day_of_week
    $daysOfWeek = [];
    if (isset($input['days_of_week']) && is_array($input['days_of_week'])) {
        foreach ($input['days_of_week'] as $d) {
            $daysOfWeek[] = Validator::requireDayOfWeek(['d' => $d], 'd');
        }
    } else {
        $daysOfWeek[] = Validator::requireDayOfWeek($input, 'day_of_week');
    }

    $created = [];
    foreach ($daysOfWeek as $dow) {
        DB::execute(
            'INSERT INTO schedules (pause_group_id, day_of_week, start_time, end_time, is_active) VALUES (:p0, :p1, :p2, :p3, :p4)',
            [$groupId, $dow, $startTime, $endTime, $isActive]
        );
        $created[] = DB::lastInsertId();
    }

    // Replan today if any schedule affects today; enforce state immediately
    replanIfNeeded($daysOfWeek, [$groupId]);

    DB::auditLog('admin', 'schedule_created', Auth::userId(), [
        'schedule_ids' => $created,
        'group_id'     => $groupId,
        'days_of_week' => $daysOfWeek,
        'window'       => $startTime . '-' . $endTime,
        'is_active'    => $isActive,
    ], true, $groupId);

    http_response_code(201);
    $schedules = [];
    foreach ($created as $id) {
        $schedules[] = DB::queryOne('SELECT s.*, g.name as group_name FROM schedules s JOIN pause_groups g ON g.id = s.pause_group_id WHERE s.id = :p0', [$id]);
    }
    echo json_encode(['schedules' => $schedules]);
}

function updateSchedule(int $scheduleId, ?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $existing = DB::queryOne('SELECT * FROM schedules WHERE id = :p0', [$scheduleId]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Schedule not found']);
        return;
    }

    $dayOfWeek = Validator::requireDayOfWeek($input, 'day_of_week');
    $startTime = Validator::requireTime($input, 'start_time');
    $endTime   = Validator::requireTime($input, 'end_time');
    $isActive  = isset($input['is_active']) ? (filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1;

    if ($startTime >= $endTime) {
        throw new RuntimeException('Start time must be before end time.');
    }

    DB::execute(
        'UPDATE schedules SET day_of_week = :p0, start_time = :p1, end_time = :p2, is_active = :p3, updated_at = datetime(\'now\') WHERE id = :p4',
        [$dayOfWeek, $startTime, $endTime, $isActive, $scheduleId]
    );

    // Replan if the old or new day is today; enforce state immediately
    replanIfNeeded([$existing['day_of_week'], $dayOfWeek], [$existing['pause_group_id']]);

    DB::auditLog('admin', 'schedule_updated', Auth::userId(), [
        'schedule_id' => $scheduleId,
        'group_id'    => (int)$existing['pause_group_id'],
        'day_of_week' => $dayOfWeek,
        'window'      => $startTime . '-' . $endTime,
        'is_active'   => $isActive,
    ], true, (int)$existing['pause_group_id']);

    $schedule = DB::queryOne('SELECT s.*, g.name as group_name FROM schedules s JOIN pause_groups g ON g.id = s.pause_group_id WHERE s.id = :p0', [$scheduleId]);
    echo json_encode($schedule);
}

function deleteSchedule(int $scheduleId): void {
    $existing = DB::queryOne('SELECT * FROM schedules WHERE id = :p0', [$scheduleId]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Schedule not found']);
        return;
    }

    DB::execute('DELETE FROM schedules WHERE id = :p0', [$scheduleId]);

    // Replan if the deleted schedule was for today; enforce state immediately
    replanIfNeeded([$existing['day_of_week']], [$existing['pause_group_id']]);

    DB::auditLog('admin', 'schedule_deleted', Auth::userId(), [
        'schedule_id' => $scheduleId,
        'group_id'    => (int)$existing['pause_group_id'],
        'day_of_week' => (int)$existing['day_of_week'],
        'window'      => $existing['start_time'] . '-' . $existing['end_time'],
    ], true, (int)$existing['pause_group_id']);

    echo json_encode(['success' => true]);
}

/**
 * Trigger replan if any of the given days matches today, and immediately
 * enforce the correct state for affected groups so changes take effect
 * without waiting for the next watchdog cycle.
 *
 * $affectedGroupIds — group IDs whose schedules changed.  When provided,
 * enforceGroupState() is called for each so the API actually applies the
 * new schedule right away (mirroring how override CRUD already works).
 */
function replanIfNeeded(array $daysOfWeek, array $affectedGroupIds = []): void {
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $now = new DateTime('now', new DateTimeZone($tz));
    $todayDow = (int)$now->format('w'); // 0=Sunday

    if (in_array($todayDow, array_map('intval', $daysOfWeek))) {
        // Load scheduler and replan
        if (file_exists(__DIR__ . '/../lib/scheduler.php')) {
            require_once __DIR__ . '/../lib/scheduler.php';
            try {
                Scheduler::replanToday();
            } catch (Exception $e) {
                error_log('Replan failed: ' . $e->getMessage());
            }

            // Immediately enforce the desired state for each affected group
            // so the user sees the effect right away instead of waiting up to
            // 60 seconds for the watchdog.
            foreach ($affectedGroupIds as $gid) {
                try {
                    Scheduler::enforceGroupState((int)$gid);
                } catch (Exception $e) {
                    error_log("Enforce state after schedule change failed for group #$gid: " . $e->getMessage());
                }
            }
        }
    }
}
