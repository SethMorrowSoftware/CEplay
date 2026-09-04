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
 * Venue server only. There is no php on the venue host — everything runs in
 * containers — so run it through the wrapper, which also guarantees you are
 * pointed at the INSTALL directory's database rather than the git checkout's:
 *
 *     sudo bash /var/persist/pause-groups/run.sh check_rollups.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/reporting.php';

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
    // The newest stored day alone cannot answer this. The rollup writes one row
    // per day WITH ACTIVITY, so a week the venue was shut ends the table at
    // exactly the same place a week of failed cron runs does — and telling
    // somebody to go restart a working systemd unit is its own kind of wrong.
    // Reporting::rollupHealth() weighs the refresh's own watermark and this
    // app's independent play feed; the same verdict the dashboard prints.
    $h = Reporting::rollupHealth('ledger', $yesterday, $v['b'], $tz);
    $behind = $h['data_stale_days'];

    if ($h['state'] === 'stalled' || $h['state'] === 'gap') {
        echo "  venue_daily_stats is STALE — newest day " . $v['b'] . ", about " . $behind . " days behind.\n\n";
        echo "  " . wordwrap($h['summary'], 70, "\n  ") . "\n\n";
        echo "  Analytics Month/Year and the year-over-year card lose everything after\n";
        echo "  that day.\n\n";
        echo "  Check the daily job and its container image:\n";
        echo "      systemctl status pause-groups-daily.service\n";
        echo "      tail -50 data/cron.log        # 'No MSSQL PDO driver' means the wrong image\n";
        echo "  Then catch it up:  php run_backfills.php\n";
    } elseif ($h['state'] === 'quiet') {
        echo "  venue_daily_stats looks HEALTHY — newest day " . $v['b'] . ", " . $behind . " days back,\n";
        echo "  and that is a QUIET VENUE rather than a stuck job.\n\n";
        echo "  " . wordwrap($h['summary'], 70, "\n  ") . "\n\n";
        echo "  Nothing to fix here. Deep history covers " . $v['a'] . " .. " . $v['b'] . ".\n";
    } elseif ($h['state'] === 'unknown' && $h['summary'] !== '') {
        echo "  venue_daily_stats is " . $behind . " days back — newest day " . $v['b'] . " — and the\n";
        echo "  evidence does not yet say why.\n\n";
        echo "  " . wordwrap($h['summary'], 70, "\n  ") . "\n\n";
        echo "  Worth a look either way:\n";
        echo "      systemctl status pause-groups-daily.service\n";
        echo "      tail -50 data/cron.log        # 'No MSSQL PDO driver' means the wrong image\n";
        echo "  Catching it up is harmless if it was fine:  php run_backfills.php\n";
    } else {
        echo "  venue_daily_stats looks HEALTHY — newest day " . $v['b']
             . ($behind ? " (" . $behind . " day behind, which is normal)" : " (current)") . ".\n\n";
        echo "  Deep history covers " . $v['a'] . " .. " . $v['b'] . ", so Analytics Month/Year\n";
        echo "  should report real figures. If a range still looks wrong, the cause is\n";
        echo "  something else — say what the page shows and we'll chase it.\n";
    }

    // The evidence, always — this is the command someone runs when a page looks
    // wrong, so it should show its working rather than just its conclusion.
    echo "\n  Evidence:\n";
    echo "    nightly refresh last ran   " . ($h['refresh_at'] ?? 'never recorded')
         . ($h['refresh_through'] ? " (covered through " . $h['refresh_through'] . ")" : '') . "\n";
    echo "    newest day with activity   " . $v['b'] . "  (expected " . $yesterday . ")\n";
    if ($h['gap_from'] !== null && $h['gap_from'] <= $h['gap_to']) {
        echo "    days covered but empty     " . $h['gap_from'] . " .. " . $h['gap_to'] . "\n";
        echo "    plays in this app's feed   "
             . ($h['feed_plays'] === null
                 ? 'not checked'
                 : number_format($h['feed_plays'])
                   . ($h['feed_covers_gap'] ? '' : ' (feed does not reach back that far)'))
             . "\n";
    }
}
echo "\n";
