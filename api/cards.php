<?php
/**
 * Cards API handler — lookup, create, wipe, combine, PIN validation, bulk issue.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/centeredge_client.php';
require_once __DIR__ . '/../lib/validator.php';

function handleCards(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $client = new CenterEdgeClient();
    if (!$client->isConfigured()) {
        http_response_code(400);
        echo json_encode(['error' => 'CenterEdge API is not configured. Please set up API credentials in Settings.']);
        return;
    }

    $subResource = $parts[0] ?? '';

    // POST /api/cards/bulk-issue
    if ($subResource === 'bulk-issue' && $method === 'POST') {
        handleBulkIssue($client, $input);
        return;
    }

    // POST /api/cards/combine
    if ($subResource === 'combine' && $method === 'POST') {
        handleCombine($client, $input);
        return;
    }

    // Card number based routes: /api/cards/{cardNumber}/...
    if ($subResource !== '' && $subResource !== 'bulk-issue' && $subResource !== 'combine') {
        $cardNumber = $subResource;
        $action = $parts[1] ?? '';

        switch ($action) {
            case '':
                if ($method === 'GET') {
                    handleGetCard($client, $cardNumber);
                } elseif ($method === 'POST') {
                    handleCreateCard($client, $cardNumber, $input);
                } elseif ($method === 'DELETE') {
                    handleWipeCard($client, $cardNumber, $input);
                } else {
                    http_response_code(405);
                    echo json_encode(['error' => 'Method not allowed']);
                }
                return;

            case 'pin':
                if ($method === 'GET') {
                    handleValidatePin($client, $cardNumber);
                } else {
                    http_response_code(405);
                    echo json_encode(['error' => 'Method not allowed']);
                }
                return;

            case 'transactions':
                $transactionId = $parts[2] ?? null;
                if ($method === 'GET' && $transactionId) {
                    handleGetCardTransaction($client, $cardNumber, (int)$transactionId);
                } elseif ($method === 'GET') {
                    handleGetCardTransactions($client, $cardNumber);
                } elseif ($method === 'POST') {
                    handleCreateCardTransaction($client, $cardNumber, $input);
                } else {
                    http_response_code(405);
                    echo json_encode(['error' => 'Method not allowed']);
                }
                return;

            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown card action']);
                return;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Card number is required']);
}

function handleGetCard(CenterEdgeClient $client, string $cardNumber): void {
    try {
        $card = $client->getCard($cardNumber);
        echo json_encode($card);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'HTTP 404') !== false) {
            http_response_code(404);
            echo json_encode(['error' => 'Card not found']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        }
    }
}

function handleCreateCard(CenterEdgeClient $client, string $cardNumber, ?array $input): void {
    $operator = buildOperator();

    $customer = null;
    if ($input && isset($input['customer'])) {
        $customer = $input['customer'];
    }

    try {
        $card = $client->createEmptyCard($cardNumber, $operator, $customer);
        http_response_code(201);
        echo json_encode($card);
    } catch (RuntimeException $e) {
        $code = strpos($e->getMessage(), 'HTTP 409') !== false ? 409 : 500;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}

function handleWipeCard(CenterEdgeClient $client, string $cardNumber, ?array $input): void {
    $operator = buildOperator();

    try {
        $result = $client->wipeCard($cardNumber, $operator);
        echo json_encode($result);
    } catch (RuntimeException $e) {
        $code = strpos($e->getMessage(), 'HTTP 405') !== false ? 405 : 500;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}

function handleValidatePin(CenterEdgeClient $client, string $cardNumber): void {
    $pin = $_GET['validate'] ?? null;

    try {
        $result = $client->validateCardPin($cardNumber, $pin);
        echo json_encode($result);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'HTTP 404') !== false) {
            http_response_code(404);
            echo json_encode(['error' => 'PIN not found for this card']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        }
    }
}

function handleGetCardTransactions(CenterEdgeClient $client, string $cardNumber): void {
    $skip = (int)($_GET['skip'] ?? 0);
    $take = (int)($_GET['take'] ?? 50);
    $take = max(1, min(100, $take));

    try {
        $result = $client->getCardTransactions($cardNumber, $skip, $take);
        echo json_encode($result);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'HTTP 404') !== false) {
            http_response_code(404);
            echo json_encode(['error' => 'Card not found']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        }
    }
}

function handleGetCardTransaction(CenterEdgeClient $client, string $cardNumber, int $transactionId): void {
    try {
        $result = $client->getCardTransaction($cardNumber, $transactionId);
        echo json_encode($result);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'HTTP 404') !== false) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
        }
    }
}

function handleCreateCardTransaction(CenterEdgeClient $client, string $cardNumber, ?array $input): void {
    if (!$input || !isset($input['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction type is required']);
        return;
    }

    // Inject operator if not provided
    if (!isset($input['operator'])) {
        $input['operator'] = buildOperator();
    }

    try {
        $result = $client->createCardTransaction($cardNumber, $input);
        http_response_code(201);
        echo json_encode($result);
    } catch (RuntimeException $e) {
        $code = 500;
        if (strpos($e->getMessage(), 'HTTP 400') !== false) $code = 400;
        if (strpos($e->getMessage(), 'HTTP 404') !== false) $code = 404;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}

function handleBulkIssue(CenterEdgeClient $client, ?array $input): void {
    if (!$input || !isset($input['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Bulk issue type is required (list or range)']);
        return;
    }

    if (!isset($input['adjustments']) || !is_array($input['adjustments'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Adjustments array is required']);
        return;
    }

    // Inject operator
    if (!isset($input['operator'])) {
        $input['operator'] = buildOperator();
    }

    try {
        $result = $client->bulkIssueCards($input);
        echo json_encode(['success' => true, 'cards' => $result]);
    } catch (RuntimeException $e) {
        $code = 500;
        if (strpos($e->getMessage(), 'HTTP 400') !== false) $code = 400;
        if (strpos($e->getMessage(), 'HTTP 409') !== false) $code = 409;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}

function handleCombine(CenterEdgeClient $client, ?array $input): void {
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Request body is required']);
        return;
    }

    $destination = $input['destinationCardNumber'] ?? '';
    $source = $input['sourceCardNumber'] ?? '';

    if (!$destination || !$source) {
        http_response_code(400);
        echo json_encode(['error' => 'Both destinationCardNumber and sourceCardNumber are required']);
        return;
    }

    $operator = buildOperator();

    try {
        $result = $client->combineCards($destination, $source, $operator);
        echo json_encode($result);
    } catch (RuntimeException $e) {
        $code = 500;
        if (strpos($e->getMessage(), 'HTTP 400') !== false) $code = 400;
        http_response_code($code);
        echo json_encode(['error' => sanitizeApiError($e->getMessage())]);
    }
}

/**
 * Build operator object from current session.
 */
function buildOperator(): array {
    $user = Auth::requireAuth();
    return [
        'employeeName' => $user['display_name'] ?? $user['username'] ?? 'System',
        'stationName' => 'PauseGroupsUI',
    ];
}

