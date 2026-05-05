<?php
/**
 * Capabilities & Card Number Formats API handler.
 *
 * Capabilities change essentially never (between card-system upgrades), so
 * the response is cached server-side in api_config under
 * "capabilities_cache" / "capabilities_cache_at" with a 1-hour TTL. Pass
 * ?refresh=1 to force a fresh fetch from CenterEdge.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/centeredge_client.php';

const CAPABILITIES_CACHE_TTL = 3600; // 1 hour

function handleCapabilities(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    $forceRefresh = !empty($_GET['refresh']);

    if (!$forceRefresh) {
        $cached = capabilitiesReadCache();
        if ($cached !== null) {
            echo json_encode($cached);
            return;
        }
    }

    try {
        $capabilities = $client->getCapabilities();
        capabilitiesWriteCache($capabilities);
        echo json_encode($capabilities);
    } catch (RuntimeException $e) {
        // On upstream failure, fall back to a stale cached copy if we have
        // one — better to serve stale capabilities than to break the kiosks
        // page entirely while CenterEdge is briefly unreachable.
        $stale = capabilitiesReadCache(true);
        if ($stale !== null) {
            echo json_encode($stale);
            return;
        }
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}

/**
 * Return the cached capabilities payload, or null when missing/expired.
 * Pass $allowStale=true to skip the TTL check (used as a fallback when
 * the upstream API call fails).
 */
function capabilitiesReadCache(bool $allowStale = false): ?array {
    $raw = DB::getConfig('capabilities_cache');
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!$allowStale) {
        $fetchedAt = DB::getConfig('capabilities_cache_at');
        if ($fetchedAt === null) {
            return null;
        }
        $ts = strtotime($fetchedAt . ' UTC');
        if ($ts === false || (time() - $ts) >= CAPABILITIES_CACHE_TTL) {
            return null;
        }
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function capabilitiesWriteCache(array $capabilities): void {
    DB::setConfig('capabilities_cache', json_encode($capabilities), false);
    DB::setConfig('capabilities_cache_at', gmdate('Y-m-d H:i:s'), false);
}
