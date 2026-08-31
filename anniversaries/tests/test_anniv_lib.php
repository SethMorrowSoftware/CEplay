<?php
/**
 * Unit tests for anniversaries/lib/anniv_lib.php.
 *
 * No database, no network — this runs anywhere PHP does, which is the whole
 * point of keeping the date/message logic separate from the MSSQL read.
 *
 *   php anniversaries/tests/test_anniv_lib.php
 */

require_once dirname(__DIR__) . '/lib/anniv_lib.php';

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

/** A roster person, shaped the way annivNormalizeRoster produces them. */
function person(string $name, string $hire, string $empNo = ''): array
{
    $bits = explode(' ', $name, 2);
    return [
        'emp_no'    => $empNo,
        'first'     => $bits[0],
        'last'      => $bits[1] ?? '',
        'name'      => $name,
        'hire_date' => $hire,
        'year'      => (int)substr($hire, 0, 4),
        'month'     => (int)substr($hire, 5, 2),
        'day'       => (int)substr($hire, 8, 2),
        'email'     => '',
        'slack_id'  => '',
    ];
}

// ---------------------------------------------------------------------------
section('annivParseDate');

is_eq('ISO date',            annivParseDate('2015-03-03'), '2015-03-03');
is_eq('ISO with time',       annivParseDate('2015-03-03 00:00:00'), '2015-03-03');
is_eq('ISO with millis',     annivParseDate('2015-03-03 00:00:00.000'), '2015-03-03');
is_eq('single-digit parts',  annivParseDate('2015-3-3'), '2015-03-03');
is_eq('US slashes',          annivParseDate('3/3/2015'), '2015-03-03');
is_eq('US slashes padded',   annivParseDate('12/25/1990'), '1990-12-25');
is_eq('compact YYYYMMDD',    annivParseDate('20150303'), '2015-03-03');
is_eq('dblib textual form',  annivParseDate('Mar  3 2015 12:00:00:000AM'), '2015-03-03');
is_eq('DateTime object',     annivParseDate(new DateTime('2015-03-03 09:12:00')), '2015-03-03');
is_eq('null',                annivParseDate(null), null);
is_eq('empty string',        annivParseDate(''), null);
is_eq('junk',                annivParseDate('not a date'), null);
is_eq('impossible date',     annivParseDate('2015-02-30'), null);
is_eq('leap day is real',    annivParseDate('2016-02-29'), '2016-02-29');
is_eq('non-leap 29 Feb',     annivParseDate('2015-02-29'), null);

// ---------------------------------------------------------------------------
section('annivIsUsableHireDate');

$today = '2026-08-30';
ok('a real hire date',         annivIsUsableHireDate('2015-03-03', [], $today));
ok('hired today is usable',    annivIsUsableHireDate('2026-08-30', [], $today));
ok('1900-01-01 rejected',     !annivIsUsableHireDate('1900-01-01', [], $today));
ok('any 1900 date rejected',  !annivIsUsableHireDate('1900-06-15', [], $today));
ok('1899-12-30 rejected',     !annivIsUsableHireDate('1899-12-30', [], $today));
ok('1970-01-01 rejected',     !annivIsUsableHireDate('1970-01-01', [], $today));
ok('a 1970 hire is fine',      annivIsUsableHireDate('1970-06-15', [], $today));
ok('future date rejected',    !annivIsUsableHireDate('2027-01-01', [], $today));
ok('null rejected',           !annivIsUsableHireDate(null, [], $today));
ok('extra sentinel honoured', !annivIsUsableHireDate('2001-01-01', ['2001-01-01'], $today));

// ---------------------------------------------------------------------------
section('annivNormalizeRoster');

$rows = [
    // Canonical aliases.
    ['emp_no' => '101', 'first_name' => 'Alex',  'last_name' => 'Rivera', 'hire_date' => '2019-08-30'],
    // Raw MSSQL column names, mixed case.
    ['EmpNo' => '102', 'FirstName' => 'Sam', 'LastName' => 'Torres', 'DateOfHire' => '2021-08-30 00:00:00'],
    // A single "Last, First" name column, and a different hire alias.
    ['EmpNo' => '103', 'EmployeeName' => 'Nguyen, Kim', 'StartDate' => '7/4/2015'],
    // A single "First Last" name column, hired on a leap day.
    ['EmpNo' => '104', 'FullName' => 'Jordan Blake', 'DateHired' => '2016-02-29'],
    // Placeholder hire date — dropped.
    ['EmpNo' => '105', 'FirstName' => 'Pat', 'LastName' => 'Doe', 'DateOfHire' => '1900-01-01'],
    // No hire date at all — dropped.
    ['EmpNo' => '106', 'FirstName' => 'Chris', 'LastName' => 'Lee', 'DateOfHire' => null],
    // Unreadable — dropped.
    ['EmpNo' => '107', 'FirstName' => 'Robin', 'LastName' => 'Fox', 'DateOfHire' => 'sometime'],
    // No name — dropped.
    ['EmpNo' => '108', 'FirstName' => '', 'LastName' => '', 'DateOfHire' => '2020-05-05'],
    // Starts next month — dropped, but counted separately from a broken column.
    ['EmpNo' => '109', 'FirstName' => 'Dana', 'LastName' => 'Ward', 'DateOfHire' => '2026-09-15'],
    // Duplicate employee number (two job codes) — collapsed.
    ['EmpNo' => '101', 'FirstName' => 'Alex', 'LastName' => 'Rivera', 'hire_date' => '2019-08-30'],
];

$n = annivNormalizeRoster($rows, ['today' => '2026-08-30']);
is_eq('people kept',           count($n['people']), 4);
is_eq('no_hire_date skipped',  $n['skipped']['no_hire_date'], 1);
is_eq('unparsed skipped',      $n['skipped']['unparsed'], 1);
is_eq('sentinel skipped',      $n['skipped']['sentinel'], 1);
is_eq('future skipped',        $n['skipped']['future'], 1);
is_eq('no_name skipped',       $n['skipped']['no_name'], 1);
is_eq('duplicate skipped',     $n['skipped']['duplicate'], 1);
is_eq('sentinel reported',     $n['sentinel_hits']['1900-01-01'] ?? 0, 1);

$byNo = [];
foreach ($n['people'] as $p) { $byNo[$p['emp_no']] = $p; }
is_eq('"Last, First" split -> first', $byNo['103']['first'], 'Kim');
is_eq('"Last, First" split -> last',  $byNo['103']['last'], 'Nguyen');
is_eq('"First Last" split -> first',  $byNo['104']['first'], 'Jordan');
is_eq('StartDate alias parsed',       $byNo['103']['hire_date'], '2015-07-04');
is_eq('DateOfHire alias parsed',      $byNo['102']['hire_date'], '2021-08-30');
is_eq('DateHired alias parsed',       $byNo['104']['hire_date'], '2016-02-29');
is_eq('year extracted',               $byNo['101']['year'], 2019);
is_eq('month extracted',              $byNo['101']['month'], 8);
is_eq('day extracted',                $byNo['101']['day'], 30);

