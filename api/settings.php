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
    // Settings is restricted: managers cannot access CenterEdge config,
    // timezone, or user management. Admins and techs may.
    Auth::requireAccess('settings');

    $action = $parts[0] ?? '';

    if ($method === 'GET' && $action === '') {
        // Return current config (passwords masked)
        $baseUrl  = DB::getConfig('base_url') ?? '';
        $username = DB::getConfig('username') ?? '';
        $password = DB::getConfig('password');
        $apiKey   = DB::getConfig('api_key');
        $timezone = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
        $tokenFetchedAt = DB::getConfig('token_fetched_at');

        echo json_encode([
            'base_url'          => $baseUrl,
            'username'          => $username,
            'password'          => $password ? '********' : '',
            'api_key'           => $apiKey ? '********' : '',
            'timezone'          => $timezone,
            'token_fetched_at'  => $tokenFetchedAt,
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

        DB::auditLog('admin', 'settings_updated', null, [
            'api_config_changed' => $hasApiFields,
            'timezone_changed' => isset($input['timezone']),
        ]);

        echo json_encode(['success' => true]);
        return;
    }

    if ($method === 'POST' && $action === 'test') {
        // Test connection
        $client = new CenterEdgeClient();
        $result = $client->testConnection();
        echo json_encode($result);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
