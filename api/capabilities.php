<?php
/**
 * Capabilities & Card Number Formats API handler.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/centeredge_client.php';

function handleCapabilities(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    // Default: return full capabilities. Use the DB cache (6h TTL) — the
    // capabilities payload is effectively static between CenterEdge upgrades,
    // and this endpoint is hit on every kiosks-page load.
    try {
        $capabilities = $client->getCapabilitiesCached();
        echo json_encode($capabilities);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}
