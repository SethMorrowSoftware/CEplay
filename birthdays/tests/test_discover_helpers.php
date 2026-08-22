<?php
/**
 * Unit tests for the classifier / ranking helpers in birthdays/discover.php.
 *
 * discover.php connects to MSSQL as soon as it runs, so the helpers can't just
 * be require'd. Instead this extracts the pure-function block (everything
 * between `function d_ident` and the `// Connect` marker) and evaluates it —
 * which means the test always runs against the CURRENT source and cannot drift
 * out of sync with a copy.
 *
 *   php birthdays/tests/test_discover_helpers.php
 */

$src = file_get_contents(dirname(__DIR__) . '/discover.php');
if ($src === false) {
    fwrite(STDERR, "Could not read discover.php\n");
    exit(1);
}
$start = strpos($src, 'function d_ident');
$end   = strpos($src, '// Connect');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "Could not locate the helper block in discover.php — has it been restructured?\n"
        . "This test expects the pure helpers to sit between `function d_ident` and the `// Connect` marker.\n");
    exit(1);
}
eval(substr($src, $start, $end - $start));

$pass = 0;
$fail = 0;

function t(string $what, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  ok   {$what}\n";
    } else {
        $fail++;
        echo "  FAIL {$what} (got " . var_export($actual, true)
            . ", want " . var_export($expected, true) . ")\n";
    }
}

echo "\nbirthday columns\n";
t('BirthDate',            d_isBirthCol('BirthDate'), true);
t('birth_date',           d_isBirthCol('birth_date'), true);
t('DOB',                  d_isBirthCol('DOB'), true);
t('Birth_Dt',             d_isBirthCol('Birth_Dt'), true);
t('BDay',                 d_isBirthCol('BDay'), true);
t('HireDate is not one',  d_isBirthCol('HireDate'), false);
t('ClockInDate is not',   d_isBirthCol('ClockInDate'), false);

echo "\nstatus columns (CamelCase matters — this DB has no separators)\n";
t('Stus',                 d_isStatusCol('Stus'), true);
t('EmpStus',              d_isStatusCol('EmpStus'), true);
t('Emp_Stus',             d_isStatusCol('Emp_Stus'), true);
t('Status',               d_isStatusCol('Status'), true);
t('EmployeeStatus',       d_isStatusCol('EmployeeStatus'), true);
t('Active',               d_isStatusCol('Active'), true);
t('Retired',              d_isStatusCol('Retired'), true);
t('PayRate is not',       d_isStatusCol('PayRate'), false);
t('BirthDate is not',     d_isStatusCol('BirthDate'), false);

echo "\ntermination dates\n";
t('TermDate',             d_isTermDateCol('TermDate'), true);
t('TerminationDate',      d_isTermDateCol('TerminationDate'), true);
t('EndDate',              d_isTermDateCol('EndDate'), true);
t('BirthDate is not',     d_isTermDateCol('BirthDate'), false);
t('HireDate is not',      d_isTermDateCol('HireDate'), false);

echo "\nemployee numbers\n";
t('EmpNo',                d_isEmpNoCol('EmpNo'), true);
t('EmployeeID',           d_isEmpNoCol('EmployeeID'), true);
t('emp_no',               d_isEmpNoCol('emp_no'), true);
t('InvNo is not',         d_isEmpNoCol('InvNo'), false);
t('rdrkey is not',        d_isEmpNoCol('rdrkey'), false);

echo "\ndate types\n";
t('datetime',             d_isDateType('datetime'), true);
t('date',                 d_isDateType('date'), true);
t('smalldatetime',        d_isDateType('smalldatetime'), true);
t('varchar is not',       d_isDateType('varchar'), false);
t('int is not',           d_isDateType('int'), false);

echo "\nguest/party tables (they have birthdays too — and must not be picked)\n";
t('Customers',            d_looksLikeGuestTable('Customers'), true);
t('GroupBirthdays',       d_looksLikeGuestTable('GroupBirthdays'), true);
t('CustVisits',           d_looksLikeGuestTable('CustVisits'), true);
t('PlayerCardTrans',      d_looksLikeGuestTable('PlayerCardTrans'), true);
t('TimeClock_Employees',  d_looksLikeGuestTable('TimeClock_Employees'), false);
t('Employees',            d_looksLikeGuestTable('Employees'), false);
t('CustomersWithName',    d_looksLikeGuestTable('CustomersWithName'), true);
t('Customer_Waivers',     d_looksLikeGuestTable('Customer_Waivers'), true);
t('GroupChildren',        d_looksLikeGuestTable('GroupChildren'), true);
t('EmployeeStatus',       d_looksLikeGuestTable('EmployeeStatus'), false);

