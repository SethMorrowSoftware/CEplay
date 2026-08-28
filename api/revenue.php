<?php
/**
 * Revenue Mix API — sales dollars by CATEGORY (area/department) over time,
 * straight from the CenterEdge MSSQL `Sales` table. Browsable by
 * Day / Week / Month / Year / Custom (same window model as Performance / Labor /
 * Card Loads / Ticket Trends).
 *
 *   GET  /api/revenue/settings — editable query (settings perm)
 *   PUT  /api/revenue/settings — save it (settings perm)
 *   POST /api/revenue/test     — reconcile a probe day + fingerprint (data_explorer perm:
 *          it returns raw POS dollars, so it rides the same gate as the Explorer)
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

/**
 * Hard row cap on the range query. MssqlClient::rows() STOPS FETCHING at its
 * limit and returns what it has — no error — so a window that exceeds this
 * would silently under-report revenue and look completely normal on screen.
 * Hitting it sets `truncated` on the payload and the page says the totals are
 * incomplete, the same contract Item Watch uses (ITEMS_MAX_ROWS).
 *
 * One row per (day, category-with-sales): a 380-day maximum window would need
 * ~105 categories selling every single day to reach this.
 */
const REVENUE_MAX_ROWS = 40000;

/** Most candidate lookup tables revenueCatNames() will read per request. */
const REVENUE_CAT_PROBE_MAX = 12;

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
 * The prior window this period is measured against, cut to the SAME STRETCH
 * when the current period is still in progress.
 *
 * Without this, a year-to-date view is compared against a FULL prior year —
 * eight months measured against twelve, which reads as a collapse every August.
 * So whenever today clipped the window short, clip the prior side at the
 * matching calendar point: for a Year view the same month/day a year back
 * (Feb 29 → Feb 28, via analyticsYoyPriorDate — the convention the dashboard's
 * year-over-year card uses), otherwise the same number of ELAPSED days, never
 * spilling past the prior period's own end.
 *
 * A completed period (any past offset) is untouched — full against full.
 *
 * @param array  $win perfResolveWindow() output
 * @param string $to  the window's end AFTER clamping to today
 * @return array{from:string,to:string,aligned:bool,days:int}
 */
