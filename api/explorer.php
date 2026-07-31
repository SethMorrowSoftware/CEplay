<?php
/**
 * CenterEdge Database Explorer API — a READ-ONLY window into the venue's
 * MSSQL database, for finding where metrics actually live (the tool-ified
 * version of the fingerprint probing that located the go-kart money under
 * DivNo 808 "Go Kart Readers").
 *
 *   GET  /api/explorer/tables       — every base table (+ row/column counts where readable)
 *   GET  /api/explorer/table?name=X — columns/types, date-column freshness, sample rows (newest first)
 *   GET  /api/explorer/search?q=X   — find tables BY COLUMN NAME across the whole schema
 *   POST /api/explorer/aggregate    — grouped totals: {table, group_by, sum_col?, date_col?, from?, to?}
 *   POST /api/explorer/query        — free-form guarded SELECT: {sql, limit?}
 *   GET  /api/explorer/history-sources — can per-GAME play history reach back
 *        past the Embed → CenterEdge/Kiosoft reader cutover? (see below)
 *
 * Gate: settings (admin plumbing; the page shares the Labor page's MSSQL
 * connection). Every statement passes MssqlClient::assertReadOnly (single
 * SELECT, keyword blacklist); identifiers used by the builder endpoints are
 * validated against INFORMATION_SCHEMA before being embedded, then
 * bracket-quoted. Statements run with the configured SQL login's
 * privileges — use a read-only login. Free-form/aggregate runs are
 * audit-logged.
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';

const EXPLORER_MAX_ROWS = 500;
const EXPLORER_SAMPLE_ROWS = 25;
const EXPLORER_CELL_CHARS = 160;

function handleExplorer(string $method, array $parts, ?array $input): void {
    // Raw POS-database access (schema + rows, incl. dollar/card figures) —
    // gated on data_explorer, not general settings, so a technician who holds
    // settings for CenterEdge/timezone config cannot reach the POS data here.
    Auth::requireAccess('data_explorer');
    $action = $parts[0] ?? '';

    switch ($action) {
        case 'tables':
            if ($method === 'GET') { explorerTables(); return; }
            break;
        case 'table':
            if ($method === 'GET') { explorerTableDetail(); return; }
            break;
        case 'search':
            if ($method === 'GET') { explorerSearch(); return; }
            break;
        case 'aggregate':
            if ($method === 'POST') { explorerAggregate($input ?? []); return; }
            break;
        case 'query':
            if ($method === 'POST') { explorerQuery($input ?? []); return; }
            break;
        case 'history-sources':
            if ($method === 'GET') { explorerHistorySources(); return; }
            break;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Unknown explorer endpoint']);
}

/** Bracket-quote an identifier that has already been schema-validated. */
function explorerIdent(string $name): string {
    return '[' . str_replace(']', '', $name) . ']';
}

/** Single-quoted SQL string literal. */
function explorerLit(string $v): string {
    return "'" . str_replace("'", "''", $v) . "'";
}

/**
 * Escape a user term for use inside a LIKE '%…%' pattern (ESCAPE '\'):
 * backslash first, then the wildcard metacharacters. Pure — unit-tested.
 */
function explorerLikeEscape(string $term): string {
    return str_replace(['\\', '%', '_', '['], ['\\\\', '\\%', '\\_', '\\['], $term);
}

function explorerJson(array $payload): void {
    // Sample cells can carry non-UTF8 bytes (legacy collations, binary
    // columns) — substitute rather than emitting invalid JSON.
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * Format 16 raw uniqueidentifier bytes the way SQL Server prints the GUID.
 * MSSQL stores the first three fields little-endian, so the byte order is
 * [3 2 1 0]-[5 4]-[7 6]-[8 9]-[10..15].
 */
function explorerGuidFromBinary(string $bin): string {
    $h = strtoupper(bin2hex($bin)); // 32 hex chars
    $b = str_split($h, 2);
    return $b[3] . $b[2] . $b[1] . $b[0]
        . '-' . $b[5] . $b[4]
        . '-' . $b[7] . $b[6]
        . '-' . $b[8] . $b[9]
        . '-' . $b[10] . $b[11] . $b[12] . $b[13] . $b[14] . $b[15];
}

/**
 * Clamp a cell for transport: scalars only, bounded length. Raw binary
 * (dblib hands uniqueidentifier/varbinary columns back as bytes) renders
 * as a GUID (16 bytes) or 0x-hex instead of mojibake.
 */
function explorerCell($v) {
    if ($v === null) return null;
    if (!is_scalar($v)) return '[binary]';
    $s = (string)$v;
    // Control bytes / invalid UTF-8 ⇒ this "string" is really binary.
    if ($s !== '' && (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s) || !mb_check_encoding($s, 'UTF-8'))) {
        if (strlen($s) === 16) {
            return explorerGuidFromBinary($s);
        }
        $hex = '0x' . strtoupper(bin2hex(substr($s, 0, 32)));
        return strlen($s) > 32 ? $hex . '… (' . strlen($s) . ' bytes)' : $hex;
    }
    if (strlen($s) > EXPLORER_CELL_CHARS) {
        $s = substr($s, 0, EXPLORER_CELL_CHARS) . '…';
    }
    return $s;
}

