<?php
/**
 * Go-Kart Labor API — sales vs labor cost per day, straight from the
 * venue's CenterEdge MSSQL database (Sales + TimeClock_Weekly tables).
 *
 *   GET  /api/labor/settings  — connection + query config (settings perm; password never returned)
 *   PUT  /api/labor/settings  — save config (settings perm; password optional = keep current)
 *   POST /api/labor/test      — connect and run both queries for today (settings perm)
 *   GET  /api/labor/rate?dates=YYYY-MM-DD,... — per-day sales/labor/rate (analytics + view_revenue)
 *
 * The two queries are admin-editable SQL with required :from/:to
 * placeholders (inclusive day range), guarded to a single SELECT (see
 * MssqlClient::assertReadOnly). Defaults are this venue's proven buckets:
 * DivNo 808 "Go Kart Readers" for sales; the 'Go-Karts' time-clock join
 * for wages.
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';
// Reuses the Performance/Reader-Groups daily stitch (perfDailyPerGame etc.)
// to count kart swipes and time-pass swipes per day from the app's own feed.
require_once __DIR__ . '/analytics.php';

// The venue's proven buckets, settled live in July 2026:
//  - SALES: the POS books the REAL dollars spent at the kart readers under
//    DivNo 808 "Go Kart Readers" (one aggregated posting per day; verified
//    against the operator's own figures, e.g. $5,797.24 on 2026-07-11).
//    Time-pass swipes never post money there, so passes are excluded by
//    the accounting itself. The Go Karts sales CATEGORY (108) posts rides
//    at $0 — it is NOT the money bucket.
//  - WAGES: TimeClock_Weekly joined to TimeClock_JobCodes on Description
//    'Go-Karts' (job code 3 at this venue).
// ShiftDate/ClockInDate are filtered as half-open day ranges — correct for
// DATE, midnight-DATETIME, and timestamped columns alike (equality matched
// only midnight rows on this install and read zero).
//
// One round-trip per range: a Year view costs the same two queries as a
// single day.
//
// Sales: one (day, total) row per day with money. CONVERT(...,120) yields
// 'YYYY-MM-DD' strings, uniform across dblib/odbc/sqlsrv drivers.
const LABOR_DEFAULT_SALES_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), ShiftDate, 120) AS day, COALESCE(SUM(AmtSold), 0) AS total\nFROM [CenterEdge].[dbo].[Sales]\nWHERE DivNo = 808  /* \"Go Kart Readers\" division: real dollars spent at the kart readers */\n  AND ShiftDate >= :from\n  AND ShiftDate < DATEADD(DAY, 1, :to)\nGROUP BY CONVERT(VARCHAR(10), ShiftDate, 120)";

// Labor: THE authority for wage dollars. The exact expression the proven
// per-day query used — PayRate × DATEDIFF seconds, an UNCLOSED punch
// accrues to CURRENT_TIMESTAMP only when opened today and counts zero on
// past days — computed LIVE by the database, grouped by clock-in day.
// One (day, total) row per day with wages.
const LABOR_DEFAULT_LABOR_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), ClockInDate, 120) AS day, COALESCE(SUM(\n  PayRate * DATEDIFF(\n    SECOND,\n    ClockInDate + CAST(CAST(ClockInTime AS TIME) AS DATETIME),\n    CASE\n      WHEN ClockOutDate IS NOT NULL\n        THEN ClockOutDate + CAST(CAST(ClockOutTime AS TIME) AS DATETIME)\n      WHEN ClockInDate = CAST(GETDATE() AS DATE)\n        THEN CURRENT_TIMESTAMP\n      /* unclosed punch on a PAST day: broken data — count zero hours */\n      ELSE ClockInDate + CAST(CAST(ClockInTime AS TIME) AS DATETIME)\n    END\n  ) / 3600.0\n), 0) AS total\nFROM CenterEdge.dbo.TimeClock_Weekly\nINNER JOIN CenterEdge.dbo.TimeClock_JobCodes\n  ON TimeClock_Weekly.JobCode = TimeClock_JobCodes.JobCode\nWHERE TimeClock_JobCodes.Description = 'Go-Karts'  /* the kart crew (job code 3 at this venue) */\n  AND ClockInDate >= :from\n  AND ClockInDate < DATEADD(DAY, 1, :to)\nGROUP BY CONVERT(VARCHAR(10), ClockInDate, 120)";

// HOURLY MONEY: an earlier hour-of-day wages/sales panel was removed because
// the Sales table books kart money once per day and the split was an estimate.
// A genuine hourly source has since been found: the card ledger
// (PlayerCardTrans) records every kart swipe with a TRUE clock time
// (TransDateTime) and the REAL dollars deducted (DollarAmount), under the same
// DivNo 808 the daily posting reconciles to. Wages-by-hour are equally real:
// each punch has clock-in/out times and a PayRate, so PHP splits PayRate
// across the wall-clock hours the punch spans. Both feed the "money vs wages
// by the hour" panel and the weekday × hour heatmap below — no estimates.
//
// Hourly kart revenue: one row per (local day, local hour) with real dollars
// and swipe count. TransDateTime is the POS's venue-local clock.
const LABOR_DEFAULT_HOURLY_SALES_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), TransDateTime, 120) AS day,\n       DATEPART(HOUR, TransDateTime) AS hour,\n       SUM(DollarAmount) AS dollars,\n       COUNT(*) AS plays\nFROM [CenterEdge].[dbo].[PlayerCardTrans]\nWHERE TransType = 1  /* plays: value deducted at a reader */\n  AND DivNo = 808    /* \"Go Kart Readers\" division — same bucket the daily sales query reads */\n  AND TransDateTime >= :from\n  AND TransDateTime < DATEADD(DAY, 1, :to)\nGROUP BY CONVERT(VARCHAR(10), TransDateTime, 120), DATEPART(HOUR, TransDateTime)";

