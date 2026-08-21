<?php
/**
 * Unit tests for birthdays/lib/birthday_lib.php.
 *
 * No database, no network — this runs anywhere PHP does, which is the whole
 * point of keeping the date/message logic separate from the MSSQL read.
 *
 *   php birthdays/tests/test_birthday_lib.php
 */

require_once dirname(__DIR__) . '/lib/birthday_lib.php';

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
    if ($actual === $expected) {
        $pass++;
        echo "  ok   {$what}\n";
    } else {
        $fail++;
        echo "  FAIL {$what}\n";
        echo "         expected: " . var_export($expected, true) . "\n";
        echo "         actual:   " . var_export($actual, true) . "\n";
    }
}

function section(string $s): void { echo "\n{$s}\n"; }

// ---------------------------------------------------------------------------
section('bdayParseBirthDate');

is_eq('ISO date',            bdayParseBirthDate('1985-03-03'), '1985-03-03');
is_eq('ISO with time',       bdayParseBirthDate('1985-03-03 00:00:00'), '1985-03-03');
is_eq('ISO with millis',     bdayParseBirthDate('1985-03-03 00:00:00.000'), '1985-03-03');
is_eq('single-digit parts',  bdayParseBirthDate('1985-3-3'), '1985-03-03');
is_eq('US slashes',          bdayParseBirthDate('3/3/1985'), '1985-03-03');
is_eq('US slashes padded',   bdayParseBirthDate('12/25/1990'), '1990-12-25');
is_eq('compact YYYYMMDD',    bdayParseBirthDate('19850303'), '1985-03-03');
is_eq('dblib textual form',  bdayParseBirthDate('Mar  3 1985 12:00:00:000AM'), '1985-03-03');
is_eq('DateTime object',     bdayParseBirthDate(new DateTime('1985-03-03 09:12:00')), '1985-03-03');
is_eq('null',                bdayParseBirthDate(null), null);
is_eq('empty string',        bdayParseBirthDate(''), null);
is_eq('junk',                bdayParseBirthDate('not a date'), null);
is_eq('impossible date',     bdayParseBirthDate('1985-02-30'), null);
is_eq('leap day is real',    bdayParseBirthDate('1996-02-29'), '1996-02-29');
is_eq('non-leap 29 Feb',     bdayParseBirthDate('1995-02-29'), null);

// ---------------------------------------------------------------------------
section('bdayIsUsableBirthDate');

$today = '2026-08-21';
ok('a real birth date',        bdayIsUsableBirthDate('1985-03-03', [], $today));
ok('1900-01-01 rejected',     !bdayIsUsableBirthDate('1900-01-01', [], $today));
ok('any 1900 date rejected',  !bdayIsUsableBirthDate('1900-06-15', [], $today));
ok('1899-12-30 rejected',     !bdayIsUsableBirthDate('1899-12-30', [], $today));
ok('1970-01-01 rejected',     !bdayIsUsableBirthDate('1970-01-01', [], $today));
ok('a 1970 birthday is fine',  bdayIsUsableBirthDate('1970-06-15', [], $today));
ok('future date rejected',    !bdayIsUsableBirthDate('2027-01-01', [], $today));
ok('today is allowed',         bdayIsUsableBirthDate('2026-08-21', [], $today));
ok('null rejected',           !bdayIsUsableBirthDate(null, [], $today));
ok('extra sentinel honoured', !bdayIsUsableBirthDate('2001-01-01', ['2001-01-01'], $today));

// ---------------------------------------------------------------------------
section('bdayNormalizeRoster');

$rows = [
    // Canonical aliases.
    ['emp_no' => '101', 'first_name' => 'Alex',  'last_name' => 'Rivera', 'birth_date' => '1990-08-21'],
    // Raw MSSQL column names, mixed case.
    ['EmpNo' => '102', 'FirstName' => 'Sam', 'LastName' => 'Torres', 'BirthDate' => '1988-08-21 00:00:00'],
    // A single "Last, First" name column.
    ['EmpNo' => '103', 'EmployeeName' => 'Nguyen, Kim', 'DOB' => '7/4/1995'],
    // A single "First Last" name column.
    ['EmpNo' => '104', 'FullName' => 'Jordan Blake', 'birthday' => '1992-02-29'],
    // Placeholder birthday — dropped.
    ['EmpNo' => '105', 'FirstName' => 'Pat', 'LastName' => 'Doe', 'BirthDate' => '1900-01-01'],
    // No birthday at all — dropped.
    ['EmpNo' => '106', 'FirstName' => 'Chris', 'LastName' => 'Lee', 'BirthDate' => null],
    // Unreadable birthday — dropped.
    ['EmpNo' => '107', 'FirstName' => 'Robin', 'LastName' => 'Fox', 'BirthDate' => 'sometime'],
    // No name — dropped.
    ['EmpNo' => '108', 'FirstName' => '', 'LastName' => '', 'BirthDate' => '1991-05-05'],
    // Duplicate employee number (two job codes) — collapsed.
    ['EmpNo' => '101', 'FirstName' => 'Alex', 'LastName' => 'Rivera', 'BirthDate' => '1990-08-21'],
];

