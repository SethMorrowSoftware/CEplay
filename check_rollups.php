<?php
/**
 * Are the reporting rollups actually advancing?
 *
 * The Analytics / Performance / year-over-year pages read permanent rollups
 * rather than the 30-day raw feed, and those rollups are advanced ONLY by the
 * nightly cron. When that job breaks, nothing shouts: the pages keep rendering,
 * they just quietly report less business than the venue did. That has happened
 * here for real — `pause-groups-daily.service` ran on an image with no MSSQL
 * driver and venue_daily_stats sat frozen for six weeks while every page looked
 * completely normal.
 *
 * This is the one command that answers "is the history healthy?" without
 * needing sqlite3 on the host (it isn't installed on the venue box).
 *
 * Venue server only. Usage: php check_rollups.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';

$tzName = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
try { $tz = new DateTimeZone($tzName); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); $tzName = 'UTC'; }
$today     = (new DateTime('now', $tz))->format('Y-m-d');
$yesterday = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');

echo "Reporting rollup health — " . $today . " (" . $tzName . ")\n";
echo str_repeat('=', 72) . "\n\n";

/** Row count + coverage window for one table, tolerant of a missing table. */
function rollupSpan(string $table, string $dateCol): ?array {
    try {
        $r = DB::queryOne("SELECT COUNT(*) AS n, MIN({$dateCol}) AS a, MAX({$dateCol}) AS b FROM {$table}");
    } catch (Exception $e) {
        return null;
    }
    if (!$r) return null;
    return [
        'n' => (int)$r['n'],
        'a' => $r['a'] ? substr((string)$r['a'], 0, 10) : null,
        'b' => $r['b'] ? substr((string)$r['b'], 0, 10) : null,
    ];
}

/** Whole days between two ISO dates (0 when b >= a). */
function daysBehind(?string $newest, string $expected): ?int {
    if ($newest === null) return null;
    if ($newest >= $expected) return 0;
    $x = DateTime::createFromFormat('!Y-m-d', $newest, new DateTimeZone('UTC'));
    $y = DateTime::createFromFormat('!Y-m-d', $expected, new DateTimeZone('UTC'));
    return ($x && $y) ? (int)$x->diff($y)->days : null;
}

$tables = [
    ['venue_daily_stats',      'stat_date',        'Deep money history (Analytics overview, year-over-year)'],
    ['game_daily_stats',       'stat_date',        'Per-game history (Performance, Reader Groups, Go-Kart swipes)'],
    ['game_hourly_stats',      'stat_date',        'Per-game hour-of-day history (staffing heatmaps)'],
    ['game_play_transactions', 'transaction_time', 'Raw play feed (rolling ~30 days — short by design)'],
];
foreach ($tables as [$t, $c, $what]) {
    $s = rollupSpan($t, $c);
    printf("%-24s %s\n", $t, $what);
    if ($s === null) {
        echo "  (table not readable)\n\n";
        continue;
    }
    printf("  %s rows", number_format($s['n']));
    if ($s['a'] !== null) printf("   covering %s .. %s", $s['a'], $s['b']);
    echo "\n\n";
}

// ---- The verdict that matters: is deep history usable? -------------------
// Everything above is context; this is the check that explains a Month or Year
// view reporting far less business than the venue actually did.
$v = rollupSpan('venue_daily_stats', 'stat_date');
$flag = DB::getConfig('venue_daily_backfill_done');
echo str_repeat('-', 72) . "\n";
echo "VERDICT\n\n";

if ($v === null || $v['n'] === 0) {
    echo "  venue_daily_stats is EMPTY.\n\n";
    echo "  Analytics Month/Year and the year-over-year card have no deep history to\n";
    echo "  read, so any range older than the ~30-day raw feed reports only what the\n";
    echo "  feed still holds — a past month or an earlier year reads as ZERO.\n\n";
    echo "  Fix: run the backfill on the venue (needs the pdo_dblib image):\n";
    echo "      sudo bash update.sh          # rebuilds + runs it, or run it directly:\n";
    echo "      php run_backfills.php\n";
    echo "  The one-time backfill reads ~2 decades from the POS ledger; it takes a\n";
    echo "  few minutes. Backfill flag: " . ($flag === null ? "NOT SET (never completed)" : $flag) . "\n";
} else {
    $behind = daysBehind($v['b'], $yesterday);
    // 1 day behind is NORMAL: the nightly refresh never holds today, and it runs
    // at 00:05 UTC = 20:05 the previous day here. Same 3-day tolerance the
    // year-over-year card and the Analytics deep banner use.
    if ($behind !== null && $behind > 3) {
        echo "  venue_daily_stats is STALE — newest day " . $v['b'] . ", about " . $behind . " days behind.\n\n";
        echo "  The nightly rollup has stopped advancing, so recent weeks are missing from\n";
        echo "  Analytics Month/Year and the year-over-year card, and those totals read low.\n\n";
        echo "  Check the daily job and its container image:\n";
        echo "      systemctl status pause-groups-daily.service\n";
        echo "      tail -50 data/cron.log        # 'No MSSQL PDO driver' means the wrong image\n";
        echo "  Then catch it up:  php run_backfills.php\n";
    } else {
        echo "  venue_daily_stats looks HEALTHY — newest day " . $v['b']
             . ($behind ? " (" . $behind . " day behind, which is normal)" : " (current)") . ".\n\n";
        echo "  Deep history covers " . $v['a'] . " .. " . $v['b'] . ", so Analytics Month/Year\n";
        echo "  should report real figures. If a range still looks wrong, the cause is\n";
        echo "  something else — say what the page shows and we'll chase it.\n";
    }
}
echo "\n";
