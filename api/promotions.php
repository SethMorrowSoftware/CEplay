<?php
/**
 * Promotional Cards API — track BLOCKS of giveaway cards (a contiguous
 * card-number range, e.g. "K104 on-air giveaway, cards 100000–100499, $30 each")
 * and see how they actually performed: how many came back, how much extra money
 * was loaded, plays, tickets earned and value spent — straight from the venue's
 * CenterEdge MSSQL PlayerCardTrans ledger, by card number.
 *
 * CRUD (batch definitions live in SQLite; analytics computed live from MSSQL):
 *   GET    /api/promotions            — list batches (+ live per-batch stats)
 *   GET    /api/promotions/{id}       — one batch definition (for the editor)
 *   POST   /api/promotions            — create a batch (promotions_manage)
 *   PUT    /api/promotions/{id}       — update a batch (promotions_manage)
 *   DELETE /api/promotions/{id}       — delete a batch (promotions_manage)
 *   GET    /api/promotions/analyze?id={id} — full analysis + per-card table
 *   GET    /api/promotions/settings   — editable range query (settings)
 *   PUT    /api/promotions/settings   — save it (settings)
 *   POST   /api/promotions/test       — probe a card range + fingerprint (data_explorer)
 *
 * A "batch" is defined by a card-number range [card_from, card_to]; performance
 * is aggregated from PlayerCardTrans across that range since the giveaway date.
 * A card that was never used has NO ledger rows, so "cards used" = distinct
 * cards that appear, and activation rate = cards used ÷ cards in the range.
 * Money definitions match the other reports: TransType 1 = plays (DollarAmount
 * = value spent at readers), TransType 3 = value ADDED (reloads), ValueNo 3 =
 * tickets earned.
 *
 * The aggregate query is admin-editable SQL with required :since / :cardfrom /
 * :cardto placeholders, guarded to a single SELECT (assertReadOnly). The
 * :since floor (the giveaway date) lets the TransDateTime index bound the scan
 * so a card-number range over a 20-year ledger stays fast. Shares the MSSQL
 * connection with the Go-Kart Labor page (lib/mssql_client.php).
 */

require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/validator.php';

// Aggregate performance for one card-number range since :since. One row of
// totals. TransType 1 = plays (DollarAmount = value spent at readers),
// TransType 3 = value added (reloads), ValueNo 3 = tickets earned. The
// :since floor keeps the scan on the TransDateTime index; the CardNumber cast
// (TRY_CONVERT) tolerates the '000000'/blank sentinels and any non-numeric card.
const PROMO_DEFAULT_RANGE_SQL = "SELECT COUNT(DISTINCT CardNumber) AS cards_used,\n       SUM(CASE WHEN TransType = 1 THEN 1 ELSE 0 END) AS plays,\n       SUM(CASE WHEN TransType = 1 THEN DollarAmount ELSE 0 END) AS play_value,\n       SUM(CASE WHEN TransType = 3 THEN 1 ELSE 0 END) AS reloads,\n       SUM(CASE WHEN TransType = 3 THEN DollarAmount ELSE 0 END) AS reload_value,\n       COUNT(DISTINCT CASE WHEN TransType = 3 THEN CardNumber END) AS cards_reloaded,\n       SUM(CASE WHEN ValueNo = 3 THEN Amount ELSE 0 END) AS tickets,\n       CONVERT(VARCHAR(19), MIN(TransDateTime), 120) AS first_seen,\n       CONVERT(VARCHAR(19), MAX(TransDateTime), 120) AS last_seen\nFROM [CenterEdge].[dbo].[PlayerCardTrans]\nWHERE TransDateTime >= :since\n  AND TRY_CONVERT(BIGINT, CardNumber) BETWEEN :cardfrom AND :cardto";

