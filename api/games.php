<?php
/**
 * API: Proxy game and category data from CenterEdge.
 * GET /api/games — Return games from cache (or auto-sync if empty)
 * GET /api/games/categories — Return categories from CenterEdge
 * POST /api/games/sync — Force sync game states from CenterEdge
 */

require_once __DIR__ . '/../lib/centeredge_client.php';

function handleGames(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $action = $parts[0] ?? '';

    if ($method === 'GET' && $action === '') {
        // Return games from cache
        $cached = DB::query(
            'SELECT game_id, game_name, operation_status, categories, last_synced_at
             FROM game_state_cache ORDER BY game_name ASC'
        );

        // Auto-sync if cache is empty
        if (empty($cached)) {
            try {
                $client = new CenterEdgeClient();
                if ($client->isConfigured()) {
                    $client->syncGamesToCache();
                    $cached = DB::query(
                        'SELECT game_id, game_name, operation_status, categories, last_synced_at
                         FROM game_state_cache ORDER BY game_name ASC'
                    );
                }
            } catch (Exception $e) {
                // Ignore sync errors, return empty
            }
        }

        // Parse categories JSON for each game
        $games = array_map(function ($g) {
            $g['categories'] = json_decode($g['categories'], true) ?: [];
            return $g;
        }, $cached);

        // Get last sync time
        $lastSync = DB::queryOne('SELECT MAX(last_synced_at) as last_synced FROM game_state_cache');

        echo json_encode([
            'games'       => $games,
            'total'       => count($games),
            'last_synced' => $lastSync['last_synced'] ?? null,
        ]);
        return;
    }

    if ($method === 'GET' && $action === 'categories') {
        // Fetch categories from CenterEdge (always live)
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        $categories = $client->getCategories();
        echo json_encode(['categories' => $categories]);
        return;
    }

    if ($method === 'PATCH' && $action === '') {
        // Patch game operation statuses via CenterEdge API
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }

        $gamesPayload = $input['games'] ?? [];
        if (empty($gamesPayload)) {
            http_response_code(400);
            echo json_encode(['error' => 'No game patches provided.']);
            return;
        }

        // Build simple status map from JSON Patch operations
        $allowedStatuses = ['enabled', 'paused', 'outOfService'];
        $changes = [];
        foreach ($gamesPayload as $gameId => $patches) {
            foreach ($patches as $patch) {
                if (($patch['op'] ?? '') === 'replace' && ($patch['path'] ?? '') === '/operationStatus') {
                    $status = $patch['value'];
                    if (!in_array($status, $allowedStatuses, true)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid operationStatus: ' . $status]);
                        return;
                    }
                    $changes[$gameId] = $status;
                }
            }
        }

        if (empty($changes)) {
            echo json_encode(['games' => [], 'errors' => []]);
            return;
        }

        $result = $client->patchGames($changes);

        // Update cache
        foreach ($changes as $gameId => $status) {
            DB::execute(
                'UPDATE game_state_cache SET operation_status = :p0 WHERE game_id = :p1',
                [$status, (string)$gameId]
            );
        }

        echo json_encode($result);
        return;
    }

    if ($method === 'POST' && $action === 'sync') {
        // Force sync
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        $count = $client->syncGamesToCache();

        echo json_encode([
            'success'    => true,
            'game_count' => $count,
            'synced_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
