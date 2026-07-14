<?php
/**
 * API: Reader group CRUD.
 *
 * Reader groups are operator-defined groupings of games/readers used purely
 * for analytics — staffing heatmaps ("when is the redemption wall busiest?")
 * and per-area play averages. They never pause anything and are deliberately
 * independent of pause groups: a game may belong to any number of reader
 * groups, so areas can overlap.
 *
 * GET    /api/reader-groups        — List all groups with their games
 * GET    /api/reader-groups/{id}   — Single group with games
 * POST   /api/reader-groups        — Create group {name, description?, game_ids[]}
 * PUT    /api/reader-groups/{id}   — Update group (full replace of membership)
 * DELETE /api/reader-groups/{id}   — Delete group
 *
 * Viewing requires 'analytics' (the groups only exist for reporting);
 * structural changes require 'reader_groups_manage' — its own catalog key,
 * separate from pause groups, so analytics-area editing can be handed out
 * independently.
 */

require_once __DIR__ . '/../lib/validator.php';

function handleReaderGroups(string $method, array $parts, ?array $input): void {
    Auth::requireAccess('analytics');

    $groupId = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : null;

    switch ($method) {
        case 'GET':
            if ($groupId) {
                readerGroupGet($groupId);
            } else {
                readerGroupList();
            }
            break;
        case 'POST':
            Auth::requireAccess('reader_groups_manage');
            readerGroupCreate($input);
            break;
        case 'PUT':
            Auth::requireAccess('reader_groups_manage');
            if (!$groupId) {
                http_response_code(400);
                echo json_encode(['error' => 'Reader group ID required']);
                return;
            }
            readerGroupUpdate($groupId, $input);
            break;
        case 'DELETE':
            Auth::requireAccess('reader_groups_manage');
            if (!$groupId) {
                http_response_code(400);
                echo json_encode(['error' => 'Reader group ID required']);
                return;
            }
            readerGroupDelete($groupId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function readerGroupList(): void {
    $groups = DB::query(
        'SELECT g.id, g.name, g.description, g.created_at, g.updated_at,
                (SELECT COUNT(*) FROM reader_group_games WHERE reader_group_id = g.id) AS game_count
         FROM reader_groups g
         ORDER BY g.name ASC'
    );

    // One membership query for all groups instead of one per group.
    $gamesByGroup = [];
    foreach (DB::query(
        'SELECT m.reader_group_id, m.game_id,
                COALESCE(NULLIF(c.game_name, \'\'), NULLIF(m.game_name, \'\'), m.game_id) AS game_name
         FROM reader_group_games m
         LEFT JOIN game_state_cache c ON c.game_id = m.game_id
         ORDER BY game_name ASC'
    ) as $r) {
        $gamesByGroup[(int)$r['reader_group_id']][] = [
            'game_id'   => (string)$r['game_id'],
            'game_name' => (string)$r['game_name'],
        ];
    }

    foreach ($groups as &$g) {
        $g['id'] = (int)$g['id'];
        $g['game_count'] = (int)$g['game_count'];
        $g['games'] = $gamesByGroup[$g['id']] ?? [];
    }
    unset($g);

    echo json_encode(['groups' => $groups, 'total' => count($groups)]);
}

function readerGroupGet(int $groupId): void {
    $group = DB::queryOne('SELECT * FROM reader_groups WHERE id = :p0', [$groupId]);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Reader group not found']);
        return;
    }
    $group['id'] = (int)$group['id'];
    $group['games'] = DB::query(
        'SELECT m.game_id,
                COALESCE(NULLIF(c.game_name, \'\'), NULLIF(m.game_name, \'\'), m.game_id) AS game_name
         FROM reader_group_games m
         LEFT JOIN game_state_cache c ON c.game_id = m.game_id
         WHERE m.reader_group_id = :p0
         ORDER BY game_name ASC',
        [$groupId]
    );
    echo json_encode($group);
}

/**
 * Validate + normalize the game_ids payload: strings, trimmed, deduped,
 * bounded — a reader group with zero games is allowed (it can be filled in
 * later) but renders as zeros in analytics.
 */
function readerGroupGameIds(?array $input): array {
    $ids = Validator::optionalStringArray($input ?? [], 'game_ids');
    $out = [];
    foreach ($ids as $id) {
        $id = trim((string)$id);
        if ($id === '' || mb_strlen($id) > 64) continue;
        $out[$id] = true;
        if (count($out) > 500) {
            throw new RuntimeException('A reader group may contain at most 500 games.');
        }
    }
    return array_keys($out);
}

function readerGroupCreate(?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $name = Validator::requireString($input, 'name', 100);
    $description = Validator::optionalString($input, 'description', 500);
    $gameIds = readerGroupGameIds($input);

    $db = DB::getInstance();
    $db->exec('BEGIN TRANSACTION');
    try {
        DB::execute(
            'INSERT INTO reader_groups (name, description) VALUES (:p0, :p1)',
            [$name, $description]
        );
        $groupId = DB::lastInsertId();
        readerGroupReplaceGames($groupId, $gameIds);
        $db->exec('COMMIT');
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    DB::auditLog('admin', 'reader_group_created', Auth::userId(), [
        'reader_group_id' => $groupId,
        'name'            => $name,
        'games'           => count($gameIds),
    ]);

    http_response_code(201);
    readerGroupGet($groupId);
}

function readerGroupUpdate(int $groupId, ?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $existing = DB::queryOne('SELECT id FROM reader_groups WHERE id = :p0', [$groupId]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Reader group not found']);
        return;
    }

    $name = Validator::requireString($input, 'name', 100);
    $description = Validator::optionalString($input, 'description', 500);
    $gameIds = readerGroupGameIds($input);

    $db = DB::getInstance();
    $db->exec('BEGIN TRANSACTION');
    try {
        DB::execute(
            'UPDATE reader_groups SET name = :p0, description = :p1, updated_at = datetime(\'now\') WHERE id = :p2',
            [$name, $description, $groupId]
        );
        DB::execute('DELETE FROM reader_group_games WHERE reader_group_id = :p0', [$groupId]);
        readerGroupReplaceGames($groupId, $gameIds);
        $db->exec('COMMIT');
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    DB::auditLog('admin', 'reader_group_updated', Auth::userId(), [
        'reader_group_id' => $groupId,
        'name'            => $name,
        'games'           => count($gameIds),
    ]);

    readerGroupGet($groupId);
}

function readerGroupDelete(int $groupId): void {
    $group = DB::queryOne('SELECT id, name FROM reader_groups WHERE id = :p0', [$groupId]);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Reader group not found']);
        return;
    }

    // reader_group_games rows cascade.
    DB::execute('DELETE FROM reader_groups WHERE id = :p0', [$groupId]);

    DB::auditLog('admin', 'reader_group_deleted', Auth::userId(), [
        'reader_group_id' => $groupId,
        'name'            => $group['name'],
    ]);

    echo json_encode(['success' => true, 'deleted_id' => $groupId]);
}

/** Insert membership rows, caching the current game name for display. */
function readerGroupReplaceGames(int $groupId, array $gameIds): void {
    if (empty($gameIds)) return;
    $names = [];
    foreach (DB::query('SELECT game_id, game_name FROM game_state_cache') as $r) {
        $names[(string)$r['game_id']] = (string)$r['game_name'];
    }
    foreach ($gameIds as $gid) {
        DB::execute(
            'INSERT OR IGNORE INTO reader_group_games (reader_group_id, game_id, game_name) VALUES (:p0, :p1, :p2)',
            [$groupId, $gid, $names[$gid] ?? '']
        );
    }
}
