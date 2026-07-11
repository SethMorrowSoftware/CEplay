<?php
/**
 * API: Card lookup, transaction history, and PIN probe/validate.
 * Read-only proxy to the CenterEdge Card System for floor-staff use.
 *
 * GET /api/cards/{cardNumber}                  — Single card (balance, time plays, privileges)
 * GET /api/cards/{cardNumber}/transactions     — Card transaction history (paginated)
 * GET /api/cards/{cardNumber}/pin?validate=...  — Probe / validate PIN on a card
 *
 * No mutations are exposed here yet. Adjustments, wipes, combines, and
 * bulk-issue all require operator audit captures and are deferred until
 * the next iteration.
 */

require_once __DIR__ . '/../lib/centeredge_client.php';
require_once __DIR__ . '/../lib/validator.php';

/**
 * Attach human-readable groupName to each time play / privilege on a card
 * payload, resolved from the cached /timePlayGroups and /privilegeGroups
 * lists (e.g. "Go-Kart Ride" instead of "group 7"). Strictly best-effort
 * and capability-gated: names are cosmetic, so any upstream failure leaves
 * the payload exactly as CenterEdge returned it.
 */
function cardsResolveGroupNames(CenterEdgeClient $client, array &$card): void {
    try {
        $caps = $client->getCapabilitiesCached();

        if (!empty($card['timePlays']) && is_array($card['timePlays']) && isset($caps['timePlay'])) {
            $names = [];
            foreach ($client->getTimePlayGroupsCached() as $g) {
                if (is_array($g) && isset($g['id'])) {
                    $names[(string)$g['id']] = trim((string)($g['name'] ?? ''));
                }
            }
            foreach ($card['timePlays'] as &$tp) {
                $gid = isset($tp['groupId']) ? (string)$tp['groupId'] : '';
                if ($gid !== '' && ($names[$gid] ?? '') !== '') {
                    $tp['groupName'] = $names[$gid];
                }
            }
            unset($tp);
        }

        if (!empty($card['privileges']) && is_array($card['privileges']) && !empty($caps['privileges']['isSupported'])) {
            $names = [];
            foreach ($client->getPrivilegeGroupsCached() as $g) {
                if (is_array($g) && isset($g['id'])) {
                    $names[(string)$g['id']] = trim((string)($g['name'] ?? ''));
                }
            }
            foreach ($card['privileges'] as &$pv) {
                $gid = isset($pv['groupId']) ? (string)$pv['groupId'] : '';
                if ($gid !== '' && ($names[$gid] ?? '') !== '') {
                    $pv['groupName'] = $names[$gid];
                }
            }
            unset($pv);
        }
    } catch (Exception $e) {
        // Group names are decoration — never fail the lookup over them.
    }
}

function handleCards(string $method, array $parts, ?array $input): void {
    // Card lookup exposes balances, transactions, and PIN data — sales-side
    // information the 'tech' role is not permitted to see.
    Auth::requireAccess('cards');

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $cardNumber = $parts[0] ?? null;
    if ($cardNumber === null || $cardNumber === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Card number required']);
        return;
    }

    // Card numbers in the spec are 6–20 chars; strip whitespace and reject
    // obviously bogus input before calling upstream.
    $cardNumber = trim($cardNumber);
    if (!preg_match('/^[A-Za-z0-9]{1,32}$/', $cardNumber)) {
        http_response_code(400);
        echo json_encode(['error' => 'Card number must be alphanumeric (max 32 chars).']);
        return;
    }

    $sub = $parts[1] ?? null;

    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured.']);
        return;
    }

    try {
        if ($sub === null) {
            $card = $client->getCard($cardNumber);
            cardsResolveGroupNames($client, $card);
            echo json_encode($card);
            return;
        }

        if ($sub === 'transactions') {
            $skip = max(0, (int)($_GET['skip'] ?? 0));
            $take = min(200, max(1, (int)($_GET['take'] ?? 50)));
            $result = $client->getCardTransactions($cardNumber, $skip, $take);
            echo json_encode($result);
            return;
        }

        if ($sub === 'pin') {
            $validate = $_GET['validate'] ?? null;
            $actorId = Auth::userId();

            // Rate-limit PIN validation attempts (the brute-forceable path).
            // Counting off the audit trail keeps this self-contained: every
            // attempt below is logged with source 'card-pin', so the log IS
            // the sliding window. 15 attempts / 10 minutes per user is ample
            // for floor staff helping guests and useless for brute force.
            if ($validate !== null) {
                $cutoff = gmdate('Y-m-d H:i:s', time() - 600);
                $row = DB::queryOne(
                    'SELECT COUNT(*) AS c FROM action_log
                     WHERE source = :p0 AND action = :p1 AND timestamp > :p2
                       AND details LIKE :p3',
                    ['card-pin', 'pin_validate_attempt', $cutoff,
                     '%"actor_user_id":' . (int)$actorId . '}']
                );
                if ((int)($row['c'] ?? 0) >= 15) {
                    DB::auditLog('card-pin', 'pin_validate_rate_limited', $actorId, [
                        'card' => $cardNumber,
                    ], false);
                    http_response_code(429);
                    header('Retry-After: 600');
                    echo json_encode(['error' => 'Too many PIN validation attempts. Please wait a few minutes and try again.']);
                    return;
                }
            }

            // Per spec, the pin endpoint returns 404 for unknown cards or cards
            // without PIN. Surface that gracefully so the UI can distinguish
            // "no PIN" from "wrong PIN".
            try {
                $result = $client->getCardPin($cardNumber, $validate !== null ? (string)$validate : null);
            } finally {
                // Audit every probe/validate — PIN checks are sensitive enough
                // to leave a trail even when the upstream call fails.
                DB::auditLog(
                    'card-pin',
                    $validate !== null ? 'pin_validate_attempt' : 'pin_probed',
                    $actorId,
                    ['card' => $cardNumber]
                );
            }
            echo json_encode($result);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Unknown card sub-resource: ' . $sub]);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        $code = 500;
        $payload = ['error' => sanitizeApiError($msg)];

        if (strpos($msg, 'HTTP 404') !== false) {
            $code = 404;
            // Use a friendlier error for pin-not-found vs card-not-found so
            // the UI can branch.
            if ($sub === 'pin') {
                $payload = ['error' => 'PIN not found', 'code' => 'pinNotFound'];
            } else {
                $payload = ['error' => 'Card not found', 'code' => 'cardNotFound'];
            }
        } elseif (strpos($msg, 'HTTP 400') !== false) {
            $code = 400;
        }

        http_response_code($code);
        echo json_encode($payload);
    }
}