/** Column types the driver returns as raw bytes or refuses to render. */
function explorerSelectExpr(string $name, string $type): string {
    $c = explorerIdent($name);
    switch ($type) {
        case 'uniqueidentifier':
            return 'CONVERT(VARCHAR(36), ' . $c . ') AS ' . $c;
        case 'binary':
        case 'varbinary':
        case 'timestamp':
        case 'rowversion':
            // Style 1 = '0x…' hex; clamp the bytes first so a blob column
            // can't flood the response.
            return 'CONVERT(VARCHAR(66), CAST(' . $c . ' AS VARBINARY(32)), 1) AS ' . $c;
        case 'image':
            return 'CONVERT(VARCHAR(66), CAST(CAST(' . $c . ' AS VARBINARY(MAX)) AS VARBINARY(32)), 1) AS ' . $c;
        case 'text':
            return 'CAST(CAST(' . $c . ' AS VARCHAR(MAX)) AS VARCHAR(200)) AS ' . $c;
        case 'ntext':
            return 'CAST(CAST(' . $c . ' AS NVARCHAR(MAX)) AS NVARCHAR(200)) AS ' . $c;
        case 'xml':
            return 'LEFT(CAST(' . $c . ' AS NVARCHAR(MAX)), 200) AS ' . $c;
        case 'sql_variant':
            return 'CONVERT(NVARCHAR(200), ' . $c . ') AS ' . $c;
        default:
            return $c;
    }
}

/** Full select list for a sample: every column, decode-safe. */
function explorerSampleSelectList(array $cols): string {
    return implode(', ', array_map(function ($c) {
        return explorerSelectExpr($c['name'], $c['type']);
    }, $cols));
}

/**
 * The list of base tables, schema-qualified, with row counts when the
 * login can read sys.partitions (counts are approximate but instant —
 * COUNT(*) on a years-deep Sales table is not something to run casually).
 */
function explorerTables(): void {
    if (!MssqlClient::isConfigured()) {
        explorerJson(['configured' => false, 'tables' => []]);
        return;
    }
    try {
        $client = new MssqlClient();
        $rows = $client->rows(
            "SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", 500);
        $tables = [];
        foreach ($rows as $r) {
            $vals = array_values($r);
            $tables[] = [
                'schema' => (string)($r['TABLE_SCHEMA'] ?? $vals[0] ?? 'dbo'),
                'name'   => (string)($r['TABLE_NAME'] ?? $vals[1] ?? ''),
                'rows'   => null,
            ];
        }
        // Best-effort row counts; absence is fine.
        try {
            $counts = [];
            foreach ($client->rows(
                "SELECT t.name AS tbl, SUM(p.rows) AS cnt FROM sys.tables t JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1) GROUP BY t.name", 500) as $c) {
                $vals = array_values($c);
                $counts[strtolower((string)($c['tbl'] ?? $vals[0] ?? ''))] = (int)($c['cnt'] ?? $vals[1] ?? 0);
            }
            foreach ($tables as &$t) {
                $key = strtolower($t['name']);
                if (isset($counts[$key])) $t['rows'] = $counts[$key];
            }
            unset($t);
        } catch (Exception $e) {
            // sys.* not readable with this login — counts stay null.
        }
        // Column counts give a feel for a table's width at a glance.
        try {
            $colCounts = [];
            foreach ($client->rows(
                "SELECT TABLE_NAME, COUNT(*) AS cols FROM INFORMATION_SCHEMA.COLUMNS GROUP BY TABLE_NAME", 500) as $c) {
                $vals = array_values($c);
                $colCounts[strtolower((string)($c['TABLE_NAME'] ?? $vals[0] ?? ''))] = (int)($c['cols'] ?? $vals[1] ?? 0);
            }
            foreach ($tables as &$t) {
                $t['cols'] = $colCounts[strtolower($t['name'])] ?? null;
            }
            unset($t);
        } catch (Exception $e) {
            // Optional context — absence is fine.
        }
        explorerJson(['configured' => true, 'tables' => $tables, 'driver' => $client->driver()]);
    } catch (Exception $e) {
        explorerJson(['configured' => MssqlClient::isConfigured(), 'error' => $e->getMessage(), 'tables' => []]);
    }
}

