<?php
/**
 * Attractions API — rides, laser tag, bumper cars, and other non-game attractions.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/validator.php';

function handleAttractions(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $id = $parts[0] ?? '';
    $action = $parts[1] ?? '';

    // POST /api/attractions/{id}/status — quick status change
    if ($id && $action === 'status' && $method === 'POST') {
        handleAttractionStatusChange($id, $input);
        return;
    }

    if ($id) {
        switch ($method) {
            case 'GET':
                handleGetAttraction($id);
                break;
            case 'PUT':
                handleUpdateAttraction($id, $input);
                break;
            case 'DELETE':
                handleDeleteAttraction($id);
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
        }
        return;
    }

    switch ($method) {
        case 'GET':
            handleListAttractions();
            break;
        case 'POST':
            handleCreateAttraction($input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleListAttractions(): void {
    $type = $_GET['type'] ?? '';
    $status = $_GET['status'] ?? '';

    // Validate enum filter values
    $validTypes = ['ride', 'laser_tag', 'bumper_cars', 'go_karts', 'mini_golf', 'bowling', 'arcade_zone', 'attraction', 'other'];
    $validStatuses = ['open', 'closed', 'maintenance', 'seasonal'];

    if ($type && !in_array($type, $validTypes, true)) {
        throw new RuntimeException('Invalid type filter. Allowed: ' . implode(', ', $validTypes));
    }
    if ($status && !in_array($status, $validStatuses, true)) {
        throw new RuntimeException('Invalid status filter. Allowed: ' . implode(', ', $validStatuses));
    }

    $sql = 'SELECT * FROM attractions';
    $params = [];
    $conditions = [];
    $idx = 0;

    if ($type) {
        $conditions[] = "type = :p$idx";
        $params[$idx++] = $type;
    }
    if ($status) {
        $conditions[] = "status = :p$idx";
        $params[$idx++] = $status;
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY name ASC';

    $attractions = DB::query($sql, $params);

    // Compute summary stats
    $stats = [
        'total' => count($attractions),
        'open' => 0,
        'closed' => 0,
        'maintenance' => 0,
        'by_type' => [],
    ];
    foreach ($attractions as $a) {
        if ($a['status'] === 'open') $stats['open']++;
        elseif ($a['status'] === 'closed') $stats['closed']++;
        elseif ($a['status'] === 'maintenance') $stats['maintenance']++;

        $t = $a['type'];
        if (!isset($stats['by_type'][$t])) $stats['by_type'][$t] = 0;
        $stats['by_type'][$t]++;
    }

    echo json_encode(['attractions' => $attractions, 'stats' => $stats]);
}

function handleGetAttraction(string $id): void {
    $attraction = DB::queryOne('SELECT * FROM attractions WHERE id = :p0', [(int)$id]);
    if (!$attraction) {
        http_response_code(404);
        echo json_encode(['error' => 'Attraction not found']);
        return;
    }

    // Get related maintenance tickets
    $tickets = DB::query(
        'SELECT * FROM maintenance_tickets WHERE asset_type = :p0 AND asset_id = :p1 ORDER BY created_at DESC LIMIT 10',
        ['attraction', (string)$id]
    );
    $attraction['recent_maintenance'] = $tickets;

    echo json_encode($attraction);
}

function handleCreateAttraction(?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $name = Validator::requireString($input, 'name');
    $type = Validator::requireEnum($input, 'type', ['ride', 'laser_tag', 'bumper_cars', 'go_karts', 'mini_golf', 'bowling', 'arcade_zone', 'attraction', 'other']);
    $description = Validator::optionalString($input, 'description');
    $location = Validator::optionalString($input, 'location');
    $capacity = Validator::optionalInt($input, 'capacity', 0, 9999) ?? 0;
    $minHeight = Validator::optionalInt($input, 'min_height_inches', 0, 120) ?? 0;
    $maxHeight = Validator::optionalInt($input, 'max_height_inches', 0, 120) ?? 0;
    $duration = Validator::optionalInt($input, 'duration_minutes', 0, 999) ?? 0;
    $status = isset($input['status']) ? Validator::requireEnum($input, 'status', ['open', 'closed', 'maintenance', 'seasonal']) : 'open';
    $ceGameId = Validator::optionalString($input, 'centeredge_game_id');
    $wristband = isset($input['requires_wristband']) ? ($input['requires_wristband'] ? 1 : 0) : 0;
    $creditCost = Validator::optionalInt($input, 'credit_cost', 0, 99999) ?? 0;

    DB::execute(
        'INSERT INTO attractions (name, type, description, location, capacity, min_height_inches, max_height_inches, duration_minutes, status, centeredge_game_id, requires_wristband, credit_cost)
         VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8, :p9, :p10, :p11)',
        [$name, $type, $description, $location, $capacity, $minHeight, $maxHeight, $duration, $status, $ceGameId, $wristband, $creditCost]
    );

    $id = DB::lastInsertId();
    $attraction = DB::queryOne('SELECT * FROM attractions WHERE id = :p0', [$id]);
    http_response_code(201);
    echo json_encode($attraction);
}

function handleUpdateAttraction(string $id, ?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $existing = DB::queryOne('SELECT * FROM attractions WHERE id = :p0', [(int)$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Attraction not found']);
        return;
    }

    $name = isset($input['name']) ? Validator::requireString($input, 'name') : $existing['name'];
    $type = isset($input['type']) ? Validator::requireEnum($input, 'type', ['ride', 'laser_tag', 'bumper_cars', 'go_karts', 'mini_golf', 'bowling', 'arcade_zone', 'attraction', 'other']) : $existing['type'];
    $description = isset($input['description']) ? Validator::optionalString($input, 'description') : $existing['description'];
    $location = isset($input['location']) ? Validator::optionalString($input, 'location') : $existing['location'];
    $capacity = isset($input['capacity']) ? (Validator::optionalInt($input, 'capacity', 0, 9999) ?? 0) : $existing['capacity'];
    $minHeight = isset($input['min_height_inches']) ? (Validator::optionalInt($input, 'min_height_inches', 0, 120) ?? 0) : $existing['min_height_inches'];
    $maxHeight = isset($input['max_height_inches']) ? (Validator::optionalInt($input, 'max_height_inches', 0, 120) ?? 0) : $existing['max_height_inches'];
    $duration = isset($input['duration_minutes']) ? (Validator::optionalInt($input, 'duration_minutes', 0, 999) ?? 0) : $existing['duration_minutes'];
    $status = isset($input['status']) ? Validator::requireEnum($input, 'status', ['open', 'closed', 'maintenance', 'seasonal']) : $existing['status'];
    $ceGameId = isset($input['centeredge_game_id']) ? Validator::optionalString($input, 'centeredge_game_id') : $existing['centeredge_game_id'];
    $wristband = isset($input['requires_wristband']) ? ($input['requires_wristband'] ? 1 : 0) : $existing['requires_wristband'];
    $creditCost = isset($input['credit_cost']) ? (Validator::optionalInt($input, 'credit_cost', 0, 99999) ?? 0) : $existing['credit_cost'];

    DB::execute(
        'UPDATE attractions SET name = :p0, type = :p1, description = :p2, location = :p3, capacity = :p4,
         min_height_inches = :p5, max_height_inches = :p6, duration_minutes = :p7, status = :p8,
         centeredge_game_id = :p9, requires_wristband = :p10, credit_cost = :p11, updated_at = datetime(\'now\')
         WHERE id = :p12',
        [$name, $type, $description, $location, $capacity, $minHeight, $maxHeight, $duration, $status, $ceGameId, $wristband, $creditCost, (int)$id]
    );

    $attraction = DB::queryOne('SELECT * FROM attractions WHERE id = :p0', [(int)$id]);
    echo json_encode($attraction);
}

function handleDeleteAttraction(string $id): void {
    $existing = DB::queryOne('SELECT * FROM attractions WHERE id = :p0', [(int)$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Attraction not found']);
        return;
    }
    DB::execute('DELETE FROM attractions WHERE id = :p0', [(int)$id]);
    echo json_encode(['success' => true]);
}

function handleAttractionStatusChange(string $id, ?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $existing = DB::queryOne('SELECT * FROM attractions WHERE id = :p0', [(int)$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Attraction not found']);
        return;
    }

    $status = Validator::requireEnum($input, 'status', ['open', 'closed', 'maintenance', 'seasonal']);
    DB::execute(
        'UPDATE attractions SET status = :p0, updated_at = datetime(\'now\') WHERE id = :p1',
        [$status, (int)$id]
    );

    $attraction = DB::queryOne('SELECT * FROM attractions WHERE id = :p0', [(int)$id]);
    echo json_encode($attraction);
}