$n = bdayNormalizeRoster($rows, ['today' => '2026-08-21']);
is_eq('people kept',            count($n['people']), 4);
is_eq('no_birth_date skipped',  $n['skipped']['no_birth_date'], 1);
is_eq('unparsed skipped',       $n['skipped']['unparsed'], 1);
is_eq('sentinel skipped',       $n['skipped']['sentinel'], 1);
is_eq('no_name skipped',        $n['skipped']['no_name'], 1);
is_eq('duplicate skipped',      $n['skipped']['duplicate'], 1);
is_eq('sentinel reported',      $n['sentinel_hits']['1900-01-01'] ?? 0, 1);

$byNo = [];
foreach ($n['people'] as $p) { $byNo[$p['emp_no']] = $p; }
is_eq('"Last, First" split -> first', $byNo['103']['first'], 'Kim');
is_eq('"Last, First" split -> last',  $byNo['103']['last'], 'Nguyen');
is_eq('"First Last" split -> first',  $byNo['104']['first'], 'Jordan');
is_eq('"First Last" split -> last',   $byNo['104']['last'], 'Blake');
is_eq('DOB alias parsed',             $byNo['103']['birth_date'], '1995-07-04');
is_eq('month extracted',              $byNo['101']['month'], 8);
is_eq('day extracted',                $byNo['101']['day'], 21);

$ex = bdayNormalizeRoster($rows, ['today' => '2026-08-21', 'exclude_emp_nos' => ['101']]);
is_eq('exclude by emp_no', count($ex['people']), 3);
$ex2 = bdayNormalizeRoster($rows, ['today' => '2026-08-21', 'exclude_names' => ['sam torres']]);
is_eq('exclude by name (normalised)', count($ex2['people']), 3);

// ---------------------------------------------------------------------------
section('leap-year handling');

ok('2024 is a leap year',      bdayIsLeapYear(2024));
ok('2026 is not',             !bdayIsLeapYear(2026));
ok('1900 is not (century)',   !bdayIsLeapYear(1900));
ok('2000 is (400-year rule)',  bdayIsLeapYear(2000));

$leapPerson = ['emp_no' => '1', 'first' => 'Feb', 'last' => 'Twentynine', 'name' => 'Feb Twentynine',
               'birth_date' => '1992-02-29', 'month' => 2, 'day' => 29, 'email' => '', 'slack_id' => ''];
$normalPerson = ['emp_no' => '2', 'first' => 'Reg', 'last' => 'Ular', 'name' => 'Reg Ular',
                 'birth_date' => '1990-02-28', 'month' => 2, 'day' => 28, 'email' => '', 'slack_id' => ''];
$roster = [$leapPerson, $normalPerson];

is_eq('leap year: 29 Feb fires on the 29th',
    count(bdayCelebrants($roster, '2024-02-29', 'feb28')), 1);
is_eq('leap year: 28 Feb does NOT also catch the 29th',
    count(bdayCelebrants($roster, '2024-02-28', 'feb28')), 1);
is_eq('leap year: the 28th match is the 28-Feb person',
    bdayCelebrants($roster, '2024-02-28', 'feb28')[0]['emp_no'], '2');
is_eq('non-leap year: 28 Feb catches both',
    count(bdayCelebrants($roster, '2026-02-28', 'feb28')), 2);
is_eq('non-leap year, mar1 mode: 1 Mar catches the leap person',
    count(bdayCelebrants($roster, '2026-03-01', 'mar1')), 1);
is_eq('non-leap year, mar1 mode: 28 Feb catches only the 28th person',
    count(bdayCelebrants($roster, '2026-02-28', 'mar1')), 1);
is_eq('skip mode: leap person never fires in a non-leap year',
    count(bdayCelebrants($roster, '2026-02-28', 'skip')), 1);
is_eq('ordinary day matches nobody',
    count(bdayCelebrants($roster, '2026-06-15', 'feb28')), 0);

// ---------------------------------------------------------------------------
section('bdayCelebrants ordering and bdayUpcoming');

$people = $n['people'];
$c = bdayCelebrants($people, '2026-08-21', 'feb28');
is_eq('two share 21 August', count($c), 2);
is_eq('sorted by name', $c[0]['name'] . '|' . $c[1]['name'], 'Alex Rivera|Sam Torres');

$up = bdayUpcoming($people, '2026-07-01', 60, 'feb28');
ok('4 July found in the window', isset($up['2026-07-04']));
ok('21 August found in the window', isset($up['2026-08-21']));
is_eq('two people on 21 August', count($up['2026-08-21']), 2);

// ---------------------------------------------------------------------------
section('name and message formatting');

$p = ['emp_no' => '1', 'first' => 'Alex', 'last' => 'Rivera', 'name' => 'Alex Rivera',
      'birth_date' => '1990-08-21', 'month' => 8, 'day' => 21, 'email' => '', 'slack_id' => ''];