/**
 * Resolve a requested table name against INFORMATION_SCHEMA (exact,
 * case-insensitive) and return [schema, canonicalName, columns[]].
 * Throws RuntimeException for unknown tables — nothing user-supplied is
 * ever embedded without passing through here first.
 *
 * @return array{0:string,1:string,2:array<int,array{name:string,type:string,nullable:bool}>}
 */
function explorerResolveTable(MssqlClient $client, string $requested): array {
    $requested = trim($requested);
    if ($requested === '' || strlen($requested) > 200) {
        throw new RuntimeException('Table name is required.');
    }
    $rows = $client->rows(
        "SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = " . explorerLit($requested), 5);
    if (!$rows) {
        throw new RuntimeException('Unknown table: ' . $requested);
    }
    $vals = array_values($rows[0]);
    $schema = (string)($rows[0]['TABLE_SCHEMA'] ?? $vals[0] ?? 'dbo');
    $name   = (string)($rows[0]['TABLE_NAME'] ?? $vals[1] ?? $requested);

    // Schema-qualified: same-named tables in two schemas must not merge.
    $cols = [];
    foreach ($client->rows(
        "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = " . explorerLit($schema) . " AND TABLE_NAME = " . explorerLit($name) . " ORDER BY ORDINAL_POSITION", 300) as $c) {
        $cvals = array_values($c);
        $cols[] = [
            'name'     => (string)($c['COLUMN_NAME'] ?? $cvals[0] ?? ''),
            'type'     => strtolower((string)($c['DATA_TYPE'] ?? $cvals[1] ?? '')),
            'nullable' => strcasecmp((string)($c['IS_NULLABLE'] ?? $cvals[2] ?? ''), 'YES') === 0,
        ];
    }
    if (!$cols) {
        throw new RuntimeException('Could not read columns for ' . $name . '.');
    }
    return [$schema, $name, $cols];
}

/** Case-insensitive column lookup; throws when absent. */
function explorerRequireColumn(array $cols, string $requested, string $what): string {
    foreach ($cols as $c) {
        if (strcasecmp($c['name'], $requested) === 0) return $c['name'];
    }
    throw new RuntimeException('Unknown ' . $what . ' column: ' . $requested);
}

/** Column types that can anchor a date filter or freshness probe. */
function explorerIsDateType(string $type): bool {
    return in_array($type, ['date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'], true);
}

/**
 * Table deep-dive: columns, freshness of up to four date columns (MIN and
 * MAX — how far back does history go, how fresh is it), and a small
 * sample of recent rows.
 */
