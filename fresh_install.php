<?php
/**
 * Fresh install script — wipes everything and sets up from scratch.
 *
 * Usage (CLI):  php fresh_install.php
 * Usage (Web):  Navigate to /fresh_install.php in browser (DELETE this file after!)
 *
 * What it does:
 *   1. Removes old database files
 *   2. Generates a new encryption key and writes it into config.php
 *   3. Initializes a fresh database with all tables
 *   4. Creates a default admin user (admin / random password printed once)
 *   5. Sets default timezone
 */

$isCli = (php_sapi_name() === 'cli');

// ─── Web safety guard ────────────────────────────────────────────────────
// This script should be DELETED after first use. Block web access if an
// admin user already exists — prevents remote database wipe.
if (!$isCli) {
    $guardDbPath = __DIR__ . '/data/pause_groups.db';
    if (file_exists($guardDbPath)) {
        try {
            $guardDb = new SQLite3($guardDbPath, SQLITE3_OPEN_READONLY);
            $guardResult = $guardDb->querySingle('SELECT COUNT(*) FROM admin_users');
            $guardDb->close();
            if ($guardResult > 0) {
                http_response_code(403);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html><head><title>Forbidden</title></head><body style="font-family:sans-serif;background:#0b0e14;color:#e5534b;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">';
                echo '<div style="text-align:center"><h1>Access Denied</h1><p style="color:#7a8194">This install script is blocked because setup has already been completed.<br>Delete <code>fresh_install.php</code> from the server for security.</p></div>';
                echo '</body></html>';
                exit(1);
            }
        } catch (Exception $e) {
            // DB can't be opened — proceed with fresh install
        }
    }
}

// ─── Helper: output for both CLI and web ─────────────────────────────────
function out(string $msg, string $type = 'info'): void {
    global $isCli, $webOutput;
    if ($isCli) {
        switch ($type) {
            case 'ok':
                $prefix = "\033[32m[OK]\033[0m ";
                break;
            case 'error':
                $prefix = "\033[31m[ERROR]\033[0m ";
                break;
            case 'warn':
                $prefix = "\033[33m[WARN]\033[0m ";
                break;
            default:
                $prefix = "[INFO] ";
                break;
        }
        echo $prefix . $msg . "\n";
    } else {
        switch ($type) {
            case 'ok':
                $color = '#3dd68c';
                break;
            case 'error':
                $color = '#e5534b';
                break;
            case 'warn':
                $color = '#f0a944';
                break;
            default:
                $color = '#c8ccd4';
                break;
        }
        $webOutput[] = "<p style=\"color:$color\">" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</p>";
    }
}

$webOutput = [];

// ─── Prerequisite Checks ─────────────────────────────────────────────────
$requiredExtensions = ['sqlite3', 'openssl', 'mbstring', 'curl'];
$missing = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if ($missing) {
    if (!$isCli) { header('Content-Type: text/html; charset=utf-8'); }
    out('Missing PHP extensions: ' . implode(', ', $missing), 'error');
    out('Install them and retry. Example: sudo apt-get install php-' . implode(' php-', $missing), 'info');
    if (!$isCli) { renderWeb($webOutput); }
    exit(1);
}

function commandExists(string $command): bool {
    $output = [];
    $exitCode = 1;
    exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);
    return $exitCode === 0 && !empty($output);
}

if (!commandExists('at') || !commandExists('atrm')) {
    out("'at' binaries (at/atrm) not found. Fallback mode still works via watchdog cron; install 'at' for native queueing.", 'warn');
}

// ─── Step 1: Remove old database ─────────────────────────────────────────
$configPath = __DIR__ . '/config.php';
$dataDir    = __DIR__ . '/data';
$dbPath     = $dataDir . '/pause_groups.db';

if (!$isCli) { header('Content-Type: text/html; charset=utf-8'); }

out('=== Fresh Install: Pause Group Automation ===');
out('');

// Remove old DB files
$removed = 0;
foreach ([$dbPath, "$dbPath-wal", "$dbPath-shm"] as $f) {
    if (file_exists($f)) {
        if (@unlink($f)) {
            $removed++;
        } else {
            out("Could not delete: $f — check file permissions", 'error');
            if (!$isCli) { renderWeb($webOutput); }
            exit(1);
        }
    }
}
if ($removed > 0) {
    out("Removed old database files ($removed files)", 'ok');
} else {
    out('No existing database found — clean slate', 'ok');
}

// ─── Step 2: Ensure data directory ───────────────────────────────────────
if (!is_dir($dataDir)) {
    if (!@mkdir($dataDir, 0770, true)) {
        out("Could not create data directory: $dataDir", 'error');
        if (!$isCli) { renderWeb($webOutput); }
        exit(1);
    }
    out('Created data directory', 'ok');
} else {
    out('Data directory exists', 'ok');
}

// ─── Step 3: Generate encryption key and write to config.php ─────────────
$newKey = bin2hex(random_bytes(32)); // 64 hex chars = 32 bytes for AES-256

