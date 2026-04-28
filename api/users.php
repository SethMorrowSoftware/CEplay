<?php
/**
 * API: Admin user management.
 * GET    /api/users        — List all admin users
 * POST   /api/users        — Create new admin user
 * PUT    /api/users/{id}   — Update user
 */

require_once __DIR__ . '/../lib/validator.php';

function handleUsers(string $method, array $parts, ?array $input): void {
    $currentUser = Auth::requireAuth();

    $userId = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;

    switch ($method) {
        case 'GET':
            $users = DB::query(
                'SELECT id, username, display_name, is_active, created_at, updated_at
                 FROM admin_users ORDER BY username ASC'
            );
            echo json_encode(['users' => $users]);
            break;

        case 'POST':
            createUser($input);
            break;

        case 'PUT':
            if (!$userId) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID required']);
                return;
            }
            updateUser($userId, $input, $currentUser);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function createUser(?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $username    = Validator::requireString($input, 'username', 50);
    $displayName = Validator::requireString($input, 'display_name', 100);
    $password    = Validator::requireString($input, 'password', 255);

    if (mb_strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    // Check uniqueness
    $existing = DB::queryOne('SELECT id FROM admin_users WHERE username = :p0', [$username]);
    if ($existing) {
        throw new RuntimeException('Username already exists.');
    }

    $hash = Auth::hashPassword($password);
    DB::execute(
        'INSERT INTO admin_users (username, password_hash, display_name) VALUES (:p0, :p1, :p2)',
        [$username, $hash, $displayName]
    );

    $newId = DB::lastInsertId();
    DB::auditLog('admin', 'user_created', null, ['user_id' => $newId, 'username' => $username]);

    http_response_code(201);
    $user = DB::queryOne(
        'SELECT id, username, display_name, is_active, created_at FROM admin_users WHERE id = :p0',
        [$newId]
    );
    echo json_encode($user);
}

function updateUser(int $userId, ?array $input, array $currentUser): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $existing = DB::queryOne('SELECT * FROM admin_users WHERE id = :p0', [$userId]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    // Cannot deactivate yourself
    if (isset($input['is_active']) && !(int)$input['is_active'] && $userId === $currentUser['id']) {
        throw new RuntimeException('You cannot deactivate your own account.');
    }

    $displayName = Validator::optionalString($input, 'display_name', 100);
    if ($displayName === '') {
        $displayName = $existing['display_name'];
    }
    $isActive = (int)($input['is_active'] ?? $existing['is_active']);

    // Validate password up-front before making any changes
    $hash = null;
    if (!empty($input['password']) && $input['password'] !== '********') {
        $password = $input['password'];
        if (mb_strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        $hash = Auth::hashPassword($password);
    }

    // Apply all changes in a single atomic UPDATE
    if ($hash !== null) {
        DB::execute(
            'UPDATE admin_users SET display_name = :p0, is_active = :p1, password_hash = :p2, updated_at = datetime(\'now\') WHERE id = :p3',
            [$displayName, $isActive, $hash, $userId]
        );
    } else {
        DB::execute(
            'UPDATE admin_users SET display_name = :p0, is_active = :p1, updated_at = datetime(\'now\') WHERE id = :p2',
            [$displayName, $isActive, $userId]
        );
    }

    DB::auditLog('admin', 'user_updated', null, ['user_id' => $userId, 'password_changed' => $hash !== null]);

    $user = DB::queryOne(
        'SELECT id, username, display_name, is_active, created_at, updated_at FROM admin_users WHERE id = :p0',
        [$userId]
    );
    echo json_encode($user);
}
