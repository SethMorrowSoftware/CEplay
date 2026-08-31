<?php
/**
 * Run the one-time MSSQL historical backfills NOW, instead of waiting for the
 * nightly cron — guest ledger (card_activity) + per-game play history
 * (game_daily_stats / game_hourly_stats). update.sh calls this after a deploy so
 * the deep history shows up immediately; it's also runnable by hand.
 *
 * Idempotent and flag-guarded exactly like cron.php (same
 * Scheduler::runPendingBackfills()): running it early just means cron finds the
 * work already done. Safe with no MSSQL / no driver — each backfill reports
 * "skipped" (or "failed") and leaves its flag unset so cron retries later.
 *
 * Venue server only. There is no php on the venue host — everything runs in
 * containers — so run it through the wrapper, which also guarantees you are
 * pointed at the INSTALL directory's database rather than the git checkout's:
 *
 *     sudo bash /var/persist/pause-groups/run.sh run_backfills.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/scheduler.php';

$log = function (string $m) { echo '[' . date('c') . '] ' . $m . "\n"; };

$log('Running pending MSSQL historical backfills...');
$res = Scheduler::runPendingBackfills($log);

// Then catch the venue-wide daily rollup up to yesterday. The one-time backfill
// above is flag-guarded, so once it has run it never widens the rollup again —
// only Scheduler::refreshVenueDailyStatsRecent() advances it, and that lives in
// the nightly cron. If cron can't reach MSSQL (a daily unit still pinned to the
// stock image, a driver that failed to build, the DB briefly unreachable), the
// rollup freezes at the day the backfill wrote and the year-over-year card
// reports that day as its newest actuals for as long as it takes anyone to
// notice. A deploy is a cheap place to close that gap, and the refresh reaches
// back to the rollup's newest stored day, so it closes the WHOLE gap rather
// than leaving a hole between it and the trailing window.
$refreshFailed = false;
try {
    $refresh = Scheduler::refreshVenueDailyStatsRecent(40, $log);
    if (!empty($refresh['skipped'])) {
        $log('Venue daily refresh skipped: ' . $refresh['reason'] . '.');
    }
} catch (Exception $e) {
    $refreshFailed = true;
    $log('Venue daily refresh failed (cron will retry): ' . $e->getMessage());
}

// Exit non-zero only if a backfill actively FAILED (so a caller can notice);
// "skipped" (no MSSQL yet) and "already done" are both fine.
$failed = ($res['card']['status'] ?? '') === 'failed'
    || ($res['game']['status'] ?? '') === 'failed'
    || ($res['venue']['status'] ?? '') === 'failed'
    || $refreshFailed;
$log('Backfills: guest=' . ($res['card']['status'] ?? '?')
    . ', per-game=' . ($res['game']['status'] ?? '?')
    . ', venue-daily=' . ($res['venue']['status'] ?? '?') . '.');
exit($failed ? 1 : 0);
