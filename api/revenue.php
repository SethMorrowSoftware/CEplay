<?php
/**
 * Revenue Analytics API — aggregates CenterEdge transaction data for reporting.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/centeredge_client.php';
require_once __DIR__ . '/../lib/db.php';

function handleRevenue(string $method, array $parts, ?array $input): void {
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

    $subResource = $parts[0] ?? 'summary';

    switch ($subResource) {
        case 'summary':
            handleRevenueSummary();
            break;
        case 'games':
            handleGameRevenue();
            break;
        case 'hourly':
            handleHourlyActivity();
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown revenue endpoint']);
    }
}

function handleRevenueSummary(): void {
    // Get game state cache for fleet health metrics
    $games = DB::query('SELECT * FROM game_state_cache');
    $totalGames = count($games);
    $enabledGames = 0;
    $pausedGames = 0;
    $oosGames = 0;
    foreach ($games as $g) {
        if ($g['operation_status'] === 'enabled') $enabledGames++;
        elseif ($g['operation_status'] === 'paused') $pausedGames++;
        else $oosGames++;
    }

    // Get attraction stats
    $attractions = DB::query('SELECT * FROM attractions');
    $openAttractions = 0;
    $closedAttractions = 0;
    $maintAttractions = 0;
    foreach ($attractions as $a) {
        if ($a['status'] === 'open') $openAttractions++;
        elseif ($a['status'] === 'closed') $closedAttractions++;
        else $maintAttractions++;
    }

    // Get maintenance summary
    $openTickets = DB::queryOne('SELECT COUNT(*) as cnt FROM maintenance_tickets WHERE status NOT IN (\'resolved\', \'closed\')')['cnt'] ?? 0;
    $criticalTickets = DB::queryOne('SELECT COUNT(*) as cnt FROM maintenance_tickets WHERE priority = \'critical\' AND status NOT IN (\'resolved\', \'closed\')')['cnt'] ?? 0;

    // Today's parties — use venue timezone instead of SQLite UTC date('now')
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    $todayParties = DB::queryOne('SELECT COUNT(*) as cnt FROM party_bookings WHERE party_date = :p0 AND status != \'cancelled\'', [$today])['cnt'] ?? 0;
    $upcomingParties = DB::queryOne('SELECT COUNT(*) as cnt FROM party_bookings WHERE party_date > :p0 AND status != \'cancelled\'', [$today])['cnt'] ?? 0;

    echo json_encode([
        'fleet' => [
            'total_games' => $totalGames,
            'enabled' => $enabledGames,
            'paused' => $pausedGames,
            'out_of_service' => $oosGames,
        ],
        'attractions' => [
            'total' => count($attractions),
            'open' => $openAttractions,
            'closed' => $closedAttractions,
            'maintenance' => $maintAttractions,
        ],
        'maintenance' => [
            'open_tickets' => $openTickets,
            'critical_tickets' => $criticalTickets,
        ],
        'parties' => [
            'today' => $todayParties,
            'upcoming' => $upcomingParties,
        ],
        'recent_transactions' => [],
    ]);
}

function handleGameRevenue(): void {
    // Aggregate game activity from local action log (today, venue timezone)
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    $logs = DB::query(
        'SELECT game_id, game_name, action, success FROM action_log
         WHERE timestamp >= :p0 AND game_id IS NOT NULL AND game_id != \'\'
         ORDER BY timestamp ASC',
        [$today . ' 00:00:00']
    );

    $byGame = [];
    foreach ($logs as $log) {
        $gameId = $log['game_id'];
        $gameName = $log['game_name'] ?: 'Unknown';
        if (!isset($byGame[$gameId])) {
            $byGame[$gameId] = ['game_id' => $gameId, 'game_name' => $gameName, 'action_count' => 0, 'success_count' => 0];
        }
        $byGame[$gameId]['action_count']++;
        if ($log['success']) {
            $byGame[$gameId]['success_count']++;
        }
    }

    // Sort by action count
    usort($byGame, function($a, $b) { return $b['action_count'] - $a['action_count']; });

    echo json_encode([
        'game_stats' => array_values($byGame),
        'total_actions' => count($logs),
    ]);
}

function handleHourlyActivity(): void {
    // Use action log to show hourly activity patterns (venue timezone)
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    $logs = DB::query(
        'SELECT * FROM action_log WHERE timestamp >= :p0 ORDER BY timestamp ASC',
        [$today . ' 00:00:00']
    );

    $hourly = array_fill(0, 24, ['actions' => 0, 'successes' => 0, 'errors' => 0]);
    foreach ($logs as $log) {
        $hour = (int)date('G', strtotime($log['timestamp']));
        $hourly[$hour]['actions']++;
        if ($log['success']) {
            $hourly[$hour]['successes']++;
        } else {
            $hourly[$hour]['errors']++;
        }
    }

    echo json_encode([
        'date' => $today,
        'hourly' => $hourly,
        'total_actions' => count($logs),
    ]);
}
