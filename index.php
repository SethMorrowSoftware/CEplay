<?php
/**
 * Main router: serves SPA shell for browser requests and dispatches API requests.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/auth.php';

// Start session for all requests
Auth::initSession();

/**
 * Strip internal details (URLs, paths, stack traces) from API error messages.
 */
function sanitizeApiError(string $message): string {
    if (preg_match('/HTTP (\d{3})/', $message, $m)) {
        return 'CenterEdge API error (HTTP ' . $m[1] . ')';
    }
    $cleaned = preg_replace('#https?://[^\s]+#', '[redacted]', $message);
    $cleaned = preg_replace('#/[a-zA-Z0-9_/.-]+\.php#', '[redacted]', $cleaned);
    return $cleaned;
}

// Parse the request
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(dirname($scriptName), '/');

// Remove basePath prefix and query string
$path = $requestUri;
if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = strtok($path, '?');
$path = trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Basic security headers for all responses
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Permitted-Cross-Domain-Policies: none');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
// HSTS: enforce HTTPS for 1 year when accessed over a secure connection
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
// Content-Security-Policy: restrict sources; unsafe-inline required for the
// inline APP_CONFIG <script> block injected by the SPA shell.
// cdn.jsdelivr.net is allowed for Chart.js (loaded for the games analytics dashboard).
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "frame-ancestors 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self';"
);

