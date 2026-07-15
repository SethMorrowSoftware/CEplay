<?php
/**
 * Card Loads API — money guests ADD to their Castle Cards, straight from the
 * venue's CenterEdge MSSQL PlayerCardTrans ledger, browsable by
 * Day / Week / Month / Year / Custom (same period model as Performance/Labor).
 *
 *   GET  /api/cardloads/settings — editable load query (settings perm)
 *   PUT  /api/cardloads/settings — save it (settings perm)
 *   POST /api/cardloads/test     — run the query for a probe range + fingerprint (settings perm)
 *   GET  /api/cardloads/data?range=&offset=&from=&to= — per-day, hour-of-day,
 *          day-of-week × hour heatmap, and summary (analytics + view_revenue)
 *
 * WHY PlayerCardTrans, not Sales:
 *   This venue defers card value (ApplicationInfo.DeferValuePlayerCards = 1),
 *   so money added to a card is stored value (a liability) — it is NOT booked
 *   as a POS sale. The load event lives only in the card ledger. Verified live
 *   in July 2026: PlayerCardTrans TransType 3 = "add value" (avg ~$21/load, at
 *   POS/cashier divisions, real TransDateTime clock times), TransType 1 = plays
 *   (deductions at readers, which reconcile to the Sales "Reader Sales"
 *   category). See the default query below.
 *
 * The load query is admin-editable SQL with required :from/:to placeholders
 * (inclusive day range), guarded to a single SELECT (MssqlClient::assertReadOnly).
 * It returns one row per (day, hour) with PAID and BONUS columns kept separate;
 * all roll-ups (daily / hour-of-day / weekday×hour / summary) are computed in
 * PHP from those buckets, so a Year view costs the same one round-trip as a Day.
 *
 * Shares the MSSQL connection with the Go-Kart Labor page (lib/mssql_client.php);
 * the connection itself is configured there.
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';
// Reuses the Performance/Labor window model (perfTimezone, perfResolveWindow,
// perfRangeMeta) so the Day/Week/Month/Year/Custom picker behaves identically.
require_once __DIR__ . '/analytics.php';

// The venue's proven bucket, settled live in July 2026 (see file header):
//   PlayerCardTrans.TransType = 3 is "add value / load".
//   DollarAmount = real dollars paid (the money added).
//   Amount       = card value-units (~100 per dollar); a value add with no
//                  DollarAmount is a comped/bonus load — surfaced SEPARATELY,
//                  estimated at Amount/100 dollars (adjust the divisor here if
//                  this install uses a different value-unit scale).
// One row per (local day, local hour). TransDateTime carries a true clock time
// (unlike Sales.ShiftDate, which is midnight-only), which is what makes the
// hour-of-day and weekday×hour views real rather than estimated.
const CARDLOADS_DEFAULT_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), TransDateTime, 120) AS day,\n       DATEPART(HOUR, TransDateTime) AS hour,\n       SUM(CASE WHEN DollarAmount > 0 THEN DollarAmount ELSE 0 END) AS paid_dollars,\n       SUM(CASE WHEN DollarAmount > 0 THEN 1 ELSE 0 END) AS paid_loads,\n       SUM(CASE WHEN DollarAmount <= 0 AND Amount > 0 THEN Amount / 100.0 ELSE 0 END) AS bonus_dollars,\n       SUM(CASE WHEN DollarAmount <= 0 AND Amount > 0 THEN 1 ELSE 0 END) AS bonus_loads\nFROM [CenterEdge].[dbo].[PlayerCardTrans]\nWHERE TransType = 3  /* \"add value\": money added to a card */\n  AND TransDateTime >= :from\n  AND TransDateTime < DATEADD(DAY, 1, :to)\nGROUP BY CONVERT(VARCHAR(10), TransDateTime, 120), DATEPART(HOUR, TransDateTime)";

