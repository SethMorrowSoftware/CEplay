<?php
/**
 * Find the employee roster, the birthday column and the "still employed"
 * column in the venue's CenterEdge MSSQL database — and print a ready-to-paste
 * roster query for config.php.
 *
 * This is the Database Explorer's job, done from the shell so the answer comes
 * back as one page of text instead of a dozen clicks. Everything it runs is a
 * plain SELECT through the app's read-only MssqlClient (single-SELECT guarded),
 * so it cannot modify the POS database. See EXPLORER-QUERIES.md for the same
 * probes as copy/paste SQL if you would rather use the web UI.
 *
 * Venue server only — the sandbox has no MSSQL driver.
 *
 * Usage:
 *   php birthdays/discover.php                 # probe and recommend
 *   php birthdays/discover.php --table=Foo     # force a specific table
 *   php birthdays/discover.php --show-years    # print full dates of birth
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
$forceTable = isset($opts['table']) && is_string($opts['table']) ? $opts['table'] : '';
$showYears  = !empty($opts['show-years']);

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function d_ident(string $name): string { return '[' . str_replace(']', '', $name) . ']'; }
function d_lit(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }
function d_hdr(string $s): void { echo "\n" . str_repeat('=', 74) . "\n" . $s . "\n" . str_repeat('=', 74) . "\n"; }
function d_sub(string $s): void { echo "\n-- " . $s . " " . str_repeat('-', max(0, 68 - strlen($s))) . "\n"; }

/** Print rows as an aligned table. */
function d_table(array $rows, array $cols = []): void
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

/** Column-name classifiers. */
function d_norm(string $s): string { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); }

function d_isBirthCol(string $name): bool
{
    $n = d_norm($name);
    foreach (['birth', 'bday', 'dob', 'dateofbirth'] as $needle) {
        if (strpos($n, $needle) !== false) { return true; }
    }
    return false;
}

function d_isNameCol(string $name): bool
{
    $n = d_norm($name);
    return in_array($n, ['firstname', 'fname', 'lastname', 'lname', 'fullname', 'employeename', 'name', 'surname', 'givenname'], true);
}

/**
 * Status-ish: a flag that might say "still employed".
 *
 * The substring test matters more than the exact list: this database names
 * things in CamelCase with no separators, so `EmpStus` has to match just as
 * `Emp_Stus` and `STATUS` do. "stus" is a distinctive enough abbreviation that
 * a substring match doesn't produce false positives.
 */
function d_isStatusCol(string $name): bool
{
    $n = d_norm($name);
    if (in_array($n, ['active', 'isactive', 'inactive', 'retired', 'terminated', 'employed', 'deleted', 'disabled'], true)) {
        return true;
    }
    return strpos($n, 'stus') !== false || strpos($n, 'status') !== false;
}

/** A date that might mark the END of employment. */
function d_isTermDateCol(string $name): bool
{
    $n = d_norm($name);
    foreach (['termdate', 'terminationdate', 'termination', 'enddate', 'dateterminated', 'separationdate', 'quitdate', 'firedate'] as $needle) {
        if ($n === $needle || strpos($n, $needle) !== false) { return true; }
    }
    return false;
}

function d_isEmpNoCol(string $name): bool
{
    $n = d_norm($name);
    return in_array($n, ['empno', 'employeeno', 'employeeid', 'empid', 'employeenumber', 'empnum'], true);
}

function d_isDateType(string $t): bool
{
    return in_array(strtolower($t), ['date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'], true);
}

/** Guest/party tables that carry birthdays but are NOT staff. */
function d_looksLikeGuestTable(string $t): bool
{
    $n = d_norm($t);
    // 'cust' rather than 'customer': this database's guest side is CustPasses,
    // CustVisits, CustSales — all of them people who visit, not people who work here.
    foreach (['cust', 'group', 'party', 'player', 'member', 'guest', 'booking', 'reservation', 'lead', 'contact', 'waiver'] as $needle) {
        if (strpos($n, $needle) !== false) { return true; }
    }
    return false;
}