// Raw kart-crew punches for the range: one row per punch with local dates and
// wall-clock times (CONVERT(...,108) = 'HH:MM:SS', uniform across drivers).
// An unclosed punch returns NULL day_out/time_out; PHP applies the same
// convention as the daily wages query (accrues to now only when opened today).
const LABOR_DEFAULT_PUNCHES_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), ClockInDate, 120) AS day,\n       CONVERT(VARCHAR(8), CAST(ClockInTime AS TIME), 108) AS time_in,\n       CASE WHEN ClockOutDate IS NOT NULL THEN CONVERT(VARCHAR(10), ClockOutDate, 120) END AS day_out,\n       CASE WHEN ClockOutDate IS NOT NULL THEN CONVERT(VARCHAR(8), CAST(ClockOutTime AS TIME), 108) END AS time_out,\n       PayRate AS pay_rate\nFROM CenterEdge.dbo.TimeClock_Weekly\nINNER JOIN CenterEdge.dbo.TimeClock_JobCodes\n  ON TimeClock_Weekly.JobCode = TimeClock_JobCodes.JobCode\nWHERE TimeClock_JobCodes.Description = 'Go-Karts'  /* the kart crew */\n  AND ClockInDate >= :from\n  AND ClockInDate < DATEADD(DAY, 1, :to)";

// Share of the daily sales total the hourly ledger must cover before the panel
// is reported as complete. The two sources are never identical — not every
// posting to the sales division carries a card-ledger dollar amount — so a few
// points of shortfall is normal and expected (June 2026 measured 95.6%). Below
// this, the bars understate the period enough that the panel says so.
const LABOR_RECONCILE_TOLERANCE = 0.90;

function handleLabor(string $method, array $parts, ?array $input): void {
    $action = $parts[0] ?? '';

    switch ($action) {
        case 'settings':
            if ($method === 'GET') { laborGetSettings(); return; }
            if ($method === 'PUT') { laborPutSettings($input ?? []); return; }
            break;
        case 'test':
            if ($method === 'POST') { laborTest($input); return; }
            break;
        case 'rate':
            if ($method === 'GET') { laborRate(); return; }
            break;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Unknown labor endpoint']);
}

function laborQueries(): array {
    return [
        'sales_range_sql'        => DB::getConfig('labor_sales_range_sql') ?: LABOR_DEFAULT_SALES_RANGE_SQL,
        'labor_range_sql'        => DB::getConfig('labor_labor_range_sql') ?: LABOR_DEFAULT_LABOR_RANGE_SQL,
        'hourly_sales_range_sql' => DB::getConfig('labor_hourly_sales_range_sql') ?: LABOR_DEFAULT_HOURLY_SALES_RANGE_SQL,
        'punches_range_sql'      => DB::getConfig('labor_punches_range_sql') ?: LABOR_DEFAULT_PUNCHES_RANGE_SQL,
    ];
}

/**
 * Per-day sales map from the range query's (day, total) rows, tolerant of
 * column naming (named keys any case, else first two columns).
 *
 * @return array<string,float> 'YYYY-MM-DD' => dollars
 */
function laborSalesMapFromRows(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $day = null; $total = null;
        foreach ($row as $k => $v) {
            if (strcasecmp((string)$k, 'day') === 0) $day = $v;
            if (strcasecmp((string)$k, 'total') === 0) $total = $v;
        }
        if ($day === null)   $day = $vals[0] ?? null;
        if ($total === null) $total = $vals[1] ?? null;
        $raw = trim((string)$day);
        $day = substr($raw, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            // Hand-edited queries sometimes return the day as a raw
            // DATETIME, which dblib renders like "Jul 14 2026 12:00AM" —
            // parse it rather than silently dropping the row (which would
            // read as a day of zero sales).
            $ts = strtotime($raw);
            if ($ts === false) continue;
            $day = date('Y-m-d', $ts);
        }
        $map[$day] = ($map[$day] ?? 0.0) + (float)$total;
    }
    return $map;
}

/**
 * Which reader-group area counts as "the karts". That's the whole config now:
 * every dollar on this page comes from the live POS queries, so there is no
 * price list and no rides × price estimate to configure. (An "estimate mode"
 * that valued paid rides at a per-track price shipped here once; it was
 * removed — the page shows recorded dollars only.)
 */
function laborRideConfig(): array {
    $gid = DB::getConfig('labor_reader_group_id');
    return [
        'reader_group_id' => ($gid !== null && $gid !== '') ? (int)$gid : null,
    ];
}

/**
 * Per-day swipe counts for the configured go-kart reader group, from the
 * app's OWN feed (game_daily_stats + raw transactions via the same stitch
 * the Reader Groups page uses). Knows used_time_play per swipe, so the
 * paid-ride count can omit time-pass swipes — which the MSSQL Sales lines
 * cannot distinguish.
 *
 * Counts only — these never become dollars.
 *
 * @param string[] $dates ISO dates
 * @return array<string,array{rides:int,pass:int}> keyed by date
 */
function laborRideStats(array $dates, int $groupId): array {
    $members = array_map(function ($r) { return (string)$r['game_id']; }, DB::query(
        'SELECT game_id FROM reader_group_games WHERE reader_group_id = :p0', [$groupId]));
    $out = [];
    if (!$members || !$dates) {
        return $out;
    }
    $memberSet = array_flip($members);
    sort($dates);
    list($tz) = perfTimezone();
    $daily = perfDailyPerGame($dates[0], $dates[count($dates) - 1], $tz);
    foreach ($dates as $d) {
        $rides = 0; $pass = 0;
        foreach (($daily['byDate'][$d] ?? []) as $gid => $b) {
            if (!isset($memberSet[$gid])) continue;
            $rides += (int)($b['plays'] ?? 0);
            $pass  += (int)($b['time_plays'] ?? 0);
        }
        $out[$d] = ['rides' => $rides, 'pass' => $pass];
    }
    return $out;
}

function laborGetSettings(): void {
    Auth::requireAccess('settings');
    $out = MssqlClient::settings();
    $out += laborQueries();
    $out += laborRideConfig();
    $out['configured'] = MssqlClient::isConfigured();
    $out['defaults'] = [
        'sales_range_sql'        => LABOR_DEFAULT_SALES_RANGE_SQL,
        'labor_range_sql'        => LABOR_DEFAULT_LABOR_RANGE_SQL,
        'hourly_sales_range_sql' => LABOR_DEFAULT_HOURLY_SALES_RANGE_SQL,
        'punches_range_sql'      => LABOR_DEFAULT_PUNCHES_RANGE_SQL,
    ];
    // For the "count rides from this area" dropdown — read directly so the
    // settings gate alone suffices.
    $out['reader_groups'] = DB::query('SELECT id, name FROM reader_groups ORDER BY name');
    echo json_encode($out);
}

