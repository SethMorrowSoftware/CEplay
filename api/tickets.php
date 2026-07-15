<?php
/**
 * Ticket Trends API — redemption tickets earned by AREA (division) over time,
 * straight from the CenterEdge MSSQL PlayerCardTrans ledger. Browsable by
 * Day / Week / Month / Year / Custom (same window model as Performance/Labor/
 * Card Loads).
 *
 *   GET  /api/tickets/settings — editable query (settings perm)
 *   PUT  /api/tickets/settings — save it (settings perm)
 *   POST /api/tickets/test     — run the query for a probe range + fingerprint (settings perm)
 *   GET  /api/tickets/data?range=&offset=&from=&to= — per-day + per-division +
 *          summary (analytics + view_revenue)
 *
 * WHY division, not game: tickets are booked to the card with a DivNo (area) but
 * NEVER a reader/game — every ValueNo-3 credit has rdrkey 0 — so the finest
 * attribution the POS DB supports is the division. ValueNo 3 = redemption
 * tickets; they carry no dollar value, and `Amount` is the ticket-unit count.
 *
 * The query is admin-editable SQL with required :from/:to placeholders, guarded
 * to a single SELECT (MssqlClient::assertReadOnly), returning one row per
 * (day, division). PHP rolls up the daily trend + per-division breakdown, so a
 * Year view costs the same one round-trip as a Day. Shares the Go-Kart Labor
 * page's MSSQL connection; the connection itself is configured there.
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/analytics.php';

// Per (local day, division): ticket units and credit count. ValueNo 3 =
// tickets. TransDateTime is the POS's venue-local clock, so CONVERT(...,120)
// yields the same local day the app bins by.
const TICKETS_DEFAULT_RANGE_SQL = "SELECT CONVERT(VARCHAR(10), TransDateTime, 120) AS day,\n       DivNo AS div,\n       SUM(Amount) AS tickets,\n       COUNT(*) AS credits\nFROM [CenterEdge].[dbo].[PlayerCardTrans]\nWHERE ValueNo = 3  /* ValueNo 3 = redemption tickets (no dollar value) */\n  AND TransDateTime >= :from\n  AND TransDateTime < DATEADD(DAY, 1, :to)\nGROUP BY CONVERT(VARCHAR(10), TransDateTime, 120), DivNo";

function handleTickets(string $method, array $parts, ?array $input): void {
    $action = $parts[0] ?? '';
    switch ($action) {
        case 'settings':
            if ($method === 'GET') { ticketsGetSettings(); return; }
            if ($method === 'PUT') { ticketsPutSettings($input ?? []); return; }
            break;
        case 'test':
            if ($method === 'POST') { ticketsTest($input); return; }
            break;
        case 'data':
            if ($method === 'GET') { ticketsData(); return; }
            break;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Unknown tickets endpoint']);
}

function ticketsRangeSql(): string {
    return DB::getConfig('tickets_range_sql') ?: TICKETS_DEFAULT_RANGE_SQL;
}

/**
 * Normalize the range query's rows into (day, div, tickets, credits), tolerant
 * of column naming (named keys any case, else positional).
 *
 * @return array<int,array{day:string,div:int,tickets:float,credits:int}>
 */
function ticketsBucketsFromRows(array $rows): array {
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
            'day'     => $day,
            'div'     => (int)$get($row, $vals, 'div', 1),
            'tickets' => (float)$get($row, $vals, 'tickets', 2),
            'credits' => (int)$get($row, $vals, 'credits', 3),
        ];
    }
    return $out;
}

/**
 * Best-effort DivNo → name map. The divisions lookup table's name varies by
 * install, so discover it via INFORMATION_SCHEMA (an integer No/ID column + a
 * text Name/Desc column) — the same approach the Labor page uses. Failure is
 * harmless: the report just shows "Division N".
 *
 * @return array<string,string> DivNo (string) => name
 */
