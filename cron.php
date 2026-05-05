<?php
/**
 * Daily cron job: syncs game states, plans the day's actions, queues at jobs.
 * Run once per day (recommended: 00:05).
 *
 * Usage: php cron.php
 * Crontab: 5 0 * * * /usr/bin/php /var/www/html/ce/pause-groups-main/cron.php >> /var/www/html/ce/pause-groups-main/data/cron.log 2>&1
 */

// CLI-only guard
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/centeredge_client.php';
require_once __DIR__ . '/lib/scheduler.php';

// Ensure data directory exists
$dataDir = dirname(DB_PATH);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0770, true);
}

// Acquire exclusive file lock (non-blocking — skip if another instance is running).
// Close the handle on the early-exit path so we don't leak a descriptor when
// flock contended with a parallel run_action.php / cron_watchdog.php invocation.
$lockFile = fopen(LOCK_FILE, 'c');
if (!$lockFile) {
    echo "[" . date('c') . "] Could not open lock file. Exiting.\n";
    exit(1);
}
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] Another instance is already running. Exiting.\n";
    fclose($lockFile);
    exit(0);
}

try {
    // Load timezone
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    date_default_timezone_set($tz);
    $today = date('Y-m-d');

    echo "[" . date('c') . "] === Daily Plan for $today (TZ: $tz) ===\n";

    // Step 1: Sync game states from CenterEdge
    echo "Syncing game states from CenterEdge...\n";
    try {
        $count = Scheduler::syncGameStates();
        echo "  Synced $count games.\n";
    } catch (Exception $e) {
        echo "  WARNING: Game sync failed: " . $e->getMessage() . "\n";
        echo "  Continuing with cached data...\n";
    }

    // Step 1b: Sync kiosks (best-effort; the card system may not support the
    // /kiosks endpoint at all — the call will throw a 404 and we just continue).
    echo "Syncing kiosks from CenterEdge...\n";
    try {
        $kioskCount = Scheduler::syncKioskStates();
        echo "  Synced $kioskCount kiosks.\n";
    } catch (Exception $e) {
        echo "  Note: kiosk sync skipped — " . $e->getMessage() . "\n";
    }

    // Step 2: Execute any missed actions from earlier
    echo "Checking for missed actions...\n";
    Scheduler::executeMissedActions($today);

    // Step 3: Plan today's actions
    echo "Planning actions for $today...\n";
    $actions = Scheduler::planDay($today);
    echo "  Planned " . count($actions) . " actions:\n";
    foreach ($actions as $a) {
        echo "    {$a['time']} - {$a['action']} - {$a['group_name']} ({$a['source']})\n";
    }

    // Step 4: Queue at jobs (if available on this host)
    echo "Queuing at jobs (or fallback mode if at/atrm unavailable)...\n";
    Scheduler::queueAtJobs($today);
    echo "  Done.\n";

    // Step 5: Purge old data to prevent unbounded growth
    echo "Purging old data...\n";
    try {
        $purged = Scheduler::purgeOldData();
        echo "  Purged: {$purged['action_log_purged']} log entries, "
            . "{$purged['scheduled_actions_purged']} old actions, "
            . "{$purged['overrides_purged']} expired overrides, "
            . "{$purged['game_plays_purged']} old game plays.\n";
    } catch (Exception $e) {
        echo "  WARNING: Data purge failed: " . $e->getMessage() . "\n";
    }

    // Step 6: Rotate log files (keep last ~256KB when file exceeds 512KB)
    foreach (['cron.log', 'watchdog.log'] as $logName) {
        $logPath = $dataDir . '/' . $logName;
        if (file_exists($logPath) && filesize($logPath) > 512000) {
            $fh = @fopen($logPath, 'r');
            if ($fh) {
                // Seek to (filesize - 256KB) and read the tail
                fseek($fh, -262144, SEEK_END);
                fgets($fh); // Discard partial first line
                $tail = fread($fh, 262144 + 1024);
                fclose($fh);
                if ($tail !== false) {
                    file_put_contents($logPath, $tail);
                }
                echo "  Rotated $logName\n";
            }
        }
    }

    // Step 7: Write heartbeat for external monitoring
    Scheduler::writeHeartbeat('cron');

    echo "[" . date('c') . "] === Daily plan complete ===\n\n";

} catch (Exception $e) {
    $msg = "[" . date('c') . "] FATAL ERROR: " . $e->getMessage() . "\n";
    echo $msg;
    error_log($msg);

    // Log the error to action_log
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, error_message, details)
             VALUES (:p0, :p1, :p2, :p3, :p4)',
            ['cron', 'plan_day', 0, $e->getMessage(), json_encode(['trace' => $e->getTraceAsString()])]
        );
    } catch (Exception $logE) {
        // Can't log — just output
        echo "Failed to log error: " . $logE->getMessage() . "\n";
    }

    exit(1);
} finally {
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
}
