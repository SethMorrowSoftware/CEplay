<?php
/**
 * API: Admin user management.
 * GET    /api/users        — List all admin users
 * POST   /api/users        — Create new admin user
 * PUT    /api/users/{id}   — Update user
 * DELETE /api/users/{id}   — Permanently delete user (admin only)
 *
 * Access: admin or tech (see Auth::ACCESS_MATRIX). Managers are denied —
 * user management is part of the Settings surface and they have no Settings
 * access. Promotion to the 'admin' role is restricted to existing admins so
 * a tech cannot escalate themselves or another account, and admin accounts
 * can only be modified or deleted by other admins (so a tech can't reset an
 * admin's password and take over the account).
 */

require_once __DIR__ . '/../lib/validator.php';

function handleUsers(string $method, array $parts, ?array $input): void {
    $currentUser = Auth::requireAccess('users');

    $userId = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;

    switch ($method) {
        case 'GET':
            $users = DB::query(
                'SELECT id, username, display_name, role, is_active, created_at, updated_at
                 FROM admin_users ORDER BY username ASC'
            );
            // Pull every per-user override in one query and group by user, so
            // the Settings UI can render each account's grant/deny layer and
            // its resolved effective permissions without an N+1.
            $ovByUser = [];
            foreach (DB::query('SELECT user_id, permission_key, effect FROM user_permission_overrides') as $o) {
                $ovByUser[(int)$o['user_id']][] = ['key' => (string)$o['permission_key'], 'effect' => (string)$o['effect']];
            }
            // Normalize legacy NULL/empty roles on the way out so the UI
            // never has to guess.
            foreach ($users as &$u) {
                $u['role'] = Auth::normalizeRole($u['role'] ?? null);
                $uid = (int)$u['id'];
                $u['permission_overrides']   = $ovByUser[$uid] ?? [];
                $u['effective_permissions']  = Auth::permissionsForUser($uid, $u['role']);
            }
            unset($u);
            // Dynamic role catalog: slug/name/description plus whether the
            // CALLER may assign each one (admins assign anything; others only
            // roles whose permissions are a subset of their own).
            $roleCatalog = [];
            foreach (Auth::getRoles() as $slug => $role) {
                $roleCatalog[] = [
                    'slug'        => $slug,
                    'name'        => $role['name'],
                    'description' => $role['description'],
                    'is_system'   => (int)$role['is_system'],
                    'assignable'  => Auth::canAssignRole($currentUser['role'] ?? '', $slug),
                ];
            }
            echo json_encode([
                'users'              => $users,
                'roles'              => array_column($roleCatalog, 'slug'),
                'role_catalog'       => $roleCatalog,
                'permission_catalog' => Auth::PERMISSIONS,
                'current_role'       => $currentUser['role'] ?? null,
            ]);
            break;

        case 'POST':
            createUser($input, $currentUser);
            break;

        case 'PUT':
            if (!$userId) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID required']);
                return;
            }
            updateUser($userId, $input, $currentUser);
            break;

        case 'DELETE':
            if (!$userId) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID required']);
                return;
            }
            deleteUser($userId, $currentUser);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

/**
 * Permanently remove a user account. Deactivation (PUT is_active=0) remains
 * the everyday way to offboard someone; hard delete exists for cleaning up
 * mistakes and departed staff. Admin-only, with the same lockout guards as
 * demotion/deactivation.
 */
