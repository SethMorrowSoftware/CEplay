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

$p2 = $p; $p2['first'] = 'Sam'; $p2['last'] = 'Torres'; $p2['name'] = 'Sam Torres'; $p2['emp_no'] = '2';
$p3 = $p; $p3['first'] = 'Kim'; $p3['last'] = 'Nguyen'; $p3['name'] = 'Kim Nguyen'; $p3['emp_no'] = '3';

$single = bdayBuildText([$p], ['venue_label' => 'The Castle Fun Center'], 'seed-1');
ok('single message names the person', strpos($single, '*Alex Rivera*') !== false);
ok('no birth year leaks into the message', strpos($single, '1990') === false);

$multi = bdayBuildText([$p, $p2, $p3], ['venue_label' => 'X'], 'seed-1');
ok('multi message lists all three',
    strpos($multi, '*Alex Rivera*, *Sam Torres* and *Kim Nguyen*') !== false);

// Every line in every shipped pool has to survive rendering — a stray or
// misspelled placeholder would otherwise reach the channel as literal
// "{nmaes}" on whichever day its seed happened to land on.
section('message pools — invariants across EVERY line');

$poolCfg = ['venue_label' => 'The Castle Fun Center'];
$render = function (string $tpl, array $people) use ($poolCfg) {
    $key = count($people) > 1 ? 'message_multi' : 'message_single';
    return bdayBuildText($people, $poolCfg + [$key => $tpl]);
};

$bad = [];
foreach (BDAY_GREETINGS as $i => $tpl) {
    $out = $render($tpl, [$p]);
    if (preg_match('/\{[a-z_]+\}/', $out)
        || strpos($tpl, '{names}') === false
        || strpos($out, '*Alex Rivera*') === false) {
        $bad[] = $i;
    }
}
is_eq('every greeting names the person and renders cleanly', $bad, []);

$bad = [];
foreach (BDAY_MULTI_GREETINGS as $i => $tpl) {
    $out = $render($tpl, [$p, $p2, $p3]);
    if (preg_match('/\{[a-z_]+\}/', $out)
        || strpos($tpl, '{names}') === false
        || strpos($out, '*Alex Rivera*, *Sam Torres* and *Kim Nguyen*') === false) {
        $bad[] = $i;
    }
}
is_eq('every multi greeting lists everyone', $bad, []);

// A flavour line follows an arbitrary greeting, so it has to stand alone: a
// complete sentence, with no placeholder of its own to leak.
$bad = [];
foreach (array_merge(BDAY_FLAVORS, BDAY_MULTI_FLAVORS) as $i => $line) {
    if ($line === '' || preg_match('/\{[a-z_]+\}/', $line)
        || !preg_match('/[.!?]$/', $line)
        || strtoupper($line[0]) !== $line[0]) {
        $bad[] = $line;
    }
}
is_eq('every flavour line is a self-contained sentence', $bad, []);

is_eq('no duplicate greetings', count(BDAY_GREETINGS), count(array_unique(BDAY_GREETINGS)));
is_eq('no duplicate flavours', count(BDAY_FLAVORS), count(array_unique(BDAY_FLAVORS)));
is_eq('no duplicate multi greetings',
    count(BDAY_MULTI_GREETINGS), count(array_unique(BDAY_MULTI_GREETINGS)));
is_eq('no duplicate multi flavours',
    count(BDAY_MULTI_FLAVORS), count(array_unique(BDAY_MULTI_FLAVORS)));

// A four-digit year anywhere would read as somebody's birth year.
$yearish = [];
foreach (array_merge(BDAY_GREETINGS, BDAY_FLAVORS, BDAY_MULTI_GREETINGS, BDAY_MULTI_FLAVORS) as $line) {
    if (preg_match('/\b(19|20)\d{2}\b/', $line)) { $yearish[] = $line; }
}
is_eq('no pool line contains a year-like number', $yearish, []);

section('composition — the point of splitting the pools');

// Greeting and flavour are seeded separately, so the combinations multiply
// instead of the pools moving in lockstep.
$seen = [];
for ($i = 0; $i < 600; $i++) {
    $seen[bdayBuildText([$p], $poolCfg, 'seed-' . $i)] = true;
}
ok('600 seeds produce a wide spread of messages (' . count($seen) . ' distinct)',
    count($seen) > 200);