function laborPutSettings(array $input): void {
    Auth::requireAccess('settings');

    $host = Validator::requireString($input, 'host', 255);
    $port = Validator::optionalInt($input, 'port', 1, 65535) ?? 1433;
    $database = Validator::requireString($input, 'database', 128);
    $username = Validator::requireString($input, 'username', 128);

    // Password optional on update: blank keeps the stored one.
    $password = isset($input['password']) ? (string)$input['password'] : '';
    if ($password === '' && DB::getConfig('mssql_password') === null) {
        throw new RuntimeException('A password is required for the first save.');
    }

    // The two range queries are what the page runs; validate NOW so a bad
    // save fails loudly, not at 9 PM when someone opens the report.
    $salesRangeSql = Validator::requireString($input, 'sales_range_sql', 4000);
    $laborRangeSql = Validator::requireString($input, 'labor_range_sql', 4000);
    MssqlClient::assertReadOnly($salesRangeSql);
    MssqlClient::assertReadOnly($laborRangeSql);
    MssqlClient::bindRange($salesRangeSql, '2000-01-01', '2000-01-02');
    MssqlClient::bindRange($laborRangeSql, '2000-01-01', '2000-01-02');

    // Hourly-money queries: validated the same way, but optional in the payload
    // (absent = leave the stored value alone) so an older client can't blank them.
    $hourlyQueryUpdates = [];
    foreach (['hourly_sales_range_sql' => 'labor_hourly_sales_range_sql',
              'punches_range_sql'      => 'labor_punches_range_sql'] as $inKey => $cfgKey) {
        if (array_key_exists($inKey, $input)) {
            $sql = Validator::requireString($input, $inKey, 4000);
            MssqlClient::assertReadOnly($sql);
            MssqlClient::bindRange($sql, '2000-01-01', '2000-01-02');
            $hourlyQueryUpdates[$cfgKey] = $sql;
        }
    }



    // Kart area: validate BEFORE the first write so a rejected field can never
    // leave a half-saved config (observed: a bad reader_group_id used to 422
    // after the connection settings had already committed).
    $groupIdToStore = null; // null = don't touch, '' = clear, else id string
    if (array_key_exists('reader_group_id', $input)) {
        $gid = $input['reader_group_id'];
        if ($gid === null || $gid === '' || (int)$gid === 0) {
            $groupIdToStore = '';
        } else {
            $gid = (int)$gid;
            $exists = DB::queryOne('SELECT id FROM reader_groups WHERE id = :p0', [$gid]);
            if (!$exists) {
                throw new RuntimeException('Unknown reader group for the go-kart area.');
            }
            $groupIdToStore = (string)$gid;
        }
    }

    // ---- Everything validated; write it all ----
    DB::setConfig('mssql_host', $host);
    DB::setConfig('mssql_port', (string)$port);
    DB::setConfig('mssql_database', $database);
    DB::setConfig('mssql_username', $username);
    if ($password !== '') {
        DB::setConfig('mssql_password', Crypto::encrypt($password));
    }
    DB::setConfig('labor_sales_range_sql', $salesRangeSql);
    DB::setConfig('labor_labor_range_sql', $laborRangeSql);
    foreach ($hourlyQueryUpdates as $cfgKey => $sql) {
        DB::setConfig($cfgKey, $sql);
    }
    if ($groupIdToStore !== null) DB::setConfig('labor_reader_group_id', $groupIdToStore);

    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'labor_settings_update',
             'MSSQL labor settings updated by ' . (Auth::check()['username'] ?? '?')]
        );
    } catch (Exception $e) {
        error_log('labor settings audit log failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'configured' => MssqlClient::isConfigured()]);
}

function laborTest(?array $input = null): void {
    // Runs the live MSSQL queries and returns wage/dollar figures + a
    // division-dollar fingerprint — gate on data_explorer (raw POS data),
    // not settings.
    Auth::requireAccess('data_explorer');
    $client = new MssqlClient();
    $tz = new DateTimeZone(DB::getConfig('timezone') ?: DEFAULT_TIMEZONE);
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    // The fingerprint can be aimed at any date — essential when chasing a
    // known figure ("7/11 total was $5,797.24") through categories and
    // divisions. Defaults to yesterday (a complete business day).
    $probeDate = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
    if ($input && isset($input['probe_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['probe_date'])) {
        $probeDate = (string)$input['probe_date'];
    }
    $yesterday = $probeDate;
    $q = laborQueries();
    try {
        $client->connect();
        // Exercise the queries the page actually runs, scoped to today.
        // Sales and labor dollars both come straight from the database.
        $salesMap = laborSalesMapFromRows(
            $client->rows(MssqlClient::bindRange($q['sales_range_sql'], $today, $today), 10));
        $sales = $salesMap[$today] ?? 0.0;
        $laborMap = laborSalesMapFromRows(
            $client->rows(MssqlClient::bindRange($q['labor_range_sql'], $today, $today), 10));
        $labor = $laborMap[$today] ?? 0.0;

        // Fingerprint the connection so "works in Grafana, wrong here" can
        // be settled with facts: WHICH server/instance/database is the app
        // actually reading, how fresh is its Sales data, and where does the
        // money live by category? Each probe is independent — one failing
        // doesn't hide the rest.
        $diag = [];
        $probe = function (string $label, string $sql) use (&$diag, $client) {
            try {
                $diag[$label] = $client->scalarText($sql);
            } catch (Exception $e) {
                $diag[$label] = 'error: ' . $e->getMessage();
            }
        };
        $probe('server',   'SELECT @@SERVERNAME');
        $probe('database', 'SELECT DB_NAME()');
        $probe('sales_latest_shiftdate', 'SELECT CONVERT(VARCHAR(19), MAX(ShiftDate), 120) FROM [CenterEdge].[dbo].[Sales]');
        $probe('sales_rows_last_7_days', 'SELECT COUNT(*) FROM [CenterEdge].[dbo].[Sales] WHERE ShiftDate >= DATEADD(DAY, -7, GETDATE())');
        $probe('sales_yesterday_cat108', MssqlClient::bindDate(
            "SELECT CONVERT(VARCHAR(32), COALESCE(SUM(AmtSold), 0)) FROM [CenterEdge].[dbo].[Sales] WHERE CatNo = 108 AND ShiftDate >= :date AND ShiftDate < DATEADD(DAY, 1, :date)", $yesterday));
        $topCatNos = [];
        try {
            $rows = $client->rows(MssqlClient::bindDate(
                "SELECT TOP 5 CatNo, COUNT(*) AS lines, SUM(AmtSold) AS total FROM [CenterEdge].[dbo].[Sales] WHERE ShiftDate >= :date AND ShiftDate < DATEADD(DAY, 1, :date) GROUP BY CatNo ORDER BY total DESC", $yesterday), 5);
            $diag['top_categories_' . $probeDate] = array_map(function ($r) use (&$topCatNos) {
                $topCatNos[] = (int)$r['CatNo'];
                return 'CatNo ' . $r['CatNo'] . ': $' . round((float)$r['total'], 2) . ' (' . $r['lines'] . ' lines)';
            }, $rows);
        } catch (Exception $e) {
            $diag['top_categories_' . $probeDate] = 'error: ' . $e->getMessage();
        }

        // --- Hourly-money reconciliation: the hourly panel is only trustworthy
        // if the card-ledger swipes (PlayerCardTrans) tie out to the POS's own
        // daily DivNo-808 posting. Total the hourly query for the probe day and
        // print it beside the daily sales query's figure so a mismatch is
        // obvious at a glance. Punch count sanity-checks the wages side.
        try {
            $hb = laborMoneyBucketsFromRows(
                $client->rows(MssqlClient::bindRange($q['hourly_sales_range_sql'], $probeDate, $probeDate), 5000));
            $hTot = 0.0; $hPlays = 0; $hoursSeen = [];
            foreach ($hb as $b) { $hTot += $b['dollars']; $hPlays += $b['plays']; $hoursSeen[$b['hour']] = true; }
            $dailyProbe = laborSalesMapFromRows(
                $client->rows(MssqlClient::bindRange($q['sales_range_sql'], $probeDate, $probeDate), 10));
            $dTot = $dailyProbe[$probeDate] ?? 0.0;
            $diag['hourly_vs_daily_' . $probeDate] =
                'ledger hourly: $' . round($hTot, 2) . ' (' . $hPlays . ' swipes across ' . count($hoursSeen)
                . ' hours) vs daily sales query: $' . round($dTot, 2)
                . ' — delta $' . round($hTot - $dTot, 2);
            $punches = $client->rows(MssqlClient::bindRange($q['punches_range_sql'], $probeDate, $probeDate), 2000);
            $diag['punches_' . $probeDate] = count($punches) . ' kart-crew punches returned';
        } catch (Exception $e) {
            $diag['hourly_money'] = 'error: ' . $e->getMessage();
        }

        // --- Schema-aware name discovery: resolve category NUMBERS to their
        // POS names. The venue's Sales table carries no item names (only
        // CatNo + SubCatNo), so names must come from a lookup table — whose
        // name and shape vary by install. Each candidate is probed inside
        // its own try/catch (a GUID-keyed near-miss like ClassCategories
        // must not abort the search), and the number column must be an
        // integer type before it's trusted. Identifiers are bracket-quoted;
        // every statement is a single SELECT through the read-only guard.
        $ident = function (string $name): string {
            return '[' . preg_replace('/[^A-Za-z0-9_ #\-]/', '', $name) . ']';
        };
        try {
            $cols = array_map(function ($r) { return (string)$r['COLUMN_NAME']; }, $client->rows(
                "SELECT COLUMN_NAME FROM [CenterEdge].INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Sales'", 64));
            $diag['sales_columns'] = implode(', ', $cols);

            $catTables = $client->rows(
                "SELECT TABLE_NAME FROM [CenterEdge].INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND (TABLE_NAME LIKE '%Cat%' OR TABLE_NAME LIKE '%Divis%') ORDER BY TABLE_NAME", 24);
            $catNames = null;
            $tried = [];
            $wanted = array_values(array_unique(array_merge($topCatNos, [106, 108])));
            $in = implode(',', array_map('intval', $wanted));
            foreach ($catTables as $t) {
                $tbl = (string)$t['TABLE_NAME'];
                $tried[] = $tbl;
                try {
                    // Columns WITH types: the join key must be an integer.
                    $tcols = $client->rows(
                        "SELECT COLUMN_NAME, DATA_TYPE FROM [CenterEdge].INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . str_replace("'", "''", $tbl) . "'", 64);
                    $noCol = null; $nameCol = null;
                    foreach ($tcols as $c) {
                        $cn = (string)$c['COLUMN_NAME'];
                        $ct = strtolower((string)$c['DATA_TYPE']);
                        $isInt = in_array($ct, ['int', 'smallint', 'tinyint', 'bigint', 'numeric', 'decimal'], true);
                        if ($noCol === null && $isInt && preg_match('/^(CatNo|CategoryNo|CatID|CategoryID|DivNo|No|ID)$/i', $cn)) $noCol = $cn;
                        if ($nameCol === null && in_array($ct, ['varchar', 'nvarchar', 'char', 'nchar'], true) && preg_match('/(Desc|Name|Title)/i', $cn)) $nameCol = $cn;
                    }
                    if (!$noCol || !$nameCol || !$wanted) continue;
                    $rows = $client->rows(
                        'SELECT ' . $ident($noCol) . ' AS no, ' . $ident($nameCol) . ' AS name FROM [CenterEdge].[dbo].' . $ident($tbl)
                        . ' WHERE ' . $ident($noCol) . ' IN (' . $in . ')', 16);
                    if ($rows) {
                        $catNames = array_map(function ($r) {
                            return 'CatNo ' . $r['no'] . ' = "' . trim((string)$r['name']) . '"';
                        }, $rows);
                        $catNames[] = '(from table ' . $tbl . ')';
                        break;
                    }
                } catch (Exception $e) {
                    // wrong shape — move on to the next candidate table
                    continue;
                }
            }
            $diag['category_names'] = $catNames ?: 'no matching category lookup found (candidates tried: ' . implode(', ', $tried) . ')';

            // --- Where does a known figure live? Dump EVERY category and
            // EVERY division total for the probe date, names joined where a
            // lookup exists, so a target number (e.g. "7/11 karts were
            // $5,797.24") identifies its own bucket on sight. The Sales
            // table's DivNo has never been examined before this probe.
            $nameLookup = function (string $tablePattern, string $noColRegex) use ($client, $ident): ?array {
                $tables = $client->rows(
                    "SELECT TABLE_NAME FROM [CenterEdge].INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE '" . $tablePattern . "' ORDER BY TABLE_NAME", 16);
                foreach ($tables as $t) {
                    $tbl = (string)$t['TABLE_NAME'];
                    try {
                        $tcols = $client->rows(
                            "SELECT COLUMN_NAME, DATA_TYPE FROM [CenterEdge].INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . str_replace("'", "''", $tbl) . "'", 64);
                        $noCol = null; $nameCol = null;
                        foreach ($tcols as $c) {
                            $cn = (string)$c['COLUMN_NAME'];
                            $ct = strtolower((string)$c['DATA_TYPE']);
                            $isInt = in_array($ct, ['int', 'smallint', 'tinyint', 'bigint', 'numeric', 'decimal'], true);
                            if ($noCol === null && $isInt && preg_match($noColRegex, $cn)) $noCol = $cn;
                            if ($nameCol === null && in_array($ct, ['varchar', 'nvarchar', 'char', 'nchar'], true) && preg_match('/(Desc|Name|Title)/i', $cn)) $nameCol = $cn;
                        }
                        if ($noCol && $nameCol) {
                            $names = [];
                            foreach ($client->rows('SELECT ' . $ident($noCol) . ' AS no, ' . $ident($nameCol) . ' AS name FROM [CenterEdge].[dbo].' . $ident($tbl), 64) as $r) {
                                $names[(string)(int)$r['no']] = trim((string)$r['name']);
                            }
                            if ($names) return $names;
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }
                return null;
            };
            $catNameMap = $nameLookup('%Cat%', '/^(CatNo|CategoryNo|CatID|CategoryID|No|ID)$/i') ?: [];
            $divNameMap = $nameLookup('%Div%', '/^(DivNo|DivisionNo|DivID|DivisionID|No|ID)$/i') ?: [];

            foreach ([
                ['all_categories_' . $probeDate, 'CatNo', $catNameMap],
                ['all_divisions_' . $probeDate, 'DivNo', $divNameMap],
            ] as [$key, $col, $nameMap]) {
                try {
                    $rows = $client->rows(MssqlClient::bindDate(
                        "SELECT {$col} AS no, COUNT(*) AS lines, SUM(AmtSold) AS total FROM [CenterEdge].[dbo].[Sales] WHERE ShiftDate >= :date AND ShiftDate < DATEADD(DAY, 1, :date) GROUP BY {$col} ORDER BY total DESC", $probeDate), 30);
                    $diag[$key] = $rows ? array_map(function ($r) use ($col, $nameMap) {
                        $no = (string)(int)$r['no'];
                        $nm = isset($nameMap[$no]) ? ' "' . $nameMap[$no] . '"' : '';
                        return $col . ' ' . $no . $nm . ': $' . round((float)$r['total'], 2) . ' (' . $r['lines'] . ' lines)';
                    }, $rows) : 'no sales rows on ' . $probeDate;
                } catch (Exception $e) {
                    $diag[$key] = 'error: ' . $e->getMessage();
                }
            }

            // Category 108 ("Go Karts") posts rides at AmtSold = 0 because
            // payment happens at the card reader. Some POS configurations
            // still carry the ride's VALUE on those lines (e.g. list price
            // offset by a 100% discount) — sum every numeric column so a
            // usable value column shows itself if one exists.
            try {
                $row = $client->rows(
                    "SELECT COUNT(*) AS lines, SUM(QtySold) AS qty, SUM(AmtSold) AS amt, SUM(Discounts) AS discounts, SUM(NumberTickets) AS tickets, SUM(CostSold) AS cost FROM [CenterEdge].[dbo].[Sales] WHERE CatNo = 108 AND ShiftDate >= DATEADD(DAY, -7, GETDATE())", 1);
                if ($row) {
                    $r = $row[0];
                    $diag['cat108_value_columns_last7'] =
                        'lines=' . $r['lines'] . ', QtySold=' . round((float)$r['qty'], 2)
                        . ', AmtSold=$' . round((float)$r['amt'], 2)
                        . ', Discounts=$' . round((float)$r['discounts'], 2)
                        . ', NumberTickets=' . round((float)$r['tickets'], 2)
                        . ', CostSold=$' . round((float)$r['cost'], 2);
                }
            } catch (Exception $e) {
                $diag['cat108_value_columns_last7'] = 'error: ' . $e->getMessage();
            }

            // No item names exist in Sales, so the finest split available is
            // the SubCatNo breakdown inside each candidate category.
            foreach ([106, 108] as $cat) {
                try {
                    $rows = $client->rows(
                        "SELECT TOP 6 SubCatNo, COUNT(*) AS lines, SUM(AmtSold) AS total FROM [CenterEdge].[dbo].[Sales] WHERE CatNo = {$cat} AND ShiftDate >= DATEADD(DAY, -7, GETDATE()) GROUP BY SubCatNo ORDER BY total DESC", 6);
                    $diag['subcats_cat' . $cat . '_last7'] = $rows
                        ? array_map(function ($r) {
                            return 'SubCatNo ' . $r['SubCatNo'] . ': $' . round((float)$r['total'], 2) . ' (' . $r['lines'] . ' lines)';
                        }, $rows)
                        : 'NO SALES in the last 7 days';
                } catch (Exception $e) {
                    $diag['subcats_cat' . $cat . '_last7'] = 'error: ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $diag['schema_discovery'] = 'error: ' . $e->getMessage();
        }

        echo json_encode([
            'success' => true,
            'driver'  => $client->driver(),
            'today'   => $today,
            'sales'   => round($sales, 2),
            'labor'   => round($labor, 2),
            'diagnostics' => $diag,
        ]);
    } catch (Exception $e) {
        // Config problems are the expected failure mode here — report them
        // as a structured result, not a 500.
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function laborRate(): void {
    // Dollar figures + wage aggregates: analytics alone is not enough.
    Auth::requireAccess('analytics');
    Auth::requireAccess('view_revenue');

    list($tz, $tzName) = perfTimezone();
    $today = (new DateTime('now', $tz))->format('Y-m-d');

    // Window: same range/offset/anchor/from/to params (and semantics) as
    // the Performance page. The legacy `dates=` list is still accepted:
    // exactly the requested days (any distance apart — year-over-year
    // comparisons were valid under the old contract), fetched one day at a
    // time so a wide spread never scans the whole span.
    $legacyDates = null;
    $rawDates = trim((string)($_GET['dates'] ?? ''));
    if ($rawDates !== '' && !isset($_GET['range'])) {
        $legacyDates = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawDates)))));
        if (!$legacyDates) {
            throw new RuntimeException('dates parameter is required (comma-separated YYYY-MM-DD).');
        }
        if (count($legacyDates) > 31) {
            throw new RuntimeException('At most 31 dates per request.');
        }
        foreach ($legacyDates as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Invalid date: ' . $d);
            }
        }
        sort($legacyDates);
        $from = $legacyDates[0];
        $to   = $legacyDates[count($legacyDates) - 1];
        $win = [
            'range' => 'custom', 'offset' => 0, 'granularity' => 'day',
            'label' => $from . ' – ' . $to, 'from' => $from, 'to' => $to,
            'prev_from' => $from, 'prev_to' => $to,
        ];
        $dates = $legacyDates;
    } else {
        $win = perfResolveWindow($tz);
        $from = $win['from'];
        $to   = $win['to'];

        // Clamp the future away (a Year view in July would otherwise ask
        // MSSQL about days that haven't happened), and bound the span: one
        // Year is the biggest period the page offers.
        if ($to > $today) $to = $today;
        $spanDays = laborDateSpan($from, $to);
        if ($spanDays < 1) {
            throw new RuntimeException('The selected period is entirely in the future.');
        }
        if ($spanDays > 380) {
            throw new RuntimeException('Ranges longer than a year aren\'t supported here — pick a year or less.');
        }
        $dates = [];
        $cursor = DateTime::createFromFormat('!Y-m-d', $from, $tz);
        for ($i = 0; $i < $spanDays; $i++) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
    }

    $meta = perfRangeMeta($win, $tzName);
    $meta['to'] = $to; // reflect the future-clamp

    $ride = laborRideConfig();
    $base = [
        'window' => $meta,
        'configured' => MssqlClient::isConfigured(),
        'ride_counts' => [
            'reader_group_id' => $ride['reader_group_id'],
            'active'          => (bool)$ride['reader_group_id'],
        ],
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    // Nothing to report until the connection exists — skip the feed work
    // too; the page shows its "Not connected yet" card either way.
    if (!MssqlClient::isConfigured()) {
        echo json_encode($base + ['days' => [], 'hourly' => null, 'money' => null]);
        return;
    }

    // Swipe counts from the app's own reader feed: daily columns for every
    // day in range, plus per-(date,hour) rows for the hourly panel. The
    // legacy path skips the hourly panel — it didn't exist under the old
    // contract, and its callers may spread dates years apart.
    $rideStats = [];
    $hourRows = [];
    $coverage = null;
    if ($ride['reader_group_id']) {
        try {
            $rideStats = laborRideStats($dates, $ride['reader_group_id']);
            if ($legacyDates === null) {
                $members = array_map(function ($r) { return (string)$r['game_id']; }, DB::query(
                    'SELECT game_id FROM reader_group_games WHERE reader_group_id = :p0', [$ride['reader_group_id']]));
                if ($members) {
                    $memberSet = array_flip($members);
                    $hourRows = readerHourlyRows($from, $to, $tz, $memberSet);
                    $coverage = readerHourlyCoverage($from, $to, $tz);
                }
            }
        } catch (Exception $e) {
            error_log('labor ride stats failed: ' . $e->getMessage());
        }
    }

    // ---- MSSQL: sales + labor dollars, both computed LIVE by the DB ----
    // Contiguous windows fetch the whole span in one round-trip per query;
    // legacy date lists fetch each requested day alone, so two dates years
    // apart never scan the span between them.
    $q = laborQueries();
    try {
        $client = new MssqlClient();
        $salesMap = [];
        $laborMap = [];
        $spans = $legacyDates !== null
            ? array_map(function ($d) { return [$d, $d]; }, $dates)
            : [[$from, $to]];
        foreach ($spans as [$a, $b]) {
            $salesMap += laborSalesMapFromRows(
                $client->rows(MssqlClient::bindRange($q['sales_range_sql'], $a, $b), 500));
            $laborMap += laborSalesMapFromRows(
                $client->rows(MssqlClient::bindRange($q['labor_range_sql'], $a, $b), 500));
        }
    } catch (Exception $e) {
        echo json_encode($base + ['error' => $e->getMessage(), 'days' => [], 'money' => null]);
        return;
    }
    $result = laborComposeResults($dates, $salesMap, $laborMap, $rideStats, $hourRows, $coverage);

    // ---- Hourly money: REAL ledger dollars per hour + punch-split wages ----
    // Best-effort and self-contained: a failure here (e.g. a hand-edited hourly
    // query with a typo) reports itself on the panel but never takes down the
    // daily figures above. Skipped on the legacy dates= path, whose callers may
    // spread dates years apart.
    $money = null;
    if ($legacyDates === null) {
        try {
            $money = laborHourlyMoney($client, $q, $from, $to, $dates, $tz);
        } catch (Exception $e) {
            $money = ['error' => $e->getMessage(), 'hours' => null, 'heatmap' => null, 'totals' => null];
            error_log('labor hourly money failed: ' . $e->getMessage());
        }
        // The hourly panel reads a DIFFERENT table from the daily sales figure
        // (card ledger vs POS sales), so the two can disagree — and when the
        // ledger returns nothing the panel would otherwise draw an empty chart
        // beside a real headline total and say nothing. Reconcile them here,
        // for the actual range on screen, rather than only behind the Test
        // button's single probe day.
        if ($money && empty($money['error']) && isset($money['totals'])) {
            $dailyTotal = 0.0;
            foreach ($dates as $d) $dailyTotal += (float)($salesMap[$d] ?? 0);
            $money['reconciliation'] = laborReconcile(
                (float)($money['totals']['dollars'] ?? 0), $dailyTotal);
        }
    }

    echo json_encode($base + ['days' => $result['days'], 'hourly' => $result['hourly'], 'money' => $money]);
}

/**
 * Compose the daily rows + hour-of-day panel from the fetched pieces.
 * Pure function — directly testable without an MSSQL connection.
 *
 * Dollar figures (daily sales AND daily wages) come straight from the
 * database maps — nothing recomputes them. The hour-of-day panel carries
 * only REAL swipe counts from the app's own feed.
 *
 * @param string[] $dates every ISO date in the window, ascending
 * @param array<string,float> $salesMap day => dollars (live SQL)
 * @param array<string,float> $laborMap day => wage dollars (live SQL)
 * @param array $rideStats laborRideStats() output (day => rides/pass)
 * @param array $hourRows readerHourlyRows() output
 * @param ?array $coverage readerHourlyCoverage() output
 * @return array{days: array, hourly: ?array}
 */
function laborComposeResults(array $dates, array $salesMap, array $laborMap, array $rideStats, array $hourRows, ?array $coverage): array {
    // ---- Daily rows (every day in range, zeros included) ----
    $days = [];
    foreach ($dates as $d) {
        $cash  = round($salesMap[$d] ?? 0.0, 2);
        $labor = round($laborMap[$d] ?? 0.0, 2);
        $day = ['date' => $d, 'cash' => $cash, 'labor' => $labor];
        // Sales are the recorded dollars from the POS query, full stop.
        $sales = $cash;
        if (isset($rideStats[$d])) {
            $rides = $rideStats[$d]['rides'];
            $pass  = $rideStats[$d]['pass'];
            $day['rides'] = $rides;
            $day['pass_rides'] = $pass;
            $day['paid_rides'] = max(0, $rides - $pass);
        }
        $day['sales'] = round($sales, 2);
        $day['rate']  = $sales > 0 ? round($labor / $sales, 4) : null;
        $days[] = $day;
    }

    // ---- Hour-of-day panel: real swipe counts only ----
    // (An hourly wages/sales panel shipped briefly here; removed — see the
    // note above the range-query constants.)
    $hourly = null;
    if ($hourRows) {
        $ridesByHour = array_fill(0, 24, 0);
        $passByHour  = array_fill(0, 24, 0);
        foreach ($hourRows as $r) {
            $h = (int)$r['hour'];
            $ridesByHour[$h] += (int)$r['plays'];
            $passByHour[$h]  += (int)$r['time_plays'];
        }
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = [
                'hour'       => $h,
                'rides'      => $ridesByHour[$h],
                'pass_rides' => $passByHour[$h],
            ];
        }
        $hourly = [
            'hours' => $hours,
            // Hour-grain swipe history only accumulates from the day the
            // hourly rollup shipped — say what's actually covered.
            'swipes_from' => $coverage['from'] ?? null,
            'swipes_full' => (bool)($coverage['full'] ?? false),
        ];
    }

    return ['days' => $days, 'hourly' => $hourly];
}

/** Inclusive day count between two ISO dates (0 when to < from). */
function laborDateSpan(string $from, string $to): int {
    $a = DateTime::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b || $b < $a) return 0;
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400) + 1;
}

// ---------------------------------------------------------------------------
// Hourly money: real kart revenue per hour (card ledger) vs punch-split wages
// ---------------------------------------------------------------------------

/**
 * Normalize the hourly sales query's rows into (day, hour, dollars, plays),
 * tolerant of column naming (named keys any case, else positional) so a
 * hand-edited query still lands in the right columns.
 *
 * @return array<int,array{day:string,hour:int,dollars:float,plays:int}>
 */
function laborMoneyBucketsFromRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $get = function (array $row, array $vals, string $name, int $pos) {
            foreach ($row as $k => $v) {
                if (strcasecmp((string)$k, $name) === 0) return $v;
            }
            return $vals[$pos] ?? null;
        };
        $rawDay = trim((string)$get($row, $vals, 'day', 0));
        $day = substr($rawDay, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $ts = strtotime($rawDay);
            if ($ts === false) continue;
            $day = date('Y-m-d', $ts);
        }
        $hour = (int)$get($row, $vals, 'hour', 1);
        if ($hour < 0 || $hour > 23) continue;
        $out[] = [
            'day'     => $day,
            'hour'    => $hour,
            'dollars' => (float)$get($row, $vals, 'dollars', 2),
            'plays'   => (int)$get($row, $vals, 'plays', 3),
        ];
    }
    return $out;
}

/** Weekday index 0=Sun..6=Sat for an ISO date (calendar-fixed, tz-independent). */
function laborWeekday(string $iso): int {
    $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
    return $d ? (int)$d->format('w') : 0;
}

/**
 * Split raw punches into wage dollars per (local day, local hour): each punch
 * accrues PayRate × the fraction of each wall-clock hour it overlaps. This is
 * exactly how wages accrue in reality — no estimate. Conventions match the
 * proven daily wages query: an unclosed punch accrues to NOW only when it was
 * opened today; an unclosed punch on a PAST day is broken data and counts zero.
 * Times are the venue's wall clock, so arithmetic is done naively (no timezone
 * conversion) — the same convention the DATEDIFF in the daily query uses.
 *
 * Pure — testable without a connection.
 *
 * @param array $punchRows rows with day/time_in/day_out/time_out/pay_rate
 *                         (named keys any case, else positional 0..4)
 * @param string $todayStr venue-local 'Y-m-d'
 * @param string $nowTs    venue-local 'Y-m-d H:i:s'
 * @return array<string,float> "Y-m-d|H" => wage dollars
 */
function laborPunchWageHours(array $punchRows, string $todayStr, string $nowTs): array {
    $wages = [];
    foreach ($punchRows as $row) {
        $vals = array_values($row);
        $get = function (array $row, array $vals, string $name, int $pos) {
            foreach ($row as $k => $v) {
                if (strcasecmp((string)$k, $name) === 0) return $v;
            }
            return $vals[$pos] ?? null;
        };
        $day     = substr(trim((string)$get($row, $vals, 'day', 0)), 0, 10);
        $timeIn  = trim((string)$get($row, $vals, 'time_in', 1));
        $dayOut  = substr(trim((string)($get($row, $vals, 'day_out', 2) ?? '')), 0, 10);
        $timeOut = trim((string)($get($row, $vals, 'time_out', 3) ?? ''));
        $rate    = (float)$get($row, $vals, 'pay_rate', 4);
        if ($rate <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
            || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeIn)) {
            continue;
        }

        $inTs = strtotime($day . ' ' . $timeIn);
        if ($inTs === false) continue;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayOut) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeOut)) {
            $outTs = strtotime($dayOut . ' ' . $timeOut);
        } elseif ($day === $todayStr) {
            $outTs = strtotime($nowTs); // still on the clock right now
        } else {
            continue; // unclosed punch on a past day: broken data — zero hours
        }
        if ($outTs === false || $outTs <= $inTs) continue;
        // Garbage guard: a punch "spanning" more than 3 days would smear junk
        // across the heatmap; the daily query's totals expose it for fixing.
        if ($outTs - $inTs > 3 * 86400) continue;

        $cursor = $inTs;
        $guard = 0;
        while ($cursor < $outTs && $guard++ < 128) {
            $hourEnd = (int)(floor($cursor / 3600) + 1) * 3600;
            $segEnd  = min($hourEnd, $outTs);
            $key = date('Y-m-d', $cursor) . '|' . (int)date('G', $cursor);
            $wages[$key] = ($wages[$key] ?? 0.0) + $rate * ($segEnd - $cursor) / 3600.0;
            $cursor = $segEnd;
        }
    }
    return $wages;
}

