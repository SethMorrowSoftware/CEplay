<?php
/**
 * API: CenterEdge API configuration management.
 * GET /api/settings — Return current config (passwords masked)
 * PUT /api/settings — Update config
 * POST /api/settings/test — Test CenterEdge connection
 */

require_once __DIR__ . '/../lib/centeredge_client.php';
require_once __DIR__ . '/../lib/validator.php';

function handleSettings(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $action = $parts[0] ?? '';

    if ($method === 'GET' && $action === '') {
        $baseUrl        = DB::getConfig('base_url') ?? '';
        $username       = DB::getConfig('username') ?? '';
        $password       = DB::getConfig('password');
        $apiKey         = DB::getConfig('api_key');
        $timezone       = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
        $tokenFetchedAt = DB::getConfig('token_fetched_at');

        // ---- Polling: CenterEdge feed ----
        $txPollInterval = (int)(DB::getConfig('tx_poll_interval_seconds')        ?? 60);

        // ---- Safety nets ----
        $tier2Throttle      = (int)(DB::getConfig('tier2_throttle_seconds')      ?? 60);
        $stateSyncStale     = (int)(DB::getConfig('state_sync_stale_seconds')    ?? 300);

        // ---- UI polling intervals (milliseconds) ----
        $uiPollDefault      = (int)(DB::getConfig('ui_poll_default_ms')          ?? 30000);
        $uiPollOverride     = (int)(DB::getConfig('ui_poll_override_active_ms')  ?? 10000);
        $uiPollImminent     = (int)(DB::getConfig('ui_poll_imminent_ms')         ?? 5000);
        $uiPollGamesAn      = (int)(DB::getConfig('ui_poll_games_analytics_ms')  ?? 30000);
        $uiPollGamesFeed    = (int)(DB::getConfig('ui_poll_games_feed_ms')       ?? 15000);
        $uiPollOverrides    = (int)(DB::getConfig('ui_poll_overrides_ms')        ?? 15000);

        // ---- Data retention (days) ----
        $retentionActionLog     = (int)(DB::getConfig('retention_action_log_days')           ?? 90);
        $retentionActions       = (int)(DB::getConfig('retention_scheduled_actions_days')    ?? 30);
        $retentionOverrides     = (int)(DB::getConfig('retention_overrides_days')            ?? 90);
        $retentionTransactions  = (int)(DB::getConfig('retention_transactions_days')         ?? 395);

        // ---- Scheduler behaviour ----
        $retryMaxAttempts       = (int)(DB::getConfig('retry_max_attempts')      ?? 10);
        $topGamesLimit          = (int)(DB::getConfig('dashboard_top_games_limit') ?? 5);

        echo json_encode([
            'base_url'          => $baseUrl,
            'username'          => $username,
            'password'          => $password ? '********' : '',
            'api_key'           => $apiKey ? '********' : '',
            'timezone'          => $timezone,
            'token_fetched_at'  => $tokenFetchedAt,

            'tx_poll_interval_seconds'       => $txPollInterval,
            'tier2_throttle_seconds'         => $tier2Throttle,
            'state_sync_stale_seconds'       => $stateSyncStale,

            'ui_poll_default_ms'             => $uiPollDefault,
            'ui_poll_override_active_ms'     => $uiPollOverride,
            'ui_poll_imminent_ms'            => $uiPollImminent,
            'ui_poll_games_analytics_ms'     => $uiPollGamesAn,
            'ui_poll_games_feed_ms'          => $uiPollGamesFeed,
            'ui_poll_overrides_ms'           => $uiPollOverrides,

            'retention_action_log_days'          => $retentionActionLog,
            'retention_scheduled_actions_days'   => $retentionActions,
            'retention_overrides_days'           => $retentionOverrides,
            'retention_transactions_days'        => $retentionTransactions,

            'retry_max_attempts'             => $retryMaxAttempts,
            'dashboard_top_games_limit'      => $topGamesLimit,
        ]);
        return;
    }

    if ($method === 'PUT' && $action === '') {
        // Detect what's being updated: API config vs. timezone-only
        $hasApiFields = isset($input['base_url']) || isset($input['username']);

        if ($hasApiFields) {
            // Full API config update
            $baseUrl  = Validator::requireUrl($input, 'base_url');
            $username = Validator::requireString($input, 'username');
            $password = $input['password'] ?? '';
            $apiKey   = $input['api_key'] ?? '';

            // Warn (but don't block) if API URL is not HTTPS — credentials sent in plaintext
            if (stripos($baseUrl, 'https://') !== 0) {
                error_log('WARNING: CenterEdge API base_url is not HTTPS — credentials will be sent in plaintext: ' . $baseUrl);
            }

            DB::setConfig('base_url', $baseUrl, false);
            DB::setConfig('username', $username, true);

            // Only update password if not the masked placeholder
            if ($password !== '' && $password !== '********') {
                DB::setConfig('password', $password, true);
                // Clear cached token when credentials change
                DB::setConfig('bearer_token', null, false);
                DB::setConfig('token_fetched_at', null, false);
            }

            // Only update api_key if not the masked placeholder
            if ($apiKey !== '********') {
                DB::setConfig('api_key', $apiKey ?: null, $apiKey ? true : false);
            }
        }

        // Update timezone if provided
        if (isset($input['timezone'])) {
            $timezone = Validator::requireString($input, 'timezone', 100);
            try {
                new DateTimeZone($timezone);
            } catch (Exception $e) {
                throw new RuntimeException("Invalid timezone: $timezone");
            }
            DB::setConfig('timezone', $timezone, false);
        }

        // Helper: save an integer setting within [min, max]
        $saveInt = function (string $key, int $min, int $max) use ($input): void {
            if (isset($input[$key])) {
                $v = (int)$input[$key];
                if ($v < $min || $v > $max) {
                    throw new RuntimeException("$key must be between $min and $max.");
                }
                DB::setConfig($key, (string)$v, false);
            }
        };

        // ---- Polling: CenterEdge feed ----
        if (isset($input['tx_poll_interval_seconds'])) {
            $allowed = [60, 120, 300, 600, 900];
            $v = (int)$input['tx_poll_interval_seconds'];
            if (!in_array($v, $allowed, true)) {
                throw new RuntimeException('tx_poll_interval_seconds must be one of: ' . implode(', ', $allowed));
            }
            DB::setConfig('tx_poll_interval_seconds', (string)$v, false);
        }

        // ---- Safety nets ----
        $saveInt('tier2_throttle_seconds',    15,   3600);
        $saveInt('state_sync_stale_seconds',  30,   3600);

        // ---- UI polling (ms) ----
        $saveInt('ui_poll_default_ms',            5000,  600000);
        $saveInt('ui_poll_override_active_ms',    2000,  120000);
        $saveInt('ui_poll_imminent_ms',           2000,   60000);
        $saveInt('ui_poll_games_analytics_ms',    5000,  600000);
        $saveInt('ui_poll_games_feed_ms',         5000,  600000);
        $saveInt('ui_poll_overrides_ms',          5000,  600000);

        // ---- Data retention (days) ----
        $saveInt('retention_action_log_days',          7, 3650);
        $saveInt('retention_scheduled_actions_days',   1,  365);
        $saveInt('retention_overrides_days',           7, 3650);
        $saveInt('retention_transactions_days',       30, 3650);

        // ---- Scheduler behaviour ----
        $saveInt('retry_max_attempts',         1,  50);
        $saveInt('dashboard_top_games_limit',  1,  20);

        DB::auditLog('admin', 'settings_updated', null, [
            'api_config_changed' => $hasApiFields,
            'timezone_changed'   => isset($input['timezone']),
            'keys_updated'       => array_keys(array_filter($input, function($k) {
                return !in_array($k, ['base_url','username','password','api_key','timezone'], true);
            }, ARRAY_FILTER_USE_KEY)),
        ]);

        echo json_encode(['success' => true]);
        return;
    }

    if ($method === 'POST' && $action === 'test') {
        // Test connection. Operators can pass ad-hoc credentials in the body
        // to verify them without committing to the DB; if any field is omitted
        // or sent as the masked placeholder the stored value is used instead.
        $client = new CenterEdgeClient();
        $overrideBase = isset($input['base_url']) ? trim((string)$input['base_url']) : null;
        $overrideUser = isset($input['username']) ? trim((string)$input['username']) : null;
        $overridePass = $input['password'] ?? null;
        $overrideKey  = $input['api_key'] ?? null;

        if ($overridePass === '********') { $overridePass = null; }
        if ($overrideKey === '********')  { $overrideKey  = null; }

        if ($overrideBase !== null || $overrideUser !== null || $overridePass !== null || $overrideKey !== null) {
            $client->applyCredentialsOverride($overrideBase, $overrideUser, $overridePass, $overrideKey);
        }

        $result = $client->testConnection();
        DB::auditLog('admin', 'settings_test_connection', null, [
            'system_name' => $result['system_name'] ?? null,
            'interface_version' => $result['interface_version'] ?? null,
            'game_count' => $result['game_count'] ?? null,
        ], !empty($result['success']), $result['error'] ?? null);
        echo json_encode($result);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
