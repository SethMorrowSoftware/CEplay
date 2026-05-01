<?php
/**
 * API: Admin user management.
 * GET    /api/users        — List all admin users
 * POST   /api/users        — Create new admin user
 * PUT    /api/users/{id}   — Update user
 *
 * Restricted to users with the 'admin' role — managing accounts (and roles)
 * is the most sensitive surface and must not be delegated to tech/manager.
 */

require_once __DIR__ . '/../lib/validator.php';

function handleUsers(string $method, array $parts, ?array $input): void {
    $currentUser = Auth::requireRole([Auth::ROLE_ADMIN]);

    $userId = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;

    switch ($method) {
        case 'GET':
            $users = DB::query(
                'SELECT id, username, display_name, is_active, role, created_at, updated_at
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
    $role        = isset($input['role']) ? Auth::normalizeRole((string)$input['role']) : Auth::ROLE_ADMIN;

    if (!in_array($role, Auth::ROLES, true)) {
        throw new RuntimeException('Invalid role. Must be one of: ' . implode(', ', Auth::ROLES));
    }
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
        'INSERT INTO admin_users (username, password_hash, display_name, role) VALUES (:p0, :p1, :p2, :p3)',
        [$username, $hash, $displayName, $role]
    );

    $newId = DB::lastInsertId();
    DB::auditLog('admin', 'user_created', null, ['user_id' => $newId, 'username' => $username, 'role' => $role]);

    http_response_code(201);
    $user = DB::queryOne(
        'SELECT id, username, display_name, is_active, role, created_at FROM admin_users WHERE id = :p0',
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

    $existingRole = Auth::normalizeRole($existing['role'] ?? Auth::ROLE_ADMIN);
    $role = isset($input['role'])
        ? Auth::normalizeRole((string)$input['role'])
        : $existingRole;
    if (!in_array($role, Auth::ROLES, true)) {
        throw new RuntimeException('Invalid role. Must be one of: ' . implode(', ', Auth::ROLES));
    }

    // Last-admin guard: never allow the last active admin to be demoted or
    // deactivated, otherwise no one can manage users or settings going forward.
    $demotingFromAdmin = ($existingRole === Auth::ROLE_ADMIN && $role !== Auth::ROLE_ADMIN);
    $deactivatingAdmin = ($existingRole === Auth::ROLE_ADMIN && !$isActive && (int)$existing['is_active']);
    if ($demotingFromAdmin || $deactivatingAdmin) {
        $row = DB::queryOne(
            "SELECT COUNT(*) AS cnt FROM admin_users
             WHERE role = 'admin' AND is_active = 1 AND id != :p0",
            [$userId]
        );
        if ((int)($row['cnt'] ?? 0) === 0) {
            throw new RuntimeException('Cannot remove the last active admin. Promote another user to admin first.');
        }
    }

    // Self-demotion guard: an admin editing themselves cannot drop their own
    // admin role — they'd lock themselves out of this very page mid-request.
    if ($userId === $currentUser['id'] && $existingRole === Auth::ROLE_ADMIN && $role !== Auth::ROLE_ADMIN) {
        throw new RuntimeException('You cannot remove your own admin role. Ask another admin to do it.');
    }

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
            'UPDATE admin_users SET display_name = :p0, is_active = :p1, role = :p2, password_hash = :p3, updated_at = datetime(\'now\') WHERE id = :p4',
            [$displayName, $isActive, $role, $hash, $userId]
        );
    } else {
        DB::execute(
            'UPDATE admin_users SET display_name = :p0, is_active = :p1, role = :p2, updated_at = datetime(\'now\') WHERE id = :p3',
            [$displayName, $isActive, $role, $userId]
        );
    }

    DB::auditLog('admin', 'user_updated', null, [
        'user_id' => $userId,
        'password_changed' => $hash !== null,
        'role' => $role,
        'role_changed' => $role !== $existingRole,
    ]);

    $user = DB::queryOne(
        'SELECT id, username, display_name, is_active, role, created_at, updated_at FROM admin_users WHERE id = :p0',
        [$userId]
    );
    echo json_encode($user);
}
