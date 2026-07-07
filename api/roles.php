<?php
/**
 * API: Role management (custom RBAC).
 * GET    /api/roles         — Role list + permission catalog + per-role user counts
 * POST   /api/roles         — Create a custom role
 * PUT    /api/roles/{slug}  — Update a role (admin role is fully locked;
 *                             system manager/tech keep their slug but their
 *                             name/description/permissions are editable)
 * DELETE /api/roles/{slug}  — Delete a custom role (blocked while in use)
 *
 * All mutations are admin-only. Reading the list requires the 'users' area
 * (the user-management UI needs it to build role selects); the permission
 * catalog itself is not sensitive.
 *
 * Escalation rules live in Auth::canAssignRole(): only admins assign freely;
 * everyone else can only assign roles whose permissions are a subset of
 * their own. The admin role bypasses permission checks in code, is locked
 * here against edit/delete, and last-admin protections stay keyed to it —
 * an installation can never lock itself out or mint a super-role.
 */

require_once __DIR__ . '/../lib/validator.php';

function handleRoles(string $method, array $parts, ?array $input): void {
    $currentUser = Auth::requireAccess('users');
    $slug = isset($parts[0]) ? strtolower(trim((string)$parts[0])) : null;

    if ($method === 'GET') {
        rolesList($currentUser);
        return;
    }

    // Mutations are admin-only.
    if (($currentUser['role'] ?? '') !== Auth::ROLE_ADMIN) {
        http_response_code(403);
        echo json_encode(['error' => 'Only an administrator can manage roles.']);
        return;
    }

    switch ($method) {
        case 'POST':
            roleCreate($input, $currentUser);
            break;
        case 'PUT':
            if (!$slug) {
                http_response_code(400);
                echo json_encode(['error' => 'Role slug required']);
                return;
            }
            roleUpdate($slug, $input, $currentUser);
            break;
        case 'DELETE':
            if (!$slug) {
                http_response_code(400);
                echo json_encode(['error' => 'Role slug required']);
                return;
            }
            roleDelete($slug, $currentUser);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

/** Shared response shape for the list (also returned after mutations). */
function rolesList(array $currentUser): void {
    $counts = [];
    foreach (DB::query('SELECT role, COUNT(*) AS c FROM admin_users GROUP BY role') as $r) {
        $counts[(string)$r['role']] = (int)$r['c'];
    }

    $roles = [];
    foreach (Auth::getRoles() as $slug => $role) {
        $roles[] = [
            'slug'        => $slug,
            'name'        => $role['name'],
            'description' => $role['description'],
            // Resolve through permissionsFor so admin shows the full catalog
            // and stale keys are filtered.
            'permissions' => Auth::permissionsFor($slug),
            'is_system'   => (int)$role['is_system'],
            'user_count'  => $counts[$slug] ?? 0,
            // What the CALLER may do with this role, so the UI can disable
            // controls instead of surfacing 403s.
            'assignable'  => Auth::canAssignRole($currentUser['id'] ?? null, $currentUser['role'] ?? '', $slug),
            'editable'    => $slug !== Auth::ROLE_ADMIN && ($currentUser['role'] ?? '') === Auth::ROLE_ADMIN,
            'deletable'   => !$role['is_system'] && ($counts[$slug] ?? 0) === 0
                             && ($currentUser['role'] ?? '') === Auth::ROLE_ADMIN,
        ];
    }

    echo json_encode([
        'roles'       => $roles,
        'permissions' => Auth::PERMISSIONS,
    ]);
}

/** Validate + normalize a permissions array from input against the catalog. */
function rolesValidatePermissions($raw): array {
    if (!is_array($raw)) {
        throw new RuntimeException('permissions must be an array of permission keys.');
    }
    $clean = [];
    foreach ($raw as $key) {
        $key = strtolower(trim((string)$key));
        if (!isset(Auth::PERMISSIONS[$key])) {
            throw new RuntimeException('Unknown permission: ' . $key);
        }
        $clean[$key] = true;
    }
    // Keep catalog order for stable storage/display.
    return array_values(array_intersect(array_keys(Auth::PERMISSIONS), array_keys($clean)));
}

function roleCreate(?array $input, array $currentUser): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $name = Validator::requireString($input, 'name', 50);
    $description = Validator::optionalString($input, 'description', 300);
    $permissions = rolesValidatePermissions($input['permissions'] ?? []);

    // Slug: explicit or derived from the name. Lowercase alphanumeric+underscore.
    $slug = strtolower(trim((string)($input['slug'] ?? $name)));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    if ($slug === '' || strlen($slug) > 40) {
        throw new RuntimeException('Role slug must be 1-40 characters (letters, numbers, underscores).');
    }
    if (Auth::roleExists($slug)) {
        throw new RuntimeException('A role with slug "' . $slug . '" already exists.');
    }

    DB::execute(
        'INSERT INTO roles (slug, name, description, permissions, is_system) VALUES (:p0, :p1, :p2, :p3, 0)',
        [$slug, $name, $description, json_encode($permissions)]
    );
    Auth::invalidateRolesCache();

    DB::auditLog('admin', 'role_created', (int)($currentUser['id'] ?? 0), [
        'slug' => $slug, 'name' => $name, 'permissions' => $permissions,
        'actor' => $currentUser['username'] ?? null,
    ]);

    http_response_code(201);
    rolesList($currentUser);
}

function roleUpdate(string $slug, ?array $input, array $currentUser): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $roles = Auth::getRoles();
    if (!isset($roles[$slug])) {
        http_response_code(404);
        echo json_encode(['error' => 'Role not found']);
        return;
    }
    if ($slug === Auth::ROLE_ADMIN) {
        throw new RuntimeException('The admin role cannot be modified.');
    }

    $existing = $roles[$slug];
    $name = array_key_exists('name', $input)
        ? Validator::requireString($input, 'name', 50)
        : $existing['name'];
    $description = array_key_exists('description', $input)
        ? Validator::optionalString($input, 'description', 300)
        : $existing['description'];
    $permissions = array_key_exists('permissions', $input)
        ? rolesValidatePermissions($input['permissions'])
        : $existing['permissions'];

    DB::execute(
        'UPDATE roles SET name = :p0, description = :p1, permissions = :p2, updated_at = datetime(\'now\') WHERE slug = :p3',
        [$name, $description, json_encode($permissions), $slug]
    );
    Auth::invalidateRolesCache();

    DB::auditLog('admin', 'role_updated', (int)($currentUser['id'] ?? 0), [
        'slug' => $slug, 'name' => $name, 'permissions' => $permissions,
        'is_system' => $existing['is_system'],
        'actor' => $currentUser['username'] ?? null,
    ]);

    rolesList($currentUser);
}

function roleDelete(string $slug, array $currentUser): void {
    $roles = Auth::getRoles();
    if (!isset($roles[$slug])) {
        http_response_code(404);
        echo json_encode(['error' => 'Role not found']);
        return;
    }
    if ($roles[$slug]['is_system']) {
        throw new RuntimeException('System roles (admin, manager, tech) cannot be deleted.');
    }

    $inUse = DB::queryOne('SELECT COUNT(*) AS c FROM admin_users WHERE role = :p0', [$slug]);
    if ((int)($inUse['c'] ?? 0) > 0) {
        throw new RuntimeException(
            'Role "' . $roles[$slug]['name'] . '" is assigned to ' . $inUse['c']
            . ' user(s). Reassign them first.'
        );
    }

    DB::execute('DELETE FROM roles WHERE slug = :p0', [$slug]);
    Auth::invalidateRolesCache();

    DB::auditLog('admin', 'role_deleted', (int)($currentUser['id'] ?? 0), [
        'slug' => $slug, 'name' => $roles[$slug]['name'],
        'actor' => $currentUser['username'] ?? null,
    ]);

    rolesList($currentUser);
}
