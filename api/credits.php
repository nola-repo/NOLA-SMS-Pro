<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

validate_api_request();

$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST') {
        $json = file_get_contents('php://input');
        $payload = json_decode($json, true) ?? [];
        
        $action = $payload['action'] ?? ''; // 'add' or 'deduct'
        $amount = (int)($payload['amount'] ?? 0);
        $reference = $payload['reference'] ?? 'api_update';
        $description = $payload['description'] ?? 'Balance updated via API';
        
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid amount'], JSON_PRETTY_PRINT);
            exit;
        }

        require_once __DIR__ . '/services/CreditManager.php';
        $creditManager = new CreditManager();
        $locId = get_ghl_location_id();
        $accountId = $locId ?: 'default';

        if ($action === 'add') {
            $newBalance = $creditManager->add_credits($accountId, $amount, $reference, $description);
        } elseif ($action === 'deduct') {
            $newBalance = $creditManager->deduct_credits($accountId, $amount, $reference, $description);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action. Use "add" or "deduct".'], JSON_PRETTY_PRINT);
            exit;
        }

        echo json_encode([
            'success' => true,
            'account_id' => $accountId,
            'action' => $action,
            'amount' => $amount,
            'new_balance' => $newBalance,
            'message' => "Successfully updated balance for account $accountId."
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Default GET logic
    $locId = get_ghl_location_id();
    $accountId = $locId ?: 'default';
    $docRef = $db->collection('accounts')->document($accountId);
    $snapshot = $docRef->snapshot();

    if ($snapshot->exists()) {
        $data = $snapshot->data();
        $creditBalance = (int)($data['credit_balance'] ?? 0);
        $currency = $data['currency'] ?? 'PHP';
        $updatedAt = isset($data['updated_at']) ? $data['updated_at']->formatAsString() : null;
        $createdAt = isset($data['created_at']) ? $data['created_at']->formatAsString() : null;
    }
    else {
        // Initialize with zero balance if not present
        $now = new DateTimeImmutable();
        $creditBalance = 0;
        $currency = 'PHP';
        $docRef->set([
            'credit_balance' => $creditBalance,
            'currency' => $currency,
            'created_at' => new \Google\Cloud\Core\Timestamp($now),
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ]);
        $createdAt = $now->format(DATE_ATOM);
        $updatedAt = $createdAt;
    }

    echo json_encode([
        'success' => true,
        'account_id' => $accountId,
        'credit_balance' => $creditBalance,
        'currency' => $currency,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ], JSON_PRETTY_PRINT);
}
catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch credit balance',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
