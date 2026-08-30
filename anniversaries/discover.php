<?php
/**
 * Find the employee roster, the HIRE-DATE column and the "still employed"
 * column in the venue's CenterEdge MSSQL database — and print a ready-to-paste
 * roster query.
 *
 * This is the one script to run first. The birthday bot's roster columns are
 * verified against this venue (dbo.Employees, DateOfBirth, EmpStatus = 1), but
 * the hire-date column is NOT: the shipped default guesses `DateOfHire` from
 * the naming of its neighbours. If that guess is wrong, MSSQL says "Invalid
 * column name" and nothing posts — this tells you what to write instead.
 *
 * It also does something the birthday version has no need for: it CHECKS the
 * candidate column against the birth-date column. A hire date and a date of
 * birth look identical to a name-matcher, and picking the wrong one would post
 * "Happy 41st anniversary" to a public channel. Any column whose values agree
 * with the birth date is rejected outright and said so.
 *
 * Everything it runs is a plain SELECT through the app's read-only MssqlClient
 * (single-SELECT guarded), so it cannot modify the POS database.
 *
 * Venue server only — the sandbox has no MSSQL driver. Run it through
 * anniversaries/run.sh, which uses the pdo_dblib overlay image:
 *
 *   sudo bash anniversaries/run.sh discover
 *
 * Usage:
 *   php anniversaries/discover.php                 # probe and recommend
 *   php anniversaries/discover.php --table=Foo     # force a specific table
 *   php anniversaries/discover.php --column=Bar    # force a specific hire column
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/db.php';
require_once $root . '/lib/crypto.php';
require_once $root . '/lib/mssql_client.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$forceTable  = isset($opts['table']) && is_string($opts['table']) ? $opts['table'] : '';
$forceColumn = isset($opts['column']) && is_string($opts['column']) ? $opts['column'] : '';

// ---------------------------------------------------------------------------
// Small helpers (the same shape as birthdays/discover.php, so the two outputs
// read alike)
// ---------------------------------------------------------------------------

function a_ident(string $name): string { return '[' . str_replace(']', '', $name) . ']'; }
function a_lit(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }
function a_hdr(string $s): void { echo "\n" . str_repeat('=', 74) . "\n" . $s . "\n" . str_repeat('=', 74) . "\n"; }
function a_sub(string $s): void { echo "\n-- " . $s . " " . str_repeat('-', max(0, 68 - strlen($s))) . "\n"; }

/** Print rows as an aligned table. */
function a_table(array $rows, array $cols = []): void
{
    if (!$rows) { echo "   (no rows)\n"; return; }
    if (!$cols) { $cols = array_keys($rows[0]); }
    $w = [];
    foreach ($cols as $c) { $w[$c] = strlen((string)$c); }
    foreach ($rows as $r) {
        foreach ($cols as $c) { $w[$c] = max($w[$c], strlen((string)($r[$c] ?? ''))); }
    }
    $line = '   ';
    foreach ($cols as $c) { $line .= str_pad((string)$c, $w[$c] + 2); }
    echo rtrim($line) . "\n   " . str_repeat('-', max(1, array_sum($w) + 2 * count($cols) - 2)) . "\n";
    foreach ($rows as $r) {
        $line = '   ';
        foreach ($cols as $c) { $line .= str_pad((string)($r[$c] ?? ''), $w[$c] + 2); }
        echo rtrim($line) . "\n";
    }
}

function a_norm(string $s): string { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); }

/** A date that might mark the START of employment. */
function a_isHireCol(string $name): bool
{
    $n = a_norm($name);
    // Never a birth date, and never the END of employment — both are dates on
    // the same table and both would produce a confidently wrong number.
    foreach (['birth', 'bday', 'dob', 'term', 'quit', 'separat', 'end'] as $bad) {
        if (strpos($n, $bad) !== false) { return false; }
    }
    foreach (['hire', 'hired', 'startdate', 'datestart', 'employmentdate', 'dateemployed',
              'seniority', 'anniversary', 'doh'] as $needle) {
        if (strpos($n, $needle) !== false) { return true; }
    }
    return false;
}

/** A rehire date is real, but it is the wrong answer more often than not. */
function a_isRehireCol(string $name): bool
{
    return strpos(a_norm($name), 'rehire') !== false;
}

function a_isBirthCol(string $name): bool
{
    $n = a_norm($name);
    foreach (['birth', 'bday', 'dob'] as $needle) {
        if (strpos($n, $needle) !== false) { return true; }
    }
    return false;
}

function a_isNameCol(string $name): bool
{
    $n = a_norm($name);
    return in_array($n, ['firstname', 'fname', 'lastname', 'lname', 'fullname',
                         'employeename', 'name', 'surname', 'givenname'], true);
}