ok('far more combinations than either pool alone',
    count($seen) > max(count(BDAY_GREETINGS), count(BDAY_FLAVORS)));

$composed = bdayPickTemplate(1, [], 'x');
ok('a composed message is two lines', substr_count($composed, "\n") === 1);
[$g, $f] = explode("\n", $composed);
ok('first line is a greeting', in_array($g, BDAY_GREETINGS, true));
ok('second line is a flavour', in_array($f, BDAY_FLAVORS, true));

$composedMulti = bdayPickTemplate(3, [], 'x');
[$mg, $mf] = explode("\n", $composedMulti);
ok('multi composes from the multi pools',
    in_array($mg, BDAY_MULTI_GREETINGS, true) && in_array($mf, BDAY_MULTI_FLAVORS, true));

// An empty flavour list is a real choice (greeting only), not a fall-through.
$greetOnly = bdayPickTemplate(1, ['flavors' => []], 'x');
ok('an empty flavour list gives the greeting alone',
    strpos($greetOnly, "\n") === false && in_array($greetOnly, BDAY_GREETINGS, true));

$customFlavor = bdayPickTemplate(1, ['flavors' => ['Only this one.']], 'x');
ok('a custom flavour pool is used', substr($customFlavor, -15) === 'Only this one.' || strpos($customFlavor, 'Only this one.') !== false);

$customGreet = bdayPickTemplate(1, ['greetings' => ['Hi {names}!'], 'flavors' => ['Bye.']], 'x');
is_eq('custom greeting and flavour compose', $customGreet, "Hi {names}!\nBye.");

section('Block Kit assembly');

$blocks = bdayBuildBlocks('hello', 'https://example.com/a.gif', ['venue_label' => 'Castle']);
is_eq('three blocks with a GIF', count($blocks), 3);
is_eq('first block is the message', $blocks[0]['type'], 'section');
is_eq('message text carried through', $blocks[0]['text']['text'], 'hello');
is_eq('second block is the image', $blocks[1]['type'], 'image');
is_eq('image url carried through', $blocks[1]['image_url'], 'https://example.com/a.gif');
ok('alt_text is present (Slack rejects an image block without it)',
    !empty($blocks[1]['alt_text']));
is_eq('last block is the footer', $blocks[2]['type'], 'context');
ok('footer names the venue', strpos($blocks[2]['elements'][0]['text'], 'Castle') !== false);

$noGif = bdayBuildBlocks('hello', null, []);
is_eq('no image block without a GIF', count($noGif), 2);
is_eq('still has the message', $noGif[0]['type'], 'section');

$noFooter = bdayBuildBlocks('hello', null, ['footer_text' => '']);
is_eq('empty footer text drops the block', count($noFooter), 1);

$custom = bdayBuildBlocks('hi', null, ['footer_text' => 'my footer']);
is_eq('custom footer used', $custom[1]['elements'][0]['text'], 'my footer');

ok('blocks are JSON-encodable for the Slack payload',
    json_encode(bdayBuildBlocks("a *b* c", 'https://x/y.gif', [])) !== false);

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
section('bdayMessageConfig — every wording setting reaches the message');

// The regression this section exists for: the daily run assembled its own
// message config by hand and left the four pool keys out of it, so custom
// greetings saved on the Birthdays page were shown by the preview and by
// --demo, and then not used by the post that actually went out. Each case
// below sets one setting to something unmistakable in a FULL config array (the
// shape BirthdayConfig::load returns) and checks it survives into the text.

/** Build a single-person message the way birthday_bot.php does. */
function msgFromFullCfg(array $cfg, array $people, string $seed = 'seed'): string
{
    return bdayBuildText($people, bdayMessageConfig($cfg), $seed);
}

$fullCfg = [
    'name_style' => 'full', 'bold_names' => true, 'venue_label' => 'The Castle Fun Center',
    'mention' => '', 'message_single' => '', 'message_multi' => '',
    'messages_single' => null, 'messages_multi' => null,
    'greetings' => null, 'flavors' => null,
    'multi_greetings' => null, 'multi_flavors' => null,
];

$one  = [$p];
$many = [$p, $p2, $p3];