function ticketsDivNames(MssqlClient $client): array {
    $ident = function (string $n): string {
        return '[' . preg_replace('/[^A-Za-z0-9_ #\-]/', '', $n) . ']';
    };
    try {
        $tables = $client->rows(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE '%Div%' ORDER BY TABLE_NAME", 16);
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
                if ($noCol === null && $isInt && preg_match('/^(DivNo|DivisionNo|DivID|DivisionID|No|ID)$/i', $cn)) $noCol = $cn;
                if ($nameCol === null && in_array($ct, ['varchar', 'nvarchar', 'char', 'nchar'], true) && preg_match('/(Desc|Name|Title)/i', $cn)) $nameCol = $cn;
            }
            if ($noCol && $nameCol) {
                $map = [];
                foreach ($client->rows('SELECT ' . $ident($noCol) . ' AS no, ' . $ident($nameCol) . ' AS name FROM dbo.' . $ident($tbl), 500) as $r) {
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

function ticketsGetSettings(): void {
    Auth::requireAccess('settings');
    echo json_encode([
        'configured' => MssqlClient::isConfigured(),
        'drivers'    => MssqlClient::availableDrivers(),
        'range_sql'  => ticketsRangeSql(),
        'defaults'   => ['range_sql' => TICKETS_DEFAULT_RANGE_SQL],
        'connection' => [
            'host'     => (MssqlClient::settings()['host'] ?? '') !== '',
            'database' => MssqlClient::settings()['database'] ?? '',
        ],
    ]);
}

function ticketsPutSettings(array $input): void {
    Auth::requireAccess('settings');
    $rangeSql = Validator::requireString($input, 'range_sql', 4000);
    MssqlClient::assertReadOnly($rangeSql);
    MssqlClient::bindRange($rangeSql, '2000-01-01', '2000-01-02');
    DB::setConfig('tickets_range_sql', $rangeSql);
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'tickets_settings_update', 'Ticket-trends query updated by ' . (Auth::check()['username'] ?? '?')]
        );
    } catch (Exception $e) {
        error_log('tickets settings audit log failed: ' . $e->getMessage());
    }
    echo json_encode(['success' => true, 'configured' => MssqlClient::isConfigured()]);
}

function ticketsTest(?array $input = null): void {
    Auth::requireAccess('settings');
    $client = new MssqlClient();
    $tz = new DateTimeZone(DB::getConfig('timezone') ?: DEFAULT_TIMEZONE);
    $probeDate = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
    if ($input && isset($input['probe_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['probe_date'])) {
        $probeDate = (string)$input['probe_date'];
    }
    try {
        $client->connect();
        $buckets = ticketsBucketsFromRows(
            $client->rows(MssqlClient::bindRange(ticketsRangeSql(), $probeDate, $probeDate), 5000));
        $total = 0.0; $credits = 0;
        foreach ($buckets as $b) { $total += $b['tickets']; $credits += $b['credits']; }
        $divNames = ticketsDivNames($client);

        $diag = [];
        $probe = function (string $label, string $sql) use (&$diag, $client) {
            try { $diag[$label] = $client->scalarText($sql); }
            catch (Exception $e) { $diag[$label] = 'error: ' . $e->getMessage(); }
        };
        $probe('server',   'SELECT @@SERVERNAME');
        $probe('database', 'SELECT DB_NAME()');
        // Ticket units by division for the probe day, names joined where found.
        $byDiv = [];
        foreach ($buckets as $b) {
            $byDiv[$b['div']] = ($byDiv[$b['div']] ?? 0) + $b['tickets'];
        }
        arsort($byDiv);
        $lines = [];
        foreach (array_slice($byDiv, 0, 15, true) as $div => $tix) {
            $nm = isset($divNames[(string)$div]) ? ' "' . $divNames[(string)$div] . '"' : '';
            $lines[] = 'DivNo ' . $div . $nm . ': ' . round($tix) . ' tickets';
        }
        $diag['tickets_by_division_' . $probeDate] = $lines ?: 'no ticket credits on ' . $probeDate;

        echo json_encode([
            'success'     => true,
            'driver'      => $client->driver(),
            'probe_date'  => $probeDate,
            'tickets'     => round($total),
            'credits'     => $credits,
            'diagnostics' => $diag,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function ticketsData(): void {
    // Ticket volumes ride the same money gate as the other MSSQL reports.
    Auth::requireAccess('analytics');
    Auth::requireAccess('view_revenue');

    list($tz, $tzName) = perfTimezone();
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $win = perfResolveWindow($tz);
    $from = $win['from'];
    $to   = $win['to'];
    if ($to > $today) $to = $today;
    $span = ticketsDateSpan($from, $to);
    if ($span < 1) throw new RuntimeException('The selected period is entirely in the future.');
    if ($span > 380) throw new RuntimeException('Ranges longer than a year aren\'t supported here — pick a year or less.');
    $dates = [];
    $cur = DateTime::createFromFormat('!Y-m-d', $from, $tz);
    for ($i = 0; $i < $span; $i++) { $dates[] = $cur->format('Y-m-d'); $cur->modify('+1 day'); }

    $meta = perfRangeMeta($win, $tzName);
    $meta['to'] = $to;
    $base = ['window' => $meta, 'configured' => MssqlClient::isConfigured(), 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')];

    if (!MssqlClient::isConfigured()) {
        echo json_encode($base + ['days' => [], 'divisions' => [], 'summary' => null]);
        return;
    }

    $sql = ticketsRangeSql();
    try {
        $client = new MssqlClient();
        $buckets = ticketsBucketsFromRows($client->rows(MssqlClient::bindRange($sql, $from, $to), 40000));
        $prior = 0.0;
        if (!empty($win['prev_from']) && !empty($win['prev_to'])) {
            foreach (ticketsBucketsFromRows($client->rows(MssqlClient::bindRange($sql, $win['prev_from'], $win['prev_to']), 40000)) as $b) {
                $prior += $b['tickets'];
            }
        }
        $divNames = ticketsDivNames($client);
    } catch (Exception $e) {
        echo json_encode($base + ['error' => $e->getMessage(), 'days' => [], 'divisions' => [], 'summary' => null]);
        return;
    }

    echo json_encode($base + ticketsCompose($dates, $buckets, $divNames, $prior));
}

/**
 * Compose the daily trend + per-division breakdown + summary from the
 * (day, division) buckets. Pure — directly testable without a connection.
 *
 * @param string[] $dates every ISO date in the window, ascending
 * @param array $buckets ticketsBucketsFromRows() output
 * @param array<string,string> $divNames DivNo => name
 * @return array{days:array, divisions:array, summary:array}
 */
function ticketsCompose(array $dates, array $buckets, array $divNames, float $priorTickets): array {
    $numDays = max(1, count($dates));
    $dayIndex = [];
    foreach ($dates as $d) $dayIndex[$d] = ['tickets' => 0.0, 'credits' => 0];
    $byDiv = [];
    $tot = 0.0; $totCredits = 0;

    foreach ($buckets as $b) {
        if (!isset($dayIndex[$b['day']])) continue; // only days we asked for
        $dayIndex[$b['day']]['tickets'] += $b['tickets'];
        $dayIndex[$b['day']]['credits'] += $b['credits'];
        $dv = $b['div'];
        if (!isset($byDiv[$dv])) $byDiv[$dv] = ['tickets' => 0.0, 'credits' => 0];
        $byDiv[$dv]['tickets'] += $b['tickets'];
        $byDiv[$dv]['credits'] += $b['credits'];
        $tot += $b['tickets']; $totCredits += $b['credits'];
    }

    $days = [];
    foreach ($dates as $d) {
        $days[] = ['date' => $d, 'tickets' => round($dayIndex[$d]['tickets']), 'credits' => $dayIndex[$d]['credits']];
    }

    $divisions = [];
    foreach ($byDiv as $dv => $a) {
        $divisions[] = [
            'div'     => $dv,
            'name'    => $divNames[(string)$dv] ?? '',
            'tickets' => round($a['tickets']),
            'credits' => $a['credits'],
            'share'   => $tot > 0 ? round($a['tickets'] / $tot, 4) : 0,
        ];
    }
    usort($divisions, function ($x, $y) { return $y['tickets'] <=> $x['tickets']; });
    $top = $divisions[0] ?? null;

    $summary = [
        'tickets'       => round($tot),
        'credits'       => $totCredits,
        'num_days'      => count($dates),
        'per_day'       => round($tot / $numDays),
        'top_div'       => $top['div'] ?? null,
        'top_div_name'  => $top['name'] ?? null,
        'top_div_share' => $top['share'] ?? null,
        'prior_tickets' => round($priorTickets),
        'delta_pct'     => $priorTickets > 0 ? round(($tot - $priorTickets) / $priorTickets, 4) : null,
    ];

    return ['days' => $days, 'divisions' => $divisions, 'summary' => $summary];
}

/** Inclusive day count between two ISO dates (0 when to < from). */
function ticketsDateSpan(string $from, string $to): int {
    $a = DateTime::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b || $b < $a) return 0;
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400) + 1;
}
