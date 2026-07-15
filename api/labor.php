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

// NOTE: an hour-of-day wages/sales panel shipped briefly, driven by raw
// punch rows and per-day estimates. It was removed on request — the POS
// books kart money once per day and the punch-derived split wasn't
// trustworthy, so the page shows only LIVE database dollars (daily) and
// real feed swipes (hourly) until a genuine hourly source is found in
// the CenterEdge schema.

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
        'sales_range_sql' => DB::getConfig('labor_sales_range_sql') ?: LABOR_DEFAULT_SALES_RANGE_SQL,
        'labor_range_sql' => DB::getConfig('labor_labor_range_sql') ?: LABOR_DEFAULT_LABOR_RANGE_SQL,
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

function laborRideConfig(): array {
    $gid = DB::getConfig('labor_reader_group_id');
    $price = DB::getConfig('labor_price_per_ride');
    $map = json_decode((string)(DB::getConfig('labor_ride_prices') ?: '{}'), true);
    return [
        'reader_group_id' => ($gid !== null && $gid !== '') ? (int)$gid : null,
        // Fallback price for any track without its own entry below.
        'price_per_ride'  => ($price !== null && $price !== '') ? (float)$price : 11.0,
        // Per-track (per-game/reader) prices: {game_id: price}. The venue
        // runs multiple kart tracks at different price points.
        'ride_prices'     => is_array($map) ? $map : [],
        // Whether paid rides × price is ADDED to sales. Defaults OFF: the
        // sales query now reads the POS's own "Go Kart Readers" division
        // dollars, and adding an estimate on top would double count. When
        // off, the Rides/Pass columns still render as context.
        'add_ride_value'  => DB::getConfig('labor_add_ride_value') === '1',
    ];
}

/** Price for one track: its own entry, or the flat fallback. */
function laborPriceFor(string $gameId, array $ride): float {
    if (isset($ride['ride_prices'][$gameId]) && $ride['ride_prices'][$gameId] !== '') {
        return (float)$ride['ride_prices'][$gameId];
    }
    return (float)$ride['price_per_ride'];
}

/**
 * Per-day swipe counts for the configured go-kart reader group, from the
 * app's OWN feed (game_daily_stats + raw transactions via the same stitch
 * the Reader Groups page uses). Knows used_time_play per swipe, so the
 * paid-ride count can omit time-pass swipes — which the MSSQL Sales lines
 * cannot distinguish.
 *
 * Each track (game/reader) is valued at its own price — the venue runs
 * multiple kart tracks at different price points — falling back to the
 * flat price for tracks without an entry.
 *
 * @param string[] $dates ISO dates
 * @return array<string,array{rides:int,pass:int,value:float}> keyed by date
 */