/** How strongly does this table look like the STAFF roster? */
function d_staffScore(string $table, array $cols): int
{
    $n = d_norm($table);
    $score = 0;
    if (strpos($n, 'employee') !== false) { $score += 60; }
    elseif (strpos($n, 'staff') !== false) { $score += 55; }
    elseif (strpos($n, 'personnel') !== false) { $score += 55; }
    elseif (preg_match('/(^|[^a-z])emp/', $n)) { $score += 40; }
    if (strpos($n, 'timeclock') !== false) { $score += 25; }
    if (strpos($n, 'cashier') !== false) { $score += 15; }
    if (d_looksLikeGuestTable($table)) { $score -= 70; }

    foreach ($cols as $c) {
        if (d_isEmpNoCol($c['col'])) { $score += 20; }
        if (d_isStatusCol($c['col'])) { $score += 15; }
        if (d_isNameCol($c['col'])) { $score += 5; }
        if (d_norm($c['col']) === 'payrate' || strpos(d_norm($c['col']), 'hiredate') !== false) { $score += 15; }
    }
    return $score;
}

// ---------------------------------------------------------------------------
// Connect
// ---------------------------------------------------------------------------

d_hdr('CEplay birthday bot — employee / birthday schema discovery');

$drivers = MssqlClient::availableDrivers();
echo "MSSQL PDO drivers in this runtime: " . ($drivers ? implode(', ', $drivers) : '(none)') . "\n";
if (!$drivers) {
    echo "\nNo MSSQL driver here. Run this on the venue server, inside the pdo_dblib\n"
       . "overlay image the app already uses:\n\n"
       . "  podman run --rm --network host --env-file /etc/pause-groups.env \\\n"
       . "      -v /opt/pause-groups:/opt/pause-groups:z -w /opt/pause-groups -u 33:33 \\\n"
       . "      localhost/ceplay-app:mssql php birthdays/discover.php\n\n"
       . "(Adjust the paths/image name to match your install — they are the same ones\n"
       . "update.sh uses to run run_backfills.php.)\n";
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
    $probe = $client->rows('SELECT DB_NAME() AS db, @@VERSION AS ver', 1);
    echo "Connected. Database: " . ($probe[0]['db'] ?? '?') . "\n";
} catch (Exception $e) {
    echo "\nCould not connect: " . $e->getMessage() . "\n";
    exit(2);
}

/** Run a SELECT, returning [] and printing the error rather than dying. */
function d_rows(MssqlClient $client, string $sql, int $limit = 200): array
{
    try {
        return $client->rows($sql, $limit);
    } catch (Exception $e) {
        echo "   ! query failed: " . trim($e->getMessage()) . "\n";
        return [];
    }
}

// ---------------------------------------------------------------------------
// 1. Every birthday-ish column in the whole database
// ---------------------------------------------------------------------------

d_hdr('1. Columns anywhere in the database that look like a date of birth');

$birthCols = d_rows($client, "SELECT c.TABLE_SCHEMA AS sch, c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col, c.DATA_TYPE AS typ
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.COLUMN_NAME LIKE '%birth%' OR c.COLUMN_NAME LIKE '%bday%' OR c.COLUMN_NAME LIKE '%dob%'
ORDER BY c.TABLE_NAME, c.COLUMN_NAME", 300);

if (!$birthCols) {
    echo "   No birthday-like column found by name anywhere in this database.\n"
       . "   The roster may store it under a name this probe doesn't recognise —\n"
       . "   browse the employee table's columns in the Database Explorer.\n";
} else {
    $shown = [];
    foreach ($birthCols as $r) {
        $shown[] = [
            'table'  => $r['tbl'],
            'column' => $r['col'],
            'type'   => $r['typ'],
            'note'   => d_looksLikeGuestTable((string)$r['tbl']) ? 'guests/parties — not staff' : '',
        ];
    }
    d_table($shown, ['table', 'column', 'type', 'note']);
}

// ---------------------------------------------------------------------------
// 2. Candidate STAFF tables
// ---------------------------------------------------------------------------

d_hdr('2. Candidate employee/roster tables');

$nameLike = "(t.TABLE_NAME LIKE '%employ%' OR t.TABLE_NAME LIKE 'Emp%' OR t.TABLE_NAME LIKE '%staff%'
   OR t.TABLE_NAME LIKE '%personnel%' OR t.TABLE_NAME LIKE 'TimeClock%' OR t.TABLE_NAME LIKE '%cashier%')";