/**
 * Fetch + compose the hourly-money block for [$from,$to]: revenue per hour from
 * the card ledger, wages per hour from punch splitting, and the weekday × hour
 * heatmap. One round-trip per query regardless of span.
 */
function laborHourlyMoney(MssqlClient $client, array $q, string $from, string $to, array $dates, DateTimeZone $tz): array {
    $buckets = laborMoneyBucketsFromRows(
        $client->rows(MssqlClient::bindRange($q['hourly_sales_range_sql'], $from, $to), 20000));
    $punches = $client->rows(MssqlClient::bindRange($q['punches_range_sql'], $from, $to), 20000);
    $now = new DateTime('now', $tz);
    $wageMap = laborPunchWageHours($punches, $now->format('Y-m-d'), $now->format('Y-m-d H:i:s'));
    return laborMoneyCompose($dates, $buckets, $wageMap);
}

/**
 * Compose the hour-of-day money rows, the weekday × hour heatmap, and totals.
 * Pure — directly testable without a connection.
 *
 * ACTUALS ONLY — every figure here is the real total for the selected period:
 * hours[] carry the dollars taken and wages paid in that hour across the whole
 * window, and each heatmap cell is the total for that weekday/hour slot. (Both
 * used to be averages — per day and per weekday-occurrence respectively; that
 * was removed so the page reports recorded numbers, never a typical day.)
 * `occurrences` stays on each heatmap row as honest context: a window with
 * three Saturdays and one Sunday says so.
 *
 * rate = wages ÷ dollars, computed on the cell/hour totals so hours with more
 * money weigh more (never an average of ratios).
 *
 * @param string[] $dates every ISO date in the window, ascending
 * @param array $buckets laborMoneyBucketsFromRows() output
 * @param array<string,float> $wageMap laborPunchWageHours() output
 * @return array{hours:array,heatmap:array,totals:array,error:null}
 */
