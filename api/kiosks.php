<?php
/**
 * API: Kiosk listing and pause/unpause control.
 *
 * GET    /api/kiosks                   — List kiosks (cache-first; auto-syncs if empty)
 * GET    /api/kiosks/{id}              — Single kiosk (live from CenterEdge)
 * POST   /api/kiosks/sync              — Force resync from CenterEdge
 * POST   /api/kiosks/{id}/pause        — Set operationStatus = paused
 * POST   /api/kiosks/{id}/unpause      — Set operationStatus = enabled
 * POST   /api/kiosks/{id}/out-of-service — Set operationStatus = outOfService
 * PATCH  /api/kiosks                   — Bulk JSON Patch passthrough (multi-kiosk)
 * POST   /api/kiosks/{id}/action       — RPC perform-action (e.g. "reboot")
 */

require_once __DIR__ . '/../lib/centeredge_client.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/scheduler.php';

function handleKiosks(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    // Kiosk reads serve the Kiosks page and the Pause Groups editor
    // (kiosk member picker).
    if ($method === 'GET') {
        Auth::requireAnyAccess(['view_kiosks', 'view_groups', 'groups_manage']);
    }

    $kioskId = $parts[0] ?? null;
    $action  = $parts[1] ?? null;

    // Every mutating kiosk operation requires manual_control — all three
    // system roles hold it; the gate exists for restricted custom roles.
    // POST /api/kiosks/sync
    if ($method === 'POST' && $kioskId === 'sync' && $action === null) {
        Auth::requireAccess('manual_control');
        kioskSync();
        return;
    }

    // POST /api/kiosks/{id}/pause | /unpause | /out-of-service
    if ($method === 'POST' && $kioskId !== null && in_array($action, ['pause', 'unpause', 'out-of-service'], true)) {
        Auth::requireAccess('manual_control');
        $statusMap = [
            'pause' => 'paused',
            'unpause' => 'enabled',
            'out-of-service' => 'outOfService',
        ];
        kioskSetStatus($kioskId, $statusMap[$action], $action);
        return;
    }

    // POST /api/kiosks/{id}/action
    if ($method === 'POST' && $kioskId !== null && $action === 'action') {
        Auth::requireAccess('manual_control');
        kioskPerformAction($kioskId, $input);
        return;
    }

    switch ($method) {
        case 'GET':
            if ($kioskId !== null) {
                kioskGetOne($kioskId);
            } else {
                kioskList();
            }
            break;
        case 'PATCH':
            Auth::requireAccess('manual_control');
            if ($kioskId !== null) {
                http_response_code(405);
                echo json_encode(['error' => 'PATCH a single kiosk is not supported. Use PATCH /api/kiosks with the bulk format, or POST /api/kiosks/{id}/pause.']);
                return;
            }
            kioskPatchBulk($input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

/**
 * Return cached kiosks. Auto-syncs once if the cache is empty so the
 * first page-load works without requiring the operator to click "Sync".
 */
function kioskList(): void {
    $cached = DB::query(
        'SELECT kiosk_id, kiosk_name, operation_status, categories, supported_actions, last_synced_at
         FROM kiosk_state_cache ORDER BY kiosk_name ASC'
    );

    if (empty($cached)) {
        try {
            $client = new CenterEdgeClient();
            if ($client->isConfigured()) {
                $client->syncKiosksToCache();
                $cached = DB::query(
                    'SELECT kiosk_id, kiosk_name, operation_status, categories, supported_actions, last_synced_at
                     FROM kiosk_state_cache ORDER BY kiosk_name ASC'
                );
            }
        } catch (Exception $e) {
            // Card system may not support /kiosks at all — return empty list.
        }
    }

    $pendingRetries = Scheduler::getPendingRetriesByType('kiosk');

    $kiosks = array_map(function ($k) use ($pendingRetries) {
        return [
            'id' => $k['kiosk_id'],
            'name' => $k['kiosk_name'],
            'operationStatus' => $k['operation_status'] !== '' ? $k['operation_status'] : null,
            'categories' => json_decode($k['categories'] ?? '[]', true) ?: [],
            'supportedActions' => json_decode($k['supported_actions'] ?? '[]', true) ?: [],
            'last_synced_at' => $k['last_synced_at'],
            'pending_retry' => $pendingRetries[$k['kiosk_id']] ?? null,
        ];
    }, $cached);

    $lastSync = DB::queryOne('SELECT MAX(last_synced_at) as last_synced FROM kiosk_state_cache');

    echo json_encode([
        'kiosks' => $kiosks,
        'totalCount' => count($kiosks),
        'last_synced' => $lastSync['last_synced'] ?? null,
    ]);
}

/**
 * Live single-kiosk lookup. Bypasses the cache so the latest state is
 * always returned — useful for checking after a pause action.
 */
function kioskGetOne(string $kioskId): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    try {
        $kiosk = $client->getKiosk($kioskId);
        echo json_encode($kiosk);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        $code = strpos($msg, 'HTTP 404') !== false ? 404 : 500;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($msg)]);
    }
}

