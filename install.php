<?php
/**
 * Guided first-run setup. Creates database, first admin user, optional API config.
 *
 * Usage (CLI):  php install.php
 *               php install.php --migrate (idempotent: upgrade an existing install in place,
 *                                           preserves all data, runs schema migrations,
 *                                           reports user-role state)
 *               php install.php --reset   (wipe database and start fresh)
 * Usage (Web):  Navigate to /install.php in browser
 */

$isCli = (php_sapi_name() === 'cli');

// ─── Prerequisite Checks ────────────────────────────────────────────────────
$requiredExtensions = [
    'sqlite3' => 'sudo apt-get install php-sqlite3',
    'openssl' => 'sudo apt-get install php-openssl (often included by default)',
    'mbstring' => 'sudo apt-get install php-mbstring',
    'curl' => 'sudo apt-get install php-curl',
];

$missing = [];
foreach ($requiredExtensions as $ext => $installHint) {
    if (!extension_loaded($ext)) {
        $missing[$ext] = $installHint;
    }
}

if ($missing) {
    if ($isCli) {
        echo "[ERROR] Missing required PHP extension(s):\n\n";
        foreach ($missing as $ext => $hint) {
            echo "  - $ext\n    Install: $hint\n";
        }
        echo "\nAfter installing, restart your web server / PHP-FPM and re-run this script.\n";
        exit(1);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Setup Error</title>';
        echo '<style>body{font-family:sans-serif;background:#0b0e14;color:#c8ccd4;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}';
        echo '.card{background:#1a1d27;border:1px solid #2a2d3a;border-radius:12px;padding:2.5rem;max-width:520px;width:100%}';
        echo 'h1{color:#e5534b;font-size:1.3rem;margin-bottom:1rem}code{background:#0f1117;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.85rem}</style>';
        echo '</head><body><div class="card"><h1>Missing PHP Extensions</h1>';
        echo '<p>The following required PHP extensions are not installed:</p><ul>';
        foreach ($missing as $ext => $hint) {
            echo '<li><strong>' . htmlspecialchars($ext) . '</strong> &mdash; <code>' . htmlspecialchars($hint) . '</code></li>';
        }
        echo '</ul><p style="margin-top:1rem;color:#7a8194;">After installing, restart your web server / PHP-FPM and reload this page.</p>';
        echo '</div></body></html>';
        exit(1);
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';

function commandExists(string $command): bool {
    $output = [];
    $exitCode = 1;
    exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);
    return $exitCode === 0 && !empty($output);
}

// ─── CLI Mode ───────────────────────────────────────────────────────────────
if ($isCli) {
    echo "╔══════════════════════════════════════╗\n";
    echo "║  Pause Group Automation — Setup      ║\n";
    echo "╚══════════════════════════════════════╝\n\n";

    if (!commandExists('at') || !commandExists('atrm')) {
        echo "[WARN] 'at' scheduler binaries (at/atrm) not found.\n";
        echo "       Fallback mode will still run schedules via watchdog cron.\n";
        echo "       Optional install for native queueing: sudo apt-get install at\n\n";
    }

    // Handle --migrate flag: idempotent upgrade for existing installs.
    // Initializes any missing schema, runs ALTER TABLE migrations, backfills
    // role values, reports what changed. NEVER destructive — safe to re-run.
    if (in_array('--migrate', $argv ?? [], true)) {
        echo "Running idempotent migration over existing installation...\n";
        $dataDir = dirname(DB_PATH);
        if (!is_dir($dataDir)) {
            echo "[ERROR] Data directory missing: $dataDir\n";
            echo "        Run 'php install.php' (without --migrate) for a first-time install.\n";
            exit(1);
        }

        try {
            $db = DB::getInstance(); // triggers schema init + ALTER TABLE migrations
        } catch (Exception $e) {
            echo "[ERROR] Database initialization/migration failed: " . $e->getMessage() . "\n";
            exit(1);
        }

        // Report user-role state
        $roleCounts = DB::query(
            "SELECT role, COUNT(*) AS cnt FROM admin_users GROUP BY role ORDER BY role"
        );
        if ($roleCounts) {
            echo "[OK] User role distribution:\n";
            foreach ($roleCounts as $r) {
                $label = $r['role'] !== '' ? $r['role'] : '(unset — backfilled to admin)';
                echo "       - $label: {$r['cnt']}\n";
            }
        }

        $adminCount = DB::queryOne(
            "SELECT COUNT(*) AS cnt FROM admin_users WHERE role = 'admin' AND is_active = 1"
        );
        if ((int)($adminCount['cnt'] ?? 0) === 0) {
            echo "[WARN] No active admin users found. Promote one with:\n";
            echo "         sqlite3 " . DB_PATH . " \"UPDATE admin_users SET role='admin', is_active=1 WHERE id=<USER_ID>;\"\n";
        }

        @chmod(DB_PATH, 0660);
        @chmod(DB_PATH . '-wal', 0660);
        @chmod(DB_PATH . '-shm', 0660);

        echo "[OK] Migration complete. Existing data preserved.\n";
        echo "     Roles: admin (full access), tech (no sales), manager (no settings).\n";
        exit(0);
    }

    // Handle --reset flag: wipe existing database to start fresh
    if (in_array('--reset', $argv ?? [], true)) {
        if (file_exists(DB_PATH)) {
            echo "[WARN] This will DELETE the existing database and all data:\n";
            echo "       " . DB_PATH . "\n\n";
            $confirm = cliPrompt('Type "yes" to confirm', '');
            if ($confirm !== 'yes') {
                echo "\n[ABORT] Reset cancelled.\n";
                exit(0);
            }
            // Remove database and WAL/SHM journal files
            @unlink(DB_PATH);
            @unlink(DB_PATH . '-wal');
            @unlink(DB_PATH . '-shm');
            echo "[OK] Database deleted. Starting fresh...\n\n";
        } else {
            echo "[INFO] No existing database found. Proceeding with fresh install.\n\n";
        }
    }

    // Step 1: Ensure data directory
    $dataDir = dirname(DB_PATH);
    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0770, true)) {
            echo "[ERROR] Could not create data directory: $dataDir\n";
            echo "        Fix: sudo mkdir -p $dataDir && sudo chown www-data:www-data $dataDir && sudo chmod 770 $dataDir\n";
            exit(1);
        }
        echo "[OK] Created data directory: $dataDir\n";
    } else {
        echo "[OK] Data directory exists: $dataDir\n";
    }

    // Verify the data directory is writable
    if (!is_writable($dataDir)) {
        $currentUser = posix_getpwuid(posix_geteuid())['name'] ?? get_current_user();
        echo "[ERROR] Data directory is not writable by '$currentUser': $dataDir\n";
        echo "        Fix: sudo chown $currentUser:$currentUser $dataDir && sudo chmod 770 $dataDir\n";
        exit(1);
    }

    // Step 2: Initialize database
    echo "\nInitializing database...\n";
    try {
        $db = DB::getInstance();
        // Make database files group-writable so the web server can access them
        @chmod(DB_PATH, 0660);
        @chmod(DB_PATH . '-wal', 0660);
        @chmod(DB_PATH . '-shm', 0660);
        echo "[OK] Database initialized at: " . DB_PATH . "\n";
    } catch (Exception $e) {
        echo "[ERROR] Database initialization failed: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Step 3: Check if admin user exists
    $existingAdmin = DB::queryOne('SELECT id FROM admin_users LIMIT 1');
    if ($existingAdmin) {
        echo "\n[INFO] An admin user already exists. Skipping user creation.\n";
        echo "       To create additional users, use the web UI Settings page.\n";
    } else {
        echo "\n--- Create Admin User ---\n";
        $username = cliPrompt('Username', 'admin');
        $displayName = cliPrompt('Display name', 'Administrator');

        do {
            $password = cliPromptPassword('Password (min 8 chars)');
            if (strlen($password) < 8) {
                echo "  Password must be at least 8 characters.\n";
                continue;
            }
            $confirm = cliPromptPassword('Confirm password');
            if ($password !== $confirm) {
                echo "  Passwords do not match. Try again.\n";
                continue;
            }
            break;
        } while (true);

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        // First user is always created with the 'admin' role — the highest tier.
        // Additional roles (tech, manager) can be assigned via the web UI afterwards.
        DB::execute(
            'INSERT INTO admin_users (username, password_hash, display_name, role) VALUES (:p0, :p1, :p2, :p3)',
            [$username, $hash, $displayName, 'admin']
        );
        echo "[OK] Admin user '$username' created with role 'admin'.\n";
        echo "     To create technician or manager accounts, log in and use Settings > Admin Users.\n";
    }

    // Step 4: Timezone
    $currentTz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    echo "\n--- Timezone ---\n";
    $tz = cliPrompt('Timezone', $currentTz);
    if (@timezone_open($tz)) {
        DB::setConfig('timezone', $tz);
        echo "[OK] Timezone set to: $tz\n";
    } else {
        echo "[WARN] Invalid timezone '$tz'. Keeping: $currentTz\n";
    }

    // Step 5: CenterEdge API (optional)
    echo "\n--- CenterEdge API Configuration (optional) ---\n";
    $configureApi = cliPrompt('Configure CenterEdge API now? (y/n)', 'n');

    if (strtolower($configureApi) === 'y') {
        $baseUrl = cliPrompt('API Base URL', '');
        $apiUser = cliPrompt('API Username', '');
        $apiPass = cliPromptPassword('API Password');
        $apiKey  = cliPrompt('API Key (optional, press Enter to skip)', '');

        if ($baseUrl) {
            DB::setConfig('base_url', $baseUrl, false);
            echo "  Base URL saved.\n";
        }
        if ($apiUser) {
            DB::setConfig('username', $apiUser, true);
            echo "  Username saved (encrypted).\n";
        }
        if ($apiPass) {
            DB::setConfig('password', $apiPass, true);
            echo "  Password saved (encrypted).\n";
        }
        if ($apiKey) {
            DB::setConfig('api_key', $apiKey, true);
            echo "  API Key saved (encrypted).\n";
        }

        // Test connection
        echo "\nTesting connection...\n";
        try {
            require_once __DIR__ . '/lib/centeredge_client.php';
            $client = new CenterEdgeClient();
            $result = $client->testConnection();
            echo "[OK] Connected! " . ($result['message'] ?? '') . "\n";
        } catch (Exception $e) {
            echo "[WARN] Connection test failed: " . $e->getMessage() . "\n";
            echo "       You can reconfigure via the web UI Settings page.\n";
        }
    } else {
        echo "[INFO] Skipped. Configure via web UI Settings page after login.\n";
    }

    // Step 6: Cron setup guidance
    echo "\n--- Cron Setup ---\n";
    echo "Add the following to your crontab (crontab -e):\n\n";
    echo "  * * * * * /usr/bin/php " . __DIR__ . "/cron_watchdog.php >> " . dirname(DB_PATH) . "/watchdog.log 2>&1\n";
    echo "  5 0 * * * /usr/bin/php " . __DIR__ . "/cron.php >> " . dirname(DB_PATH) . "/cron.log 2>&1\n\n";
    echo "The watchdog runs every minute, and the daily planner runs at 00:05.\n";

    // Step 7: Set web server ownership
    echo "\n--- File Permissions ---\n";
    $dataDir = dirname(DB_PATH);
    echo "The web server needs read/write access to the data directory.\n";
    echo "Run this command now:\n\n";
    echo "  sudo chown -R www-data:www-data $dataDir\n\n";

    echo "╔══════════════════════════════════════╗\n";
    echo "║  Setup complete!                     ║\n";
    echo "╚══════════════════════════════════════╝\n\n";
    echo "Next steps:\n";
    echo "  1. Run the chown command above (required for login to work!)\n";
    echo "  2. Navigate to the site and log in\n";
    echo "  3. Configure CenterEdge API in Settings (if not done above)\n";
    echo "  4. Create Pause Groups, then Schedules\n\n";
    exit(0);
}