function laborMoneyCompose(array $dates, array $buckets, array $wageMap): array {
    $daySet = array_flip($dates);
    $dowOcc = array_fill(0, 7, 0);
    foreach ($dates as $d) $dowOcc[laborWeekday($d)]++;

    $dolH = array_fill(0, 24, 0.0);
    $wagH = array_fill(0, 24, 0.0);
    $plyH = array_fill(0, 24, 0);
    $dolCell = []; $wagCell = [];
    for ($w = 0; $w < 7; $w++) { $dolCell[$w] = array_fill(0, 24, 0.0); $wagCell[$w] = array_fill(0, 24, 0.0); }
    $totDol = 0.0; $totWag = 0.0; $totPly = 0;

    foreach ($buckets as $b) {
        if (!isset($daySet[$b['day']])) continue; // only days we asked for
        $h = $b['hour']; $w = laborWeekday($b['day']);
        $dolH[$h] += $b['dollars']; $plyH[$h] += $b['plays'];
        $dolCell[$w][$h] += $b['dollars'];
        $totDol += $b['dollars']; $totPly += $b['plays'];
    }
    foreach ($wageMap as $key => $amt) {
        $parts = explode('|', $key);
        if (count($parts) !== 2) continue;
        [$d, $h] = $parts;
        $h = (int)$h;
        if (!isset($daySet[$d]) || $h < 0 || $h > 23) continue;
        $w = laborWeekday($d);
        $wagH[$h] += $amt;
        $wagCell[$w][$h] += $amt;
        $totWag += $amt;
    }

    $hours = [];
    for ($h = 0; $h < 24; $h++) {
        $hours[] = [
            'hour'    => $h,
            'dollars' => round($dolH[$h], 2),
            'wages'   => round($wagH[$h], 2),
            'plays'   => $plyH[$h],
            'rate'    => $dolH[$h] > 0 ? round($wagH[$h] / $dolH[$h], 4) : null,
        ];
    }

    $dowNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $rows = [];
    $maxDol = 0.0; $maxWag = 0.0;
    for ($w = 0; $w < 7; $w++) {
        $cells = [];
        for ($h = 0; $h < 24; $h++) {
            $dol = $dolCell[$w][$h];
            $wag = $wagCell[$w][$h];
            if ($dol > $maxDol) $maxDol = $dol;
            if ($wag > $maxWag) $maxWag = $wag;
            $cells[] = [
                'hour'    => $h,
                'dollars' => round($dol, 2),
                'wages'   => round($wag, 2),
                'rate'    => $dol > 0 ? round($wag / $dol, 4) : null,
            ];
        }
        $rows[] = ['dow' => $w, 'label' => $dowNames[$w], 'occurrences' => $dowOcc[$w], 'cells' => $cells];
    }

    return [
        'hours'   => $hours,
        'heatmap' => ['rows' => $rows, 'max_dollars' => round($maxDol, 2), 'max_wages' => round($maxWag, 2)],
        'totals'  => [
            'dollars' => round($totDol, 2),
            'wages'   => round($totWag, 2),
            'plays'   => $totPly,
            'rate'    => $totDol > 0 ? round($totWag / $totDol, 4) : null,
        ],
        'error'   => null,
    ];
}

