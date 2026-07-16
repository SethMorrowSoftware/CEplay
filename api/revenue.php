<?php
/**
 * Revenue Mix API — sales dollars by CATEGORY (area/department) over time,
 * straight from the CenterEdge MSSQL `Sales` table. Browsable by
 * Day / Week / Month / Year / Custom (same window model as Performance / Labor /
 * Card Loads / Ticket Trends).
 *
 *   GET  /api/revenue/settings — editable query (settings perm)
 *   PUT  /api/revenue/settings — save it (settings perm)
 *   POST /api/revenue/test     — run the query for a probe range + fingerprint (settings perm)
 *   GET  /api/revenue/data?range=&offset=&from=&to= — per-day total + per-category
 *          breakdown + summary (analytics + view_revenue)
 *
 * This is the P&L-lite the app was missing: one screen showing where the money
 * comes from (attractions vs food vs groups vs card fees…), the MIX shift over
 * time, and the discount rate per category (margin leakage). Every other money
 * report is a single silo; this is the roll-up that frames them.
 *
 * Grain is DAY, not hour: `Sales.ShiftDate` is stamped at midnight (a business
 * day, not a clock time), so hour-of-day would be fiction here — Day / Week /
 * Month / Year / Custom is the honest resolution (same as Ticket Trends).
 *
 * The query is admin-editable SQL with required :from/:to placeholders, guarded
 * to a single SELECT (MssqlClient::assertReadOnly), returning one row per
 * (day, category). PHP rolls up the daily trend + per-category breakdown, so a
 * Year view costs the same one round-trip as a Day. Shares the Go-Kart Labor
 * page's MSSQL connection; the connection itself is configured there.
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/analytics.php';

// Per (local day, category): dollars sold, discount dollars, and unit count.
// AmtSold / Discounts / QtySold are all real columns on this venue's Sales
// table (the Labor page's diagnostics sum them live). ShiftDate is a business
// day at midnight, so CONVERT(...,120) yields the same local day the app bins.
const REVENUE_DEFAULT_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), ShiftDate, 120) AS day,\n       CatNo AS cat,\n       SUM(AmtSold) AS amount,\n       SUM(Discounts) AS discounts,\n       SUM(QtySold) AS qty\nFROM [CenterEdge].[dbo].[Sales]\nWHERE ShiftDate >= :from\n  AND ShiftDate < DATEADD(DAY, 1, :to)\nGROUP BY CONVERT(VARCHAR(10), ShiftDate, 120), CatNo";

function handleRevenue(string $method, array $parts, ?array $input): void {
    $action = $parts[0] ?? '';
    switch ($action) {
        case 'settings':
            if ($method === 'GET') { revenueGetSettings(); return; }
            if ($method === 'PUT') { revenuePutSettings($input ?? []); return; }
            break;
        case 'test':
            if ($method === 'POST') { revenueTest($input); return; }
            break;
        case 'data':
            if ($method === 'GET') { revenueData(); return; }
            break;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Unknown revenue endpoint']);
}

function revenueRangeSql(): string {
    return DB::getConfig('revenue_range_sql') ?: REVENUE_DEFAULT_RANGE_SQL;
}

/**
 * Normalize the range query's rows into (day, cat, amount, discounts, qty),
 * tolerant of column naming (named keys any case, else positional).
 *
 * @return array<int,array{day:string,cat:int,amount:float,discounts:float,qty:float}>
 */
function revenueBucketsFromRows(array $rows): array {
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
        $out[] = [
            'day'       => $day,
            'cat'       => (int)$get($row, $vals, 'cat', 1),
            'amount'    => (float)$get($row, $vals, 'amount', 2),
            'discounts' => (float)$get($row, $vals, 'discounts', 3),
            'qty'       => (float)$get($row, $vals, 'qty', 4),
        ];
    }
    return $out;
}

/**
 * Best-effort CatNo → name map. The category lookup table's name varies by
 * install, so discover it via INFORMATION_SCHEMA (an integer No/ID column + a
 * text Name/Desc column) — the same approach the Labor / Ticket Trends pages
 * use. Failure is harmless: the report just shows "Category N".
 *
 * @return array<string,string> CatNo (string) => name
 */
