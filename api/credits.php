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

        // ALWAYS log the payload for webhook debugging
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $debugMsg = date('[Y-m-d H:i:s] ') . "Method: $method\nHeaders: " . json_encode($headers) . "\nPayload: " . json_encode($payload) . "\n\n";
        
        // Try multiple log locations
        file_put_contents(__DIR__ . '/credits_debug.log', $debugMsg, FILE_APPEND);
        @file_put_contents('/tmp/credits_debug.log', $debugMsg, FILE_APPEND);
        error_log("Credits API Debug: " . $debugMsg);

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
        
        // Robust check for location_id in the payload (for webhooks/automations)
        if (!$locId) {
            $locId = $payload['company_name'] ?? $payload['companyName'] ?? 
                     $payload['location_id'] ?? $payload['locationId'] ?? $payload['location'] ?? 
                     $payload['business.name'] ?? $payload['business_name'] ?? null;
        }
        
        // Extra robust check for nested customData (GHL sometimes nests these)
        if (empty($locId) || is_array($locId)) {
            $nestedLocId = $payload['customData']['company_name'] ?? $payload['customData']['companyName'] ?? 
                            $payload['customData']['location_id'] ?? $payload['customData']['locationId'] ?? 
                            $payload['customData']['location'] ?? $payload['customData']['business.name'] ?? 
                            $payload['customData']['business_name'] ?? null;
            if ($nestedLocId && !is_array($nestedLocId)) {
                $locId = $nestedLocId;
            }
        }

        // If it's still null or an object (like GHL's standard location object), try to get the ID
        if (is_array($locId) || is_object($locId)) {
            $locId = $locId['id'] ?? $locId['locationId'] ?? $locId['location_id'] ?? null;
        }

        // Final prioritize check: if we have a company_name that is a string, use it
        if (!empty($payload['company_name']) && is_string($payload['company_name'])) {
            $locId = $payload['company_name'];
        }

        // If it's still null, and we see GHL-like headers or payload structure, log it
        if (!$locId && (isset($payload['contact']) || isset($payload['workflow']))) {
            $locId = $payload['locationId'] ?? $payload['contact']['locationId'] ?? null;
        }

        $accountId = $locId ?: 'default';
        
        // Ensure accountId is a string if it's not 'default'
        if ($accountId !== 'default') {
            if (is_array($accountId)) {
                $accountId = $accountId[0] ?? 'default';
            }
            $accountId = trim((string)$accountId);
        }

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

    // --- Calculate Stats ---
    $startOfMonth = new \DateTimeImmutable('first day of this month 00:00:00');
    $startOfToday = new \DateTimeImmutable('today 00:00:00');

    $creditsRef = $db->collection('credit_transactions');
    $query = $creditsRef->where('account_id', '=', $docId)
                        ->where('created_at', '>=', new \Google\Cloud\Core\Timestamp($startOfMonth));
    
    $statsDocs = [];
    try {
        $statsDocs = $query->documents();
    } catch (\Throwable $e) {
        // If Firestore throws a Missing Index error, catch it here cleanly
        $stats = [
            'sent_today' => 0,
            'credits_used_today' => 0,
            'credits_used_month' => 0,
            'error' => 'Stats query failed. Likely missing Firestore index. See message string.',
            'message' => $e->getMessage()
        ];
    }

    if (empty($stats)) {
        $sentToday = 0;
        $creditsUsedToday = 0;
        $creditsUsedMonth = 0;

        foreach ($statsDocs as $txDoc) {
            if (!$txDoc->exists()) continue;
            $tx = $txDoc->data();
            
            if (($tx['type'] ?? '') === 'deduction') {
                $amt = abs((int)($tx['amount'] ?? 0));
                $freeApplied = (int)($tx['free_usage_applied'] ?? 0);
                
                // All tx here are at least this month
                $creditsUsedMonth += $amt;
                
                $createdAtTs = $tx['created_at'] ?? null;
                if ($createdAtTs && $createdAtTs instanceof \Google\Cloud\Core\Timestamp) {
                    $dt = $createdAtTs->get();
                    
                    // Compare explicitly to start of today using timestamp or date formatting
                    // get() returns a DateTime object (or DateTimeImmutable)
                    if ($dt->getTimestamp() >= $startOfToday->getTimestamp()) {
                        $creditsUsedToday += $amt;
                        $sentToday += ($amt + $freeApplied);
                    }
                }
            }
        }
        
        $stats = [
            'sent_today' => $sentToday,
            'credits_used_today' => $creditsUsedToday,
            'credits_used_month' => $creditsUsedMonth
        ];
    }
    // -----------------------

    echo json_encode([
        'success' => true,
        'account_id' => $locId,
        'credit_balance' => $creditBalance,
        'free_usage_count' => (int)($data['free_usage_count'] ?? 0),
        'free_credits_total' => (int)($data['free_credits_total'] ?? 0),
        'currency' => $currency,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'stats' => $stats
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
