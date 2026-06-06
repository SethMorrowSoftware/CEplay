<?php
/**
 * API: Kiosk listing and pause/unpause control.
 *
 * GET    /api/kiosks                   — List kiosks (cache-first; auto-syncs if empty)
 * GET    /api/kiosks/{id}              — Single kiosk (live from CenterEdge)
 * POST   /api/kiosks/sync              — Force resync from CenterEdge
 * POST   /api/kiosks/unpause-all       — Unpause every paused kiosk (skips OOS/unknown)
 * POST   /api/kiosks/pause-all         — Pause every enabled kiosk (skips OOS/unknown)
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

    $kioskId = $parts[0] ?? null;
    $action  = $parts[1] ?? null;

    // POST /api/kiosks/sync
    if ($method === 'POST' && $kioskId === 'sync' && $action === null) {
        kioskSync();
        return;
    }

    // POST /api/kiosks/unpause-all | /pause-all — bulk flip every kiosk in one shot
    if ($method === 'POST' && $kioskId === 'unpause-all' && $action === null) {
        kioskBulkAll('unpause');
        return;
    }
    if ($method === 'POST' && $kioskId === 'pause-all' && $action === null) {
        kioskBulkAll('pause');
        return;
    }

    // POST /api/kiosks/{id}/pause | /unpause | /out-of-service
    if ($method === 'POST' && $kioskId !== null && in_array($action, ['pause', 'unpause', 'out-of-service'], true)) {
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

    // Manual click is a fresh intent — wipe any prior retry/give-up state
    // so a failure here starts a clean 10-attempt window.
    Scheduler::clearRetry('kiosk', $kioskId);

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
        ]);

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
        if (isset($k['id']) && (string)$k['id'] === (string)$kioskId) {
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
        $result = kioskApplyStatusChanges($client, $changes);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        return;
    }

    $userId = Auth::check()['id'] ?? null;
    DB::auditLog('kiosk-patch', 'kiosk_bulk_patch', $userId, [
        'changes' => $changes,
        'errors' => $result['errors'] ?? [],
    ]);

    echo json_encode($result);
}

/**
 * Apply a set of kiosk operationStatus changes through the CenterEdge client,
 * using the exact same retry semantics as every other manual kiosk action:
 *   - clear any prior retry/give-up state first (fresh intent → clean window)
 *   - PATCH the changes upstream
 *   - on per-kiosk success, reflect the new state in the local cache
 *   - on per-kiosk failure, queue a retry so the watchdog re-attempts it (up
 *     to max_attempts) exactly like a single pause/unpause click would.
 *
 * $changes = ['kioskId' => 'enabled'|'paused'|'outOfService', ...]
 * Returns the raw CenterEdge result: ['kiosks' => [...], 'errors' => [...]].
 * Throws RuntimeException if the bulk PATCH itself fails (network/4xx/5xx).
 */
function kioskApplyStatusChanges(CenterEdgeClient $client, array $changes): array {
    foreach ($changes as $kioskId => $_status) {
        Scheduler::clearRetry('kiosk', (string)$kioskId);
    }

    $result = $client->patchKiosks($changes);

    // Confirmed successes = kiosk ids the API echoed back in the "kiosks" array.
    // Anything not confirmed — whether it errored OR was silently dropped from
    // the response — is treated as a failure and queued for retry, matching the
    // reconciliation the watchdog's processRetries()/applyRetryResult() uses.
    $errors = $result['errors'] ?? [];
    $succeeded = [];
    foreach (($result['kiosks'] ?? []) as $k) {
        $kid = (string)($k['id'] ?? '');
        if ($kid !== '') {
            $succeeded[$kid] = true;
        }
    }

    foreach ($changes as $kioskId => $status) {
        $kid = (string)$kioskId;
        $hasError = isset($errors[$kioskId]) || isset($errors[$kid]);
        if (!$hasError && isset($succeeded[$kid])) {
            DB::execute(
                'UPDATE kiosk_state_cache SET operation_status = :p0, last_synced_at = datetime(\'now\') WHERE kiosk_id = :p1',
                [$status, $kid]
            );
        } else {
            $err = $errors[$kioskId] ?? ($errors[$kid] ?? 'no response from server');
            $errorText = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
            Scheduler::queueRetry('kiosk', $kid, $status, 'manual', null, $errorText);
        }
    }

    return $result;
}

/**
 * Bulk pause or unpause every eligible kiosk in one action (the remote-control
 * "Pause / Unpause All Kiosks" buttons).
 *
 * $action = 'unpause' (flip every paused kiosk to enabled) or
 *           'pause'   (flip every enabled kiosk to paused).
 *
 * Syncs the kiosk cache if stale so we act on the true current state. Kiosks
 * that are outOfService — or report no operationStatus ("unknown"), which per
 * the API spec MUST NOT be pause-controlled — are skipped. Per-kiosk failures
 * are routed through the shared retry path (kioskApplyStatusChanges), so a busy
 * kiosk is re-attempted by the watchdog just like a single manual action.
 */
