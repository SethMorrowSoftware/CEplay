<?php
/**
 * retry_status.php — read-only inspector for the pause/unpause retry queue.
 *
 * When the remote (or web UI) says "the server will keep retrying", that retry
 * is driven by cron_watchdog.php → Scheduler::processRetries(), which re-attempts
 * the rows in `action_retries` once per minute (up to max_attempts, then gives
 * up; gave-up rows self-heal after a cooldown). This script prints that queue
 * plus the watchdog heartbeat so you can confirm retries are actually being
 * processed — e.g. click a pause that reports "N busy", then watch the rows
 * drain here.
 *
 * Usage on the CEplay server:
 *   php retry_status.php                 # one-shot snapshot
 *   watch -n 5 'php retry_status.php'    # live view as the queue drains
 *
 * Read-only: never changes state and never contacts CenterEdge.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("retry_status.php is CLI-only.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

function rs_ageStr(?int $seconds): string {
    if ($seconds === null) return 'never';
    $s = max(0, $seconds);
    if ($s < 60) return $s . 's';
    if ($s < 3600) return floor($s / 60) . 'm ' . ($s % 60) . 's';
    if ($s < 86400) return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
    return floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h';
}

function rs_heartbeatAge(string $file): ?int {
    if (!file_exists($file)) return null;
    $raw = trim((string)@file_get_contents($file));
    $ts = $raw !== '' ? strtotime($raw) : false;
    if ($ts === false) { $ts = @filemtime($file); }
    return $ts === false ? null : (time() - (int)$ts);
}

$dataDir = dirname(DB_PATH);
$wdAge = rs_heartbeatAge($dataDir . '/.heartbeat_watchdog');
$cronAge = rs_heartbeatAge($dataDir . '/.heartbeat_cron');

echo "CEplay — retry queue status  (" . date('Y-m-d H:i:s T') . ")\n";
echo str_repeat('=', 64) . "\n";

// The per-minute watchdog is what drains the retry queue.
$wdHealthy = ($wdAge !== null && $wdAge < 180);
echo sprintf("Watchdog cron : %-8s (last run %s ago)\n", $wdHealthy ? 'OK' : 'STALLED', rs_ageStr($wdAge));
echo sprintf("Daily cron    : %-8s (last run %s ago)\n", ($cronAge !== null && $cronAge < 90000) ? 'OK' : 'STALE', rs_ageStr($cronAge));
if (!$wdHealthy) {
    echo "\n  ! The per-minute watchdog is NOT running, so busy assets will not be\n";
    echo "    retried. Add cron_watchdog.php to crontab (see README / CLAUDE.md):\n";
    echo "      * * * * * /usr/bin/php " . __DIR__ . "/cron_watchdog.php >> " . $dataDir . "/watchdog.log 2>&1\n";
}
echo "\n";

try {
    $rows = DB::query(
        'SELECT asset_type, asset_id, desired_status, source, pause_group_id,
                attempts, max_attempts, last_error, last_attempted_at, created_at, gave_up_at
         FROM action_retries
         ORDER BY (gave_up_at IS NOT NULL), asset_type, id'
    );
} catch (Exception $e) {
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
    exit(1);
}

$pending = 0;
$gaveUp = 0;
foreach ($rows as $r) {
    if (!empty($r['gave_up_at'])) { $gaveUp++; } else { $pending++; }
}

echo sprintf("Retry queue   : %d pending, %d gave up, %d total\n", $pending, $gaveUp, count($rows));
echo str_repeat('-', 64) . "\n";

if (empty($rows)) {
    echo "Queue is empty — nothing waiting to retry.\n";
    exit(0);
}

foreach ($rows as $r) {
    $state = !empty($r['gave_up_at']) ? 'GAVE UP' : 'pending';
    echo sprintf(
        "[%-7s] %-5s %-16s -> %-8s  attempt %d/%d  group=%s\n",
        $state,
        $r['asset_type'],
        substr((string)$r['asset_id'], 0, 16),
        $r['desired_status'],
        (int)$r['attempts'],
        (int)$r['max_attempts'],
        $r['pause_group_id'] === null ? '-' : (string)$r['pause_group_id']
    );
    if (!empty($r['last_error'])) {
        echo "            last error: " . trim((string)$r['last_error']) . "\n";
    }
}

echo "\nTip: run this again in a minute — pending rows should drop as the watchdog\n";
echo "re-attempts them (successes clear the row; failures bump the attempt count).\n";
