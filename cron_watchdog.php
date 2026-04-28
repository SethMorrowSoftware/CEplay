<?php
/**
 * Watchdog cron: catches missed actions and re-queues broken at jobs.
 * Run every minute as a safety net alongside the daily cron.
 *
 * Usage: php cron_watchdog.php
 * Crontab: * * * * * /usr/bin/php /var/www/html/ce/pause-groups-main/cron_watchdog.php >> /var/www/html/ce/pause-groups-main/data/watchdog.log 2>&1
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

// Acquire exclusive file lock with a short blocking wait.
// Previous behavior: non-blocking skip.  Problem: if cron.php or run_action.php
// held the lock for even a few seconds the watchdog would silently do nothing,
// causing missed actions and delayed enforcement.  Now we retry for up to 15s
// so the watchdog almost always runs within its 1-minute cadence.
$lockFile = fopen(LOCK_FILE, 'c');
if (!$lockFile) {
    exit(0);
}
$lockAcquired = false;
for ($i = 0; $i < 15; $i++) {
    if (flock($lockFile, LOCK_EX | LOCK_NB)) {
        $lockAcquired = true;
        break;
    }
    sleep(1);
}
if (!$lockAcquired) {
    // Another long-running process still holds the lock after 15s — skip this cycle
    error_log("[" . date('c') . "] watchdog: lock not acquired after 15s, skipping cycle");
    fclose($lockFile);
    exit(0);
}

try {
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    date_default_timezone_set($tz);
    $today = date('Y-m-d');

    $errors = [];

    // Execute any missed actions (scheduled time has passed but not yet executed)
    try {
        Scheduler::executeMissedActions($today);
    } catch (Exception $e) {
        $errors[] = "executeMissedActions: " . $e->getMessage();
        error_log("[" . date('c') . "] watchdog executeMissedActions error: " . $e->getMessage());
    }

    // Enforce the live desired state as a fallback when queued jobs are delayed/missing.
    try {
        Scheduler::enforceCurrentStates();
    } catch (Exception $e) {
        $errors[] = "enforceCurrentStates: " . $e->getMessage();
        error_log("[" . date('c') . "] watchdog enforceCurrentStates error: " . $e->getMessage());
    }

    // Re-queue any actions that are missing their at jobs
    // (pending, future, but no at_job_id — can happen if at failed silently)
    try {
        Scheduler::queueAtJobs($today);
    } catch (Exception $e) {
        $errors[] = "queueAtJobs: " . $e->getMessage();
        error_log("[" . date('c') . "] watchdog queueAtJobs error: " . $e->getMessage());
    }

    // Write heartbeat even if individual steps had transient errors,
    // so long as the watchdog itself is running
    Scheduler::writeHeartbeat('watchdog');

    if (!empty($errors)) {
        echo "[" . date('c') . "] watchdog completed with " . count($errors) . " error(s): " . implode('; ', $errors) . "\n";
    }

} catch (Exception $e) {
    $msg = "[" . date('c') . "] watchdog fatal error: " . $e->getMessage() . "\n";
    echo $msg;
    error_log($msg);
} finally {
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
}