/**
 * Compare the hourly ledger total against the daily sales total for the same
 * window. Pure — directly testable without a connection.
 *
 * The hourly panel and the headline sales figure come from different tables
 * (PlayerCardTrans vs Sales), so they never match exactly and a small gap is
 * normal. Two cases are NOT normal and must reach the user:
 *   - 'missing'  the daily query booked real money but the ledger returned
 *                none, so every hourly panel is empty while the totals above
 *                show dollars. This is what a range predating the POS's
 *                division stamping on card transactions looks like — verified
 *                on this venue: June 2015 carried 100% DivNo 1, while 2019
 *                onward carries the kart division normally.
 *   - 'partial'  the ledger covers materially less than the daily figure, so
 *                the bars understate the period.
 * 'unknown' means there was no daily money to compare against (an empty range),
 * which is not an error — nothing is claimed either way.
 *
 * @return array{status:string,ledger_total:float,daily_total:float,covered:?float}
 */
function laborReconcile(float $ledgerTotal, float $dailyTotal): array {
    $covered = $dailyTotal > 0 ? $ledgerTotal / $dailyTotal : null;
    if ($covered === null)                              $status = 'unknown';
    elseif ($ledgerTotal <= 0)                          $status = 'missing';
    elseif ($covered < LABOR_RECONCILE_TOLERANCE)       $status = 'partial';
    else                                                $status = 'ok';

    return [
        'status'       => $status,
        'ledger_total' => round($ledgerTotal, 2),
        'daily_total'  => round($dailyTotal, 2),
        'covered'      => $covered === null ? null : round($covered, 4),
    ];
}
