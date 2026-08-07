<?php
/**
 * Item Watch API — pin specific POS inventory items (or the set of InvNo values
 * that make up a deal) and watch how they sell: units, dollars, discounts,
 * cost/margin, the trend across the period, and the change vs the previous
 * period. Plus a best-sellers leaderboard for the same window, so "which items
 * are selling best" is answerable without knowing an InvNo up front.
 *
 * Modeled on the Promotional Cards page: the DEFINITIONS (what you're watching)
 * live in SQLite; every sales number is computed LIVE from the venue's
 * CenterEdge MSSQL `Sales` table by InvNo. Nothing is duplicated locally.
 *
 *   GET    /api/items?range=&offset=&from=&to=  — watchlist + live stats (cards)
 *   GET    /api/items/{id}                      — one definition (for the editor)
 *   POST   /api/items                           — create (items_manage)
 *   PUT    /api/items/{id}                      — update (items_manage)
 *   DELETE /api/items/{id}                      — delete (items_manage)
 *   GET    /api/items/detail?id=&range=…        — drill-down: trend, per-InvNo
 *                                                 breakdown, since-launch totals
 *   GET    /api/items/top?range=…&limit=        — best sellers (analytics + view_revenue)
 *   GET    /api/items/settings                  — editable queries (settings)
 *   PUT    /api/items/settings                  — save them (settings)
 *   POST   /api/items/test                      — probe an InvNo + fingerprint (data_explorer)
 *
 * Grain is DAY, never hour: `Sales.ShiftDate` is a business day stamped at
 * midnight (not a clock time), so an hour-of-day panel here would be fiction —
 * same honesty as Revenue Mix and Ticket Trends. Day / Week / Month / Year /
 * Custom comes from the shared window model (perfResolveWindow), so this page
 * browses time exactly like Performance / Labor / Revenue Mix.
 *
 * All three queries are admin-editable single SELECTs (assertReadOnly) with
 * required placeholders, and share the Go-Kart Labor page's MSSQL connection.
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/analytics.php';

/**
 * Per (local day, InvNo) for the watched inventory numbers: units, dollars,
 * discount dollars and cost of goods. AmtSold / QtySold / Discounts / CostSold
 * are all real columns on this venue's Sales table. ShiftDate is a business day
 * at midnight, so CONVERT(...,120) yields the same local day the app bins.
 */
const ITEMS_DEFAULT_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), ShiftDate, 120) AS day,\n       InvNo AS inv,\n       SUM(QtySold) AS qty,\n       SUM(AmtSold) AS amount,\n       SUM(Discounts) AS discounts,\n       SUM(CostSold) AS cost\nFROM [CenterEdge].[dbo].[Sales]\nWHERE ShiftDate >= :from\n  AND ShiftDate < DATEADD(DAY, 1, :to)\n  AND InvNo IN (:invnos)\nGROUP BY CONVERT(VARCHAR(10), ShiftDate, 120), InvNo";

/**
 * Period TOTALS per InvNo (no day grain) for the watched inventory numbers.
 * Used where only the totals are wanted — the previous-period comparison and
 * the since-launch figures on the detail view — so a multi-year lookback costs
 * one row per item instead of one per item per day.
 */
const ITEMS_DEFAULT_TOTALS_SQL = "SELECT InvNo AS inv,\n       SUM(QtySold) AS qty,\n       SUM(AmtSold) AS amount,\n       SUM(Discounts) AS discounts,\n       SUM(CostSold) AS cost,\n       COUNT(DISTINCT CONVERT(VARCHAR(10), ShiftDate, 120)) AS days_sold\nFROM [CenterEdge].[dbo].[Sales]\nWHERE ShiftDate >= :from\n  AND ShiftDate < DATEADD(DAY, 1, :to)\n  AND InvNo IN (:invnos)\nGROUP BY InvNo";

/**
 * Best sellers for the window across the WHOLE catalog (no InvNo filter) —
 * the leaderboard, and how an operator finds the InvNo of something worth
 * watching. Ranked by revenue; TOP keeps the row count bounded no matter how
 * many items the venue stocks.
 */
const ITEMS_DEFAULT_TOP_SQL = "SELECT TOP 150 InvNo AS inv,\n       SUM(QtySold) AS qty,\n       SUM(AmtSold) AS amount,\n       SUM(Discounts) AS discounts,\n       SUM(CostSold) AS cost\nFROM [CenterEdge].[dbo].[Sales]\nWHERE ShiftDate >= :from\n  AND ShiftDate < DATEADD(DAY, 1, :to)\nGROUP BY InvNo\nORDER BY :rankexpr DESC";

/**
 * One row per CALENDAR PERIOD (week/month/quarter/year…) for the watched
 * inventory numbers — the "how is this item doing over various periods" table.
 *
 * Grouping by the period IN SQL rather than rolling up day rows in PHP is what
 * makes a 5-year lookback cheap: the row count is the number of periods asked
 * for (12, 24…), not days × items. `:periodexpr` is substituted from a
 * server-side allowlist (ITEMS_PERIOD_EXPR) — never from user input — so it is
 * a fixed expression, not an injection surface.
 */
const ITEMS_DEFAULT_HISTORY_SQL = "SELECT :periodexpr AS period,\n       SUM(QtySold) AS qty,\n       SUM(AmtSold) AS amount,\n       SUM(Discounts) AS discounts,\n       SUM(CostSold) AS cost,\n       COUNT(DISTINCT CONVERT(VARCHAR(10), ShiftDate, 120)) AS days_sold\nFROM [CenterEdge].[dbo].[Sales]\nWHERE ShiftDate >= :from\n  AND ShiftDate < DATEADD(DAY, 1, :to)\n  AND InvNo IN (:invnos)\nGROUP BY :periodexpr";

/**
 * The calendar-period expressions the history query may group by, and the PHP
 * side's matching key format. Server-side constants, never user input.
 *
 * The week expression deliberately avoids DATEPART(WEEKDAY, …), which shifts
 * with the connection's SET DATEFIRST: counting days from a known Sunday
 * (1900-01-07) yields Sunday-start weeks on any server, matching the
 * Sunday-start weeks the rest of the app uses.
 */
const ITEMS_PERIOD_EXPR = [
    'day'     => "CONVERT(VARCHAR(10), ShiftDate, 120)",
    'week'    => "CONVERT(VARCHAR(10), DATEADD(DAY, -(DATEDIFF(DAY, '19000107', ShiftDate) % 7), ShiftDate), 120)",
    'month'   => "CONVERT(VARCHAR(7), ShiftDate, 120)",
    'quarter' => "CONVERT(VARCHAR(4), YEAR(ShiftDate)) + '-Q' + CONVERT(VARCHAR(1), DATEPART(QUARTER, ShiftDate))",
    'year'    => "CONVERT(VARCHAR(4), YEAR(ShiftDate))",
];

/** How many periods of history each grain will look back over, at most. */
const ITEMS_PERIOD_MAX = ['day' => 90, 'week' => 104, 'month' => 60, 'quarter' => 40, 'year' => 20];

/** Default period count per grain (a useful first view without any fiddling). */
const ITEMS_PERIOD_DEFAULT = ['day' => 30, 'week' => 12, 'month' => 12, 'quarter' => 8, 'year' => 5];

/**
 * What the best-sellers leaderboard may be ranked by. Allowlisted server-side
 * for the same reason as ITEMS_PERIOD_EXPR — the value is chosen by key, so the
 * SQL fragment can never come from the request.
 */
const ITEMS_RANK_EXPR = [
    'revenue' => 'SUM(AmtSold)',
    'units'   => 'SUM(QtySold)',
    'margin'  => 'SUM(AmtSold) - SUM(CostSold)',
];

/** Most InvNo values one watch entry may bundle (a deal, not a whole category). */
const ITEMS_MAX_INV_PER_ENTRY = 50;

/**
 * Most InvNo values one request will pull stats for, across the whole
 * watchlist. The day-grain query returns up to (days × items) rows, so this
 * bounds the worst case. Entries past the cap are listed without stats and the
 * response says how many were skipped — never silently dropped.
 */
const ITEMS_MAX_INV_TOTAL = 120;

/** Hard row cap on the day-grain query; hitting it sets `truncated` on the payload. */
const ITEMS_MAX_ROWS = 60000;

/** Furthest back a "since launch" lookback will reach (matches the custom-range cap). */
const ITEMS_LIFETIME_MAX_DAYS = 1830;