// ---------------------------------------------------
// API Routes
// ---------------------------------------------------
if ($path === 'api' || strpos($path, 'api/') === 0) {
    // Prevent PHP warnings/notices from corrupting JSON output.
    // On PHP 8.x (Fedora default), display_errors is often enabled and
    // undefined-key warnings, deprecation notices, etc. would be printed
    // before the JSON body, breaking frontend parsing. Errors are still
    // logged to the PHP error log via log_errors.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    // Safety net — two tiers, only for authenticated sessions.
    // Running the scheduler against an unauthenticated request would
    // trigger CenterEdge API calls on every probe/bot hit, which is
    // wasteful and a potential DoS vector.
    //
    // Tier 1 (every authenticated API call): Fast, DB-only check for
    // recently-expired overrides whose state hasn't been corrected yet.
    //
    // Tier 2 (throttled, every 15s): Full missed-action execution and
    // live state enforcement including a CenterEdge cache sync.
    // Save the system timezone so Scheduler methods don't pollute it for the rest of the request.
    $__savedTz = date_default_timezone_get();
    if (Auth::check()) {
        require_once __DIR__ . '/lib/centeredge_client.php';
        require_once __DIR__ . '/lib/scheduler.php';

        // Tier 1: targeted expired-override enforcement (fast — cache-only unless change needed)
        try {
            Scheduler::enforceExpiredOverrides(300);
        } catch (Exception $e) {
            error_log('Expired-override enforcement failed: ' . $e->getMessage());
        }

        // Tier 2: full enforcement (throttled to avoid hammering CenterEdge).
        // The throttle period is configurable via Settings → Safety Nets.
        $tier2Throttle = (int)(DB::getConfig('tier2_throttle_seconds') ?? 60);
        $tier2Throttle = max(15, min(3600, $tier2Throttle));
        $missedCheckFile = __DIR__ . '/data/.last_missed_check';
        $throttleFh = @fopen($missedCheckFile, 'c');
        if ($throttleFh && flock($throttleFh, LOCK_EX | LOCK_NB)) {
            $mtime = @filemtime($missedCheckFile) ?: 0;
            if ((time() - $mtime) >= $tier2Throttle) {
                @touch($missedCheckFile);
                // Run inside the scheduler lock so this HTTP-driven safety
                // net doesn't race the watchdog cron / at-jobs / the
                // operator-side manual buttons that all touch the same
                // upstream state. If the lock is contended we skip — the
                // watchdog runs every minute and will catch up shortly.
                // executeMissedActions and enforceCurrentStates each
                // re-acquire the lock internally too; flock() is a no-op
                // when held by the same fd in the same process, so the
                // outer wrapper just guarantees we don't enter the slow
                // path while another worker is mid-flight.
                Scheduler::withSchedulerLock(function () {
                    try {
                        Scheduler::executeMissedActions();
                    } catch (Exception $e) {
                        error_log('Missed-action check failed: ' . $e->getMessage());
                    }
                    try {
                        Scheduler::enforceCurrentStates();
                    } catch (Exception $e) {
                        error_log('State enforcement failed: ' . $e->getMessage());
                    }
                }, /*waitSec*/ 2, /*skipOnContention*/ true);
            }
            flock($throttleFh, LOCK_UN);
        }
        if ($throttleFh) {
            fclose($throttleFh);
        }
    }
    date_default_timezone_set($__savedTz);

    // CSRF validation for state-changing methods (exempt login endpoint)
    $isLogin = ($path === 'api/auth/login');
    if (!$isLogin && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        if (!CSRF::validate()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or missing CSRF token']);
            exit;
        }
    }

    // Parse API path: api/{resource}/{id?}/{action?}
    $apiPath = substr($path, 4); // Remove 'api/'
    $parts = array_values(array_filter(explode('/', $apiPath)));
    $resource = array_shift($parts) ?? '';

    // Parse JSON body for POST/PUT/PATCH
    $input = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $rawBody = file_get_contents('php://input');
        if ($rawBody !== '') {
            $input = json_decode($rawBody, true);
            if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON body']);
                exit;
            }
        }
    }

    // Dispatch to handler
    try {
        switch ($resource) {
            case 'auth':
                require_once __DIR__ . '/api/auth.php';
                handleAuth($method, $parts, $input);
                break;
            case 'settings':
                require_once __DIR__ . '/api/settings.php';
                handleSettings($method, $parts, $input);
                break;
            case 'games':
                require_once __DIR__ . '/api/games.php';
                handleGames($method, $parts, $input);
                break;
            case 'cards':
                require_once __DIR__ . '/api/cards.php';
                handleCards($method, $parts, $input);
                break;
            case 'groups':
                require_once __DIR__ . '/api/groups.php';
                handleGroups($method, $parts, $input);
                break;
            case 'kiosks':
                require_once __DIR__ . '/api/kiosks.php';
                handleKiosks($method, $parts, $input);
                break;
            case 'schedules':
                require_once __DIR__ . '/api/schedules.php';
                handleSchedules($method, $parts, $input);
                break;
            case 'overrides':
                require_once __DIR__ . '/api/overrides.php';
                handleOverrides($method, $parts, $input);
                break;
            case 'logs':
                require_once __DIR__ . '/api/logs.php';
                handleLogs($method, $parts, $input);
                break;
            case 'users':
                require_once __DIR__ . '/api/users.php';
                handleUsers($method, $parts, $input);
                break;
            case 'capabilities':
                require_once __DIR__ . '/api/capabilities.php';
                handleCapabilities($method, $parts, $input);
                break;
            case 'health':
                handleHealthCheck();
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown API endpoint']);
        }
    } catch (RuntimeException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        $msg = $e->getMessage();
        // Surface actionable details for common setup issues
        if (stripos($msg, 'unable to open database') !== false || stripos($msg, 'readonly') !== false) {
            $hint = 'Database error: ' . $msg . '. Check that the data/ directory and .db file are writable by the web server (e.g. sudo chown -R www-data:www-data data/).';
            echo json_encode(['error' => $hint]);
        } else {
            $publicMessage = APP_DEBUG
                ? 'Internal server error: ' . sanitizeApiError($msg)
                : 'Internal server error';
            echo json_encode(['error' => $publicMessage]);
        }
        error_log('Unhandled exception in ' . $resource . ': ' . $msg . "\n" . $e->getTraceAsString());
    }

    exit;
}

/**
 * Health check endpoint.
 *
 * Public surface (unauthenticated): minimal liveness — overall status,
 * heartbeat ages, and a single "degraded" boolean. Anything that could
 * help an attacker (specific error messages, install.php-still-present
 * warnings, retry counts, exact failure reasons) requires a logged-in
 * session. This keeps the endpoint useful for external monitoring while
 * not leaking operational detail to anonymous probes.
 *
 * Authenticated callers see the full payload.
 */
