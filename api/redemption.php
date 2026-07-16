<?php
/**
 * Redemption Economics API — tickets REDEEMED at the prize counter, from the
 * venue's CenterEdge MSSQL `RedeemReceipts` / `RedeemRecItems` tables.
 * Browsable by Day / Week / Month / Year / Custom (same window model as the
 * other reports).
 *
 *   GET  /api/redemption/settings — editable queries (settings perm)
 *   PUT  /api/redemption/settings — save them (settings perm)
 *   POST /api/redemption/test     — run the query for a probe range + fingerprint (data_explorer perm)
 *   GET  /api/redemption/data?range=&offset=&from=&to= — per-day, hour-of-day,
 *          weekday × hour heatmap, redemption %, prize mix, summary
 *          (analytics + view_revenue)
 *
 * This is the redemption half of the ticket economy — Ticket Trends reports
 * tickets EARNED (PlayerCardTrans ValueNo 3); this reports tickets SPENT for
 * prizes, so together they give the **redemption rate** and the period's net
 * change in outstanding ticket liability.
 *
 * Data model (confirmed live from the venue's Explorer, July 2026):
 *   RedeemReceipts — one row per redemption. `RecType` marks the redemption
 *     record, but the value DEPENDS ON THE CARD SYSTEM: the current CenterEdge/
 *     Kiosoft readers (live since ~end of April 2026) use `RecType = 1`
 *     (verified: July rows at the redemption counter carry TotalTickets); the
 *     legacy Embed system used `RecType = 3`. The default below filters
 *     `RecType = 1 AND TotalTickets > 0`. `TotalTickets` = tickets spent;
 *     `RecTime` is a real clock time on every row (`RedeemDateTime` is sometimes
 *     null/anomalous), so it drives day + hour.
 *   RedeemRecItems — per-prize line items (InvNo = prize SKU, NumberTickets =
 *     ticket cost, Qty). There is NO cost/COGS column and no prize name here,
 *     so this yields prize MIX (tickets/qty), not margin — a prize-cost source
 *     would be needed for margin. Prize names are a best-effort Inventory lookup.
 *
 * Both queries are admin-editable SQL with required :from/:to placeholders,
 * single-SELECT guarded (MssqlClient::assertReadOnly). All roll-ups are computed
 * in PHP, so a Year view costs the same handful of round-trips as a Day. Shares
 * the Go-Kart Labor page's MSSQL connection.
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/analytics.php';
// Reuse the tickets-EARNED query (PlayerCardTrans ValueNo 3) for the
// redemption-rate denominator.
require_once __DIR__ . '/tickets.php';

// Per (local day, local hour) redeemed tickets + receipt count. RecType 1 is
// the redemption record on the current CenterEdge/Kiosoft system (the legacy
// Embed system used RecType 3 — adjust here if querying pre-cutover data);
// TotalTickets > 0 skips void/header rows. RecTime is the true clock time (the
// hour-of-day and weekday×hour views are real, not estimated).
const REDEMPTION_DEFAULT_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), RecTime, 120) AS day,\n       DATEPART(HOUR, RecTime) AS hour,\n       SUM(TotalTickets) AS tickets,\n       COUNT(*) AS receipts\nFROM [CenterEdge].[dbo].[RedeemReceipts]\nWHERE RecType = 1  /* CenterEdge/Kiosoft redemption record (legacy Embed used RecType 3) */\n  AND TotalTickets > 0\n  AND RecTime >= :from\n  AND RecTime < DATEADD(DAY, 1, :to)\nGROUP BY CONVERT(VARCHAR(10), RecTime, 120), DATEPART(HOUR, RecTime)";