// The birthday column must never be mistaken for the hire date — every roster
// table carries both, and a row offering only a birth date has no hire date.
$mixed = annivNormalizeRoster([
    ['EmpNo' => '201', 'FirstName' => 'Lee', 'LastName' => 'Park',
     'DateOfBirth' => '1990-08-30', 'DateOfHire' => '2018-04-02'],
    ['EmpNo' => '202', 'FirstName' => 'Max', 'LastName' => 'Hall', 'DateOfBirth' => '1988-01-01'],
], ['today' => '2026-08-30']);
is_eq('birth date is not read as a hire date', count($mixed['people']), 1);
is_eq('hire date wins over birth date', $mixed['people'][0]['hire_date'], '2018-04-02');
is_eq('a birth-date-only row has no hire date', $mixed['skipped']['no_hire_date'], 1);

$ex = annivNormalizeRoster($rows, ['today' => '2026-08-30', 'exclude_emp_nos' => ['101']]);
is_eq('exclude by emp_no', count($ex['people']), 3);
$ex2 = annivNormalizeRoster($rows, ['today' => '2026-08-30', 'exclude_names' => ['sam torres']]);
is_eq('exclude by name (normalised)', count($ex2['people']), 3);
$ex3 = annivNormalizeRoster($rows, ['today' => '2026-08-30', 'ignore_hire_dates' => ['2019-08-30']]);
is_eq('ignore a specific hire date', count($ex3['people']), 3);

// ---------------------------------------------------------------------------
section('annivYearsOfService / ordinals / labels');

is_eq('one year',        annivYearsOfService('2025-08-30', '2026-08-30'), 1);
is_eq('ten years',       annivYearsOfService('2016-08-30', '2026-08-30'), 10);
is_eq('hired today = 0', annivYearsOfService('2026-08-30', '2026-08-30'), 0);
// A 29 Feb hire marked on 28 Feb still reads the right number.
is_eq('leap hire on the substitute day', annivYearsOfService('2016-02-29', '2026-02-28'), 10);

is_eq('1st',  annivOrdinal(1),  '1st');
is_eq('2nd',  annivOrdinal(2),  '2nd');
is_eq('3rd',  annivOrdinal(3),  '3rd');
is_eq('4th',  annivOrdinal(4),  '4th');
is_eq('11th', annivOrdinal(11), '11th');
is_eq('12th', annivOrdinal(12), '12th');
is_eq('13th', annivOrdinal(13), '13th');
is_eq('21st', annivOrdinal(21), '21st');
is_eq('22nd', annivOrdinal(22), '22nd');
is_eq('23rd', annivOrdinal(23), '23rd');
is_eq('101st', annivOrdinal(101), '101st');
is_eq('111th', annivOrdinal(111), '111th');

is_eq('one year label',  annivYearLabel(1), '1 year');
is_eq('five year label', annivYearLabel(5), '5 years');
is_eq('zero year label', annivYearLabel(0), '0 years');

// ---------------------------------------------------------------------------
section('annivMilestoneYears / annivIsMilestone');

is_eq('null uses the built-in list', annivMilestoneYears(null), ANNIV_DEFAULT_MILESTONES);
is_eq('a string uses the built-in list', annivMilestoneYears('5,10'), ANNIV_DEFAULT_MILESTONES);
is_eq('cleaned, sorted, deduped', annivMilestoneYears(['10', '5', ' 5 ', '0', 'x', '20']), [5, 10, 20]);
// An EMPTY array is a deliberate "no milestones", not "use the defaults" —
// --check and the page both call out the milestone-only case that creates.
is_eq('an empty list stays empty', annivMilestoneYears([]), []);

ok('5 is a milestone by default',  annivIsMilestone(5, annivMilestoneYears(null)));
ok('3 is not a milestone',        !annivIsMilestone(3, annivMilestoneYears(null)));
ok('nothing is a milestone when the list is empty', !annivIsMilestone(5, []));

// ---------------------------------------------------------------------------
section('annivTargetMonthDays (the leap-day rule)');

is_eq('an ordinary day', annivTargetMonthDays('2026-08-30'), [[8, 30]]);
is_eq('28 Feb in a non-leap year also covers 29 Feb',
    annivTargetMonthDays('2026-02-28', 'feb28'), [[2, 28], [2, 29]]);
is_eq('mar1 mode moves it to 1 March',
    annivTargetMonthDays('2026-03-01', 'mar1'), [[3, 1], [2, 29]]);
is_eq('mar1 mode leaves 28 Feb alone',
    annivTargetMonthDays('2026-02-28', 'mar1'), [[2, 28]]);
is_eq('skip mode adds nothing',
    annivTargetMonthDays('2026-02-28', 'skip'), [[2, 28]]);
// In a LEAP year 29 Feb is a real date, so it must not also fire on the 28th.
is_eq('28 Feb in a leap year is just the 28th',
    annivTargetMonthDays('2024-02-28', 'feb28'), [[2, 28]]);
is_eq('29 Feb in a leap year matches directly',
    annivTargetMonthDays('2024-02-29', 'feb28'), [[2, 29]]);

// ---------------------------------------------------------------------------
section('annivCelebrants');

$roster = [
    person('Alex Rivera', '2019-08-30', '101'),   // 7 years
    person('Sam Torres',  '2021-08-30', '102'),   // 5 years — a milestone
    person('Kim Nguyen',  '2026-08-30', '103'),   // hired today — year zero
    person('Jordan Blake', '2016-02-29', '104'),  // leap-day hire
    person('Dana Ward',   '2025-08-30', '105'),   // 1 year — a milestone
    person('Robin Fox',   '2015-01-05', '106'),   // different day entirely
];

$hits = annivCelebrants($roster, '2026-08-30');
is_eq('year zero is excluded', count($hits), 3);
is_eq('longest service leads', $hits[0]['name'], 'Alex Rivera');
is_eq('leader\'s years', $hits[0]['years'], 7);
is_eq('then the five-year', $hits[1]['name'], 'Sam Torres');
is_eq('then the one-year', $hits[2]['years'], 1);
ok('5 years is flagged as a milestone', $hits[1]['milestone'] === true);
ok('7 years is not', $hits[0]['milestone'] === false);

$milestoneOnly = annivCelebrants($roster, '2026-08-30', ['mode' => 'milestones']);
is_eq('milestone mode drops the seven-year', count($milestoneOnly), 2);
is_eq('milestone mode keeps the five-year', $milestoneOnly[0]['years'], 5);