/**
 * Pause / unpause / out-of-service a single kiosk by ID.
 */
function kioskSetStatus(string $kioskId, string $status, string $actionLabel): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    try {
        $result = $client->patchKiosks([$kioskId => $status]);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        return;
    }

    // The API returns successful kiosks under "kiosks" and per-id failures
    // under "errors". A successful no-op is still in the kiosks array.
    $errors = $result['errors'] ?? [];
    $errForThis = $errors[$kioskId] ?? null;

    if ($errForThis) {
        $userId = Auth::check()['id'] ?? null;
        $errorText = is_array($errForThis) ? ($errForThis['message'] ?? json_encode($errForThis)) : (string)$errForThis;
        DB::auditLog('kiosk-' . $actionLabel, 'kiosk_action_failed', $userId, [
            'kiosk_id' => $kioskId,
            'requested_status' => $status,
            'error' => $errForThis,
        ], false);

        // Queue a retry so the watchdog re-attempts this every minute (up to
        // max_attempts). The kiosk was likely in use when we tried to act.
        Scheduler::queueRetry('kiosk', $kioskId, $status, 'manual', null, $errorText);
        $pending = Scheduler::getPendingRetry('kiosk', $kioskId);

        http_response_code(422);
        echo json_encode([
            'success' => false,
            'kiosk_id' => $kioskId,
            'error' => $errorText,
            'pending_retry' => $pending,
        ]);
        return;
    }

    // Update local cache so the UI reflects the new state immediately.
    DB::execute(
        'UPDATE kiosk_state_cache SET operation_status = :p0, last_synced_at = datetime(\'now\') WHERE kiosk_id = :p1',
        [$status, $kioskId]
    );

    // Drop any stale pending retry for this kiosk — the operator's manual
    // action just succeeded, so older retry intent is moot.
    Scheduler::clearRetry('kiosk', $kioskId);

    $updated = null;
    foreach (($result['kiosks'] ?? []) as $k) {
        if (($k['id'] ?? null) == $kioskId) {
            $updated = $k;
            break;
        }
    }

    $userId = Auth::check()['id'] ?? null;
    DB::auditLog('kiosk-' . $actionLabel, 'kiosk_status_changed', $userId, [
        'kiosk_id' => $kioskId,
        'kiosk_name' => $updated['name'] ?? null,
        'new_status' => $status,
    ]);

    echo json_encode([
        'success' => true,
        'kiosk_id' => $kioskId,
        'operationStatus' => $status,
        'kiosk' => $updated,
    ]);
}

/**
 * Bulk-patch passthrough. Accepts the same body shape as the upstream
 * CenterEdge endpoint:
 *   { "kiosks": { "<kioskId>": [ {"op":"replace","path":"/operationStatus","value":"paused"} ] } }
 *
 * We only allow operationStatus replace ops here — other JSON Patch ops
 * aren't useful from this admin UI and could surprise operators.
 */
