<?php
/**
 * API: Aggregated dashboard analytics.
 *
 * GET  /api/stats/dashboard       — pre-computed rollups (today, hourly, top games,
 *                                   trailing 7-day series, live ticker)
 * POST /api/stats/sync            — force a transactions sync from CenterEdge
 *
 * Reads from the local game_transaction_cache table — fast even with tens of
 * thousands of cached rows. The sync itself is incremental (sinceId-based).
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/centeredge_client.php';
require_once __DIR__ . '/../lib/scheduler.php';

function handleStats(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $action = $parts[0] ?? 'dashboard';

    if ($method === 'GET' && ($action === '' || $action === 'dashboard')) {
        // Opportunistically refresh the transactions cache. Throttled internally
        // so concurrent dashboards don't hammer CenterEdge.
        try {
            Scheduler::syncGameTransactionsIfStale(45);
        } catch (Exception $e) {
            // Soft-fail — fall back to whatever's already cached.
            error_log('stats: opportunistic sync failed: ' . $e->getMessage());
        }

        $tickerLimit  = isset($_GET['ticker']) ? (int)$_GET['ticker'] : 12;
        $topGamesLimit = isset($_GET['top']) ? (int)$_GET['top'] : 8;

        $tickerLimit = max(1, min(50, $tickerLimit));
        $topGamesLimit = max(1, min(25, $topGamesLimit));

        $stats = Scheduler::getDashboardStats($tickerLimit, $topGamesLimit);
        echo json_encode($stats);
        return;
    }

    if ($method === 'POST' && $action === 'sync') {
        $client = new CenterEdgeClient();
        if (!$client->isConfigured()) {
            http_response_code(400);
            echo json_encode(['error' => 'CenterEdge API is not configured.']);
            return;
        }
        $count = Scheduler::syncGameTransactions('default');
        echo json_encode([
            'success' => true,
            'inserted' => $count,
            'synced_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
