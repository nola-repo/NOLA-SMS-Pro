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
    usort($unifiedLogs, function ($a, $b) {
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
        $senderId = $payload['sender_id'] ?? null;
        $apiKey = $payload['api_key'] ?? null;

        if (!$locId || !$senderId || !$apiKey) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing fields']);
            exit;
        }

        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $db->collection('integrations')->document($docId)->set([
            'approved_sender_id' => $senderId,
            'nola_pro_api_key' => $apiKey,
            'semaphore_api_key' => $apiKey, // for backward compat
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);

        echo json_encode(['status' => 'success', 'message' => 'Sender configuration updated successfully.']);
        exit;
    }

    // 2. Action: revoke_sender (Clear/Delete)
    if (isset($payload['action']) && $payload['action'] === 'revoke_sender') {
        $locId = $payload['location_id'] ?? null;

        if (!$locId) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
            exit;
        }

        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $db->collection('integrations')->document($docId)->set([
            'approved_sender_id' => null, // Set to null to clear
            'nola_pro_api_key' => null,
            'semaphore_api_key' => null,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);

        echo json_encode(['status' => 'success', 'message' => 'Sender ID has been revoked.']);
        exit;
    }

    $requestId = $payload['request_id'] ?? null;
    $status = $payload['status'] ?? null; // 'approved' or 'rejected'
    $apiKey = $payload['api_key'] ?? null;
    $note = $payload['note'] ?? null;

    if (!$requestId || !in_array($status, ['approved', 'rejected', 'delete'])) {
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

    if ($status === 'delete') {
        $requestRef->delete();
        echo json_encode(['status' => 'success', 'message' => 'Request deleted successfully.']);
        exit;
    }

    // 2. If approved, update the account mapping
    if ($status === 'approved') {
        // Format Doc ID for integrations
        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $accountRef = $db->collection('integrations')->document($docId);

        // Fetch location name for better record keeping
        $locationName = 'Unknown';
        $tokenSnap = $db->collection('ghl_tokens')->document((string)$locId)->snapshot();
        if ($tokenSnap->exists()) {
            $locationName = $tokenSnap->data()['location_name'] ?? 'Unknown';
        }

        $accountRef->set([
            'location_id' => $locId,
            'location_name' => $locationName,
            'approved_sender_id' => $reqData['requested_id'],
            'nola_pro_api_key' => $apiKey, // Manual assignment by boss
            'semaphore_api_key' => $apiKey, // Alias for backward compatibility
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);
    }

    echo json_encode(['status' => 'success', 'message' => "Request $status and account updated."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'accounts') {
    $results = [];

    // 1. Fetch all tokens (Master list of installations)
    $tokens = $db->collection('ghl_tokens')->documents();

    // 2. Fetch all integrations (For credit balances and sender IDs)
    $integrationsRaw = $db->collection('integrations')->documents();
    $integrationMap = [];
    foreach ($integrationsRaw as $intDoc) {
        $integrationMap[$intDoc->id()] = $intDoc->data();
    }

    foreach ($tokens as $tokenDoc) {
        $locId = $tokenDoc->id();
        $tokenData = $tokenDoc->data();

        // Skip the master 'ghl' settings document if it accidentally exists in this collection
        if ($locId === 'ghl')
            continue;

        $locationName = $tokenData['location_name'] ?? '';

        // --- THE FIX: Fetch missing location name using GHL API ---
        if (empty(trim($locationName))) {
            $accessToken = $tokenData['access_token'] ?? '';

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

                            // Save back to Firestore so we don't query it again
                            $db->collection('ghl_tokens')->document($locId)->set([
                                'location_name' => $locationName
                            ], ['merge' => true]);

                            // Sync name to integrations collection if it exists
                            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
                            if (isset($integrationMap[$intDocId])) {
                                $db->collection('integrations')->document($intDocId)->set([
                                    'location_name' => $locationName
                                ], ['merge' => true]);
                            }
                        }
                    }
                }
                catch (Exception $e) {
                    error_log("Failed to fetch location name for $locId: " . $e->getMessage());
                }
            }
        }

        if (empty(trim($locationName))) {
            $locationName = 'Unknown Location';
        }

        // 3. Merge with integrations data for the UI
        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $intData = $integrationMap[$intDocId] ?? [];

        $results[] = [
            'id' => $intDocId, // Expected by frontend mapping
            'data' => [
                'location_id' => $locId,
                'location_name' => $locationName,
                'approved_sender_id' => $intData['approved_sender_id'] ?? null,
                'credit_balance' => (int)($intData['credit_balance'] ?? 0),
                'free_usage_count' => (int)($intData['free_usage_count'] ?? 0)
            ]
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $results]);
    exit;
}