function revenueCatNames(MssqlClient $client): array {
    $ident = function (string $n): string {
        return '[' . preg_replace('/[^A-Za-z0-9_ #\-]/', '', $n) . ']';
    };
    try {
        $tables = $client->rows(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE '%Cat%' ORDER BY TABLE_NAME", 24);
    } catch (Exception $e) {
        return [];
    }
    foreach ($tables as $t) {
        $tbl = (string)$t['TABLE_NAME'];
        try {
            $cols = $client->rows(
                "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . str_replace("'", "''", $tbl) . "'", 64);
            $noCol = null; $nameCol = null;
            foreach ($cols as $c) {
                $cn = (string)$c['COLUMN_NAME'];
                $ct = strtolower((string)$c['DATA_TYPE']);
                $isInt = in_array($ct, ['int', 'smallint', 'tinyint', 'bigint', 'numeric', 'decimal'], true);
                if ($noCol === null && $isInt && preg_match('/^(CatNo|CategoryNo|CatID|CategoryID|No|ID)$/i', $cn)) $noCol = $cn;
                if ($nameCol === null && in_array($ct, ['varchar', 'nvarchar', 'char', 'nchar'], true) && preg_match('/(Desc|Name|Title)/i', $cn)) $nameCol = $cn;
            }
            if ($noCol && $nameCol) {
                $map = [];
                foreach ($client->rows('SELECT ' . $ident($noCol) . ' AS no, ' . $ident($nameCol) . ' AS name FROM dbo.' . $ident($tbl), 1000) as $r) {
                    $map[(string)(int)$r['no']] = trim((string)$r['name']);
                }
                if ($map) return $map;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    return [];
}

function revenueGetSettings(): void {
    Auth::requireAccess('settings');
    echo json_encode([
        'configured' => MssqlClient::isConfigured(),
        'drivers'    => MssqlClient::availableDrivers(),
        'range_sql'  => revenueRangeSql(),
        'defaults'   => ['range_sql' => REVENUE_DEFAULT_RANGE_SQL],
        'connection' => [
            'host'     => (MssqlClient::settings()['host'] ?? '') !== '',
            'database' => MssqlClient::settings()['database'] ?? '',
        ],
    ]);
}

function revenuePutSettings(array $input): void {
    Auth::requireAccess('settings');
    $rangeSql = Validator::requireString($input, 'range_sql', 4000);
    MssqlClient::assertReadOnly($rangeSql);
    MssqlClient::bindRange($rangeSql, '2000-01-01', '2000-01-02');
    DB::setConfig('revenue_range_sql', $rangeSql);
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'revenue_settings_update', 'Revenue-mix query updated by ' . (Auth::check()['username'] ?? '?')]
        );
    } catch (Exception $e) {
        error_log('revenue settings audit log failed: ' . $e->getMessage());
    }
    echo json_encode(['success' => true, 'configured' => MssqlClient::isConfigured()]);
}

function revenueTest(?array $input = null): void {
    // Runs the live MSSQL query and returns dollar figures — gate on
    // data_explorer (raw POS data), not settings.
    Auth::requireAccess('data_explorer');
    $client = new MssqlClient();
    $tz = new DateTimeZone(DB::getConfig('timezone') ?: DEFAULT_TIMEZONE);
    $probeDate = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
    if ($input && isset($input['probe_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['probe_date'])) {
        $probeDate = (string)$input['probe_date'];
    }
    try {
        $client->connect();
        $buckets = revenueBucketsFromRows(
            $client->rows(MssqlClient::bindRange(revenueRangeSql(), $probeDate, $probeDate), 5000));
        $total = 0.0; $discounts = 0.0;
        foreach ($buckets as $b) { $total += $b['amount']; $discounts += $b['discounts']; }
        $catNames = revenueCatNames($client);

        $diag = [];
        $probe = function (string $label, string $sql) use (&$diag, $client) {
            try { $diag[$label] = $client->scalarText($sql); }
            catch (Exception $e) { $diag[$label] = 'error: ' . $e->getMessage(); }
        };
        $probe('server',   'SELECT @@SERVERNAME');
        $probe('database', 'SELECT DB_NAME()');
        // Revenue by category for the probe day, names joined where found.
        $byCat = [];
        foreach ($buckets as $b) {
            $byCat[$b['cat']] = ($byCat[$b['cat']] ?? 0) + $b['amount'];
        }
        arsort($byCat);
        $lines = [];
        foreach (array_slice($byCat, 0, 15, true) as $cat => $amt) {
            $nm = isset($catNames[(string)$cat]) ? ' "' . $catNames[(string)$cat] . '"' : '';
            $lines[] = 'CatNo ' . $cat . $nm . ': $' . number_format((float)$amt, 2);
        }
        $diag['revenue_by_category_' . $probeDate] = $lines ?: 'no sales on ' . $probeDate;

        echo json_encode([
            'success'     => true,
            'driver'      => $client->driver(),
            'probe_date'  => $probeDate,
            'revenue'     => round($total, 2),
            'discounts'   => round($discounts, 2),
            'diagnostics' => $diag,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function revenueData(): void {
    // Revenue dollars ride the same money gate as the other MSSQL reports.
    Auth::requireAccess('analytics');
    Auth::requireAccess('view_revenue');

    list($tz, $tzName) = perfTimezone();
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $win = perfResolveWindow($tz);
    $from = $win['from'];
    $to   = $win['to'];
    if ($to > $today) $to = $today;
    $span = revenueDateSpan($from, $to);
    if ($span < 1) throw new RuntimeException('The selected period is entirely in the future.');
    if ($span > 380) throw new RuntimeException('Ranges longer than a year aren\'t supported here — pick a year or less.');
    $dates = [];
    $cur = DateTime::createFromFormat('!Y-m-d', $from, $tz);
    for ($i = 0; $i < $span; $i++) { $dates[] = $cur->format('Y-m-d'); $cur->modify('+1 day'); }

    $meta = perfRangeMeta($win, $tzName);
    $meta['to'] = $to;
    $base = ['window' => $meta, 'configured' => MssqlClient::isConfigured(), 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')];

    if (!MssqlClient::isConfigured()) {
        echo json_encode($base + ['days' => [], 'categories' => [], 'summary' => null]);
        return;
    }

    $sql = revenueRangeSql();
    try {
        $client = new MssqlClient();
        // GROUP BY (day, CatNo): a Year is ~366 × (tens of categories) rows —
        // comfortably under the cap; 40000 leaves generous headroom.
        $buckets = revenueBucketsFromRows($client->rows(MssqlClient::bindRange($sql, $from, $to), 40000));
        $prior = 0.0;
        if (!empty($win['prev_from']) && !empty($win['prev_to'])) {
            foreach (revenueBucketsFromRows($client->rows(MssqlClient::bindRange($sql, $win['prev_from'], $win['prev_to']), 40000)) as $b) {
                $prior += $b['amount'];
            }
        }
        $catNames = revenueCatNames($client);
    } catch (Exception $e) {
        echo json_encode($base + ['error' => $e->getMessage(), 'days' => [], 'categories' => [], 'summary' => null]);
        return;
    }

    echo json_encode($base + revenueCompose($dates, $buckets, $catNames, $prior));
}

/**
 * Compose the daily revenue trend + per-category breakdown + summary from the
 * (day, category) buckets. Pure — directly testable without a connection.
 *
 * @param string[] $dates every ISO date in the window, ascending
 * @param array $buckets revenueBucketsFromRows() output
 * @param array<string,string> $catNames CatNo => name
 * @return array{days:array, categories:array, summary:array}
 */
function revenueCompose(array $dates, array $buckets, array $catNames, float $priorRevenue): array {
    $numDays = max(1, count($dates));
    $dayIndex = [];
    foreach ($dates as $d) $dayIndex[$d] = ['amount' => 0.0];
    $byCat = [];
    $tot = 0.0; $totDiscounts = 0.0;

    foreach ($buckets as $b) {
        if (!isset($dayIndex[$b['day']])) continue; // only days we asked for
        $dayIndex[$b['day']]['amount'] += $b['amount'];
        $dv = $b['cat'];
        if (!isset($byCat[$dv])) $byCat[$dv] = ['amount' => 0.0, 'discounts' => 0.0, 'qty' => 0.0];
        $byCat[$dv]['amount']    += $b['amount'];
        $byCat[$dv]['discounts'] += $b['discounts'];
        $byCat[$dv]['qty']       += $b['qty'];
        $tot += $b['amount']; $totDiscounts += $b['discounts'];
    }

    $days = [];
    foreach ($dates as $d) {
        $days[] = ['date' => $d, 'amount' => round($dayIndex[$d]['amount'], 2)];
    }

    $categories = [];
    foreach ($byCat as $dv => $a) {
        // Discount rate = discount dollars as a share of gross (net + discount),
        // so a category with heavy comping reads high regardless of its size.
        $gross = $a['amount'] + $a['discounts'];
        $categories[] = [
            'cat'          => $dv,
            'name'         => $catNames[(string)$dv] ?? '',
            'amount'       => round($a['amount'], 2),
            'discounts'    => round($a['discounts'], 2),
            'discount_pct' => $gross > 0 ? round($a['discounts'] / $gross, 4) : 0,
            'qty'          => round($a['qty'], 2),
            'share'        => $tot > 0 ? round($a['amount'] / $tot, 4) : 0,
        ];
    }
    usort($categories, function ($x, $y) { return $y['amount'] <=> $x['amount']; });
    $top = $categories[0] ?? null;

    $grossTot = $tot + $totDiscounts;
    $summary = [
        'revenue'       => round($tot, 2),
        'discounts'     => round($totDiscounts, 2),
        'discount_pct'  => $grossTot > 0 ? round($totDiscounts / $grossTot, 4) : 0,
        'num_days'      => count($dates),
        'per_day'       => round($tot / $numDays, 2),
        'num_cats'      => count($categories),
        'top_cat'       => $top['cat'] ?? null,
        'top_cat_name'  => $top['name'] ?? null,
        'top_cat_share' => $top['share'] ?? null,
        'prior_revenue' => round($priorRevenue, 2),
        'delta_pct'     => $priorRevenue > 0 ? round(($tot - $priorRevenue) / $priorRevenue, 4) : null,
    ];

    return ['days' => $days, 'categories' => $categories, 'summary' => $summary];
}

/** Inclusive day count between two ISO dates (0 when to < from). */
function revenueDateSpan(string $from, string $to): int {
    $a = DateTime::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b || $b < $a) return 0;
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400) + 1;
}