echo "\ntable ranking\n";
$empCols = [
    ['col' => 'EmpNo', 'typ' => 'int'], ['col' => 'FirstName', 'typ' => 'varchar'],
    ['col' => 'LastName', 'typ' => 'varchar'], ['col' => 'BirthDate', 'typ' => 'datetime'],
    ['col' => 'Stus', 'typ' => 'int'], ['col' => 'HireDate', 'typ' => 'datetime'],
    ['col' => 'PayRate', 'typ' => 'money'],
];
$partyCols = [
    ['col' => 'GroupNo', 'typ' => 'int'], ['col' => 'Name', 'typ' => 'varchar'],
    ['col' => 'BirthDate', 'typ' => 'datetime'], ['col' => 'PartyDate', 'typ' => 'datetime'],
];
$custCols = [
    ['col' => 'CustNo', 'typ' => 'int'], ['col' => 'FirstName', 'typ' => 'varchar'],
    ['col' => 'LastName', 'typ' => 'varchar'], ['col' => 'Birthday', 'typ' => 'datetime'],
];
$punchCols = [
    ['col' => 'EmpNo', 'typ' => 'int'], ['col' => 'ClockInDate', 'typ' => 'datetime'],
    ['col' => 'PayRate', 'typ' => 'money'],
];

// `Employees` is this venue's real roster table (confirmed August 2026);
// the prefixed variant covers other CenterEdge installs.
$roster = d_staffScore('Employees', $empCols);
$plain  = d_staffScore('TimeClock_Employees', $empCols);
$party  = d_staffScore('GroupBirthdays', $partyCols);
$cust   = d_staffScore('Customers', $custCols);
$punch  = d_staffScore('TimeClock_Weekly', $punchCols);

printf("  scores: Employees=%d TimeClock_Employees=%d TimeClock_Weekly=%d GroupBirthdays=%d Customers=%d\n",
    $roster, $plain, $punch, $party, $cust);

t('roster beats the party table', $roster > $party, true);
t('roster beats the customer table', $roster > $cust, true);
t('roster beats the punch table', $roster > $punch, true);
t('a prefixed roster table also wins', $plain > $cust, true);
t('guest tables score negative', $party < 0 && $cust < 0, true);

echo "\nlookup-table label columns\n";
t('Description',          d_isDescriptionCol('Description'), true);
t('Descr',                d_isDescriptionCol('Descr'), true);
t('StatusName',           d_isDescriptionCol('StatusName'), true);
t('Name',                 d_isDescriptionCol('Name'), true);
t('EmpStatus is not',     d_isDescriptionCol('EmpStatus'), false);
t('DateOfBirth is not',   d_isDescriptionCol('DateOfBirth'), false);

echo "\n\"currently employed\" label ranking\n";
t('Active wins outright',   d_activeLabelRank('Active'), 100);
t('Current',                d_activeLabelRank('Current'), 100);
t('Employed',               d_activeLabelRank('Employed'), 100);
// "Inactive" CONTAINS "active" — the exclusion has to be tested first, or the
// probe would recommend the exact code that means somebody has left.
t('Inactive scores zero',   d_activeLabelRank('Inactive'), 0);
t('Terminated scores zero', d_activeLabelRank('Terminated'), 0);
t('Suspended scores zero',  d_activeLabelRank('Suspended'), 0);
t('Retired scores zero',    d_activeLabelRank('Retired'), 0);
t('Leave of Absence zero',  d_activeLabelRank('Active - Leave of Absence'), 0);
t('empty label scores zero', d_activeLabelRank(''), 0);
t('Active Employee ranks',  d_activeLabelRank('Active Employee') > 0, true);
// This venue's real lookup: 1 Active, 2 Suspended, 3 Terminated.
t('Active beats Suspended', d_activeLabelRank('Active') > d_activeLabelRank('Suspended'), true);
t('Active beats Terminated', d_activeLabelRank('Active') > d_activeLabelRank('Terminated'), true);

echo "\nSQL helpers\n";
t('identifier bracketing', d_ident('BirthDate'), '[BirthDate]');
t('bracket injection stripped', d_ident('Bad]Name'), '[BadName]');
t('literal quoting', d_lit("O'Brien"), "'O''Brien'");

echo "\n" . str_repeat('-', 50) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