// Per-card drill-down (fixed; the detail view only). Same predicate as the
// aggregate above. Capped at TOP 200 most-recently-active cards.
const PROMO_PERCARD_SQL = "SELECT TOP 200 CardNumber AS card,\n       CONVERT(VARCHAR(19), MIN(TransDateTime), 120) AS first_seen,\n       CONVERT(VARCHAR(19), MAX(TransDateTime), 120) AS last_seen,\n       SUM(CASE WHEN TransType = 1 THEN 1 ELSE 0 END) AS plays,\n       SUM(CASE WHEN TransType = 1 THEN DollarAmount ELSE 0 END) AS play_value,\n       SUM(CASE WHEN TransType = 3 THEN 1 ELSE 0 END) AS reloads,\n       SUM(CASE WHEN TransType = 3 THEN DollarAmount ELSE 0 END) AS reload_value,\n       SUM(CASE WHEN ValueNo = 3 THEN Amount ELSE 0 END) AS tickets\nFROM [CenterEdge].[dbo].[PlayerCardTrans]\nWHERE TransDateTime >= :since\n  AND TRY_CONVERT(BIGINT, CardNumber) BETWEEN :cardfrom AND :cardto\nGROUP BY CardNumber\nORDER BY MAX(TransDateTime) DESC";

const PROMO_SINCE_FLOOR = '1900-01-01'; // "all time" when a batch has no giveaway date