/**
 * Status-ish: a flag that might say "still employed".
 *
 * The substring test matters more than the exact list: this database names
 * things in CamelCase with no separators, so `EmpStus` has to match just as
 * `Emp_Stus` and `STATUS` do.
 */
function a_isStatusCol(string $name): bool
{
    $n = a_norm($name);
    if (in_array($n, ['active', 'isactive', 'inactive', 'retired', 'terminated',
                      'employed', 'deleted', 'disabled'], true)) {
        return true;
    }
    return strpos($n, 'stus') !== false || strpos($n, 'status') !== false;
}

/** A date that might mark the END of employment. */
function a_isTermDateCol(string $name): bool
{
    $n = a_norm($name);
    foreach (['termdate', 'terminationdate', 'termination', 'dateofterminate', 'enddate',
              'dateterminated', 'separationdate', 'quitdate'] as $needle) {
        if ($n === $needle || strpos($n, $needle) !== false) { return true; }
    }
    return false;
}

/** A human-readable label column on a lookup table. */
function a_isDescriptionCol(string $name): bool
{
    $n = a_norm($name);
    return in_array($n, ['description', 'descr', 'desc', 'name', 'statusname',
                         'statusdescription', 'title', 'label', 'text'], true);
}

/**
 * Does this description read as "currently employed"?
 *
 * Returned as a rank so the best label wins: a bare "Active" beats "Active -
 * Leave of Absence", and anything terminated/suspended scores nothing.
 */
function a_activeLabelRank(string $desc): int
{
    $n = a_norm($desc);
    if ($n === '') { return 0; }
    foreach (['terminated', 'termed', 'suspended', 'inactive', 'separated', 'quit',
              'fired', 'retired', 'leave', 'loa', 'deleted', 'archived'] as $bad) {
        if (strpos($n, $bad) !== false) { return 0; }
    }
    if ($n === 'active' || $n === 'current' || $n === 'employed') { return 100; }
    if (strpos($n, 'active') === 0 || strpos($n, 'current') === 0) { return 80; }
    if (strpos($n, 'active') !== false || strpos($n, 'employed') !== false) { return 60; }
    return 0;
}

function a_isEmpNoCol(string $name): bool
{
    $n = a_norm($name);
    return in_array($n, ['empno', 'employeeno', 'employeeid', 'empid', 'employeenumber', 'empnum'], true);
}

function a_isDateType(string $t): bool
{
    return in_array(strtolower($t), ['date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'], true);
}

/** Guest/party tables that carry dates but are NOT staff. */
function a_looksLikeGuestTable(string $t): bool
{
    $n = a_norm($t);
    foreach (['cust', 'group', 'party', 'player', 'member', 'guest', 'booking',
              'reservation', 'lead', 'contact', 'waiver'] as $needle) {
        if (strpos($n, $needle) !== false) { return true; }
    }
    return false;
}

/** How strongly does this table look like the STAFF roster? */
function a_staffScore(string $table, array $cols): int
{
    $n = a_norm($table);
    $score = 0;
    if (strpos($n, 'employee') !== false) { $score += 60; }
    elseif (strpos($n, 'staff') !== false) { $score += 55; }
    elseif (strpos($n, 'personnel') !== false) { $score += 55; }
    elseif (preg_match('/(^|[^a-z])emp/', $n)) { $score += 40; }
    if (strpos($n, 'timeclock') !== false) { $score += 25; }
    if (strpos($n, 'cashier') !== false) { $score += 15; }
    if (a_looksLikeGuestTable($table)) { $score -= 70; }

    foreach ($cols as $c) {
        if (a_isEmpNoCol($c['col'])) { $score += 20; }
        if (a_isStatusCol($c['col'])) { $score += 15; }
        if (a_isNameCol($c['col'])) { $score += 5; }
        if (a_norm($c['col']) === 'payrate') { $score += 15; }
        if (a_isHireCol($c['col'])) { $score += 15; }
    }
    return $score;
}

// ---------------------------------------------------------------------------
// Connect
// ---------------------------------------------------------------------------

a_hdr('CEplay work-anniversary bot — hire-date schema discovery');

$drivers = MssqlClient::availableDrivers();
echo "MSSQL PDO drivers in this runtime: " . ($drivers ? implode(', ', $drivers) : '(none)') . "\n";
if (!$drivers) {
    echo "\nNo MSSQL driver here. Run this on the venue server, inside the pdo_dblib\n"
       . "overlay image the app already uses:\n\n"
       . "  sudo bash anniversaries/run.sh discover\n\n";
    exit(2);
}
if (!MssqlClient::isConfigured()) {
    echo "\nMSSQL is not configured yet. Set it up in the app: Go-Kart Labor page ->\n"
       . "Settings -> connection, then re-run this script.\n";
    exit(2);
}