function explorerTableDetail(): void {
    $name = (string)($_GET['name'] ?? '');
    try {
        $client = new MssqlClient();
        list($schema, $table, $cols) = explorerResolveTable($client, $name);
        $qualified = explorerIdent($schema) . '.' . explorerIdent($table);

        $freshness = [];
        $probed = 0;
        foreach ($cols as $c) {
            if (!explorerIsDateType($c['type']) || $probed >= 4) continue;
            $probed++;
            try {
                $row = $client->rows(
                    'SELECT CONVERT(VARCHAR(19), MIN(' . explorerIdent($c['name']) . '), 120) AS mn, '
                    . 'CONVERT(VARCHAR(19), MAX(' . explorerIdent($c['name']) . '), 120) AS mx FROM ' . $qualified, 1);
                if ($row) {
                    $vals = array_values($row[0]);
                    $freshness[] = [
                        'column' => $c['name'],
                        'min' => explorerCell($row[0]['mn'] ?? $vals[0] ?? null),
                        'max' => explorerCell($row[0]['mx'] ?? $vals[1] ?? null),
                    ];
                }
            } catch (Exception $e) {
                // A single unaggregatable column must not sink the page.
            }
        }

        // Sample NEWEST rows when a date column exists — an unordered TOP
        // returns allocation order, which on a years-deep table means 2019
        // rows and tells you nothing about what the data looks like today.
        $sample = [];
        $sampleCols = [];
        $sampleOrder = null;
        foreach ($cols as $c) {
            if (explorerIsDateType($c['type'])) { $sampleOrder = $c['name']; break; }
        }
        $selectList = explorerSampleSelectList($cols);
        foreach ([true, false] as $useOrder) {
            if ($useOrder && $sampleOrder === null) continue;
            try {
                $sql = 'SELECT TOP ' . EXPLORER_SAMPLE_ROWS . ' ' . $selectList . ' FROM ' . $qualified
                     . ($useOrder ? ' ORDER BY ' . explorerIdent($sampleOrder) . ' DESC' : '');
                foreach ($client->rows($sql, EXPLORER_SAMPLE_ROWS) as $r) {
                    if (!$sampleCols) $sampleCols = array_map('strval', array_keys($r));
                    $sample[] = array_map('explorerCell', array_values($r));
                }
                break; // got rows (or an empty table) — done
            } catch (Exception $e) {
                // Ordered probe can fail on exotic types — fall through to
                // the unordered fallback.
                $sample = [];
                $sampleCols = [];
            }
        }

        explorerJson([
            'schema' => $schema,
            'table' => $table,
            'columns' => $cols,
            'freshness' => $freshness,
            'sample_columns' => $sampleCols,
            'sample' => $sample,
            // Which column (if any) the sample is sorted newest-first by.
            'sample_order' => ($sample && $sampleOrder !== null) ? $sampleOrder : null,
        ]);
    } catch (RuntimeException $e) {
        throw $e; // → 422 with message
    } catch (Exception $e) {
        explorerJson(['error' => $e->getMessage()]);
    }
}

/**
 * Column-name search across the whole schema: "which tables have a column
 * like 'hour' / 'amt' / 'ticket'?" — THE question when hunting where a
 * metric lives. One INFORMATION_SCHEMA query, grouped by table.
 */
function explorerSearch(): void {
    $q = trim((string)($_GET['q'] ?? ''));
    if (strlen($q) < 2) {
        throw new RuntimeException('Search needs at least 2 characters.');
    }
    if (strlen($q) > 100) {
        throw new RuntimeException('Search term is too long.');
    }
    try {
        $client = new MssqlClient();
        $like = explorerLit('%' . explorerLikeEscape($q) . '%');
        $rows = $client->rows(
            "SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE"
            . " FROM INFORMATION_SCHEMA.COLUMNS c"
            . " JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'"
            . " WHERE c.COLUMN_NAME LIKE " . $like . " ESCAPE '\\'"
            . " ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION", 300);
        $byTable = [];
        $order = [];
        foreach ($rows as $r) {
            $vals = array_values($r);
            $tbl = (string)($r['TABLE_NAME'] ?? $vals[1] ?? '');
            if ($tbl === '') continue;
            if (!isset($byTable[$tbl])) {
                $byTable[$tbl] = [
                    'schema' => (string)($r['TABLE_SCHEMA'] ?? $vals[0] ?? 'dbo'),
                    'table' => $tbl,
                    'columns' => [],
                ];
                $order[] = $tbl;
            }
            $byTable[$tbl]['columns'][] = [
                'name' => (string)($r['COLUMN_NAME'] ?? $vals[2] ?? ''),
                'type' => strtolower((string)($r['DATA_TYPE'] ?? $vals[3] ?? '')),
            ];
        }
        $matches = [];
        foreach ($order as $tbl) $matches[] = $byTable[$tbl];
        explorerJson([
            'q' => $q,
            'matches' => $matches,
            'truncated' => count($rows) >= 300,
        ]);
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Exception $e) {
        explorerJson(['q' => $q, 'matches' => [], 'error' => $e->getMessage()]);
    }
}

/**
 * Build the grouped-total SQL from ALREADY-VALIDATED identifiers. Pure —
 * unit-testable without a connection.
 */