$fromFive = annivCelebrants($roster, '2026-08-30', ['min_years' => 5]);
is_eq('min_years raises the floor', count($fromFive), 2);

$emptyMilestones = annivCelebrants($roster, '2026-08-30',
    ['mode' => 'milestones', 'milestone_years' => []]);
is_eq('milestone mode with no milestones posts nothing', count($emptyMilestones), 0);

$leap = annivCelebrants($roster, '2026-02-28', ['leap_mode' => 'feb28']);
is_eq('the leap-day hire is marked on 28 Feb', count($leap), 1);
is_eq('and reads ten years', $leap[0]['years'], 10);
is_eq('skip mode says nothing about them',
    count(annivCelebrants($roster, '2026-02-28', ['leap_mode' => 'skip'])), 0);
is_eq('a leap year matches the real date',
    count(annivCelebrants($roster, '2024-02-29')), 1);
is_eq('and not the 28th', count(annivCelebrants($roster, '2024-02-28')), 0);

// ---------------------------------------------------------------------------
section('annivUpcoming');

$up = annivUpcoming($roster, '2026-08-29', 3);
is_eq('only the day with anniversaries', array_keys($up), ['2026-08-30']);
is_eq('with everyone on it', count($up['2026-08-30']), 3);
is_eq('a year-long window finds the January hire',
    isset(annivUpcoming($roster, '2026-08-29', 200)['2027-01-05']), true);

// ---------------------------------------------------------------------------
section('annivObservedDate');

is_eq('an ordinary date is itself',      annivObservedDate(2026, 8, 30), '2026-08-30');
is_eq('29 Feb in a leap year is real',   annivObservedDate(2028, 2, 29), '2028-02-29');
is_eq('and is NOT moved to the 28th',    annivObservedDate(2028, 2, 29, 'feb28'), '2028-02-29');
is_eq('29 Feb otherwise falls back',     annivObservedDate(2026, 2, 29, 'feb28'), '2026-02-28');
is_eq('or forward, when asked',          annivObservedDate(2026, 2, 29, 'mar1'), '2026-03-01');
is_eq('or is not observed at all',       annivObservedDate(2026, 2, 29, 'skip'), null);
is_eq('a century year is not a leap year', annivObservedDate(2100, 2, 29, 'feb28'), '2100-02-28');
is_eq('but a 400-year one is',           annivObservedDate(2000, 2, 29, 'feb28'), '2000-02-29');

// ---------------------------------------------------------------------------
section('annivNextAnniversary');

/** date => years, so a whole row of the table reads on one line. */
function nextOn(string $hire, string $from, string $leap = 'feb28'): string
{
    $r = annivNextAnniversary($hire, $from, $leap);
    return $r === null ? 'none' : $r['date'] . '=' . $r['years'];
}

is_eq('today counts as on-or-after',      nextOn('2020-08-31', '2026-08-31'), '2026-08-31=6');
is_eq('yesterday rolls to next year',     nextOn('2020-08-30', '2026-08-31'), '2027-08-30=7');
is_eq('tomorrow is this year',            nextOn('2020-09-01', '2026-08-31'), '2026-09-01=6');
is_eq('hired today is the zeroth',        nextOn('2026-08-31', '2026-08-31'), '2026-08-31=0');
is_eq('31 December, on the day',          nextOn('2015-12-31', '2026-12-31'), '2026-12-31=11');
is_eq('1 January, from the last day',     nextOn('2015-01-01', '2026-12-31'), '2027-01-01=12');
is_eq('1 January, on the day',            nextOn('2015-01-01', '2026-01-01'), '2026-01-01=11');
is_eq('a January hire seen in August',    nextOn('2015-01-05', '2026-08-31'), '2027-01-05=12');
is_eq('leap hire, non-leap year, feb28',  nextOn('2000-02-29', '2026-01-01', 'feb28'), '2026-02-28=26');
is_eq('leap hire, non-leap year, mar1',   nextOn('2000-02-29', '2026-01-01', 'mar1'), '2026-03-01=26');
is_eq('leap hire, skip, waits for 2028',  nextOn('2000-02-29', '2026-01-01', 'skip'), '2028-02-29=28');
is_eq('leap hire, on the substitute day', nextOn('2000-02-29', '2026-02-28', 'feb28'), '2026-02-28=26');
is_eq('and the day after moves on',       nextOn('2000-02-29', '2026-03-01', 'feb28'), '2027-02-28=27');
is_eq('leap hire in a leap year',         nextOn('2000-02-29', '2028-02-01', 'feb28'), '2028-02-29=28');
is_eq('the 28th does not fire in a leap year',
    nextOn('2000-02-29', '2028-02-28', 'feb28'), '2028-02-29=28');
is_eq('mar1 mode in a leap year uses the real date',
    nextOn('2000-02-29', '2028-02-29', 'mar1'), '2028-02-29=28');
is_eq('a hire date in the future still answers', nextOn('2027-03-04', '2026-08-31'), '2027-03-04=0');

// The number this reports on a given day MUST be the number the bot announces
// on that day, or the page and Slack disagree about somebody's tenth year.
foreach ([['2019-08-30', '2026-08-30'], ['2016-02-29', '2026-02-28'],
          ['2016-02-29', '2028-02-29'], ['2021-01-05', '2027-01-05']] as $row) {
    $p = person('Parity Case', $row[0]);
    $hit = annivCelebrants([$p], $row[1], ['min_years' => 0]);
    $next = annivNextAnniversary($row[0], $row[1]);
    is_eq('parity with annivCelebrants on ' . $row[1] . ' for a ' . $row[0] . ' hire',
        [$next['date'], $next['years']], [$row[1], $hit ? $hit[0]['years'] : -1]);
}

// ---------------------------------------------------------------------------
section('annivNextCelebrated');

function celebOn(string $hire, string $from, array $opts = []): string
{
    $r = annivNextCelebrated($hire, $from, $opts);
    return $r === null ? 'never' : $r['date'] . '=' . $r['years'];
}

is_eq('every year: the next one, as the calendar has it',
    celebOn('2020-06-01', '2026-08-31'), '2027-06-01=7');
is_eq('min_years skips the year below the floor',
    celebOn('2026-08-01', '2026-08-31', ['min_years' => 1]), '2027-08-01=1');
is_eq('min_years 5 waits for the fifth',
    celebOn('2024-06-01', '2026-08-31', ['min_years' => 5]), '2029-06-01=5');
is_eq('the day itself still counts',
    celebOn('2020-08-31', '2026-08-31', ['min_years' => 1]), '2026-08-31=6');

