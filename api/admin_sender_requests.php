<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/services/CreditManager.php';
require_once __DIR__ . '/cache_helper.php';

// Authentication: Admin-only secret or specialized check
validate_api_request();

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'logs') {
    $cacheKey = "admin_dashboard_logs";
    $cachedData = NolaCache::get($cacheKey);
    if ($cachedData !== null) {
        echo json_encode($cachedData);
        exit;
    }

    $unifiedLogs = [];

    // 1. Fetch recent messages
    $messages = $db->collection('messages')->orderBy('date_created', 'DESC')->limit(30)->documents();
    foreach ($messages as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $ts = isset($data['date_created']) && $data['date_created'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['date_created']->get()->format('c') : null;
            
            $unifiedLogs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'message',
                'timestamp' => $ts
            ]);
        }
    }

    // 2. Fetch sender requests
    $requests = $db->collection('sender_id_requests')->orderBy('created_at', 'DESC')->limit(20)->documents();
    foreach ($requests as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $ts = isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['created_at']->get()->format('c') : null;
            
            $unifiedLogs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'sender_request',
                'timestamp' => $ts
            ]);
        }
    }

    // 3. Fetch credit transactions
    $purchases = $db->collection('credit_transactions')->orderBy('created_at', 'DESC')->limit(20)->documents();
    foreach ($purchases as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $ts = isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['created_at']->get()->format('c') : null;
            
            $unifiedLogs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'credit_purchase',
                'timestamp' => $ts
            ]);
        }
    }

    // Sort combined array by timestamp descending
    usort($unifiedLogs, function($a, $b) {
        $timeA = strtotime($a['timestamp'] ?? '1970-01-01');
        $timeB = strtotime($b['timestamp'] ?? '1970-01-01');
        return $timeB - $timeA;
    });

    // Return the top 50
    $finalLogs = array_slice($unifiedLogs, 0, 50);

    $totalMessages = 0;
    try {
        $totalMessages = $db->collection('messages')->count()->get()->get('count');
    } catch (\Throwable $e) {
        try {
            $totalMessages = iterator_count($db->collection('messages')->documents());
        } catch (\Throwable $e2) {
            $totalMessages = 0;
        }
    }

    $responsePayload = [
        'status' => 'success',
        'data' => $finalLogs,
        'total_messages' => $totalMessages
    ];
    NolaCache::set($cacheKey, $responsePayload, 60); // 60 seconds TTL
    echo json_encode($responsePayload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!isset($_GET['action']) || $_GET['action'] === 'sender_requests')) {
    $cacheKey = "admin_sender_requests_list";
    $cachedData = NolaCache::get($cacheKey);
    if ($cachedData !== null) {
        echo json_encode($cachedData);
        exit;
    }

    // In production, we'd probably filter by "pending" first, but let's fetch all
    $requests = $db->collection('sender_id_requests')
        ->orderBy('created_at', 'DESC')
        ->documents();

    $results = [];
    foreach ($requests as $request) {
        $data = $request->data();
        if (isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
            $data['created_at'] = $data['created_at']->get()->format('Y-m-d H:i:s');
        }
        $data['id'] = $request->id();
        $results[] = $data;
    }

    $responsePayload = ['status' => 'success', 'data' => $results];
    NolaCache::set($cacheKey, $responsePayload, 300); // 5 minutes cache
    echo json_encode($responsePayload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    // 1. Action: manage_sender (Update existing)
    if (isset($payload['action']) && $payload['action'] === 'manage_sender') {
        $locId = $payload['location_id'] ?? null;

        if (!$locId) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
            exit;
        }

        $docId = CreditManager::integration_doc_id_for_location((string)$locId);
        $docRef = $db->collection('integrations')->document($docId);

        $creditManager = new CreditManager();
        $oldBalance = $creditManager->get_balance((string)$locId);

        $updateData = [
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ];

        if (isset($payload['sender_id'])) {
            $updateData['approved_sender_id'] = $payload['sender_id'];
        }
        if (isset($payload['api_key']) && !empty($payload['api_key'])) {
            $apiKeyToValidate = $payload['api_key'];
            $isUniSmsKey = (str_starts_with($apiKeyToValidate, 'sk_'));
            
            if ($isUniSmsKey) {
                require_once __DIR__ . '/services/providers/UniSmsProvider.php';
                $uniSms = new UniSmsProvider(['UNISMS_API_KEY' => $apiKeyToValidate]);
                $accCheck = $uniSms->checkAccount();
                $isValidKey = ($accCheck['status'] === 'active');
            } else {
                $ch = curl_init('https://api.semaphore.co/api/v4/account?apikey=' . urlencode($apiKeyToValidate));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $isValidKey = ($httpCode === 200);
            }

            if (!$isValidKey) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid API Key. Verification failed.']);
                exit;
            }

            if ($isUniSmsKey) {
                $updateData['unisms_api_key'] = $payload['api_key'];
                $updateData['provider_preference'] = 'unisms_custom';
            } else {
                $updateData['nola_pro_api_key']   = $payload['api_key'];
                $updateData['semaphore_api_key']  = $payload['api_key'];
                $updateData['provider_preference'] = 'semaphore_custom';
            }
        }
        if (isset($payload['free_credits_total'])) {
            $updateData['free_credits_total'] = (int)$payload['free_credits_total'];
        }

        $docRef->set($updateData, ['merge' => true]);

        if (isset($payload['credit_balance'])) {
            $balanceRef = $creditManager->resolveSubaccountBalanceDocument((string)$locId);
            $balanceRef->set([
                'credit_balance' => (int)$payload['credit_balance'],
                'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ], ['merge' => true]);
        }

        // Auto-add sender to dynamic master whitelist when admin sets one directly
        if (isset($payload['sender_id']) && !empty($payload['sender_id'])) {
            $db->collection('admin_config')->document('master_senders')->set([
                'approved_senders' => \Google\Cloud\Firestore\FieldValue::arrayUnion([$payload['sender_id']]),
                'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ], ['merge' => true]);
        }

        if (isset($payload['credit_balance'])) {
            $newBalance = (int)$payload['credit_balance'];
            $delta = $newBalance - $oldBalance;

            // Only log if there was an actual change
            if ($delta !== 0) {
                $logId = 'adj_' . uniqid();
                $db->collection('credit_transactions')->document($logId)->set([
                    'id' => $logId,
                    'account_id' => $docId,
                    'location_id' => $locId,
                    'amount' => $delta, // Log the difference (+5, -2, etc)
                    'balance_after' => $newBalance,
                    'type' => 'admin_adjustment',
                    'description' => "Manual credit adjustment by System Admin (Applied " . ($delta > 0 ? "+" : "") . $delta . " credits)",
                    'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                ]);
            }
        }

        NolaCache::invalidateAdminDashboard();
        if ($locId) {
            NolaCache::delete("account_profile_" . $locId);
            NolaCache::deleteRegistry("credits_registry_" . $locId);
        }

        echo json_encode(['status' => 'success', 'message' => 'Account configuration updated successfully.']);
        exit;
    }

    // 2. Action: manage_agency (Set credit_balance on a users doc by Firestore doc ID)
    if (isset($payload['action']) && $payload['action'] === 'manage_agency') {
        $userId    = $payload['user_id']        ?? null;  // Firestore document ID in `users`
        $companyId = $payload['company_id']     ?? null;  // GHL company_id (optional)
        $newBalance = isset($payload['credit_balance']) ? (int)$payload['credit_balance'] : null;

        if (!$userId) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing user_id']);
            exit;
        }

        $userRef  = $db->collection('users')->document($userId);
        $userSnap = $userRef->snapshot();

        if (!$userSnap->exists()) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Agency user not found']);
            exit;
        }

        $userData   = $userSnap->data();
        $oldBalance = (int)($userData['credit_balance'] ?? 0);

        $updateFields = [
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ];

        if ($newBalance !== null) {
            $updateFields['credit_balance'] = $newBalance;
        }
        if ($companyId) {
            $updateFields['company_id'] = $companyId;
        }

        $userRef->set($updateFields, ['merge' => true]);

        // Log credit delta to credit_transactions
        if ($newBalance !== null) {
            $delta = $newBalance - $oldBalance;
            if ($delta !== 0) {
                $logId = 'agency_adj_' . uniqid();
                $db->collection('credit_transactions')->document($logId)->set([
                    'id'            => $logId,
                    'account_id'    => $userId,
                    'company_id'    => $companyId ?? ($userData['company_id'] ?? null),
                    'wallet_scope'  => 'agency',
                    'amount'        => $delta,
                    'balance_after' => $newBalance,
                    'type'          => 'admin_adjustment',
                    'description'   => 'Admin agency credit adjustment (' . ($delta > 0 ? '+' : '') . $delta . ' credits)',
                    'created_at'    => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                ]);
            }
        }

        NolaCache::invalidateAdminDashboard();
        if ($userId) {
            NolaCache::delete("admin_user_profile_" . $userId);
            NolaCache::delete("agency_profile_" . $userId);
        }

        echo json_encode(['status' => 'success', 'message' => 'Agency account updated successfully.']);
        exit;
    }

    $requestId = $payload['request_id'] ?? null;
    $status = $payload['status'] ?? null; // 'approved' or 'rejected'
    $apiKey = $payload['api_key'] ?? null;
    $note = $payload['note'] ?? $payload['rejection_note'] ?? null;

    if (!$requestId || !in_array($status, ['approved', 'rejected', 'revoked', 'deleted'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid request_id or status']);
        exit;
    }

    // 1. Update the request status
    $requestRef = $db->collection('sender_id_requests')->document($requestId);
    $reqSnapshot = $requestRef->snapshot();
    
    if (!$reqSnapshot->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Request not found']);
        exit;
    }

    $reqData = $reqSnapshot->data();
    $locId = $reqData['location_id'];

    if ($status === 'approved' && !empty($apiKey)) {
        $isUniSmsKey = (str_starts_with($apiKey, 'sk_'));
        if ($isUniSmsKey) {
            require_once __DIR__ . '/services/providers/UniSmsProvider.php';
            $uniSms = new UniSmsProvider(['UNISMS_API_KEY' => $apiKey]);
            $accCheck = $uniSms->checkAccount();
            $isValidKey = ($accCheck['status'] === 'active');
        } else {
            $ch = curl_init('https://api.semaphore.co/api/v4/account?apikey=' . urlencode($apiKey));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $isValidKey = ($httpCode === 200);
        }

        if (!$isValidKey) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid API Key. Verification failed.']);
            exit;
        }
    }

    $updateData = ['status' => $status];
    if ($status === 'rejected' && $note) {
        $updateData['admin_notes'] = $note;
    }
    $updateData['updated_at'] = new \Google\Cloud\Core\Timestamp(new \DateTime());

    $requestRef->set($updateData, ['merge' => true]);

    // 2. If approved, update the account mapping
    if ($status === 'approved') {
        // ... approved logic ...
        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $accountRef = $db->collection('integrations')->document($docId);
        
        $locationName = 'Unknown';
        $intSnap = $db->collection('integrations')->document($docId)->snapshot();
        if ($intSnap->exists()) {
            $locationName = $intSnap->data()['location_name'] ?? 'Unknown';
        }

        $updateFields = [
            'location_id' => $locId,
            'location_name' => $locationName,
            'approved_sender_id' => $reqData['requested_id'],
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ];
        if (str_starts_with($apiKey, 'sk_')) {
            $updateFields['unisms_api_key'] = $apiKey;
            $updateFields['provider_preference'] = 'unisms_custom';
        } else {
            $updateFields['nola_pro_api_key'] = $apiKey;
            $updateFields['semaphore_api_key'] = $apiKey;
            $updateFields['provider_preference'] = 'semaphore_custom';
        }
        $accountRef->set($updateFields, ['merge' => true]);

        // ── Auto-add sender to dynamic master whitelist ─────────────────────────
        // So send_sms.php and ghl_provider.php recognize it without config edits
        $db->collection('admin_config')->document('master_senders')->set([
            'approved_senders' => \Google\Cloud\Firestore\FieldValue::arrayUnion([$reqData['requested_id']]),
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);

        // Keep one active Sender ID per location in the request history.
        $sameLocationRequests = $db->collection('sender_id_requests')
            ->where('location_id', '==', $locId)
            ->documents();
        foreach ($sameLocationRequests as $sameRequest) {
            if (!$sameRequest->exists() || $sameRequest->id() === $requestId) {
                continue;
            }
            $sameData = $sameRequest->data();
            if (($sameData['status'] ?? '') === 'approved') {
                $sameRequest->reference()->set([
                    'status' => 'revoked',
                    'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
                ], ['merge' => true]);
                if (!empty($sameData['requested_id'])) {
                    $db->collection('admin_config')->document('master_senders')->set([
                        'approved_senders' => \Google\Cloud\Firestore\FieldValue::arrayRemove([$sameData['requested_id']]),
                        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                    ], ['merge' => true]);
                }
            }
        }

    } elseif ($status === 'revoked') {
        // Clear the sender ID from the account mapping only if this request is still active.
        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $accountRef = $db->collection('integrations')->document($docId);

        $accountSnap = $accountRef->snapshot();
        $activeSender = $accountSnap->exists() ? (string)($accountSnap->data()['approved_sender_id'] ?? '') : '';
        $requestSender = (string)($reqData['requested_id'] ?? '');
        if ($requestSender !== '' && strtolower(trim($activeSender)) === strtolower(trim($requestSender))) {
            $accountRef->set([
                'approved_sender_id' => null,
                'nola_pro_api_key'   => null,
                'semaphore_api_key'  => null,
                'updated_at'         => new \Google\Cloud\Core\Timestamp(new \DateTime())
            ], ['merge' => true]);
        }

        // ── Remove sender from dynamic master whitelist ─────────────────────────
        if (!empty($reqData['requested_id'])) {
            $db->collection('admin_config')->document('master_senders')->set([
                'approved_senders' => \Google\Cloud\Firestore\FieldValue::arrayRemove([$reqData['requested_id']]),
                'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ], ['merge' => true]);
        }

    } elseif ($status === 'deleted') {
        // If this deleted request is the active sender, remove it from the account too.
        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $accountRef = $db->collection('integrations')->document($docId);
        $accountSnap = $accountRef->snapshot();
        $activeSender = $accountSnap->exists() ? (string)($accountSnap->data()['approved_sender_id'] ?? '') : '';
        $requestSender = (string)($reqData['requested_id'] ?? '');
        if ($requestSender !== '' && strtolower(trim($activeSender)) === strtolower(trim($requestSender))) {
            $accountRef->set([
                'approved_sender_id' => null,
                'nola_pro_api_key'   => null,
                'semaphore_api_key'  => null,
                'updated_at'         => new \Google\Cloud\Core\Timestamp(new \DateTime())
            ], ['merge' => true]);
        }

        if (!empty($reqData['requested_id'])) {
            $db->collection('admin_config')->document('master_senders')->set([
                'approved_senders' => \Google\Cloud\Firestore\FieldValue::arrayRemove([$reqData['requested_id']]),
                'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ], ['merge' => true]);
        }

        // Physical deletion of the request document
        $requestRef->delete();
        NolaCache::invalidateAdminDashboard();
        if ($locId) {
            NolaCache::delete("account_profile_" . $locId);
        }
        echo json_encode(['status' => 'success', 'message' => "Request deleted and active sender cleared if it was in use."]);
        exit;
    }

    // 3. Dispatch approval or rejection notification
    if (in_array($status, ['approved', 'rejected'])) {
        try {
            require_once __DIR__ . '/services/NotificationService.php';
            NotificationService::notifySenderIdStatus($db, $locId, $reqData['requested_id'], $status, $note);
        } catch (\Throwable $e) {
            error_log("[admin_sender_requests.php] Failed to send sender ID notification ($status): " . $e->getMessage());
        }
    }

    NolaCache::invalidateAdminDashboard();
    if ($locId) {
        NolaCache::delete("account_profile_" . $locId);
    }

    echo json_encode(['status' => 'success', 'message' => "Request $status and account updated."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'agencies') {
    $cacheKey = "admin_agencies_list";
    $cachedData = NolaCache::get($cacheKey);
    if ($cachedData !== null) {
        echo json_encode($cachedData);
        exit;
    }

    $results = [];

    // Source: ghl_tokens collection, filtered by appType == 'agency'
    $agencyDocs = $db->collection('ghl_tokens')
        ->where('appType', '==', 'agency')
        ->documents();

    foreach ($agencyDocs as $doc) {
        if (!$doc->exists()) continue;
        $data = $doc->data();

        $createdAt = null;
        if (isset($data['createdAt']) && $data['createdAt'] instanceof \Google\Cloud\Core\Timestamp) {
            $createdAt = $data['createdAt']->get()->format('Y-m-d H:i:s');
        } elseif (isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
            $createdAt = $data['created_at']->get()->format('Y-m-d H:i:s');
        }

        // company_id is the GHL companyId stored on the token document;
        // fall back to the Firestore document ID if absent.
        $companyId = $data['companyId'] ?? $data['company_id'] ?? $doc->id();

        $results[] = [
            'id'           => $doc->id(),
            'company_name' => $data['companyName'] ?? $data['company_name'] ?? '',
            'company_id'   => $companyId,
            'active'       => $data['active'] ?? true,
            'createdAt'    => $createdAt,
        ];
    }

    // Sort newest first
    usort($results, function ($a, $b) {
        return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? '');
    });

    $responsePayload = ['status' => 'success', 'data' => $results];
    NolaCache::set($cacheKey, $responsePayload, 300); // 5 minutes cache
    echo json_encode($responsePayload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'accounts') {
    $cacheKey = "admin_accounts_list";
    $cachedPayload = NolaCache::get($cacheKey);
    $filterCompanyId = $_GET['company_id'] ?? null;

    if ($cachedPayload !== null) {
        if ($filterCompanyId) {
            $filteredResults = [];
            foreach ($cachedPayload['data'] as $item) {
                if (($item['data']['company_id'] ?? '') === $filterCompanyId) {
                    $filteredResults[] = $item;
                }
            }
            echo json_encode(['status' => 'success', 'data' => $filteredResults]);
        } else {
            echo json_encode($cachedPayload);
        }
        exit;
    }

    $results = [];

    // Pre-fetch tokens to resolve agency/subaccount names and settings (saves collection reads).
    // Agency-level installs may live in either legacy ghl_agency_tokens or canonical ghl_tokens.
    $agencyMap = [];
    $subTokenMap = [];
    $subCompanyMap = [];

    foreach (['ghl_agency_tokens', 'ghl_tokens'] as $tokenCollection) {
        $allTokens = $db->collection($tokenCollection)->documents();
        foreach ($allTokens as $tokenDoc) {
            if ($tokenDoc->exists()) {
                $tData = $tokenDoc->data();
                $appType = $tData['appType'] ?? '';
                $isAgencyToken = $tokenCollection === 'ghl_agency_tokens' || $appType === 'agency';

                if ($isAgencyToken) {
                    $comp = trim((string)($tData['companyId'] ?? $tData['company_id'] ?? $tokenDoc->id()));
                    $agencyName = $tData['company_name']
                        ?? $tData['companyName']
                        ?? $tData['agency_name']
                        ?? $tData['location_name']
                        ?? '';
                    if ($comp !== '' && trim((string)$agencyName) !== '') {
                        $agencyMap[$comp] = trim((string)$agencyName);
                    }
                } else {
                    $locIdStr = $tData['locationId'] ?? $tData['location_id'] ?? $tokenDoc->id();
                    if ($locIdStr) {
                        $subTokenMap[$locIdStr] = $tData;
                        $subCompStr = $tData['companyId'] ?? $tData['company_id'] ?? null;
                        if ($subCompStr) {
                            $subCompanyMap[$locIdStr] = $subCompStr;
                        }
                    }
                }
            }
        }
    }

    // Pre-fetch all users to map location credit balances and user profile details in-memory (eliminates O(N) nested loop queries)
    $usersRaw = $db->collection('users')->documents();
    $locationToCreditMap = [];
    $locationToUserMap = [];
    foreach ($usersRaw as $userDoc) {
        if ($userDoc->exists()) {
            $uData = $userDoc->data();
            
            // Build location to user map
            foreach (['active_location_id', 'location_id'] as $field) {
                $loc = trim((string)($uData[$field] ?? ''));
                if ($loc !== '') {
                    $userDataEntry = ['id' => $userDoc->id()] + $uData;
                    $locationToUserMap[$loc] = $userDataEntry;
                    $locationToUserMap['ghl_' . $loc] = $userDataEntry;
                }
            }

            $bal = isset($uData['credit_balance']) ? (int)$uData['credit_balance'] : null;
            if ($bal !== null) {
                $activeLoc = trim((string)($uData['active_location_id'] ?? ''));
                if ($activeLoc !== '') {
                    $locationToCreditMap[$activeLoc] = $bal;
                    $locationToCreditMap['ghl_' . $activeLoc] = $bal;
                }
                
                $locIdField = trim((string)($uData['location_id'] ?? ''));
                if ($locIdField !== '') {
                    $locationToCreditMap[$locIdField] = $bal;
                    $locationToCreditMap['ghl_' . $locIdField] = $bal;
                }
            }
        }
    }

    // 1. Fetch all integrations (Master list)
    $integrationsRaw = $db->collection('integrations')->documents();

    foreach ($integrationsRaw as $intDoc) {
        $intData = $intDoc->data();
        $intDocId = $intDoc->id();
        $locId = $intData['location_id'] ?? str_replace('ghl_', '', $intDocId);

        if ($locId === 'ghl') continue; // Skip misconfigured docs

        $locationName = $intData['location_name'] ?? '';

        // --- Fetch missing location name using GHL API ---
        if (empty(trim($locationName))) {
            $accessToken = $intData['access_token'] ?? '';
            
            if ($accessToken) {
                try {
                    $locationUrl = 'https://services.leadconnectorhq.com/locations/' . $locId;
                    $ch = curl_init($locationUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $accessToken,
                        'Accept: application/json',
                        'Version: 2021-07-28',
                    ]);
                    $locResponse = curl_exec($ch);
                    $locHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($locHttpCode === 200) {
                        $locData = json_decode($locResponse, true);
                        if (!empty($locData['location']['name'])) {
                            $locationName = $locData['location']['name'];
                            
                            // Save back to integrations so we don't query it again
                            $db->collection('integrations')->document($intDocId)->set([
                                'location_name' => $locationName
                            ], ['merge' => true]);
                        }
                    }
                } catch (Exception $e) {
                    error_log("Failed to fetch location name for $locId: " . $e->getMessage());
                }
            }
        }
        
        if (empty(trim($locationName))) {
            $locationName = 'Unknown Location';
        }

        $companyId = $intData['companyId'] ?? $intData['company_id'] ?? ($subCompanyMap[$locId] ?? '');

        $agencyName = $companyId && isset($agencyMap[$companyId]) ? $agencyMap[$companyId] : 'No Agency';

        $tokData = $subTokenMap[$locId] ?? [];

        // Resolve sub-account credit balance using the high-performance in-memory map
        $creditBalance = 0;
        if (isset($locationToCreditMap[$locId])) {
            $creditBalance = $locationToCreditMap[$locId];
        } elseif (isset($locationToCreditMap['ghl_' . $locId])) {
            $creditBalance = $locationToCreditMap['ghl_' . $locId];
        } else {
            // Fallback to legacy integrations local credit_balance
            $creditBalance = (int)($intData['credit_balance'] ?? 0);
        }

        $userData = $locationToUserMap[$locId] ?? $locationToUserMap['ghl_' . $locId] ?? [];
        
        $firstName = $userData['firstName'] ?? '';
        $lastName  = $userData['lastName'] ?? '';
        $fullName  = $userData['name'] ?? '';
        if (empty($firstName) && empty($lastName) && !empty($fullName)) {
            $parts = preg_split('/\s+/', trim((string)$fullName));
            $firstName = $parts[0] ?? '';
            $lastName  = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        }

        $results[] = [
            'id' => $intDocId, // Expected by frontend mapping
            'data' => [
                'location_id' => $locId,
                'location_name' => $locationName,
                'company_id' => $companyId,
                'agency_name' => $agencyName,
                'approved_sender_id' => $intData['approved_sender_id'] ?? null,
                'nola_pro_api_key'   => $intData['nola_pro_api_key'] ?? null,
                'api_key'            => $intData['api_key'] ?? null,
                'semaphore_api_key'  => $intData['semaphore_api_key'] ?? null,
                'credit_balance'     => $creditBalance,
                'free_usage_count'   => (int)($intData['free_usage_count'] ?? 0),
                'free_credits_total' => (int)($intData['free_credits_total'] ?? 10),
                'toggle_enabled'     => isset($tokData['toggle_enabled']) ? (bool)$tokData['toggle_enabled'] : true,
                'rate_limit'         => (int)($tokData['rate_limit'] ?? 0),
                'attempt_count'      => (int)($tokData['attempt_count'] ?? 0),
                'last_reset_date'    => $tokData['last_reset_date'] ?? '',
                
                // Enriched user details
                'name'               => $fullName,
                'firstName'          => $firstName,
                'lastName'           => $lastName,
                'email'              => $userData['email'] ?? '',
                'phone'              => $userData['phone'] ?? '',
                'role'               => $userData['role'] ?? 'user',
                'active'             => !array_key_exists('active', $userData) || !empty($userData['active']),
            ]
        ];
    }

    $responsePayload = ['status' => 'success', 'data' => $results];
    NolaCache::set($cacheKey, $responsePayload, 300); // 5 minutes cache

    if ($filterCompanyId) {
        $filteredResults = [];
        foreach ($results as $item) {
            if (($item['data']['company_id'] ?? '') === $filterCompanyId) {
                $filteredResults[] = $item;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $filteredResults]);
    } else {
        echo json_encode($responsePayload);
    }
    exit;
}