function handleItems(string $method, array $parts, ?array $input): void {
    // View gate for every method; mutations require items_manage below.
    Auth::requireAccess('analytics');

    $action = $parts[0] ?? '';

    // Sub-resources (word segments) take precedence over a numeric entry id.
    if ($action === 'settings') {
        if ($method === 'GET') { itemsGetSettings(); return; }
        if ($method === 'PUT') { itemsPutSettings($input ?? []); return; }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); return;
    }
    if ($action === 'test') {
        if ($method === 'POST') { itemsTest($input); return; }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); return;
    }
    if ($action === 'detail') {
        if ($method === 'GET') { itemsDetail(); return; }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); return;
    }
    if ($action === 'top') {
        if ($method === 'GET') { itemsTop(); return; }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); return;
    }
    if ($action === 'history') {
        if ($method === 'GET') { itemsHistory(); return; }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); return;
    }

    $itemId = is_numeric($action) ? (int)$action : null;

    switch ($method) {
        case 'GET':
            if ($itemId) { itemsGet($itemId); } else { itemsList(); }
            break;
        case 'POST':
            Auth::requireAccess('items_manage');
            itemsCreate($input);
            break;
        case 'PUT':
            Auth::requireAccess('items_manage');
            if (!$itemId) { http_response_code(400); echo json_encode(['error' => 'Item ID required']); return; }
            itemsUpdate($itemId, $input);
            break;
        case 'DELETE':
            Auth::requireAccess('items_manage');
            if (!$itemId) { http_response_code(400); echo json_encode(['error' => 'Item ID required']); return; }
            itemsDelete($itemId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

/** The editable day-grain query (config override, else the shipped default). */
function itemsRangeSql(): string {
    return DB::getConfig('items_range_sql') ?: ITEMS_DEFAULT_RANGE_SQL;
}

/** The editable totals-only query (prior period + since-launch). */
function itemsTotalsSql(): string {
    return DB::getConfig('items_totals_sql') ?: ITEMS_DEFAULT_TOTALS_SQL;
}

/** The editable best-sellers query. */
function itemsTopSql(): string {
    return DB::getConfig('items_top_sql') ?: ITEMS_DEFAULT_TOP_SQL;
}

/** The editable calendar-period history query. */
function itemsHistorySql(): string {
    return DB::getConfig('items_history_sql') ?: ITEMS_DEFAULT_HISTORY_SQL;
}

/**
 * Substitute :periodexpr with the allowlisted grouping expression for $grain.
 * The replacement is a server constant chosen by key, so nothing from the
 * request reaches the SQL text.
 */
function itemsBindPeriodExpr(string $sql, string $grain): string {
    if (!isset(ITEMS_PERIOD_EXPR[$grain])) {
        throw new RuntimeException('Unknown period grain: ' . $grain);
    }
    if (strpos($sql, ':periodexpr') === false) {
        throw new RuntimeException('Query must contain the :periodexpr placeholder.');
    }
    return str_replace(':periodexpr', ITEMS_PERIOD_EXPR[$grain], $sql);
}

/**
 * Substitute :rankexpr with the allowlisted ORDER BY expression. A stored query
 * that predates the ranking control (or an admin who hard-coded their own
 * ORDER BY) simply has no placeholder — that is not an error, it just means the
 * ranking is fixed, which itemsTop() reports back as `rank_locked`.
 */
function itemsBindRankExpr(string $sql, string $rank): string {
    if (strpos($sql, ':rankexpr') === false) {
        return $sql;
    }
    if (!isset(ITEMS_RANK_EXPR[$rank])) {
        throw new RuntimeException('Unknown ranking: ' . $rank);
    }
    return str_replace(':rankexpr', ITEMS_RANK_EXPR[$rank], $sql);
}

// ------------------------------------------------------------------
// Definition normalization + validation
// ------------------------------------------------------------------

/** Normalize a DB watch row for the client (inv_nos as an array of strings). */
function itemsRow(array $r): array {
    $invNos = itemsSplitInvNos((string)$r['inv_nos']);
    return [
        'id'         => (int)$r['id'],
        'name'       => (string)$r['name'],
        'inv_nos'    => $invNos,
        'tag'        => (string)($r['tag'] ?? ''),
        'start_date' => (string)($r['start_date'] ?? ''),
        'end_date'   => (string)($r['end_date'] ?? ''),
        'notes'      => (string)($r['notes'] ?? ''),
        'created_at' => (string)$r['created_at'],
        'updated_at' => (string)$r['updated_at'],
    ];
}

/** Split a stored/submitted InvNo list into normalized, de-duplicated strings. */
function itemsSplitInvNos(string $raw): array {
    $parts = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $seen = [];
    foreach ($parts as $p) {
        $p = ltrim($p, '0');
        $seen[$p === '' ? '0' : $p] = true;
    }
    // array_keys() hands back ints for numeric keys — cast so InvNos stay
    // strings all the way to the JSON (matching the string-keyed `names` map).
    return array_map('strval', array_keys($seen));
}

/**
 * Validate + normalize a create/update payload into
 * [name, invNosCsv, tag, startDate, endDate, notes].
 * Throws RuntimeException (→ 422) on any bad field.
 */
function itemsValidateInput(?array $input): array {
    if (!$input) { throw new RuntimeException('Request body required.'); }
    $name  = Validator::requireString($input, 'name', 100);
    $tag   = Validator::optionalString($input, 'tag', 40);
    $notes = Validator::optionalString($input, 'notes', 1000);

    $raw = $input['inv_nos'] ?? '';
    if (is_array($raw)) { $raw = implode(',', $raw); }
    $raw = trim((string)$raw);
    if ($raw === '') {
        throw new RuntimeException('Enter at least one inventory number (InvNo).');
    }
    // Anything that isn't a digit or a separator is a typo worth reporting,
    // rather than silently splitting "71a57" into two items.
    if (preg_match('/[^0-9,;\s]/', $raw)) {
        throw new RuntimeException('Inventory numbers must be whole numbers separated by commas.');
    }
    $invNos = itemsSplitInvNos($raw);
    if (!$invNos) {
        throw new RuntimeException('Enter at least one inventory number (InvNo).');
    }
    if (count($invNos) > ITEMS_MAX_INV_PER_ENTRY) {
        throw new RuntimeException('That is more than ' . ITEMS_MAX_INV_PER_ENTRY
            . ' inventory numbers in one entry — split it into separate items.');
    }
    foreach ($invNos as $n) {
        if (strlen($n) > 9) {
            throw new RuntimeException('Inventory number "' . $n . '" is too long.');
        }
    }

    $dates = [];
    foreach (['start_date', 'end_date'] as $k) {
        $v = trim((string)($input[$k] ?? ''));
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw new RuntimeException(str_replace('_', ' ', $k) . ' must be YYYY-MM-DD (or left blank).');
        }
        $dates[$k] = $v;
    }
    if ($dates['start_date'] !== '' && $dates['end_date'] !== '' && $dates['end_date'] < $dates['start_date']) {
        throw new RuntimeException('"Ends" must be on or after "Starts".');
    }

    return [$name, implode(',', $invNos), $tag, $dates['start_date'], $dates['end_date'], $notes];
}

// ------------------------------------------------------------------
// CRUD
// ------------------------------------------------------------------

function itemsGet(int $itemId): void {
    $row = DB::queryOne('SELECT * FROM watch_items WHERE id = :p0', [$itemId]);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Watched item not found']); return; }
    echo json_encode(itemsRow($row));
}

function itemsCreate(?array $input): void {
    list($name, $invNos, $tag, $start, $end, $notes) = itemsValidateInput($input);
    DB::execute(
        'INSERT INTO watch_items (name, inv_nos, tag, start_date, end_date, notes)
         VALUES (:p0, :p1, :p2, :p3, :p4, :p5)',
        [$name, $invNos, $tag, $start, $end, $notes]
    );
    $itemId = DB::lastInsertId();
    DB::auditLog('admin', 'watch_item_created', Auth::userId(), [
        'item_id' => $itemId, 'name' => $name, 'inv_nos' => $invNos,
    ]);
    http_response_code(201);
    itemsGet($itemId);
}

function itemsUpdate(int $itemId, ?array $input): void {
    $existing = DB::queryOne('SELECT id FROM watch_items WHERE id = :p0', [$itemId]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Watched item not found']); return; }
    list($name, $invNos, $tag, $start, $end, $notes) = itemsValidateInput($input);
    DB::execute(
        'UPDATE watch_items SET name = :p0, inv_nos = :p1, tag = :p2, start_date = :p3,
             end_date = :p4, notes = :p5, updated_at = datetime(\'now\') WHERE id = :p6',
        [$name, $invNos, $tag, $start, $end, $notes, $itemId]
    );
    DB::auditLog('admin', 'watch_item_updated', Auth::userId(), [
        'item_id' => $itemId, 'name' => $name, 'inv_nos' => $invNos,
    ]);
    itemsGet($itemId);
}

function itemsDelete(int $itemId): void {
    $existing = DB::queryOne('SELECT name FROM watch_items WHERE id = :p0', [$itemId]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Watched item not found']); return; }
    DB::execute('DELETE FROM watch_items WHERE id = :p0', [$itemId]);
    DB::auditLog('admin', 'watch_item_deleted', Auth::userId(), [
        'item_id' => $itemId, 'name' => (string)$existing['name'],
    ]);
    echo json_encode(['success' => true, 'deleted_id' => $itemId]);
}

// ------------------------------------------------------------------
// Row shaping (tolerant of column naming: named keys any case, else position)
// ------------------------------------------------------------------

/** Read a cell from a result row by name (case-insensitive), else by position. */
function itemsCell(array $row, array $vals, string $name, int $pos) {
    foreach ($row as $k => $v) {
        if (strcasecmp((string)$k, $name) === 0) return $v;
    }
    return $vals[$pos] ?? null;
}

/** Normalize an ISO-ish day value to YYYY-MM-DD, or '' when unparseable. */
function itemsNormDay($raw): string {
    $s = trim((string)$raw);
    $day = substr($s, 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return $day;
    $ts = strtotime($s);
    return $ts === false ? '' : date('Y-m-d', $ts);
}

/**
 * Normalize the day-grain query's rows into (day, inv, qty, amount, discounts, cost).
 *
 * @return array<int,array{day:string,inv:string,qty:float,amount:float,discounts:float,cost:float}>
 */
function itemsBucketsFromRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $day = itemsNormDay(itemsCell($row, $vals, 'day', 0));
        if ($day === '') continue;
        $out[] = [
            'day'       => $day,
            'inv'       => itemsNormInv(itemsCell($row, $vals, 'inv', 1)),
            'qty'       => (float)itemsCell($row, $vals, 'qty', 2),
            'amount'    => (float)itemsCell($row, $vals, 'amount', 3),
            'discounts' => (float)itemsCell($row, $vals, 'discounts', 4),
            'cost'      => (float)itemsCell($row, $vals, 'cost', 5),
        ];
    }
    return $out;
}

/**
 * Normalize the totals-only query's rows into per-InvNo totals, keyed by InvNo.
 *
 * @return array<string,array{qty:float,amount:float,discounts:float,cost:float,days_sold:int}>
 */
function itemsTotalsFromRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $inv = itemsNormInv(itemsCell($row, $vals, 'inv', 0));
        if ($inv === '') continue;
        $out[$inv] = [
            'qty'       => (float)itemsCell($row, $vals, 'qty', 1),
            'amount'    => (float)itemsCell($row, $vals, 'amount', 2),
            'discounts' => (float)itemsCell($row, $vals, 'discounts', 3),
            'cost'      => (float)itemsCell($row, $vals, 'cost', 4),
            'days_sold' => (int)itemsCell($row, $vals, 'days_sold', 5),
        ];
    }
    return $out;
}

/** Normalize an InvNo cell to the same digit form the definitions are stored in. */
function itemsNormInv($raw): string {
    $s = trim((string)$raw);
    if (!preg_match('/^\d+$/', $s)) {
        // Tolerate "7157.0" and the like from loosely-typed drivers.
        if (!preg_match('/^(\d+)(?:\.0+)?$/', $s, $m)) return '';
        $s = $m[1];
    }
    $s = ltrim($s, '0');
    return $s === '' ? '0' : $s;
}

// ------------------------------------------------------------------
// Aggregation (pure — directly testable without a connection)
// ------------------------------------------------------------------

/**
 * Index day-grain buckets per InvNo: period totals plus a per-period-key
 * series (the key is the day, or the month on a Year-style window).
 *
 * @param array<string,bool> $daySet the days actually requested
 * @return array<string,array{qty:float,amount:float,discounts:float,cost:float,days:array<string,bool>,series:array<string,array{qty:float,amount:float}>}>
 */
function itemsIndexBuckets(array $buckets, array $daySet, string $grain): array {
    $idx = [];
    foreach ($buckets as $b) {
        if (!isset($daySet[$b['day']])) continue; // only days we asked for
        $inv = $b['inv'];
        if ($inv === '') continue;
        if (!isset($idx[$inv])) {
            $idx[$inv] = ['qty' => 0.0, 'amount' => 0.0, 'discounts' => 0.0, 'cost' => 0.0,
                          'days' => [], 'series' => []];
        }
        $idx[$inv]['qty']       += $b['qty'];
        $idx[$inv]['amount']    += $b['amount'];
        $idx[$inv]['discounts'] += $b['discounts'];
        $idx[$inv]['cost']      += $b['cost'];
        if ($b['qty'] != 0.0 || $b['amount'] != 0.0) {
            $idx[$inv]['days'][$b['day']] = true;
        }
        $key = $grain === 'month' ? substr($b['day'], 0, 7) : $b['day'];
        if (!isset($idx[$inv]['series'][$key])) {
            $idx[$inv]['series'][$key] = ['qty' => 0.0, 'amount' => 0.0];
        }
        $idx[$inv]['series'][$key]['qty']    += $b['qty'];
        $idx[$inv]['series'][$key]['amount'] += $b['amount'];
    }
    return $idx;
}

/** The ordered period keys (days, or months on a Year-style window). */
function itemsPeriodKeys(array $dates, string $grain): array {
    if ($grain !== 'month') return $dates;
    $keys = []; $seen = [];
    foreach ($dates as $d) {
        $m = substr($d, 0, 7);
        if (!isset($seen[$m])) { $seen[$m] = true; $keys[] = $m; }
    }
    return $keys;
}

/**
 * Roll one entry's InvNo set up into its headline stats + zero-filled series.
 *
 * @param string[] $invNos    the entry's inventory numbers
 * @param array    $idx       itemsIndexBuckets() output
 * @param string[] $keys      ordered period keys the series must cover
 * @param array    $priorTot  itemsTotalsFromRows() output for the previous period
 */
function itemsStatsFor(array $invNos, array $idx, array $keys, array $priorTot): array {
    $qty = 0.0; $amount = 0.0; $discounts = 0.0; $cost = 0.0;
    $days = []; $byKey = [];
    $priorQty = 0.0; $priorAmount = 0.0; $priorSeen = false;

    foreach ($invNos as $inv) {
        if (isset($idx[$inv])) {
            $e = $idx[$inv];
            $qty += $e['qty']; $amount += $e['amount'];
            $discounts += $e['discounts']; $cost += $e['cost'];
            foreach ($e['days'] as $d => $_) { $days[$d] = true; }
            foreach ($e['series'] as $k => $v) {
                if (!isset($byKey[$k])) $byKey[$k] = ['qty' => 0.0, 'amount' => 0.0];
                $byKey[$k]['qty']    += $v['qty'];
                $byKey[$k]['amount'] += $v['amount'];
            }
        }
        if (isset($priorTot[$inv])) {
            $priorSeen = true;
            $priorQty    += $priorTot[$inv]['qty'];
            $priorAmount += $priorTot[$inv]['amount'];
        }
    }

    $series = [];
    $bestKey = null; $bestQty = null;
    foreach ($keys as $k) {
        $v = $byKey[$k] ?? ['qty' => 0.0, 'amount' => 0.0];
        $series[] = ['key' => $k, 'qty' => round($v['qty'], 2), 'amount' => round($v['amount'], 2)];
        if ($bestQty === null || $v['qty'] > $bestQty) { $bestQty = $v['qty']; $bestKey = $k; }
    }

    $margin = $amount - $cost;
    return [
        'qty'              => round($qty, 2),
        'amount'           => round($amount, 2),
        'discounts'        => round($discounts, 2),
        'cost'             => round($cost, 2),
        'margin'           => round($margin, 2),
        'margin_pct'       => $amount > 0 ? round($margin / $amount, 4) : null,
        'avg_price'        => $qty > 0 ? round($amount / $qty, 2) : null,
        'days_with_sales'  => count($days),
        'best_key'         => ($bestQty !== null && $bestQty > 0) ? $bestKey : null,
        'best_qty'         => ($bestQty !== null && $bestQty > 0) ? round($bestQty, 2) : null,
        // prior_* is null (not 0) when the previous period returned nothing for
        // these items at all, so the UI can say "no prior data" instead of
        // drawing a −100%.
        'prior_qty'        => $priorSeen ? round($priorQty, 2) : null,
        'prior_amount'     => $priorSeen ? round($priorAmount, 2) : null,
        'qty_delta_pct'    => ($priorSeen && $priorQty > 0) ? round(($qty - $priorQty) / $priorQty, 4) : null,
        'amount_delta_pct' => ($priorSeen && $priorAmount > 0) ? round(($amount - $priorAmount) / $priorAmount, 4) : null,
        'series'           => $series,
    ];
}

// ------------------------------------------------------------------
// Window plumbing
// ------------------------------------------------------------------

/**
 * Resolve the requested window and the list of local dates it covers.
 * Shared by the list, detail and top endpoints so they always agree.
 *
 * @return array{meta:array,dates:string[],from:string,to:string,grain:string,win:array}
 */
function itemsWindow(): array {
    list($tz, $tzName) = perfTimezone();
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $win  = perfResolveWindow($tz);
    $from = $win['from'];
    $to   = $win['to'];
    if ($to > $today) $to = $today;
    $span = itemsDateSpan($from, $to);
    if ($span < 1) throw new RuntimeException('The selected period is entirely in the future.');
    if ($span > 380) throw new RuntimeException('Ranges longer than a year aren\'t supported here — pick a year or less.');

    $dates = [];
    $cur = DateTime::createFromFormat('!Y-m-d', $from, $tz);
    for ($i = 0; $i < $span; $i++) { $dates[] = $cur->format('Y-m-d'); $cur->modify('+1 day'); }

    $meta = perfRangeMeta($win, $tzName);
    $meta['to'] = $to;

    // What "vs last" means: the immediately preceding span (default), or the
    // same calendar dates a year earlier. For a seasonal venue the second is
    // usually the honest comparison — an August week against the previous week
    // says less than August against last August.
    $basis = (isset($_GET['compare']) && (string)$_GET['compare'] === 'yoy') ? 'yoy' : 'prev';
    if ($basis === 'yoy') {
        // analyticsYoyPriorDate clamps Feb 29 to Feb 28 rather than rolling
        // into March — the same convention the YoY dashboard card uses.
        $win['prev_from'] = analyticsYoyPriorDate($win['from']);
        $win['prev_to']   = analyticsYoyPriorDate($to);
    }
    $meta['compare']       = $basis;
    $meta['compare_label'] = $basis === 'yoy' ? 'same period last year' : 'previous period';
    $meta['prev_from']     = $win['prev_from'];
    $meta['prev_to']       = $win['prev_to'];
    // Sales is a business-day ledger, so an "hour" window (a single day) still
    // reports at day grain — say so rather than implying an hourly breakdown.
    $grain = $win['granularity'] === 'month' ? 'month' : 'day';
    $meta['series_grain'] = $grain;

    return ['meta' => $meta, 'dates' => $dates, 'from' => $from, 'to' => $to, 'grain' => $grain, 'win' => $win];
}

/** Inclusive day count between two ISO dates (0 when to < from). */
function itemsDateSpan(string $from, string $to): int {
    $a = DateTime::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b || $b < $a) return 0;
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400) + 1;
}