$ms = ['mode' => 'milestones', 'milestone_years' => [1, 5, 10, 15]];
is_eq('milestone mode jumps to the next milestone',
    celebOn('2019-06-01', '2026-08-31', $ms), '2029-06-01=10');
is_eq('milestone mode when this year IS one',
    celebOn('2016-06-01', '2026-08-31', $ms), '2031-06-01=15');
is_eq('milestone mode past the end of the list is silence for good',
    celebOn('2005-06-01', '2026-08-31', $ms), 'never');
is_eq('milestone mode with an empty list can never post',
    celebOn('2019-06-01', '2026-08-31', ['mode' => 'milestones', 'milestone_years' => []]), 'never');
is_eq('a first year is a milestone by default',
    celebOn('2026-06-01', '2026-08-31', ['mode' => 'milestones']), '2027-06-01=1');
is_eq('leap hire under skip waits for the leap year',
    celebOn('2000-02-29', '2026-01-01', ['leap_mode' => 'skip']), '2028-02-29=28');
is_eq('leap hire under skip AND milestones takes the next milestone that IS a leap year',
    celebOn('2000-02-29', '2026-01-01',
        ['leap_mode' => 'skip', 'mode' => 'milestones', 'milestone_years' => [26, 27, 28, 30]]),
    '2028-02-29=28');
// Every milestone on the list lands in a non-leap year, and 'skip' means
// exactly that: nothing to say, ever. Silence a page ought to spell out.
is_eq('and says never when no milestone ever lands on one',
    celebOn('2000-02-29', '2026-01-01',
        ['leap_mode' => 'skip', 'mode' => 'milestones', 'milestone_years' => [26, 27, 30]]),
    'never');

// ---------------------------------------------------------------------------
section('annivPrevAnniversary');

function prevOn(string $hire, string $on, string $leap = 'feb28'): string
{
    $r = annivPrevAnniversary($hire, $on, $leap);
    return $r === null ? 'none' : $r['date'] . '=' . $r['years'];
}

is_eq('the day itself counts',        prevOn('2020-08-31', '2026-08-31'), '2026-08-31=6');
is_eq('the day after',                prevOn('2020-08-30', '2026-08-31'), '2026-08-30=6');
is_eq('the day before rolls back a year',
    prevOn('2020-09-01', '2026-08-31'), '2025-09-01=5');
is_eq('the hire day itself is year zero', prevOn('2026-08-31', '2026-08-31'), '2026-08-31=0');
is_eq('nothing before the hire date',  prevOn('2026-09-01', '2026-08-31'), 'none');
is_eq('a January hire seen in August', prevOn('2015-01-05', '2026-08-31'), '2026-01-05=11');
is_eq('leap hire, non-leap year',      prevOn('2000-02-29', '2026-06-01', 'feb28'), '2026-02-28=26');
is_eq('leap hire, mar1, before 1 March',
    prevOn('2000-02-29', '2026-02-28', 'mar1'), '2025-03-01=25');
is_eq('leap hire under skip reaches back to the last leap year',
    prevOn('2000-02-29', '2026-06-01', 'skip'), '2024-02-29=24');
is_eq('a leap year uses the real date', prevOn('2000-02-29', '2028-06-01'), '2028-02-29=28');
// The two directions must agree on the day itself, or a range that ends today
// and one that starts today would disagree about who is in it.
is_eq('forward and back agree on the day',
    prevOn('2019-08-30', '2026-08-30'), nextOn('2019-08-30', '2026-08-30'));

// ---------------------------------------------------------------------------
section('annivDaysBetween');

is_eq('same day',      annivDaysBetween('2026-08-31', '2026-08-31'), 0);
is_eq('tomorrow',      annivDaysBetween('2026-08-31', '2026-09-01'), 1);
is_eq('backwards',     annivDaysBetween('2026-08-31', '2026-08-24'), -7);
is_eq('across a year', annivDaysBetween('2026-01-01', '2027-01-01'), 365);
is_eq('across a leap year', annivDaysBetween('2024-01-01', '2025-01-01'), 366);
// A local-time subtraction across a DST boundary is 23 or 25 hours, and
// dividing that by 86400 loses or gains a day.
$tzWas = date_default_timezone_get();
@date_default_timezone_set('America/New_York');
is_eq('spring forward does not lose a day', annivDaysBetween('2026-03-07', '2026-03-09'), 2);
is_eq('fall back does not gain one',        annivDaysBetween('2026-10-31', '2026-11-02'), 2);
@date_default_timezone_set($tzWas);

// Leap years are four apart EXCEPT across a non-leap century, and a bound of
// four here would report "the bot will never mention you again" about somebody
// who has a real future date. Unreachable at this venue; still a wrong answer,
// so it is pinned rather than left to a comment.
is_eq('the eight-year gap across 2100 is still found',
    celebOn('2092-02-29', '2097-01-01', ['leap_mode' => 'skip', 'min_years' => 1]),
    '2104-02-29=12');
is_eq('and the plain calendar answer agrees',
    nextOn('2092-02-29', '2097-01-01', 'skip'), '2104-02-29=12');

// ---------------------------------------------------------------------------
section('annivYearsCompleted');

is_eq('the day before is one less',   annivYearsCompleted('2020-09-01', '2026-08-31'), 5);
is_eq('the day itself counts',        annivYearsCompleted('2020-08-31', '2026-08-31'), 6);
is_eq('the day after is the same',    annivYearsCompleted('2020-08-30', '2026-08-31'), 6);
is_eq('hired today is nought',        annivYearsCompleted('2026-08-31', '2026-08-31'), 0);
is_eq('never negative for a future hire', annivYearsCompleted('2027-01-01', '2026-08-31'), 0);
// The one place the calendar and the observance disagree, resolved in favour of
// what the bot is saying out loud that morning.
is_eq('a leap hire reads the announced number on the 28th',
    annivYearsCompleted('2000-02-29', '2026-02-28', 'feb28'), 26);
is_eq('and the plain calendar count a day earlier',
    annivYearsCompleted('2000-02-29', '2026-02-27', 'feb28'), 25);
is_eq('mar1 mode leaves the 28th on the calendar count',
    annivYearsCompleted('2000-02-29', '2026-02-28', 'mar1'), 25);
is_eq('skip mode never lifts it early',
    annivYearsCompleted('2000-02-29', '2026-02-28', 'skip'), 25);
is_eq('a leap year reads the real date',
    annivYearsCompleted('2000-02-29', '2028-02-29'), 28);

// ---------------------------------------------------------------------------
section('annivRosterRows');