function handleHealthCheck(): void {
    $isAuthed = (Auth::check() !== null);

    $dataDir = dirname(DB_PATH);
    $status = [
        'status' => 'ok',
        'cron' => null,
        'watchdog' => null,
        'database' => false,
        'centeredge' => null,
        'retries' => null,
        'caches' => null,
    ];
    $degradedReasons = [];

    // Check database connectivity
    try {
        DB::queryOne('SELECT 1');
        $status['database'] = true;
    } catch (Exception $e) {
        $status['status'] = 'degraded';
        $degradedReasons[] = 'database unreachable';
    }

    // Check cron heartbeat
    $cronHeartbeat = $dataDir . '/.heartbeat_cron';
    if (file_exists($cronHeartbeat)) {
        $lastRun = file_get_contents($cronHeartbeat);
        $ts = $lastRun !== false ? strtotime(trim($lastRun)) : false;
        $age = $ts !== false ? time() - $ts : PHP_INT_MAX;
        $status['cron'] = [
            'last_run' => $lastRun,
            'age_seconds' => $age,
            'healthy' => $age < 90000, // 25 hours (cron runs daily)
        ];
        if ($age >= 90000) {
            $status['status'] = 'degraded';
            $degradedReasons[] = 'daily cron has not run in 25h';
        }
    } else {
        $status['cron'] = ['last_run' => null, 'healthy' => false];
        $status['status'] = 'degraded';
        $degradedReasons[] = 'daily cron has never run';
    }

    // Check watchdog heartbeat
    $watchdogHeartbeat = $dataDir . '/.heartbeat_watchdog';
    if (file_exists($watchdogHeartbeat)) {
        $lastRun = file_get_contents($watchdogHeartbeat);
        $ts = $lastRun !== false ? strtotime(trim($lastRun)) : false;
        $age = $ts !== false ? time() - $ts : PHP_INT_MAX;
        $status['watchdog'] = [
            'last_run' => $lastRun,
            'age_seconds' => $age,
            'healthy' => $age < 180, // 3 minutes (watchdog runs every minute)
        ];
        if ($age >= 180) {
            $status['status'] = 'degraded';
            $degradedReasons[] = 'per-minute watchdog has not run in 3 min';
        }
    } else {
        $status['watchdog'] = ['last_run' => null, 'healthy' => false];
        $status['status'] = 'degraded';
        $degradedReasons[] = 'watchdog has never run';
    }

    // CenterEdge upstream health
    try {
        require_once __DIR__ . '/lib/centeredge_client.php';
        $ceHealth = CenterEdgeClient::getUpstreamHealth();
        $breakerOpenSince = CenterEdgeClient::circuitBreakerOpenSince();
        $ceHealth['breaker_open_since'] = $breakerOpenSince;
        $status['centeredge'] = $ceHealth;
        if (!empty($ceHealth['degraded'])) {
            $status['status'] = 'degraded';
            $msg = 'CenterEdge upstream degraded';
            if (!empty($ceHealth['consecutive_failures'])) {
                $msg .= ' (' . $ceHealth['consecutive_failures'] . ' consecutive failures)';
            }
            $degradedReasons[] = $msg;
        }
    } catch (Exception $e) {
        $status['centeredge'] = ['error' => 'unavailable'];
    }

    // Retry queue depth: how many assets are stuck waiting for the watchdog?
    // Useful as an operator-visible signal that something has been failing
    // upstream long enough to accumulate state.
    try {
        $rqRow = DB::queryOne(
            'SELECT
               COUNT(*) AS pending,
               SUM(CASE WHEN gave_up_at IS NOT NULL THEN 1 ELSE 0 END) AS gave_up,
               MIN(created_at) AS oldest_created_at
             FROM action_retries'
        );
        $pending = $rqRow ? (int)($rqRow['pending'] ?? 0) : 0;
        $gaveUp = $rqRow ? (int)($rqRow['gave_up'] ?? 0) : 0;
        $oldest = $rqRow ? ($rqRow['oldest_created_at'] ?? null) : null;
        $oldestAge = null;
        if ($oldest) {
            $ts = strtotime($oldest . ' UTC');
            if ($ts !== false) $oldestAge = max(0, time() - $ts);
        }
        $status['retries'] = [
            'pending'        => $pending,
            'gave_up'        => $gaveUp,
            'active'         => max(0, $pending - $gaveUp),
            'oldest_age_seconds' => $oldestAge,
        ];
        if ($gaveUp > 0) {
            $status['status'] = 'degraded';
            $degradedReasons[] = "$gaveUp asset(s) gave up after retry exhaustion";
        }
    } catch (Exception $e) {
        $status['retries'] = ['error' => 'unavailable'];
    }

    // Cache freshness — surfaces "we're running on stale data"
    try {
        $gameOldest = DB::queryOne('SELECT MIN(last_synced_at) AS oldest, COUNT(*) AS n FROM game_state_cache');
        $kioskOldest = DB::queryOne('SELECT MIN(last_synced_at) AS oldest, COUNT(*) AS n FROM kiosk_state_cache');
        $catFetched = DB::getConfig('categories_cache_at');

        $status['caches'] = [
            'games'      => formatCacheStat($gameOldest),
            'kiosks'     => formatCacheStat($kioskOldest),
            'categories' => $catFetched ? [
                'fetched_at'  => $catFetched,
                'age_seconds' => max(0, time() - (strtotime($catFetched . ' UTC') ?: time())),
            ] : null,
        ];
    } catch (Exception $e) {
        $status['caches'] = ['error' => 'unavailable'];
    }

    // Game-transaction polling lag — how long since the watchdog last
    // ingested a transaction page? If this grows unbounded the live activity
    // feed and Top Games widgets will go stale.
    try {
        $lastTx = DB::queryOne('SELECT MAX(fetched_at) AS last_at FROM game_play_transactions');
        $lastAt = $lastTx ? ($lastTx['last_at'] ?? null) : null;
        $lagSec = null;
        if ($lastAt) {
            $ts = strtotime($lastAt . ' UTC');
            if ($ts !== false) $lagSec = max(0, time() - $ts);
        }
        $status['polling'] = [
            'transactions' => [
                'last_fetched_at'  => $lastAt,
                'lag_seconds'      => $lagSec,
            ],
        ];
        // Only flag as degraded if the watchdog is healthy (otherwise the
        // watchdog banner already explains why polling stopped).
        if ($status['watchdog'] && !empty($status['watchdog']['healthy'])
            && $lagSec !== null && $lagSec > 1800) { // 30 min
            $status['status'] = 'degraded';
            $degradedReasons[] = 'transaction feed poll lag exceeds 30 min';
        }
    } catch (Exception $e) {
        $status['polling'] = ['error' => 'unavailable'];
    }

    // Recent error rate from action_log (last hour). Useful as a quick
    // "is something failing right now?" signal that doesn't depend on
    // upstream-call counters (which only move when something tries the API).
    try {
        $sinceCutoff = gmdate('Y-m-d H:i:s', time() - 3600);
        $errRow = DB::queryOne(
            'SELECT
               SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS errors,
               COUNT(*) AS total
             FROM action_log WHERE timestamp >= :p0',
            [$sinceCutoff]
        );
        $status['errors_last_hour'] = [
            'errors' => $errRow ? (int)($errRow['errors'] ?? 0) : 0,
            'total'  => $errRow ? (int)($errRow['total'] ?? 0) : 0,
        ];
    } catch (Exception $e) {
        $status['errors_last_hour'] = ['error' => 'unavailable'];
    }

    if (!empty($degradedReasons) && $isAuthed) {
        $status['degraded_reasons'] = $degradedReasons;
    }

    // Security warnings for operators (only visible to authenticated sessions
    // so an anonymous probe can't learn that install.php is still around).
    if ($isAuthed) {
        $warnings = [];
        if (file_exists(__DIR__ . '/install.php')) {
            $warnings[] = 'install.php is still web-accessible. Remove or block it in your web server config.';
        }
        if (file_exists(__DIR__ . '/fresh_install.php')) {
            $warnings[] = 'fresh_install.php is still present. Delete it — it contains default credentials.';
        }
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $warnings[] = 'APP_DEBUG is enabled. Disable it for production (unset PG_APP_DEBUG or set to false).';
        }
        if (!empty($warnings)) {
            $status['warnings'] = $warnings;
        }
    }

    // Strip detail-rich fields from the unauthenticated public surface.
    // Keep: overall status, heartbeat ages, degraded boolean. Drop: error
    // messages, retry counts, cache details, polling lag. External monitors
    // see "is this thing alive?" — operators see "what's wrong?".
    if (!$isAuthed) {
        if (is_array($status['centeredge'] ?? null)) {
            $status['centeredge'] = [
                'degraded' => !empty($status['centeredge']['degraded']),
            ];
        }
        unset(
            $status['retries'],
            $status['caches'],
            $status['polling'],
            $status['errors_last_hour']
        );
    }

    $httpCode = $status['status'] === 'ok' ? 200 : 503;
    http_response_code($httpCode);
    echo json_encode($status);
}