function handleCardLoads(string $method, array $parts, ?array $input): void {
    $action = $parts[0] ?? '';

    switch ($action) {
        case 'settings':
            if ($method === 'GET') { cardloadsGetSettings(); return; }
            if ($method === 'PUT') { cardloadsPutSettings($input ?? []); return; }
            break;
        case 'test':
            if ($method === 'POST') { cardloadsTest($input); return; }
            break;
        case 'data':
            if ($method === 'GET') { cardloadsData(); return; }
            break;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Unknown cardloads endpoint']);
}

/** The one editable range query (config override, else the shipped default). */
function cardloadsRangeSql(): string {
    return DB::getConfig('cardloads_range_sql') ?: CARDLOADS_DEFAULT_RANGE_SQL;
}

/**
 * Normalize the range query's rows into (day, hour, paid_dollars, paid_loads,
 * bonus_dollars, bonus_loads), tolerant of column naming (named keys any case,
 * else positional) so a hand-edited query still lands in the right columns.
 *
 * @return array<int,array{day:string,hour:int,paid_dollars:float,paid_loads:int,bonus_dollars:float,bonus_loads:int}>
 */
function cardloadsBucketsFromRows(array $rows): array {
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
            // dblib can render a DATETIME as "Jul 14 2026 12:00AM" — parse it
            // rather than dropping the row (which would read as a zero day).
            $ts = strtotime($rawDay);
            if ($ts === false) continue;
            $day = date('Y-m-d', $ts);
        }
        $hour = (int)$get($row, $vals, 'hour', 1);
        if ($hour < 0 || $hour > 23) continue;
        $out[] = [
            'day'           => $day,
            'hour'          => $hour,
            'paid_dollars'  => (float)$get($row, $vals, 'paid_dollars', 2),
            'paid_loads'    => (int)$get($row, $vals, 'paid_loads', 3),
            'bonus_dollars' => (float)$get($row, $vals, 'bonus_dollars', 4),
            'bonus_loads'   => (int)$get($row, $vals, 'bonus_loads', 5),
        ];
    }
    return $out;
}

/** Weekday index 0=Sun..6=Sat for an ISO date (calendar-fixed, tz-independent). */
function cardloadsWeekday(string $iso): int {
    $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
    return $d ? (int)$d->format('w') : 0;
}

function cardloadsGetSettings(): void {
    Auth::requireAccess('settings');
    echo json_encode([
        'configured'  => MssqlClient::isConfigured(),
        'drivers'     => MssqlClient::availableDrivers(),
        'range_sql'   => cardloadsRangeSql(),
        'defaults'    => ['range_sql' => CARDLOADS_DEFAULT_RANGE_SQL],
        // Connection lives on the Go-Kart Labor page (same MSSQL settings);
        // surface just enough here to say whether it's ready.
        'connection'  => [
            'host'     => (MssqlClient::settings()['host'] ?? '') !== '',
            'database' => MssqlClient::settings()['database'] ?? '',
        ],
    ]);
}