$listRoster = [
    person('Alex Rivera', '2019-08-30', '101'),   // 7 years, anniversary today-ish
    person('Sam Chen',    '2021-08-30', '102'),   // 5 years
    person('Dana Frost',  '2025-08-30', '103'),   // 1 year
    person('Jordan Blake', '2016-02-29', '104'),  // leap-day hire
    person('Robin Vale',  '2026-08-25', '105'),   // hired days ago: year zero
];
$listRows = annivRosterRows($listRoster, '2026-08-31');
is_eq('one row per person', count($listRows), 5);

$byName = [];
foreach ($listRows as $r) { $byName[$r['name']] = $r; }

is_eq('an anniversary just gone reads as completed',
    $byName['Alex Rivera']['years'], 7);
is_eq('and the next one is a year out',
    $byName['Alex Rivera']['next_date'], '2027-08-30');
is_eq('reaching one more year',   $byName['Alex Rivera']['next_years'], 8);
is_eq('with the day count',       $byName['Alex Rivera']['days_until'], 364);
is_eq('and the one just gone',    $byName['Alex Rivera']['prev_date'], '2026-08-30');

is_eq('somebody hired last week has completed nothing',
    $byName['Robin Vale']['years'], 0);
is_eq('their first anniversary is next year',
    $byName['Robin Vale']['next_date'], '2027-08-25');
is_eq('the bot posts on it, since 1 year clears the floor',
    $byName['Robin Vale']['post_date'], '2027-08-25');
is_eq('so nothing is being suppressed', $byName['Robin Vale']['silent'], '');

is_eq('three people share 30 August',
    $byName['Sam Chen']['shared'] . '/' . $byName['Dana Frost']['shared'], '3/3');
is_eq('the leap hire is on their own', $byName['Jordan Blake']['shared'], 1);
is_eq('sorted soonest first', $listRows[0]['next_date'], '2027-02-28');

// min_years pushes the posting date out without touching the calendar one.
$strict = annivRosterRows($listRoster, '2026-08-31', ['min_years' => 5]);
$strictByName = [];
foreach ($strict as $r) { $strictByName[$r['name']] = $r; }
is_eq('the calendar answer is unchanged',
    $strictByName['Dana Frost']['next_date'], '2027-08-30');
is_eq('but the bot waits for the fifth year',
    $strictByName['Dana Frost']['post_date'], '2030-08-30');
is_eq('and says why', $strictByName['Dana Frost']['silent'], 'below_min');

// Milestone-only mode: the two columns come apart for nearly everybody.
$msRows = annivRosterRows($listRoster, '2026-08-31',
    ['mode' => 'milestones', 'milestone_years' => [1, 5, 10]]);
$msByName = [];
foreach ($msRows as $r) { $msByName[$r['name']] = $r; }
is_eq('an eighth year is not posted at all',
    $msByName['Alex Rivera']['post_date'], '2029-08-30');
is_eq('flagged as milestone-only',  $msByName['Alex Rivera']['silent'], 'milestones_only');
is_eq('a sixth year waits for the tenth',
    $msByName['Sam Chen']['post_years'], 10);
is_eq('past the end of the list is silence for good',
    $msByName['Jordan Blake']['post_date'], null);
is_eq('and says so', $msByName['Jordan Blake']['silent'], 'no_milestone_left');
is_eq('an empty milestone list is a different silence',
    annivRosterRows([person('Alex Rivera', '2019-08-30')], '2026-08-31',
        ['mode' => 'milestones', 'milestone_years' => []])[0]['silent'], 'no_milestones');

// When both the floor and the milestone list could be holding a post up, the
// reported reason has to be the one actually doing it — naming the other sends
// somebody to change a setting that changes nothing.
$bothBlock = annivRosterRows([person('Ella Ford', '2024-06-01')], '2026-08-31',
    ['mode' => 'milestones', 'milestone_years' => [5, 10], 'min_years' => 3])[0];
is_eq('the milestone list is named when the year would not post anyway',
    $bothBlock['silent'], 'milestones_only');
$floorBlocks = annivRosterRows([person('Ella Ford', '2026-06-01')], '2026-08-31',
    ['mode' => 'milestones', 'milestone_years' => [1, 5], 'min_years' => 3])[0];
is_eq('the floor is named when the year IS a milestone but below it',
    $floorBlocks['silent'], 'below_min');

// `shared` has to be the bot's own count on that day, not a count of the rows
// whose NEXT post lands there — milestone mode puts one person's fifth year on
// the day another reaches ten, and grouping the rows misses it.
$sameDay = [
    person('Ada One', '2022-04-13'),    // reaches 5 in 2027
    person('Bea Two', '2017-04-13'),    // reaches 10 in 2027
    person('Cal Three', '2017-04-13'),  // likewise
];
$msOpts = ['mode' => 'milestones', 'milestone_years' => [5, 10], 'min_years' => 1];
foreach (annivRosterRows($sameDay, '2026-08-31', $msOpts) as $r) {
    is_eq('shared matches the bot for ' . $r['name'],
        $r['shared'], count(annivCelebrants($sameDay, $r['post_date'], $msOpts)));
}

is_eq('names are left raw for the caller to style',
    $byName['Alex Rivera']['first'] . '|' . $byName['Alex Rivera']['last'], 'Alex|Rivera');

// A filter is never allowed to drop somebody from THIS list: it can only move
// their posting date. "Complete" is the whole point.
is_eq('the strictest possible settings still list everybody',
    count(annivRosterRows($listRoster, '2026-08-31',
        ['min_years' => 10, 'mode' => 'milestones', 'milestone_years' => [50]])),
    count($listRoster));

// The pin that fails when somebody spreads the person record into a row
// instead of projecting it: `email` and `slack_id` must never be in here, and
// therefore can never reach the browser by accident.
is_eq('a row carries exactly the allowlisted keys', array_keys($listRows[0]), [
    'emp_no', 'first', 'last', 'name', 'hire_date', 'years',
    'next_date', 'next_years', 'next_milestone', 'days_until',
    'prev_date', 'prev_years', 'post_date', 'post_years', 'post_is_next',
    'silent', 'shared',
]);

// The year on the observance date always equals hire year + years. A future
// leap rule that moved an observance across a year boundary would break the
// page's agreement with Slack silently; this catches it.
foreach ($listRows as $r) {
    ok('year parity for ' . $r['name'],
        substr($r['next_date'], 0, 4) === (string)((int)substr($r['hire_date'], 0, 4) + $r['next_years']));
}

// Every posting date this list advertises must actually produce a celebrant on
// that day, with the same year count, when the bot asks.
foreach (annivRosterRows($listRoster, '2026-08-31', ['mode' => 'milestones']) as $r) {
    if ($r['post_date'] === null) { continue; }
    $p = person($r['name'], $r['hire_date']);
    $hit = annivCelebrants([$p], $r['post_date'], ['mode' => 'milestones']);
    ok('the bot agrees about ' . $r['name'] . ' on ' . $r['post_date'],
        count($hit) === 1 && (int)$hit[0]['years'] === (int)$r['post_years']);
}