/**
 * Helper: format a cache freshness row {oldest, n} into the shape the
 * /api/health response expects. Used for game_state_cache and kiosk_state_cache.
 */
function formatCacheStat(?array $row): ?array {
    if (!$row) return null;
    $oldest = $row['oldest'] ?? null;
    $n = (int)($row['n'] ?? 0);
    if (!$oldest) {
        return ['count' => $n, 'oldest_synced_at' => null, 'age_seconds' => null];
    }
    $ts = strtotime($oldest . ' UTC');
    return [
        'count'            => $n,
        'oldest_synced_at' => $oldest,
        'age_seconds'      => $ts !== false ? max(0, time() - $ts) : null,
    ];
}

// ---------------------------------------------------
// Static file serving (CSS, JS)
// ---------------------------------------------------
if (strpos($path, 'public/') === 0) {
    $publicRoot = realpath(__DIR__ . '/public');
    $filePath = realpath(__DIR__ . '/' . $path);

    if ($publicRoot && $filePath && is_file($filePath) && strpos($filePath, $publicRoot . DIRECTORY_SEPARATOR) === 0) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=3600');
        readfile($filePath);
        exit;
    }
}

// ---------------------------------------------------
// SPA Shell — serves for all other routes
// ---------------------------------------------------
$csrfToken = CSRF::getToken();
if (!$csrfToken) {
    $csrfToken = CSRF::generate();
}
$user = Auth::check();
$userJson = $user ? json_encode($user) : 'null';
$csrfJson = json_encode($csrfToken);
$basePathJson = json_encode($basePath);
$appTimezone = DEFAULT_TIMEZONE;
try {
    $configuredTz = DB::getConfig('timezone');
    if ($configuredTz) {
        $appTimezone = $configuredTz;
    }
} catch (Exception $e) {
    // Keep default timezone if config read fails
}
$appTimezoneJson = json_encode($appTimezone);