$candTables = d_rows($client, "SELECT t.TABLE_SCHEMA AS sch, t.TABLE_NAME AS tbl, t.TABLE_TYPE AS kind
FROM INFORMATION_SCHEMA.TABLES t
WHERE {$nameLike}
ORDER BY t.TABLE_NAME", 200);

// Anything that owns a birthday column is a candidate too, even if its name
// says nothing — that is how the roster gets found when it's called something
// unexpected.
$byName = [];
foreach ($candTables as $r) { $byName[(string)$r['tbl']] = (string)$r['sch']; }
foreach ($birthCols as $r) { $byName[(string)$r['tbl']] = (string)$r['sch']; }

if (!$byName) {
    echo "   Nothing matched. Try: php birthdays/discover.php --table=YourTableName\n";
    exit(1);
}

// Pull every column of every candidate in ONE query.
//
// The read-only guard rejects a query containing a write keyword ANYWHERE,
// including inside a string literal — so a table literally named something
// like "EmpCreateLog" would otherwise take the whole column sweep down with
// it. Drop those from the IN list and say so, rather than losing the probe.
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
$inList = implode(', ', array_map(function ($t) { return d_lit($t); }, $safeNames));
$allCols = d_rows($client, "SELECT c.TABLE_SCHEMA AS sch, c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col,
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
    $birth = null; $status = []; $term = []; $empNo = null; $names = [];
    foreach ($cols as $c) {
        if ($birth === null && d_isBirthCol($c['col'])) { $birth = $c; }
        if (d_isStatusCol($c['col'])) { $status[] = $c['col']; }
        if (d_isTermDateCol($c['col'])) { $term[] = $c['col']; }
        if ($empNo === null && d_isEmpNoCol($c['col'])) { $empNo = $c['col']; }
        if (d_isNameCol($c['col'])) { $names[] = $c['col']; }
    }
    $score = d_staffScore($tbl, $cols) + ($birth ? 50 : 0);
    $ranked[] = [
        'table'   => $tbl,
        'sch'     => $info['sch'],
        'score'   => $score,
        'birth'   => $birth,
        'status'  => $status,
        'term'    => $term,
        'emp_no'  => $empNo,
        'names'   => $names,
        'cols'    => $cols,
    ];
}
usort($ranked, function ($a, $b) { return $b['score'] <=> $a['score']; });

$summary = [];
foreach (array_slice($ranked, 0, 20) as $r) {
    $summary[] = [
        'table'      => $r['table'],
        'birthday'   => $r['birth'] ? $r['birth']['col'] : '-',
        'emp_no'     => $r['emp_no'] ?: '-',
        'name_cols'  => $r['names'] ? implode(',', array_slice($r['names'], 0, 3)) : '-',
        'status_cols' => $r['status'] ? implode(',', $r['status']) : '-',
        'term_cols'  => $r['term'] ? implode(',', $r['term']) : '-',
        'score'      => $r['score'],
    ];
}
d_table($summary, ['table', 'birthday', 'emp_no', 'name_cols', 'status_cols', 'term_cols', 'score']);

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
        if ($r['birth'] !== null && !d_looksLikeGuestTable($r['table'])) { $chosen = $r; break; }
    }
    if (!$chosen) { $chosen = $ranked[0]; }
}

// ---------------------------------------------------------------------------
// 3. The chosen table, in detail
// ---------------------------------------------------------------------------

d_hdr('3. Best candidate: ' . $chosen['sch'] . '.' . $chosen['table']);

$T = d_ident($chosen['sch']) . '.' . d_ident($chosen['table']);

$colRows = [];
foreach ($chosen['cols'] as $c) {
    $tags = [];
    if (d_isBirthCol($c['col']))    { $tags[] = 'BIRTHDAY'; }
    if (d_isStatusCol($c['col']))   { $tags[] = 'status?'; }
    if (d_isTermDateCol($c['col'])) { $tags[] = 'term-date?'; }
    if (d_isEmpNoCol($c['col']))    { $tags[] = 'emp no'; }
    if (d_isNameCol($c['col']))     { $tags[] = 'name'; }
    $colRows[] = ['column' => $c['col'], 'type' => $c['typ'], 'looks like' => implode(' ', $tags)];
}
d_sub('Columns');
d_table($colRows, ['column', 'type', 'looks like']);

$cnt = d_rows($client, "SELECT COUNT(*) AS n FROM {$T}", 1);
$total = (int)($cnt[0]['n'] ?? 0);
echo "\n   Rows: " . number_format($total) . "\n";

if (!$chosen['birth']) {
    echo "\n   This table has no birthday column, so the bot has nothing to read.\n"
       . "   Re-run with --table= pointing at a table from section 1.\n";
    exit(1);
}