// ---------------------------------------------------------------------------
section('annivNormalizeRoster: dropped rows');

$rawRows = [
    ['EmpNo' => '1', 'FirstName' => 'Alex', 'LastName' => 'Rivera', 'DateOfHire' => '2019-08-30'],
    ['EmpNo' => '2', 'FirstName' => 'Nell', 'LastName' => 'Voss',   'DateOfHire' => null],
    ['EmpNo' => '3', 'FirstName' => 'Pat',  'LastName' => 'Oke',    'DateOfHire' => '1900-01-01'],
    ['EmpNo' => '4', 'FirstName' => 'Wren', 'LastName' => 'Ash',    'DateOfHire' => '2027-04-01'],
    ['EmpNo' => '5', 'FirstName' => 'Kit',  'LastName' => 'Lowe',   'DateOfHire' => 'not a date'],
    ['EmpNo' => '6', 'FirstName' => 'Ola',  'LastName' => 'Reed',   'DateOfHire' => '2020-05-05'],
];
$plain = annivNormalizeRoster($rawRows, ['today' => '2026-08-31']);
is_eq('collecting is off by default', $plain['dropped'], []);
is_eq('the counts are unchanged', $plain['skipped']['no_hire_date'], 1);

$withDrops = annivNormalizeRoster($rawRows, [
    'today' => '2026-08-31', 'collect_dropped' => true,
]);
is_eq('four rows are set aside', count($withDrops['dropped']), 4);
$reasons = array_column($withDrops['dropped'], 'reason');
is_eq('each with its own reason', $reasons, ['no_hire_date', 'sentinel', 'future', 'unparsed']);
is_eq('a dropped row is still named', $withDrops['dropped'][0]['name'], 'Nell Voss');
is_eq('the rejected value is kept',   $withDrops['dropped'][3]['value'], 'not a date');
// The opt-out has to say which rule matched, or a typo'd entry that matches
// nobody looks exactly like one that works.
$optOut = annivNormalizeRoster([$rawRows[5]], [
    'today' => '2026-08-31', 'collect_dropped' => true, 'exclude_emp_nos' => ['6'],
]);
is_eq('an opt-out is recorded as one', $optOut['dropped'][0]['reason'], 'excluded');
is_eq('naming the rule that matched',  $optOut['dropped'][0]['value'], 'employee number');
$optOutName = annivNormalizeRoster([$rawRows[5]], [
    'today' => '2026-08-31', 'collect_dropped' => true, 'exclude_names' => ['Ola Reed'],
]);
is_eq('or the other rule', $optOutName['dropped'][0]['value'], 'name');

// The roster query is operator-editable and nothing stops it being SELECT *,
// over a table that also holds SSN and PasswordHash. A dropped record is an
// allowlist, not a copy of the row.
$secretish = annivNormalizeRoster([
    ['EmpNo' => '9', 'FirstName' => 'Ivy', 'LastName' => 'Bly', 'DateOfHire' => null,
     'SSN' => '123-45-6789', 'PasswordHash' => 'x', 'FingerprintTemplate' => 'y'],
], ['today' => '2026-08-31', 'collect_dropped' => true]);
is_eq('a dropped record carries only the allowlisted keys',
    array_keys($secretish['dropped'][0]), ['reason', 'name', 'first', 'last', 'emp_no', 'value']);
ok('and nothing from the rest of the row',
    strpos(json_encode($secretish['dropped']), '123-45-6789') === false);

// ---------------------------------------------------------------------------
section('names');

$p = person('Alex Rivera', '2019-08-30', '101');
is_eq('full name',      annivDisplayName($p, 'full'), 'Alex Rivera');
is_eq('first only',     annivDisplayName($p, 'first'), 'Alex');
is_eq('first + initial', annivDisplayName($p, 'first_initial'), 'Alex R.');

is_eq('bolded by default', annivMentionOrName($p, 'full', true), '*Alex Rivera*');
is_eq('unbolded',          annivMentionOrName($p, 'full', false), 'Alex Rivera');
$m = $p; $m['slack_id'] = 'U123';
is_eq('a resolved Slack user becomes a mention', annivMentionOrName($m, 'full', true), '<@U123>');

is_eq('escapes Slack control characters', annivSlackEscape('Ben & <Jo>'), 'Ben &amp; &lt;Jo&gt;');

is_eq('one name',    annivJoinNames(['A']), 'A');
is_eq('two names',   annivJoinNames(['A', 'B']), 'A and B');
is_eq('three names', annivJoinNames(['A', 'B', 'C']), 'A, B and C');
is_eq('no names',    annivJoinNames([]), '');

// One person: the template carries the number, so the name does not.
$one = annivCelebrants($roster, '2026-02-28');
is_eq('a single name carries no year count',
    annivNameList($one, 'full', true), '*Jordan Blake*');
// Several: they have different numbers, so each name carries its own.
$three = annivCelebrants($roster, '2026-08-30');
is_eq('shared day: every name carries its own years',
    annivNameList($three, 'first', true),
    '*Alex* (7 years), *Sam* (5 years) and *Dana* (1 year)');

// ---------------------------------------------------------------------------
section('annivPickTemplate');

$seed = 'test-seed';
is_eq('a pinned single template wins outright',
    annivPickTemplate(1, false, ['message_single' => 'PINNED {names}'], $seed), 'PINNED {names}');
is_eq('a pinned multi template wins outright',
    annivPickTemplate(3, false, ['message_multi' => 'PINNED MULTI'], $seed), 'PINNED MULTI');
is_eq('a custom whole-message pool is used',
    annivPickTemplate(1, false, ['messages_single' => ['ONLY ONE {names}']], $seed), 'ONLY ONE {names}');
is_eq('greeting + flavour are joined',
    annivPickTemplate(1, false, ['greetings' => ['G {names}'], 'flavors' => ['F.']], $seed), "G {names}\nF.");
is_eq('an EMPTY flavour list means greeting only',
    annivPickTemplate(1, false, ['greetings' => ['G {names}'], 'flavors' => []], $seed), 'G {names}');
is_eq('an ABSENT flavour list uses the built-in pool',
    strpos(annivPickTemplate(1, false, ['greetings' => ['G {names}']], $seed), "\n") !== false, true);
is_eq('a milestone uses the milestone pool',
    annivPickTemplate(1, true, ['milestone_greetings' => ['BIG {names}'], 'milestone_flavors' => []], $seed),
    'BIG {names}');