ok('custom greetings pool reaches a single-person post',
    strpos(msgFromFullCfg(['greetings' => ['GREET-X {names}!']] + $fullCfg, $one), 'GREET-X') !== false);
ok('custom flavours pool reaches a single-person post',
    strpos(msgFromFullCfg(['flavors' => ['FLAVOUR-X.']] + $fullCfg, $one), 'FLAVOUR-X.') !== false);
ok('custom multi greetings pool reaches a shared post',
    strpos(msgFromFullCfg(['multi_greetings' => ['MGREET-X {names}!']] + $fullCfg, $many), 'MGREET-X') !== false);
ok('custom multi flavours pool reaches a shared post',
    strpos(msgFromFullCfg(['multi_flavors' => ['MFLAVOUR-X.']] + $fullCfg, $many), 'MFLAVOUR-X.') !== false);
ok('whole-template pool reaches a single-person post',
    strpos(msgFromFullCfg(['messages_single' => ['WHOLE-X {names}']] + $fullCfg, $one), 'WHOLE-X') !== false);
ok('whole-template pool reaches a shared post',
    strpos(msgFromFullCfg(['messages_multi' => ['WHOLEM-X {names}']] + $fullCfg, $many), 'WHOLEM-X') !== false);
ok('pinned single wording reaches the post',
    strpos(msgFromFullCfg(['message_single' => 'PINNED-X {names}'] + $fullCfg, $one), 'PINNED-X') !== false);
ok('pinned shared wording reaches the post',
    strpos(msgFromFullCfg(['message_multi' => 'PINNEDM-X {names}'] + $fullCfg, $many), 'PINNEDM-X') !== false);
ok('venue label reaches the post',
    strpos(msgFromFullCfg(['message_single' => '{venue}'] + $fullCfg, $one), 'The Castle Fun Center') !== false);
ok('ping prefix reaches the post',
    strpos(msgFromFullCfg(['mention' => '<!here>'] + $fullCfg, $one), '<!here>') === 0);

// Absent vs empty is a real distinction, and the one most easily lost when
// this subset is rebuilt: a stored NULL means "use the built-in pool", an
// empty array means "the operator wants no flavour line".
$defaults = bdayMessageConfig($fullCfg);
ok('a null pool is omitted, so the built-in set is used',
    !array_key_exists('flavors', $defaults) && !array_key_exists('greetings', $defaults));
ok('an empty pool is passed through as empty',
    bdayMessageConfig(['flavors' => []] + $fullCfg)['flavors'] === []);
is_eq('empty flavour list really does mean greeting only',
    substr_count(msgFromFullCfg(['flavors' => []] + $fullCfg, $one), "\n"), 0);
ok('an unset pinned wording is omitted rather than passed as an empty string',
    !array_key_exists('message_single', $defaults));

// --demo blanks the ping so a sample announcement can never @-here a channel.
is_eq('overrides win over the stored value',
    bdayMessageConfig(['mention' => '<!channel>'] + $fullCfg, ['mention' => ''])['mention'], '');

// ---------------------------------------------------------------------------
section('run record — telling "nobody today" apart from "never ran"');

// The bot's failure mode is silence, and every cause of it looks identical in
// the channel. These are the readings that have to distinguish them.

$hbNow  = date('c');
$today  = date('Y-m-d');
$mk = function (string $outcome, int $count = 0, string $detail = '', ?int $at = null) use ($today) {
    return bdayStateRecordRun(['posted' => []], $today, $outcome, $detail, $count, $at);
};

$never = bdayRunHealth(['posted' => []], null);
is_eq('never run -> fail', $never['status'], 'fail');
ok('never run says how to check the timer', strpos($never['detail'], 'timer') !== false);
is_eq('never run has no age', $never['age'], null);

$posted = bdayRunHealth($mk('posted', 2, 'to C01'), $hbNow);
is_eq('posted today -> ok', $posted['status'], 'ok');
ok('posted today counts the greetings', strpos($posted['detail'], '2 greetings') !== false);
ok('one greeting is singular',
    strpos(bdayRunHealth($mk('posted', 1), $hbNow)['detail'], '1 greeting at') !== false);

is_eq('nobody today -> idle', bdayRunHealth($mk('idle'), $hbNow)['status'], 'idle');
is_eq('switched off -> off', bdayRunHealth($mk('disabled'), $hbNow)['status'], 'off');

