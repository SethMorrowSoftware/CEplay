<?php
/**
 * Unit tests for Reporting::classifyRollup() — the verdict behind the
 * year-over-year card's staleness banner and the Analytics deep-history note.
 *
 * WHY THIS EXISTS. Both permanent rollups store one row per day that HAS
 * ACTIVITY, so a day the venue was closed leaves exactly the same trace as a
 * night the refresh never ran: no row. Reading staleness off MAX(stat_date)
 * therefore cannot tell those apart at the newest end of the table — and the
 * card used to assert one of them anyway ("the nightly refresh has not advanced
 * them"), which sends somebody to debug a systemd unit that is working. The
 * opposite mistake is worse: this venue really did have a six-week freeze that
 * nothing shouted about.
 *
 * The classifier is pure — facts in, verdict out — precisely so these boundary
 * cases can be pinned here instead of only ever being exercised on a venue at
 * 4am. The gathering half (Reporting::rollupHealth) reads the watermark and the
 * raw feed; everything that decides anything lives below.
 *
 * Needs no database and no network: config.php is constants only, lib/db.php
 * connects lazily, and classifyRollup touches neither.
 *
 *   php tests/test_rollup_health.php
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/db.php';
require_once $root . '/lib/reporting.php';

$pass = 0;
$fail = 0;

function ok(string $what, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$what}\n"; }
    else       { $fail++; echo "  FAIL {$what}\n"; }
}

function is_eq(string $what, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  ok   {$what}\n"; }
    else {
        $fail++;
        echo "  FAIL {$what}\n";
        echo "         expected: " . var_export($expected, true) . "\n";
        echo "         actual:   " . var_export($actual, true) . "\n";
    }
}

function section(string $s): void { echo "\n{$s}\n"; }

/**
 * A healthy-shaped fact set, overridable per case. Modelled on the venue: the
 * nightly job fires at 00:05 UTC = 20:05 the previous day Eastern, so on Sep 4
 * the last run covered through Sep 2 while "yesterday" is Sep 3 — one day
 * behind is the steady state here, not a fault.
 */
function facts(array $over = []): array
{
    return $over + [
        'rollup'           => 'ledger',
        'expected_through' => '2026-09-03',
        'data_through'     => '2026-09-02',
        'refresh_at'       => '2026-09-04T00:05:11Z',
        'refresh_through'  => '2026-09-02',
        'gap_from'         => null,
        'gap_to'           => '2026-09-02',
        'feed_plays'       => null,
        'feed_covers_gap'  => false,
        'feed_live'        => true,
    ];
}

// ---------------------------------------------------------------------------
section('The healthy steady state stays silent');

$v = Reporting::classifyRollup(facts());
is_eq('a rollup one day behind is ok', $v['state'], 'ok');
ok('and warns about nothing', $v['warn'] === false);
is_eq('one day behind is reported as exactly that', $v['data_stale_days'], 1);

// The tolerance boundary, both sides. Three days behind must stay quiet: the
// year-over-year card and the deep-history banner both document a 3-day
// tolerance, and a card that cries wolf every few days stops being read.
$v = Reporting::classifyRollup(facts([
    'data_through' => '2026-08-31', 'refresh_through' => '2026-08-31', 'gap_to' => '2026-08-31',
]));
is_eq('exactly at tolerance (3 days) is still ok', $v['state'], 'ok');
ok('and still silent', $v['warn'] === false);

// ---------------------------------------------------------------------------
section('A stopped job is named as a stopped job');

$v = Reporting::classifyRollup(facts([
    'data_through'    => '2026-07-16',
    'refresh_through' => '2026-07-16',   // the watermark froze with the table
    'refresh_at'      => '2026-07-17T00:05:04Z',
    'gap_from'        => '2026-07-17',
    'gap_to'          => '2026-07-16',
]));
is_eq('a refresh that stopped advancing reads as stalled', $v['state'], 'stalled');
ok('and warns', $v['warn'] === true);
ok('naming the day it last covered', strpos($v['summary'], '2026-07-16') !== false);
is_eq('with the refresh gap measured too', $v['refresh_stale_days'], 49);

// ---------------------------------------------------------------------------
section('Closed days are NOT a stopped job');