// ─── Web Mode ───────────────────────────────────────────────────────────────
// Simple web-based setup for first-run

// Hard lockout: if an admin already exists, refuse to serve the installer at all.
// This prevents information leakage (confirming the app exists) and closes any
// residual attack surface if the file is accidentally left web-accessible.
try {
    $existingAdmin = DB::queryOne('SELECT id FROM admin_users LIMIT 1');
    if ($existingAdmin) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Setup has already been completed. This endpoint is disabled.';
        exit;
    }
} catch (Exception $e) {
    // DB not initialized yet — allow installer to proceed
}

header('Content-Type: text/html; charset=utf-8');

$step = $_POST['step'] ?? 'check';
$message = '';
$messageType = '';
$atAvailable = commandExists('at') && commandExists('atrm');

// Process POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Hard stop after first-time setup: never allow creating additional
        // admin users from this unauthenticated installer endpoint.
        $setupAlreadyCompleted = (bool)DB::queryOne('SELECT id FROM admin_users LIMIT 1');
        if ($setupAlreadyCompleted) {
            throw new RuntimeException('Setup has already been completed. Delete or restrict install.php after first run.');
        }

        switch ($step) {
            case 'create_admin':
                $username = trim($_POST['username'] ?? '');
                $displayName = trim($_POST['display_name'] ?? '');
                $password = $_POST['password'] ?? '';
                $confirm = $_POST['confirm_password'] ?? '';

                if (!$username) throw new RuntimeException('Username is required.');
                if (strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters.');
                if ($password !== $confirm) throw new RuntimeException('Passwords do not match.');

                $db = DB::getInstance();
                $existing = DB::queryOne('SELECT id FROM admin_users WHERE username = :p0', [$username]);
                if ($existing) throw new RuntimeException("Username '$username' already exists.");

                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                DB::execute(
                    'INSERT INTO admin_users (username, password_hash, display_name, role) VALUES (:p0, :p1, :p2, :p3)',
                    [$username, $hash, $displayName, 'admin']
                );
                $message = "Admin user '$username' created successfully (role: admin).";
                $messageType = 'success';
                $step = 'done';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
        $step = 'create_admin';
    }
}

