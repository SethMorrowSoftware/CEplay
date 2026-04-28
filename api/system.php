<?php
/**
 * System Transactions API handler.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/centeredge_client.php';

function handleSystem(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    $subResource = $parts[0] ?? '';

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    if ($subResource === 'transactions') {
        $sinceId = $_GET['sinceId'] ?? '0';
        $take = (int)($_GET['take'] ?? 50);
        $take = max(1, min(100, $take));

        // Parse type[] params
        $types = $_GET['type'] ?? [];
        if (is_string($types)) {
            $types = [$types];
        }
        if (empty($types)) {
            $types = ['merge', 'expiration'];
        }

        try {
            $result = $client->getSystemTransactions($sinceId, $types, $take);
            echo json_encode($result);
        } catch (RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        }
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Unknown system endpoint']);
}