// The case that produced the false alarm: the refresh ran last night and
// covered through Sep 2, but the venue took Aug 31 - Sep 2 off, so no rows were
// written for them and MAX(stat_date) still reads Aug 30. The app's own play
// feed — polled every minute by the watchdog, independent of this job — is
// empty for those days too, which is what settles it.
$quiet = facts([
    'data_through'    => '2026-08-30',
    'gap_from'        => '2026-08-31',
    'gap_to'          => '2026-09-02',
    'feed_plays'      => 0,
    'feed_covers_gap' => true,
]);
$v = Reporting::classifyRollup($quiet);
is_eq('a covered stretch with no activity anywhere reads as quiet', $v['state'], 'quiet');
ok('and does NOT warn', $v['warn'] === false);
ok('saying so in words rather than blaming the job',
    strpos($v['summary'], 'closure') !== false
    && strpos($v['summary'], 'up to date') !== false);
is_eq('while still reporting the real gap as context', $v['data_stale_days'], 4);

// The same shape, except the venue plainly was open: the feed recorded plays on
// days the ledger has nothing for. That is a real hole in the source and must
// warn — with a different cause than a stalled job, so a different message.
$v = Reporting::classifyRollup(['feed_plays' => 41200] + $quiet);
is_eq('activity in the app feed but none in the rollup is a gap', $v['state'], 'gap');
ok('and warns', $v['warn'] === true);
ok('crediting the days to the source, not the job',
    strpos($v['summary'], 'missing from the source') !== false);
ok('and quoting the plays it counted', strpos($v['summary'], '41,200') !== false);

// The witness must be AWAKE for its silence to mean anything. A watchdog that
// stopped polling is exactly as quiet as a closed venue, and reading that as
// "nothing to fix here" would make this verdict worse than the warning it
// replaced — two dead data paths reported as a calm night off.
$v = Reporting::classifyRollup(['feed_live' => false] + $quiet);
is_eq('an empty feed from a stopped poller is NOT a closure', $v['state'], 'unknown');
ok('and warns', $v['warn'] === true);
ok('saying the feed cannot testify',
    strpos($v['summary'], 'not currently polling') !== false);

// Plays in the gap still decide it outright — a poller that recorded activity
// was plainly running when it mattered, whatever its heartbeat says now.
$v = Reporting::classifyRollup(['feed_live' => false, 'feed_plays' => 41200] + $quiet);
is_eq('but recorded plays still prove the venue was open', $v['state'], 'gap');

// ---------------------------------------------------------------------------
section('Unsettled evidence says so instead of picking a cause');

// Every install is here on the first load after this change ships: the rollup
// predates the watermark, so nothing recorded whether last night's job ran.
$v = Reporting::classifyRollup(facts([
    'data_through'    => '2026-08-30',
    'refresh_at'      => null,
    'refresh_through' => null,
    'gap_from'        => '2026-08-31',
    'gap_to'          => '2026-09-03',
]));
is_eq('no watermark yet is unknown, not stalled', $v['state'], 'unknown');
ok('it still warns — something is unexplained', $v['warn'] === true);
ok('but names no cause', strpos($v['summary'], 'not yet possible to tell') !== false);
ok('and never claims the refresh failed', stripos($v['summary'], 'has stopped') === false);

// The raw feed is a ~30-day rolling window. Past that it cannot testify, and an
// empty count proves nothing — silence there is not evidence of closure.
$v = Reporting::classifyRollup(facts([
    'data_through'    => '2026-06-01',
    'refresh_through' => '2026-09-02',
    'gap_from'        => '2026-06-02',
    'gap_to'          => '2026-09-02',
    'feed_plays'      => 0,
    'feed_covers_gap' => false,
]));
is_eq('a gap the feed cannot reach back over is unknown', $v['state'], 'unknown');
ok('and warns rather than declaring a three-month closure', $v['warn'] === true);

// ---------------------------------------------------------------------------
section('Degenerate inputs');

$v = Reporting::classifyRollup(facts(['data_through' => null]));
is_eq('an empty rollup is unknown', $v['state'], 'unknown');
ok('and does not warn — the callers have their own "no history yet" message',
    $v['warn'] === false);
is_eq('with no summary to print', $v['summary'], '');

$v = Reporting::classifyRollup(facts(['data_through' => '2026-09-30']));
is_eq('a rollup ahead of expectations is ok', $v['state'], 'ok');
is_eq('and reports zero days behind, never a negative', $v['data_stale_days'], 0);

// The app's own play rollup runs through the same classifier and must name
// itself correctly — the two sources fail for different reasons and the reader
// needs to know which one stopped.
$v = Reporting::classifyRollup(facts([
    'rollup'          => 'app',
    'data_through'    => '2026-08-01',
    'refresh_through' => '2026-08-01',
    'gap_from'        => '2026-08-02',
    'gap_to'          => '2026-08-01',
]));
ok('the app rollup calls itself the play rollup',
    strpos($v['summary'], 'play rollup') !== false);
ok('and not the POS ledger', strpos($v['summary'], 'POS ledger') === false);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 50) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
