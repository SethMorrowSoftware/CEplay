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
