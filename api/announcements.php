<?php
/**
 * Staff Announcements API — internal communication board.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/validator.php';

function handleAnnouncements(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $id = $parts[0] ?? '';
    $action = $parts[1] ?? '';

    // POST /api/announcements/{id}/pin
    if ($id && $action === 'pin' && $method === 'POST') {
        handleTogglePin($id);
        return;
    }

    if ($id) {
        switch ($method) {
            case 'GET':
                handleGetAnnouncement($id);
                break;
            case 'PUT':
                handleUpdateAnnouncement($id, $input);
                break;
            case 'DELETE':
                handleDeleteAnnouncement($id);
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
        }
        return;
    }

    switch ($method) {
        case 'GET':
            handleListAnnouncements();
            break;
        case 'POST':
            handleCreateAnnouncement($input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleListAnnouncements(): void {
    $category = $_GET['category'] ?? '';
    $includeExpired = ($_GET['include_expired'] ?? '') === '1';

    // Validate category filter
    $validCategories = ['general', 'safety', 'maintenance', 'events', 'policy', 'kudos'];
    if ($category && !in_array($category, $validCategories, true)) {
        throw new RuntimeException('Invalid category filter. Allowed: ' . implode(', ', $validCategories));
    }

    $sql = 'SELECT a.*, au.display_name as author_name FROM announcements a LEFT JOIN admin_users au ON a.created_by = au.id';
    $params = [];
    $conditions = [];
    $idx = 0;

    if ($category) {
        $conditions[] = "a.category = :p$idx";
        $params[$idx++] = $category;
    }
    if (!$includeExpired) {
        $conditions[] = "(a.expires_at IS NULL OR a.expires_at > datetime('now'))";
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY a.is_pinned DESC, a.created_at DESC';

    $announcements = DB::query($sql, $params);
    echo json_encode(['announcements' => $announcements]);
}

function handleGetAnnouncement(string $id): void {
    $ann = DB::queryOne(
        'SELECT a.*, au.display_name as author_name FROM announcements a LEFT JOIN admin_users au ON a.created_by = au.id WHERE a.id = :p0',
        [(int)$id]
    );
    if (!$ann) {
        http_response_code(404);
        echo json_encode(['error' => 'Announcement not found']);
        return;
    }
    echo json_encode($ann);
}

function handleCreateAnnouncement(?array $input): void {
    if (!$input) { http_response_code(400); echo json_encode(['error' => 'Request body required']); return; }

    $user = Auth::check();
    $title = Validator::requireString($input, 'title');
    $body = Validator::requireString($input, 'body', 5000);
    $priority = isset($input['priority']) ? Validator::requireEnum($input, 'priority', ['low', 'normal', 'high', 'urgent']) : 'normal';
    $category = isset($input['category']) ? Validator::requireEnum($input, 'category', ['general', 'safety', 'maintenance', 'events', 'policy', 'kudos']) : 'general';
    $pinned = isset($input['is_pinned']) ? ($input['is_pinned'] ? 1 : 0) : 0;
    $expiresAt = null;
    if (isset($input['expires_at']) && $input['expires_at']) {
        $expiresAt = Validator::requireDatetime($input, 'expires_at');
    }

    DB::execute(
        'INSERT INTO announcements (title, body, priority, category, is_pinned, expires_at, created_by)
         VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6)',
        [$title, $body, $priority, $category, $pinned, $expiresAt, $user['id'] ?? null]
    );

    $id = DB::lastInsertId();
    $ann = DB::queryOne('SELECT a.*, au.display_name as author_name FROM announcements a LEFT JOIN admin_users au ON a.created_by = au.id WHERE a.id = :p0', [$id]);
    http_response_code(201);
    echo json_encode($ann);
}

function handleUpdateAnnouncement(string $id, ?array $input): void {
    if (!$input) { http_response_code(400); echo json_encode(['error' => 'Request body required']); return; }

    $existing = DB::queryOne('SELECT * FROM announcements WHERE id = :p0', [(int)$id]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Announcement not found']); return; }

    $title = isset($input['title']) ? Validator::requireString($input, 'title') : $existing['title'];
    $body = isset($input['body']) ? Validator::requireString($input, 'body', 5000) : $existing['body'];
    $priority = isset($input['priority']) ? Validator::requireEnum($input, 'priority', ['low', 'normal', 'high', 'urgent']) : $existing['priority'];
    $category = isset($input['category']) ? Validator::requireEnum($input, 'category', ['general', 'safety', 'maintenance', 'events', 'policy', 'kudos']) : $existing['category'];
    $pinned = isset($input['is_pinned']) ? ($input['is_pinned'] ? 1 : 0) : $existing['is_pinned'];
    $expiresAt = $existing['expires_at'];
    if (isset($input['expires_at'])) {
        $expiresAt = $input['expires_at'] ? Validator::requireDatetime($input, 'expires_at') : null;
    }

    DB::execute(
        'UPDATE announcements SET title = :p0, body = :p1, priority = :p2, category = :p3,
         is_pinned = :p4, expires_at = :p5, updated_at = datetime(\'now\') WHERE id = :p6',
        [$title, $body, $priority, $category, $pinned, $expiresAt, (int)$id]
    );

    $ann = DB::queryOne('SELECT a.*, au.display_name as author_name FROM announcements a LEFT JOIN admin_users au ON a.created_by = au.id WHERE a.id = :p0', [(int)$id]);
    echo json_encode($ann);
}

function handleDeleteAnnouncement(string $id): void {
    $existing = DB::queryOne('SELECT * FROM announcements WHERE id = :p0', [(int)$id]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Announcement not found']); return; }
    DB::execute('DELETE FROM announcements WHERE id = :p0', [(int)$id]);
    echo json_encode(['success' => true]);
}

function handleTogglePin(string $id): void {
    $existing = DB::queryOne('SELECT * FROM announcements WHERE id = :p0', [(int)$id]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Announcement not found']); return; }

    $newPinned = $existing['is_pinned'] ? 0 : 1;
    DB::execute('UPDATE announcements SET is_pinned = :p0, updated_at = datetime(\'now\') WHERE id = :p1', [$newPinned, (int)$id]);

    $ann = DB::queryOne('SELECT a.*, au.display_name as author_name FROM announcements a LEFT JOIN admin_users au ON a.created_by = au.id WHERE a.id = :p0', [(int)$id]);
    echo json_encode($ann);
}
