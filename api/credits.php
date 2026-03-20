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
        // Support both JSON body (Postman/API) and form-encoded data (GHL Webhooks)
        $json = file_get_contents('php://input');
        $jsonPayload = json_decode($json, true) ?? [];
        $formPayload = $_POST ?? [];
        
        // Merge payloads, prioritizing JSON but allowing form fields as fallback
        $payload = array_merge($formPayload, $jsonPayload);

        // Basic logging for debugging (will be removed later)
        // file_put_contents(__DIR__ . '/credits_debug.log', date('[Y-m-d H:i:s] ') . "Method: $method, Payload: " . json_encode($payload) . "\n", FILE_APPEND);

        $action = trim(strtolower($payload['action'] ?? $payload['Action'] ?? ''));
        
        // Extra robust check for nested customData (GHL sometimes nests these)
        if (empty($action) && isset($payload['customData']) && is_array($payload['customData'])) {
            $action = trim(strtolower($payload['customData']['action'] ?? $payload['customData']['Action'] ?? ''));
        }
        
        // Robust amount extraction
        $amountValue = $payload['amount'] ?? $payload['Amount'] ?? $payload['credits'] ?? $payload['Credits'] ?? 0;
        $amount = (int)$amountValue;

        // Extra robust check for nested customData (GHL sometimes nests these)
        if ($amount === 0 && isset($payload['customData']) && is_array($payload['customData'])) {
            $amountValue = $payload['customData']['amount'] ?? $payload['customData']['Amount'] ?? 0;
            $amount = (int)$amountValue;
        }
        
        $reference   = $payload['reference'] ?? $payload['Reference'] ?? 'api_update';
        $description = $payload['description'] ?? $payload['Description'] ?? 'Balance updated via API';
        
        if ($amount <= 0) {
            // Log the failure to help troubleshoot
            $logMsg = date('[Y-m-d H:i:s] ') . "Invalid Amount Failure. Payload: " . json_encode($payload) . " | Raw Amount: " . var_export($amountValue, true) . "\n";
            file_put_contents(__DIR__ . '/credits_error.log', $logMsg, FILE_APPEND);

            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'error' => 'Invalid amount',
                'received_amount' => $amountValue,
                'suggestion' => 'Ensure "amount" is sent as a positive integer in the request body.'
            ], JSON_PRETTY_PRINT);
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
            // Log the failure to help troubleshoot
            $logMsg = date('[Y-m-d H:i:s] ') . "Invalid Action Failure. Payload: " . json_encode($payload) . " | Raw Action: " . var_export($action, true) . "\n";
            file_put_contents(__DIR__ . '/credits_error.log', $logMsg, FILE_APPEND);

            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'error' => 'Invalid action. Use "add" or "deduct".',
                'received_action' => $action,
                'suggestion' => 'Ensure "action" is set to "add" or "deduct" exactly.'
            ], JSON_PRETTY_PRINT);
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
    if (!$locId) {
        echo json_encode(['success' => false, 'error' => 'Missing location_id']);
        exit;
    }
    
    $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
    $docRef = $db->collection('integrations')->document($docId);

    $snapshot = $docRef->snapshot();

    if ($snapshot->exists()) {
        $data = $snapshot->data();
        $creditBalance = (int)($data['credit_balance'] ?? 0);
        $currency = $data['currency'] ?? 'PHP';

        // One-time migration: check if credits are orphaned in 'accounts' collection
        if ($creditBalance === 0) {
            $accountsRef = $db->collection('accounts')->document($locId);
            $accSnap = $accountsRef->snapshot();
            if ($accSnap->exists()) {
                $accBal = (int)($accSnap->data()['credit_balance'] ?? 0);
                if ($accBal > 0) {
                    $creditBalance = $accBal;
                    $now = new \DateTimeImmutable();
                    $docRef->set([
                        'credit_balance' => $accBal,
                        'updated_at'     => new \Google\Cloud\Core\Timestamp($now),
                    ], ['merge' => true]);
                    $accountsRef->set([
                        'credit_balance' => 0,
                        'migrated_to'    => $docId,
                        'updated_at'     => new \Google\Cloud\Core\Timestamp($now),
                    ], ['merge' => true]);
                }
            }
        }

        $updatedAt = isset($data['updated_at']) && $data['updated_at'] instanceof \Google\Cloud\Core\Timestamp 
            ? $data['updated_at']->get()->format('Y-m-d H:i:s') 
            : null;
        $createdAt = isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp 
            ? $data['created_at']->get()->format('Y-m-d H:i:s') 
            : null;
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
        $createdAt = $now->format('Y-m-d H:i:s');
        $updatedAt = $createdAt;
    }

    echo json_encode([
        'success' => true,
        'account_id' => $locId,
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