function explorerAggregateSql(string $schema, string $table, string $groupBy, ?string $sumCol, ?string $dateCol, ?string $from, ?string $to, string $groupType = ''): string {
    $qualified = explorerIdent($schema) . '.' . explorerIdent($table);
    $g = explorerIdent($groupBy);
    // GUID/binary group columns must be decoded server-side or the driver
    // hands back raw bytes; grouping stays on the raw column.
    $gSelect = $g;
    if ($groupType === 'uniqueidentifier') {
        $gSelect = 'CONVERT(VARCHAR(36), ' . $g . ')';
    } elseif (in_array($groupType, ['binary', 'varbinary', 'timestamp', 'rowversion'], true)) {
        $gSelect = 'CONVERT(VARCHAR(66), CAST(' . $g . ' AS VARBINARY(32)), 1)';
    }
    $select = 'SELECT TOP 100 ' . $gSelect . ' AS grp, COUNT(*) AS lines';
    $order = 'lines';
    if ($sumCol !== null) {
        $select .= ', SUM(' . explorerIdent($sumCol) . ') AS total';
        $order = 'total';
    }
    $sql = $select . ' FROM ' . $qualified;
    if ($dateCol !== null && $from !== null && $to !== null) {
        $d = explorerIdent($dateCol);
        $sql .= ' WHERE ' . $d . ' >= ' . explorerLit($from)
              . ' AND ' . $d . ' < DATEADD(DAY, 1, ' . explorerLit($to) . ')';
    }
    return $sql . ' GROUP BY ' . $g . ' ORDER BY ' . $order . ' DESC';
}

/**
 * The metric hunter: total one column grouped by another, optionally
 * fenced to a date range — the generalized form of the probe that
 * identified DivNo 808 as the real go-kart money.
 */
function explorerAggregate(array $input): void {
    $tableIn  = Validator::requireString($input, 'table', 200);
    $groupIn  = Validator::requireString($input, 'group_by', 200);
    $sumIn    = isset($input['sum_col']) ? trim((string)$input['sum_col']) : '';
    $dateIn   = isset($input['date_col']) ? trim((string)$input['date_col']) : '';
    $from     = isset($input['from']) ? trim((string)$input['from']) : '';
    $to       = isset($input['to']) ? trim((string)$input['to']) : '';

    try {
        $client = new MssqlClient();
        list($schema, $table, $cols) = explorerResolveTable($client, $tableIn);
        $groupBy = explorerRequireColumn($cols, $groupIn, 'group-by');
        $groupType = '';
        foreach ($cols as $c) {
            if (strcasecmp($c['name'], $groupBy) === 0) { $groupType = $c['type']; break; }
        }
        $sumCol  = $sumIn !== '' ? explorerRequireColumn($cols, $sumIn, 'sum') : null;
        $dateCol = null;
        if ($dateIn !== '') {
            $dateCol = explorerRequireColumn($cols, $dateIn, 'date');
            foreach ([['from', $from], ['to', $to]] as [$label, $v]) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    throw new RuntimeException('A date column needs valid ' . $label . ' (YYYY-MM-DD).');
                }
            }
            if ($from > $to) {
                throw new RuntimeException('"From" must be on or before "To".');
            }
        }

        $sql = explorerAggregateSql($schema, $table, $groupBy, $sumCol, $dateCol,
            $dateCol !== null ? $from : null, $dateCol !== null ? $to : null, $groupType);
        explorerAudit('aggregate', $sql);

        $t0 = microtime(true);
        $rows = $client->rows($sql, 100);
        $out = [];
        foreach ($rows as $r) {
            $vals = array_values($r);
            $entry = [
                'grp'   => explorerCell($r['grp'] ?? $vals[0] ?? null),
                'lines' => (int)($r['lines'] ?? $vals[1] ?? 0),
            ];
            if ($sumCol !== null) {
                $entry['total'] = round((float)($r['total'] ?? $vals[2] ?? 0), 2);
            }
            $out[] = $entry;
        }
        explorerJson([
            'sql' => $sql,
            'rows' => $out,
            'has_total' => $sumCol !== null,
            // Exactly at the TOP cap ⇒ more groups exist than are shown.
            'truncated' => count($out) >= 100,
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ]);
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Exception $e) {
        explorerJson(['error' => $e->getMessage()]);
    }
}

