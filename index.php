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
// Under `php -S host:port index.php` (the documented local-dev mode) the
// built-in server reports SCRIPT_NAME as the REQUEST path for routed URLs
// (e.g. "/api/health"), which would make basePath swallow "/api" and break
// every API route. Real deployments always execute index.php directly
// (SCRIPT_NAME ends in ".php"), so only derive a prefix in that case.
$basePath = substr($scriptName, -4) === '.php' ? rtrim(dirname($scriptName), '/') : '';

/**
 * Cache-busting URL for a local asset. This app has no build step and serves
 * JS/CSS straight from disk, so without a version query a browser keeps
 * running a stale cached bundle after a deploy — shipped features stay
 * invisible until a manual hard refresh. Appending ?v=<mtime> makes the URL
 * change exactly when the file changes, so the browser refetches on the next
 * normal reload. $ver overrides the file's own mtime (used for style.css,
 * whose @import partials wouldn't otherwise bust it — we key it to the newest
 * file anywhere in the CSS tree instead).
 */
function assetUrl(string $basePath, string $rel, ?int $ver = null): string {
    if ($ver === null) {
        $ver = @filemtime(__DIR__ . $rel) ?: 0;
    }
    return htmlspecialchars($basePath . $rel . '?v=' . $ver);
}

/** Newest mtime anywhere under a directory (recursive) — for tree-wide busting. */
function assetTreeVersion(string $dir): int {
    $latest = 0;
    if (!is_dir($dir)) {
        return $latest;
    }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $m = $f->getMTime();
                if ($m > $latest) {
                    $latest = $m;
                }
            }
        }
    } catch (Exception $e) {
        // Fall back to 0 (no busting) rather than breaking page render.
    }
    return $latest;
}

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
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline'; " .
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

        // Both tiers run under the global scheduler lock so web-triggered
        // enforcement never races the watchdog / at-jobs on the state cache
        // and CenterEdge PATCHes. Short wait only: if the lock is busy, skip
        // quietly — the watchdog re-covers this ground within a minute, and
        // an API request must not stall behind a long action phase.
        if (Scheduler::acquireLock(2)) {
            try {
                // Tier 1: targeted expired-override enforcement (fast — cache-only unless change needed)
                try {
                    Scheduler::enforceExpiredOverrides(300);
                } catch (Exception $e) {
                    error_log('Expired-override enforcement failed: ' . $e->getMessage());
                }

                // Tier 2: full enforcement (throttled to avoid hammering CenterEdge)
                $missedCheckFile = __DIR__ . '/data/.last_missed_check';
                if (!file_exists($missedCheckFile) || (time() - filemtime($missedCheckFile)) >= 15) {
                    @touch($missedCheckFile);
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
                }
            } finally {
                Scheduler::releaseLock();
            }
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
            case 'roles':
                require_once __DIR__ . '/api/roles.php';
                handleRoles($method, $parts, $input);
                break;
            case 'capabilities':
                require_once __DIR__ . '/api/capabilities.php';
                handleCapabilities($method, $parts, $input);
                break;
            case 'analytics':
                require_once __DIR__ . '/api/analytics.php';
                handleAnalytics($method, $parts, $input);
                break;
            case 'reader-groups':
                require_once __DIR__ . '/api/reader_groups.php';
                handleReaderGroups($method, $parts, $input);
                break;
            case 'labor':
                require_once __DIR__ . '/api/labor.php';
                handleLabor($method, $parts, $input);
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
 * Health check endpoint (no auth required).
 * Reports cron heartbeat status so operators can detect if scheduling is alive.
 */
function handleHealthCheck(): void {
    $dataDir = dirname(DB_PATH);
    $status = ['status' => 'ok', 'cron' => null, 'watchdog' => null, 'database' => false];

    // Check database connectivity
    try {
        DB::queryOne('SELECT 1');
        $status['database'] = true;
    } catch (Exception $e) {
        $status['status'] = 'degraded';
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
        }
    } else {
        $status['cron'] = ['last_run' => null, 'healthy' => false];
        $status['status'] = 'degraded';
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
        }
    } else {
        $status['watchdog'] = ['last_run' => null, 'healthy' => false];
        $status['status'] = 'degraded';
    }

    // Security warnings for operators
    $warnings = [];
    // Transaction-feed backlog: set by pollGameTransactions when the per-cycle
    // page cap is hit with pages still full (feed producing faster than we
    // drain — e.g. catching up after an outage). Cleared automatically.
    try {
        // One backlog flag per polled feed (game_tx_backlog_<feedName>) —
        // scan by prefix so secondary feeds surface here without this check
        // needing to know the capabilities feed list.
        $backlogRows = DB::query(
            "SELECT key, value FROM api_config WHERE key LIKE 'game_tx_backlog_%' AND value IS NOT NULL"
        );
        foreach ($backlogRows as $blr) {
            $feed = substr((string)$blr['key'], strlen('game_tx_backlog_'));
            // Advisory only (no status downgrade): the app functions normally
            // during a catch-up; only recent-plays reporting lags.
            $warnings[] = 'Game-play transaction feed "' . $feed . '" is backlogged (since '
                . $blr['value'] . ' UTC). Recent-plays data may lag until the poll catches up.';
        }
    } catch (Exception $e) {
        // Health check must never fail on a config read
    }
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

    $httpCode = $status['status'] === 'ok' ? 200 : 503;
    http_response_code($httpCode);
    echo json_encode($status);
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
if ($user) {
    // Resolved permission set for the client-side gates (nav, buttons,
    // route guards). The server re-checks every call regardless. Includes
    // the per-user grant/deny overrides.
    $user['permissions'] = Auth::permissionsForUser($user['id'] ?? null, $user['role'] ?? '');
    $roles = Auth::getRoles();
    $user['role_name'] = $roles[$user['role'] ?? '']['name'] ?? ($user['role'] ?? '');
}
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


?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0d14">
    <meta name="description" content="Castle Fun Center Management System - Game scheduling and automation">
    <title>Castle Fun Center - Management System</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235b8def' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 21V9l3 2V7l3 2V6l3 2V5l3 2V8l3-1v4l3-2v12'/%3E%3Cpath d='M3 21h18M10 21v-5h4v5'/%3E%3C/svg%3E">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl($basePath, '/public/css/style.css', assetTreeVersion(__DIR__ . '/public/css')) ?>">
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
        window.APP_CONFIG = {
            basePath: <?= $basePathJson ?>,
            csrfToken: <?= $csrfJson ?>,
            user: <?= $userJson ?>,
            timezone: <?= $appTimezoneJson ?>
        };
    </script>
    <script defer src="<?= assetUrl($basePath, '/public/js/api.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/app.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/login.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/dashboard.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/games.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/cards.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/groups.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/kiosks.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/schedules.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/overrides.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/logs.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/settings.js') ?>"></script>
    <!-- Chart.js is vendored locally so analytics charts work without
         internet access and never depend on a third-party CDN. -->
    <script defer src="<?= htmlspecialchars($basePath) ?>/public/js/vendor/chart.umd.min.js?v=4.4.7"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/analytics.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/performance.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/readers.js') ?>"></script>
    <script defer src="<?= assetUrl($basePath, '/public/js/labor.js') ?>"></script>
</body>
</html>