$s = MssqlClient::settings();
echo "Connecting to {$s['host']}:{$s['port']} / {$s['database']} as {$s['username']} ...\n";

try {
    $client = new MssqlClient();
    $client->setTimeout(30);
    $probe = $client->rows('SELECT DB_NAME() AS db', 1);
    echo "Connected. Database: " . ($probe[0]['db'] ?? '?') . "\n";
} catch (Exception $e) {
    echo "\nCould not connect: " . $e->getMessage() . "\n";
    exit(2);
}

/** Run a SELECT, returning [] and printing the error rather than dying. */
function a_rows(MssqlClient $client, string $sql, int $limit = 200): array
{
    try {
        return $client->rows($sql, $limit);
    } catch (Exception $e) {
        echo "   ! query failed: " . trim($e->getMessage()) . "\n";
        return [];
    }
}

// ---------------------------------------------------------------------------
// 1. Every hire-date-ish column in the whole database
// ---------------------------------------------------------------------------

a_hdr('1. Columns anywhere in the database that look like a hire date');

$hireCols = a_rows($client, "SELECT c.TABLE_SCHEMA AS sch, c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col, c.DATA_TYPE AS typ
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.COLUMN_NAME LIKE '%hire%' OR c.COLUMN_NAME LIKE '%employ%'
   OR c.COLUMN_NAME LIKE '%start%' OR c.COLUMN_NAME LIKE '%seniority%'
ORDER BY c.TABLE_NAME, c.COLUMN_NAME", 400);

$hireCols = array_values(array_filter($hireCols, function ($r) {
    return a_isHireCol((string)$r['col']) || a_isRehireCol((string)$r['col']);
}));

if (!$hireCols) {
    echo "   No hire-date-like column found by name anywhere in this database.\n"
       . "   The roster may store it under a name this probe doesn't recognise —\n"
       . "   browse the employee table's columns in the Database Explorer, then\n"
       . "   re-run with --table= and --column=.\n";
} else {
    $shown = [];
    foreach ($hireCols as $r) {
        $note = [];
        if (a_looksLikeGuestTable((string)$r['tbl'])) { $note[] = 'guests/parties — not staff'; }
        if (a_isRehireCol((string)$r['col']))          { $note[] = 'REHIRE date'; }
        if (!a_isDateType((string)$r['typ']))          { $note[] = 'not a date type'; }
        $shown[] = [
            'table'  => $r['tbl'],
            'column' => $r['col'],
            'type'   => $r['typ'],
            'note'   => implode('; ', $note),
        ];
    }
    a_table($shown, ['table', 'column', 'type', 'note']);
}

// ---------------------------------------------------------------------------
// 2. Candidate STAFF tables
// ---------------------------------------------------------------------------

a_hdr('2. Candidate employee/roster tables');

$nameLike = "(t.TABLE_NAME LIKE '%employ%' OR t.TABLE_NAME LIKE 'Emp%' OR t.TABLE_NAME LIKE '%staff%'
   OR t.TABLE_NAME LIKE '%personnel%' OR t.TABLE_NAME LIKE 'TimeClock%' OR t.TABLE_NAME LIKE '%cashier%')";

