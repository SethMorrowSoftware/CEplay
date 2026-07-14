<?php
/**
 * Admin session management: login, logout, session check.
 * Uses HttpOnly + SameSite=Strict cookies, bcrypt passwords, 2-hour timeout.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

class Auth {
    /**
     * Role identifiers. Stored as plain strings on admin_users.role.
     *  - admin   : full access to every feature
     *  - manager : full access EXCEPT the Settings page (no API config,
     *              timezone, or user management)
     *  - tech    : full access EXCEPT customer-card lookup and the cash /
     *              revenue figures inside Analytics. Tech still sees plays,
     *              tickets, fleet posture, and automation activity — only
     *              the dollar columns are stripped (see api/analytics.php).
     */
    public const ROLE_ADMIN   = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_TECH    = 'tech';

    /** Canonical list of valid roles, used for input validation and the UI. */
    public const VALID_ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_TECH];

    /**
     * The permission catalog: every gateable capability in the app, keyed by
     * the area name used in requireAccess()/canAccess() calls. Roles are DATA
     * (the `roles` table, editable via /api/roles); permissions are CODE —
     * adding a new key here plus a requireAccess() call is how a new gate is
     * born. Areas NOT listed here are open to every authenticated user
     * (dashboard, game/kiosk list views, action log).
     *
     * The `admin` system role bypasses this entirely (always allowed) and is
     * locked against editing/deletion so an install can never lock itself out.
     */
    public const PERMISSIONS = [
        'analytics'        => 'View Analytics & Performance reporting (plays and tickets)',
        'view_revenue'     => 'See reader CC payment (dollar) figures in reporting',
        'cards'            => 'Card lookup: balances, transaction history, PIN checks',
        'manual_control'   => 'Operate the floor: pause/unpause groups, kiosk & game actions, force syncs',
        'overrides_manage' => 'Create and delete schedule overrides',
        'groups_manage'    => 'Create, edit, and delete pause groups',
        'reader_groups_manage' => 'Create, edit, and delete reader groups (the analytics areas)',
        'schedules_manage' => 'Create, edit, and delete recurring schedules',
        'settings'         => 'System settings: CenterEdge API credentials, timezone',
        'users'            => 'Manage user accounts (admin accounts always excluded)',
        'view_logs'        => 'View the Action Log / audit trail (card numbers, logins, IP addresses)',
    ];

    /**
     * Fallback role definitions used ONLY if the roles table is unreadable
     * (e.g. mid-upgrade). Mirrors the seeds in DB::initSchema so behavior is
     * identical either way.
     */
    private const FALLBACK_ROLES = [
        'admin'   => ['name' => 'Administrator', 'is_system' => 1, 'permissions' => ['*']],
        'manager' => ['name' => 'Manager', 'is_system' => 1,
                      'permissions' => ['analytics', 'view_revenue', 'cards', 'manual_control', 'overrides_manage', 'groups_manage', 'reader_groups_manage', 'schedules_manage', 'view_logs']],
        'tech'    => ['name' => 'Technician', 'is_system' => 1,
                      'permissions' => ['analytics', 'manual_control', 'overrides_manage', 'settings', 'users']],
    ];

    /** @var array<string,array>|null Per-request cache of the roles table. */
    private static $rolesCache = null;

    /** @var array<int,array{grant:string[],deny:string[]}> Per-request cache of user overrides. */
    private static $overridesCache = [];

    /**
     * All role definitions, keyed by slug:
     * ['slug' => ['slug','name','description','permissions'=>string[],'is_system'=>int]]
     * Cached for the request. Falls back to the built-in definitions if the
     * table can't be read, so auth never fails open (or closed) mid-upgrade.
     */
    public static function getRoles(): array {
        if (self::$rolesCache !== null) {
            return self::$rolesCache;
        }
        try {
            $rows = DB::query('SELECT slug, name, description, permissions, is_system FROM roles ORDER BY is_system DESC, name ASC');
            $roles = [];
            foreach ($rows as $r) {
                $perms = json_decode((string)$r['permissions'], true);
                $roles[(string)$r['slug']] = [
                    'slug'        => (string)$r['slug'],
                    'name'        => (string)$r['name'],
                    'description' => (string)($r['description'] ?? ''),
                    'permissions' => is_array($perms) ? array_values(array_map('strval', $perms)) : [],
                    'is_system'   => (int)$r['is_system'],
                ];
            }
            if (empty($roles)) {
                throw new RuntimeException('roles table empty');
            }
            self::$rolesCache = $roles;
        } catch (Exception $e) {
            error_log('Auth::getRoles falling back to built-in roles: ' . $e->getMessage());
            $roles = [];
            foreach (self::FALLBACK_ROLES as $slug => $def) {
                $roles[$slug] = [
                    'slug' => $slug, 'name' => $def['name'], 'description' => '',
                    'permissions' => $def['permissions'], 'is_system' => $def['is_system'],
                ];
            }
            self::$rolesCache = $roles;
        }
        return self::$rolesCache;
    }

    /** Drop the per-request roles cache (call after mutating the roles table). */
    public static function invalidateRolesCache(): void {
        self::$rolesCache = null;
    }

    /** True when the slug names an existing role. */
    public static function roleExists(string $slug): bool {
        $roles = self::getRoles();
        return isset($roles[$slug]);
    }

    /**
     * Resolved permission keys for a role slug. Admin resolves to the full
     * catalog; an unknown slug resolves to NOTHING (fail closed) — safer than
     * the old behavior of coercing unknowns to tech, which granted real access.
     */
    public static function permissionsFor(?string $roleSlug): array {
        $slug = strtolower(trim((string)$roleSlug));
        if ($slug === self::ROLE_ADMIN) {
            return array_keys(self::PERMISSIONS);
        }
        $roles = self::getRoles();
        if (!isset($roles[$slug])) {
            return [];
        }
        $perms = $roles[$slug]['permissions'];
        if (in_array('*', $perms, true)) {
            return array_keys(self::PERMISSIONS);
        }
        // Only catalog keys count — stale keys from a removed permission are ignored.
        return array_values(array_intersect($perms, array_keys(self::PERMISSIONS)));
    }

    /**
     * Per-user permission overrides (the grant/deny layer stored in
     * user_permission_overrides). Returns ['grant' => string[], 'deny' =>
     * string[]], filtered to real catalog keys. Request-cached. A missing
     * table or read error resolves to NO overrides, so a user falls back to
     * exactly their role's base permissions — never more.
     */
    public static function overridesForUser(?int $userId): array {
        $uid = (int)$userId;
        if ($uid <= 0) {
            return ['grant' => [], 'deny' => []];
        }
        if (isset(self::$overridesCache[$uid])) {
            return self::$overridesCache[$uid];
        }
        $grant = [];
        $deny = [];
        try {
            $rows = DB::query(
                'SELECT permission_key, effect FROM user_permission_overrides WHERE user_id = :p0',
                [$uid]
            );
            foreach ($rows as $r) {
                $key = (string)$r['permission_key'];
                if (!isset(self::PERMISSIONS[$key])) {
                    continue; // ignore stale keys from a removed permission
                }
                if ($r['effect'] === 'grant') {
                    $grant[] = $key;
                } elseif ($r['effect'] === 'deny') {
                    $deny[] = $key;
                }
            }
        } catch (Exception $e) {
            error_log('Auth::overridesForUser: ' . $e->getMessage());
        }
        $res = ['grant' => array_values(array_unique($grant)), 'deny' => array_values(array_unique($deny))];
        self::$overridesCache[$uid] = $res;
        return $res;
    }

    /** Drop the per-request per-user overrides cache (after mutating overrides). */
    public static function invalidateOverridesCache(): void {
        self::$overridesCache = [];
    }

    /**
     * Effective permission keys for a SPECIFIC user: their role's permissions,
     * plus per-user grants, minus per-user denies (deny wins). The admin system
     * role is absolute — it always resolves to the full catalog and per-user
     * denies do NOT apply to it, matching the last-admin lockout protections.
     * This is the resolver every gate now flows through.
     */
    public static function permissionsForUser(?int $userId, ?string $roleSlug): array {
        $slug = strtolower(trim((string)$roleSlug));
        if ($slug === self::ROLE_ADMIN) {
            return array_keys(self::PERMISSIONS);
        }
        $base = self::permissionsFor($roleSlug);
        $ov = self::overridesForUser($userId);
        $effective = array_merge($base, $ov['grant']);
        if (!empty($ov['deny'])) {
            $effective = array_diff($effective, $ov['deny']);
        }
        return array_values(array_intersect(array_unique($effective), array_keys(self::PERMISSIONS)));
    }

    /**
     * The permissions a caller may confer on (or reach through) another
     * account: their ROLE's permissions MINUS any per-user DENIES applied to
     * them. Grants are deliberately NOT added — a personal grant is a per-user
     * exception, not something to propagate to other accounts. This is the
     * ceiling that bounds role assignment and peer-account management, so a
     * denied capability cannot be handed to a freshly created account or
     * regained by resetting a peer's password.
     */
    public static function assignableCeiling(?int $callerUserId, ?string $callerRole): array {
        $base = self::permissionsFor($callerRole);
        $deny = self::overridesForUser($callerUserId)['deny'];
        return empty($deny) ? $base : array_values(array_diff($base, $deny));
    }

    /**
     * May the caller assign $targetSlug to a user? Admins can assign any
     * existing role. Everyone else can only hand out roles whose permissions
     * are a SUBSET of their own EFFECTIVE ceiling (role minus per-user denies)
     * — you can never grant more than you effectively hold — and never admin.
     * Taking denies into account is what stops a denied user from re-minting
     * the capability on a new peer account.
     */
    public static function canAssignRole(?int $callerUserId, string $callerRole, string $targetSlug): bool {
        if (!self::roleExists($targetSlug)) {
            return false;
        }
        if ($callerRole === self::ROLE_ADMIN) {
            return true;
        }
        if ($targetSlug === self::ROLE_ADMIN) {
            return false;
        }
        $callerPerms = self::assignableCeiling($callerUserId, $callerRole);
        $targetPerms = self::permissionsFor($targetSlug);
        return count(array_diff($targetPerms, $callerPerms)) === 0;
    }

    /** Max failed login attempts allowed per IP within the rate-limit window. */
    private const RATE_LIMIT_MAX_TRIES = 10;
    /** Sliding window in seconds for counting failed attempts (15 minutes). */
    private const RATE_LIMIT_WINDOW = 900;
    /** Progressive delay tiers: [attempt_threshold => sleep_seconds]. */
    private const PROGRESSIVE_DELAYS = [
        3 => 1,   // attempts 3-5: 1s delay
        6 => 3,   // attempts 6-8: 3s delay
        9 => 5,   // attempts 9-10: 5s delay (then locked out)
    ];

    /**
     * Configure and start the session with secure settings.
     */
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $cookiePath = (defined('BASE_PATH') && BASE_PATH !== '') ? rtrim(BASE_PATH, '/') . '/' : '/';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $cookiePath,
            'domain'   => '',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');

        session_start();
    }

    /**
     * Attempt login with username and password.
     * Returns user array on success, null on failure.
     * Uses progressive delays based on recent failure count for the IP.
     */
    public static function login(string $username, string $password, string $clientIp = ''): ?array {
        $user = DB::queryOne(
            'SELECT id, username, password_hash, display_name, role, is_active FROM admin_users WHERE username = :p0',
            [$username]
        );

        if (!$user || !$user['is_active']) {
            // Run password_verify against a dummy hash to equalize timing
            // and prevent username-existence enumeration via response time.
            password_verify($password, '$2y$12$dummyhashfortimingequaliz000000000000000000000000000');
            self::progressiveDelay($clientIp);
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::progressiveDelay($clientIp);
            return null;
        }

        // Rehash if bcrypt cost has changed
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            DB::execute(
                'UPDATE admin_users SET password_hash = :p0, updated_at = datetime(\'now\') WHERE id = :p1',
                [$newHash, $user['id']]
            );
        }

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        // Store auth data in session
        $_SESSION['auth_user'] = [
            'id'           => $user['id'],
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
            'role'         => self::normalizeRole($user['role'] ?? self::ROLE_ADMIN),
        ];
        $_SESSION['auth_time'] = time();

        // Generate fresh CSRF token
        CSRF::generate();

        return $_SESSION['auth_user'];
    }

    /**
     * Destroy session and logout.
     */
    public static function logout(): void {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Check if the current session is authenticated and not expired.
     * Returns user array or null.
     */
    public static function check(): ?array {
        if (!isset($_SESSION['auth_user']) || !isset($_SESSION['auth_time'])) {
            return null;
        }

        // Check session timeout
        if ((time() - $_SESSION['auth_time']) > SESSION_LIFETIME) {
            self::logout();
            return null;
        }

        // Refresh last activity time
        $_SESSION['auth_time'] = time();

        // Re-validate the account against the DB periodically (and on the
        // first request of a pre-role-migration session). Without this, a
        // deactivated/deleted user keeps working until their session expires
        // and a role change never applies until re-login. One tiny SELECT per
        // authenticated user per minute.
        $needsRevalidate = !isset($_SESSION['auth_user']['role'])
            || !isset($_SESSION['auth_revalidated'])
            || (time() - (int)$_SESSION['auth_revalidated']) > 60;
        if ($needsRevalidate) {
            try {
                $row = DB::queryOne(
                    'SELECT role, is_active FROM admin_users WHERE id = :p0',
                    [(int)$_SESSION['auth_user']['id']]
                );
                if (!$row || !(int)($row['is_active'] ?? 0)) {
                    // Deleted or deactivated — kill the session immediately.
                    self::logout();
                    return null;
                }
                $_SESSION['auth_user']['role'] = self::normalizeRole($row['role'] ?? self::ROLE_ADMIN);
                $_SESSION['auth_revalidated'] = time();
            } catch (Exception $e) {
                // If DB lookup fails, fail closed to least privilege for this
                // request; the next request retries the re-validation.
                if (!isset($_SESSION['auth_user']['role'])) {
                    $_SESSION['auth_user']['role'] = self::ROLE_TECH;
                }
            }
        }

        return $_SESSION['auth_user'];
    }

    /**
     * Normalize a stored role string. Unknown values fail closed to the
     * least-privileged role rather than silently granting admin. Legacy
     * installations (where the role column was added later and may be
     * NULL for existing rows) are handled at the call sites with an
     * explicit `?? ROLE_ADMIN` default before this method runs.
     */
    public static function normalizeRole(?string $role): string {
        $role = strtolower(trim((string)$role));
        if (self::roleExists($role)) {
            return $role;
        }
        // Unknown slug: keep it as-is. permissionsFor() resolves unknown
        // roles to an EMPTY permission set, which is strictly safer than the
        // old coercion to 'tech' (tech carries real access like settings).
        // Role deletion is blocked while users hold the role, so this only
        // happens for corrupt data — the user stays signed in but gated.
        return $role !== '' ? $role : self::ROLE_TECH;
    }

    /**
     * Return the current user's role, or null when not authenticated.
     */
    public static function role(): ?string {
        $user = self::check();
        return $user['role'] ?? null;
    }

    /**
     * True when the current user holds any of the supplied roles.
     */
    public static function hasRole(array $roles): bool {
        $role = self::role();
        return $role !== null && in_array($role, $roles, true);
    }

    /**
     * True when the current user's role grants the named permission (see
     * Auth::PERMISSIONS). Admin always passes; an unknown/deleted role slug
     * grants nothing.
     */
    public static function hasPermission(string $permission): bool {
        $user = self::check();
        if ($user === null) {
            return false;
        }
        $role = $user['role'] ?? null;
        if ($role === self::ROLE_ADMIN) {
            return true;
        }
        // Resolve through the per-user layer (role perms + grants − denies).
        return in_array($permission, self::permissionsForUser((int)($user['id'] ?? 0), $role), true);
    }

    /**
     * True when the current user may access the named resource area. Areas
     * not in the permission catalog are open to every authenticated user;
     * cataloged areas resolve through the user's role permissions.
     */
    public static function canAccess(string $area): bool {
        if (!isset(self::PERMISSIONS[$area])) {
            return self::check() !== null;
        }
        return self::hasPermission($area);
    }

    /**
     * Require that the current user holds one of the listed roles, otherwise
     * send 403 JSON and exit. Authentication is enforced first; an
     * unauthenticated request will get the standard 401 from requireAuth().
     */
    public static function requireRole(array $roles): array {
        $user = self::requireAuth();
        if (!in_array($user['role'] ?? '', $roles, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to access this resource.']);
            exit;
        }
        return $user;
    }

    /**
     * Require access to a named resource area defined in ACCESS_MATRIX. Sends
     * 403 JSON and exits if the role is wrong.
     */
    public static function requireAccess(string $area): array {
        $user = self::requireAuth();
        if (!self::canAccess($area)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to access this resource.']);
            exit;
        }
        return $user;
    }

    /**
     * Require authentication. Sends 401 JSON response and exits if not authenticated.
     * Returns user array on success.
     */
    public static function requireAuth(): array {
        $user = self::check();
        if ($user === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        return $user;
    }

    /**
     * Get the current user's ID, or null if not authenticated.
     */
    public static function userId(): ?int {
        $user = self::check();
        return $user ? $user['id'] : null;
    }

    /**
     * Hash a password for storage using bcrypt.
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Apply a progressive delay based on recent failure count for the IP.
     * More failures = longer delay, making brute-force increasingly impractical.
     */
    private static function progressiveDelay(string $ip): void {
        $failures = self::getFailureCount($ip);
        $delay = 1; // minimum 1 second
        foreach (self::PROGRESSIVE_DELAYS as $threshold => $seconds) {
            if ($failures >= $threshold) {
                $delay = $seconds;
            }
        }
        sleep($delay);
    }

    /**
     * Get recent failure count for an IP within the rate-limit window.
     */
    public static function getFailureCount(string $ip): int {
        if ($ip === '') {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW);
        $row = DB::queryOne(
            'SELECT COUNT(*) AS cnt FROM login_attempts WHERE ip_address = :p0 AND attempted_at > :p1',
            [$ip, $cutoff]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Return true if this IP has exceeded the failed-login threshold.
     */
    public static function isRateLimited(string $ip): bool {
        if ($ip === '') {
            return false;
        }
        return self::getFailureCount($ip) >= self::RATE_LIMIT_MAX_TRIES;
    }

    /**
     * Record a failed login attempt for the given IP.
     * Also prunes records older than 24 hours to keep the table small.
     */
    public static function recordFailedAttempt(string $ip): void {
        if ($ip === '') {
            return;
        }
        DB::execute('INSERT INTO login_attempts (ip_address) VALUES (:p0)', [$ip]);
        // Opportunistic cleanup — remove entries older than 24 hours
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
        DB::execute('DELETE FROM login_attempts WHERE attempted_at < :p0', [$cutoff]);
    }

    /**
     * Clear all failed-login records for this IP (call on successful login).
     */
    public static function clearLoginAttempts(string $ip): void {
        if ($ip === '') {
            return;
        }
        DB::execute('DELETE FROM login_attempts WHERE ip_address = :p0', [$ip]);
    }
}