/** Bind :from/:to and the :invnos list into one of the editable queries. */
function itemsBindSql(string $sql, string $from, string $to, ?array $invNos = null): string {
    $bound = MssqlClient::bindRange($sql, $from, $to);
    if ($invNos !== null) {
        $bound = MssqlClient::bindIntList($bound, ':invnos', $invNos);
    }
    return $bound;
}

// ------------------------------------------------------------------
// Calendar periods (the multi-period history table)
// ------------------------------------------------------------------

/**
 * The period key for a date, in the SAME format ITEMS_PERIOD_EXPR produces in
 * SQL — these two must agree or the zero-fill silently drops every row.
 */
function itemsPeriodKey(DateTime $d, string $grain): string {
    switch ($grain) {
        case 'day':     return $d->format('Y-m-d');
        case 'week':    return (clone $d)->modify('-' . (int)$d->format('w') . ' days')->format('Y-m-d');
        case 'month':   return $d->format('Y-m');
        case 'quarter': return $d->format('Y') . '-Q' . (int)ceil((int)$d->format('n') / 3);
        default:        return $d->format('Y');
    }
}

/** First day of the calendar period containing $d. */
function itemsPeriodStart(DateTime $d, string $grain, DateTimeZone $tz): DateTime {
    $s = (clone $d)->setTime(0, 0, 0);
    switch ($grain) {
        case 'day':   return $s;
        case 'week':  return $s->modify('-' . (int)$s->format('w') . ' days');
        case 'month': return DateTime::createFromFormat('!Y-m-d', $s->format('Y-m') . '-01', $tz);
        case 'quarter':
            $q = (int)ceil((int)$s->format('n') / 3);
            return DateTime::createFromFormat('!Y-m-d', sprintf('%s-%02d-01', $s->format('Y'), ($q - 1) * 3 + 1), $tz);
        default:      return DateTime::createFromFormat('!Y-m-d', $s->format('Y') . '-01-01', $tz);
    }
}

