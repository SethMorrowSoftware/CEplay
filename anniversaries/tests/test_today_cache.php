<?php
/**
 * Unit tests for the Command Center chip's cache (api/anniversaries.php).
 *
 * The dashboard polls every 30 seconds and `GET /api/anniversaries/today` sits
 * on top of a 5000-row MSSQL query, so the memoisation in front of it is the
 * only thing standing between the busiest page in the app and a roster read per
 * poll. These tests pin the three properties that make it safe:
 *
 *   - a good answer is reused for its TTL, and a FAILURE is cached too (so an
 *     unreachable database is not retried by every open dashboard);
 *   - a change to any setting that decides who counts invalidates it outright,
 *     rather than leaving a wrong chip up for the rest of the TTL;
 *   - so does the day rolling over.
 *
 * Like test_anniv_lib.php this needs no database and no network: config.php is
 * constants only, and the functions under test touch nothing but a temp file
 * and the clock.
 *
 *   php anniversaries/tests/test_today_cache.php
 */

$root = dirname(dirname(__DIR__));
require_once $root . '/config.php';
require_once $root . '/api/anniversaries.php';

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

$tmp = sys_get_temp_dir() . '/anniv_today_' . getmypid() . '.json';
@unlink($tmp);

$today = date('Y-m-d');
$goodPayload = [
    'date' => $today, 'available' => true, 'reason' => '',
    'people' => [['name' => 'Alex Rivera', 'years' => 7, 'milestone' => false]],
    'count' => 1, 'mode' => 'all',
];
$failPayload = ['date' => $today, 'available' => false, 'reason' => 'no driver',
                'people' => [], 'count' => 0, 'mode' => 'all'];

/** Rewrite the stored timestamp, to age a cache entry without waiting. */
function age_cache(string $path, int $seconds): void
{
    $d = json_decode((string)file_get_contents($path), true);
    $d['at'] = time() - $seconds;
    file_put_contents($path, json_encode($d));
}

// ---------------------------------------------------------------------------
section('write / read round trip');

is_eq('a missing file is a miss', annivTodayCacheRead($tmp, $today, 'sig1'), null);

annivTodayCacheWrite($tmp, 'sig1', $goodPayload);
ok('the file was written', is_file($tmp));
is_eq('...and is owner-only (it names employees)', fileperms($tmp) & 0777, 0600);
is_eq('a fresh entry is a hit', annivTodayCacheRead($tmp, $today, 'sig1'), $goodPayload);

file_put_contents($tmp, 'not json at all');
is_eq('a corrupt file is a miss, not a crash', annivTodayCacheRead($tmp, $today, 'sig1'), null);
file_put_contents($tmp, json_encode(['at' => time(), 'sig' => 'sig1']));
is_eq('a file with no payload is a miss', annivTodayCacheRead($tmp, $today, 'sig1'), null);

// ---------------------------------------------------------------------------
section('invalidation');

annivTodayCacheWrite($tmp, 'sig1', $goodPayload);
is_eq('a different signature is a miss', annivTodayCacheRead($tmp, $today, 'sig2'), null);
is_eq('a different day is a miss', annivTodayCacheRead($tmp, '2027-01-01', 'sig1'), null);
// Both of those are about a DIFFERENT question, so no TTL can rescue them —
// they must miss however fresh the entry is.
age_cache($tmp, 0);
is_eq('...even when the entry was written this second',
    annivTodayCacheRead($tmp, $today, 'sig2'), null);

// ---------------------------------------------------------------------------
section('TTLs');

annivTodayCacheWrite($tmp, 'sig1', $goodPayload);
age_cache($tmp, ANNIV_TODAY_TTL_OK - 60);
ok('a good answer stands inside its TTL', annivTodayCacheRead($tmp, $today, 'sig1') !== null);
age_cache($tmp, ANNIV_TODAY_TTL_OK + 60);
is_eq('...and expires after it', annivTodayCacheRead($tmp, $today, 'sig1'), null);

// A cached FAILURE is the point of the whole design: without it an unreachable
// database is retried by every dashboard on every poll, each waiting out the
// connect timeout. It expires sooner than a good answer so a fixed connection
// comes back quickly.
ok('a failure is cached for less time than a success',
    ANNIV_TODAY_TTL_FAIL < ANNIV_TODAY_TTL_OK);
annivTodayCacheWrite($tmp, 'sig1', $failPayload);
age_cache($tmp, ANNIV_TODAY_TTL_FAIL - 60);
ok('a failure stands inside its shorter TTL', annivTodayCacheRead($tmp, $today, 'sig1') !== null);
age_cache($tmp, ANNIV_TODAY_TTL_FAIL + 60);
is_eq('...and expires after it', annivTodayCacheRead($tmp, $today, 'sig1'), null);
// The good-answer TTL must not be applied to a failure — that would keep a
// database outage on screen (as "nothing to show") for half an hour.
annivTodayCacheWrite($tmp, 'sig1', $failPayload);
age_cache($tmp, ANNIV_TODAY_TTL_OK - 60);
is_eq('a failure does NOT get the longer success TTL',
    annivTodayCacheRead($tmp, $today, 'sig1'), null);

// A clock that jumped backwards would otherwise give a negative age, which is
// "younger than the TTL" and would pin a stale entry indefinitely.
annivTodayCacheWrite($tmp, 'sig1', $goodPayload);
age_cache($tmp, -3600);
is_eq('a future timestamp is a miss, not an immortal entry',
    annivTodayCacheRead($tmp, $today, 'sig1'), null);

@unlink($tmp);

// ---------------------------------------------------------------------------
section('annivTodaySignature');

$base = [
    'roster_sql' => 'SELECT 1', 'leap_day_mode' => 'feb28', 'celebrate_years' => 'all',
    'min_years' => 1, 'milestone_years' => null, 'name_style' => 'full',
    'exclude_emp_nos' => [], 'exclude_names' => [], 'ignore_hire_dates' => [],
    'timezone' => 'America/New_York',
];
$sig = annivTodaySignature($base);
is_eq('stable for identical settings', annivTodaySignature($base), $sig);

// Every setting that changes WHO shows up has to change the signature, or the
// chip keeps showing the old answer until the TTL runs out.
$changes = [
    'roster_sql'        => 'SELECT 2',
    'leap_day_mode'     => 'mar1',
    'celebrate_years'   => 'milestones',
    'min_years'         => 5,
    'milestone_years'   => ['5', '10'],
    'name_style'        => 'first',
    'exclude_emp_nos'   => ['101'],
    'exclude_names'     => ['Alex Rivera'],
    'ignore_hire_dates' => ['2015-01-01'],
    'timezone'          => 'UTC',
];
foreach ($changes as $key => $value) {
    $changed = $base;
    $changed[$key] = $value;
    ok("changing {$key} changes the signature", annivTodaySignature($changed) !== $sig);
}

// A setting that cannot change who shows up must NOT bust the cache — a saved
// Slack channel should not cost a roster read.
$irrelevant = array_merge($base, ['slack_channel' => '#somewhere', 'gifs_enabled' => false,
                                  'greetings' => ['hello {names}'], 'enabled' => false]);
is_eq('a wording or Slack change leaves it alone', annivTodaySignature($irrelevant), $sig);

// milestone_years is normalised before hashing, so two spellings of the same
// list are one cache entry rather than two.
is_eq('the milestone list is normalised, not hashed raw',
    annivTodaySignature(array_merge($base, ['milestone_years' => ['10', '5', '5']])),
    annivTodaySignature(array_merge($base, ['milestone_years' => [5, 10]])));

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 50) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