// Check current state
$dbExists = file_exists(DB_PATH);
$hasAdmin = false;
if ($dbExists) {
    try {
        $db = DB::getInstance();
        $hasAdmin = (bool)DB::queryOne('SELECT id FROM admin_users LIMIT 1');
    } catch (Exception $e) {
        // DB not initialized yet
    }
}

if ($hasAdmin && $step !== 'done') {
    $step = 'already_setup';
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — Pause Group Automation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0b0e14; color: #c8ccd4;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 1rem;
        }
        .setup-card {
            background: #1a1d27; border: 1px solid #2a2d3a; border-radius: 12px;
            padding: 2.5rem; max-width: 480px; width: 100%;
        }
        h1 { color: #e2e8f0; font-size: 1.5rem; margin-bottom: 0.5rem; }
        p.sub { color: #7a8194; font-size: 0.9rem; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; color: #94a3b8; font-size: 0.85rem; margin-bottom: 0.35rem; font-weight: 500; }
        input[type="text"], input[type="password"] {
            width: 100%; padding: 0.6rem 0.8rem; background: #0f1117; border: 1px solid #2a2d3a;
            border-radius: 6px; color: #e2e8f0; font-size: 0.9rem; outline: none;
        }
        input:focus { border-color: #5b8def; }
        .btn {
            display: inline-block; padding: 0.6rem 1.5rem; background: #5b8def; color: #fff;
            border: none; border-radius: 6px; font-size: 0.9rem; cursor: pointer; font-weight: 500;
            margin-top: 0.5rem;
        }
        .btn:hover { background: #4a7de0; }
        .msg { padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.85rem; }
        .msg.success { background: rgba(61,214,140,0.15); color: #3dd68c; border: 1px solid rgba(61,214,140,0.3); }
        .msg.error { background: rgba(229,83,75,0.15); color: #e5534b; border: 1px solid rgba(229,83,75,0.3); }
        .check { color: #3dd68c; }
        .info { color: #7a8194; font-size: 0.85rem; margin-top: 1rem; line-height: 1.5; }
        a { color: #5b8def; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="setup-card">
    <h1>Pause Group Automation</h1>

    <?php if ($message): ?>
        <div class="msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!$atAvailable): ?>
        <div class="msg">System dependency check: <code>at</code>/<code>atrm</code> not found. The app can still run via watchdog/manual cron fallback, but timed jobs will not be queued as native <code>at</code> tasks.</div>
    <?php endif; ?>

    <?php if ($step === 'already_setup'): ?>
        <p class="sub">Setup has already been completed.</p>
        <p><span class="check">&#10003;</span> Database initialized</p>
        <p><span class="check">&#10003;</span> Admin user exists</p>
        <div class="info">
            <p><a href="./">Go to application &rarr;</a></p>
            <p style="margin-top: 0.5rem;">To manage users and settings, log in and visit the Settings page.</p>
        </div>

    <?php elseif ($step === 'done'): ?>
        <p class="sub">Setup complete!</p>
        <p><span class="check">&#10003;</span> Database initialized</p>
        <p><span class="check">&#10003;</span> Admin user created</p>
        <div class="info">
            <p><a href="./">Log in to get started &rarr;</a></p>
            <p style="margin-top: 0.5rem;">After logging in, configure the CenterEdge API connection in Settings.</p>
        </div>

    <?php else: ?>
        <p class="sub">Create your first admin account to get started.</p>

        <?php if ($dbExists): ?>
            <p style="margin-bottom: 1rem;"><span class="check">&#10003;</span> Database initialized</p>
        <?php else: ?>
            <p style="margin-bottom: 1rem; color: #f0a944;">Database will be created automatically.</p>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="step" value="create_admin">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>" required>
            </div>

            <div class="form-group">
                <label for="display_name">Display Name</label>
                <input type="text" id="display_name" name="display_name" value="<?= htmlspecialchars($_POST['display_name'] ?? 'Administrator') ?>">
            </div>

            <div class="form-group">
                <label for="password">Password (min 8 characters)</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn">Create Admin &amp; Initialize</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
<?php

// ─── CLI Helper Functions ───────────────────────────────────────────────────
function cliPrompt(string $prompt, string $default = ''): string {
    $display = $default ? "$prompt [$default]: " : "$prompt: ";
    echo $display;
    $line = trim(fgets(STDIN));
    return $line !== '' ? $line : $default;
}

function cliPromptPassword(string $prompt): string {
    // Try to hide input on Unix systems
    if (function_exists('readline')) {
        echo "$prompt: ";
        // Attempt to disable echo
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            system('stty -echo 2>/dev/null');
            $password = trim(fgets(STDIN));
            system('stty echo 2>/dev/null');
            echo "\n";
        } else {
            $password = trim(fgets(STDIN));
        }
        return $password;
    }
    echo "$prompt: ";
    return trim(fgets(STDIN));
}
