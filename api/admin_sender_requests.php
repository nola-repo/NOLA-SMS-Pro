<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// Authentication: Admin-only secret or specialized check
validate_api_request();

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'logs') {
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

    echo json_encode(['status' => 'success', 'data' => $finalLogs]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!isset($_GET['action']) || $_GET['action'] === 'sender_requests')) {
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

    echo json_encode(['status' => 'success', 'data' => $results]);
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

        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $docRef = $db->collection('integrations')->document($docId);
        $oldBalance = 0;
        $snap = $docRef->snapshot();
        if ($snap->exists()) {
            $oldBalance = (int)($snap->data()['credit_balance'] ?? 0);
        }

        $updateData = [
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ];

        if (isset($payload['sender_id'])) {
            $updateData['approved_sender_id'] = $payload['sender_id'];
        }
        if (isset($payload['api_key']) && !empty($payload['api_key'])) {
            $updateData['nola_pro_api_key']   = $payload['api_key'];
            $updateData['semaphore_api_key']  = $payload['api_key'];
        }
        if (isset($payload['credit_balance'])) {
            $updateData['credit_balance'] = (int)$payload['credit_balance'];
        }
        if (isset($payload['free_credits_total'])) {
            $updateData['free_credits_total'] = (int)$payload['free_credits_total'];
        }

        $docRef->set($updateData, ['merge' => true]);

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

        echo json_encode(['status' => 'success', 'message' => 'Agency account updated successfully.']);
        exit;
    }

    $requestId = $payload['request_id'] ?? null;
    $status = $payload['status'] ?? null; // 'approved' or 'rejected'
    $apiKey = $payload['api_key'] ?? null;
    $note = $payload['note'] ?? null;

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

        $accountRef->set([
            'location_id' => $locId,
            'location_name' => $locationName,
            'approved_sender_id' => $reqData['requested_id'],
            'nola_pro_api_key' => $apiKey, 
            'semaphore_api_key' => $apiKey,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);
    } elseif ($status === 'revoked') {
        // Clear the sender ID from the account mapping
        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $accountRef = $db->collection('integrations')->document($docId);
        
        $accountRef->set([
            'approved_sender_id' => null,
            'nola_pro_api_key'   => null,
            'semaphore_api_key'  => null,
            'updated_at'         => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);
    } elseif ($status === 'deleted') {
        // Physical deletion of the request document
        $requestRef->delete();
        echo json_encode(['status' => 'success', 'message' => "Request deleted successfully."]);
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => "Request $status and account updated."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'agencies') {
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

    echo json_encode(['status' => 'success', 'data' => $results]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'accounts') {
    $results = [];

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

        $results[] = [
            'id' => $intDocId, // Expected by frontend mapping
            'data' => [
                'location_id' => $locId,
                'location_name' => $locationName,
                'approved_sender_id' => $intData['approved_sender_id'] ?? null,
                'nola_pro_api_key'   => $intData['nola_pro_api_key'] ?? null,
                'api_key'            => $intData['api_key'] ?? null,
                'semaphore_api_key'  => $intData['semaphore_api_key'] ?? null,
                'credit_balance'     => (int)($intData['credit_balance'] ?? 0),
                'free_usage_count'   => (int)($intData['free_usage_count'] ?? 0),
                'free_credits_total' => (int)($intData['free_credits_total'] ?? 10)
            ]
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $results]);
    exit;
}