function cardloadsPutSettings(array $input): void {
    Auth::requireAccess('settings');

    // Validate NOW so a bad save fails loudly here, not at 9 PM when the report
    // is opened. Must be a single SELECT and contain both :from and :to.
    $rangeSql = Validator::requireString($input, 'range_sql', 4000);
    MssqlClient::assertReadOnly($rangeSql);
    MssqlClient::bindRange($rangeSql, '2000-01-01', '2000-01-02');

    DB::setConfig('cardloads_range_sql', $rangeSql);

    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'cardloads_settings_update',
             'Card-loads query updated by ' . (Auth::check()['username'] ?? '?')]
        );
    } catch (Exception $e) {
        error_log('cardloads settings audit log failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'configured' => MssqlClient::isConfigured()]);
}

function cardloadsTest(?array $input = null): void {
    Auth::requireAccess('settings');
    $client = new MssqlClient();
    $tz = new DateTimeZone(DB::getConfig('timezone') ?: DEFAULT_TIMEZONE);
    // Default the probe to yesterday (a complete business day); allow aiming it
    // at any date to reconcile a known figure against the venue's own reports.
    $probeDate = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
    if ($input && isset($input['probe_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['probe_date'])) {
        $probeDate = (string)$input['probe_date'];
    }

    try {
        $client->connect();

        // Run the query the page actually runs, scoped to the probe day, and
        // total it — the headline "does this match my books?" number.
        $buckets = cardloadsBucketsFromRows(
            $client->rows(MssqlClient::bindRange(cardloadsRangeSql(), $probeDate, $probeDate), 500));
        $paid = 0.0; $paidN = 0; $bonus = 0.0; $bonusN = 0;
        foreach ($buckets as $b) {
            $paid += $b['paid_dollars']; $paidN += $b['paid_loads'];
            $bonus += $b['bonus_dollars']; $bonusN += $b['bonus_loads'];
        }

        // Fingerprint: which server/db, how fresh, and a TransType breakdown for
        // the probe day so "TransType 3 = loads" stays verifiable and any other
        // value-add type can be spotted. Each probe is independent.
        $diag = [];
        $probe = function (string $label, string $sql) use (&$diag, $client) {
            try { $diag[$label] = $client->scalarText($sql); }
            catch (Exception $e) { $diag[$label] = 'error: ' . $e->getMessage(); }
        };
        $probe('server',   'SELECT @@SERVERNAME');
        $probe('database', 'SELECT DB_NAME()');
        $probe('playercardtrans_latest', 'SELECT CONVERT(VARCHAR(19), MAX(TransDateTime), 120) FROM [CenterEdge].[dbo].[PlayerCardTrans]');
        try {
            $rows = $client->rows(MssqlClient::bindDate(
                "SELECT TransType, COUNT(*) AS lines, SUM(DollarAmount) AS dollars"
                . " FROM [CenterEdge].[dbo].[PlayerCardTrans]"
                . " WHERE TransDateTime >= :date AND TransDateTime < DATEADD(DAY, 1, :date)"
                . " GROUP BY TransType ORDER BY SUM(DollarAmount) DESC", $probeDate), 20);
            $diag['transtypes_' . $probeDate] = $rows ? array_map(function ($r) {
                return 'TransType ' . $r['TransType'] . ': $' . round((float)$r['dollars'], 2)
                    . ' (' . $r['lines'] . ' lines)';
            }, $rows) : 'no card transactions on ' . $probeDate;
        } catch (Exception $e) {
            $diag['transtypes_' . $probeDate] = 'error: ' . $e->getMessage();
        }

        echo json_encode([
            'success'     => true,
            'driver'      => $client->driver(),
            'probe_date'  => $probeDate,
            'paid_dollars'  => round($paid, 2),
            'paid_loads'    => $paidN,
            'bonus_dollars' => round($bonus, 2),
            'bonus_loads'   => $bonusN,
            'diagnostics' => $diag,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function cardloadsData(): void {
    // Dollar figures: analytics access alone is not enough (tech never sees $).
    Auth::requireAccess('analytics');
    Auth::requireAccess('view_revenue');

    list($tz, $tzName) = perfTimezone();
    $today = (new DateTime('now', $tz))->format('Y-m-d');

    $win = perfResolveWindow($tz);
    $from = $win['from'];
    $to   = $win['to'];
    // Clamp the future away and bound the span at a year (the largest view).
    if ($to > $today) $to = $today;
    $spanDays = cardloadsDateSpan($from, $to);
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

    $meta = perfRangeMeta($win, $tzName);
    $meta['to'] = $to;

    $base = [
        'window'       => $meta,
        'configured'   => MssqlClient::isConfigured(),
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (!MssqlClient::isConfigured()) {
        echo json_encode($base + ['days' => [], 'hours' => [], 'heatmap' => null, 'summary' => null]);
        return;
    }

    $sql = cardloadsRangeSql();
    try {
        $client = new MssqlClient();
        $buckets = cardloadsBucketsFromRows(
            $client->rows(MssqlClient::bindRange($sql, $from, $to), 20000));
        // Prior period: totals only, for the headline delta.
        $priorPaid = 0.0; $priorBonus = 0.0;
        if (!empty($win['prev_from']) && !empty($win['prev_to'])) {
            foreach (cardloadsBucketsFromRows(
                        $client->rows(MssqlClient::bindRange($sql, $win['prev_from'], $win['prev_to']), 20000)) as $b) {
                $priorPaid += $b['paid_dollars']; $priorBonus += $b['bonus_dollars'];
            }
        }
    } catch (Exception $e) {
        echo json_encode($base + ['error' => $e->getMessage(), 'days' => [], 'hours' => [], 'heatmap' => null, 'summary' => null]);
        return;
    }

    echo json_encode($base + cardloadsCompose($dates, $buckets, round($priorPaid, 2), round($priorBonus, 2)));
}

/**
 * Compose per-day rows, the hour-of-day curve, the weekday×hour heatmap, and
 * the summary from the (day, hour) buckets. Pure — directly testable without a
 * connection.
 *
 * "Averaged out by hour and date" is honoured two ways:
 *   - hours[].paid_dollars_avg  = that hour's paid $ ÷ number of days in range
 *     (a typical day's curve).
 *   - heatmap per-occurrence     = each weekday×hour cell ÷ how many times that
 *     weekday occurred in the range (e.g. all Saturdays' 2 PM averaged) — the
 *     staffing view, same idea as the Reader Groups heatmap.
 *
 * @param string[] $dates every ISO date in the window, ascending
 * @param array $buckets cardloadsBucketsFromRows() output
 * @return array{days:array, hours:array, heatmap:array, summary:array}
 */
function cardloadsCompose(array $dates, array $buckets, float $priorPaid, float $priorBonus): array {
    $numDays = max(1, count($dates));

    // Occurrences per weekday in the window (for per-occurrence heatmap avgs).
    $dowOccurrences = array_fill(0, 7, 0);
    $dayIndex = [];
    foreach ($dates as $d) {
        $dowOccurrences[cardloadsWeekday($d)]++;
        $dayIndex[$d] = ['paid_dollars' => 0.0, 'paid_loads' => 0, 'bonus_dollars' => 0.0, 'bonus_loads' => 0];
    }

    // Accumulators.
    $byHour = [];
    for ($h = 0; $h < 24; $h++) {
        $byHour[$h] = ['paid_dollars' => 0.0, 'paid_loads' => 0, 'bonus_dollars' => 0.0, 'bonus_loads' => 0];
    }
    $byDowHour = [];
    for ($w = 0; $w < 7; $w++) {
        $byDowHour[$w] = [];
        for ($h = 0; $h < 24; $h++) $byDowHour[$w][$h] = ['paid_dollars' => 0.0, 'paid_loads' => 0];
    }
    $totPaid = 0.0; $totPaidN = 0; $totBonus = 0.0; $totBonusN = 0;

    foreach ($buckets as $b) {
        // Ignore rows outside the requested date set (a hand-edited query could
        // over-return); every reported day is one we asked for.
        if (!isset($dayIndex[$b['day']])) continue;
        $h = $b['hour'];
        $w = cardloadsWeekday($b['day']);

        $dayIndex[$b['day']]['paid_dollars']  += $b['paid_dollars'];
        $dayIndex[$b['day']]['paid_loads']    += $b['paid_loads'];
        $dayIndex[$b['day']]['bonus_dollars'] += $b['bonus_dollars'];
        $dayIndex[$b['day']]['bonus_loads']   += $b['bonus_loads'];

        $byHour[$h]['paid_dollars']  += $b['paid_dollars'];
        $byHour[$h]['paid_loads']    += $b['paid_loads'];
        $byHour[$h]['bonus_dollars'] += $b['bonus_dollars'];
        $byHour[$h]['bonus_loads']   += $b['bonus_loads'];

        $byDowHour[$w][$h]['paid_dollars'] += $b['paid_dollars'];
        $byDowHour[$w][$h]['paid_loads']   += $b['paid_loads'];

        $totPaid += $b['paid_dollars']; $totPaidN += $b['paid_loads'];
        $totBonus += $b['bonus_dollars']; $totBonusN += $b['bonus_loads'];
    }

    // ---- Daily rows (every day in range, zeros included) ----
    $days = [];
    foreach ($dates as $d) {
        $x = $dayIndex[$d];
        $days[] = [
            'date'          => $d,
            'paid_dollars'  => round($x['paid_dollars'], 2),
            'paid_loads'    => $x['paid_loads'],
            'bonus_dollars' => round($x['bonus_dollars'], 2),
            'bonus_loads'   => $x['bonus_loads'],
            'avg_load'      => $x['paid_loads'] > 0 ? round($x['paid_dollars'] / $x['paid_loads'], 2) : null,
        ];
    }

    // ---- Hour-of-day curve (per-day averages across the window) ----
    $hours = [];
    for ($h = 0; $h < 24; $h++) {
        $x = $byHour[$h];
        $hours[] = [
            'hour'              => $h,
            'paid_dollars'      => round($x['paid_dollars'], 2),
            'paid_loads'        => $x['paid_loads'],
            'bonus_dollars'     => round($x['bonus_dollars'], 2),
            'bonus_loads'       => $x['bonus_loads'],
            'paid_dollars_avg'  => round($x['paid_dollars'] / $numDays, 2),
            'paid_loads_avg'    => round($x['paid_loads'] / $numDays, 2),
            'avg_load'          => $x['paid_loads'] > 0 ? round($x['paid_dollars'] / $x['paid_loads'], 2) : null,
        ];
    }

    // ---- Weekday × hour heatmap (per-occurrence averages) ----
    $dowNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $heatRows = [];
    $heatMax = 0.0;
    for ($w = 0; $w < 7; $w++) {
        $occ = $dowOccurrences[$w];
        $cells = [];
        for ($h = 0; $h < 24; $h++) {
            $avg = $occ > 0 ? $byDowHour[$w][$h]['paid_dollars'] / $occ : 0.0;
            if ($avg > $heatMax) $heatMax = $avg;
            $cells[] = [
                'hour'             => $h,
                'paid_dollars_avg' => round($avg, 2),
                'paid_loads_avg'   => $occ > 0 ? round($byDowHour[$w][$h]['paid_loads'] / $occ, 2) : 0.0,
            ];
        }
        $heatRows[] = ['dow' => $w, 'label' => $dowNames[$w], 'occurrences' => $occ, 'cells' => $cells];
    }

    // Busiest hour / weekday by per-day (per-occurrence) average paid dollars.
    $busiestHour = null; $bestHourAvg = -1.0;
    foreach ($hours as $hh) {
        if ($hh['paid_dollars_avg'] > $bestHourAvg) { $bestHourAvg = $hh['paid_dollars_avg']; $busiestHour = $hh['hour']; }
    }
    $busiestDow = null; $bestDowAvg = -1.0;
    for ($w = 0; $w < 7; $w++) {
        $occ = $dowOccurrences[$w];
        if ($occ <= 0) continue;
        $sum = 0.0;
        for ($h = 0; $h < 24; $h++) $sum += $byDowHour[$w][$h]['paid_dollars'];
        $avg = $sum / $occ;
        if ($avg > $bestDowAvg) { $bestDowAvg = $avg; $busiestDow = $w; }
    }

    $summary = [
        'paid_dollars'   => round($totPaid, 2),
        'paid_loads'     => $totPaidN,
        'bonus_dollars'  => round($totBonus, 2),
        'bonus_loads'    => $totBonusN,
        'avg_load'       => $totPaidN > 0 ? round($totPaid / $totPaidN, 2) : null,
        'num_days'       => count($dates),
        'per_day'        => round($totPaid / $numDays, 2),
        'busiest_hour'   => $busiestHour,
        'busiest_dow'    => $busiestDow,
        'busiest_dow_label' => $busiestDow !== null ? $dowNames[$busiestDow] : null,
        'prior_paid_dollars'  => $priorPaid,
        'prior_bonus_dollars' => $priorBonus,
        'delta_pct'      => $priorPaid > 0 ? round(($totPaid - $priorPaid) / $priorPaid, 4) : null,
    ];

    return ['days' => $days, 'hours' => $hours, 'heatmap' => ['max_avg' => round($heatMax, 2), 'rows' => $heatRows], 'summary' => $summary];
}

/** Inclusive day count between two ISO dates (0 when to < from). */
function cardloadsDateSpan(string $from, string $to): int {
    $a = DateTime::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b || $b < $a) return 0;
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400) + 1;
}
