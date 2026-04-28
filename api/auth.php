<?php
/**
 * API: Authentication endpoints.
 * POST /api/auth/login  — Login, return user + CSRF token
 * POST /api/auth/logout — Destroy session
 * GET  /api/auth/status — Check session status
 */

require_once __DIR__ . '/../lib/validator.php';

function handleAuth(string $method, array $parts, ?array $input): void {
    $action = $parts[0] ?? '';

    switch ($action) {
        case 'login':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                return;
            }

            // Rate-limit check before touching credentials
            $clientIp = getClientIp();
            if (Auth::isRateLimited($clientIp)) {
                DB::auditLog('auth', 'login_rate_limited', null, ['ip' => $clientIp, 'username' => $input['username'] ?? null]);
                http_response_code(429);
                header('Retry-After: 900');
                echo json_encode(['error' => 'Too many failed login attempts. Please wait 15 minutes before trying again.']);
                return;
            }

            $username = Validator::requireString($input ?? [], 'username');
            $password = Validator::requireString($input ?? [], 'password');

            $user = Auth::login($username, $password, $clientIp);
            if ($user) {
                Auth::clearLoginAttempts($clientIp);
                DB::auditLog('auth', 'login_success', null, ['username' => $username, 'ip' => $clientIp]);
                echo json_encode([
                    'user'       => $user,
                    'csrf_token' => CSRF::getToken(),
                ]);
            } else {
                Auth::recordFailedAttempt($clientIp);
                DB::auditLog('auth', 'login_failed', null, ['username' => $username, 'ip' => $clientIp]);
                error_log("Failed login attempt for user '$username' from IP $clientIp");
                http_response_code(401);
                echo json_encode(['error' => 'Invalid username or password.']);
            }
            break;

        case 'logout':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                return;
            }
            Auth::logout();
            echo json_encode(['success' => true]);
            break;

        case 'status':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                return;
            }
            $user = Auth::check();
            echo json_encode([
                'authenticated' => $user !== null,
                'user'          => $user,
                'csrf_token'    => CSRF::getToken(),
            ]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown auth action']);
    }
}

/**
 * Get the real client IP, respecting X-Forwarded-For behind trusted proxies.
 */
function getClientIp(): string {
    // Trust X-Forwarded-For only when behind a local reverse proxy
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        // Take the leftmost (original client) IP
        $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        return $ips[0] ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
