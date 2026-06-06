<?php
/**
 * API: Action log viewer with pagination and filters.
 * GET /api/logs — Paginated, filterable action log
 */

require_once __DIR__ . '/../lib/validator.php';

/**
 * Action and source whitelists used for both API filtering and the
 * frontend dropdown. Centralised so adding a new audit-logged action
 * only takes one edit. Returned via /api/logs/options for the UI.
 */
const LOG_FILTER_SOURCES = [
    'cron', 'manual', 'override', 'schedule', 'watchdog', 'expired_override',
    'admin', 'auth',
    'kiosk-pause', 'kiosk-unpause', 'kiosk-out-of-service',
    'kiosk-patch', 'kiosk-action', 'kiosk-sync', 'kiosk-unpause-all',
    'game-action', 'game-patch', 'game-sync', 'game-poll', 'game-unpause-all',
    'card-lookup', 'card-pin',
    'retry',
];

const LOG_FILTER_ACTIONS = [
    // Scheduler actions
    'pause', 'unpause', 'skip', 'plan_day', 'execute_action',
    // Auth
    'login_success', 'login_failed', 'login_rate_limited', 'logout',
    // Admin user management
    'user_created', 'user_updated',
    // Settings
    'settings_updated', 'settings_test_connection',
    // Pause groups
    'group_created', 'group_updated', 'group_deleted',
    'group_pause', 'group_unpause', 'group_enforce', 'manual_override_cleared',
    // Schedules
    'schedule_created', 'schedule_updated', 'schedule_deleted',
    // Overrides
    'override_created', 'override_deleted',
    // Games
    'games_bulk_patch', 'games_sync_triggered', 'games_poll_triggered',
    'game_action_performed', 'games_unpause_all',
    // Kiosks
    'kiosk_status_changed', 'kiosk_action_failed', 'kiosk_bulk_patch',
    'kiosk_action_performed', 'kiosks_sync_triggered', 'kiosks_unpause_all',
    // Cards
    'card_viewed', 'card_transactions_viewed',
    'card_pin_probed', 'card_pin_validated',
];

function handleLogs(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    // Allow the front-end to fetch the canonical action/source lists
    // so its filter dropdowns stay in sync without hard-coding values.
    if (($parts[0] ?? '') === 'options') {
        echo json_encode([
            'sources' => LOG_FILTER_SOURCES,
            'actions' => LOG_FILTER_ACTIONS,
        ]);
        return;
    }

    // Parse pagination
    list($page, $perPage, $offset) = Validator::pagination($_GET);

    // Build dynamic WHERE clauses
    $conditions = [];
    $params = [];
    $idx = 0;

    // Date range filter (validate format before use)
    if (!empty($_GET['from'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) {
            throw new RuntimeException('Invalid "from" date format. Expected YYYY-MM-DD.');
        }
        $conditions[] = "l.timestamp >= :p$idx";
        $params[$idx] = $_GET['from'] . ' 00:00:00';
        $idx++;
    }
    if (!empty($_GET['to'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])) {
            throw new RuntimeException('Invalid "to" date format. Expected YYYY-MM-DD.');
        }
        $conditions[] = "l.timestamp <= :p$idx";
        $params[$idx] = $_GET['to'] . ' 23:59:59';
        $idx++;
    }

    // Source filter
    if (!empty($_GET['source']) && in_array($_GET['source'], LOG_FILTER_SOURCES, true)) {
        $conditions[] = "l.source = :p$idx";
        $params[$idx] = $_GET['source'];
        $idx++;
    }

    // Group filter
    if (!empty($_GET['group_id']) && is_numeric($_GET['group_id'])) {
        $conditions[] = "l.pause_group_id = :p$idx";
        $params[$idx] = (int)$_GET['group_id'];
        $idx++;
    }

    // Action filter
    if (!empty($_GET['action']) && in_array($_GET['action'], LOG_FILTER_ACTIONS, true)) {
        $conditions[] = "l.action = :p$idx";
        $params[$idx] = $_GET['action'];
        $idx++;
    }

    // Success filter
    if (isset($_GET['success']) && $_GET['success'] !== '') {
        $conditions[] = "l.success = :p$idx";
        $params[$idx] = (int)$_GET['success'];
        $idx++;
    }

    // Actor filter — by user ID or username substring
    if (!empty($_GET['actor_user_id']) && is_numeric($_GET['actor_user_id'])) {
        $conditions[] = "l.actor_user_id = :p$idx";
        $params[$idx] = (int)$_GET['actor_user_id'];
        $idx++;
    }
    if (!empty($_GET['actor_username'])) {
        $conditions[] = "l.actor_username LIKE :p$idx";
        $params[$idx] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$_GET['actor_username']) . '%';
        $idx++;
    }

    // IP address filter (exact or prefix LIKE)
    if (!empty($_GET['ip'])) {
        $conditions[] = "l.ip_address LIKE :p$idx";
        $params[$idx] = str_replace(['%', '_'], ['\\%', '\\_'], (string)$_GET['ip']) . '%';
        $idx++;
    }

    $whereClause = '';
    if (!empty($conditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $conditions);
    }

    // Count total
    $countSql = "SELECT COUNT(*) as total FROM action_log l $whereClause";
    $countRow = DB::queryOne($countSql, $params);
    $total = $countRow ? $countRow['total'] : 0;

    // Fetch page
    $sql = "SELECT l.*, g.name as group_name
            FROM action_log l
            LEFT JOIN pause_groups g ON g.id = l.pause_group_id
            $whereClause
            ORDER BY l.timestamp DESC
            LIMIT :p$idx OFFSET :p" . ($idx + 1);
    $params[$idx] = $perPage;
    $params[$idx + 1] = $offset;

    $logs = DB::query($sql, $params);

    // Parse details JSON and ensure actor fields fall back to the
    // legacy details payload for entries written before the schema
    // migration.
    foreach ($logs as &$log) {
        $detailsParsed = null;
        if ($log['details']) {
            $detailsParsed = json_decode($log['details'], true);
        }
        if (empty($log['actor_user_id']) && is_array($detailsParsed) && isset($detailsParsed['actor_user_id'])) {
            $log['actor_user_id'] = (int)$detailsParsed['actor_user_id'];
        }
        if (empty($log['actor_username']) && is_array($detailsParsed) && isset($detailsParsed['actor_username'])) {
            $log['actor_username'] = (string)$detailsParsed['actor_username'];
        }
        if (empty($log['ip_address']) && is_array($detailsParsed) && isset($detailsParsed['ip_address'])) {
            $log['ip_address'] = (string)$detailsParsed['ip_address'];
        }
        $log['details'] = $detailsParsed;
    }

    echo json_encode([
        'logs'     => $logs,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
}
