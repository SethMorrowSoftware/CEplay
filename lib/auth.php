<?php
/**
 * Admin session management: login, logout, session check.
 * Uses HttpOnly + SameSite=Strict cookies, bcrypt passwords, 2-hour timeout.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

class Auth {
    /** Valid role identifiers. Order is informational only — no implicit hierarchy. */
    public const ROLE_ADMIN   = 'admin';
    public const ROLE_TECH    = 'tech';
    public const ROLE_MANAGER = 'manager';
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_TECH, self::ROLE_MANAGER];

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
            'SELECT id, username, password_hash, display_name, is_active, role FROM admin_users WHERE username = :p0',
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

        // Refresh role from DB on every check so role changes take effect within
        // the existing session without requiring re-login.
        $row = DB::queryOne(
            'SELECT role, is_active FROM admin_users WHERE id = :p0',
            [(int)$_SESSION['auth_user']['id']]
        );
        if (!$row || !$row['is_active']) {
            // Account deactivated mid-session — kill the session.
            self::logout();
            return null;
        }
        $_SESSION['auth_user']['role'] = self::normalizeRole($row['role'] ?? self::ROLE_ADMIN);

        return $_SESSION['auth_user'];
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
     * Coerce an arbitrary role string to a known role, defaulting to admin
     * for legacy/unknown values so upgrades don't lock out existing accounts.
     */
    public static function normalizeRole(?string $role): string {
        $role = strtolower(trim((string)$role));
        return in_array($role, self::ROLES, true) ? $role : self::ROLE_ADMIN;
    }

    /**
     * Return true if the current authenticated user has any of the given roles.
     */
    public static function hasRole(array $allowedRoles): bool {
        $user = self::check();
        if (!$user) return false;
        return in_array($user['role'] ?? self::ROLE_ADMIN, $allowedRoles, true);
    }

    /**
     * Require the authenticated user to hold one of the allowed roles.
     * Sends 401 if unauthenticated, 403 if authenticated but unauthorized.
     */
    public static function requireRole(array $allowedRoles): array {
        $user = self::requireAuth();
        if (!in_array($user['role'] ?? self::ROLE_ADMIN, $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to access this resource.']);
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
