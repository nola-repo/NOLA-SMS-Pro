<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/services/CreditManager.php';

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
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['HTTP_X_CORRELATION_ID'] ?? bin2hex(random_bytes(8));
        error_log('[credits.php] request ' . json_encode([
            'request_id' => $requestId,
            'method' => $method,
            'action' => $payload['action'] ?? $payload['Action'] ?? ($payload['customData']['action'] ?? null),
            'location_id_hash' => isset($payload['location_id']) ? hash('sha256', (string)$payload['location_id']) : null,
            'reference_hash' => isset($payload['reference']) ? hash('sha256', (string)$payload['reference']) : null,
            'has_secret' => !empty($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_GET['secret'] ?? $_GET['token'] ?? ''),
        ]));

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

        $reference = $payload['reference'] ?? $payload['Reference'] ?? 'api_update';
        $description = $payload['description'] ?? $payload['Description'] ?? 'Balance updated via API';

        if ($amount <= 0) {
            error_log('[credits.php] invalid amount request_id=' . ($requestId ?? 'unknown') . ' raw_amount_type=' . gettype($amountValue));

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid amount',
                'received_amount' => $amountValue,
                'suggestion' => 'Ensure "amount" is sent as a positive integer in the request body.'
            ], JSON_PRETTY_PRINT);
            exit;
        }

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
        }
        elseif ($action === 'deduct') {
            $newBalance = $creditManager->deduct_credits($accountId, $amount, $reference, $description);
        }
        else {
            error_log('[credits.php] invalid action request_id=' . ($requestId ?? 'unknown') . ' action_hash=' . hash('sha256', (string)$action));

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

    require_once __DIR__ . '/cache_helper.php';
    $cacheKey = "credits_data_{$locId}";
    $registryKey = "credits_registry_{$locId}";
    $forceFresh = isset($_GET['fresh']) || isset($_GET['no_cache']);

    $cachedData = NolaCache::get($cacheKey);
    if (!$forceFresh && $cachedData !== null) {
        NolaCache::sendApiCacheHeaders(30, true);
        echo json_encode(NolaCache::withCacheMeta($cachedData, 30, true, 'location'), JSON_PRETTY_PRINT);
        exit;
    }

    $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
    $docRef = $db->collection('integrations')->document($docId);

    $creditManager = new CreditManager();
    $creditBalance = $creditManager->get_balance((string)$locId);

    $snapshot = $docRef->snapshot();
    $data = $snapshot->exists() ? $snapshot->data() : [];

    if ($snapshot->exists()) {
        $trialBackfill = [];
        $existingUpdatedAt = isset($data['updated_at']) && $data['updated_at'] instanceof \Google\Cloud\Core\Timestamp
            ? $data['updated_at']
            : null;
        $shouldTouchUpdatedAt = false;

        if (!array_key_exists('free_credits_total', $data)) {
            $trialBackfill['free_credits_total'] = 10;
            $data['free_credits_total'] = 10;
            $shouldTouchUpdatedAt = true;
        }
        if (!array_key_exists('free_usage_count', $data)) {
            $trialBackfill['free_usage_count'] = 0;
            $data['free_usage_count'] = 0;
            $shouldTouchUpdatedAt = true;
        }
        if (!isset($data['created_at']) || !$data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
            $createdAtBackfill = $existingUpdatedAt ?: new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());
            $trialBackfill['created_at'] = $createdAtBackfill;
            $data['created_at'] = $createdAtBackfill;
        }
        if (!empty($trialBackfill)) {
            if ($shouldTouchUpdatedAt) {
                $trialBackfill['updated_at'] = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());
                $data['updated_at'] = $trialBackfill['updated_at'];
            }
            $docRef->set($trialBackfill, ['merge' => true]);
        }

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
                    $walletRef = $creditManager->resolveSubaccountBalanceDocument((string)$locId);
                    $walletRef->set([
                        'credit_balance' => $accBal,
                        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
                    ], ['merge' => true]);
                    $accountsRef->set([
                        'credit_balance' => 0,
                        'migrated_to' => $docId,
                        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
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
        // Initialize with zero balance if not present (legacy integrations doc)
        $now = new DateTimeImmutable();
        $currency = 'PHP';
        $docRef->set([
            'credit_balance' => 0,
            'free_credits_total' => 10,
            'free_usage_count' => 0,
            'currency' => $currency,
            'created_at' => new \Google\Cloud\Core\Timestamp($now),
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ]);
        $data = [
            'free_credits_total' => 10,
            'free_usage_count' => 0,
        ];
        $createdAt = $now->format('Y-m-d H:i:s');
        $updatedAt = $createdAt;
    }

    // --- Calculate Stats ---
    // Stats count both 'deduction' (legacy) and 'sms_usage' (modern deduct_subaccount_only)
    // transaction types so the figures are always accurate regardless of which code path sent.
    $startOfMonth = new \DateTimeImmutable('first day of this month 00:00:00');
    $startOfToday = new \DateTimeImmutable('today 00:00:00');

    $creditsRef = $db->collection('credit_transactions');

    // Query by account_id (stored as the Firestore doc ID, e.g. "ghl_ABC123")
    $query = $creditsRef
        ->where('account_id', '=', $docId)
        ->where('created_at', '>=', new \Google\Cloud\Core\Timestamp($startOfMonth));

    $statsDocs = [];
    try {
        $statsDocs = $query->documents();
    } catch (\Throwable $e) {
        // If Firestore throws a Missing Index error, catch it here cleanly.
        // Fix: create a composite index on credit_transactions for (account_id ASC, created_at ASC)
        $stats = [
            'sent_today'         => 0,
            'credits_used_today' => 0,
            'credits_used_month' => 0,
            'error'   => 'Stats query failed — likely missing Firestore composite index.',
            'message' => $e->getMessage(),
        ];
    }

    if (empty($stats)) {
        $sentToday         = 0;
        $creditsUsedToday  = 0;
        $creditsUsedMonth  = 0;

        foreach ($statsDocs as $txDoc) {
            if (!$txDoc->exists()) continue;
            $tx = $txDoc->data();

            $txType = $tx['type'] ?? '';

            // Count both legacy 'deduction' AND modern 'sms_usage' transaction types.
            // 'sms_usage' is written by deduct_subaccount_only() (the current SMS path).
            // 'deduction' is written by the older deduct_credits() path.
            $isSmsDeduction = ($txType === 'deduction' || $txType === 'sms_usage')
                && ($tx['wallet_scope'] ?? '') !== 'agency'; // exclude agency-side mirror rows

            if ($isSmsDeduction) {
                $amt         = abs((int)($tx['amount'] ?? 0));
                $freeApplied = (int)($tx['free_usage_applied'] ?? 0);

                // All documents here are >= start of month (enforced by Firestore query)
                $creditsUsedMonth += $amt;

                $createdAtTs = $tx['created_at'] ?? null;
                if ($createdAtTs instanceof \Google\Cloud\Core\Timestamp) {
                    $dt = $createdAtTs->get();

                    if ($dt->getTimestamp() >= $startOfToday->getTimestamp()) {
                        $creditsUsedToday += $amt;
                        // sent_today = paid credits used + free trial credits used
                        $sentToday += max(1, $amt + $freeApplied);
                    }
                }
            }
        }

        $stats = [
            'sent_today'         => $sentToday,
            'credits_used_today' => $creditsUsedToday,
            'credits_used_month' => $creditsUsedMonth,
        ];
    }
    // -----------------------

    $responsePayload = [
        'success' => true,
        'account_id' => $locId,
        'credit_balance' => $creditBalance,
        'free_usage_count' => (int)($data['free_usage_count'] ?? 0),
        'free_credits_total' => (int)($data['free_credits_total'] ?? 10),
        'currency' => $currency,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'stats' => $stats
    ];

    NolaCache::setWithRegistry($registryKey, $cacheKey, $responsePayload, 30); // Short cache; UI can request fresh values.

    NolaCache::sendApiCacheHeaders(30, $forceFresh ? 'BYPASS' : false);
    echo json_encode(NolaCache::withCacheMeta($responsePayload, 30, $forceFresh ? 'BYPASS' : false, 'location'), JSON_PRETTY_PRINT);
}
catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch credit balance',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
