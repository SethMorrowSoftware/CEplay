<?php
/**
 * Watchdog cron: catches missed actions and re-queues broken at jobs.
 * Run every minute as a safety net alongside the daily cron.
 *
 * Usage: php cron_watchdog.php
 * Crontab: * * * * * /usr/bin/php /path/to/app/cron_watchdog.php >> /path/to/app/data/watchdog.log 2>&1
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

// Acquire the global scheduler lock with a short blocking wait (up to 15s so
// the watchdog almost always runs within its 1-minute cadence). Uses the
// shared re-entrant helper so nested Scheduler calls don't deadlock.
//
// IMPORTANT: the lock is held ONLY for the action phase (missed actions,
// enforcement, at-job queueing, retries) and released BEFORE the
// transaction-feed poll. Polling is the slowest step and touches no
// scheduling state — holding the lock through it starved web-triggered
// manual actions for many seconds out of every minute.
if (!Scheduler::acquireLock(15)) {
    error_log("[" . date('c') . "] watchdog: lock not acquired after 15s, skipping cycle");
    exit(0);
}
$lockHeld = true;

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

    // Re-attempt any pause/unpause actions that previously failed at the
    // source (e.g. kiosk in use). Cap is enforced inside processRetries —
    // assets that hit max_attempts are dropped with a give-up audit entry.
    try {
        $retrySummary = Scheduler::processRetries();
        if (!empty($retrySummary['attempted'])) {
            echo "[" . date('c') . "] watchdog retries: " . json_encode($retrySummary) . "\n";
        }
    } catch (Exception $e) {
        $errors[] = "processRetries: " . $e->getMessage();
        error_log("[" . date('c') . "] watchdog processRetries error: " . $e->getMessage());
    }

    // Action phase complete — release the scheduler lock before the slow,
    // lock-free transaction poll so manual actions from the UI aren't blocked.
    Scheduler::releaseLock();
    $lockHeld = false;

    // Poll the upstream game-play transaction feeds — every feed the card
    // system advertises via capabilities (games.transactionFeedNames), not
    // just "default", so plays reported on secondary feeds (e.g. a separate
    // credit-card feed) are never missed. Best-effort: if the API is
    // unreachable or the card system doesn't expose the feed yet, swallow the
    // error so the rest of the watchdog cycle still runs. Capped at 20 pages
    // per feed per cycle (4000 plays) to keep watchdog runtime bounded.
    try {
        $client = new CenterEdgeClient();
        if ($client->isConfigured()) {
            $txSummary = $client->pollAllGameTransactionFeeds();
            if (!empty($txSummary['fetched'])) {
                echo "[" . date('c') . "] watchdog game-tx poll: " . json_encode($txSummary) . "\n";
            }
            foreach ($txSummary['errors'] as $feed => $msg) {
                $errors[] = "pollGameTransactions($feed): $msg";
            }
        }
    } catch (Exception $e) {
        $errors[] = "pollGameTransactions: " . $e->getMessage();
        error_log("[" . date('c') . "] watchdog pollGameTransactions error: " . $e->getMessage());
    }

    // Write heartbeat even if individual steps had transient errors,
    // so long as the watchdog itself is running
    Scheduler::writeHeartbeat('watchdog');

    if (!empty($errors)) {
        echo "[" . date('c') . "] watchdog completed with " . count($errors) . " error(s): " . implode('; ', $errors) . "\n";
    } elseif (date('i') === '00') {
        // One "alive" line per hour so watchdog.log visibly proves the timer is
        // firing even when every cycle is healthy and quiet.
        echo "[" . date('c') . "] watchdog healthy (hourly heartbeat line)\n";
    }

} catch (Exception $e) {
    $msg = "[" . date('c') . "] watchdog fatal error: " . $e->getMessage() . "\n";
    echo $msg;
    error_log($msg);
} finally {
    if ($lockHeld) {
        Scheduler::releaseLock();
    }
}