/** Free-form guarded SELECT. */
function explorerQuery(array $input): void {
    $sql = Validator::requireString($input, 'sql', 8000);
    $limit = Validator::optionalInt($input, 'limit', 1, EXPLORER_MAX_ROWS) ?? 100;

    MssqlClient::assertReadOnly($sql);
    explorerAudit('query', $sql);

    try {
        $client = new MssqlClient();
        $t0 = microtime(true);
        $raw = $client->rows($sql, $limit);
        $columns = [];
        $rows = [];
        foreach ($raw as $r) {
            if (!$columns) $columns = array_map('strval', array_keys($r));
            $rows[] = array_map('explorerCell', array_values($r));
        }
        explorerJson([
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => count($rows) >= $limit,
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ]);
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Exception $e) {
        // SQL errors are the expected failure mode while exploring —
        // structured result, not a 500.
        explorerJson(['error' => $e->getMessage()]);
    }
}

/** Audit-log an explorer run (best effort; never blocks the query). */
// ---------------------------------------------------------------------------
// "How far back can per-GAME play history reach?" probe
//
// The Performance page attributes a play to a game through a READER KEY. That
// works for the current CenterEdge/Kiosoft readers, but the venue ran Embed
// before ~May 2026, and `PlayerCardTrans.rdrkey` is 0 from ~2013 onward — so
// the obvious source cannot attribute an Embed-era play to a machine.
//
// Whether the Embed era is recoverable comes down to two questions no amount of
// reading the schema from outside can answer:
//   1. Does SOME table carry a populated reader key on pre-cutover play rows?
//   2. Do those reader keys still resolve to the games the app knows about?
// This endpoint answers both against the live database and prints a verdict, so
// the decision is made on evidence instead of assumption. Every check is
// independently fault-tolerant: a probe that errors reports its own message and
// never takes the others down.
// ---------------------------------------------------------------------------

/** The Embed → CenterEdge/Kiosoft reader cutover, per docs (end of April 2026). */
const EXPLORER_CUTOVER_DATE = '2026-05-01';

/** Column names that look like a reader/device key. */
function explorerIsReaderKeyColumn(string $name): bool {
    $n = strtolower(str_replace(['_', ' '], '', $name));
    return in_array($n, ['rdrkey', 'readerkey', 'readerid', 'rdrid', 'deviceid', 'devicekey'], true);
}

/**
 * One candidate table's per-era reader-key coverage. Bounded to a single probe
 * month per era so a 20M-row table is never scanned end to end.
 *
 * @return array reader-key coverage before and after the cutover, or an error
 */
