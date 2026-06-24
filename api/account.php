<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/cache_helper.php';
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

function account_is_suspicious_location_id(string $locationId): bool
{
    $locationId = trim($locationId);
    if ($locationId === '') {
        return false;
    }

    // Numeric-only values observed from the iframe are account/company context,
    // not GHL subaccount location IDs.
    return (bool)preg_match('/^\d+$/', $locationId);
}

function account_invalid_location_response(string $locationId): void
{
    error_log('[api/account.php] Invalid/suspicious location_id received: ' . json_encode([
        'location_id' => $locationId,
        'reason' => 'numeric_only_not_installed',
    ]));

    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'The provided location_id does not match an installed GHL subaccount. Check frontend location detection.',
        'code' => 'INVALID_GHL_LOCATION_ID',
        'location_id' => $locationId,
    ]);
    exit;
}

// 1. Authentication
validate_api_request();

// 2. Get Location ID
$locId = get_ghl_location_id();
$requestedLocId = $locId;

try {
    $db = get_firestore();
    $authUserData = null;
    $authJwt = account_extract_bearer_token();
    if ($authJwt) {
        $jwtSecret = getenv('JWT_SECRET');
        if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
            error_log('[api/account.php] JWT_SECRET missing; cannot verify auth token.');
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Server configuration error']);
            exit;
        }
        $payload = jwt_verify($authJwt, $jwtSecret);
        $authUserId = $payload['sub'] ?? null;
        $authRole = (string)($payload['role'] ?? 'user');
        $authCollection = (string)($payload['auth_collection'] ?? ($authRole === 'agency' ? 'agency_users' : 'users'));
        if ($authUserId) {
            $authSnap = $db->collection($authCollection)->document((string)$authUserId)->snapshot();
            if (!$authSnap->exists() && $authCollection !== 'users') {
                $authSnap = $db->collection('users')->document((string)$authUserId)->snapshot();
            }
            if ($authSnap->exists()) {
                $candidate = $authSnap->data();
                $candidateRole = $candidate['role'] ?? 'user';
                $candidateLocs = install_user_location_ids($candidate);
                $candidateLoc = $candidateLocs[0] ?? null;
                $candidateActive = !array_key_exists('active', $candidate) || !empty($candidate['active']);
                if ($candidateActive && $candidateRole === 'agency') {
                    $authUserData = $candidate;
                } elseif ($candidateActive && $candidateRole !== 'agency' && $candidateLoc) {
                    $authUserData = $candidate;
                    $locId = (string)$candidateLoc;
                }
            }
        }
    }

    // POST handler for update_profile action
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';

        if ($action === 'update_profile') {
            if (empty($authUserId)) {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid token or user session']);
                exit;
            }

            $name  = trim($input['name'] ?? '');
            $email = trim($input['email'] ?? '');
            $phone = trim($input['phone'] ?? '');

            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Name is required']);
                exit;
            }

            $nameParts = preg_split('/\s+/', $name);
            $firstName = $nameParts[0] ?? '';
            $lastName  = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

            $db->collection('users')->document($authUserId)->update([
                ['path' => 'name', 'value' => $name],
                ['path' => 'firstName', 'value' => $firstName],
                ['path' => 'lastName', 'value' => $lastName],
                ['path' => 'email', 'value' => $email],
                ['path' => 'phone', 'value' => $phone],
                ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())]
            ]);

            if ($locId) {
                NolaCache::delete("account_profile_" . $locId);
            }
            NolaCache::invalidateAdminDashboard();

            echo json_encode([
                'status'  => 'success',
                'message' => 'Profile updated successfully.'
            ]);
            exit;
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

    if (account_is_suspicious_location_id((string)$locId)) {
        $precheckTokenSnap = $db->collection('ghl_tokens')->document((string)$locId)->snapshot();
        $precheckIntDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $precheckIntSnap = $db->collection('integrations')->document($precheckIntDocId)->snapshot();
        if (!$precheckTokenSnap->exists() && !$precheckIntSnap->exists()) {
            account_invalid_location_response((string)$locId);
        }
    }

    // Cache check for GET requests
    $cacheKey = "account_profile_" . $locId;
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $cachedData = NolaCache::get($cacheKey);
        if ($cachedData !== null) {
            echo json_encode($cachedData);
            exit;
        }
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

    if (!$tokenSnap->exists() && !$intSnap->exists() && account_is_suspicious_location_id((string)$locId)) {
        account_invalid_location_response((string)$locId);
    }

    // 3b. Fetch subaccount owner's profile for Settings personal details
    $userEmail = null;
    $userPhone = null;
    $userName  = null;
    $userFirstName = null;
    $userLastName  = null;

    try {
        if ($authUserData !== null) {
            $authRole = (string)($authUserData['role'] ?? 'user');
            $matchesLocation = (string)($authUserData['active_location_id'] ?? '') === (string)$requestedLocId;
            if ($authRole === 'agency' || $matchesLocation) {
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
        }

        if ($userName === null && $userEmail === null && $userPhone === null) {
            $userQuery = $db->collection('users')
            ->where('active_location_id', '=', (string)$requestedLocId)
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
                ->where('location_id', '=', (string)$requestedLocId)
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
            $linked = install_linked_account_for_location($db, (string)$requestedLocId, false);
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
    $responsePayload = [
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
    ];

    $registryKey = "credits_registry_" . $locId;
    NolaCache::setWithRegistry($registryKey, $cacheKey, $responsePayload, 300);

    echo json_encode($responsePayload);

} catch (\Throwable $e) {
    error_log('[api/account.php] Internal error: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal Server Error',
    ]);
}
