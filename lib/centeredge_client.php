<?php
/**
 * CenterEdge Card System API client.
 * Handles authentication (SHA-1 hash), token caching, and all game management endpoints.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

class CenterEdgeClient {
    private const MAX_PAGINATION_LOOPS = 1000;
    private ?string $baseUrl = null;
    private ?string $username = null;
    private ?string $password = null;
    private ?string $apiKey = null;
    private ?string $bearerToken = null;
    private ?string $tokenFetchedAt = null;

    public function __construct() {
        $this->loadConfig();
    }

    /**
     * Load API configuration from the database.
     */
    private function loadConfig(): void {
        $this->baseUrl = DB::getConfig('base_url');
        $this->username = DB::getConfig('username');
        $this->password = DB::getConfig('password');
        $this->apiKey = DB::getConfig('api_key');
        $this->bearerToken = DB::getConfig('bearer_token');
        $this->tokenFetchedAt = DB::getConfig('token_fetched_at');
    }

    /**
     * Check if the client is configured with API credentials.
     */
    public function isConfigured(): bool {
        return !empty($this->baseUrl) && !empty($this->username) && !empty($this->password);
    }

    // -----------------------------------------------
    // Authentication
    // -----------------------------------------------

    /**
     * Authenticate with CenterEdge using SHA-1 hash flow.
     * Returns the bearer token.
     */
    public function authenticate(): string {
        if (!$this->isConfigured()) {
            throw new RuntimeException('CenterEdge API is not configured. Please set up API credentials in Settings.');
        }

        // Generate timestamp in ISO 8601 UTC with milliseconds
        $dt = new DateTime('now', new DateTimeZone('UTC'));
        $requestTimestamp = $dt->format('Y-m-d\TH:i:s.v\Z');

        // Build the hash: SHA-1(username + password + requestTimestamp), then base64
        $concat = $this->username . $this->password . $requestTimestamp;
        $rawHash = sha1($concat, true);
        $passwordHash = base64_encode($rawHash);

        $body = [
            'username'         => $this->username,
            'passwordHash'     => $passwordHash,
            'password'         => $this->password,
            'requestTimestamp'  => $requestTimestamp,
        ];

        $result = $this->httpRequest('POST', '/login', $body, false);

        if (!isset($result['bearerToken'])) {
            throw new RuntimeException('CenterEdge login failed: no bearer token in response.');
        }

        $this->bearerToken = $result['bearerToken'];
        $this->tokenFetchedAt = gmdate('Y-m-d H:i:s');

        // Cache the token encrypted in the database
        DB::setConfig('bearer_token', $this->bearerToken, true);
        DB::setConfig('token_fetched_at', $this->tokenFetchedAt, false);

        return $this->bearerToken;
    }

    /**
     * Get a valid bearer token (reuses cached if fresh enough).
     */
    private function getToken(): string {
        if ($this->bearerToken && !$this->tokenNeedsRefresh()) {
            return $this->bearerToken;
        }
        return $this->authenticate();
    }

    /**
     * Check if the cached token needs refresh (older than TOKEN_MAX_AGE).
     */
    private function tokenNeedsRefresh(): bool {
        if (!$this->tokenFetchedAt) {
            return true;
        }
        $fetchedAt = strtotime($this->tokenFetchedAt . ' UTC');
        return (time() - $fetchedAt) > TOKEN_MAX_AGE;
    }

    // -----------------------------------------------
    // Game Endpoints
    // -----------------------------------------------

    /**
     * Fetch ALL games from CenterEdge (handles pagination).
     * Returns array of game objects.
     */
    public function getGames(): array {
        $allGames = [];
        $skip = 0;
        $take = GAMES_PAGE_SIZE;
        $pagesFetched = 0;

        do {
            $pagesFetched++;
            if ($pagesFetched > self::MAX_PAGINATION_LOOPS) {
                throw new RuntimeException('CenterEdge API pagination exceeded safety limit while fetching games. Check API pagination behavior.');
            }

            $result = $this->request('GET', '/games', null, ['skip' => $skip, 'take' => $take]);
            $games = $result['games'] ?? [];
            $allGames = array_merge($allGames, $games);
            $totalCount = $result['totalCount'] ?? null;
            $skip += count($games);

            // Stop if we got fewer than requested or reached totalCount
            if (count($games) < $take) {
                break;
            }
            if ($totalCount !== null && $skip >= $totalCount) {
                break;
            }
        } while (true);

        return $allGames;
    }

    /**
     * Fetch ALL game categories from CenterEdge.
     * Returns array of category objects.
     */
    public function getCategories(): array {
        $allCategories = [];
        $skip = 0;
        $take = GAMES_PAGE_SIZE;
        $pagesFetched = 0;

        do {
            $pagesFetched++;
            if ($pagesFetched > self::MAX_PAGINATION_LOOPS) {
                throw new RuntimeException('CenterEdge API pagination exceeded safety limit while fetching categories. Check API pagination behavior.');
            }

            $result = $this->request('GET', '/games/categories', null, ['skip' => $skip, 'take' => $take]);
            $categories = $result['categories'] ?? [];
            $allCategories = array_merge($allCategories, $categories);
            $totalCount = $result['totalCount'] ?? null;
            $skip += count($categories);

            if (count($categories) < $take) {
                break;
            }
            if ($totalCount !== null && $skip >= $totalCount) {
                break;
            }
        } while (true);

        return $allCategories;
    }

    /**
     * Patch game operation statuses using JSON Patch format.
     * $changes = ['gameId' => 'paused', 'gameId2' => 'enabled', ...]
     * Returns ['games' => [...], 'errors' => [...]]
     */
    public function patchGames(array $changes): array {
        if (empty($changes)) {
            return ['games' => [], 'errors' => []];
        }

        $gamesPayload = [];
        foreach ($changes as $gameId => $status) {
            if (!in_array($status, ['enabled', 'paused', 'outOfService'], true)) {
                throw new RuntimeException("Invalid operationStatus '$status' for game '$gameId'.");
            }
            $gamesPayload[(string)$gameId] = [
                ['op' => 'replace', 'path' => '/operationStatus', 'value' => $status]
            ];
        }

        $result = $this->request('PATCH', '/games', ['games' => $gamesPayload]);

        return [
            'games' => is_array($result['games'] ?? null) ? $result['games'] : [],
            'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
        ];
    }

    /**
     * Get API capabilities.
     */
    public function getCapabilities(): array {
        return $this->request('GET', '/capabilities');
    }

    // -----------------------------------------------
    // Lightweight DB cache for endpoints whose payload
    // changes rarely (categories, capabilities). The UI
    // hits these on every group-modal open / kiosk page
    // load; without caching we'd hammer CenterEdge for
    // data that's effectively static for hours.
    // -----------------------------------------------

    /**
     * Default TTL for the categories/capabilities DB cache: 6 hours.
     * Operators can force a refresh via the existing /api/games/sync
     * and /api/kiosks/sync endpoints (we invalidate from there).
     */
    public const STATIC_CACHE_TTL = 21600;

    /**
     * Cached wrapper for getCategories(). Returns the cached payload
     * if it's fresher than $maxAgeSeconds; otherwise refetches and
     * stores the new payload in api_config.
     */
    public function getCategoriesCached(int $maxAgeSeconds = self::STATIC_CACHE_TTL): array {
        $cached = self::readJsonCache('cache_categories', $maxAgeSeconds);
        if ($cached !== null) {
            return $cached;
        }
        $fresh = $this->getCategories();
        self::writeJsonCache('cache_categories', $fresh);
        return $fresh;
    }

    /**
     * Cached wrapper for getCapabilities(). Same TTL semantics as
     * getCategoriesCached().
     */
    public function getCapabilitiesCached(int $maxAgeSeconds = self::STATIC_CACHE_TTL): array {
        $cached = self::readJsonCache('cache_capabilities', $maxAgeSeconds);
        if ($cached !== null) {
            return $cached;
        }
        $fresh = $this->getCapabilities();
        self::writeJsonCache('cache_capabilities', $fresh);
        return $fresh;
    }

    /**
     * Drop the cached categories payload. Called from POST /api/games/sync
     * so an operator who just clicked "Sync" sees the latest data.
     */
    public static function invalidateCategoriesCache(): void {
        DB::setConfig('cache_categories', null, false);
        DB::setConfig('cache_categories_at', null, false);
    }

    /**
     * Drop the cached capabilities payload. Called from POST /api/kiosks/sync
     * (kiosks page exposes a sync button that operators reach for when the
     * upstream config changed).
     */
    public static function invalidateCapabilitiesCache(): void {
        DB::setConfig('cache_capabilities', null, false);
        DB::setConfig('cache_capabilities_at', null, false);
    }

    /**
     * Read a JSON-encoded cache value from api_config and return it if it
     * isn't older than $maxAgeSeconds. Returns null on miss / stale / decode error.
     */
    private static function readJsonCache(string $key, int $maxAgeSeconds): ?array {
        $raw = DB::getConfig($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        $stamp = DB::getConfig($key . '_at');
        if (!$stamp) {
            return null;
        }
        $ts = strtotime($stamp . ' UTC');
        if ($ts === false || (time() - $ts) > $maxAgeSeconds) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Write a JSON cache value plus a UTC timestamp companion key.
     */
    private static function writeJsonCache(string $key, array $value): void {
        DB::setConfig($key, json_encode($value), false);
        DB::setConfig($key . '_at', gmdate('Y-m-d H:i:s'), false);
    }

    // -----------------------------------------------
    // Kiosk Endpoints
    // -----------------------------------------------

    /**
     * Get a paginated list of kiosks.
     */
    public function getKiosks(int $skip = 0, int $take = 100): array {
        return $this->request('GET', '/kiosks', null, ['skip' => $skip, 'take' => $take]);
    }

    /**
     * Fetch ALL kiosks from CenterEdge (handles pagination).
     */
    public function getAllKiosks(): array {
        return $this->fetchAllPaginated('/kiosks', 'kiosks');
    }

    /**
     * Get a single kiosk by ID.
     */
    public function getKiosk(string $kioskId): array {
        return $this->request('GET', '/kiosks/' . urlencode($kioskId));
    }

    /**
     * Patch kiosk operation statuses using JSON Patch format.
     * $changes = ['kioskId' => 'paused', 'kioskId2' => 'enabled', ...]
     * Returns ['kiosks' => [...], 'errors' => [...]]
     *
     * Per the API spec, only call this when the capabilities endpoint reports
     * `kiosks.operationStatus` is true. The server may reject otherwise.
     */
    public function patchKiosks(array $changes): array {
        if (empty($changes)) {
            return ['kiosks' => [], 'errors' => []];
        }

        $kiosksPayload = [];
        foreach ($changes as $kioskId => $status) {
            if (!in_array($status, ['enabled', 'paused', 'outOfService'], true)) {
                throw new RuntimeException("Invalid operationStatus '$status' for kiosk '$kioskId'.");
            }
            $kiosksPayload[(string)$kioskId] = [
                ['op' => 'replace', 'path' => '/operationStatus', 'value' => $status]
            ];
        }

        $result = $this->request('PATCH', '/kiosks', ['kiosks' => $kiosksPayload]);

        return [
            'kiosks' => is_array($result['kiosks'] ?? null) ? $result['kiosks'] : [],
            'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
        ];
    }

    /**
     * Perform an action on a kiosk (RPC-style, e.g. "reboot").
     */
    public function performKioskAction(string $kioskId, string $actionId, array $operator): array {
        return $this->request('POST', '/kiosks/' . urlencode($kioskId) . '/performAction', [
            'actionId' => $actionId,
            'operator' => $operator,
        ]);
    }

    // -----------------------------------------------
    // Single Game / Game RPC Actions
    // -----------------------------------------------

    /**
     * Fetch a single game by ID. Returns the game object as the API delivers it
     * (id, name, operationStatus, categories, supportedActions, etc.).
     */
    public function getGame(string $gameId): array {
        return $this->request('GET', '/games/' . urlencode($gameId));
    }

    /**
     * Perform an RPC-style action on a game (e.g. "reboot").
     * Returns the updated Game object per the spec.
     */
    public function performGameAction(string $gameId, string $actionId, array $operator): array {
        return $this->request('POST', '/games/' . urlencode($gameId) . '/performAction', [
            'actionId' => $actionId,
            'operator' => $operator,
        ]);
    }

    // -----------------------------------------------
    // Game Transactions Stream (live play feed)
    // -----------------------------------------------

    /**
     * Fetch a page of game-play transactions since (exclusive) the given ID.
     * Returns the raw API payload: ['transactions' => [...], 'sinceId' => <int>].
     *
     * The API guarantees ascending order by ID and that IDs are unique within
     * a feed, so callers can checkpoint the highest ID they've processed.
     */
    public function getGameTransactions(int $sinceId, ?string $feedName = null, int $take = 200): array {
        $query = ['sinceId' => $sinceId, 'take' => $take];
        if ($feedName !== null && $feedName !== '') {
            $query['feedName'] = $feedName;
        }
        return $this->request('GET', '/games/transactions', null, $query);
    }

    /**
     * Poll game-play transactions and persist them into the local cache.
     * Walks the feed forward from the last processed ID until either the API
     * returns an empty page or the safety cap is hit (avoids unbounded loops
     * if the upstream feed is huge).
     *
     * Returns ['fetched' => <int>, 'last_id' => <int>].
     */
    public function pollGameTransactions(string $feedName = 'default', int $maxPages = 20, int $pageSize = 200): array {
        $stateKey = 'game_tx_last_id_' . $feedName;
        $backlogKey = 'game_tx_backlog_' . $feedName;
        $sinceId = (int)(DB::getConfig($stateKey) ?? '0');
        $totalFetched = 0;
        $lastId = $sinceId;
        $caughtUp = false;

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->getGameTransactions($sinceId, $feedName, $pageSize);
            $txs = $result['transactions'] ?? [];
            if (empty($txs)) {
                $caughtUp = true;
                break;
            }

            foreach ($txs as $tx) {
                $txId = isset($tx['id']) ? (int)$tx['id'] : 0;
                if ($txId <= 0) continue;

                $cardNumber = isset($tx['cardNumber']) ? (string)$tx['cardNumber'] : '';
                $type = isset($tx['type']) ? (string)$tx['type'] : '';
                $gameId = isset($tx['gameId']) ? (string)$tx['gameId'] : '';
                $gameDescription = isset($tx['gameDescription']) ? (string)$tx['gameDescription'] : '';
                $transactionTime = isset($tx['transactionTime']) ? (string)$tx['transactionTime'] : '';
                $usedTimePlay = !empty($tx['usedTimePlay']) ? 1 : 0;
                $usedPlayPrivilege = !empty($tx['usedPlayPrivilege']) ? 1 : 0;

                $amount = $tx['amount'] ?? [];
                $regular = isset($amount['regularPoints']) ? (float)$amount['regularPoints'] : 0;
                $bonus = isset($amount['bonusPoints']) ? (float)$amount['bonusPoints'] : 0;
                $tickets = isset($amount['redemptionTickets']) ? (float)$amount['redemptionTickets'] : 0;
                $cash = isset($amount['cash']) ? (float)$amount['cash'] : 0;

                DB::execute(
                    'INSERT OR IGNORE INTO game_play_transactions
                        (transaction_id, feed_name, card_number, type, game_id, game_description,
                         transaction_time, regular_points, bonus_points, redemption_tickets, cash_amount,
                         used_time_play, used_play_privilege, raw_payload, fetched_at)
                     VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8, :p9, :p10, :p11, :p12, :p13, datetime(\'now\'))',
                    [
                        $txId, $feedName, $cardNumber, $type, $gameId, $gameDescription,
                        $transactionTime, $regular, $bonus, $tickets, $cash,
                        $usedTimePlay, $usedPlayPrivilege, json_encode($tx)
                    ]
                );

                if ($txId > $lastId) $lastId = $txId;
                $totalFetched++;
            }

            // Persist checkpoint after every page so an interrupted poll
            // doesn't replay work next cycle.
            if ($lastId > $sinceId) {
                DB::setConfig($stateKey, (string)$lastId, false);
                $sinceId = $lastId;
            }

            // If we got fewer than requested, the feed is caught up.
            if (count($txs) < $pageSize) {
                $caughtUp = true;
                break;
            }
        }

        // Backlog flag: if we exhausted the page cap while pages were still
        // full, the feed is producing faster than one poll cycle can drain —
        // e.g. after an outage. Record when the backlog was first noticed so
        // /api/health can warn operators; clear the flag once caught up.
        if (!$caughtUp) {
            if (DB::getConfig($backlogKey) === null) {
                DB::setConfig($backlogKey, gmdate('Y-m-d\TH:i:s\Z'), false);
            }
            error_log("pollGameTransactions($feedName): page cap hit with feed still full — backlog present ($totalFetched fetched this cycle)");
        } elseif (DB::getConfig($backlogKey) !== null) {
            DB::setConfig($backlogKey, null, false);
        }

        return ['fetched' => $totalFetched, 'last_id' => $lastId, 'caught_up' => $caughtUp];
    }

    // -----------------------------------------------
    // Card Endpoints
    // -----------------------------------------------

    /**
     * Fetch a card by number. 404 from upstream surfaces as "HTTP 404"
     * in the RuntimeException message.
     */
    public function getCard(string $cardNumber): array {
        return $this->request('GET', '/cards/' . urlencode($cardNumber));
    }

    /**
     * Fetch a paginated page of transactions for a card. Per spec, transactions
     * are sorted descending (most recent first).
     */
    public function getCardTransactions(string $cardNumber, int $skip = 0, int $take = 50): array {
        return $this->request('GET', '/cards/' . urlencode($cardNumber) . '/transactions', null, [
            'skip' => $skip,
            'take' => $take,
        ]);
    }

    /**
     * Probe / validate a card's PIN. If $validate is null we just probe whether
     * the card has a PIN; otherwise we test the supplied PIN.
     * 404 (no PIN / unknown card) is propagated as a RuntimeException.
     */
    public function getCardPin(string $cardNumber, ?string $validate = null): array {
        $query = [];
        if ($validate !== null && $validate !== '') {
            $query['validate'] = $validate;
        }
        return $this->request('GET', '/cards/' . urlencode($cardNumber) . '/pin', null, $query);
    }

    /**
     * Sync all kiosks to the kiosk_state_cache table.
     * Returns count of kiosks synced.
     */
    public function syncKiosksToCache(): int {
        $kiosks = $this->getAllKiosks();
        $seenIds = [];

        // Same transactional wrapper as syncGamesToCache: never leave a
        // half-updated kiosk cache behind on a mid-sync crash.
        $db = DB::getInstance();
        $db->exec('BEGIN');
        try {
            foreach ($kiosks as $kiosk) {
                $kioskId = (string)($kiosk['id'] ?? '');
                if ($kioskId === '') continue;
                $seenIds[] = $kioskId;
                $name = $kiosk['name'] ?? '';
                // Per spec: missing operationStatus means "unknown" — clients MUST NOT
                // try to change it. Store as-is so the UI can hide pause controls.
                $opStatus = isset($kiosk['operationStatus']) ? (string)$kiosk['operationStatus'] : '';
                $categories = json_encode($kiosk['categories'] ?? []);
                $actions = json_encode($kiosk['supportedActions'] ?? []);

                DB::execute(
                    'INSERT INTO kiosk_state_cache (kiosk_id, kiosk_name, operation_status, categories, supported_actions, last_synced_at)
                     VALUES (:p0, :p1, :p2, :p3, :p4, datetime(\'now\'))
                     ON CONFLICT(kiosk_id) DO UPDATE SET
                         kiosk_name = :p1, operation_status = :p2, categories = :p3,
                         supported_actions = :p4, last_synced_at = datetime(\'now\')',
                    [$kioskId, $name, $opStatus, $categories, $actions]
                );
            }

            if (empty($seenIds)) {
                // Defensive: same reasoning as syncGamesToCache — treat an empty
                // response as a transient blip and keep the existing cache rather
                // than wiping every kiosk's state. Installs that genuinely don't
                // expose /kiosks surface a 404 (handled by the caller), not an
                // empty list, so this only skips pruning on a suspicious empty
                // 200. Individual removed kiosks are still pruned on any non-empty
                // sync below.
                error_log('syncKiosksToCache: upstream returned 0 kiosks — keeping cached kiosk states (skipping prune).');
            } else {
                $placeholders = [];
                $params = [];
                foreach ($seenIds as $i => $kid) {
                    $placeholders[] = ':p' . $i;
                    $params[] = $kid;
                }
                DB::execute(
                    'DELETE FROM kiosk_state_cache WHERE kiosk_id NOT IN (' . implode(',', $placeholders) . ')',
                    $params
                );
            }
            $db->exec('COMMIT');
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }

        return count($kiosks);
    }

    // -----------------------------------------------
    // Generic Pagination Helper
    // -----------------------------------------------

    /**
     * Fetch all records from a paginated endpoint.
     */
    private function fetchAllPaginated(string $path, string $dataKey): array {
        $all = [];
        $skip = 0;
        $take = GAMES_PAGE_SIZE;
        $pages = 0;

        do {
            $pages++;
            if ($pages > self::MAX_PAGINATION_LOOPS) {
                throw new RuntimeException("Pagination safety limit exceeded for $path.");
            }

            $result = $this->request('GET', $path, null, ['skip' => $skip, 'take' => $take]);
            $items = $result[$dataKey] ?? [];
            $all = array_merge($all, $items);
            $totalCount = $result['totalCount'] ?? null;
            $skip += count($items);

            if (count($items) < $take) break;
            if ($totalCount !== null && $skip >= $totalCount) break;
        } while (true);

        return $all;
    }

    /**
     * Test the connection: authenticate, check capabilities, probe game and
     * category endpoints. Uses single-page (take=1) probes that read the
     * upstream `totalCount` rather than walking every page — a connection
     * test should never paginate through hundreds of games.
     * Returns status array.
     */
    public function testConnection(): array {
        try {
            $this->authenticate();
            $capabilities = $this->getCapabilities();
            // Refresh the capabilities cache so subsequent UI requests are
            // served from cache.
            self::writeJsonCache('cache_capabilities', $capabilities);

            $gamesProbe = $this->request('GET', '/games', null, ['skip' => 0, 'take' => 1]);
            $catsProbe  = $this->request('GET', '/games/categories', null, ['skip' => 0, 'take' => 1]);

            $gameCount = isset($gamesProbe['totalCount']) ? (int)$gamesProbe['totalCount']
                                                           : count($gamesProbe['games'] ?? []);
            $categoryCount = isset($catsProbe['totalCount']) ? (int)$catsProbe['totalCount']
                                                              : count($catsProbe['categories'] ?? []);

            $supportsOperationStatus = $capabilities['games']['operationStatus'] ?? false;

            return [
                'success'                 => true,
                'system_name'             => $capabilities['systemName'] ?? 'Unknown',
                'interface_version'       => $capabilities['interfaceVersion'] ?? 'Unknown',
                'supports_operation_status' => $supportsOperationStatus,
                'supports_categories'     => $capabilities['games']['categories'] ?? false,
                'game_count'              => $gameCount,
                'category_count'          => $categoryCount,
                'error'                   => null,
            ];
        } catch (Exception $e) {
            return [
                'success'                 => false,
                'system_name'             => null,
                'interface_version'       => null,
                'supports_operation_status' => false,
                'supports_categories'     => false,
                'game_count'              => 0,
                'category_count'          => 0,
                'error'                   => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync all games to the game_state_cache table.
     * Returns count of games synced.
     */
    public function syncGamesToCache(): int {
        $games = $this->getGames();
        $seenGameIds = [];

        // Upsert + prune run in one transaction so a mid-sync crash can never
        // leave a half-updated cache (some rows fresh, some stale, some
        // pruned). WAL mode keeps readers unblocked while this commits.
        $db = DB::getInstance();
        $db->exec('BEGIN');
        try {
            foreach ($games as $game) {
                $gameId = (string)$game['id'];
                $seenGameIds[] = $gameId;
                $gameName = $game['name'] ?? '';
                $opStatus = $game['operationStatus'] ?? 'enabled';
                $categories = json_encode($game['categories'] ?? []);

                DB::execute(
                    'INSERT INTO game_state_cache (game_id, game_name, operation_status, categories, last_synced_at)
                     VALUES (:p0, :p1, :p2, :p3, datetime(\'now\'))
                     ON CONFLICT(game_id) DO UPDATE SET
                         game_name = :p1, operation_status = :p2, categories = :p3, last_synced_at = datetime(\'now\')',
                    [$gameId, $gameName, $opStatus, $categories]
                );
            }

            if (empty($seenGameIds)) {
                // Defensive: an empty games list is almost always a transient API
                // hiccup (an empty-but-200 response), not the venue genuinely
                // having zero games. Wiping the cache here would blank every game's
                // state and disable ALL pause/unpause until the next good sync, so
                // we keep the existing cache and skip pruning this cycle.
                error_log('syncGamesToCache: upstream returned 0 games — keeping cached game states (skipping prune to avoid wiping the cache on a transient empty response).');
            } else {
                $params = [];
                $placeholders = [];
                foreach ($seenGameIds as $i => $gameId) {
                    $placeholders[] = ':p' . $i;
                    $params[] = $gameId;
                }
                DB::execute(
                    'DELETE FROM game_state_cache WHERE game_id NOT IN (' . implode(',', $placeholders) . ')',
                    $params
                );
            }
            $db->exec('COMMIT');
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }

        return count($games);
    }

    // -----------------------------------------------
    // HTTP Layer
    // -----------------------------------------------

    /**
     * Make an authenticated API request with retry on 401 and transient errors.
     * Retries up to 3 times with exponential backoff for network errors and 5xx.
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array {
        $token = $this->getToken();
        $maxRetries = 3;
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->httpRequest($method, $path, $body, true, $query);
            } catch (RuntimeException $e) {
                $lastException = $e;
                $msg = $e->getMessage();

                // If 401, re-authenticate and retry once (don't count as transient retry)
                if (strpos($msg, 'HTTP 401') !== false && $attempt === 0) {
                    $this->authenticate();
                    continue;
                }

                // Don't retry client errors (4xx) other than 401/408/429
                if (preg_match('/HTTP (4\d\d)/', $msg, $m)) {
                    $code = (int)$m[1];
                    if ($code !== 408 && $code !== 429) {
                        throw $e;
                    }
                }

                // Retry on transient errors: network failures, 5xx, 408, 429
                if ($attempt < $maxRetries) {
                    $delay = (int)pow(2, $attempt + 1); // 2s, 4s, 8s
                    error_log("CenterEdge API transient error (attempt " . ($attempt + 1) . "/$maxRetries, retrying in {$delay}s): $msg");
                    sleep($delay);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Execute an HTTP request to the CenterEdge API.
     */
    private function httpRequest(string $method, string $path, ?array $body = null, bool $auth = true, array $query = []): array {
        $url = rtrim($this->baseUrl, '/') . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($auth && $this->bearerToken) {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        }

        if ($this->apiKey) {
            $headers[] = 'X-Api-Key: ' . $this->apiKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("CenterEdge API connection error ($url): $curlError");
        }

        $data = [];
        if ($responseBody !== '' && $responseBody !== false) {
            $data = json_decode($responseBody, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                $snippet = substr(trim($responseBody), 0, 200);
                throw new RuntimeException("CenterEdge API ($url) returned non-JSON (HTTP $httpCode). Response begins with: $snippet");
            }
        }

        if ($httpCode === 401) {
            $msg = $data['message'] ?? ($data['error'] ?? 'Unauthorized – check username, password, and API key');
            throw new RuntimeException("CenterEdge API error: $msg (HTTP 401)");
        }

        if ($httpCode === 403) {
            $msg = $data['message'] ?? ($data['error'] ?? 'Forbidden – invalid credentials');
            throw new RuntimeException("CenterEdge API error: $msg (HTTP 403)");
        }

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? ($data['error'] ?? "HTTP $httpCode");
            throw new RuntimeException("CenterEdge API error: $msg (HTTP $httpCode)");
        }

        return $data ?? [];
    }
}