function handlePromotions(string $method, array $parts, ?array $input): void {
    // View gate for every method; mutations require promotions_manage below.
    Auth::requireAccess('analytics');

    $action = $parts[0] ?? '';

    // Sub-resources (word segments) take precedence over a numeric batch id.
    if ($action === 'settings') {
        if ($method === 'GET') { promoGetSettings(); return; }
        if ($method === 'PUT') { promoPutSettings($input ?? []); return; }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); return;
    }
    if ($action === 'test') {
        if ($method === 'POST') { promoTest($input); return; }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); return;
    }
    if ($action === 'analyze') {
        if ($method === 'GET') { promoAnalyze(); return; }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); return;
    }

    $batchId = is_numeric($action) ? (int)$action : null;

    switch ($method) {
        case 'GET':
            if ($batchId) { promoGet($batchId); } else { promoList(); }
            break;
        case 'POST':
            Auth::requireAccess('promotions_manage');
            promoCreate($input);
            break;
        case 'PUT':
            Auth::requireAccess('promotions_manage');
            if (!$batchId) { http_response_code(400); echo json_encode(['error' => 'Batch ID required']); return; }
            promoUpdate($batchId, $input);
            break;
        case 'DELETE':
            Auth::requireAccess('promotions_manage');
            if (!$batchId) { http_response_code(400); echo json_encode(['error' => 'Batch ID required']); return; }
            promoDelete($batchId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

/** The one editable aggregate query (config override, else the shipped default). */
function promoRangeSql(): string {
    return DB::getConfig('promo_range_sql') ?: PROMO_DEFAULT_RANGE_SQL;
}

// ------------------------------------------------------------------
// Batch normalization + validation
// ------------------------------------------------------------------

/** Normalize a DB batch row for the client, adding the computed range size. */
function promoBatchRow(array $r): array {
    $from = (string)$r['card_from'];
    $to   = (string)$r['card_to'];
    $defined = null;
    if (preg_match('/^\d{1,18}$/', $from) && preg_match('/^\d{1,18}$/', $to) && $to >= $from) {
        $defined = (int)$to - (int)$from + 1;
    }
    $initial = (float)$r['initial_value'];
    return [
        'id'            => (int)$r['id'],
        'name'          => (string)$r['name'],
        'card_from'     => $from,
        'card_to'       => $to,
        'giveaway_date' => (string)$r['giveaway_date'],
        'initial_value' => round($initial, 2),
        'notes'         => (string)($r['notes'] ?? ''),
        'created_at'    => (string)$r['created_at'],
        'updated_at'    => (string)$r['updated_at'],
        'cards_defined' => $defined,
        'total_initial' => $defined !== null ? round($initial * $defined, 2) : null,
    ];
}

/**
 * Validate + normalize a create/update payload into
 * [name, card_from, card_to, giveaway_date, initial_value, notes].
 * Throws RuntimeException (→ 422) on any bad field.
 */
function promoValidateInput(?array $input): array {
    if (!$input) { throw new RuntimeException('Request body required.'); }
    $name  = Validator::requireString($input, 'name', 100);
    $notes = Validator::optionalString($input, 'notes', 1000);

    $from = trim((string)($input['card_from'] ?? ''));
    $to   = trim((string)($input['card_to'] ?? ''));
    if (!preg_match('/^\d{1,18}$/', $from) || !preg_match('/^\d{1,18}$/', $to)) {
        throw new RuntimeException('Card numbers must be whole numbers (up to 18 digits).');
    }
    // Compare numerically (not string) so 100000..99999 is caught.
    if ((int)$to < (int)$from) {
        throw new RuntimeException('"Card to" must be greater than or equal to "Card from".');
    }
    if ((int)$to - (int)$from + 1 > 5000000) {
        throw new RuntimeException('That card range is too large (max 5,000,000 cards).');
    }

    $giveaway = trim((string)($input['giveaway_date'] ?? ''));
    if ($giveaway !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $giveaway)) {
        throw new RuntimeException('Giveaway date must be YYYY-MM-DD (or left blank).');
    }

    $initial = 0.0;
    if (isset($input['initial_value']) && $input['initial_value'] !== '' && $input['initial_value'] !== null) {
        if (!is_numeric($input['initial_value'])) {
            throw new RuntimeException('Starting value must be a dollar amount.');
        }
        $initial = (float)$input['initial_value'];
        if ($initial < 0) { throw new RuntimeException('Starting value cannot be negative.'); }
    }

    return [$name, $from, $to, $giveaway, $initial, $notes];
}

// ------------------------------------------------------------------
// CRUD
// ------------------------------------------------------------------

function promoList(): void {
    $hideMoney = !Auth::hasPermission('view_revenue');
    $rows = DB::query('SELECT * FROM promo_batches ORDER BY created_at DESC, id DESC');
    $batches = array_map('promoBatchRow', $rows);

    $configured = MssqlClient::isConfigured();
    // Best-effort live stats per batch, one reused connection. On any per-batch
    // error (timeout, bad range) that batch simply reports null stats.
    if ($configured && $batches) {
        try {
            $client = new MssqlClient();
            $client->connect();
            foreach ($batches as &$b) {
                try {
                    $b['stats'] = promoRunAggregate($client, $b);
                } catch (Exception $e) {
                    $b['stats'] = null;
                }
            }
            unset($b);
        } catch (Exception $e) {
            // Connection failed — leave all stats absent.
        }
    }

    $payload = [
        'batches'          => $batches,
        'total'            => count($batches),
        'mssql_configured' => $configured,
        'hide_money'       => $hideMoney,
        'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if ($hideMoney) { promoScrubMoney($payload); }
    echo json_encode($payload);
}

function promoGet(int $batchId): void {
    $row = DB::queryOne('SELECT * FROM promo_batches WHERE id = :p0', [$batchId]);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Batch not found']); return; }
    $batch = promoBatchRow($row);
    if (!Auth::hasPermission('view_revenue')) {
        $wrap = ['batch' => $batch];
        promoScrubMoney($wrap);
        $batch = $wrap['batch'];
    }
    echo json_encode($batch);
}

function promoCreate(?array $input): void {
    list($name, $from, $to, $giveaway, $initial, $notes) = promoValidateInput($input);
    DB::execute(
        'INSERT INTO promo_batches (name, card_from, card_to, giveaway_date, initial_value, notes)
         VALUES (:p0, :p1, :p2, :p3, :p4, :p5)',
        [$name, $from, $to, $giveaway, $initial, $notes]
    );
    $batchId = DB::lastInsertId();
    DB::auditLog('admin', 'promo_batch_created', Auth::userId(), [
        'batch_id' => $batchId, 'name' => $name, 'card_from' => $from, 'card_to' => $to,
    ]);
    http_response_code(201);
    promoGet($batchId);
}

function promoUpdate(int $batchId, ?array $input): void {
    $existing = DB::queryOne('SELECT id FROM promo_batches WHERE id = :p0', [$batchId]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Batch not found']); return; }
    list($name, $from, $to, $giveaway, $initial, $notes) = promoValidateInput($input);
    DB::execute(
        'UPDATE promo_batches SET name = :p0, card_from = :p1, card_to = :p2, giveaway_date = :p3,
             initial_value = :p4, notes = :p5, updated_at = datetime(\'now\') WHERE id = :p6',
        [$name, $from, $to, $giveaway, $initial, $notes, $batchId]
    );
    DB::auditLog('admin', 'promo_batch_updated', Auth::userId(), [
        'batch_id' => $batchId, 'name' => $name, 'card_from' => $from, 'card_to' => $to,
    ]);
    promoGet($batchId);
}

function promoDelete(int $batchId): void {
    $existing = DB::queryOne('SELECT name FROM promo_batches WHERE id = :p0', [$batchId]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Batch not found']); return; }
    DB::execute('DELETE FROM promo_batches WHERE id = :p0', [$batchId]);
    DB::auditLog('admin', 'promo_batch_deleted', Auth::userId(), [
        'batch_id' => $batchId, 'name' => (string)$existing['name'],
    ]);
    echo json_encode(['success' => true, 'deleted_id' => $batchId]);
}

// ------------------------------------------------------------------
// Live analysis (MSSQL)
// ------------------------------------------------------------------

/** The giveaway date bounds the scan; blank → all time. */
function promoSinceFor(array $batch): string {
    $g = (string)($batch['giveaway_date'] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $g) ? $g : PROMO_SINCE_FLOOR;
}

/** Bind :since / :cardfrom / :cardto into a query (validated, injection-safe). */
function promoBindSql(string $sql, string $since, string $from, string $to): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
        throw new RuntimeException('Invalid since date: ' . $since);
    }
    if (strpos($sql, ':since') === false) {
        throw new RuntimeException('Query must contain the :since placeholder.');
    }
    $sql = str_replace(':since', "'" . $since . "'", $sql);
    return MssqlClient::bindCardRange($sql, $from, $to);
}

/** Read a scalar from an aggregate row by name (case-insensitive), else position. */
function promoCell(array $row, array $vals, string $name, int $pos) {
    foreach ($row as $k => $v) {
        if (strcasecmp((string)$k, $name) === 0) return $v;
    }
    return $vals[$pos] ?? null;
}

/** Run the aggregate query for a batch and shape the summary stats. */
function promoRunAggregate(MssqlClient $client, array $batch): array {
    $since = promoSinceFor($batch);
    $sql = promoBindSql(promoRangeSql(), $since, (string)$batch['card_from'], (string)$batch['card_to']);
    $rows = $client->rows($sql, 1);
    $row = $rows[0] ?? [];
    return promoStatsFromAggRow($row, $batch);
}

/** Shape one aggregate row + the batch definition into the summary stats block. */
function promoStatsFromAggRow(array $row, array $batch): array {
    $vals = array_values($row);
    $cardsUsed     = (int)promoCell($row, $vals, 'cards_used', 0);
    $plays         = (int)promoCell($row, $vals, 'plays', 1);
    $playValue     = (float)promoCell($row, $vals, 'play_value', 2);
    $reloads       = (int)promoCell($row, $vals, 'reloads', 3);
    $reloadValue   = (float)promoCell($row, $vals, 'reload_value', 4);
    $cardsReloaded = (int)promoCell($row, $vals, 'cards_reloaded', 5);
    $tickets       = (float)promoCell($row, $vals, 'tickets', 6);
    $firstSeen     = trim((string)promoCell($row, $vals, 'first_seen', 7));
    $lastSeen      = trim((string)promoCell($row, $vals, 'last_seen', 8));

    $defined = $batch['cards_defined'] ?? null;
    $activation = ($defined && $defined > 0) ? round($cardsUsed / $defined, 4) : null;

    return [
        'cards_defined'        => $defined,
        'cards_used'           => $cardsUsed,
        'activation_rate'      => $activation,
        'plays'                => $plays,
        'play_value'           => round($playValue, 2),
        'reloads'              => $reloads,
        'reload_value'         => round($reloadValue, 2),
        'cards_reloaded'       => $cardsReloaded,
        'reload_rate'          => $cardsUsed > 0 ? round($cardsReloaded / $cardsUsed, 4) : null,
        'avg_additional'       => $cardsReloaded > 0 ? round($reloadValue / $cardsReloaded, 2) : null,
        'avg_additional_all'   => $cardsUsed > 0 ? round($reloadValue / $cardsUsed, 2) : null,
        'tickets'              => round($tickets, 2),
        'first_seen'           => $firstSeen !== '' ? $firstSeen : null,
        'last_seen'            => $lastSeen !== '' ? $lastSeen : null,
    ];
}

/** Shape per-card rows for the drill-down table. */
function promoCardsFromRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $out[] = [
            'card'        => trim((string)promoCell($row, $vals, 'card', 0)),
            'first_seen'  => trim((string)promoCell($row, $vals, 'first_seen', 1)) ?: null,
            'last_seen'   => trim((string)promoCell($row, $vals, 'last_seen', 2)) ?: null,
            'plays'       => (int)promoCell($row, $vals, 'plays', 3),
            'play_value'  => round((float)promoCell($row, $vals, 'play_value', 4), 2),
            'reloads'     => (int)promoCell($row, $vals, 'reloads', 5),
            'reload_value'=> round((float)promoCell($row, $vals, 'reload_value', 6), 2),
            'tickets'     => round((float)promoCell($row, $vals, 'tickets', 7), 2),
        ];
    }
    return $out;
}

function promoAnalyze(): void {
    $batchId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($batchId < 1) { throw new RuntimeException('id is required.'); }
    $row = DB::queryOne('SELECT * FROM promo_batches WHERE id = :p0', [$batchId]);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Batch not found']); return; }

    $hideMoney = !Auth::hasPermission('view_revenue');
    $batch = promoBatchRow($row);

    $payload = [
        'batch'            => $batch,
        'mssql_configured' => MssqlClient::isConfigured(),
        'since'            => promoSinceFor($batch),
        'summary'          => null,
        'cards'            => [],
        'cards_capped'     => false,
        'hide_money'       => $hideMoney,
        'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (!MssqlClient::isConfigured()) {
        if ($hideMoney) { promoScrubMoney($payload); }
        echo json_encode($payload);
        return;
    }

    try {
        $client = new MssqlClient();
        $client->connect();
        $payload['summary'] = promoRunAggregate($client, $batch);
        $percard = promoBindSql(PROMO_PERCARD_SQL, promoSinceFor($batch), (string)$batch['card_from'], (string)$batch['card_to']);
        $cardRows = $client->rows($percard, 200);
        $payload['cards'] = promoCardsFromRows($cardRows);
        $payload['cards_capped'] = count($cardRows) >= 200;
    } catch (Exception $e) {
        $payload['error'] = $e->getMessage();
    }

    if ($hideMoney) { promoScrubMoney($payload); }
    echo json_encode($payload);
}

/**
 * Zero every dollar figure so a role without view_revenue never sees money
 * (activation, plays, tickets and rates stay — they aren't monetary).
 */
function promoScrubMoney(array &$payload): void {
    $zeroStats = function (&$s) {
        if (!is_array($s)) return;
        foreach (['play_value', 'reload_value', 'avg_additional', 'avg_additional_all'] as $k) {
            if (array_key_exists($k, $s)) $s[$k] = 0.0;
        }
    };
    $zeroBatch = function (&$b) {
        if (!is_array($b)) return;
        if (array_key_exists('initial_value', $b)) $b['initial_value'] = 0.0;
        if (array_key_exists('total_initial', $b)) $b['total_initial'] = ($b['total_initial'] === null ? null : 0.0);
        if (isset($b['stats'])) { $s = $b['stats']; if (is_array($s)) { foreach (['play_value','reload_value','avg_additional','avg_additional_all'] as $k) { if (array_key_exists($k, $s)) $s[$k] = 0.0; } $b['stats'] = $s; } }
    };
    if (isset($payload['batches']) && is_array($payload['batches'])) {
        foreach ($payload['batches'] as &$b) { $zeroBatch($b); } unset($b);
    }
    if (isset($payload['batch'])) { $zeroBatch($payload['batch']); }
    if (isset($payload['summary'])) { $zeroStats($payload['summary']); }
    if (isset($payload['cards']) && is_array($payload['cards'])) {
        foreach ($payload['cards'] as &$c) {
            if (is_array($c)) {
                foreach (['play_value', 'reload_value'] as $k) { if (array_key_exists($k, $c)) $c[$k] = 0.0; }
            }
        }
        unset($c);
    }
}

// ------------------------------------------------------------------
// Settings + Test
// ------------------------------------------------------------------

function promoGetSettings(): void {
    Auth::requireAccess('settings');
    echo json_encode([
        'configured' => MssqlClient::isConfigured(),
        'drivers'    => MssqlClient::availableDrivers(),
        'range_sql'  => promoRangeSql(),
        'defaults'   => ['range_sql' => PROMO_DEFAULT_RANGE_SQL],
        'connection' => [
            'host'     => (MssqlClient::settings()['host'] ?? '') !== '',
            'database' => MssqlClient::settings()['database'] ?? '',
        ],
    ]);
}

function promoPutSettings(array $input): void {
    Auth::requireAccess('settings');
    $rangeSql = Validator::requireString($input, 'range_sql', 4000);
    MssqlClient::assertReadOnly($rangeSql);
    // Fail loudly at save time if a placeholder is missing / not a single SELECT.
    promoBindSql($rangeSql, '2000-01-01', '1', '2');

    DB::setConfig('promo_range_sql', $rangeSql);
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'promo_settings_update',
             'Promotional-cards query updated by ' . (Auth::check()['username'] ?? '?')]
        );
    } catch (Exception $e) {
        error_log('promo settings audit log failed: ' . $e->getMessage());
    }
    echo json_encode(['success' => true, 'configured' => MssqlClient::isConfigured()]);
}