function deleteUser(int $userId, array $currentUser): void {
    if (($currentUser['role'] ?? '') !== Auth::ROLE_ADMIN) {
        http_response_code(403);
        echo json_encode(['error' => 'Only an administrator can delete user accounts.']);
        return;
    }

    $existing = DB::queryOne('SELECT * FROM admin_users WHERE id = :p0', [$userId]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    if ($userId === (int)($currentUser['id'] ?? 0)) {
        throw new RuntimeException('You cannot delete your own account.');
    }

    $existingRole = Auth::normalizeRole($existing['role'] ?? null);
    if ($existingRole === Auth::ROLE_ADMIN) {
        $otherAdmins = DB::queryOne(
            'SELECT COUNT(*) AS c FROM admin_users
             WHERE role = :p0 AND is_active = 1 AND id != :p1',
            [Auth::ROLE_ADMIN, $userId]
        );
        if ((int)($otherAdmins['c'] ?? 0) === 0) {
            throw new RuntimeException('Cannot delete the only remaining administrator.');
        }
    }

    // schedule_overrides.created_by references admin_users(id) with no
    // ON DELETE clause — detach those rows first so the delete can't fail
    // on the foreign key. The override history itself is preserved.
    DB::execute('UPDATE schedule_overrides SET created_by = NULL WHERE created_by = :p0', [$userId]);
    DB::execute('DELETE FROM admin_users WHERE id = :p0', [$userId]);

    DB::auditLog('admin', 'user_deleted', (int)($currentUser['id'] ?? 0), [
        'user_id'  => $userId,
        'username' => $existing['username'] ?? null,
        'role'     => $existingRole,
        'actor'    => $currentUser['username'] ?? null,
    ]);

    echo json_encode(['success' => true]);
}

function createUser(?array $input, array $currentUser): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $username    = Validator::requireString($input, 'username', 50);
    $displayName = Validator::requireString($input, 'display_name', 100);
    $password    = Validator::requireString($input, 'password', 255);
    $role        = strtolower(trim((string)($input['role'] ?? Auth::ROLE_TECH)));

    if (mb_strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    if (!Auth::roleExists($role)) {
        throw new RuntimeException('Unknown role: ' . $role);
    }
    // Escalation guard: admins may assign any role; everyone else only roles
    // whose permissions are a subset of their own (and never admin) — you
    // can't create an account with more access than you hold.
    if (!Auth::canAssignRole($currentUser['role'] ?? '', $role)) {
        throw new RuntimeException('You cannot assign a role with permissions beyond your own.');
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

    // Optional per-user permission overrides (admin-only; validated inside).
    if (array_key_exists('permission_overrides', $input)) {
        saveUserOverrides($newId, $input['permission_overrides'], $currentUser);
    }

    DB::auditLog('admin', 'user_created', null, [
        'user_id'  => $newId,
        'username' => $username,
        'role'     => $role,
        'actor'    => $currentUser['username'] ?? null,
    ]);

    http_response_code(201);
    $user = DB::queryOne(
        'SELECT id, username, display_name, role, is_active, created_at FROM admin_users WHERE id = :p0',
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

    $existingRole = Auth::normalizeRole($existing['role'] ?? null);
    $isSelf = $userId === (int)($currentUser['id'] ?? 0);
    $callerIsAdmin = ($currentUser['role'] ?? '') === Auth::ROLE_ADMIN;

    // Admin accounts can only be modified by other admins. Without this, a
    // tech (who has users-API access) could reset an admin's password and
    // take over the account — a straight privilege escalation.
    if (!$callerIsAdmin && $existingRole === Auth::ROLE_ADMIN) {
        http_response_code(403);
        echo json_encode(['error' => 'Only an administrator can modify an admin account.']);
        return;
    }

    // Cannot deactivate yourself
    if (isset($input['is_active']) && !(int)$input['is_active'] && $isSelf) {
        throw new RuntimeException('You cannot deactivate your own account.');
    }

    // Resolve target role and enforce escalation guards.
    $targetRole = $existingRole;
    if (array_key_exists('role', $input)) {
        $targetRole = strtolower(trim((string)$input['role']));

        if ($targetRole !== $existingRole && !Auth::roleExists($targetRole)) {
            throw new RuntimeException('Unknown role: ' . $targetRole);
        }
        if (!$callerIsAdmin && $targetRole !== $existingRole) {
            throw new RuntimeException('Only an administrator can change a user\'s role.');
        }
        if ($isSelf && $existingRole === Auth::ROLE_ADMIN && $targetRole !== Auth::ROLE_ADMIN) {
            throw new RuntimeException('You cannot remove your own admin role.');
        }
        if ($existingRole === Auth::ROLE_ADMIN && $targetRole !== Auth::ROLE_ADMIN) {
            // Refuse to demote the final remaining active admin so the
            // installation never locks itself out.
            $otherAdmins = DB::queryOne(
                'SELECT COUNT(*) AS c FROM admin_users
                 WHERE role = :p0 AND is_active = 1 AND id != :p1',
                [Auth::ROLE_ADMIN, $userId]
            );
            if ((int)($otherAdmins['c'] ?? 0) === 0) {
                throw new RuntimeException('Cannot demote the only remaining administrator.');
            }
        }
    }

    $displayName = Validator::optionalString($input, 'display_name', 100);
    if ($displayName === '') {
        $displayName = $existing['display_name'];
    }
    $isActive = (int)($input['is_active'] ?? $existing['is_active']);

    // Same final-admin protection for deactivation.
    if (!$isActive && $existingRole === Auth::ROLE_ADMIN && (int)$existing['is_active'] === 1) {
        $otherAdmins = DB::queryOne(
            'SELECT COUNT(*) AS c FROM admin_users
             WHERE role = :p0 AND is_active = 1 AND id != :p1',
            [Auth::ROLE_ADMIN, $userId]
        );
        if ((int)($otherAdmins['c'] ?? 0) === 0) {
            throw new RuntimeException('Cannot deactivate the only remaining administrator.');
        }
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
            'UPDATE admin_users SET display_name = :p0, role = :p1, is_active = :p2, password_hash = :p3, updated_at = datetime(\'now\') WHERE id = :p4',
            [$displayName, $targetRole, $isActive, $hash, $userId]
        );
    } else {
        DB::execute(
            'UPDATE admin_users SET display_name = :p0, role = :p1, is_active = :p2, updated_at = datetime(\'now\') WHERE id = :p3',
            [$displayName, $targetRole, $isActive, $userId]
        );
    }

    // Optional per-user permission overrides (admin-only; validated inside).
    if (array_key_exists('permission_overrides', $input)) {
        saveUserOverrides($userId, $input['permission_overrides'], $currentUser);
    }

    DB::auditLog('admin', 'user_updated', null, [
        'user_id'          => $userId,
        'role'             => $targetRole,
        'role_changed'     => $targetRole !== $existingRole,
        'password_changed' => $hash !== null,
        'is_active'        => $isActive,
        'actor'            => $currentUser['username'] ?? null,
    ]);

    $user = DB::queryOne(
        'SELECT id, username, display_name, role, is_active, created_at, updated_at FROM admin_users WHERE id = :p0',
        [$userId]
    );
    echo json_encode($user);
}

/**
 * Replace a user's per-user permission overrides (the grant/deny layer that
 * sits on top of their role). ADMIN-ONLY: a per-user grant/deny can widen or
 * narrow real access, so — like role mutations — only administrators may set
 * it. Every key is validated against the catalog and every effect against
 * grant|deny; 'inherit'/empty entries mean "no override" and are dropped, and
 * anything unrecognized is rejected rather than silently ignored. The write is
 * a full replace of the user's override set.
 */
function saveUserOverrides(int $userId, $overrides, array $currentUser): void {
    if (($currentUser['role'] ?? '') !== Auth::ROLE_ADMIN) {
        throw new RuntimeException('Only an administrator can set per-user permission overrides.');
    }
    if (!is_array($overrides)) {
        throw new RuntimeException('permission_overrides must be an array.');
    }

    $catalog = Auth::PERMISSIONS;
    $clean = []; // key => effect, de-duplicated (last entry wins)
    foreach ($overrides as $o) {
        if (!is_array($o)) {
            continue;
        }
        $key    = strtolower(trim((string)($o['key'] ?? $o['permission_key'] ?? '')));
        $effect = strtolower(trim((string)($o['effect'] ?? '')));
        if ($effect === '' || $effect === 'inherit') {
            continue; // no override for this permission
        }
        if ($key === '' || !isset($catalog[$key])) {
            throw new RuntimeException('Unknown permission key in overrides: ' . $key);
        }
        if ($effect !== 'grant' && $effect !== 'deny') {
            throw new RuntimeException('Override effect must be "grant" or "deny".');
        }
        $clean[$key] = $effect;
    }

    DB::execute('DELETE FROM user_permission_overrides WHERE user_id = :p0', [$userId]);
    foreach ($clean as $key => $effect) {
        DB::execute(
            'INSERT INTO user_permission_overrides (user_id, permission_key, effect) VALUES (:p0, :p1, :p2)',
            [$userId, $key, $effect]
        );
    }
    Auth::invalidateOverridesCache();

    DB::auditLog('admin', 'user_overrides_updated', (int)($currentUser['id'] ?? 0), [
        'user_id'   => $userId,
        'overrides' => $clean,
        'actor'     => $currentUser['username'] ?? null,
    ]);
}