/** How far one period of $grain advances. */
function itemsPeriodStep(string $grain): string {
    switch ($grain) {
        case 'day':     return '1 day';
        case 'week':    return '7 days';
        case 'month':   return '1 month';
        case 'quarter': return '3 months';
        default:        return '1 year';
    }
}

/**
 * The last $count calendar periods ending with the one in progress right now.
 * Returns the ordered period descriptors (oldest first) plus the overall date
 * range to scan. The newest entry is normally PARTIAL — `complete` says so, so
 * the UI never reads "this month is down 60%" on the 10th.
 *
 * @return array{periods:array<int,array{key:string,start:string,end:string,complete:bool}>,from:string,to:string}
 */
function itemsPeriodSeries(DateTimeZone $tz, string $grain, int $count): array {
    $today = (new DateTime('now', $tz))->setTime(0, 0, 0);
    $step = itemsPeriodStep($grain);
    $starts = [];
    $cursor = itemsPeriodStart($today, $grain, $tz);
    for ($i = 0; $i < $count; $i++) {
        array_unshift($starts, clone $cursor);
        $cursor = itemsPeriodStart((clone $cursor)->modify('-' . $step), $grain, $tz);
    }
    $todayStr = $today->format('Y-m-d');
    $periods = [];
    foreach ($starts as $start) {
        $endExcl = itemsPeriodStart((clone $start)->modify('+' . $step), $grain, $tz);
        $end = (clone $endExcl)->modify('-1 day')->format('Y-m-d');
        $periods[] = [
            'key'      => itemsPeriodKey($start, $grain),
            'start'    => $start->format('Y-m-d'),
            'end'      => $end,
            'complete' => $end < $todayStr,
        ];
    }
    return ['periods' => $periods, 'from' => $periods[0]['start'], 'to' => $todayStr];
}

/**
 * Multi-period history for ONE watched entry (?id=) or ANY ad-hoc set of
 * inventory numbers (?inv=7157,7158) — so an item can be examined straight off
 * the best-sellers leaderboard without pinning it first.
 *
 * Params: id | inv, grain (day|week|month|quarter|year), count.
 */
