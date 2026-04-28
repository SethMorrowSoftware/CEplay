<?php
/**
 * Privileges API handler.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/centeredge_client.php';

function handlePrivileges(string $method, array $parts, ?array $input): void {
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

    try {
        $groups = $client->getAllPrivilegeGroups();
        echo json_encode(['privilegeGroups' => $groups, 'totalCount' => count($groups)]);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}
