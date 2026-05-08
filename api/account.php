<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// 1. Authentication
validate_api_request();

// 2. Get Location ID
$locId = get_ghl_location_id();

if (!$locId) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing location_id'
    ]);
    exit;
}

try {
    $db = get_firestore();
    
    // 3. Database Query
    $locationName = 'Unknown';

    // 1. FIRST check the newer 'ghl_tokens' collection
    $tokenSnap = $db->collection('ghl_tokens')->document((string)$locId)->snapshot();
    if ($tokenSnap->exists()) {
        $tokenData = $tokenSnap->data();
        $locationName = $tokenData['location_name'] ?? 'Unknown';
    }

    // 2. FALLBACK to 'integrations' collection if still Unknown
    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);
    $intRef = $db->collection('integrations')->document($intDocId);
    $intSnap = $intRef->snapshot();

    if ($locationName === 'Unknown' || empty($locationName)) {
        if ($intSnap->exists()) {
            $intData = $intSnap->data();
            $locationName = $intData['location_name'] ?? 'Unknown';
        }
    }

    $intData = $intSnap->exists() ? $intSnap->data() : [];

    // 3b. Fetch subaccount owner's profile for Settings personal details
    $userEmail = null;
    $userPhone = null;
    $userName = null;

    try {
        $userQuery = $db->collection('users')
            ->where('active_location_id', '=', (string)$locId)
            ->limit(1)
            ->documents();

        foreach ($userQuery as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $userData = $doc->data();
            $userName = isset($userData['name']) ? trim((string)$userData['name']) : '';
            $userEmail = isset($userData['email']) ? trim((string)$userData['email']) : null;
            $userPhone = isset($userData['phone']) ? trim((string)$userData['phone']) : null;

            if ($userName === '') {
                $first = isset($userData['firstName']) ? trim((string)$userData['firstName']) : '';
                $last = isset($userData['lastName']) ? trim((string)$userData['lastName']) : '';
                $joined = trim($first . ' ' . $last);
                $userName = $joined !== '' ? $joined : null;
            }
            break;
        }

        // Fallback for migrated records that may rely on subaccounts linkage.
        if ($userName === null && $userEmail === null && $userPhone === null) {
            $subQuery = $db->collectionGroup('subaccounts')
                ->where('location_id', '=', (string)$locId)
                ->limit(1)
                ->documents();

            foreach ($subQuery as $subDoc) {
                if (!$subDoc->exists()) {
                    continue;
                }

                $parentUserRef = $subDoc->reference()->parent()->parent();
                if ($parentUserRef === null) {
                    break;
                }
                $parentSnap = $parentUserRef->snapshot();
                if (!$parentSnap->exists()) {
                    break;
                }

                $userData = $parentSnap->data();
                $userName = isset($userData['name']) ? trim((string)$userData['name']) : '';
                $userEmail = isset($userData['email']) ? trim((string)$userData['email']) : null;
                $userPhone = isset($userData['phone']) ? trim((string)$userData['phone']) : null;

                if ($userName === '') {
                    $first = isset($userData['firstName']) ? trim((string)$userData['firstName']) : '';
                    $last = isset($userData['lastName']) ? trim((string)$userData['lastName']) : '';
                    $joined = trim($first . ' ' . $last);
                    $userName = $joined !== '' ? $joined : null;
                }
                break;
            }
        }
    } catch (\Throwable $e) {
        error_log("[api/account.php] Failed to fetch user credentials: " . $e->getMessage());
    }

    // 4. Response format
    echo json_encode([
        'status' => 'success',
        'data' => [
            'location_id' => $locId,
            'location_name' => $locationName,
            'name' => $userName,
            'full_name' => $userName,
            'email' => $userEmail,
            'email_address' => $userEmail,
            'phone' => $userPhone,
            'phone_number' => $userPhone,
            'approved_sender_id' => $intData['approved_sender_id'] ?? null,
            'free_usage_count' => $intData['free_usage_count'] ?? 0,
            'free_credits_total' => $intData['free_credits_total'] ?? 10,
            'credit_balance' => (int)($intData['credit_balance'] ?? 0),
            'currency' => $intData['currency'] ?? 'PHP'
        ]
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal Server Error',
        'details' => $e->getMessage()
    ]);
}
