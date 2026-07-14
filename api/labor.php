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
 * The two queries are admin-editable SQL with a required :date placeholder,
 * guarded to a single SELECT (see MssqlClient::assertReadOnly). Defaults
 * mirror the venue's existing hand-run SQL; the go-kart department filter
 * is a placeholder comment the admin completes on-site, since column
 * naming varies by CenterEdge install.
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';

// Defaults mirror the venue's proven Grafana query verbatim, with the
// $__timeFilter(col) macro translated to `col = :date`. Go-kart sales are
// category 106; go-kart labor is every punch whose job code description is
// 'Karting', costed to the second from actual clock-in/out timestamps
// (still-open punches accrue to now, matching the Grafana behavior).
const LABOR_DEFAULT_SALES_SQL = "SELECT COALESCE(SUM(AmtSold), 0)\nFROM [CenterEdge].[dbo].[Sales]\nWHERE CatNo = 106  /* go-kart sales category */\n  AND ShiftDate = :date";

const LABOR_DEFAULT_LABOR_SQL = "SELECT COALESCE(SUM(\n  PayRate * DATEDIFF(\n    SECOND,\n    ClockInDate + CAST(CAST(ClockInTime AS TIME) AS DATETIME),\n    CASE WHEN ClockOutDate IS NULL\n      THEN CURRENT_TIMESTAMP\n      ELSE ClockOutDate + CAST(CAST(ClockOutTime AS TIME) AS DATETIME)\n    END\n  ) / 3600.0\n), 0)\nFROM CenterEdge.dbo.TimeClock_Weekly\nINNER JOIN CenterEdge.dbo.TimeClock_JobCodes\n  ON TimeClock_Weekly.JobCode = TimeClock_JobCodes.JobCode\nWHERE TimeClock_JobCodes.Description = 'Karting'\n  AND ClockInDate = :date";

function handleLabor(string $method, array $parts, ?array $input): void {
    $action = $parts[0] ?? '';

    switch ($action) {
        case 'settings':
            if ($method === 'GET') { laborGetSettings(); return; }
            if ($method === 'PUT') { laborPutSettings($input ?? []); return; }
            break;
        case 'test':
            if ($method === 'POST') { laborTest(); return; }
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
        'sales_sql' => DB::getConfig('labor_sales_sql') ?: LABOR_DEFAULT_SALES_SQL,
        'labor_sql' => DB::getConfig('labor_labor_sql') ?: LABOR_DEFAULT_LABOR_SQL,
    ];
}

function laborGetSettings(): void {
    Auth::requireAccess('settings');
    $out = MssqlClient::settings();
    $out += laborQueries();
    $out['configured'] = MssqlClient::isConfigured();
    $out['defaults'] = ['sales_sql' => LABOR_DEFAULT_SALES_SQL, 'labor_sql' => LABOR_DEFAULT_LABOR_SQL];
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

    $salesSql = Validator::requireString($input, 'sales_sql', 4000);
    $laborSql = Validator::requireString($input, 'labor_sql', 4000);
    // Validate both queries NOW so a bad save fails loudly, not at 9 PM
    // when someone opens the report.
    MssqlClient::assertReadOnly($salesSql);
    MssqlClient::assertReadOnly($laborSql);
    MssqlClient::bindDate($salesSql, '2000-01-01');
    MssqlClient::bindDate($laborSql, '2000-01-01');

    DB::setConfig('mssql_host', $host);
    DB::setConfig('mssql_port', (string)$port);
    DB::setConfig('mssql_database', $database);
    DB::setConfig('mssql_username', $username);
    if ($password !== '') {
        DB::setConfig('mssql_password', Crypto::encrypt($password));
    }
    DB::setConfig('labor_sales_sql', $salesSql);
    DB::setConfig('labor_labor_sql', $laborSql);

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

function laborTest(): void {
    Auth::requireAccess('settings');
    $client = new MssqlClient();
    $today = (new DateTime('now', new DateTimeZone(DB::getConfig('timezone') ?: DEFAULT_TIMEZONE)))->format('Y-m-d');
    $q = laborQueries();
    try {
        $client->connect();
        $sales = $client->scalar(MssqlClient::bindDate($q['sales_sql'], $today));
        $labor = $client->scalar(MssqlClient::bindDate($q['labor_sql'], $today));
        echo json_encode([
            'success' => true,
            'driver'  => $client->driver(),
            'today'   => $today,
            'sales'   => round($sales, 2),
            'labor'   => round($labor, 2),
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

    $raw = trim((string)($_GET['dates'] ?? ''));
    if ($raw === '') {
        throw new RuntimeException('dates parameter is required (comma-separated YYYY-MM-DD).');
    }
    $dates = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    if (count($dates) > 14) {
        throw new RuntimeException('At most 14 dates per request.');
    }
    foreach ($dates as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            throw new RuntimeException('Invalid date: ' . $d);
        }
    }

    $q = laborQueries();
    $client = new MssqlClient();
    $days = [];
    foreach ($dates as $d) {
        try {
            $sales = $client->scalar(MssqlClient::bindDate($q['sales_sql'], $d));
            $labor = $client->scalar(MssqlClient::bindDate($q['labor_sql'], $d));
            $days[] = [
                'date'  => $d,
                'sales' => round($sales, 2),
                'labor' => round($labor, 2),
                'rate'  => $sales > 0 ? round($labor / $sales, 4) : null,
            ];
        } catch (Exception $e) {
            $days[] = ['date' => $d, 'error' => $e->getMessage()];
            // A connection-level failure will fail every date the same way;
            // stop after the first so the response arrives fast.
            if (strpos($e->getMessage(), 'Could not connect') !== false
                || strpos($e->getMessage(), 'not configured') !== false
                || strpos($e->getMessage(), 'driver') !== false) {
                break;
            }
        }
    }
    echo json_encode([
        'days' => $days,
        'configured' => MssqlClient::isConfigured(),
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}