function itemsHistory(): void {
    $hideMoney = !Auth::hasPermission('view_revenue');

    $grain = isset($_GET['grain']) ? (string)$_GET['grain'] : 'month';
    if (!isset(ITEMS_PERIOD_EXPR[$grain])) { $grain = 'month'; }
    $count = isset($_GET['count']) ? (int)$_GET['count'] : ITEMS_PERIOD_DEFAULT[$grain];
    $count = max(2, min(ITEMS_PERIOD_MAX[$grain], $count));

    // Either a saved entry or a bare InvNo list.
    $item = null;
    if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
        $row = DB::queryOne('SELECT * FROM watch_items WHERE id = :p0', [(int)$_GET['id']]);
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Watched item not found']); return; }
        $item = itemsRow($row);
        $invNos = $item['inv_nos'];
        $label = $item['name'];
    } else {
        $invNos = itemsSplitInvNos((string)($_GET['inv'] ?? ''));
        if (!$invNos) { throw new RuntimeException('Pass an id, or one or more inventory numbers.'); }
        if (count($invNos) > ITEMS_MAX_INV_PER_ENTRY) {
            throw new RuntimeException('At most ' . ITEMS_MAX_INV_PER_ENTRY . ' inventory numbers at a time.');
        }
        $label = 'Item ' . implode(', ', $invNos);
    }

    list($tz, $tzName) = perfTimezone();
    $series = itemsPeriodSeries($tz, $grain, $count);

    $payload = [
        'configured'   => MssqlClient::isConfigured(),
        'item'         => $item,
        'inv_nos'      => $invNos,
        'label'        => $label,
        'grain'        => $grain,
        'count'        => $count,
        'max_count'    => ITEMS_PERIOD_MAX[$grain],
        'from'         => $series['from'],
        'to'           => $series['to'],
        'timezone'     => $tzName,
        'periods'      => [],
        'names'        => new stdClass(),
        'has_cost'     => false,
        'hide_money'   => $hideMoney,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if (!MssqlClient::isConfigured()) { echo json_encode($payload); return; }

    try {
        $client = new MssqlClient();
        $sql = itemsBindPeriodExpr(itemsHistorySql(), $grain);
        $sql = itemsBindSql($sql, $series['from'], $series['to'], $invNos);
        // One row per period, so the count bounds the result — not days × items.
        $rows = $client->rows($sql, $count + 8);
        $names = itemsNames($client, $invNos);
    } catch (Exception $e) {
        $payload['error'] = $e->getMessage();
        echo json_encode($payload);
        return;
    }

    $byKey = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $key = trim((string)itemsCell($row, $vals, 'period', 0));
        if ($key === '') continue;
        $byKey[$key] = [
            'qty'       => (float)itemsCell($row, $vals, 'qty', 1),
            'amount'    => (float)itemsCell($row, $vals, 'amount', 2),
            'discounts' => (float)itemsCell($row, $vals, 'discounts', 3),
            'cost'      => (float)itemsCell($row, $vals, 'cost', 4),
            'days_sold' => (int)itemsCell($row, $vals, 'days_sold', 5),
        ];
    }

    $out = []; $prev = null; $anyCost = false;
    foreach ($series['periods'] as $p) {
        $v = $byKey[$p['key']] ?? ['qty' => 0.0, 'amount' => 0.0, 'discounts' => 0.0, 'cost' => 0.0, 'days_sold' => 0];
        if ($v['cost'] != 0.0) $anyCost = true;
        $margin = $v['amount'] - $v['cost'];
        $row = $p + [
            'qty'        => round($v['qty'], 2),
            'amount'     => round($v['amount'], 2),
            'discounts'  => round($v['discounts'], 2),
            'cost'       => round($v['cost'], 2),
            'margin'     => round($margin, 2),
            'margin_pct' => $v['amount'] > 0 ? round($margin / $v['amount'], 4) : null,
            'avg_price'  => $v['qty'] > 0 ? round($v['amount'] / $v['qty'], 2) : null,
            'days_sold'  => $v['days_sold'],
            // Step change against the period before it. Null on the first row,
            // and null when the previous period sold nothing (no honest base).
            'qty_delta_pct'    => ($prev !== null && $prev['qty'] > 0) ? round(($v['qty'] - $prev['qty']) / $prev['qty'], 4) : null,
            'amount_delta_pct' => ($prev !== null && $prev['amount'] > 0) ? round(($v['amount'] - $prev['amount']) / $prev['amount'], 4) : null,
        ];
        $out[] = $row;
        $prev = $v;
    }

    // Headline totals across the whole span (complete periods only, so a
    // half-finished month can't drag the average or the best/worst pick).
    $done = array_values(array_filter($out, function ($r) { return $r['complete']; }));
    $tq = 0.0; $ta = 0.0; $tc = 0.0;
    foreach ($done as $r) { $tq += $r['qty']; $ta += $r['amount']; $tc += $r['cost']; }
    $best = null; $worst = null;
    foreach ($done as $r) {
        if ($best === null || $r['qty'] > $best['qty']) $best = $r;
        if ($worst === null || $r['qty'] < $worst['qty']) $worst = $r;
    }

    $payload['periods']  = $out;
    $payload['names']    = (object)$names;
    $payload['has_cost'] = $anyCost;
    $payload['summary']  = [
        'complete_periods' => count($done),
        'qty'              => round($tq, 2),
        'amount'           => round($ta, 2),
        'cost'             => round($tc, 2),
        'margin'           => round($ta - $tc, 2),
        // An average ACROSS periods is the metric here (not an estimate of
        // anything), which the actuals-only rule allows.
        'avg_qty'          => $done ? round($tq / count($done), 2) : null,
        'avg_amount'       => $done ? round($ta / count($done), 2) : null,
        'best_key'         => $best ? $best['key'] : null,
        'best_qty'         => $best ? $best['qty'] : null,
        'worst_key'        => $worst ? $worst['key'] : null,
        'worst_qty'        => $worst ? $worst['qty'] : null,
    ];

    if ($hideMoney) { itemsScrubMoney($payload); }
    echo json_encode($payload);
}

// ------------------------------------------------------------------
// Item names (best-effort InvNo → description)
// ------------------------------------------------------------------

/**
 * Best-effort InvNo → item name map for a bounded set of inventory numbers.
 *
 * The known shape on this install is `Inventory.Description` (the same lookup
 * the ReaderSales per-attraction figures use), so that is tried directly first
 * — one query in the common case. Only if that fails do we fall back to
 * discovering a lookup table through INFORMATION_SCHEMA, since the table name
 * and columns vary by install (same approach as the Revenue Mix category
 * lookup). Failure is harmless: the report just shows "Item 7157".
 *
 * @param string[] $invNos
 * @return array<string,string> InvNo (string) => name
 */