$candTables = a_rows($client, "SELECT t.TABLE_SCHEMA AS sch, t.TABLE_NAME AS tbl
FROM INFORMATION_SCHEMA.TABLES t
WHERE {$nameLike}
ORDER BY t.TABLE_NAME", 200);

// Anything that owns a hire-date column is a candidate too, even if its name
// says nothing — that is how the roster gets found when it's called something
// unexpected.
$byName = [];
foreach ($candTables as $r) { $byName[(string)$r['tbl']] = (string)$r['sch']; }
foreach ($hireCols as $r)   { $byName[(string)$r['tbl']] = (string)$r['sch']; }

if (!$byName) {
    echo "   Nothing matched. Try: php anniversaries/discover.php --table=YourTableName\n";
    exit(1);
}

// Pull every column of every candidate in ONE query.
//
// The read-only guard rejects a query containing a write keyword ANYWHERE,
// including inside a string literal — so a table literally named something like
// "EmpCreateLog" would otherwise take the whole column sweep down with it. Drop
// those from the IN list and say so, rather than losing the probe.
$guardUnsafe = [];
$safeNames = [];
foreach (array_keys($byName) as $t) {
    if (preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE|GRANT|REVOKE|INTO)\b/i', $t)) {
        $guardUnsafe[] = $t;
    } else {
        $safeNames[] = $t;
    }
}
if ($guardUnsafe) {
    echo "\n   Note: skipping " . implode(', ', $guardUnsafe) . " — the read-only guard\n"
       . "   rejects queries mentioning those names. Inspect them in the Explorer instead.\n";
}
if (!$safeNames) {
    echo "   No inspectable candidates left.\n";
    exit(1);
}
$inList = implode(', ', array_map(function ($t) { return a_lit($t); }, $safeNames));
$allCols = a_rows($client, "SELECT c.TABLE_SCHEMA AS sch, c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col,
       c.DATA_TYPE AS typ, c.ORDINAL_POSITION AS pos
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_NAME IN ({$inList})
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION", 4000);

$tables = [];
foreach ($allCols as $r) {
    $tables[(string)$r['tbl']]['sch'] = (string)$r['sch'];
    $tables[(string)$r['tbl']]['cols'][] = ['col' => (string)$r['col'], 'typ' => (string)$r['typ']];
}

$ranked = [];
foreach ($tables as $tbl => $info) {
    $cols = $info['cols'] ?? [];
    $hire = []; $birth = null; $status = []; $term = []; $empNo = null; $names = [];
    foreach ($cols as $c) {
        if (a_isHireCol($c['col']) || a_isRehireCol($c['col'])) { $hire[] = $c; }
        if ($birth === null && a_isBirthCol($c['col'])) { $birth = $c; }
        if (a_isStatusCol($c['col'])) { $status[] = $c['col']; }
        if (a_isTermDateCol($c['col'])) { $term[] = $c['col']; }
        if ($empNo === null && a_isEmpNoCol($c['col'])) { $empNo = $c['col']; }
        if (a_isNameCol($c['col'])) { $names[] = $c['col']; }
    }
    $ranked[] = [
        'table'  => $tbl,
        'sch'    => $info['sch'],
        'score'  => a_staffScore($tbl, $cols) + ($hire ? 50 : 0),
        'hire'   => $hire,
        'birth'  => $birth,
        'status' => $status,
        'term'   => $term,
        'emp_no' => $empNo,
        'names'  => $names,
        'cols'   => $cols,
    ];
}
usort($ranked, function ($a, $b) { return $b['score'] <=> $a['score']; });

$summary = [];
foreach (array_slice($ranked, 0, 20) as $r) {
    $summary[] = [
        'table'       => $r['table'],
        'hire_cols'   => $r['hire'] ? implode(',', array_column($r['hire'], 'col')) : '-',
        'emp_no'      => $r['emp_no'] ?: '-',
        'name_cols'   => $r['names'] ? implode(',', array_slice($r['names'], 0, 3)) : '-',
        'status_cols' => $r['status'] ? implode(',', $r['status']) : '-',
        'term_cols'   => $r['term'] ? implode(',', $r['term']) : '-',
        'score'       => $r['score'],
    ];
}
a_table($summary, ['table', 'hire_cols', 'emp_no', 'name_cols', 'status_cols', 'term_cols', 'score']);

// Pick the winner.
$chosen = null;
if ($forceTable !== '') {
    foreach ($ranked as $r) {
        if (strcasecmp($r['table'], $forceTable) === 0) { $chosen = $r; break; }
    }
    if (!$chosen) {
        echo "\n   --table={$forceTable} did not match any table above.\n";
        exit(1);
    }
} else {
    foreach ($ranked as $r) {
        if ($r['hire'] && !a_looksLikeGuestTable($r['table'])) { $chosen = $r; break; }
    }
    if (!$chosen) { $chosen = $ranked[0]; }
}

// ---------------------------------------------------------------------------
// 3. The chosen table, in detail
// ---------------------------------------------------------------------------

a_hdr('3. Best candidate: ' . $chosen['sch'] . '.' . $chosen['table']);

$T = a_ident($chosen['sch']) . '.' . a_ident($chosen['table']);

$colRows = [];
foreach ($chosen['cols'] as $c) {
    $tags = [];
    if (a_isHireCol($c['col']))     { $tags[] = 'HIRE DATE?'; }
    if (a_isRehireCol($c['col']))   { $tags[] = 'rehire?'; }
    if (a_isBirthCol($c['col']))    { $tags[] = 'birthday (NOT this)'; }
    if (a_isStatusCol($c['col']))   { $tags[] = 'status?'; }
    if (a_isTermDateCol($c['col'])) { $tags[] = 'term-date?'; }
    if (a_isEmpNoCol($c['col']))    { $tags[] = 'emp no'; }
    if (a_isNameCol($c['col']))     { $tags[] = 'name'; }
    $colRows[] = ['column' => $c['col'], 'type' => $c['typ'], 'looks like' => implode(' ', $tags)];
}
a_sub('Columns');
a_table($colRows, ['column', 'type', 'looks like']);

$cnt = a_rows($client, "SELECT COUNT(*) AS n FROM {$T}", 1);
$total = (int)($cnt[0]['n'] ?? 0);
echo "\n   Rows: " . number_format($total) . "\n";

if (!$chosen['hire']) {
    echo "\n   This table has no hire-date column, so the bot has nothing to read.\n"
       . "   Re-run with --table= pointing at a table from section 1, or --column=\n"
       . "   naming a column this probe didn't recognise.\n";
    exit(1);
}

// A hire date stored as text still works, but every date function needs a
// conversion wrapped around it — and TRY_CONVERT so one bad row can't throw.
$dateExpr = function (array $c): string {
    return a_isDateType($c['typ'])
        ? a_ident($c['col'])
        : 'TRY_CONVERT(DATE, ' . a_ident($c['col']) . ')';
};

$birthExpr = $chosen['birth'] ? $dateExpr($chosen['birth']) : null;

// ---------------------------------------------------------------------------
// 3b. Which hire column is actually usable
//
// The whole point of this section. A column can be named perfectly and be
// unpopulated, or full of future dates, or — the dangerous one — hold the same
// values as the date of birth, which would put "Happy 41st anniversary" in a
// public channel.
// ---------------------------------------------------------------------------

a_sub('Hire-date candidates, measured');

$measured = [];
foreach ($chosen['hire'] as $c) {
    $H = $dateExpr($c);
    $sel = "SELECT COUNT(*) AS rows_total,
       SUM(CASE WHEN {$H} IS NULL THEN 1 ELSE 0 END) AS missing,
       SUM(CASE WHEN {$H} IS NOT NULL AND YEAR({$H}) < 1901 THEN 1 ELSE 0 END) AS placeholder,
       SUM(CASE WHEN {$H} > GETDATE() THEN 1 ELSE 0 END) AS future_dated,
       MIN(CASE WHEN YEAR({$H}) >= 1901 THEN YEAR({$H}) END) AS first_year,
       MAX(CASE WHEN YEAR({$H}) >= 1901 THEN YEAR({$H}) END) AS last_year";
    if ($birthExpr !== null) {
        $sel .= ",\n       SUM(CASE WHEN {$H} IS NOT NULL AND {$H} = {$birthExpr} THEN 1 ELSE 0 END) AS same_as_birthday";
    }
    $q = a_rows($client, $sel . "\nFROM {$T}", 1);
    if (!$q) { continue; }
    $r = $q[0];
    $rowsTotal = (int)$r['rows_total'];
    $present = $rowsTotal - (int)$r['missing'];
    $sameBirth = isset($r['same_as_birthday']) ? (int)$r['same_as_birthday'] : 0;

    // Scoring: population is the main thing, everything else is a penalty. The
    // birthday collision is fatal rather than merely costly — a column that
    // agrees with the date of birth is the date of birth.
    $score = $present > 0 ? (int)round(100 * $present / max(1, $rowsTotal)) : 0;
    $why = [];
    if ($present === 0) { $why[] = 'never populated'; $score = -100; }
    if ($present > 0 && $sameBirth > 0 && $sameBirth >= 0.5 * $present) {
        $why[] = 'MATCHES THE BIRTH DATE — this is not a hire date';
        $score = -200;
    } elseif ($sameBirth > 0) {
        $why[] = $sameBirth . ' row(s) equal the birth date';
        $score -= 10;
    }
    if ((int)$r['future_dated'] > 0) {
        $why[] = (int)$r['future_dated'] . ' future-dated';
        $score -= 5;
    }
    if ((int)$r['placeholder'] > 0) {
        $why[] = (int)$r['placeholder'] . ' pre-1901 placeholder(s)';
        $score -= 5;
    }
    if (a_isRehireCol($c['col'])) {
        $why[] = 'a rehire date — usually not the one you want';
        $score -= 40;
    }

    $measured[] = [
        'row' => [
            'column'      => $c['col'],
            'populated'   => $present . ' / ' . $rowsTotal,
            'years'       => ($r['first_year'] ?? '?') . '-' . ($r['last_year'] ?? '?'),
            'future'      => (int)$r['future_dated'],
            '= birthday'  => $birthExpr === null ? 'n/a' : $sameBirth,
            'verdict'     => $why ? implode('; ', $why) : 'looks usable',
        ],
        'col' => $c,
        'score' => $score,
    ];
}
a_table(array_column($measured, 'row'),
    ['column', 'populated', 'years', 'future', '= birthday', 'verdict']);
if ($birthExpr === null) {
    echo "\n   NOTE: no birth-date column on this table, so the birthday cross-check could\n"
       . "   not run. Read the sample rows below before trusting the column.\n";
}

usort($measured, function ($a, $b) { return $b['score'] <=> $a['score']; });

$hireCol = null;
if ($forceColumn !== '') {
    foreach ($measured as $m) {
        if (strcasecmp($m['col']['col'], $forceColumn) === 0) { $hireCol = $m['col']; break; }
    }
    if (!$hireCol) {
        echo "\n   --column={$forceColumn} is not a candidate on this table.\n";
        exit(1);
    }
} else {
    $hireCol = $measured && $measured[0]['score'] > -100 ? $measured[0]['col'] : null;
}
if (!$hireCol) {
    echo "\n   None of these columns is usable as a hire date. Look at section 1 for a\n"
       . "   different table, or point at a column by hand with --column=.\n";
    exit(1);
}
$H = $dateExpr($hireCol);
echo "\n   -> Using " . $hireCol['col'] . ".\n";
if (!a_isDateType($hireCol['typ'])) {
    echo "      ({$hireCol['col']} is {$hireCol['typ']}, not a date type — using TRY_CONVERT(DATE, …).)\n";
}

$firstCol = null; $lastCol = null; $fullCol = null;
foreach ($chosen['names'] as $n) {
    $k = a_norm($n);
    if ($firstCol === null && in_array($k, ['firstname', 'fname', 'givenname'], true)) { $firstCol = $n; }
    if ($lastCol === null && in_array($k, ['lastname', 'lname', 'surname'], true))     { $lastCol = $n; }
    if ($fullCol === null && in_array($k, ['fullname', 'employeename', 'name'], true)) { $fullCol = $n; }
}
$exampleCol = $lastCol ?? $firstCol ?? $fullCol;

// -- status column value distributions -------------------------------------
a_sub('What the status column(s) actually contain');
if (!$chosen['status']) {
    echo "   No status-like column on this table.\n";
}

/** Status column -> the code that means "currently employed", once resolved. */
$activeCode = null;
$activeCodeCol = null;
$activeCodeWhy = '';

foreach ($chosen['status'] as $stCol) {
    echo "\n   " . $stCol . ":\n";

    // A code column is far more useful decoded. CenterEdge stores the labels in
    // a sibling lookup table carrying the SAME column name plus a description
    // (Employees.EmpStatus -> EmployeeStatus(EmpStatus, Description)), so look
    // for that shape rather than making the operator infer meaning from counts.
    $labels = [];
    $lookupName = '';
    $lookups = a_rows($client, "SELECT c.TABLE_SCHEMA AS sch, c.TABLE_NAME AS tbl, c2.COLUMN_NAME AS descr
FROM INFORMATION_SCHEMA.COLUMNS c
INNER JOIN INFORMATION_SCHEMA.COLUMNS c2
  ON c2.TABLE_SCHEMA = c.TABLE_SCHEMA AND c2.TABLE_NAME = c.TABLE_NAME
WHERE c.COLUMN_NAME = " . a_lit($stCol) . "
  AND c.TABLE_NAME <> " . a_lit($chosen['table']) . "
  AND c2.DATA_TYPE IN ('char', 'nchar', 'varchar', 'nvarchar')
ORDER BY c.TABLE_NAME", 200);
    foreach ($lookups as $lk) {
        if (!a_isDescriptionCol((string)$lk['descr'])) { continue; }
        $L = a_ident((string)$lk['sch']) . '.' . a_ident((string)$lk['tbl']);
        $got = a_rows($client, "SELECT TOP 50 " . a_ident($stCol) . " AS code, "
            . a_ident((string)$lk['descr']) . " AS label FROM {$L}", 50);
        if ($got) {
            foreach ($got as $g) { $labels[(string)$g['code']] = trim((string)$g['label']); }
            $lookupName = (string)$lk['tbl'];
            break;
        }
    }

    $rows = a_rows($client, "SELECT TOP 20 " . a_ident($stCol) . " AS value, COUNT(*) AS people
FROM {$T}
GROUP BY " . a_ident($stCol) . "
ORDER BY COUNT(*) DESC", 20);

    if ($labels) {
        echo "   (labels from " . $lookupName . ")\n";
        foreach ($rows as &$r) {
            $r = array_merge(
                ['value' => $r['value'], 'means' => $labels[(string)$r['value']] ?? '?'],
                array_diff_key($r, ['value' => 1])
            );
        }
        unset($r);
    }
    a_table($rows);

    if ($labels) {
        // Pick the code whose LABEL says employed — the counts are irrelevant
        // once the database itself tells you what each code means.
        $best = 0;
        foreach ($labels as $code => $label) {
            $rank = a_activeLabelRank($label);
            if ($rank > $best) {
                $best = $rank;
                $activeCode = $code;
                $activeCodeCol = $stCol;
                $activeCodeWhy = $label;
            }
        }
        if ($activeCode !== null) {
            echo "   -> " . $stCol . " = " . $activeCode . " means '" . $activeCodeWhy
               . "'. That is the employment filter.\n";
        } else {
            echo "   -> None of these labels reads as 'currently employed'. Pick the right\n"
               . "      code by hand.\n";
        }
    } else {
        echo "   -> No lookup table decodes " . $stCol . ", so the meaning of each value is\n"
           . "      not written down anywhere. The value with the most people is USUALLY\n"
           . "      current staff, but a long-lived database has more leavers than staff —\n"
           . "      check an example name against someone you know is on this week's rota.\n";
    }
}

// -- hire-date data quality -------------------------------------------------
a_sub('Hire-date data quality (' . $hireCol['col'] . ')');

echo "\n   Most-repeated exact dates (a big count = a placeholder, not a hire date):\n";
$dupes = a_rows($client, "SELECT TOP 12 CONVERT(VARCHAR(10), {$H}, 120) AS hire_date, COUNT(*) AS people
FROM {$T}
WHERE {$H} IS NOT NULL
GROUP BY CONVERT(VARCHAR(10), {$H}, 120)
ORDER BY COUNT(*) DESC", 12);
a_table($dupes);
echo "   -> Add any date with an implausible count to 'Ignore these hire dates' on the\n"
   . "      Anniversaries page. (1900-01-01 and friends are already ignored by default.)\n";

echo "\n   Years of service, as the bot would count them today:\n";
$years = a_rows($client, "SELECT DATEDIFF(YEAR, {$H}, GETDATE()) AS years_of_service, COUNT(*) AS people
FROM {$T}
WHERE {$H} IS NOT NULL AND YEAR({$H}) >= 1901
GROUP BY DATEDIFF(YEAR, {$H}, GETDATE())
ORDER BY DATEDIFF(YEAR, {$H}, GETDATE())", 80);
a_table($years);
echo "   -> A long tail of 20+ year figures on a seasonal roster usually means leavers\n"
   . "      are still included, i.e. the employment filter below is not tight enough.\n"
   . "      (This count ignores it — it is every row in the table.)\n";

// -- sample rows ------------------------------------------------------------
a_sub('Sample rows');
$sel = [];
if ($chosen['emp_no']) { $sel[] = a_ident($chosen['emp_no']) . ' AS emp_no'; }
if ($firstCol) { $sel[] = a_ident($firstCol) . ' AS first_name'; }
if ($lastCol)  { $sel[] = a_ident($lastCol) . ' AS last_name'; }
if (!$firstCol && !$lastCol && $fullCol) { $sel[] = a_ident($fullCol) . ' AS full_name'; }
$sel[] = "CONVERT(VARCHAR(10), {$H}, 120) AS hire_date";
foreach ($chosen['status'] as $stCol) { $sel[] = a_ident($stCol) . ' AS ' . a_ident($stCol); }

$sample = a_rows($client, "SELECT TOP 15 " . implode(', ', $sel) . "
FROM {$T}
WHERE {$H} IS NOT NULL AND YEAR({$H}) >= 1901
ORDER BY " . ($exampleCol ? a_ident($exampleCol) : '1'), 15);
a_table($sample);
echo "   (hire dates are shown in full — years of service are the point of this bot,\n"
   . "    unlike the birthday probe, which masks birth years.)\n";

// -- who would be congratulated today ---------------------------------------
a_sub('Anniversaries today (' . date('Y-m-d') . ')');
$todayRows = a_rows($client, "SELECT " . implode(', ', $sel) . ",
       DATEDIFF(YEAR, {$H}, GETDATE()) AS years_of_service
FROM {$T}
WHERE {$H} IS NOT NULL AND YEAR({$H}) >= 1901
  AND MONTH({$H}) = MONTH(GETDATE()) AND DAY({$H}) = DAY(GETDATE())
  AND DATEDIFF(YEAR, {$H}, GETDATE()) >= 1", 50);
a_table($todayRows);
echo "   (all rows, ignoring employment status — the status filter is yours to set below.\n"
   . "    Year zero is excluded, exactly as the bot excludes it: a start date is not an\n"
   . "    anniversary.)\n";

// ---------------------------------------------------------------------------
// 4. The generated roster query
// ---------------------------------------------------------------------------

a_hdr('4. Suggested roster query');

$statusCol = $activeCodeCol ?? ($chosen['status'][0] ?? null);
$where = [a_ident($hireCol['col']) . " IS NOT NULL", "YEAR({$H}) >= 1901"];
$filterKnown = false;

if ($activeCode !== null && $activeCodeCol !== null) {
    array_unshift($where, a_ident($activeCodeCol) . ' = ' . (int)$activeCode
        . "  /* '" . $activeCodeWhy . "' */");
    $filterKnown = true;
} elseif ($statusCol !== null) {
    array_unshift($where, a_ident($statusCol) . " = 1  /* UNVERIFIED — check section 3 */");
} elseif ($chosen['term']) {
    array_unshift($where, a_ident($chosen['term'][0]) . " IS NULL  /* still employed */");
    $filterKnown = true;
} else {
    // Nothing here says who still works at this venue. Emit the marker the bot
    // refuses to run on, rather than a query that quietly congratulates every
    // person who ever worked here.
    array_unshift($where, "TODO_CONFIRM_EMPLOYMENT_FILTER  /* no status or termination column found */");
}

// Belt-and-braces: if there's ALSO a termination date, only add it when the two
// actually agree. Measured, not assumed — a stale term date on an active record
// would otherwise silently drop that person from every anniversary.
if ($filterKnown && $activeCode !== null && $chosen['term']) {
    $tCol = $chosen['term'][0];
    $agree = a_rows($client, "SELECT COUNT(*) AS active_rows,
       SUM(CASE WHEN " . a_ident($tCol) . " IS NOT NULL THEN 1 ELSE 0 END) AS active_with_term_date
FROM {$T}
WHERE " . a_ident($activeCodeCol) . ' = ' . (int)$activeCode, 1);
    $withTerm = isset($agree[0]) ? (int)$agree[0]['active_with_term_date'] : -1;
    if ($withTerm === 0) {
        array_splice($where, 1, 0, [a_ident($tCol) . " IS NULL  /* agrees with the status flag */"]);
        echo "\n   " . $tCol . " agrees with " . $activeCodeCol . " (no active row carries a\n"
           . "   termination date), so both conditions are included — harmless either way.\n";
    } elseif ($withTerm > 0) {
        echo "\n   NOTE: " . $withTerm . " row(s) are marked '" . $activeCodeWhy . "' but DO carry a\n"
           . "   " . $tCol . ". The two disagree, so only the status flag is used below.\n"
           . "   If those people have really left, add: AND " . $tCol . " IS NULL\n";
    }
}

$selectList = [];
$selectList[] = ($chosen['emp_no'] ? a_ident($chosen['emp_no']) : "''") . ' AS emp_no';
if ($firstCol) { $selectList[] = a_ident($firstCol) . ' AS first_name'; }
if ($lastCol)  { $selectList[] = a_ident($lastCol) . ' AS last_name'; }
if (!$firstCol && !$lastCol && $fullCol) { $selectList[] = a_ident($fullCol) . ' AS full_name'; }
$selectList[] = "CONVERT(VARCHAR(10), {$H}, 120) AS hire_date";

$sql = "SELECT " . implode(",\n       ", $selectList) . "\nFROM {$T}\nWHERE " . implode("\n  AND ", $where);

echo "\nPaste this into the Anniversaries page, under \"Roster query\":\n\n";
foreach (explode("\n", $sql) as $line) { echo "    " . $line . "\n"; }
echo "\n(Or, for a file-only install, into data/anniversary_config.php as 'roster_sql'.)\n";

echo "\nBefore trusting it:\n";
if ($activeCode !== null) {
    echo "  1. The employment filter is decoded from the database's own lookup table\n"
       . "     (" . $activeCodeCol . " = " . $activeCode . " = '" . $activeCodeWhy . "'), so it is not a guess.\n"
       . "     Still worth one sanity check: does the count of matching staff look like\n"
       . "     the size of your actual team?\n";
} elseif ($statusCol !== null) {
    echo "  1. That " . $statusCol . " = 1 really means 'still employed' — NOTHING decoded it,\n"
       . "     so that value is a guess. Fix it before running the bot.\n";
} else {
    echo "  1. The query has NO employment filter — replace TODO_CONFIRM_EMPLOYMENT_FILTER\n"
       . "     with a real condition. The bot refuses to run until you do.\n";
}
echo "  2. That " . $hireCol['col'] . " really is when people STARTED, not when the record was\n"
   . "     created or when they were last rehired. The sample rows above are the check:\n"
   . "     find somebody you know and see whether the year matches when they joined.\n";
echo "  3. That the most-repeated dates are real hire dates and not placeholders.\n";
echo "\nThen: sudo bash anniversaries/run.sh --list      (preview the next 60 days)\n";
echo "      sudo bash anniversaries/run.sh --dry-run   (build today's message, post nothing)\n\n";