function kioskPatchBulk(?array $input): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    $kiosksPayload = $input['kiosks'] ?? null;
    if (!is_array($kiosksPayload) || empty($kiosksPayload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body must include a non-empty "kiosks" object.']);
        return;
    }

    $allowed = ['enabled', 'paused', 'outOfService'];
    $changes = [];
    foreach ($kiosksPayload as $kioskId => $patches) {
        if (!is_array($patches)) {
            http_response_code(400);
            echo json_encode(['error' => "Patches for kiosk '$kioskId' must be an array."]);
            return;
        }
        foreach ($patches as $patch) {
            if (($patch['op'] ?? '') !== 'replace' || ($patch['path'] ?? '') !== '/operationStatus') {
                http_response_code(400);
                echo json_encode(['error' => 'Only {op:"replace", path:"/operationStatus"} patches are supported.']);
                return;
            }
            $value = $patch['value'] ?? null;
            if (!in_array($value, $allowed, true)) {
                http_response_code(400);
                echo json_encode(['error' => "Invalid operationStatus '$value' for kiosk '$kioskId'."]);
                return;
            }
            $changes[(string)$kioskId] = $value;
        }
    }

    try {
        $result = $client->patchKiosks($changes);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        return;
    }

    // Reflect successful changes in the local cache, and update the retry
    // queue: clear retries for kiosks that succeeded, queue/refresh for those
    // that failed (the watchdog will re-attempt).
    foreach ($changes as $kioskId => $status) {
        if (!isset($result['errors'][$kioskId])) {
            DB::execute(
                'UPDATE kiosk_state_cache SET operation_status = :p0, last_synced_at = datetime(\'now\') WHERE kiosk_id = :p1',
                [$status, $kioskId]
            );
            Scheduler::clearRetry('kiosk', (string)$kioskId);
        } else {
            $err = $result['errors'][$kioskId];
            $errorText = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
            Scheduler::queueRetry('kiosk', (string)$kioskId, $status, 'manual', null, $errorText);
        }
    }

    $userId = Auth::check()['id'] ?? null;
    DB::auditLog('kiosk-patch', 'kiosk_bulk_patch', $userId, [
        'changes' => $changes,
        'errors' => $result['errors'] ?? [],
    ]);

    echo json_encode($result);
}

/**
 * Force-sync from CenterEdge.
 */
function kioskSync(): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }
    try {
        $count = $client->syncKiosksToCache();
        // Operator-initiated sync also drops the capabilities cache so the
        // next kiosks-page load picks up any new supported actions.
        CenterEdgeClient::invalidateCapabilitiesCache();
        echo json_encode([
            'success' => true,
            'kiosk_count' => $count,
            'synced_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}

/**
 * RPC perform-action passthrough (e.g. reboot a kiosk).
 * Body: { "actionId": "reboot", "operator": { ... } }
 *
 * If operator is omitted, we synthesise one from the logged-in admin user
 * so the front-end can call this without rebuilding the operator object.
 */
function kioskPerformAction(string $kioskId, ?array $input): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body required']);
        return;
    }

    $actionId = Validator::requireString($input, 'actionId');

    $operator = $input['operator'] ?? null;
    if (!is_array($operator) || empty($operator)) {
        $user = Auth::check();
        $operator = [
            'id' => (string)($user['id'] ?? 'web'),
            'name' => $user['display_name'] ?? ($user['username'] ?? 'web'),
        ];
    }

    try {
        $result = $client->performKioskAction($kioskId, $actionId, $operator);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        return;
    }

    $userId = Auth::check()['id'] ?? null;
    DB::auditLog('kiosk-action', 'kiosk_action_performed', $userId, [
        'kiosk_id' => $kioskId,
        'action_id' => $actionId,
    ]);

    echo json_encode($result);
}