// Per prize (InvNo): total ticket cost and quantity. Joined to RedeemReceipts
// for the date filter. NumberTickets is the line's ticket price; line cost =
// NumberTickets × Qty (Test reconciles this against the receipt header total).
const REDEMPTION_DEFAULT_ITEMS_SQL = "SELECT i.InvNo AS inv,\n       SUM(i.NumberTickets * i.Qty) AS tickets,\n       SUM(i.Qty) AS qty\nFROM [CenterEdge].[dbo].[RedeemRecItems] i\nJOIN [CenterEdge].[dbo].[RedeemReceipts] r ON r.RecNo = i.RecNo\nWHERE r.RecType = 1\n  AND r.RecTime >= :from\n  AND r.RecTime < DATEADD(DAY, 1, :to)\nGROUP BY i.InvNo";

function handleRedemption(string $method, array $parts, ?array $input): void {
    $action = $parts[0] ?? '';
    switch ($action) {
        case 'settings':
            if ($method === 'GET') { redemptionGetSettings(); return; }
            if ($method === 'PUT') { redemptionPutSettings($input ?? []); return; }
            break;
        case 'test':
            if ($method === 'POST') { redemptionTest($input); return; }
            break;
        case 'data':
            if ($method === 'GET') { redemptionData(); return; }
            break;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Unknown redemption endpoint']);
}

function redemptionRangeSql(): string {
    return DB::getConfig('redemption_range_sql') ?: REDEMPTION_DEFAULT_RANGE_SQL;
}
function redemptionItemsSql(): string {
    return DB::getConfig('redemption_items_sql') ?: REDEMPTION_DEFAULT_ITEMS_SQL;
}

/**
 * Normalize the primary query's rows into (day, hour, tickets, receipts),
 * tolerant of column naming (named keys any case, else positional).
 *
 * @return array<int,array{day:string,hour:int,tickets:float,receipts:int}>
 */
function redemptionBucketsFromRows(array $rows): array {
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
            'day'      => $day,
            'hour'     => $hour,
            'tickets'  => (float)$get($row, $vals, 'tickets', 2),
            'receipts' => (int)$get($row, $vals, 'receipts', 3),
        ];
    }
    return $out;
}

/**
 * Normalize the prize-items query rows into (inv, tickets, qty).
 *
 * @return array<int,array{inv:int,tickets:float,qty:float}>
 */
function redemptionItemsFromRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $get = function (array $row, array $vals, string $name, int $pos) {
            foreach ($row as $k => $v) {
                if (strcasecmp((string)$k, $name) === 0) return $v;
            }
            return $vals[$pos] ?? null;
        };
        $out[] = [
            'inv'     => (int)$get($row, $vals, 'inv', 0),
            'tickets' => (float)$get($row, $vals, 'tickets', 1),
            'qty'     => (float)$get($row, $vals, 'qty', 2),
        ];
    }
    return $out;
}

/** Weekday index 0=Sun..6=Sat for an ISO date (calendar-fixed, tz-independent). */
function redemptionWeekday(string $iso): int {
    $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
    return $d ? (int)$d->format('w') : 0;
}

/**
 * Best-effort InvNo → prize name map. The inventory/merchandise table name
 * varies by install, so discover it via INFORMATION_SCHEMA (an int No/ID column
 * + a text Name/Desc column). Failure is harmless: the report shows "Item N".
 *
 * @return array<string,string> InvNo (string) => name
 */
