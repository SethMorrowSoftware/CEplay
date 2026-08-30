<?php
/**
 * Unit tests for lib/roster_guard.php.
 *
 * Shared by the birthday bot and the work-anniversary bot, and it guards the
 * worst failure either one has: both put employee names in a public Slack
 * channel, and the ONLY thing between "today's celebrants" and "everyone who
 * ever worked here" is the WHERE clause of an operator-editable query. On this
 * venue that is 193 current staff out of 1,547 rows.
 *
 * No database and no network — the guard reads SQL text and nothing else.
 *
 *   php anniversaries/tests/test_roster_guard.php
 */

$root = dirname(dirname(__DIR__));
require_once $root . '/lib/roster_guard.php';

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

/** Shorthand: does this query read as filtered to current staff? */
function guarded(string $sql): bool
{
    return RosterGuard::employmentFilter($sql)['ok'];
}

// ---------------------------------------------------------------------------
section('the queries actually shipped');

// Both bots' defaults, verified against this venue: EmpStatus = 1 is 'Active'
// (193 of 1,547) and no active row carries a DateOfTerminate.
$annivDefault = "SELECT EmpNo AS emp_no,
       FirstName AS first_name,
       LastName AS last_name,
       CONVERT(VARCHAR(10), DateOfHire, 120) AS hire_date
FROM CenterEdge.dbo.Employees
WHERE EmpStatus = 1
  AND DateOfTerminate IS NULL
  AND DateOfHire IS NOT NULL
  AND YEAR(DateOfHire) >= 1901";
$bdayDefault = str_replace(['DateOfHire', 'hire_date'], ['DateOfBirth', 'birth_date'], $annivDefault);

ok('the anniversary default is guarded', guarded($annivDefault));
ok('the birthday default is guarded',    guarded($bdayDefault));
is_eq('and both report the same two columns',
    RosterGuard::employmentFilter($annivDefault)['found'],
    RosterGuard::employmentFilter($bdayDefault)['found']);
is_eq('named so an operator recognises them',
    RosterGuard::employmentFilter($annivDefault)['found'], ['EmpStatus', 'DateOfTerminate']);

// What discover.php generates: bracket-quoted, and annotated with comments.
ok('discover.php output is guarded', guarded(
    "SELECT [EmpNo] AS emp_no FROM [dbo].[Employees]\n"
    . "WHERE [EmpStatus] = 1  /* 'Active' */\n"
    . "  AND [DateOfTerminate] IS NULL  /* agrees with the status flag */\n"
    . "  AND [DateOfHire] IS NOT NULL"));
is_eq('...and the comment text is not reported as a column',
    RosterGuard::employmentFilter(
        "SELECT a FROM E WHERE [EmpStatus] = 1  /* 'Active' */ AND [DateOfTerminate] IS NULL")['found'],
    ['EmpStatus', 'DateOfTerminate']);

// ---------------------------------------------------------------------------
section('what must NOT pass');

// The case the old check missed entirely: it only asked whether the word WHERE
// appeared anywhere, so this sailed through and would have greeted 1,350
// leavers by name.
ok('a WHERE with no employment filter is caught',
    !guarded("SELECT a FROM Employees WHERE DateOfHire IS NOT NULL AND YEAR(DateOfHire) >= 1901"));
ok('no WHERE at all is caught', !guarded("SELECT a FROM Employees"));

// Only the WHERE clause can limit rows. A SELECT list or a table name that
// merely mentions status proves nothing.
ok('a status column in the SELECT list does not count',
    !guarded("SELECT EmpStatus, DateOfTerminate, DateOfHire FROM Employees WHERE DateOfHire IS NOT NULL"));
ok('a commented-out filter does not count',
    !guarded("SELECT a FROM Employees WHERE DateOfHire IS NOT NULL /* AND EmpStatus = 1 */"));
ok('...including a line comment',
    !guarded("SELECT a FROM Employees WHERE DateOfHire IS NOT NULL -- AND EmpStatus = 1"));

// ---------------------------------------------------------------------------
section('what must pass');

ok('a status flag alone',       guarded("SELECT a FROM Employees WHERE EmpStatus = 1"));
ok('a termination date alone',  guarded("SELECT a FROM Employees WHERE DateOfTerminate IS NULL"));
// The venue's column naming is CamelCase with no separators, so the match has
// to be a substring, not an exact name.
ok('an abbreviated status column', guarded("SELECT a FROM Staff WHERE EmpStus = 'A'"));
ok('a snake_case status column',   guarded("SELECT a FROM staff WHERE emp_status = 1"));
ok('a boolean active flag',        guarded("SELECT a FROM Staff WHERE IsActive = 1"));
ok('a soft-delete flag',           guarded("SELECT a FROM Staff WHERE Deleted = 0"));
ok('an end-date column',           guarded("SELECT a FROM Staff WHERE EndDate IS NULL"));
ok('a subquery naming a status',
    guarded("SELECT a FROM E WHERE EmpNo IN (SELECT EmpNo FROM S WHERE Status = 1)"));
// A non-numeric status code is a real shape (an install with 'A' = Active) and
// must not confuse the reader — this is the same case discover.php now quotes
// rather than casting to int.
is_eq('a char status code is reported by its column name',
    RosterGuard::employmentFilter("SELECT a FROM Staff WHERE EmpStus = 'A'")['found'], ['EmpStus']);

// ---------------------------------------------------------------------------
section('the summary is fit to show an operator');

foreach ([$annivDefault, "SELECT a FROM Employees", "SELECT a FROM E WHERE DateOfHire IS NOT NULL"] as $i => $sql) {
    $r = RosterGuard::employmentFilter($sql);
    ok('case ' . $i . ': summary is a non-empty sentence', trim($r['summary']) !== '');
    ok('case ' . $i . ': found is a list of strings',
        $r['found'] === array_values(array_filter($r['found'], 'is_string')));
}

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 50) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
