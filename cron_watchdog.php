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

// Acquire the watchdog-specific lock. Using a dedicated lock (not LOCK_FILE)
// means the per-minute watchdog never has to wait for the daily cron's
// multi-second data-sync run to finish — its enforcement and missed-action
// checks fire on schedule. Concurrent watchdog instances are still prevented
// because they all contend on this same file.
$lockFile = fopen(WATCHDOG_LOCK_FILE, 'c');
if (!$lockFile) {
    exit(0);
}
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    // Another watchdog cycle is still running — skip this one.
    error_log("[" . date('c') . "] watchdog: prior cycle still running, skipping");
    fclose($lockFile);
    exit(0);
}

try {
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    date_default_timezone_set($tz);
    $today = date('Y-m-d');

    $errors = [];

    // Helper: run a callback while briefly holding LOCK_FILE so we don't race
    // with the daily cron's planning section or run_action.php. If we can't
    // acquire it within $waitSec we skip that step but the rest of the cycle
    // still runs (enforcement, retries, transaction polling) — that's the
    // whole point of having a separate watchdog lock.
    $withPlanLock = function (string $name, int $waitSec, callable $fn) use (&$errors): void {
        $fh = @fopen(LOCK_FILE, 'c');
        if (!$fh) {
            $errors[] = "$name: could not open scheduler lock file";
            return;
        }
        $held = false;
        for ($i = 0; $i < $waitSec; $i++) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                $held = true;
                break;
            }
            sleep(1);
        }
        if (!$held) {
            $errors[] = "$name: scheduler lock busy after {$waitSec}s, skipping this cycle";
            error_log("[" . date('c') . "] watchdog $name: skipping (planning lock busy)");
            fclose($fh);
            return;
        }
        // Tell Scheduler we already hold the lock so nested
        // withSchedulerLock() calls (from executeMissedActions etc) don't
        // race a competing fd within this same process.
        Scheduler::declareLockHeld();
        try {
            $fn();
        } catch (Exception $e) {
            $errors[] = "$name: " . $e->getMessage();
            error_log("[" . date('c') . "] watchdog $name error: " . $e->getMessage());
        } finally {
            Scheduler::declareLockReleased();
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    };

    // Execute any missed actions (scheduled time has passed but not yet executed).
    // Mutates scheduled_actions, so coordinate with cron.php's planning section.
    $withPlanLock('executeMissedActions', 5, function () use ($today) {
        Scheduler::executeMissedActions($today);
    });

    // Enforce the live desired state. This patches CenterEdge but does NOT
    // mutate scheduled_actions, so it's safe to run without LOCK_FILE.
    try {
        Scheduler::enforceCurrentStates();
    } catch (Exception $e) {
        $errors[] = "enforceCurrentStates: " . $e->getMessage();
        error_log("[" . date('c') . "] watchdog enforceCurrentStates error: " . $e->getMessage());
    }

    // Re-queue any actions that are missing their at jobs (pending, future, no
    // at_job_id). Mutates scheduled_actions.at_job_id, so take the plan lock.
    $withPlanLock('queueAtJobs', 5, function () use ($today) {
        Scheduler::queueAtJobs($today);
    });

    // Re-attempt any pause/unpause actions that previously failed at the
    // source (e.g. kiosk in use). Cap is enforced inside processRetries —
    // assets that hit max_attempts are marked gave_up_at and skipped on
    // subsequent ticks until a fresh intent (manual click, schedule
    // transition, override boundary) clears the flag.
    try {
        $retrySummary = Scheduler::processRetries();
        if (!empty($retrySummary['attempted'])) {
            echo "[" . date('c') . "] watchdog retries: " . json_encode($retrySummary) . "\n";
        }
    } catch (Exception $e) {
        $errors[] = "processRetries: " . $e->getMessage();
        error_log("[" . date('c') . "] watchdog processRetries error: " . $e->getMessage());
    }

    // Poll the upstream game-play transaction feed. Best-effort: if the API is
    // unreachable or the card system doesn't expose the feed yet, swallow the
    // error so the rest of the watchdog cycle still runs. Capped at 20 pages
    // per cycle (4000 plays) to keep watchdog runtime bounded.
    //
    // The interval is configurable via Settings → Polling Intervals. The
    // watchdog runs every minute but will skip the CE fetch when the last
    // poll was more recent than the configured interval. This lets venues
    // trade data freshness for fewer upstream API calls.
    try {
        $client = new CenterEdgeClient();
        if ($client->isConfigured()) {
            $txPollInterval = (int)(DB::getConfig('tx_poll_interval_seconds') ?? 60);
            $txPollInterval = max(60, min(900, $txPollInterval));

            // Derive last-poll time from MAX(fetched_at) so we don't need a
            // separate config key. Falls back to 0 (epoch) when no rows exist.
            $lastPollRow = DB::queryOne('SELECT MAX(fetched_at) AS last_at FROM game_play_transactions');
            $lastPollTs  = $lastPollRow && $lastPollRow['last_at']
                ? strtotime($lastPollRow['last_at'] . ' UTC')
                : 0;

            if ((time() - $lastPollTs) >= $txPollInterval) {
                $txSummary = $client->pollGameTransactions('default');
                if (!empty($txSummary['fetched'])) {
                    echo "[" . date('c') . "] watchdog game-tx poll: " . json_encode($txSummary) . "\n";
                }
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
    }

} catch (Exception $e) {
    $msg = "[" . date('c') . "] watchdog fatal error: " . $e->getMessage() . "\n";
    echo $msg;
    error_log($msg);
} finally {
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
}