function redemptionPrizeNames(MssqlClient $client): array {
    $ident = function (string $n): string {
        return '[' . preg_replace('/[^A-Za-z0-9_ #\-]/', '', $n) . ']';
    };
    try {
        $tables = $client->rows(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'"
            . " AND (TABLE_NAME LIKE '%Inventory%' OR TABLE_NAME LIKE '%Merch%' OR TABLE_NAME LIKE '%Prize%' OR TABLE_NAME LIKE '%Redeem%Item%')"
            . " ORDER BY TABLE_NAME", 24);
    } catch (Exception $e) {
        return [];
    }
    foreach ($tables as $t) {
        $tbl = (string)$t['TABLE_NAME'];
        try {
            $cols = $client->rows(
                "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . str_replace("'", "''", $tbl) . "'", 128);
            $noCol = null; $nameCol = null;
            foreach ($cols as $c) {
                $cn = (string)$c['COLUMN_NAME'];
                $ct = strtolower((string)$c['DATA_TYPE']);
                $isInt = in_array($ct, ['int', 'smallint', 'tinyint', 'bigint', 'numeric', 'decimal'], true);
                if ($noCol === null && $isInt && preg_match('/^(InvNo|InventoryNo|InvID|ItemNo|ItemID|No|ID)$/i', $cn)) $noCol = $cn;
                if ($nameCol === null && in_array($ct, ['varchar', 'nvarchar', 'char', 'nchar'], true) && preg_match('/(Desc|Name|Title)/i', $cn)) $nameCol = $cn;
            }
            if ($noCol && $nameCol) {
                $map = [];
                foreach ($client->rows('SELECT ' . $ident($noCol) . ' AS no, ' . $ident($nameCol) . ' AS name FROM dbo.' . $ident($tbl), 5000) as $r) {
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

function redemptionGetSettings(): void {
    Auth::requireAccess('settings');
    echo json_encode([
        'configured' => MssqlClient::isConfigured(),
        'drivers'    => MssqlClient::availableDrivers(),
        'range_sql'  => redemptionRangeSql(),
        'items_sql'  => redemptionItemsSql(),
        'defaults'   => [
            'range_sql' => REDEMPTION_DEFAULT_RANGE_SQL,
            'items_sql' => REDEMPTION_DEFAULT_ITEMS_SQL,
        ],
        'connection' => [
            'host'     => (MssqlClient::settings()['host'] ?? '') !== '',
            'database' => MssqlClient::settings()['database'] ?? '',
        ],
    ]);
}

function redemptionPutSettings(array $input): void {
    Auth::requireAccess('settings');
    $rangeSql = Validator::requireString($input, 'range_sql', 4000);
    MssqlClient::assertReadOnly($rangeSql);
    MssqlClient::bindRange($rangeSql, '2000-01-01', '2000-01-02');
    DB::setConfig('redemption_range_sql', $rangeSql);

    // Items query is optional in the payload; validate + save when present.
    if (array_key_exists('items_sql', $input)) {
        $itemsSql = Validator::requireString($input, 'items_sql', 4000);
        MssqlClient::assertReadOnly($itemsSql);
        MssqlClient::bindRange($itemsSql, '2000-01-01', '2000-01-02');
        DB::setConfig('redemption_items_sql', $itemsSql);
    }

    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'redemption_settings_update', 'Redemption query updated by ' . (Auth::check()['username'] ?? '?')]
        );
    } catch (Exception $e) {
        error_log('redemption settings audit log failed: ' . $e->getMessage());
    }
    echo json_encode(['success' => true, 'configured' => MssqlClient::isConfigured()]);
}

function redemptionTest(?array $input = null): void {
    // Runs the live MSSQL query and returns ticket figures — gate on
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
        $buckets = redemptionBucketsFromRows(
            $client->rows(MssqlClient::bindRange(redemptionRangeSql(), $probeDate, $probeDate), 5000));
        $tickets = 0.0; $receipts = 0;
        foreach ($buckets as $b) { $tickets += $b['tickets']; $receipts += $b['receipts']; }

        $diag = [];
        $probe = function (string $label, string $sql) use (&$diag, $client) {
            try { $diag[$label] = $client->scalarText($sql); }
            catch (Exception $e) { $diag[$label] = 'error: ' . $e->getMessage(); }
        };
        $probe('server',   'SELECT @@SERVERNAME');
        $probe('database', 'SELECT DB_NAME()');
        $probe('redeemreceipts_latest', 'SELECT CONVERT(VARCHAR(19), MAX(RecTime), 120) FROM [CenterEdge].[dbo].[RedeemReceipts]');
        // RecType breakdown for the probe day, so the redemption RecType stays
        // verifiable (RecType 1 on CenterEdge/Kiosoft; RecType 3 on legacy
        // Embed) and any other ticket-bearing type can be spotted.
        try {
            $rows = $client->rows(MssqlClient::bindDate(
                "SELECT RecType, COUNT(*) AS lines, SUM(TotalTickets) AS tickets"
                . " FROM [CenterEdge].[dbo].[RedeemReceipts]"
                . " WHERE RecTime >= :date AND RecTime < DATEADD(DAY, 1, :date)"
                . " GROUP BY RecType ORDER BY SUM(TotalTickets) DESC", $probeDate), 20);
            $diag['rectypes_' . $probeDate] = $rows ? array_map(function ($r) {
                return 'RecType ' . $r['RecType'] . ': ' . round((float)$r['tickets']) . ' tickets (' . $r['lines'] . ' rows)';
            }, $rows) : 'no redemption records on ' . $probeDate;
        } catch (Exception $e) {
            $diag['rectypes_' . $probeDate] = 'error: ' . $e->getMessage();
        }
        // Reconcile the line-item ticket total against the receipt-header total
        // for the probe day (confirms NumberTickets × Qty == TotalTickets).
        try {
            $items = redemptionItemsFromRows(
                $client->rows(MssqlClient::bindRange(redemptionItemsSql(), $probeDate, $probeDate), 40000));
            $itemTickets = 0.0; foreach ($items as $it) $itemTickets += $it['tickets'];
            $diag['line_items_vs_header_' . $probeDate] =
                'line items: ' . round($itemTickets) . ' tickets across ' . count($items) . ' prizes; receipt header: ' . round($tickets) . ' tickets';
        } catch (Exception $e) {
            $diag['line_items_vs_header_' . $probeDate] = 'error: ' . $e->getMessage();
        }

        echo json_encode([
            'success'     => true,
            'driver'      => $client->driver(),
            'probe_date'  => $probeDate,
            'tickets'     => round($tickets),
            'receipts'    => $receipts,
            'diagnostics' => $diag,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function redemptionData(): void {
    // Ticket economics ride the same money gate as the other MSSQL reports.
    Auth::requireAccess('analytics');
    Auth::requireAccess('view_revenue');

    list($tz, $tzName) = perfTimezone();
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $win = perfResolveWindow($tz);
    $from = $win['from'];
    $to   = $win['to'];
    if ($to > $today) $to = $today;
    $span = redemptionDateSpan($from, $to);
    if ($span < 1) throw new RuntimeException('The selected period is entirely in the future.');
    if ($span > 380) throw new RuntimeException('Ranges longer than a year aren\'t supported here — pick a year or less.');
    $dates = [];
    $cur = DateTime::createFromFormat('!Y-m-d', $from, $tz);
    for ($i = 0; $i < $span; $i++) { $dates[] = $cur->format('Y-m-d'); $cur->modify('+1 day'); }

    $meta = perfRangeMeta($win, $tzName);
    $meta['to'] = $to;
    $base = ['window' => $meta, 'configured' => MssqlClient::isConfigured(), 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')];

    if (!MssqlClient::isConfigured()) {
        echo json_encode($base + ['days' => [], 'hours' => [], 'heatmap' => null, 'prizes' => [], 'summary' => null]);
        return;
    }

    try {
        $client = new MssqlClient();
        $buckets = redemptionBucketsFromRows($client->rows(MssqlClient::bindRange(redemptionRangeSql(), $from, $to), 20000));

        // Prior-period redeemed total (for the headline delta).
        $priorRedeemed = 0.0;
        if (!empty($win['prev_from']) && !empty($win['prev_to'])) {
            foreach (redemptionBucketsFromRows($client->rows(MssqlClient::bindRange(redemptionRangeSql(), $win['prev_from'], $win['prev_to']), 20000)) as $b) {
                $priorRedeemed += $b['tickets'];
            }
        }

        // Tickets EARNED this window (Ticket Trends source) → redemption rate.
        $earned = 0.0;
        try {
            foreach (ticketsBucketsFromRows($client->rows(MssqlClient::bindRange(ticketsRangeSql(), $from, $to), 40000)) as $b) {
                $earned += $b['tickets'];
            }
        } catch (Exception $e) {
            $earned = 0.0; // earned is best-effort; redemption % just goes null
        }

        // Prize mix (best-effort — the report still renders without it).
        $prizes = [];
        $prizeNames = [];
        try {
            $items = redemptionItemsFromRows($client->rows(MssqlClient::bindRange(redemptionItemsSql(), $from, $to), 40000));
            $prizeNames = redemptionPrizeNames($client);
            $prizes = redemptionComposePrizes($items, $prizeNames);
        } catch (Exception $e) {
            $prizes = [];
        }
    } catch (Exception $e) {
        echo json_encode($base + ['error' => $e->getMessage(), 'days' => [], 'hours' => [], 'heatmap' => null, 'prizes' => [], 'summary' => null]);
        return;
    }

    echo json_encode($base + redemptionCompose($dates, $buckets, $earned, round($priorRedeemed), $prizes));
}

/**
 * Fold the prize line items into a ranked breakdown (top by ticket cost).
 *
 * @param array $items redemptionItemsFromRows() output
 * @param array<string,string> $names InvNo => name
 */
function redemptionComposePrizes(array $items, array $names): array {
    $tot = 0.0;
    foreach ($items as $it) $tot += $it['tickets'];
    $out = [];
    foreach ($items as $it) {
        $out[] = [
            'inv'     => $it['inv'],
            'name'    => $names[(string)$it['inv']] ?? '',
            'tickets' => round($it['tickets']),
            'qty'     => round($it['qty']),
            'share'   => $tot > 0 ? round($it['tickets'] / $tot, 4) : 0,
        ];
    }
    usort($out, function ($x, $y) { return $y['tickets'] <=> $x['tickets']; });
    return array_slice($out, 0, 40); // cap the table; the tail is a long list of one-offs
}

/**
 * Compose per-day rows, the hour-of-day curve, the weekday×hour heatmap, the
 * prize breakdown, and the summary. Pure — directly testable without a
 * connection.
 *
 * @param string[] $dates every ISO date in the window, ascending
 * @param array $buckets redemptionBucketsFromRows() output
 * @param float $earned tickets earned this window (redemption-rate denominator)
 * @param float $priorRedeemed prior-period redeemed total
 * @param array $prizes redemptionComposePrizes() output
 * @return array{days:array, hours:array, heatmap:array, prizes:array, summary:array}
 */
function redemptionCompose(array $dates, array $buckets, float $earned, float $priorRedeemed, array $prizes): array {
    $numDays = max(1, count($dates));
    $dowOccurrences = array_fill(0, 7, 0);
    $dayIndex = [];
    foreach ($dates as $d) {
        $dowOccurrences[redemptionWeekday($d)]++;
        $dayIndex[$d] = ['tickets' => 0.0, 'receipts' => 0];
    }

    $byHour = [];
    for ($h = 0; $h < 24; $h++) $byHour[$h] = ['tickets' => 0.0, 'receipts' => 0];
    $byDowHour = [];
    for ($w = 0; $w < 7; $w++) {
        $byDowHour[$w] = [];
        for ($h = 0; $h < 24; $h++) $byDowHour[$w][$h] = ['tickets' => 0.0, 'receipts' => 0];
    }
    $tot = 0.0; $totReceipts = 0;

    foreach ($buckets as $b) {
        if (!isset($dayIndex[$b['day']])) continue;
        $h = $b['hour'];
        $w = redemptionWeekday($b['day']);
        $dayIndex[$b['day']]['tickets']  += $b['tickets'];
        $dayIndex[$b['day']]['receipts'] += $b['receipts'];
        $byHour[$h]['tickets']  += $b['tickets'];
        $byHour[$h]['receipts'] += $b['receipts'];
        $byDowHour[$w][$h]['tickets']  += $b['tickets'];
        $byDowHour[$w][$h]['receipts'] += $b['receipts'];
        $tot += $b['tickets']; $totReceipts += $b['receipts'];
    }

    $days = [];
    foreach ($dates as $d) {
        $x = $dayIndex[$d];
        $days[] = [
            'date'     => $d,
            'tickets'  => round($x['tickets']),
            'receipts' => $x['receipts'],
            'avg'      => $x['receipts'] > 0 ? round($x['tickets'] / $x['receipts']) : null,
        ];
    }

    $hours = [];
    for ($h = 0; $h < 24; $h++) {
        $x = $byHour[$h];
        $hours[] = [
            'hour'         => $h,
            'tickets'      => round($x['tickets']),
            'receipts'     => $x['receipts'],
            'tickets_avg'  => round($x['tickets'] / $numDays),
            'receipts_avg' => round($x['receipts'] / $numDays, 2),
        ];
    }

    $dowNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $heatRows = [];
    $heatMax = 0.0;
    for ($w = 0; $w < 7; $w++) {
        $occ = $dowOccurrences[$w];
        $cells = [];
        for ($h = 0; $h < 24; $h++) {
            $avg = $occ > 0 ? $byDowHour[$w][$h]['tickets'] / $occ : 0.0;
            if ($avg > $heatMax) $heatMax = $avg;
            $cells[] = [
                'hour'         => $h,
                'tickets_avg'  => round($avg),
                'receipts_avg' => $occ > 0 ? round($byDowHour[$w][$h]['receipts'] / $occ, 2) : 0.0,
            ];
        }
        $heatRows[] = ['dow' => $w, 'label' => $dowNames[$w], 'occurrences' => $occ, 'cells' => $cells];
    }

    $busiestHour = null; $bestHourAvg = -1.0;
    foreach ($hours as $hh) {
        if ($hh['tickets_avg'] > $bestHourAvg) { $bestHourAvg = $hh['tickets_avg']; $busiestHour = $hh['hour']; }
    }
    $busiestDow = null; $bestDowAvg = -1.0;
    for ($w = 0; $w < 7; $w++) {
        $occ = $dowOccurrences[$w];
        if ($occ <= 0) continue;
        $sum = 0.0;
        for ($h = 0; $h < 24; $h++) $sum += $byDowHour[$w][$h]['tickets'];
        $avg = $sum / $occ;
        if ($avg > $bestDowAvg) { $bestDowAvg = $avg; $busiestDow = $w; }
    }

    $topPrize = $prizes[0] ?? null;
    $summary = [
        'redeemed'        => round($tot),
        'receipts'        => $totReceipts,
        'num_days'        => count($dates),
        'per_day'         => round($tot / $numDays),
        'avg_receipt'     => $totReceipts > 0 ? round($tot / $totReceipts) : null,
        'earned'          => round($earned),
        // Redemption rate: tickets spent ÷ tickets earned this period.
        'redemption_pct'  => $earned > 0 ? round($tot / $earned, 4) : null,
        // Net change in outstanding ticket liability this period (earned − redeemed).
        // (All-time outstanding float would come from EmbedBalance.ETickets.)
        'net_float'       => round($earned - $tot),
        'busiest_hour'    => $busiestHour,
        'busiest_dow'     => $busiestDow,
        'busiest_dow_label' => $busiestDow !== null ? $dowNames[$busiestDow] : null,
        'top_prize'       => $topPrize['inv'] ?? null,
        'top_prize_name'  => $topPrize['name'] ?? null,
        'prior_redeemed'  => round($priorRedeemed),
        'delta_pct'       => $priorRedeemed > 0 ? round(($tot - $priorRedeemed) / $priorRedeemed, 4) : null,
    ];

    return [
        'days'    => $days,
        'hours'   => $hours,
        'heatmap' => ['max_avg' => round($heatMax), 'rows' => $heatRows],
        'prizes'  => $prizes,
        'summary' => $summary,
    ];
}

/** Inclusive day count between two ISO dates (0 when to < from). */
function redemptionDateSpan(string $from, string $to): int {
    $a = DateTime::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b || $b < $a) return 0;
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400) + 1;
}
