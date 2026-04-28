<?php
/**
 * Game Details API handler — single game, actions, transaction feed.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/centeredge_client.php';

function handleGameDetails(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    $subResource = $parts[0] ?? '';

    // GET /api/gamedetails/transactions — game transaction feed
    if ($subResource === 'transactions' && $method === 'GET') {
        $sinceId = $_GET['sinceId'] ?? '0';
        $feedName = $_GET['feedName'] ?? null;
        $take = (int)($_GET['take'] ?? 50);
        $take = max(1, min(100, $take));

        try {
            $result = $client->getGameTransactions($sinceId, $feedName, $take);
            echo json_encode($result);
        } catch (RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        }
        return;
    }

    // GET /api/gamedetails/{gameId} — single game
    if ($subResource !== '' && $method === 'GET') {
        $action = $parts[1] ?? '';
        if ($action === '') {
            try {
                $game = $client->getGame($subResource);
                echo json_encode($game);
            } catch (RuntimeException $e) {
                http_response_code(500);
                echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
            }
            return;
        }
    }

    // POST /api/gamedetails/{gameId}/action — perform game action
    if ($subResource !== '' && $method === 'POST') {
        $action = $parts[1] ?? '';
        if ($action === 'action') {
            if (!$input || !isset($input['actionId'])) {
                http_response_code(400);
                echo json_encode(['error' => 'actionId is required']);
                return;
            }

            $user = Auth::check();
            $operator = [
                'employeeName' => $user['display_name'] ?? $user['username'] ?? 'System',
                'stationName' => 'PauseGroupsUI',
            ];

            try {
                $result = $client->performGameAction($subResource, $input['actionId'], $operator);
                echo json_encode($result);
            } catch (RuntimeException $e) {
                $code = strpos($e->getMessage(), 'HTTP 400') !== false ? 400 : 500;
                http_response_code($code);
                echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
            }
            return;
        }
    }

    http_response_code(404);
    echo json_encode(['error' => 'Unknown game details endpoint']);
}