is_eq('a non-milestone ignores the milestone pool',
    annivPickTemplate(1, false, ['greetings' => ['G {names}'], 'flavors' => [],
                                 'milestone_greetings' => ['BIG {names}']], $seed),
    'G {names}');
is_eq('several people use the multi pool',
    annivPickTemplate(3, false, ['multi_greetings' => ['M {names}'], 'multi_flavors' => [],
                                 'greetings' => ['G {names}']], $seed),
    'M {names}');
// The same seed always picks the same pair — that is what makes --dry-run an
// honest preview of what will actually post.
$cfgPools = ['greetings' => ['A {names}', 'B {names}', 'C {names}'],
             'flavors'   => ['one.', 'two.', 'three.', 'four.']];
is_eq('deterministic for a given seed',
    annivPickTemplate(1, false, $cfgPools, 'seed-x'),
    annivPickTemplate(1, false, $cfgPools, 'seed-x'));

// ---------------------------------------------------------------------------
section('annivMessageConfig');

$full = [
    'name_style' => 'first', 'bold_names' => false, 'venue_label' => 'Somewhere',
    'mention' => '<!here>', 'milestone_years' => ['5', '10'],
    'greetings' => ['G {names}'], 'flavors' => [], 'multi_flavors' => null,
    'message_single' => '  ', 'slack_bot_token' => 'xoxb-secret',
];
$msg = annivMessageConfig($full);
is_eq('name style carried',   $msg['name_style'], 'first');
is_eq('bold flag carried',    $msg['bold_names'], false);
is_eq('venue carried',        $msg['venue_label'], 'Somewhere');
is_eq('mention carried',      $msg['mention'], '<!here>');
is_eq('milestones normalised', $msg['milestone_years'], [5, 10]);
ok('a blank pinned template is not carried', !array_key_exists('message_single', $msg));
ok('a real pool is carried',                  array_key_exists('greetings', $msg));
// Presence, not value: an empty array means "no flavour line", a null means
// "use the built-in pool", and the two must not collapse into each other.
ok('an empty pool is carried as empty',       array_key_exists('flavors', $msg) && $msg['flavors'] === []);
ok('a null pool is NOT carried',             !array_key_exists('multi_flavors', $msg));
ok('the token is not carried into the message config', !isset($msg['slack_bot_token']));
is_eq('overrides win', annivMessageConfig($full, ['mention' => ''])['mention'], '');

// ---------------------------------------------------------------------------
section('annivBuildText');

$solo = annivCelebrants($roster, '2026-08-30', ['min_years' => 7]);
is_eq('one celebrant', count($solo), 1);
is_eq('single: every placeholder resolves',
    annivBuildText($solo, [
        'greetings' => ['{count}|{years}|{year_label}|{ordinal}|{s}|{venue}|{names}'],
        'flavors'   => [], 'venue_label' => 'The Castle', 'name_style' => 'first',
    ], $seed),
    '1|7|7 years|7th|s|The Castle|*Alex*');

// milestone_years is emptied here so the ordinary pool is the one under test —
// one year IS a milestone by default, and would otherwise pick the other pool.
is_eq('single: {s} is empty at one year',
    annivBuildText([array_merge(person('Dana Ward', '2025-08-30'), ['years' => 1])],
        ['greetings' => ['{years} year{s}'], 'flavors' => [], 'milestone_years' => []], $seed),
    '1 year');

// Several people: {years} is the COMBINED total, and {ordinal} is dropped
// rather than made up — nobody has an ordinal of 13.
is_eq('multi: {years} is the combined total',
    annivBuildText($three, ['multi_greetings' => ['{count}: {years} ({year_label})'],
                            'multi_flavors' => []], $seed),
    '3: 13 (13 years)');
is_eq('multi: {ordinal} resolves to nothing',
    annivBuildText($three, ['multi_greetings' => ['[{ordinal}]'], 'multi_flavors' => []], $seed),
    '[]');

is_eq('the ping prefix is prepended',
    annivBuildText($solo, ['greetings' => ['hi'], 'flavors' => [], 'mention' => '<!here>'], $seed),
    '<!here> hi');
is_eq('no celebrants, no message', annivBuildText([], []), '');

// A milestone picks the milestone pool without being told: the flag is derived
// from the celebrant's own year count, so a preview cannot disagree with a post.
$five = annivCelebrants($roster, '2026-08-30', ['min_years' => 5]);
$fiveOnly = [$five[1]];
is_eq('the celebrant\'s years choose the pool',
    annivBuildText($fiveOnly, ['greetings' => ['ORDINARY'], 'flavors' => [],
                               'milestone_greetings' => ['MILESTONE'], 'milestone_flavors' => []], $seed),
    'MILESTONE');
is_eq('...and an empty milestone list turns that off',
    annivBuildText($fiveOnly, ['greetings' => ['ORDINARY'], 'flavors' => [],
                               'milestone_greetings' => ['MILESTONE'], 'milestone_flavors' => [],
                               'milestone_years' => []], $seed),
    'ORDINARY');
is_eq('a shared day never uses the milestone pool',
    annivBuildText($three, ['multi_greetings' => ['MULTI'], 'multi_flavors' => [],
                            'milestone_greetings' => ['MILESTONE']], $seed),
    'MULTI');

// ---------------------------------------------------------------------------
section('the built-in pools');

foreach (['greetings' => ANNIV_GREETINGS,
          'milestone greetings' => ANNIV_MILESTONE_GREETINGS,
          'multi greetings' => ANNIV_MULTI_GREETINGS] as $label => $pool) {
    $missing = 0;
    foreach ($pool as $line) {
        if (strpos($line, '{names}') === false) { $missing++; }
    }
    is_eq("every one of the {$label} names somebody", $missing, 0);
}

// {ordinal} has no meaning when several people share a day, so the multi pools
// must not use it — the API rejects it there too.
$badOrdinal = 0;
foreach (array_merge(ANNIV_MULTI_GREETINGS, ANNIV_MULTI_FLAVORS) as $line) {
    if (strpos($line, '{ordinal}') !== false) { $badOrdinal++; }
}
is_eq('no multi line uses {ordinal}', $badOrdinal, 0);

// Every flavour line is joined after an ARBITRARY greeting, so each has to be a
// complete sentence that never refers back to the line above it.
$badFlavor = [];
foreach (array_merge(ANNIV_FLAVORS, ANNIV_MILESTONE_FLAVORS, ANNIV_MULTI_FLAVORS) as $line) {
    $firstChar = mb_substr($line, 0, 1);
    $lastChar  = mb_substr($line, -1);
    if ($firstChar !== mb_strtoupper($firstChar) || !in_array($lastChar, ['.', '!', '?'], true)) {
        $badFlavor[] = $line;
    }
}
is_eq('every flavour line stands alone (capitalised, ends a sentence)', $badFlavor, []);