is_eq('full name',          bdayDisplayName($p, 'full'), 'Alex Rivera');
is_eq('first only',         bdayDisplayName($p, 'first'), 'Alex');
is_eq('first + initial',    bdayDisplayName($p, 'first_initial'), 'Alex R.');

is_eq('join one',    bdayJoinNames(['A']), 'A');
is_eq('join two',    bdayJoinNames(['A', 'B']), 'A and B');
is_eq('join three',  bdayJoinNames(['A', 'B', 'C']), 'A, B and C');
is_eq('join four',   bdayJoinNames(['A', 'B', 'C', 'D']), 'A, B, C and D');

is_eq('slack escaping', bdaySlackEscape('Ben & <Jerry>'), 'Ben &amp; &lt;Jerry&gt;');

$single = bdayBuildText([$p], ['venue_label' => 'The Castle Fun Center']);
ok('single message names the person', strpos($single, '*Alex Rivera*') !== false);
ok('single message uses the venue',   strpos($single, 'The Castle Fun Center') !== false);
ok('no birth year leaks into the message', strpos($single, '1990') === false);

$p2 = $p; $p2['first'] = 'Sam'; $p2['last'] = 'Torres'; $p2['name'] = 'Sam Torres'; $p2['emp_no'] = '2';
$p3 = $p; $p3['first'] = 'Kim'; $p3['last'] = 'Nguyen'; $p3['name'] = 'Kim Nguyen'; $p3['emp_no'] = '3';
$multi = bdayBuildText([$p, $p2, $p3], ['venue_label' => 'X']);
ok('multi message lists all three',
    strpos($multi, '*Alex Rivera*, *Sam Torres* and *Kim Nguyen*') !== false);
is_eq('{count} substituted', substr_count($multi, '3'), 1);

$mentioned = $p; $mentioned['slack_id'] = 'U0123ABC';
is_eq('mention replaces the name',
    bdayMentionOrName($mentioned, 'full'), '<@U0123ABC>');
is_eq('no mention -> bold name',
    bdayMentionOrName($p, 'full'), '*Alex Rivera*');
is_eq('bold can be turned off',
    bdayMentionOrName($p, 'full', false), 'Alex Rivera');

$withPing = bdayBuildText([$p], ['mention' => '<!here>', 'venue_label' => 'X']);
ok('mention prefix is prepended', strpos($withPing, '<!here> ') === 0);

$custom = bdayBuildText([$p], ['message_single' => 'HB {names} at {venue} ({count})', 'venue_label' => 'Castle']);
is_eq('custom template', $custom, 'HB *Alex Rivera* at Castle (1)');

is_eq('empty celebrant list -> empty text', bdayBuildText([], []), '');

// ---------------------------------------------------------------------------
section('state file');

$statePath = sys_get_temp_dir() . '/bday_test_' . getmypid() . '.json';
@unlink($statePath);

$state = bdayStateLoad($statePath);
is_eq('missing file loads clean', $state, ['posted' => []]);

ok('nobody wished yet', !bdayStateHas($state, '2026-08-21', $p));
$state = bdayStateMark($state, '2026-08-21', [$p, $p2]);
ok('Alex now recorded',  bdayStateHas($state, '2026-08-21', $p));
ok('Sam now recorded',   bdayStateHas($state, '2026-08-21', $p2));
ok('Kim still not',     !bdayStateHas($state, '2026-08-21', $p3));
ok('different date is separate', !bdayStateHas($state, '2026-08-22', $p));

$state = bdayStateMark($state, '2026-08-21', [$p]);
is_eq('marking twice does not duplicate', count($state['posted']['2026-08-21']), 2);

ok('state saves', bdayStateSave($statePath, $state));
$reloaded = bdayStateLoad($statePath);
ok('state round-trips', bdayStateHas($reloaded, '2026-08-21', $p));
is_eq('state file is owner-only', substr(sprintf('%o', fileperms($statePath)), -3), '600');

$reloaded['posted']['2026-01-01'] = ['e:99'];
$pruned = bdayStatePrune($reloaded, '2026-08-21', 45);
ok('old entry pruned',    !isset($pruned['posted']['2026-01-01']));
ok('recent entry kept',    isset($pruned['posted']['2026-08-21']));

file_put_contents($statePath, 'this is not json');
is_eq('corrupt state file loads clean', bdayStateLoad($statePath), ['posted' => []]);
@unlink($statePath);

// A person with no employee number still gets a stable key.
$anon = ['emp_no' => '', 'first' => 'No', 'last' => 'Number', 'name' => 'No Number',
         'month' => 1, 'day' => 1, 'birth_date' => '1990-01-01', 'email' => '', 'slack_id' => ''];
$s2 = bdayStateMark(['posted' => []], '2026-01-01', [$anon]);
ok('name-keyed person is remembered', bdayStateHas($s2, '2026-01-01', $anon));

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 50) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