$configContent = file_get_contents($configPath);
if ($configContent === false) {
    out("Could not read config.php at: $configPath", 'error');
    if (!$isCli) { renderWeb($webOutput); }
    exit(1);
}

// Replace the ENCRYPTION_KEY line — handles both env-var form and hardcoded form
$pattern = "/define\(\s*'ENCRYPTION_KEY'\s*,.+?\)\s*;/s";
$replacement = "define('ENCRYPTION_KEY', getenv('PG_ENCRYPTION_KEY') ?: '$newKey');";

if (preg_match($pattern, $configContent)) {
    $newConfig = preg_replace($pattern, $replacement, $configContent, 1);
} else {
    out('Could not find ENCRYPTION_KEY line in config.php', 'error');
    if (!$isCli) { renderWeb($webOutput); }
    exit(1);
}

if (file_put_contents($configPath, $newConfig) === false) {
    out("Could not write to config.php — check file permissions", 'error');
    if (!$isCli) { renderWeb($webOutput); }
    exit(1);
}

out("Generated encryption key and saved to config.php", 'ok');

// ─── Step 4: Load config and initialize database ────────────────────────
// Re-read the updated config
require_once $configPath;
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';

try {
    $db = DB::getInstance();
    @chmod($dbPath, 0660);
    out('Database initialized with all tables', 'ok');
} catch (Exception $e) {
    out('Database initialization failed: ' . $e->getMessage(), 'error');
    if (!$isCli) { renderWeb($webOutput); }
    exit(1);
}

// ─── Step 5: Create default admin user ───────────────────────────────────
// Generate a random initial password instead of hard-coding one.  The previous
// fixed default ("admin123!") meant any operator who forgot to delete this
// script — or to change the password on first login — was exposing a known
// credential to the world.  Random + printed once = the operator must record
// it before continuing.
$adminUser = 'admin';
$adminDisplay = 'Administrator';
$randomPart = bin2hex(random_bytes(8)); // 16 hex chars
$adminPass = 'cfc!' . $randomPart;       // e.g. cfc!9f3a8b1c2d4e5f60

$hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
try {
    DB::execute(
        'INSERT INTO admin_users (username, password_hash, display_name) VALUES (:p0, :p1, :p2)',
        [$adminUser, $hash, $adminDisplay]
    );
    out("Admin user created — username: $adminUser", 'ok');
    out("INITIAL PASSWORD (record this NOW; it will not be shown again): $adminPass", 'warn');
} catch (Exception $e) {
    out('Could not create admin user: ' . $e->getMessage(), 'error');
    if (!$isCli) { renderWeb($webOutput); }
    exit(1);
}

// ─── Step 6: Set default timezone ────────────────────────────────────────
DB::setConfig('timezone', 'America/New_York', false);
out('Timezone set to America/New_York', 'ok');

// ─── Step 7: Verify encryption works ─────────────────────────────────────
try {
    $testVal = 'encryption_test_' . time();
    $encrypted = Crypto::encrypt($testVal);
    $decrypted = Crypto::decrypt($encrypted);
    if ($decrypted === $testVal) {
        out('Encryption verified — credentials will be stored securely', 'ok');
    } else {
        out('Encryption test failed — decrypted value did not match', 'error');
    }
} catch (Exception $e) {
    out('Encryption test failed: ' . $e->getMessage(), 'error');
}

// ─── Done ────────────────────────────────────────────────────────────────
out('');
out('=== Setup complete! ===', 'ok');
out('');
out('Next steps:');
out("  1. Log in with username: admin / password: $adminPass");
out('  2. CHANGE your password in Settings > Admin Users immediately');
out('  3. Configure your CenterEdge API connection in Settings');
out('  4. DELETE this file (fresh_install.php) — it is a security risk!');

if ($isCli) {
    out('');
    out('If running behind a web server, ensure the data directory is owned by the web user:');
    out("  sudo chown -R www-data:www-data $dataDir");
}

if (!$isCli) {
    renderWeb($webOutput);
}

exit(0);

// ─── Web rendering ───────────────────────────────────────────────────────
function renderWeb(array $lines): void {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Fresh Install</title>';
    echo '<style>';
    echo 'body{font-family:"Inter",-apple-system,sans-serif;background:#0b0e14;color:#c8ccd4;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}';
    echo '.card{background:#1a1d27;border:1px solid #2a2d3a;border-radius:12px;padding:2rem;max-width:600px;width:100%}';
    echo 'p{margin:0.3rem 0;font-size:0.9rem;font-family:monospace;line-height:1.5}';
    echo 'a{color:#5b8def;text-decoration:none}a:hover{text-decoration:underline}';
    echo '</style></head><body><div class="card">';
    foreach ($lines as $line) {
        echo $line;
    }
    echo '<p style="margin-top:1.5rem"><a href="./">Go to application &rarr;</a></p>';
    echo '</div></body></html>';
}