function explorerProbeReaderCoverage(MssqlClient $client, string $schema, string $table, string $keyCol, string $dateCol): array {
    $t = explorerIdent($schema) . '.' . explorerIdent($table);
    $k = explorerIdent($keyCol);
    $d = explorerIdent($dateCol);

    $era = function (string $from, string $toExcl) use ($client, $t, $k, $d) {
        $sql = "SELECT COUNT(*) AS total,"
             . " SUM(CASE WHEN $k IS NOT NULL AND $k <> 0 THEN 1 ELSE 0 END) AS with_key,"
             . " COUNT(DISTINCT CASE WHEN $k IS NOT NULL AND $k <> 0 THEN $k END) AS distinct_keys"
             . " FROM $t WHERE $d >= " . explorerLit($from) . " AND $d < " . explorerLit($toExcl);
        $rows = $client->rows($sql, 1);
        $r = $rows[0] ?? [];
        $v = array_values($r);
        $total = (int)($r['total'] ?? $v[0] ?? 0);
        $withKey = (int)($r['with_key'] ?? $v[1] ?? 0);
        return [
            'rows'          => $total,
            'with_key'      => $withKey,
            'distinct_keys' => (int)($r['distinct_keys'] ?? $v[2] ?? 0),
            'key_pct'       => $total > 0 ? round($withKey / $total * 100, 1) : null,
        ];
    };

    $out = ['legacy' => null, 'current' => null, 'error' => null];
    try {
        // A month safely inside each era. Legacy = the winter before the
        // cutover; current = the month after it.
        $out['legacy']  = $era('2026-02-01', '2026-03-01');
        $out['current'] = $era('2026-05-01', '2026-06-01');
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/**
 * Distinct reader keys used on pre-cutover play rows, and how many of them map
 * to a game the app knows about. A source is only useful if BOTH the keys exist
 * AND they resolve — an Embed-era key pointing at a reader that was ripped out
 * and deleted from ReaderDevices attributes to nothing.
 */
function explorerProbeReaderMapping(MssqlClient $client, string $schema, string $table, string $keyCol, string $dateCol): array {
    $t = explorerIdent($schema) . '.' . explorerIdent($table);
    $k = explorerIdent($keyCol);
    $d = explorerIdent($dateCol);
    $out = ['keys_seen' => 0, 'mapped' => 0, 'unmapped' => 0, 'samples' => [], 'error' => null];
    try {
        // Reader keys actually used in the legacy era (bounded probe month).
        $keys = [];
        foreach ($client->rows(
            "SELECT DISTINCT $k AS rk FROM $t"
            . " WHERE $d >= " . explorerLit('2026-02-01') . " AND $d < " . explorerLit('2026-03-01')
            . " AND $k IS NOT NULL AND $k <> 0", 2000) as $r) {
            $rk = (int)($r['rk'] ?? array_values($r)[0] ?? 0);
            if ($rk !== 0) $keys[$rk] = true;
        }
        $out['keys_seen'] = count($keys);
        if (!$keys) return $out;

        // rdrKey → description, then description → the app's game names, using
        // exactly the normalization the existing per-game backfill uses.
        $desc = [];
        try {
            foreach ($client->rows('SELECT rdrKey, Description FROM dbo.ReaderDevices', 100000) as $rd) {
                $rk = (int)($rd['rdrKey'] ?? 0);
                if ($rk !== 0) $desc[$rk] = (string)($rd['Description'] ?? '');
            }
        } catch (Exception $e) {
            $out['error'] = 'ReaderDevices not readable: ' . $e->getMessage();
        }

        $byName = [];
        foreach (DB::query('SELECT game_id, game_name FROM game_state_cache') as $g) {
            $n = explorerNormName((string)$g['game_name']);
            if ($n !== '' && !isset($byName[$n])) $byName[$n] = (string)$g['game_name'];
        }

        foreach (array_keys($keys) as $rk) {
            $description = $desc[$rk] ?? '';
            $n = explorerNormName($description);
            $match = ($n !== '' && isset($byName[$n])) ? $byName[$n] : null;
            if ($match !== null) { $out['mapped']++; } else { $out['unmapped']++; }
            if (count($out['samples']) < 15) {
                $out['samples'][] = [
                    'reader_key'  => $rk,
                    'description' => $description !== '' ? $description : null,
                    'game'        => $match,
                ];
            }
        }
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/** Same normalization the per-game backfill uses (lower, strip sort prefix, collapse spaces). */
function explorerNormName(string $s): string {
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim($s)) : strtolower(trim($s));
    $s = preg_replace('/^\d+\s*/', '', (string)$s);
    return trim((string)preg_replace('/\s+/', ' ', (string)$s));
}

function explorerHistorySources(): void {
    if (!MssqlClient::isConfigured()) {
        explorerJson(['configured' => false, 'candidates' => []]);
        return;
    }
    explorerAudit('history_sources', 'per-game history source probe');

    try {
        $client = new MssqlClient();
        $client->setTimeout(120);
    } catch (Exception $e) {
        explorerJson(['configured' => true, 'error' => $e->getMessage(), 'candidates' => []]);
        return;
    }

    // ---- Discover every table carrying BOTH a reader-key column and a date ----
    // Schema-driven rather than a hardcoded list, so an Embed-specific table
    // nobody has catalogued yet still turns up.
    $candidates = [];
    try {
        $byTable = [];
        foreach ($client->rows(
            "SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS", 20000) as $c) {
            $v = array_values($c);
            $sch  = (string)($c['TABLE_SCHEMA'] ?? $v[0] ?? 'dbo');
            $tbl  = (string)($c['TABLE_NAME'] ?? $v[1] ?? '');
            $col  = (string)($c['COLUMN_NAME'] ?? $v[2] ?? '');
            $type = strtolower((string)($c['DATA_TYPE'] ?? $v[3] ?? ''));
            if ($tbl === '' || $col === '') continue;
            $key = $sch . '.' . $tbl;
            if (!isset($byTable[$key])) $byTable[$key] = ['schema' => $sch, 'table' => $tbl, 'key_col' => null, 'date_col' => null];
            if ($byTable[$key]['key_col'] === null && explorerIsReaderKeyColumn($col)) {
                $byTable[$key]['key_col'] = $col;
            }
            if ($byTable[$key]['date_col'] === null && explorerIsDateType($type)) {
                $byTable[$key]['date_col'] = $col;
            }
        }
        foreach ($byTable as $t) {
            if ($t['key_col'] !== null && $t['date_col'] !== null) $candidates[] = $t;
        }
    } catch (Exception $e) {
        explorerJson(['configured' => true, 'error' => 'Schema scan failed: ' . $e->getMessage(), 'candidates' => []]);
        return;
    }

    // Approximate sizes, so the biggest (most likely transactional) tables sort
    // first and the UI can say how much history is on offer.
    $sizes = [];
    try {
        foreach ($client->rows(
            "SELECT t.name AS tbl, SUM(p.rows) AS cnt FROM sys.tables t"
            . " JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)"
            . " GROUP BY t.name", 2000) as $c) {
            $v = array_values($c);
            $sizes[strtolower((string)($c['tbl'] ?? $v[0] ?? ''))] = (int)($c['cnt'] ?? $v[1] ?? 0);
        }
    } catch (Exception $e) {
        // sys.* not readable — sizes stay null, ordering falls back to name.
    }
    foreach ($candidates as &$c) { $c['rows'] = $sizes[strtolower($c['table'])] ?? null; }
    unset($c);
    usort($candidates, function ($a, $b) { return ($b['rows'] ?? 0) <=> ($a['rows'] ?? 0); });

    // Probing is the expensive part — only the largest few are worth the trip.
    $probeLimit = 6;
    $results = [];
    foreach ($candidates as $i => $c) {
        $entry = $c + ['coverage' => null, 'mapping' => null, 'probed' => false];
        if ($i < $probeLimit) {
            $entry['probed'] = true;
            $entry['coverage'] = explorerProbeReaderCoverage($client, $c['schema'], $c['table'], $c['key_col'], $c['date_col']);
            $legacy = $entry['coverage']['legacy'] ?? null;
            // Only worth a mapping trip when the legacy era actually has keys.
            if ($legacy && ($legacy['with_key'] ?? 0) > 0) {
                $entry['mapping'] = explorerProbeReaderMapping($client, $c['schema'], $c['table'], $c['key_col'], $c['date_col']);
            }
        }
        $results[] = $entry;
    }

    // ---- Verdict: is the Embed era recoverable, and via what? ----
    $best = null;
    foreach ($results as $r) {
        $legacy = $r['coverage']['legacy'] ?? null;
        $map = $r['mapping'] ?? null;
        if (!$legacy || ($legacy['with_key'] ?? 0) <= 0 || !$map) continue;
        $score = (int)($map['mapped'] ?? 0);
        if ($score > 0 && ($best === null || $score > $best['mapped_readers'])) {
            $best = [
                'schema'         => $r['schema'],
                'table'          => $r['table'],
                'key_col'        => $r['key_col'],
                'date_col'       => $r['date_col'],
                'mapped_readers' => $score,
                'total_readers'  => (int)($map['keys_seen'] ?? 0),
            ];
        }
    }

    // How deep does the winning source actually go?
    if ($best !== null) {
        try {
            $t = explorerIdent($best['schema']) . '.' . explorerIdent($best['table']);
            $d = explorerIdent($best['date_col']);
            $rows = $client->rows("SELECT MIN($d) AS lo, MAX($d) AS hi FROM $t", 1);
            $r = $rows[0] ?? [];
            $v = array_values($r);
            $best['earliest'] = substr((string)($r['lo'] ?? $v[0] ?? ''), 0, 19) ?: null;
            $best['latest']   = substr((string)($r['hi'] ?? $v[1] ?? ''), 0, 19) ?: null;
        } catch (Exception $e) {
            $best['range_error'] = $e->getMessage();
        }
    }

    explorerJson([
        'configured'  => true,
        'driver'      => $client->driver(),
        'cutover'     => EXPLORER_CUTOVER_DATE,
        'legacy_probe'  => ['from' => '2026-02-01', 'to' => '2026-03-01'],
        'current_probe' => ['from' => '2026-05-01', 'to' => '2026-06-01'],
        'candidates'  => $results,
        'recommended' => $best,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

function explorerAudit(string $kind, string $sql): void {
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'explorer_' . $kind,
             (Auth::check()['username'] ?? '?') . ': ' . substr(preg_replace('/\s+/', ' ', $sql), 0, 300)]
        );
    } catch (Exception $e) {
        error_log('explorer audit log failed: ' . $e->getMessage());
    }
}