$birthCol  = $chosen['birth']['col'];
$birthType = $chosen['birth']['typ'];
// A birthday stored as text still works, but every date function needs a
// conversion wrapped around it — and TRY_CONVERT so one bad row can't throw.
$B = d_isDateType($birthType) ? d_ident($birthCol) : 'TRY_CONVERT(DATE, ' . d_ident($birthCol) . ')';
if (!d_isDateType($birthType)) {
    echo "   Note: {$birthCol} is {$birthType}, not a date type — using TRY_CONVERT(DATE, …).\n";
}

$firstCol = null; $lastCol = null; $fullCol = null;
foreach ($chosen['names'] as $n) {
    $k = d_norm($n);
    if ($firstCol === null && in_array($k, ['firstname', 'fname', 'givenname'], true)) { $firstCol = $n; }
    if ($lastCol === null && in_array($k, ['lastname', 'lname', 'surname'], true))     { $lastCol = $n; }
    if ($fullCol === null && in_array($k, ['fullname', 'employeename', 'name'], true)) { $fullCol = $n; }
}
$exampleCol = $lastCol ?? $firstCol ?? $fullCol;

// -- status column value distributions -------------------------------------
d_sub('What the status column(s) actually contain');
if (!$chosen['status']) {
    echo "   No status-like column on this table.\n";
}
foreach ($chosen['status'] as $stCol) {
    echo "\n   " . $stCol . ":\n";
    $exSel = $exampleCol
        ? ", MIN(" . d_ident($exampleCol) . ") AS example_a, MAX(" . d_ident($exampleCol) . ") AS example_b"
        : '';
    $rows = d_rows($client, "SELECT TOP 20 " . d_ident($stCol) . " AS value, COUNT(*) AS people{$exSel}
FROM {$T}
GROUP BY " . d_ident($stCol) . "
ORDER BY COUNT(*) DESC", 20);
    d_table($rows);
    echo "   -> The value with the most people is usually 'still employed'. Confirm by\n"
       . "      checking one of the example names against someone you know is current.\n";
}

foreach ($chosen['term'] as $tCol) {
    echo "\n   " . $tCol . " (a date here usually means 'no longer employed'):\n";
    $rows = d_rows($client, "SELECT COUNT(*) AS rows_total,
       SUM(CASE WHEN " . d_ident($tCol) . " IS NULL THEN 1 ELSE 0 END) AS term_is_null,
       SUM(CASE WHEN " . d_ident($tCol) . " IS NOT NULL THEN 1 ELSE 0 END) AS term_is_set
FROM {$T}", 1);
    d_table($rows);
}

// -- birthday data quality --------------------------------------------------
d_sub('Birthday data quality (' . $birthCol . ')');

$q = d_rows($client, "SELECT COUNT(*) AS rows_total,
       SUM(CASE WHEN {$B} IS NULL THEN 1 ELSE 0 END) AS birthday_missing,
       SUM(CASE WHEN {$B} IS NOT NULL THEN 1 ELSE 0 END) AS birthday_present,
       SUM(CASE WHEN {$B} IS NOT NULL AND YEAR({$B}) < 1901 THEN 1 ELSE 0 END) AS placeholder_year
FROM {$T}", 1);
d_table($q);

echo "\n   Most-repeated exact dates (a big count = a placeholder, not a birthday):\n";
$dupes = d_rows($client, "SELECT TOP 12 CONVERT(VARCHAR(10), {$B}, 120) AS birth_date, COUNT(*) AS people
FROM {$T}
WHERE {$B} IS NOT NULL
GROUP BY CONVERT(VARCHAR(10), {$B}, 120)
ORDER BY COUNT(*) DESC", 12);
d_table($dupes);
echo "   -> Add any date with an implausible count to ignore_birth_dates in config.php.\n"
   . "      (1900-01-01 and friends are already ignored by default.)\n";

echo "\n   Spread by birth month (a flat-ish shape means real data):\n";
$months = d_rows($client, "SELECT MONTH({$B}) AS birth_month, COUNT(*) AS people
FROM {$T}
WHERE {$B} IS NOT NULL AND YEAR({$B}) >= 1901
GROUP BY MONTH({$B})
ORDER BY MONTH({$B})", 12);
d_table($months);

// -- sample rows ------------------------------------------------------------
d_sub('Sample rows');
$sel = [];
if ($chosen['emp_no']) { $sel[] = d_ident($chosen['emp_no']) . ' AS emp_no'; }
if ($firstCol) { $sel[] = d_ident($firstCol) . ' AS first_name'; }
if ($lastCol)  { $sel[] = d_ident($lastCol) . ' AS last_name'; }
if (!$firstCol && !$lastCol && $fullCol) { $sel[] = d_ident($fullCol) . ' AS full_name'; }
$sel[] = "CONVERT(VARCHAR(10), {$B}, 120) AS birth_date";
foreach ($chosen['status'] as $stCol) { $sel[] = d_ident($stCol) . ' AS ' . d_ident($stCol); }

$sample = d_rows($client, "SELECT TOP 15 " . implode(', ', $sel) . "
FROM {$T}
WHERE {$B} IS NOT NULL AND YEAR({$B}) >= 1901
ORDER BY " . ($exampleCol ? d_ident($exampleCol) : '1'), 15);
if (!$showYears) {
    foreach ($sample as &$r) {
        if (isset($r['birth_date']) && strlen((string)$r['birth_date']) >= 10) {
            $r['birth_date'] = '••••' . substr((string)$r['birth_date'], 4);
        }
    }
    unset($r);
    echo "   (birth years masked — pass --show-years to print them in full)\n";
}
d_table($sample);

// -- who would be wished today ---------------------------------------------
d_sub('Birthdays today (' . date('Y-m-d') . ')');
$todaySel = $sel;
$todayRows = d_rows($client, "SELECT " . implode(', ', $todaySel) . "
FROM {$T}
WHERE {$B} IS NOT NULL AND YEAR({$B}) >= 1901
  AND MONTH({$B}) = MONTH(GETDATE()) AND DAY({$B}) = DAY(GETDATE())", 50);
if (!$showYears) {
    foreach ($todayRows as &$r2) {
        if (isset($r2['birth_date']) && strlen((string)$r2['birth_date']) >= 10) {
            $r2['birth_date'] = '••••' . substr((string)$r2['birth_date'], 4);
        }
    }
    unset($r2);
}
d_table($todayRows);
echo "   (all rows, ignoring employment status — the status filter is yours to set below)\n";

// ---------------------------------------------------------------------------
// 4. The generated roster query
// ---------------------------------------------------------------------------

d_hdr('4. Suggested roster_sql for birthdays/config.php');

$statusCol = $chosen['status'][0] ?? null;
$where = ["{$B} IS NOT NULL", "YEAR({$B}) >= 1901"];
if ($statusCol !== null) {
    $where[] = d_ident($statusCol) . " = 1  /* CHECK THIS against section 3 */";
} elseif ($chosen['term']) {
    $where[] = d_ident($chosen['term'][0]) . " IS NULL  /* still employed */";
}

$selectList = [];
$selectList[] = ($chosen['emp_no'] ? d_ident($chosen['emp_no']) : "''") . ' AS emp_no';
if ($firstCol) { $selectList[] = d_ident($firstCol) . ' AS first_name'; }
if ($lastCol)  { $selectList[] = d_ident($lastCol) . ' AS last_name'; }
if (!$firstCol && !$lastCol && $fullCol) { $selectList[] = d_ident($fullCol) . ' AS full_name'; }
$selectList[] = "CONVERT(VARCHAR(10), {$B}, 120) AS birth_date";

$sql = "SELECT " . implode(",\n       ", $selectList) . "\nFROM {$T}\nWHERE " . implode("\n  AND ", $where);

echo "\nCopy this into birthdays/config.php as 'roster_sql':\n\n";
echo "    'roster_sql' => <<<SQL\n";
foreach (explode("\n", $sql) as $line) { echo $line . "\n"; }
echo "SQL,\n";

echo "\nBefore trusting it, confirm two things from section 3:\n";
if ($statusCol !== null) {
    echo "  1. That " . $statusCol . " = 1 really means 'still employed' at this venue —\n"
       . "     the value distribution above shows which value most people have, but only\n"
       . "     you can say which one is current staff.\n";
} else {
    echo "  1. How this table marks someone as no longer employed — no status column was\n"
       . "     detected, so the query above has NO employment filter yet. Add one.\n";
}
echo "  2. That the most-repeated dates are real birthdays and not placeholders.\n";
echo "\nThen: php birthdays/birthday_bot.php --list      (preview the next 60 days)\n";
echo "      php birthdays/birthday_bot.php --dry-run   (build today's message, post nothing)\n\n";
