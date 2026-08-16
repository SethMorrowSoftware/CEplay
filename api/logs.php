<?php
/**
 * API: Action log viewer with pagination and filters.
 * GET /api/logs — Paginated, filterable action log
 */

require_once __DIR__ . '/../lib/validator.php';

function handleLogs(string $method, array $parts, ?array $input): void {
    // The audit trail exposes card numbers (from PIN-check logs), usernames,
    // IP addresses, and settings/role-change payloads — gate it behind an
    // explicit permission rather than any authenticated user.
    Auth::requireAccess('view_logs');

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
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
    if (!empty($_GET['source']) && in_array($_GET['source'], ['cron', 'manual', 'override', 'schedule', 'watchdog', 'expired_override', 'game-status', 'game-action'], true)) {
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
    if (!empty($_GET['action']) && in_array($_GET['action'], ['pause', 'unpause', 'skip', 'plan_day', 'execute_action', 'user_created', 'user_updated', 'settings_updated', 'group_deleted', 'override_created', 'override_deleted', 'game_tagged_out', 'game_enabled', 'game_paused'], true)) {
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

    // Parse details JSON
    foreach ($logs as &$log) {
        if ($log['details']) {
            $log['details'] = json_decode($log['details'], true);
        }
    }

    echo json_encode([
        'logs'     => $logs,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
}
