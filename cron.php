<?php
/**
 * Daily cron job: syncs game states, plans the day's actions, queues at jobs.
 * Run once per day (recommended: 00:05).
 *
 * Usage: php cron.php
 * Crontab: 5 0 * * * /usr/bin/php /path/to/app/cron.php >> /path/to/app/data/cron.log 2>&1
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

// Acquire the global scheduler lock (non-blocking — skip if another instance
// is running). Uses the shared re-entrant helper so nested Scheduler calls
// (e.g. replanToday) don't deadlock against our own lock.
if (!Scheduler::acquireLock(0)) {
    echo "[" . date('c') . "] Another instance is already running. Exiting.\n";
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

    // Step 5a: Snapshot the database BEFORE tonight's rollup + purge mutate
    // it. game_daily_stats is irreplaceable reporting history, and update.sh
    // only backs up on updates — this is the disaster-recovery net for every
    // other night. Failure is a warning, never a blocker.
    echo "Backing up database...\n";
    try {
        $bk = Scheduler::backupDatabase();
        echo "  Snapshot: {$bk['path']} (" . round($bk['bytes'] / 1048576, 1) . " MB, "
            . "{$bk['kept']} kept, {$bk['pruned']} pruned)\n";
    } catch (Exception $e) {
        echo "  WARNING: Database backup failed: " . $e->getMessage() . "\n";
    }

    // Step 5: Roll up the raw play feed into the permanent per-game daily
    // summary BEFORE purging. This is what makes month/year reporting possible
    // — the raw feed is only kept for a short window, but game_daily_stats is
    // retained indefinitely.
    echo "Rolling up daily game stats...\n";
    $rollupOk = false;
    try {
        $rollup = Scheduler::rollupDailyStats();
        $rollupOk = true;
        echo "  Rolled up {$rollup['days_recomputed']} days, {$rollup['rows_written']} game-day rows, "
            . "{$rollup['hourly_rows_written']} game-hour rows.\n";
    } catch (Exception $e) {
        echo "  WARNING: Daily rollup failed: " . $e->getMessage() . "\n";
    }

    // Step 5b: Refresh the durable per-card activity ledger (first/last seen)
    // that powers Guest Insights' new-vs-returning split. Also runs before the
    // purge, but its monotonic upsert is independent of the game rollup, so a
    // rollup failure doesn't block it.
    echo "Refreshing card activity ledger...\n";
    try {
        $cardRollup = Scheduler::rollupCardActivity();
        echo "  Recorded {$cardRollup['cards_seen']} cards.\n";
    } catch (Exception $e) {
        echo "  WARNING: Card activity ledger refresh failed: " . $e->getMessage() . "\n";
    }

    // Step 5c: Refresh the trailing window of the venue-wide daily rollup
    // (venue_daily_stats) from the POS ledger — the deep-history source for the
    // Analytics overview. MSSQL-only (self-skips if not configured); one bounded
    // query, so it's cheap. The one-time DEEP backfill runs later via
    // runPendingBackfills; this keeps recent complete days accurate.
    echo "Refreshing venue daily rollup...\n";
    try {
        $venueRefresh = Scheduler::refreshVenueDailyStatsRecent(40);
        if (!empty($venueRefresh['skipped'])) {
            echo "  Skipped: {$venueRefresh['reason']}.\n";
        } else {
            echo "  Refreshed {$venueRefresh['days']} day-rows from {$venueRefresh['from']}"
                . (!empty($venueRefresh['clamped'])
                    ? " (gap clamped at " . Scheduler::VENUE_DAILY_CATCHUP_MAX_DAYS . " days —"
                        . " bump VENUE_DAILY_BACKFILL_VERSION to rebuild the rest)"
                    : '') . ".\n";
        }
    } catch (Exception $e) {
        echo "  WARNING: Venue daily rollup refresh failed: " . $e->getMessage() . "\n";
    }

    // Step 6: Purge old data to prevent unbounded growth. Skip purging the raw
    // play feed if the rollup failed — purging would delete raw rows that never
    // made it into the permanent summary, silently losing reporting history.
    // The other retention sweeps (logs, executed actions, expired overrides)
    // are independent of the rollup and still run.
    if (!$rollupOk) {
        echo "Purging old data (raw play feed retained — rollup failed)...\n";
    } else {
        echo "Purging old data...\n";
    }
    try {
        // A very large retention effectively disables the raw-feed purge for
        // this run without affecting the other sweeps.
        $purged = $rollupOk ? Scheduler::purgeOldData() : Scheduler::purgeOldData(90, 30, 90, 100000);
        echo "  Purged: {$purged['action_log_purged']} log entries, "
            . "{$purged['scheduled_actions_purged']} old actions, "
            . "{$purged['overrides_purged']} expired overrides, "
            . "{$purged['game_plays_purged']} old game plays.\n";
    } catch (Exception $e) {
        echo "  WARNING: Data purge failed: " . $e->getMessage() . "\n";
    }

    // Step 7: Rotate log files (keep last ~256KB when file exceeds 512KB)
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

    // Step 8: Write heartbeat for external monitoring
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
    Scheduler::releaseLock();
}

// One-time MSSQL historical backfills (guest ledger + per-game play history).
// Runs AFTER the scheduler lock is released — they only widen analytics tables
// and race nothing — and only on a night the main plan above succeeded (so the
// game cache is fresh and the rollup cutoff reflects the feed's coverage; the
// catch exits first on failure). Each is flag-guarded and retried until it
// succeeds; the same call backs run_backfills.php / update.sh for an on-demand
// run right after a deploy.
Scheduler::runPendingBackfills(function ($m) { echo "[" . date('c') . "] " . $m . "\n"; });
