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
                DB::auditLog('auth', 'login_rate_limited', null, ['ip' => $clientIp, 'username' => $input['username'] ?? null], false);
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
                DB::auditLog('auth', 'login_success', null, ['username' => $username, 'role' => $user['role'] ?? null, 'ip' => $clientIp]);
                // Resolved permission set rides along so the client can gate
                // nav/UI without a hardcoded role→area map. Includes the
                // per-user grant/deny overrides.
                $user['permissions'] = Auth::permissionsForUser($user['id'] ?? null, $user['role'] ?? '');
                $roles = Auth::getRoles();
                $user['role_name'] = $roles[$user['role'] ?? '']['name'] ?? ($user['role'] ?? '');
                echo json_encode([
                    'user'       => $user,
                    'csrf_token' => CSRF::getToken(),
                ]);
            } else {
                Auth::recordFailedAttempt($clientIp);
                DB::auditLog('auth', 'login_failed', null, ['username' => $username, 'ip' => $clientIp], false);
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
            if ($user) {
                $user['permissions'] = Auth::permissionsForUser($user['id'] ?? null, $user['role'] ?? '');
                $roles = Auth::getRoles();
                $user['role_name'] = $roles[$user['role'] ?? '']['name'] ?? ($user['role'] ?? '');
            }
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
 * Get the real client IP, respecting X-Forwarded-For behind a local proxy.
 *
 * Security: the login rate-limiter and progressive delay key off this value, so
 * it MUST NOT be client-forgeable. We trust X-Forwarded-For only when the
 * request actually arrived from a loopback reverse proxy (REMOTE_ADDR is
 * 127.0.0.1/::1), and even then we take the RIGHTMOST entry — the hop the proxy
 * itself appended. The documented deployment runs nginx/Caddy on the same host
 * with the default `proxy_add_x_forwarded_for`, which APPENDS the real TCP peer
 * to the right of whatever the client sent. The leftmost entries are therefore
 * attacker-supplied: trusting them let a client rotate a fresh fake IP on every
 * request, so the 10-try lockout never tripped and staff passwords could be
 * brute-forced (and the audit IP was spoofable). The rightmost entry is the
 * peer the proxy observed and cannot be forged by the client.
 */
function getClientIp(): string {
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        $ips = array_values(array_filter(
            array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])),
            function ($v) { return $v !== ''; }
        ));
        if ($ips) {
            return end($ips); // rightmost = proxy-attested peer, not client-controlled
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