function kioskBulkAll(string $action): void {
    $fromStatus = $action === 'pause' ? 'enabled' : 'paused';
    $toStatus   = $action === 'pause' ? 'paused'  : 'enabled';

    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    // Refresh state if the cache is stale, reusing the project's freshness
    // contract (~30s, like the main app's manual buttons) so a button press
    // doesn't force a full CenterEdge re-fetch every time. syncKioskStatesIfStale
    // also swallows the "no /kiosks endpoint" case, so venues without kiosks
    // just see an empty list instead of an error.
    Scheduler::syncKioskStatesIfStale(30);

    $rows = DB::query('SELECT kiosk_id, kiosk_name, operation_status FROM kiosk_state_cache');
    $total = count($rows);
    $changes = [];
    $already = 0;        // already in the target state
    $outOfService = 0;
    $unknown = 0;
    foreach ($rows as $r) {
        $st = $r['operation_status'];
        if ($st === $fromStatus) {
            $changes[(string)$r['kiosk_id']] = $toStatus;
        } elseif ($st === 'outOfService') {
            $outOfService++;
        } elseif ($st === '' || $st === null) {
            $unknown++; // unknown status; must not control
        } else {
            $already++; // already in $toStatus
        }
    }

    $result = ['kiosks' => [], 'errors' => []];
    $patchError = null;
    if (!empty($changes)) {
        try {
            $result = kioskApplyStatusChanges($client, $changes);
        } catch (RuntimeException $e) {
            $patchError = sanitizeApiError($e->getMessage());
        }
    }

    $errors = $result['errors'] ?? [];

    // Failures = changed kiosks that ended up with a pending retry (errored or
    // silently dropped). Successes cleared their retry row inside the helper, so
    // whatever is still pending is a real failure the watchdog will keep chasing.
    $pending = Scheduler::getPendingRetriesByType('kiosk');
    $retrying = [];
    foreach (array_keys($changes) as $kid) {
        if (isset($pending[$kid])) {
            $retrying[] = [
                'id'           => (string)$kid,
                'attempts'     => $pending[$kid]['attempts'],
                'max_attempts' => $pending[$kid]['max_attempts'],
                'last_error'   => $pending[$kid]['last_error'],
            ];
        }
    }
    $failed = count($retrying);
    $changed = $patchError === null ? max(0, count($changes) - $failed) : 0;
    $success = ($patchError === null && $failed === 0);

    $userId = Auth::check()['id'] ?? null;
    DB::auditLog('kiosk-' . $action . '-all', 'kiosks_' . $action . '_all', $userId, [
        'total'          => $total,
        'attempted'      => count($changes),
        'changed'        => $changed,
        'failed'         => $failed,
        'already'        => $already,
        'out_of_service' => $outOfService,
        'unknown'        => $unknown,
    ], $success, $success ? null : ($patchError ?? ($failed . ' kiosk(s) failed; queued for retry')));

    if ($patchError !== null) {
        http_response_code(502);
    }
    echo json_encode([
        'success'        => $success,
        'asset'          => 'kiosk',
        'action'         => $action,
        'total'          => $total,
        'attempted'      => count($changes),
        'changed'        => $changed,
        'failed'         => $failed,
        'already'        => $already,
        'out_of_service' => $outOfService,
        'unknown'        => $unknown,
        'retrying'       => $retrying,
        'errors'         => $errors,
        'error'          => $patchError,   // non-null only on a hard PATCH failure
        'sync_warning'   => null,          // kiosk sync failures are non-fatal here
    ]);
}

/**
 * Force-sync from CenterEdge.
 */
function kioskSync(): void {
    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        DB::auditLog('kiosk-sync', 'kiosks_sync_triggered', null,
            [], false, 'CenterEdge API is not configured');
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }
    try {
        $count = $client->syncKiosksToCache();
        DB::auditLog('kiosk-sync', 'kiosks_sync_triggered', null, [
            'kiosk_count' => $count,
        ]);
        echo json_encode([
            'success' => true,
            'kiosk_count' => $count,
            'synced_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (RuntimeException $e) {
        DB::auditLog('kiosk-sync', 'kiosks_sync_triggered', null,
            [], false, $e->getMessage());
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
        // Match the Operator schema CenterEdge expects (employeeName /
        // employeeNumber / stationName) — same shape as gamesPerformAction.
        // The earlier {id, name} shape was rejected as a 400 by the API.
        $user = Auth::check();
        $operator = [
            'employeeName'   => $user['display_name'] ?? ($user['username'] ?? 'web'),
            'employeeNumber' => (int)($user['id'] ?? 0),
            'stationName'    => 'CEplay Web',
        ];
    }

    try {
        $result = $client->performKioskAction($kioskId, $actionId, $operator);
    } catch (RuntimeException $e) {
        DB::auditLog('kiosk-action', 'kiosk_action_performed', null, [
            'kiosk_id' => $kioskId,
            'action_id' => $actionId,
        ], false, $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        return;
    }

    DB::auditLog('kiosk-action', 'kiosk_action_performed', null, [
        'kiosk_id' => $kioskId,
        'action_id' => $actionId,
    ]);

    echo json_encode($result);
}