function promoTest(?array $input = null): void {
    // Runs the live query over a probe card range + a fingerprint — gate on
    // data_explorer (raw POS data + dollar figures), not settings.
    Auth::requireAccess('data_explorer');

    $from  = isset($input['card_from']) ? trim((string)$input['card_from']) : '';
    $to    = isset($input['card_to'])   ? trim((string)$input['card_to'])   : '';
    if (!preg_match('/^\d{1,18}$/', $from) || !preg_match('/^\d{1,18}$/', $to) || (int)$to < (int)$from) {
        echo json_encode(['success' => false, 'error' => 'Enter a valid card range (whole numbers, "to" ≥ "from").']);
        return;
    }
    $since = PROMO_SINCE_FLOOR;
    if (isset($input['since']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['since'])) {
        $since = (string)$input['since'];
    }
    $probeBatch = ['card_from' => $from, 'card_to' => $to, 'giveaway_date' => $since,
                   'cards_defined' => ((int)$to - (int)$from + 1)];

    try {
        $client = new MssqlClient();
        $client->connect();
        $stats = promoStatsFromAggRow(($client->rows(
            promoBindSql(promoRangeSql(), $since, $from, $to), 1)[0] ?? []), $probeBatch);

        // A sample of the actual card numbers matched, so "did my range hit real
        // cards?" is answerable, plus server/db/freshness.
        $diag = [];
        $probe = function (string $label, string $sql) use (&$diag, $client) {
            try { $diag[$label] = $client->scalarText($sql); }
            catch (Exception $e) { $diag[$label] = 'error: ' . $e->getMessage(); }
        };
        $probe('server',   'SELECT @@SERVERNAME');
        $probe('database', 'SELECT DB_NAME()');
        $probe('playercardtrans_latest', 'SELECT CONVERT(VARCHAR(19), MAX(TransDateTime), 120) FROM [CenterEdge].[dbo].[PlayerCardTrans]');
        try {
            $sampleSql = promoBindSql(
                "SELECT DISTINCT TOP 15 CardNumber AS card FROM [CenterEdge].[dbo].[PlayerCardTrans]"
                . " WHERE TransDateTime >= :since AND TRY_CONVERT(BIGINT, CardNumber) BETWEEN :cardfrom AND :cardto"
                . " ORDER BY CardNumber", $since, $from, $to);
            $sample = $client->rows($sampleSql, 15);
            $diag['sample_cards'] = $sample ? array_map(function ($r) { return trim((string)($r['card'] ?? reset($r))); }, $sample)
                                            : 'no cards in that range since ' . $since;
        } catch (Exception $e) {
            $diag['sample_cards'] = 'error: ' . $e->getMessage();
        }

        echo json_encode([
            'success'     => true,
            'driver'      => $client->driver(),
            'since'       => $since,
            'card_from'   => $from,
            'card_to'     => $to,
            'stats'       => $stats,
            'diagnostics' => $diag,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