function revenuePriorWindow(array $win, string $to): array {
    $from    = (string)($win['prev_from'] ?? '');
    $priorTo = (string)($win['prev_to'] ?? '');
    $aligned = false;
    if ($from !== '' && $priorTo !== '' && $to < (string)$win['to']) {
        // In progress: today cut the window short of its calendar end.
        if (($win['range'] ?? '') === 'year') {
            $cut = analyticsYoyPriorDate($to);
        } else {
            $elapsed = revenueDateSpan((string)$win['from'], $to);
            $cut = revenueDateAdd($from, max(0, $elapsed - 1));
        }
        if ($cut < $from) $cut = $from;
        if ($cut < $priorTo) { $priorTo = $cut; $aligned = true; }
    }
    return [
        'from'    => $from,
        'to'      => $priorTo,
        'aligned' => $aligned,
        'days'    => ($from === '' || $priorTo === '') ? 0 : revenueDateSpan($from, $priorTo),
    ];
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
 * How category-ish a table NAME looks. Used both to rank candidates and to
 * decide whether a bare `No`/`ID` key column is trustworthy on that table.
 */
function revenueCatTableScore(string $table): int {
    $l = strtolower($table);
    if ($l === 'categories' || $l === 'category') return 4;
    if (preg_match('/^[a-z]{0,6}cat(egory|egories|s)?$/', $l)) return 3;   // InvCategories, SubCategory…
    if (preg_match('/cat(egory|egories|no)/', $l)) return 2;
    return 1;                                                              // merely CONTAINS "cat"
}

/**
 * Best-effort CatNo → name map, discovered via INFORMATION_SCHEMA because the
 * lookup table's name varies by install. Failure is harmless: the report just
 * shows "Category N".
 *
 * PICKS THE BEST CANDIDATE, rather than the first one that parses. `LIKE
 * '%Cat%'` is a case-insensitive SUBSTRING match, so it also catches
 * ApplicationInfo, Allocations, Duplicates and friends — and the old code took
 * the first match in alphabetical order and returned, so any of those sorting
 * before "Categories" (they all do — 'A' < 'C') would starve the real table and
 * leave every category rendering as "Category 108". CLAUDE.md documents the
 * same false-positive shape in the Explorer's cost probe.
 *
 * Two defences: a bare `No`/`ID` key column is only accepted on a table whose
 * NAME actually looks like a category table (a generic int id on a random
 * table is not a category key), and every candidate is scored by how many of
 * the CatNos ACTUALLY IN THIS WINDOW it can name — evidence beats heuristics.
 * Ties break toward the more category-ish table name.
 *
 * @param int[] $wantedCats CatNos present in the data, for coverage scoring
 * @return array<string,string> CatNo (string) => name
 */
function revenueCatNames(MssqlClient $client, array $wantedCats = []): array {
    $ident = function (string $n): string {
        return '[' . preg_replace('/[^A-Za-z0-9_ #\-]/', '', $n) . ']';
    };
    try {
        $tables = $client->rows(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE '%Cat%' ORDER BY TABLE_NAME", 80);
    } catch (Exception $e) {
        return [];
    }

    // Most category-ish names first, so the early exit below lands on the real
    // table and the generic "contains cat" matches are only reached if it fails.
    $names = [];
    foreach ($tables as $t) $names[] = (string)$t['TABLE_NAME'];
    usort($names, function ($a, $b) {
        $d = revenueCatTableScore($b) <=> revenueCatTableScore($a);
        return $d !== 0 ? $d : strcasecmp($a, $b);
    });

    $wanted = [];
    foreach ($wantedCats as $c) $wanted[(string)(int)$c] = true;

    // Every probe costs a COLUMNS query plus a table read, and this runs on
    // each page load, so bound the sweep. Score order means the real table is
    // tried first and the early exits below almost always fire on it.
    $best = []; $bestScore = -1; $bestTableScore = -1; $probes = 0;
    foreach ($names as $tbl) {
        if ($probes >= REVENUE_CAT_PROBE_MAX) break;
        $probes++;
        $tblScore = revenueCatTableScore($tbl);
        try {
            $cols = $client->rows(
                "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . str_replace("'", "''", $tbl) . "'", 64);
            $noCol = null; $nameCol = null;
            foreach ($cols as $c) {
                $cn = (string)$c['COLUMN_NAME'];
                $ct = strtolower((string)$c['DATA_TYPE']);
                $isInt = in_array($ct, ['int', 'smallint', 'tinyint', 'bigint', 'numeric', 'decimal'], true);
                // A bare No/ID only counts on a table that really looks like a
                // category table; otherwise half the schema qualifies.
                $keyOk = preg_match('/^(CatNo|CategoryNo|CatID|CategoryID)$/i', $cn)
                    || ($tblScore >= 3 && preg_match('/^(No|ID)$/i', $cn));
                if ($noCol === null && $isInt && $keyOk) $noCol = $cn;
                if ($nameCol === null && in_array($ct, ['varchar', 'nvarchar', 'char', 'nchar'], true) && preg_match('/(Desc|Name|Title)/i', $cn)) $nameCol = $cn;
            }
            if (!$noCol || !$nameCol) continue;

            $map = [];
            foreach ($client->rows('SELECT ' . $ident($noCol) . ' AS no, ' . $ident($nameCol) . ' AS name FROM dbo.' . $ident($tbl), 1000) as $r) {
                $nm = trim((string)$r['name']);
                if ($nm !== '') $map[(string)(int)$r['no']] = $nm;
            }
            if (!$map) continue;

            // Score = how many of the categories on screen this table can name.
            // With nothing to check against, fall back to the name heuristic.
            $score = 0;
            if ($wanted) {
                foreach ($wanted as $k => $_) if (isset($map[$k])) $score++;
            }
            if ($score > $bestScore || ($score === $bestScore && $tblScore > $bestTableScore)) {
                $best = $map; $bestScore = $score; $bestTableScore = $tblScore;
            }
            // Names every category we're showing — nothing can beat that.
            if ($wanted && $score === count($wanted)) break;
            // A table literally called "Categories" that names at least one of
            // them is the lookup table; no point reading the rest of the schema
            // for the handful of CatNos it happens to be missing.
            if ($tblScore >= 4 && $score > 0) break;
        } catch (Exception $e) {
            continue;
        }
    }
    return $best;
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
        $total = 0.0; $discounts = 0.0; $wantedCats = [];
        foreach ($buckets as $b) { $total += $b['amount']; $discounts += $b['discounts']; $wantedCats[$b['cat']] = true; }
        $catNames = revenueCatNames($client, array_keys($wantedCats));

        $diag = [];
        $probe = function (string $label, string $sql) use (&$diag, $client) {
            try { $diag[$label] = $client->scalarText($sql); }
            catch (Exception $e) { $diag[$label] = 'error: ' . $e->getMessage(); }
        };
        $probe('server',   'SELECT @@SERVERNAME');
        $probe('database', 'SELECT DB_NAME()');

        // RECONCILE: the admin-editable query's total for the probe day against
        // a direct, independent SUM over the same day. A customised query that
        // filters, joins or groups differently shows up here as a gap instead
        // of quietly shifting every figure on the page.
        try {
            $refSql = "SELECT SUM(AmtSold) AS amount, SUM(Discounts) AS disc, COUNT(*) AS lines"
                . " FROM [CenterEdge].[dbo].[Sales]"
                . " WHERE ShiftDate >= :date AND ShiftDate < DATEADD(DAY, 1, :date)";
            $ref = $client->rows(MssqlClient::bindDate($refSql, $probeDate), 1);
            $refAmt = (float)($ref[0]['amount'] ?? 0);
            $gap = $total - $refAmt;
            $diag['reconcile_' . $probeDate] =
                'query $' . number_format($total, 2)
                . ' vs direct Sales SUM $' . number_format($refAmt, 2)
                . ' (' . (int)($ref[0]['lines'] ?? 0) . ' sale lines) — '
                . (abs($gap) < 0.005
                    ? 'MATCH to the penny.'
                    : 'DIFFERS by $' . number_format($gap, 2) . ' — the saved query is not summing the same rows.');
        } catch (Exception $e) {
            $diag['reconcile_' . $probeDate] = 'could not reconcile: ' . $e->getMessage();
        }

        // Is AmtSold NET of discounts, or gross? The page's discount RATE is
        // discounts / (AmtSold + discounts), which is only right if AmtSold is
        // net. A fully comped line settles it: net stores AmtSold 0 with the
        // price in Discounts; gross stores AmtSold == Discounts. Counted over a
        // 30-day window so there are enough discounted lines to judge.
        try {
            $sFrom = revenueDateAdd($probeDate, -30);
            $semSql = "SELECT"
                . " SUM(CASE WHEN Discounts > 0 THEN 1 ELSE 0 END) AS discounted,"
                . " SUM(CASE WHEN Discounts > 0 AND ABS(AmtSold) < 0.005 THEN 1 ELSE 0 END) AS net_like,"
                . " SUM(CASE WHEN Discounts > 0 AND AmtSold > 0.005 AND ABS(AmtSold - Discounts) < 0.005 THEN 1 ELSE 0 END) AS gross_like"
                . " FROM [CenterEdge].[dbo].[Sales]"
                . " WHERE ShiftDate >= :from AND ShiftDate < DATEADD(DAY, 1, :to)";
            $sem = $client->rows(MssqlClient::bindRange($semSql, $sFrom, $probeDate), 1);
            $nDisc  = (int)($sem[0]['discounted'] ?? 0);
            $nNet   = (int)($sem[0]['net_like'] ?? 0);
            $nGross = (int)($sem[0]['gross_like'] ?? 0);
            if ($nDisc === 0) {
                $verdict = 'no discounted lines in ' . $sFrom . '..' . $probeDate . ' — cannot tell; discount rates will read 0 anyway.';
            } elseif ($nNet > 0 && $nGross === 0) {
                $verdict = 'AmtSold looks NET of discounts — the discount rate shown on this page is correct.';
            } elseif ($nGross > 0 && $nNet === 0) {
                $verdict = 'AmtSold looks GROSS (already includes the discount) — the discount rate shown on this page is UNDERSTATED and the formula needs changing.';
            } else {
                $verdict = 'inconclusive — both patterns present; inspect a few discounted lines by hand.';
            }
            $diag['discount_basis'] = $nDisc . ' discounted lines over ' . $sFrom . '..' . $probeDate
                . ' (' . $nNet . ' fully-comped-at-zero, ' . $nGross . ' amount-equals-discount): ' . $verdict;
        } catch (Exception $e) {
            $diag['discount_basis'] = 'could not probe: ' . $e->getMessage();
        }
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

    // Compare like with like: an in-progress period is measured against the
    // same stretch of the previous one, not against its full span.
    $priorWin = revenuePriorWindow($win, $to);

    $meta = perfRangeMeta($win, $tzName);
    $meta['to']            = $to;
    $meta['prev_from']     = $priorWin['from'];
    $meta['prev_to']       = $priorWin['to'];
    $meta['prev_aligned']  = $priorWin['aligned'];
    // Whether TODAY cut this period short of its calendar end. Distinct from
    // prev_aligned, which only says the PRIOR side got trimmed: the two come
    // apart whenever the elapsed-day cut overshoots a shorter prior month (a
    // 30-day-elapsed March against a 28-day February leaves prev_aligned
    // false), and that is exactly when the reader most needs to be told the
    // period is still running. The label still says "August 2026" either way.
    $meta['in_progress']   = ($to < (string)$win['to']);
    $meta['compare_label'] = revenueCompareLabel($win['range'], $priorWin['aligned']);
    $base = ['window' => $meta, 'configured' => MssqlClient::isConfigured(), 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')];

    if (!MssqlClient::isConfigured()) {
        echo json_encode($base + ['days' => [], 'categories' => [], 'summary' => null]);
        return;
    }

    $sql = revenueRangeSql();
    try {
        $client = new MssqlClient();
        // GROUP BY (day, CatNo): a Year is ~366 × (tens of categories) rows —
        // comfortably under the cap. rows() truncates SILENTLY at its limit, so
        // compare the count and flag it rather than quietly under-reporting.
        $raw = $client->rows(MssqlClient::bindRange($sql, $from, $to), REVENUE_MAX_ROWS);
        $base['truncated'] = count($raw) >= REVENUE_MAX_ROWS;
        $buckets = revenueBucketsFromRows($raw);

        $prior = 0.0;
        if ($priorWin['from'] !== '' && $priorWin['to'] !== '' && $priorWin['days'] > 0) {
            $rawPrior = $client->rows(MssqlClient::bindRange($sql, $priorWin['from'], $priorWin['to']), REVENUE_MAX_ROWS);
            $base['prior_truncated'] = count($rawPrior) >= REVENUE_MAX_ROWS;
            foreach (revenueBucketsFromRows($rawPrior) as $b) {
                // Same day-window filter revenueCompose applies to the current
                // side. With the stock query the WHERE already bounds this, but
                // a customised one returning extra days must not inflate ONE
                // side of the comparison.
                if ($b['day'] < $priorWin['from'] || $b['day'] > $priorWin['to']) continue;
                $prior += $b['amount'];
            }
        }

        $wantedCats = [];
        foreach ($buckets as $b) $wantedCats[$b['cat']] = true;
        // Nothing on screen to name — don't sweep the schema for it.
        $catNames = $wantedCats ? revenueCatNames($client, array_keys($wantedCats)) : [];
    } catch (Exception $e) {
        echo json_encode($base + ['error' => $e->getMessage(), 'days' => [], 'categories' => [], 'summary' => null]);
        return;
    }

    echo json_encode($base + revenueCompose($dates, $buckets, $catNames, $prior, $priorWin,
        revenueCompareLabel($win['range'], $priorWin['aligned'])));
}

/**
 * How the comparison should read on screen. On a Year view the previous period
 * IS last year, so say so — and say when it was cut to the same stretch, since
 * "vs last year" over eight months means something different than over twelve.
 */
function revenueCompareLabel(string $range, bool $aligned): string {
    if ($range === 'year') return $aligned ? 'vs same stretch last year' : 'vs last year';
    if ($aligned)          return 'vs same stretch, previous period';
    return 'vs previous period';
}

/**
 * Compose the daily revenue trend + per-category breakdown + summary from the
 * (day, category) buckets. Pure — directly testable without a connection.
 *
 * @param string[] $dates every ISO date in the window, ascending
 * @param array $buckets revenueBucketsFromRows() output
 * @param array<string,string> $catNames CatNo => name
 * @param array|null $priorWindow revenuePriorWindow() output — the exact span
 *        $priorRevenue was measured over, echoed so the UI can state it
 * @return array{days:array, categories:array, summary:array}
 */
function revenueCompose(array $dates, array $buckets, array $catNames, float $priorRevenue,
                        ?array $priorWindow = null, string $compareLabel = 'vs previous period'): array {
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
    $daysWithSales = 0;
    foreach ($dates as $d) {
        $amt = round($dayIndex[$d]['amount'], 2);
        if (abs($amt) > 0.005) $daysWithSales++;
        $days[] = ['date' => $d, 'amount' => $amt];
    }

    // Share is measured against the POSITIVE revenue pool, not the net total.
    // A category can go negative (refunds/voids outstripping sales in the
    // window), which drags the net total toward zero while the positive
    // categories keep their full value — dividing by the net then produces
    // shares like 500% and -400%. Against the positive pool every positive
    // share is <= 100% by construction, and a refund-heavy category reads as a
    // sane negative fraction of what the others took in. When nothing is
    // negative the pool EQUALS the net total, so this is a no-op for every
    // ordinary period.
    $posPool = 0.0;
    foreach ($byCat as $a) if ($a['amount'] > 0) $posPool += $a['amount'];

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
            'share'        => $posPool > 0 ? round($a['amount'] / $posPool, 4) : 0,
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
        // per_day averages over CALENDAR days (the convention Card Loads /
        // Ticket Trends / Redemption share). days_with_sales is the ACTUAL
        // count behind it, so a period containing days the venue was closed
        // explains its own average instead of just reading low.
        'days_with_sales' => $daysWithSales,
        'num_cats'      => count($categories),
        'top_cat'       => $top['cat'] ?? null,
        'top_cat_name'  => $top['name'] ?? null,
        'top_cat_share' => $top['share'] ?? null,
        'prior_revenue' => round($priorRevenue, 2),
        'delta_pct'     => $priorRevenue > 0 ? round(($tot - $priorRevenue) / $priorRevenue, 4) : null,
        // The exact span the prior figure covers, so the card can name it
        // instead of leaving "previous period" to the reader's imagination.
        'prior_from'    => $priorWindow['from'] ?? null,
        'prior_to'      => $priorWindow['to'] ?? null,
        'prior_days'    => $priorWindow['days'] ?? null,
        'prior_aligned' => (bool)($priorWindow['aligned'] ?? false),
        'compare_label' => $compareLabel,
    ];

    return ['days' => $days, 'categories' => $categories, 'summary' => $summary];
}

/** ISO date $n days after $date. */
function revenueDateAdd(string $date, int $n): string {
    $d = DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$d) return $date;
    if ($n !== 0) $d->modify(($n > 0 ? '+' : '') . $n . ' days');
    return $d->format('Y-m-d');
}

/** Inclusive day count between two ISO dates (0 when to < from). */
function revenueDateSpan(string $from, string $to): int {
    $a = DateTime::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b || $b < $a) return 0;
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400) + 1;
}