$failedRun = bdayRunHealth($mk('failed', 0, 'Could not read the roster'), $hbNow);
is_eq('failed today -> fail', $failedRun['status'], 'fail');
ok('failed today carries the reason',
    strpos($failedRun['detail'], 'Could not read the roster') !== false);

// A run earlier in the day is normal — the timer fires once, so for most of
// the day the newest run is yesterday morning's.
$yesterday = date('c', time() - 20 * 3600);
is_eq('ran 20 hours ago -> still ok',
    bdayRunHealth(['posted' => []], $yesterday)['status'], 'ok');

$missed = bdayRunHealth(['posted' => []], date('c', time() - 30 * 3600));
is_eq('30 hours -> warn (a firing was missed)', $missed['status'], 'warn');
$dead = bdayRunHealth(['posted' => []], date('c', time() - 72 * 3600));
is_eq('72 hours -> fail (the timer is not firing)', $dead['status'], 'fail');

// An OLD success never colours the verdict green: a post three days ago says
// nothing about this morning.
$staleButHappy = bdayRunHealth(
    bdayStateRecordRun(['posted' => []], date('Y-m-d', time() - 72 * 3600), 'posted', '', 3),
    date('c', time() - 72 * 3600)
);
is_eq('an old success does not excuse a dead timer', $staleButHappy['status'], 'fail');

// But TODAY's record does outrank the heartbeat, because the run writes it
// itself. A heartbeat that could not be written (a permissions problem on the
// data directory) must not be reported as a timer that stopped firing.
is_eq('a missing heartbeat cannot override a run recorded today',
    bdayRunHealth($mk('posted', 1), null)['status'], 'ok');
is_eq('nor can a stale one',
    bdayRunHealth($mk('idle'), date('c', time() - 80 * 3600))['status'], 'idle');

// Upgrading an install that has a heartbeat but no last_run yet.
is_eq('heartbeat without a run record still reads',
    bdayRunHealth(['posted' => []], $hbNow)['status'], 'ok');
is_eq('an outcome this version does not know falls back to the age reading',
    bdayRunHealth($mk('something_new'), $hbNow)['status'], 'ok');

$rec = bdayStateRecordRun(['posted' => ['2026-08-23' => ['e:1']]], '2026-08-23', 'posted', 'to C01', 2);
is_eq('recording keeps the already-greeted list', $rec['posted'], ['2026-08-23' => ['e:1']]);
is_eq('recording stores the outcome', $rec['last_run']['outcome'], 'posted');
is_eq('recording stores the count', $rec['last_run']['count'], 2);

is_eq('relative: minutes', bdayRelativeTime(1000000 - 600, 1000000), '10 minutes ago');
is_eq('relative: one hour', bdayRelativeTime(1000000 - 3600, 1000000), '1 hour ago');
is_eq('relative: hours', bdayRelativeTime(1000000 - 7200, 1000000), '2 hours ago');
ok('relative: yesterday', strpos(bdayRelativeTime(1000000 - 86400, 1000000), 'yesterday at ') === 0);
ok('relative: days', strpos(bdayRelativeTime(1000000 - 4 * 86400, 1000000), '4 days ago') === 0);

// ---------------------------------------------------------------------------
section('run lock — two runs must not both post');

$lockPath = sys_get_temp_dir() . '/bday_lock_' . getmypid() . '.lock';
@unlink($lockPath);

$first = bdayLockAcquire($lockPath);
ok('the first run takes the lock', is_resource($first));
is_eq('a second run is turned away', bdayLockAcquire($lockPath), false);
bdayLockRelease($first);
$second = bdayLockAcquire($lockPath);
ok('the lock is free once released', is_resource($second));
bdayLockRelease($second);
bdayLockRelease(null);   // must not blow up
ok('releasing nothing is harmless', true);
@unlink($lockPath);

// A lock file that cannot be opened returns null, NOT false: the caller has to
// tell "somebody else is posting" (stop) from "the lock is broken" (carry on,
// because a permissions problem must not cost a birthday).
is_eq('an unopenable lock path reports null',
    bdayLockAcquire('/proc/nonexistent-dir-for-test/x.lock'), null);

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