function laborRideStats(array $dates, int $groupId, array $ride): array {
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
        $rides = 0; $pass = 0; $value = 0.0;
        foreach (($daily['byDate'][$d] ?? []) as $gid => $b) {
            if (!isset($memberSet[$gid])) continue;
            $p  = (int)($b['plays'] ?? 0);
            $tp = (int)($b['time_plays'] ?? 0);
            $rides += $p;
            $pass  += $tp;
            $value += max(0, $p - $tp) * laborPriceFor((string)$gid, $ride);
        }
        $out[$d] = ['rides' => $rides, 'pass' => $pass, 'value' => round($value, 2)];
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
        'sales_range_sql' => LABOR_DEFAULT_SALES_RANGE_SQL,
        'labor_range_sql' => LABOR_DEFAULT_LABOR_RANGE_SQL,
    ];
    // For the "value rides from this area" dropdown — read directly so the
    // settings gate alone suffices. Members ship per group so the per-track
    // price editor can rebuild instantly when the selection changes.
    $out['reader_groups'] = DB::query('SELECT id, name FROM reader_groups ORDER BY name');
    $members = [];
    foreach (DB::query('SELECT reader_group_id, game_id, game_name FROM reader_group_games ORDER BY game_name') as $m) {
        $members[(string)$m['reader_group_id']][] = ['game_id' => $m['game_id'], 'game_name' => $m['game_name']];
    }
    $out['reader_group_members'] = $members;
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



    // Ride valuation: validate BEFORE the first write so a rejected field
    // can never leave a half-saved config (observed: a bad reader_group_id
    // used to 422 after the connection settings had already committed).
    $groupIdToStore = null; // null = don't touch, '' = clear, else id string
    if (array_key_exists('reader_group_id', $input)) {
        $gid = $input['reader_group_id'];
        if ($gid === null || $gid === '' || (int)$gid === 0) {
            $groupIdToStore = '';
        } else {
            $gid = (int)$gid;
            $exists = DB::queryOne('SELECT id FROM reader_groups WHERE id = :p0', [$gid]);
            if (!$exists) {
                throw new RuntimeException('Unknown reader group for ride valuation.');
            }
            $groupIdToStore = (string)$gid;
        }
    }
    $priceToStore = null;
    if (array_key_exists('price_per_ride', $input)) {
        $p = (float)$input['price_per_ride'];
        if ($p < 0 || $p > 10000) {
            throw new RuntimeException('Price per ride must be between 0 and 10000.');
        }
        $priceToStore = (string)$p;
    }
    // Per-track prices: {game_id: price}. Blank/absent entries fall back to
    // the flat price; unknown keys are dropped rather than stored.
    $ridePricesToStore = null;
    if (array_key_exists('ride_prices', $input)) {
        $in = is_array($input['ride_prices']) ? $input['ride_prices'] : [];
        $clean = [];
        foreach ($in as $gameId => $price) {
            if ($price === '' || $price === null) continue;
            $p = (float)$price;
            if ($p < 0 || $p > 10000) {
                throw new RuntimeException('Track price must be between 0 and 10000.');
            }
            $clean[(string)$gameId] = $p;
        }
        $ridePricesToStore = json_encode($clean);
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
    if ($groupIdToStore !== null) DB::setConfig('labor_reader_group_id', $groupIdToStore);
    if ($priceToStore !== null) DB::setConfig('labor_price_per_ride', $priceToStore);
    if (array_key_exists('add_ride_value', $input)) {
        DB::setConfig('labor_add_ride_value', $input['add_ride_value'] ? '1' : '0');
    }
    if ($ridePricesToStore !== null) DB::setConfig('labor_ride_prices', $ridePricesToStore);

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
    Auth::requireAccess('settings');
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
        'ride_valuation' => [
            'reader_group_id' => $ride['reader_group_id'],
            'price_per_ride'  => $ride['price_per_ride'],
            'active'          => (bool)$ride['reader_group_id'],
            'add_ride_value'  => (bool)$ride['add_ride_value'],
        ],
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    // Nothing to report until the connection exists — skip the feed work
    // too; the page shows its "Not connected yet" card either way.
    if (!MssqlClient::isConfigured()) {
        echo json_encode($base + ['days' => [], 'hourly' => null]);
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
            $rideStats = laborRideStats($dates, $ride['reader_group_id'], $ride);
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
        echo json_encode($base + ['error' => $e->getMessage(), 'days' => []]);
        return;
    }
    $result = laborComposeResults($dates, $salesMap, $laborMap, $rideStats, $hourRows, $coverage, $ride);

    echo json_encode($base + ['days' => $result['days'], 'hourly' => $result['hourly']]);
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
 * @param array $rideStats laborRideStats() output (day => rides/pass/value)
 * @param array $hourRows readerHourlyRows() output
 * @param ?array $coverage readerHourlyCoverage() output
 * @param array $ride laborRideConfig() output
 * @return array{days: array, hourly: ?array}
 */
function laborComposeResults(array $dates, array $salesMap, array $laborMap, array $rideStats, array $hourRows, ?array $coverage, array $ride): array {
    // ---- Daily rows (every day in range, zeros included) ----
    $days = [];
    foreach ($dates as $d) {
        $cash  = round($salesMap[$d] ?? 0.0, 2);
        $labor = round($laborMap[$d] ?? 0.0, 2);
        $day = ['date' => $d, 'cash' => $cash, 'labor' => $labor];
        $sales = $cash;
        if (isset($rideStats[$d])) {
            $rides = $rideStats[$d]['rides'];
            $pass  = $rideStats[$d]['pass'];
            if ($ride['add_ride_value']) {
                $sales += $rideStats[$d]['value'];
                $day['ride_value'] = $rideStats[$d]['value'];
            }
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