// Rendering every combination must never leave an unreplaced placeholder.
$leftover = [];
foreach ([1 => $solo, 3 => $three] as $count => $people) {
    foreach ([false, true] as $milestone) {
        for ($i = 0; $i < 40; $i++) {
            $text = annivBuildText($people, ['milestone_years' => $milestone ? [7, 13] : []],
                'sweep|' . $count . '|' . $milestone . '|' . $i);
            if (strpos($text, '{') !== false) { $leftover[] = $text; }
        }
    }
}
is_eq('no built-in combination leaves a placeholder behind', $leftover, []);

// ---------------------------------------------------------------------------
section('annivBuildBlocks');

$blocks = annivBuildBlocks('Hello', 'https://example.com/a.gif', ['venue_label' => 'The Castle']);
is_eq('three blocks with a GIF', count($blocks), 3);
is_eq('the message leads',   $blocks[0]['text']['text'], 'Hello');
is_eq('the GIF is an image', $blocks[1]['type'], 'image');
ok('the image has alt text', ($blocks[1]['alt_text'] ?? '') !== '');
is_eq('the footer names the venue',
    $blocks[2]['elements'][0]['text'], ':trophy: from everyone at The Castle');

is_eq('no GIF, two blocks', count(annivBuildBlocks('Hi', null, [])), 2);
is_eq('a blank footer removes it', count(annivBuildBlocks('Hi', null, ['footer_text' => ' '])), 1);
is_eq('a custom footer is used',
    annivBuildBlocks('Hi', null, ['footer_text' => 'Mine'])[1]['elements'][0]['text'], 'Mine');

// ---------------------------------------------------------------------------
section('state file');

$tmp = sys_get_temp_dir() . '/anniv_test_' . getmypid() . '.json';
@unlink($tmp);

is_eq('a missing file loads clean', annivStateLoad($tmp), ['posted' => []]);

$st = annivStateMark(['posted' => []], '2026-08-30', $three);
ok('marked people are remembered', annivStateHas($st, '2026-08-30', $three[0]));
ok('...but not on another date',  !annivStateHas($st, '2026-08-31', $three[0]));
is_eq('marking twice does not duplicate',
    count(annivStateMark($st, '2026-08-30', $three)['posted']['2026-08-30']), 3);

ok('state saves', annivStateSave($tmp, $st));
is_eq('and reloads identically', annivStateLoad($tmp), $st);
file_put_contents($tmp, 'not json at all');
is_eq('a corrupt state file loads clean', annivStateLoad($tmp), ['posted' => []]);
@unlink($tmp);

$old = ['posted' => ['2026-01-01' => ['e:1'], '2026-08-29' => ['e:2']]];
is_eq('pruning drops the old day',
    array_keys(annivStatePrune($old, '2026-08-30', 45)['posted']), ['2026-08-29']);

// A person with no employee number is keyed by name, so the roster query
// deciding not to select EmpNo does not cost duplicate suppression.
$anon = person('Robin Fox', '2015-01-05');
is_eq('name-keyed person', annivPersonKey($anon), 'n:robinfox');
is_eq('number-keyed person', annivPersonKey($three[0]), 'e:101');

// ---------------------------------------------------------------------------
section('annivRunHealth');

$now = strtotime('2026-08-30 11:00:00');
$todayIso = date('Y-m-d', $now);

$posted = annivStateRecordRun(['posted' => []], $todayIso, 'posted', 'to C123', 2, $now);
is_eq('a posted run today reads ok',
    annivRunHealth($posted, date('c', $now), $now)['status'], 'ok');
is_eq('an idle run today reads idle',
    annivRunHealth(annivStateRecordRun(['posted' => []], $todayIso, 'idle', '', 0, $now),
        date('c', $now), $now)['status'], 'idle');
is_eq('a disabled run today reads off',
    annivRunHealth(annivStateRecordRun(['posted' => []], $todayIso, 'disabled', '', 0, $now),
        date('c', $now), $now)['status'], 'off');
is_eq('a failed run today reads fail',
    annivRunHealth(annivStateRecordRun(['posted' => []], $todayIso, 'failed', 'boom', 0, $now),
        date('c', $now), $now)['status'], 'fail');

// Today's run record beats a missing heartbeat: the run writes it itself, so it
// is proof the bot ran even when the heartbeat write failed.
is_eq('today\'s record wins over a missing heartbeat',
    annivRunHealth($posted, null, $now)['status'], 'ok');

is_eq('never run at all', annivRunHealth(['posted' => []], null, $now)['status'], 'fail');
is_eq('yesterday is normal',
    annivRunHealth(['posted' => []], date('c', $now - 3600 * 20), $now)['status'], 'ok');
is_eq('a missed firing warns',
    annivRunHealth(['posted' => []], date('c', $now - 3600 * 30), $now)['status'], 'warn');
is_eq('two missed firings fail',
    annivRunHealth(['posted' => []], date('c', $now - 3600 * 60), $now)['status'], 'fail');
// A successful post three days ago says nothing about this morning.
$stale = annivStateRecordRun(['posted' => []], '2026-08-27', 'posted', '', 1, $now);
is_eq('an old run record does not colour the verdict',
    annivRunHealth($stale, date('c', $now - 3600 * 60), $now)['status'], 'fail');
// An outcome from a future version falls through to the age reading rather
// than inventing a verdict for it.
$unknown = annivStateRecordRun(['posted' => []], $todayIso, 'something-new', '', 0, $now);
is_eq('an unknown outcome falls through to the heartbeat',
    annivRunHealth($unknown, date('c', $now), $now)['status'], 'ok');

is_eq('relative: minutes', annivRelativeTime($now - 300, $now), '5 minutes ago');
is_eq('relative: hours',   annivRelativeTime($now - 7200, $now), '2 hours ago');
is_eq('relative: just now', annivRelativeTime($now - 10, $now), 'just now');
ok('relative: yesterday', strpos(annivRelativeTime($now - 86400 * 1, $now), 'yesterday') === 0);

// ---------------------------------------------------------------------------
section('the run lock');

$lockPath = sys_get_temp_dir() . '/anniv_test_lock_' . getmypid();
@unlink($lockPath);
$h1 = annivLockAcquire($lockPath);
ok('the first run takes the lock', is_resource($h1));
// A second acquire in the SAME process cannot be tested with flock (the lock is
// per file handle per process and PHP would re-grant it), so the contract that
// matters here is that releasing is safe and idempotent.
annivLockRelease($h1);
annivLockRelease($h1);
ok('releasing twice is harmless', true);
ok('null is not false: an unopenable path reports null',
    annivLockAcquire('/proc/definitely/not/writable/anniv.lock') === null);
@unlink($lockPath);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 50) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