// UI polling intervals — read from DB with safe defaults so the JS modules
// get the operator-configured values without an extra round-trip.
$uiConfig = [
    'uiPollDefaultMs'         => 30000,
    'uiPollOverrideActiveMs'  => 10000,
    'uiPollImminentMs'        => 5000,
    'uiPollGamesAnalyticsMs'  => 30000,
    'uiPollGamesFeedMs'       => 15000,
    'uiPollOverridesMs'       => 15000,
    'dashboardTopGamesLimit'  => 5,
];
try {
    $uiMap = [
        'uiPollDefaultMs'        => 'ui_poll_default_ms',
        'uiPollOverrideActiveMs' => 'ui_poll_override_active_ms',
        'uiPollImminentMs'       => 'ui_poll_imminent_ms',
        'uiPollGamesAnalyticsMs' => 'ui_poll_games_analytics_ms',
        'uiPollGamesFeedMs'      => 'ui_poll_games_feed_ms',
        'uiPollOverridesMs'      => 'ui_poll_overrides_ms',
        'dashboardTopGamesLimit' => 'dashboard_top_games_limit',
    ];
    foreach ($uiMap as $jsKey => $dbKey) {
        $val = DB::getConfig($dbKey);
        if ($val !== null) {
            $uiConfig[$jsKey] = (int)$val;
        }
    }
} catch (Exception $e) {
    // Keep defaults on any DB error
}
$uiConfigJson = json_encode($uiConfig);


?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b0e14">
    <meta name="description" content="Castle Fun Center Management System - Game scheduling and automation">
    <title>Castle Fun Center - Management System</title>
    <link rel="icon" href="data:,">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>/public/css/style.css">
</head>
<body>
    <div id="app">
        <div class="loading-overlay" id="app-loading">
            <div class="spinner"></div>
            <span>Loading...</span>
        </div>
    </div>
    <noscript>
        <div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;color:#e1e4ed;background:#0b0e14;text-align:center;padding:2rem;">
            <div>
                <h1 style="font-size:1.5rem;margin-bottom:1rem;">JavaScript Required</h1>
                <p>This application requires JavaScript to be enabled in your browser.</p>
            </div>
        </div>
    </noscript>

    <script>
        window.APP_CONFIG = Object.assign({
            basePath: <?= $basePathJson ?>,
            csrfToken: <?= $csrfJson ?>,
            user: <?= $userJson ?>,
            timezone: <?= $appTimezoneJson ?>
        }, <?= $uiConfigJson ?>);
    </script>
    <!-- Chart.js (used by the Games analytics dashboard). Pinned to a specific
         minor version so a future CDN release can't silently break the page. -->
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.js"
            crossorigin="anonymous"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/api.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/app.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/login.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/dashboard.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/games.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/cards.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/groups.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/kiosks.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/schedules.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/overrides.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/logs.js"></script>
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/settings.js"></script>
</body>
</html>