function itemsNames(MssqlClient $client, array $invNos): array {
    if (!$invNos) return [];
    $ident = function (string $n): string {
        return '[' . preg_replace('/[^A-Za-z0-9_ #\-]/', '', $n) . ']';
    };
    $fetch = function (string $table, string $noCol, string $nameCol) use ($client, $invNos, $ident): array {
        $sql = MssqlClient::bindIntList(
            'SELECT ' . $ident($noCol) . ' AS no, ' . $ident($nameCol) . ' AS name'
            . ' FROM dbo.' . $ident($table) . ' WHERE ' . $ident($noCol) . ' IN (:invnos)',
            ':invnos', $invNos);
        $map = [];
        foreach ($client->rows($sql, max(1, count($invNos))) as $r) {
            $inv = itemsNormInv($r['no'] ?? '');
            if ($inv === '') continue;
            $map[$inv] = trim((string)($r['name'] ?? ''));
        }
        return $map;
    };

    try {
        $map = $fetch('Inventory', 'InvNo', 'Description');
        if ($map) return $map;
    } catch (Exception $e) {
        // Fall through to discovery.
    }

    try {
        $tables = $client->rows(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'"
            . " AND (TABLE_NAME LIKE '%Inventory%' OR TABLE_NAME LIKE '%Item%') ORDER BY LEN(TABLE_NAME), TABLE_NAME", 24);
    } catch (Exception $e) {
        return [];
    }
    foreach ($tables as $t) {
        $tbl = (string)$t['TABLE_NAME'];
        try {
            $cols = $client->rows(
                "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '"
                . str_replace("'", "''", $tbl) . "'", 128);
            $noCol = null; $nameCol = null;
            foreach ($cols as $c) {
                $cn = (string)$c['COLUMN_NAME'];
                $ct = strtolower((string)$c['DATA_TYPE']);
                $isInt = in_array($ct, ['int', 'smallint', 'tinyint', 'bigint', 'numeric', 'decimal'], true);
                if ($noCol === null && $isInt && preg_match('/^(InvNo|InventoryNo|ItemNo|InvID|ItemID|No|ID)$/i', $cn)) $noCol = $cn;
                if ($nameCol === null && in_array($ct, ['varchar', 'nvarchar', 'char', 'nchar'], true)
                    && preg_match('/(Desc|Name|Title)/i', $cn)) $nameCol = $cn;
            }
            if ($noCol && $nameCol) {
                $map = $fetch($tbl, $noCol, $nameCol);
                if ($map) return $map;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    return [];
}

// ------------------------------------------------------------------
// List (the watch cards)
// ------------------------------------------------------------------

function itemsList(): void {
    $hideMoney = !Auth::hasPermission('view_revenue');
    $w = itemsWindow();
    $rows = DB::query('SELECT * FROM watch_items ORDER BY tag ASC, name ASC, id ASC');
    $items = array_map('itemsRow', $rows);

    $payload = [
        'window'      => $w['meta'],
        'configured'  => MssqlClient::isConfigured(),
        'items'       => $items,
        'names'       => new stdClass(),
        'totals'      => null,
        'has_cost'    => false,
        'hide_money'  => $hideMoney,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (!$items || !MssqlClient::isConfigured()) {
        echo json_encode($payload);
        return;
    }

    // Take entries in listed order until the InvNo budget is spent; anything
    // past it is returned WITHOUT stats and counted, never silently dropped.
    $wanted = []; $covered = 0;
    foreach ($items as $it) {
        $next = $wanted;
        foreach ($it['inv_nos'] as $n) { $next[$n] = true; }
        if (count($next) > ITEMS_MAX_INV_TOTAL && $wanted) break;
        $wanted = $next;
        $covered++;
    }
    $wantedList = array_map('strval', array_keys($wanted));
    if ($covered < count($items)) {
        $payload['stats_skipped'] = count($items) - $covered;
    }

    $keys = itemsPeriodKeys($w['dates'], $w['grain']);
    $daySet = array_fill_keys($w['dates'], true);

    try {
        $client = new MssqlClient();
        $raw = $client->rows(itemsBindSql(itemsRangeSql(), $w['from'], $w['to'], $wantedList), ITEMS_MAX_ROWS);
        if (count($raw) >= ITEMS_MAX_ROWS) { $payload['truncated'] = true; }
        $buckets = itemsBucketsFromRows($raw);
        $idx = itemsIndexBuckets($buckets, $daySet, $w['grain']);

        $priorTot = [];
        if (!empty($w['win']['prev_from']) && !empty($w['win']['prev_to'])) {
            $priorTot = itemsTotalsFromRows($client->rows(
                itemsBindSql(itemsTotalsSql(), $w['win']['prev_from'], $w['win']['prev_to'], $wantedList),
                ITEMS_MAX_INV_TOTAL + 10));
        }
        $names = itemsNames($client, $wantedList);
    } catch (Exception $e) {
        $payload['error'] = $e->getMessage();
        echo json_encode($payload);
        return;
    }

    $totQty = 0.0; $totAmt = 0.0; $totCost = 0.0; $totDisc = 0.0; $anyCost = false;
    for ($i = 0; $i < $covered; $i++) {
        $stats = itemsStatsFor($items[$i]['inv_nos'], $idx, $keys, $priorTot);
        $items[$i]['stats'] = $stats;
        $totQty += $stats['qty']; $totAmt += $stats['amount'];
        $totCost += $stats['cost']; $totDisc += $stats['discounts'];
        if ($stats['cost'] != 0.0) $anyCost = true;
    }

    $payload['items']    = $items;
    // Cast to an object so json_encode always emits a keyed map — an int-keyed
    // PHP array that happened to be a 0..n list would otherwise encode as an
    // array and break the client's names[inv] lookup.
    $payload['names']    = (object)$names;
    $payload['has_cost'] = $anyCost;
    $payload['totals']   = [
        'qty'       => round($totQty, 2),
        'amount'    => round($totAmt, 2),
        'discounts' => round($totDisc, 2),
        'cost'      => round($totCost, 2),
        'margin'    => round($totAmt - $totCost, 2),
        'watched'   => count($items),
    ];

    if ($hideMoney) { itemsScrubMoney($payload); }
    echo json_encode($payload);
}

// ------------------------------------------------------------------
// Detail (one watched entry)
// ------------------------------------------------------------------

function itemsDetail(): void {
    $itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($itemId < 1) { throw new RuntimeException('id is required.'); }
    $row = DB::queryOne('SELECT * FROM watch_items WHERE id = :p0', [$itemId]);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Watched item not found']); return; }

    $hideMoney = !Auth::hasPermission('view_revenue');
    $item = itemsRow($row);
    $w = itemsWindow();

    $payload = [
        'window'     => $w['meta'],
        'item'       => $item,
        'configured' => MssqlClient::isConfigured(),
        'summary'    => null,
        'breakdown'  => [],
        'names'      => new stdClass(),
        'lifetime'   => null,
        'has_cost'   => false,
        'hide_money' => $hideMoney,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (!MssqlClient::isConfigured()) {
        echo json_encode($payload);
        return;
    }

    $invNos = $item['inv_nos'];
    $keys = itemsPeriodKeys($w['dates'], $w['grain']);
    $daySet = array_fill_keys($w['dates'], true);

    try {
        $client = new MssqlClient();
        $buckets = itemsBucketsFromRows(
            $client->rows(itemsBindSql(itemsRangeSql(), $w['from'], $w['to'], $invNos), ITEMS_MAX_ROWS));
        $idx = itemsIndexBuckets($buckets, $daySet, $w['grain']);

        $priorTot = [];
        if (!empty($w['win']['prev_from']) && !empty($w['win']['prev_to'])) {
            $priorTot = itemsTotalsFromRows($client->rows(
                itemsBindSql(itemsTotalsSql(), $w['win']['prev_from'], $w['win']['prev_to'], $invNos),
                ITEMS_MAX_INV_PER_ENTRY + 10));
        }
        $names = itemsNames($client, $invNos);
        $payload['lifetime'] = itemsLifetime($client, $item);
    } catch (Exception $e) {
        $payload['error'] = $e->getMessage();
        echo json_encode($payload);
        return;
    }

    $summary = itemsStatsFor($invNos, $idx, $keys, $priorTot);
    $payload['summary'] = $summary;
    $payload['names']   = (object)$names;

    // Per-InvNo breakdown — which member of a deal is actually moving.
    $breakdown = []; $anyCost = false;
    foreach ($invNos as $inv) {
        $e = $idx[$inv] ?? null;
        $amount = $e ? $e['amount'] : 0.0;
        $cost   = $e ? $e['cost'] : 0.0;
        $qty    = $e ? $e['qty'] : 0.0;
        if ($cost != 0.0) $anyCost = true;
        $breakdown[] = [
            'inv'        => (string)$inv,
            'name'       => $names[$inv] ?? '',
            'qty'        => round($qty, 2),
            'amount'     => round($amount, 2),
            'discounts'  => $e ? round($e['discounts'], 2) : 0.0,
            'cost'       => round($cost, 2),
            'margin'     => round($amount - $cost, 2),
            'margin_pct' => $amount > 0 ? round(($amount - $cost) / $amount, 4) : null,
            'avg_price'  => $qty > 0 ? round($amount / $qty, 2) : null,
            'share'      => $summary['qty'] > 0 ? round($qty / $summary['qty'], 4) : null,
            'prior_qty'  => isset($priorTot[$inv]) ? round($priorTot[$inv]['qty'], 2) : null,
        ];
    }
    usort($breakdown, function ($a, $b) { return $b['qty'] <=> $a['qty']; });
    $payload['breakdown'] = $breakdown;
    $payload['has_cost']  = $anyCost || ($summary['cost'] != 0.0);

    if ($hideMoney) { itemsScrubMoney($payload); }
    echo json_encode($payload);
}

/**
 * Totals since the deal launched (only when a start date is set), so "how is
 * this deal doing overall" is answerable independent of the period picker.
 * Uses the totals-only query — one row per InvNo, however long the run is.
 * The lookback is clamped so a mis-typed 1999 start date can't scan two
 * decades; `clamped` says so when it bites.
 */
function itemsLifetime(MssqlClient $client, array $item): ?array {
    $start = (string)$item['start_date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return null;

    list($tz) = perfTimezone();
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $floor = (new DateTime('now', $tz))->modify('-' . ITEMS_LIFETIME_MAX_DAYS . ' days')->format('Y-m-d');

    $from = $start < $floor ? $floor : $start;
    $to = $today;
    $end = (string)$item['end_date'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) && $end < $to) { $to = $end; }
    if ($to < $from) return null;

    $totals = itemsTotalsFromRows($client->rows(
        itemsBindSql(itemsTotalsSql(), $from, $to, $item['inv_nos']),
        ITEMS_MAX_INV_PER_ENTRY + 10));

    $qty = 0.0; $amount = 0.0; $cost = 0.0; $discounts = 0.0; $daysSold = 0;
    foreach ($item['inv_nos'] as $inv) {
        if (!isset($totals[$inv])) continue;
        $qty += $totals[$inv]['qty'];
        $amount += $totals[$inv]['amount'];
        $cost += $totals[$inv]['cost'];
        $discounts += $totals[$inv]['discounts'];
        // days_sold is per InvNo, so the max is the honest floor for a bundle
        // (the union of selling days can't be smaller than any one member's).
        if ($totals[$inv]['days_sold'] > $daysSold) $daysSold = $totals[$inv]['days_sold'];
    }

    return [
        'from'       => $from,
        'to'         => $to,
        'clamped'    => $start < $floor,
        'started'    => $start,
        'span_days'  => itemsDateSpan($from, $to),
        'qty'        => round($qty, 2),
        'amount'     => round($amount, 2),
        'discounts'  => round($discounts, 2),
        'cost'       => round($cost, 2),
        'margin'     => round($amount - $cost, 2),
        'avg_price'  => $qty > 0 ? round($amount / $qty, 2) : null,
        'days_sold'  => $daysSold,
    ];
}

// ------------------------------------------------------------------
// Best sellers
// ------------------------------------------------------------------

function itemsTop(): void {
    // A revenue leaderboard is money end to end (it is even RANKED by dollars),
    // so it rides the same gate as Revenue Mix rather than being scrubbed.
    Auth::requireAccess('view_revenue');
    $w = itemsWindow();

    $rank = isset($_GET['rank']) ? (string)$_GET['rank'] : 'revenue';
    if (!isset(ITEMS_RANK_EXPR[$rank])) { $rank = 'revenue'; }
    // A hand-customized query with its own ORDER BY has no :rankexpr to swap;
    // say so rather than letting the control silently do nothing.
    $rankLocked = strpos(itemsTopSql(), ':rankexpr') === false;
    if ($rankLocked) { $rank = 'revenue'; }

    $payload = [
        'window'      => $w['meta'],
        'configured'  => MssqlClient::isConfigured(),
        'items'       => [],
        'has_cost'    => false,
        'rank'        => $rank,
        'rank_locked' => $rankLocked,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if (!MssqlClient::isConfigured()) {
        echo json_encode($payload);
        return;
    }

    // Which InvNos are already on the watchlist, so the UI can offer "watch
    // this" only for the ones that aren't.
    $watched = [];
    foreach (DB::query('SELECT id, name, inv_nos FROM watch_items') as $r) {
        foreach (itemsSplitInvNos((string)$r['inv_nos']) as $inv) {
            if (!isset($watched[$inv])) {
                $watched[$inv] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
            }
        }
    }

    try {
        $client = new MssqlClient();
        $topSql = itemsBindRankExpr(itemsTopSql(), $rank);
        $rows = $client->rows(MssqlClient::bindRange($topSql, $w['from'], $w['to']), 300);
        $totals = itemsTotalsFromRows($rows);
        $names = itemsNames($client, array_keys($totals));
    } catch (Exception $e) {
        $payload['error'] = $e->getMessage();
        echo json_encode($payload);
        return;
    }

    $grand = 0.0; $anyCost = false;
    foreach ($totals as $t) { $grand += $t['amount']; if ($t['cost'] != 0.0) $anyCost = true; }

    $out = [];
    foreach ($totals as $inv => $t) {
        $margin = $t['amount'] - $t['cost'];
        $out[] = [
            'inv'        => (string)$inv,
            'name'       => $names[$inv] ?? '',
            'qty'        => round($t['qty'], 2),
            'amount'     => round($t['amount'], 2),
            'discounts'  => round($t['discounts'], 2),
            'cost'       => round($t['cost'], 2),
            'margin'     => round($margin, 2),
            'margin_pct' => $t['amount'] > 0 ? round($margin / $t['amount'], 4) : null,
            'avg_price'  => $t['qty'] > 0 ? round($t['amount'] / $t['qty'], 2) : null,
            'share'      => $grand > 0 ? round($t['amount'] / $grand, 4) : null,
            'watch_id'   => $watched[$inv]['id'] ?? null,
            'watch_name' => $watched[$inv]['name'] ?? null,
        ];
    }
    // Re-sort by the ranked metric so the arrival order matches what the SQL
    // selected the TOP N on (row order isn't guaranteed across drivers).
    $rankKey = $rank === 'units' ? 'qty' : ($rank === 'margin' ? 'margin' : 'amount');
    usort($out, function ($a, $b) use ($rankKey) { return $b[$rankKey] <=> $a[$rankKey]; });

    $payload['items']    = $out;
    $payload['has_cost'] = $anyCost;
    // The query's own TOP bound decides the size — say what came back so the
    // UI never implies it is showing the whole catalog.
    $payload['shown']    = count($out);
    echo json_encode($payload);
}

// ------------------------------------------------------------------
// Money scrub
// ------------------------------------------------------------------

/**
 * Zero every dollar figure and null every money-derived ratio, so a role
 * without view_revenue sees units and unit trends but never money. Mirrors the
 * scrub the other reports apply.
 */
function itemsScrubMoney(array &$payload): void {
    $zeroKeys = ['amount', 'discounts', 'cost', 'margin', 'avg_price', 'prior_amount', 'avg_amount'];
    $nullKeys = ['margin_pct', 'amount_delta_pct'];
    $scrub = function (&$s) use ($zeroKeys, $nullKeys) {
        if (!is_array($s)) return;
        foreach ($zeroKeys as $k) { if (array_key_exists($k, $s)) $s[$k] = 0.0; }
        foreach ($nullKeys as $k) { if (array_key_exists($k, $s)) $s[$k] = null; }
        if (isset($s['series']) && is_array($s['series'])) {
            foreach ($s['series'] as &$pt) { if (is_array($pt) && array_key_exists('amount', $pt)) $pt['amount'] = 0.0; }
            unset($pt);
        }
    };
    if (isset($payload['items']) && is_array($payload['items'])) {
        foreach ($payload['items'] as &$it) {
            if (is_array($it) && isset($it['stats'])) { $scrub($it['stats']); }
        }
        unset($it);
    }
    if (isset($payload['summary']))  { $scrub($payload['summary']); }
    if (isset($payload['totals']))   { $scrub($payload['totals']); }
    if (isset($payload['lifetime'])) { $scrub($payload['lifetime']); }
    foreach (['breakdown', 'periods'] as $listKey) {
        if (isset($payload[$listKey]) && is_array($payload[$listKey])) {
            foreach ($payload[$listKey] as &$r) { $scrub($r); }
            unset($r);
        }
    }
    // Cost is the only reason the margin columns render at all — hide them.
    $payload['has_cost'] = false;
}

// ------------------------------------------------------------------
// Settings + Test
// ------------------------------------------------------------------

function itemsGetSettings(): void {
    Auth::requireAccess('settings');
    echo json_encode([
        'configured' => MssqlClient::isConfigured(),
        'drivers'    => MssqlClient::availableDrivers(),
        'range_sql'   => itemsRangeSql(),
        'totals_sql'  => itemsTotalsSql(),
        'top_sql'     => itemsTopSql(),
        'history_sql' => itemsHistorySql(),
        'defaults'   => [
            'range_sql'   => ITEMS_DEFAULT_RANGE_SQL,
            'totals_sql'  => ITEMS_DEFAULT_TOTALS_SQL,
            'top_sql'     => ITEMS_DEFAULT_TOP_SQL,
            'history_sql' => ITEMS_DEFAULT_HISTORY_SQL,
        ],
        'connection' => [
            'host'     => (MssqlClient::settings()['host'] ?? '') !== '',
            'database' => MssqlClient::settings()['database'] ?? '',
        ],
    ]);
}

function itemsPutSettings(array $input): void {
    Auth::requireAccess('settings');
    $saved = [];
    // Each query is optional in the body — save only what was sent, so the
    // page can offer three independent editors.
    if (array_key_exists('range_sql', $input)) {
        $sql = Validator::requireString($input, 'range_sql', 4000);
        MssqlClient::assertReadOnly($sql);
        itemsBindSql($sql, '2000-01-01', '2000-01-02', ['1']); // fail loudly on a missing placeholder
        DB::setConfig('items_range_sql', $sql);
        $saved[] = 'range';
    }
    if (array_key_exists('totals_sql', $input)) {
        $sql = Validator::requireString($input, 'totals_sql', 4000);
        MssqlClient::assertReadOnly($sql);
        itemsBindSql($sql, '2000-01-01', '2000-01-02', ['1']);
        DB::setConfig('items_totals_sql', $sql);
        $saved[] = 'totals';
    }
    if (array_key_exists('top_sql', $input)) {
        $sql = Validator::requireString($input, 'top_sql', 4000);
        MssqlClient::assertReadOnly($sql);
        MssqlClient::bindRange(itemsBindRankExpr($sql, 'revenue'), '2000-01-01', '2000-01-02');
        DB::setConfig('items_top_sql', $sql);
        $saved[] = 'top';
    }
    if (array_key_exists('history_sql', $input)) {
        $sql = Validator::requireString($input, 'history_sql', 4000);
        MssqlClient::assertReadOnly($sql);
        // Dry-run every grain: a query that only works for one of them would
        // fail later on a control the page offers.
        foreach (array_keys(ITEMS_PERIOD_EXPR) as $g) {
            itemsBindSql(itemsBindPeriodExpr($sql, $g), '2000-01-01', '2000-01-02', ['1']);
        }
        DB::setConfig('items_history_sql', $sql);
        $saved[] = 'history';
    }
    if (!$saved) { throw new RuntimeException('Nothing to save — send range_sql, totals_sql, top_sql or history_sql.'); }

    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'items_settings_update',
             'Item Watch query updated (' . implode(', ', $saved) . ') by ' . (Auth::check()['username'] ?? '?')]
        );
    } catch (Exception $e) {
        error_log('items settings audit log failed: ' . $e->getMessage());
    }
    echo json_encode(['success' => true, 'saved' => $saved, 'configured' => MssqlClient::isConfigured()]);
}

function itemsTest(?array $input = null): void {
    // Runs the live queries and returns dollar figures — gate on data_explorer
    // (raw POS data), not settings.
    Auth::requireAccess('data_explorer');

    list($tz) = perfTimezone();
    $to = (new DateTime('now', $tz))->format('Y-m-d');
    $from = (new DateTime('now', $tz))->modify('-30 days')->format('Y-m-d');
    foreach (['from' => &$from, 'to' => &$to] as $k => &$ref) {
        if ($input && isset($input[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input[$k])) {
            $ref = (string)$input[$k];
        }
    }
    unset($ref);
    if ($to < $from) { echo json_encode(['success' => false, 'error' => 'The "to" date must be on or after "from".']); return; }

    $probe = itemsSplitInvNos((string)($input['inv_nos'] ?? ''));
    if (count($probe) > ITEMS_MAX_INV_PER_ENTRY) { $probe = array_slice($probe, 0, ITEMS_MAX_INV_PER_ENTRY); }

    try {
        $client = new MssqlClient();
        $client->connect();

        $diag = [];
        $scalar = function (string $label, string $sql) use (&$diag, $client) {
            try { $diag[$label] = $client->scalarText($sql); }
            catch (Exception $e) { $diag[$label] = 'error: ' . $e->getMessage(); }
        };
        $scalar('server',   'SELECT @@SERVERNAME');
        $scalar('database', 'SELECT DB_NAME()');
        $scalar('sales_latest_shiftdate',
            'SELECT CONVERT(VARCHAR(19), MAX(ShiftDate), 120) FROM [CenterEdge].[dbo].[Sales]');

        $result = ['from' => $from, 'to' => $to];

        // Best sellers for the probe range — also the answer to "what InvNo is
        // the thing I want to watch?".
        try {
            $topTotals = itemsTotalsFromRows($client->rows(
                MssqlClient::bindRange(itemsBindRankExpr(itemsTopSql(), 'revenue'), $from, $to), 300));
            $topNames = itemsNames($client, array_keys($topTotals));
            uasort($topTotals, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
            $lines = [];
            $n = 0;
            $withCost = 0; $totalCost = 0.0; $totalAmt = 0.0;
            foreach ($topTotals as $inv => $t) {
                $totalCost += $t['cost'];
                $totalAmt  += $t['amount'];
                if ($t['cost'] != 0.0) $withCost++;
                if ($n++ >= 15) continue; // keep counting for coverage, print only the top 15
                $nm = isset($topNames[$inv]) && $topNames[$inv] !== '' ? ' "' . $topNames[$inv] . '"' : '';
                $lines[] = 'InvNo ' . $inv . $nm . ': ' . rtrim(rtrim(number_format($t['qty'], 2), '0'), '.')
                    . ' units, $' . number_format($t['amount'], 2)
                    . ($t['cost'] != 0.0
                        ? ', cost $' . number_format($t['cost'], 2)
                          // A refund-heavy item can net to zero or negative
                          // revenue, where a margin percentage is meaningless.
                          . ($t['amount'] > 0
                              ? ' (margin ' . number_format(($t['amount'] - $t['cost']) / $t['amount'] * 100, 1) . '%)'
                              : ' (no positive revenue — margin n/a)')
                        : ', no cost recorded');
            }
            $diag['top_items_' . $from . '_to_' . $to] = $lines ?: 'no sales in that range';

            // Does this install populate Sales.CostSold? That single fact
            // decides whether the page shows gross margin at all, so answer it
            // outright instead of leaving it to be inferred.
            $totalItems = count($topTotals);
            if ($totalItems === 0) {
                $diag['cost_coverage'] = 'no rows in this range — cannot tell yet';
            } elseif ($withCost === 0) {
                $diag['cost_coverage'] = 'NONE of the ' . $totalItems . ' items in this range record a cost. '
                    . 'Item Watch will hide its margin columns (by design — it will not print a fake 100%).';
            } else {
                $diag['cost_coverage'] = $withCost . ' of ' . $totalItems . ' items record a cost ('
                    . number_format($withCost / $totalItems * 100, 1) . '%). Total cost $'
                    . number_format($totalCost, 2) . ' against $' . number_format($totalAmt, 2) . ' revenue'
                    . ($totalAmt > 0
                        ? ' = ' . number_format(($totalAmt - $totalCost) / $totalAmt * 100, 1) . '% blended margin. '
                        : ' (no positive revenue in this range, so no blended margin). ')
                    . ($withCost < $totalItems
                        ? 'Margin columns will show, but items with no cost read as 100% margin — treat those rows with care.'
                        : 'Margin columns will show for every item.');
            }
        } catch (Exception $e) {
            $diag['top_items'] = 'error: ' . $e->getMessage();
        }

        // Reconcile the two watched-item queries against each other for the
        // probe InvNos: the day-grain rows must sum to the totals-only row.
        if ($probe) {
            try {
                $buckets = itemsBucketsFromRows(
                    $client->rows(itemsBindSql(itemsRangeSql(), $from, $to, $probe), ITEMS_MAX_ROWS));
                $dayQty = 0.0; $dayAmt = 0.0;
                foreach ($buckets as $b) { $dayQty += $b['qty']; $dayAmt += $b['amount']; }

                $totals = itemsTotalsFromRows($client->rows(
                    itemsBindSql(itemsTotalsSql(), $from, $to, $probe), ITEMS_MAX_INV_PER_ENTRY + 10));
                $totQty = 0.0; $totAmt = 0.0; $totCost = 0.0;
                foreach ($totals as $t) { $totQty += $t['qty']; $totAmt += $t['amount']; $totCost += $t['cost']; }

                $result['probe'] = [
                    'inv_nos'      => $probe,
                    'qty'          => round($totQty, 2),
                    'amount'       => round($totAmt, 2),
                    'cost'         => round($totCost, 2),
                    'margin'       => round($totAmt - $totCost, 2),
                    'has_cost'     => $totCost != 0.0,
                    'daily_rows'   => count($buckets),
                    'daily_qty'    => round($dayQty, 2),
                    'daily_amount' => round($dayAmt, 2),
                    // Both queries read the same rows a different way; a
                    // mismatch means one of them has been edited into
                    // disagreement, which is exactly what this button is for.
                    'agrees'       => abs($dayQty - $totQty) < 0.01 && abs($dayAmt - $totAmt) < 0.01,
                ];
                $probeNames = itemsNames($client, $probe);
                $diag['probe_names'] = $probeNames ?: 'no names found for those InvNos';

                // Exercise the history query on every grain — each one swaps in
                // a different period expression, so a broken edit can hide in
                // just one of them.
                $grainLines = [];
                foreach (array_keys(ITEMS_PERIOD_EXPR) as $g) {
                    try {
                        $hs = itemsBindSql(itemsBindPeriodExpr(itemsHistorySql(), $g), $from, $to, $probe);
                        $hr = $client->rows($hs, 80);
                        $hq = 0.0;
                        foreach ($hr as $x) { $hq += (float)itemsCell($x, array_values($x), 'qty', 1); }
                        $grainLines[] = $g . ': ' . count($hr) . ' periods, '
                            . rtrim(rtrim(number_format($hq, 2), '0'), '.') . ' units'
                            . (abs($hq - $totQty) < 0.01 ? ' ✓ ties to the totals query' : ' ⚠ differs from ' . $totQty);
                    } catch (Exception $e) {
                        $grainLines[] = $g . ': error: ' . $e->getMessage();
                    }
                }
                $diag['history_by_grain'] = $grainLines;
            } catch (Exception $e) {
                $result['probe'] = ['error' => $e->getMessage()];
            }
        }

        echo json_encode(['success' => true, 'driver' => $client->driver(), 'diagnostics' => $diag] + $result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
