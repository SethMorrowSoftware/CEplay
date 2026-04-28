<?php
/**
 * Maintenance Tracker API — issue tracking and preventive maintenance for games and rides.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/validator.php';

function handleMaintenance(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $id = $parts[0] ?? '';
    $action = $parts[1] ?? '';

    // GET /api/maintenance/stats — dashboard summary
    if ($id === 'stats' && $method === 'GET') {
        handleMaintenanceStats();
        return;
    }

    // POST /api/maintenance/{id}/resolve
    if ($id && $action === 'resolve' && $method === 'POST') {
        handleResolveTicket($id, $input);
        return;
    }

    // POST /api/maintenance/{id}/reopen
    if ($id && $action === 'reopen' && $method === 'POST') {
        handleReopenTicket($id);
        return;
    }

    if ($id) {
        switch ($method) {
            case 'GET':
                handleGetTicket($id);
                break;
            case 'PUT':
                handleUpdateTicket($id, $input);
                break;
            case 'DELETE':
                handleDeleteTicket($id);
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
        }
        return;
    }

    switch ($method) {
        case 'GET':
            handleListTickets();
            break;
        case 'POST':
            handleCreateTicket($input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleListTickets(): void {
    $status = $_GET['status'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $assetType = $_GET['asset_type'] ?? '';
    $assetId = $_GET['asset_id'] ?? '';

    // Validate enum query parameters
    $validStatuses = ['open', 'in_progress', 'waiting_parts', 'resolved', 'closed'];
    $validPriorities = ['low', 'medium', 'high', 'critical'];
    $validAssetTypes = ['game', 'attraction', 'facility', 'other'];

    if ($status && !in_array($status, $validStatuses, true)) {
        throw new RuntimeException('Invalid status filter. Allowed: ' . implode(', ', $validStatuses));
    }
    if ($priority && !in_array($priority, $validPriorities, true)) {
        throw new RuntimeException('Invalid priority filter. Allowed: ' . implode(', ', $validPriorities));
    }
    if ($assetType && !in_array($assetType, $validAssetTypes, true)) {
        throw new RuntimeException('Invalid asset_type filter. Allowed: ' . implode(', ', $validAssetTypes));
    }

    $sql = 'SELECT mt.*, au.display_name as reporter_name
            FROM maintenance_tickets mt
            LEFT JOIN admin_users au ON mt.reported_by = au.id';
    $params = [];
    $conditions = [];
    $idx = 0;

    if ($status) {
        $conditions[] = "mt.status = :p$idx";
        $params[$idx++] = $status;
    }
    if ($priority) {
        $conditions[] = "mt.priority = :p$idx";
        $params[$idx++] = $priority;
    }
    if ($assetType) {
        $conditions[] = "mt.asset_type = :p$idx";
        $params[$idx++] = $assetType;
    }
    if ($assetId) {
        $conditions[] = "mt.asset_id = :p$idx";
        $params[$idx++] = $assetId;
    }

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY CASE mt.priority WHEN \'critical\' THEN 0 WHEN \'high\' THEN 1 WHEN \'medium\' THEN 2 WHEN \'low\' THEN 3 END, mt.created_at DESC';

    [$page, $perPage, $offset] = Validator::pagination($_GET);
    $countSql = str_replace('SELECT mt.*, au.display_name as reporter_name', 'SELECT COUNT(*) as cnt', $sql);
    $total = DB::queryOne($countSql, $params)['cnt'] ?? 0;

    $sql .= " LIMIT :p$idx OFFSET :p" . ($idx + 1);
    $params[$idx] = $perPage;
    $params[$idx + 1] = $offset;

    $tickets = DB::query($sql, $params);

    echo json_encode([
        'tickets' => $tickets,
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]
    ]);
}

function handleGetTicket(string $id): void {
    $ticket = DB::queryOne(
        'SELECT mt.*, au.display_name as reporter_name, au2.display_name as resolver_name
         FROM maintenance_tickets mt
         LEFT JOIN admin_users au ON mt.reported_by = au.id
         LEFT JOIN admin_users au2 ON mt.resolved_by = au2.id
         WHERE mt.id = :p0',
        [(int)$id]
    );
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        return;
    }
    echo json_encode($ticket);
}

function handleCreateTicket(?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $user = Auth::check();
    $assetType = Validator::requireEnum($input, 'asset_type', ['game', 'attraction', 'facility', 'other']);
    $assetId = Validator::requireString($input, 'asset_id');
    $assetName = Validator::requireString($input, 'asset_name');
    $title = Validator::requireString($input, 'title');
    $description = Validator::optionalString($input, 'description', 5000);
    $priority = isset($input['priority']) ? Validator::requireEnum($input, 'priority', ['low', 'medium', 'high', 'critical']) : 'medium';
    $assignedTo = Validator::optionalString($input, 'assigned_to');
    $dueDate = isset($input['due_date']) && $input['due_date'] ? Validator::requireDate($input, 'due_date') : null;

    DB::execute(
        'INSERT INTO maintenance_tickets (asset_type, asset_id, asset_name, title, description, priority, assigned_to, reported_by, due_date)
         VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8)',
        [$assetType, $assetId, $assetName, $title, $description, $priority, $assignedTo, $user['id'] ?? null, $dueDate]
    );

    $id = DB::lastInsertId();
    $ticket = DB::queryOne('SELECT * FROM maintenance_tickets WHERE id = :p0', [$id]);
    http_response_code(201);
    echo json_encode($ticket);
}

function handleUpdateTicket(string $id, ?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $existing = DB::queryOne('SELECT * FROM maintenance_tickets WHERE id = :p0', [(int)$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        return;
    }

    $title = isset($input['title']) ? Validator::requireString($input, 'title') : $existing['title'];
    $description = isset($input['description']) ? Validator::optionalString($input, 'description', 5000) : $existing['description'];
    $priority = isset($input['priority']) ? Validator::requireEnum($input, 'priority', ['low', 'medium', 'high', 'critical']) : $existing['priority'];
    $status = isset($input['status']) ? Validator::requireEnum($input, 'status', ['open', 'in_progress', 'waiting_parts', 'resolved', 'closed']) : $existing['status'];
    $assignedTo = isset($input['assigned_to']) ? Validator::optionalString($input, 'assigned_to') : $existing['assigned_to'];
    $dueDate = isset($input['due_date']) ? ($input['due_date'] ? Validator::requireDate($input, 'due_date') : null) : $existing['due_date'];

    DB::execute(
        'UPDATE maintenance_tickets SET title = :p0, description = :p1, priority = :p2, status = :p3,
         assigned_to = :p4, due_date = :p5, updated_at = datetime(\'now\') WHERE id = :p6',
        [$title, $description, $priority, $status, $assignedTo, $dueDate, (int)$id]
    );

    $ticket = DB::queryOne('SELECT * FROM maintenance_tickets WHERE id = :p0', [(int)$id]);
    echo json_encode($ticket);
}

function handleDeleteTicket(string $id): void {
    $existing = DB::queryOne('SELECT * FROM maintenance_tickets WHERE id = :p0', [(int)$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        return;
    }
    DB::execute('DELETE FROM maintenance_tickets WHERE id = :p0', [(int)$id]);
    echo json_encode(['success' => true]);
}

function handleResolveTicket(string $id, ?array $input): void {
    $existing = DB::queryOne('SELECT * FROM maintenance_tickets WHERE id = :p0', [(int)$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        return;
    }

    if (in_array($existing['status'], ['resolved', 'closed'], true)) {
        throw new RuntimeException('Ticket is already ' . $existing['status'] . '.');
    }

    $user = Auth::check();
    $notes = ($input && isset($input['resolution_notes'])) ? Validator::optionalString($input, 'resolution_notes', 5000) : '';

    DB::execute(
        'UPDATE maintenance_tickets SET status = :p0, resolved_by = :p1, resolution_notes = :p2,
         resolved_at = datetime(\'now\'), updated_at = datetime(\'now\') WHERE id = :p3',
        ['resolved', $user['id'] ?? null, $notes, (int)$id]
    );

    $ticket = DB::queryOne('SELECT * FROM maintenance_tickets WHERE id = :p0', [(int)$id]);
    echo json_encode($ticket);
}

function handleReopenTicket(string $id): void {
    $existing = DB::queryOne('SELECT * FROM maintenance_tickets WHERE id = :p0', [(int)$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
        return;
    }

    if (!in_array($existing['status'], ['resolved', 'closed'], true)) {
        throw new RuntimeException('Only resolved or closed tickets can be reopened.');
    }

    DB::execute(
        'UPDATE maintenance_tickets SET status = :p0, resolved_by = NULL, resolved_at = NULL,
         resolution_notes = \'\', updated_at = datetime(\'now\') WHERE id = :p1',
        ['open', (int)$id]
    );

    $ticket = DB::queryOne('SELECT * FROM maintenance_tickets WHERE id = :p0', [(int)$id]);
    echo json_encode($ticket);
}

function handleMaintenanceStats(): void {
    // Use venue timezone for "today" instead of SQLite's UTC date('now')
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

    $open = DB::queryOne('SELECT COUNT(*) as cnt FROM maintenance_tickets WHERE status IN (\'open\', \'in_progress\', \'waiting_parts\')')['cnt'] ?? 0;
    $critical = DB::queryOne('SELECT COUNT(*) as cnt FROM maintenance_tickets WHERE priority = \'critical\' AND status NOT IN (\'resolved\', \'closed\')')['cnt'] ?? 0;
    $resolvedToday = DB::queryOne('SELECT COUNT(*) as cnt FROM maintenance_tickets WHERE resolved_at >= :p0', [$today])['cnt'] ?? 0;
    $overdue = DB::queryOne('SELECT COUNT(*) as cnt FROM maintenance_tickets WHERE due_date < :p0 AND status NOT IN (\'resolved\', \'closed\')', [$today])['cnt'] ?? 0;

    $byStatus = DB::query('SELECT status, COUNT(*) as cnt FROM maintenance_tickets GROUP BY status');
    $byPriority = DB::query('SELECT priority, COUNT(*) as cnt FROM maintenance_tickets WHERE status NOT IN (\'resolved\', \'closed\') GROUP BY priority');
    $byAssetType = DB::query('SELECT asset_type, COUNT(*) as cnt FROM maintenance_tickets WHERE status NOT IN (\'resolved\', \'closed\') GROUP BY asset_type');

    $recentResolved = DB::query(
        'SELECT * FROM maintenance_tickets WHERE status IN (\'resolved\', \'closed\') ORDER BY resolved_at DESC LIMIT 5'
    );

    echo json_encode([
        'open_count' => $open,
        'critical_count' => $critical,
        'resolved_today' => $resolvedToday,
        'overdue_count' => $overdue,
        'by_status' => $byStatus,
        'by_priority' => $byPriority,
        'by_asset_type' => $byAssetType,
        'recent_resolved' => $recentResolved,
    ]);
}
