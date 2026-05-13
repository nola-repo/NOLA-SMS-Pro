<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/install_helpers.php';
require_once __DIR__ . '/services/CreditManager.php';

function account_extract_bearer_token(): ?string
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$authHeader && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }

    if (is_string($authHeader) && preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
        return trim((string)$m[1]);
    }

    return null;
}

// 1. Authentication
validate_api_request();

// 2. Get Location ID
$locId = get_ghl_location_id();

try {
    $db = get_firestore();
    $authUserData = null;
    $authJwt = account_extract_bearer_token();
    if ($authJwt) {
        $jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
        $payload = jwt_verify($authJwt, $jwtSecret);
        $authUserId = $payload['sub'] ?? null;
        if ($authUserId) {
            $authSnap = $db->collection('users')->document((string)$authUserId)->snapshot();
            if ($authSnap->exists()) {
                $candidate = $authSnap->data();
                $candidateRole = $candidate['role'] ?? 'user';
                $candidateLoc = $candidate['active_location_id'] ?? null;
                $candidateActive = !array_key_exists('active', $candidate) || !empty($candidate['active']);
                if ($candidateActive && $candidateRole !== 'agency' && $candidateLoc) {
                    $authUserData = $candidate;
                    $locId = (string)$candidateLoc;
                }
            }
        }
    }

    if (!$locId) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing location_id'
        ]);
        exit;
    }
    
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
    $userName  = null;
    $userFirstName = null;
    $userLastName  = null;

    try {
        if ($authUserData !== null && (string)($authUserData['active_location_id'] ?? '') === (string)$locId) {
            $userName      = isset($authUserData['name'])      ? trim((string)$authUserData['name'])      : '';
            $userFirstName = isset($authUserData['firstName']) ? trim((string)$authUserData['firstName']) : null;
            $userLastName  = isset($authUserData['lastName'])  ? trim((string)$authUserData['lastName'])  : null;
            $userEmail     = isset($authUserData['email'])     ? trim((string)$authUserData['email'])     : null;
            $userPhone     = isset($authUserData['phone'])     ? trim((string)$authUserData['phone'])     : null;

            if ($userName === '') {
                $joined = trim(($userFirstName ?? '') . ' ' . ($userLastName ?? ''));
                $userName = $joined !== '' ? $joined : null;
            }
        }

        if ($userName === null && $userEmail === null && $userPhone === null) {
            $userQuery = $db->collection('users')
            ->where('active_location_id', '=', (string)$locId)
            ->limit(1)
            ->documents();

            foreach ($userQuery as $doc) {
                if (!$doc->exists()) {
                    continue;
                }
                $userData = $doc->data();
                $userName      = isset($userData['name'])      ? trim((string)$userData['name'])      : '';
                $userFirstName = isset($userData['firstName']) ? trim((string)$userData['firstName']) : null;
                $userLastName  = isset($userData['lastName'])  ? trim((string)$userData['lastName'])  : null;
                $userEmail     = isset($userData['email'])     ? trim((string)$userData['email'])     : null;
                $userPhone     = isset($userData['phone'])     ? trim((string)$userData['phone'])     : null;

                if ($userName === '') {
                    $joined = trim(($userFirstName ?? '') . ' ' . ($userLastName ?? ''));
                    $userName = $joined !== '' ? $joined : null;
                }
                break;
            }
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
                $userName      = isset($userData['name'])      ? trim((string)$userData['name'])      : '';
                $userFirstName = isset($userData['firstName']) ? trim((string)$userData['firstName']) : null;
                $userLastName  = isset($userData['lastName'])  ? trim((string)$userData['lastName'])  : null;
                $userEmail     = isset($userData['email'])     ? trim((string)$userData['email'])     : null;
                $userPhone     = isset($userData['phone'])     ? trim((string)$userData['phone'])     : null;

                if ($userName === '') {
                    $joined = trim(($userFirstName ?? '') . ' ' . ($userLastName ?? ''));
                    $userName = $joined !== '' ? $joined : null;
                }
                break;
            }
        }

        if ($userName === null && $userEmail === null && $userPhone === null) {
            $linked = install_linked_account_for_location($db, (string)$locId, false);
            if ($linked !== null) {
                $userName = $linked['name'] !== '' ? $linked['name'] : null;
                $userEmail = $linked['email'] !== '' ? $linked['email'] : null;
            }
        }
    } catch (\Throwable $e) {
        error_log("[api/account.php] Failed to fetch user credentials: " . $e->getMessage());
    }

    // If firstName/lastName weren't stored separately, split from full name
    if (($userFirstName === null || $userFirstName === '') && $userName) {
        $nameParts     = preg_split('/\s+/', trim((string)$userName));
        $userFirstName = $nameParts[0] ?? null;
        $userLastName  = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : null;
    }

    $creditBalanceDisplay = (new CreditManager())->get_balance((string)$locId);
    $registrationStatus = install_registration_status_for_account($db, (string)$locId);

    // 4. Response format
    echo json_encode([
        'status' => 'success',
        'data' => [
            'location_id'       => $locId,
            'location_name'     => $locationName,
            'name'              => $userName,
            'full_name'         => $userName,
            'firstName'         => $userFirstName,
            'lastName'          => $userLastName,
            'email'             => $userEmail,
            'email_address'     => $userEmail,
            'phone'             => $userPhone,
            'phone_number'      => $userPhone,
            'registration_status' => $registrationStatus,
            'is_registered'       => $registrationStatus === 'registered',
            'approved_sender_id'  => $intData['approved_sender_id'] ?? null,
            'free_usage_count'    => $intData['free_usage_count'] ?? 0,
            'free_credits_total'  => $intData['free_credits_total'] ?? 10,
            'credit_balance'      => $creditBalanceDisplay,
            'currency'            => $intData['currency'] ?? 'PHP'
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
