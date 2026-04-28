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

    /**
     * Sync all kiosks to the kiosk_state_cache table.
     * Returns count of kiosks synced.
     */
    public function syncKiosksToCache(): int {
        $kiosks = $this->getAllKiosks();
        $seenIds = [];

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
            DB::execute('DELETE FROM kiosk_state_cache');
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
     * Test the connection: authenticate, check capabilities, count games.
     * Returns status array.
     */
    public function testConnection(): array {
        try {
            $this->authenticate();
            $capabilities = $this->getCapabilities();
            $games = $this->getGames();
            $categories = $this->getCategories();

            $supportsOperationStatus = $capabilities['games']['operationStatus'] ?? false;

            return [
                'success'                 => true,
                'system_name'             => $capabilities['systemName'] ?? 'Unknown',
                'interface_version'       => $capabilities['interfaceVersion'] ?? 'Unknown',
                'supports_operation_status' => $supportsOperationStatus,
                'supports_categories'     => $capabilities['games']['categories'] ?? false,
                'game_count'              => count($games),
                'category_count'          => count($categories),
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
            DB::execute('DELETE FROM game_state_cache');
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
