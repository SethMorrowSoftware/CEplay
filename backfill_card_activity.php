<?php
/**
 * OPTIONAL manual runner for the historical guest-ledger backfill.
 *
 * You normally do NOT need to run this: the nightly cron (cron.php) performs the
 * backfill automatically, exactly once, the first time it runs with the MSSQL
 * connection configured — see Scheduler::backfillCardActivityFromMssql(). This
 * script is kept only as an escape hatch to force a run on demand, or to re-seed
 * just recent years after a data correction (it shares the same idempotent,
 * monotonic logic, so re-running never narrows a card's first/last-seen range).
 *
 * Usage:  php backfill_card_activity.php [fromYear]      (default 2005)
 * Venue server only — needs the MSSQL connection configured on the Go-Kart Labor
 * page. (The sandbox has no MSSQL driver.)
 */

// CLI-only guard — a heavy maintenance job, never a web request.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/scheduler.php';

$log = function (string $m) { echo '[' . date('c') . '] ' . $m . "\n"; };
$fromYear = (isset($argv[1]) && preg_match('/^\d{4}$/', $argv[1])) ? (int)$argv[1] : 2005;

$log("Backfilling card_activity from PlayerCardTrans (years {$fromYear}.." . date('Y') . ").");
try {
    $bf = Scheduler::backfillCardActivityFromMssql($fromYear, $log);
} catch (Exception $e) {
    fwrite(STDERR, "Backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}
if (!empty($bf['skipped'])) {
    fwrite(STDERR, $bf['reason'] . ". Set the connection up on the Go-Kart Labor page first.\n");
    exit(1);
}

// Mark done so the nightly cron won't repeat the full historical scan.
DB::setConfig('card_activity_backfill_done', '1');
$log("Done. card_activity holds {$bf['cards']} cards; earliest first-seen "
    . ($bf['earliest'] ?? '?') . ". Merged {$bf['merged']} rows.");
