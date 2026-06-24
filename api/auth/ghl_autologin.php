<?php
/**
 * GET/POST /api/auth/ghl_autologin
 *
 * Backward-compatible location-level iframe autologin for older user-app builds.
 * The agency app uses /api/agency/ghl_autologin with company_id; this endpoint
 * accepts location_id and prefers a linked location user. If the sub-account is
 * installed but has no location user yet, agency owners can fall back to an
 * agency JWT scoped to that location.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/../install_helpers.php';
require_once __DIR__ . '/user_profile_helper.php';
require_once __DIR__ . '/../services/LocationUserResolver.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $decoded = json_decode(file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
}

$locationId = trim((string)(
    $_GET['location_id']
    ?? $_GET['locationId']
    ?? $input['location_id']
    ?? $input['locationId']
    ?? ''
));
$companyId = trim((string)(
    $_GET['company_id']
    ?? $_GET['companyId']
    ?? $input['company_id']
    ?? $input['companyId']
    ?? ''
));

$jwtSecret = getenv('JWT_SECRET') ?: '';
if ($jwtSecret === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
    exit;
}

function nola_auth_location_doc_id(string $locationId): string
{
    return 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
}

function nola_auth_find_user_for_location($db, string $locationId): ?array
{
    return LocationUserResolver::find($db, $locationId);
}

function nola_auth_has_agency_token($db, string $companyId): bool
{
    $tokenDoc = $db->collection('ghl_tokens')->document($companyId)->snapshot();
    if ($tokenDoc->exists()) {
        $data = $tokenDoc->data();
        $isAgencyApp = ($data['appType'] ?? '') === 'agency';
        $isCompanyToken = ($data['userType'] ?? 'Company') === 'Company';
        if ($isAgencyApp && $isCompanyToken) {
            return true;
        }
    }

    $tokenDocs = $db->collection('ghl_tokens')
        ->where('companyId', '=', $companyId)
        ->documents();

    foreach ($tokenDocs as $doc) {
        if (!$doc->exists()) {
            continue;
        }

        $data = $doc->data();
        $isAgencyApp = ($data['appType'] ?? '') === 'agency';
        $isCompanyToken = ($data['userType'] ?? '') === 'Company' || $doc->id() === $companyId;

        if ($isAgencyApp && $isCompanyToken) {
            return true;
        }
    }

    return false;
}

function nola_auth_agency_autologin(
    $db,
    string $companyId,
    string $jwtSecret,
    ?string $locationId = null,
    ?string $locationName = null
): void
{
    $authCollection = 'agency_users';
    $results = $db->collection('agency_users')
        ->where('company_id', '=', $companyId)
        ->limit(1)
        ->documents();

    $userId = null;
    $userData = null;
    foreach ($results as $doc) {
        if ($doc->exists()) {
            $userId = $doc->id();
            $userData = $doc->data();
            break;
        }
    }

    if (!$userData) {
        $authCollection = 'users';
        $results = $db->collection('users')
            ->where('role', '=', 'agency')
            ->where('company_id', '=', $companyId)
            ->limit(1)
            ->documents();

        foreach ($results as $doc) {
            if ($doc->exists()) {
                $userId = $doc->id();
                $userData = $doc->data();
                break;
            }
        }
    }

    if (!$userData) {
        if (!nola_auth_has_agency_token($db, $companyId)) {
            http_response_code(404);
            echo json_encode([
                'error' => 'No agency account is linked to this GHL company. Please install the Agency App first.',
                'code' => 'LOCATION_NOT_INSTALLED',
            ]);
            return;
        }

        $userData = [
            'role' => 'agency',
            'company_id' => $companyId,
            'createdAt' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            'active' => true,
            'email' => 'agency_' . $companyId . '@ghl.nolasmspro.com',
        ];
        $newUserRef = $db->collection('agency_users')->add($userData);
        $userId = $newUserRef->id();
        $authCollection = 'agency_users';
    }

    if (empty($userData['active'])) {
        http_response_code(403);
        echo json_encode(['error' => 'This agency account has been deactivated.', 'code' => 'LOCATION_INACTIVE']);
        return;
    }

    if (empty($userData['company_name'])) {
        foreach (['ghl_agency_tokens', 'ghl_tokens'] as $collection) {
            try {
                $snap = $db->collection($collection)->document($companyId)->snapshot();
                if (!$snap->exists()) {
                    continue;
                }
                $tokenData = $snap->data();
                $companyName = $tokenData['company_name']
                    ?? $tokenData['agency_name']
                    ?? $tokenData['location_name']
                    ?? null;
                if ($companyName !== null && trim((string)$companyName) !== '') {
                    $userData['company_name'] = trim((string)$companyName);
                    break;
                }
            } catch (Exception $ignored) {
            }
        }
    }

    if ($locationId !== null && trim($locationId) !== '') {
        $userData['active_location_id'] = trim($locationId);
        $userData['location_id'] = trim($locationId);
        if ($locationName !== null && trim($locationName) !== '') {
            $userData['location_name'] = trim($locationName);
        }
    }

    $claims = [
        'sub' => $userId,
        'email' => $userData['email'] ?? '',
        'role' => 'agency',
        'company_id' => $companyId,
        'auth_collection' => $authCollection,
    ];
    if ($locationId !== null && trim($locationId) !== '') {
        $claims['location_id'] = trim($locationId);
    }

    $token = jwt_sign($claims, $jwtSecret, 28800);

    $response = [
        'token' => $token,
        'role' => 'agency',
        'company_id' => $companyId,
        'user' => auth_user_payload_for_api($userData, (string)($userData['email'] ?? '')),
    ];
    if ($locationId !== null && trim($locationId) !== '') {
        $response['location_id'] = trim($locationId);
    }

    echo json_encode($response);
}

function nola_auth_first_non_empty(...$values): string
{
    foreach ($values as $value) {
        $str = trim((string)($value ?? ''));
        if ($str !== '') {
            return $str;
        }
    }

    return '';
}

function nola_auth_is_suspicious_location_id(string $locationId): bool
{
    $locationId = trim($locationId);
    if ($locationId === '') {
        return false;
    }

    // Real GHL Location IDs are normally opaque alpha-numeric strings. Numeric-only
    // values in the iframe have repeatedly indicated company/account context.
    return (bool)preg_match('/^\d+$/', $locationId);
}

function nola_auth_invalid_location_response(string $locationId, ?string $companyId = null): void
{
    error_log('[api/auth/ghl_autologin.php] Invalid/suspicious location_id received: ' . json_encode([
        'location_id' => $locationId,
        'company_id' => $companyId,
        'reason' => 'numeric_only_not_installed',
    ]));

    http_response_code(422);
    echo json_encode([
        'error' => 'The provided location_id does not match an installed GHL subaccount. Check frontend location detection.',
        'code' => 'INVALID_GHL_LOCATION_ID',
        'location_id' => $locationId,
    ]);
}

try {
    $db = get_firestore();

    if ($locationId === '' && $companyId !== '') {
        nola_auth_agency_autologin($db, $companyId, $jwtSecret);
        exit;
    }

    if ($locationId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'location_id is required.', 'code' => 'LOCATION_ID_REQUIRED']);
        exit;
    }

    $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
    $tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
    $intSnap = $db->collection('integrations')->document(nola_auth_location_doc_id($locationId))->snapshot();
    $intData = $intSnap->exists() ? $intSnap->data() : [];

    $installed = $tokenSnap->exists() || $intSnap->exists();
    if (!$installed) {
        if (nola_auth_is_suspicious_location_id($locationId)) {
            nola_auth_invalid_location_response($locationId, $companyId !== '' ? $companyId : null);
            exit;
        }

        error_log('[api/auth/ghl_autologin.php] Location not installed: ' . json_encode([
            'location_id' => $locationId,
            'company_id' => $companyId !== '' ? $companyId : null,
            'token_exists' => false,
            'integration_exists' => false,
        ]));

        http_response_code(404);
        echo json_encode(['error' => 'NOLA SMS Pro is not installed for this location.', 'code' => 'LOCATION_NOT_INSTALLED']);
        exit;
    }

    if ($tokenSnap->exists() && !install_token_active_for_sms(true, $tokenData)) {
        http_response_code(403);
        echo json_encode(['error' => 'NOLA SMS Pro is not active for this location.', 'code' => 'LOCATION_INACTIVE']);
        exit;
    }

    $match = nola_auth_find_user_for_location($db, $locationId);
    if ($match === null) {
        $resolvedCompanyId = nola_auth_first_non_empty(
            $companyId,
            $tokenData['companyId'] ?? null,
            $tokenData['company_id'] ?? null,
            $intData['companyId'] ?? null,
            $intData['company_id'] ?? null
        );

        $tokenCompanyId = nola_auth_first_non_empty($tokenData['companyId'] ?? null, $tokenData['company_id'] ?? null);
        if ($companyId !== '' && $tokenCompanyId !== '' && $tokenCompanyId !== $companyId) {
            http_response_code(403);
            echo json_encode(['error' => 'Location is not authorized for this company.', 'code' => 'LOCATION_COMPANY_MISMATCH']);
            exit;
        }

        if (!$tokenSnap->exists() || $resolvedCompanyId === '' || $tokenCompanyId === '' || $tokenCompanyId !== $resolvedCompanyId) {
            http_response_code(404);
            echo json_encode([
                'error' => 'No user account is linked to this GHL location. Please finish installation or log in manually.',
                'code' => 'LOCATION_USER_NOT_FOUND',
            ]);
            exit;
        }

        if (!nola_auth_has_agency_token($db, $resolvedCompanyId)) {
            http_response_code(404);
            echo json_encode([
                'error' => 'No agency account is linked to this GHL company. Please install the Agency App first.',
                'code' => 'LOCATION_NOT_INSTALLED',
            ]);
            exit;
        }

        $locationName = nola_auth_first_non_empty(
            $tokenData['location_name'] ?? null,
            $tokenData['locationName'] ?? null,
            $intData['location_name'] ?? null,
            $intData['locationName'] ?? null
        );

        nola_auth_agency_autologin(
            $db,
            $resolvedCompanyId,
            $jwtSecret,
            $locationId,
            $locationName !== '' ? $locationName : null
        );
        exit;
    }

    $userId = $match['id'];
    $userData = $match['data'];
    if (empty($userData['active'])) {
        http_response_code(403);
        echo json_encode(['error' => 'This user account has been deactivated.', 'code' => 'LOCATION_INACTIVE']);
        exit;
    }

    $companyId = $userData['company_id']
        ?? $intData['companyId']
        ?? $intData['company_id']
        ?? $tokenData['companyId']
        ?? $tokenData['company_id']
        ?? null;

    $locationName = $userData['location_name']
        ?? $intData['location_name']
        ?? $tokenData['location_name']
        ?? null;
    if ($locationName !== null) {
        $userData['location_name'] = $locationName;
    }

    $companyName = $userData['company_name']
        ?? $intData['company_name']
        ?? $tokenData['company_name']
        ?? null;
    if ($companyName !== null) {
        $userData['company_name'] = $companyName;
    }

    $token = jwt_sign([
        'sub' => $userId,
        'email' => $userData['email'] ?? '',
        'role' => 'user',
        'company_id' => $companyId,
        'location_id' => $locationId,
        'auth_collection' => 'users',
    ], $jwtSecret, 28800);

    echo json_encode([
        'token' => $token,
        'role' => 'user',
        'company_id' => $companyId,
        'location_id' => $locationId,
        'user' => auth_user_payload_for_api($userData, (string)($userData['email'] ?? '')),
    ]);
} catch (LocationUserResolutionException $e) {
    error_log('[api/auth/ghl_autologin.php] Location ownership needs repair: ' . $e->getMessage());
    http_response_code(409);
    echo json_encode([
        'error' => 'Multiple users are linked to this location.',
        'code' => 'DUPLICATE_LOCATION_USERS',
        'location_id' => $locationId ?? null,
        'repair_hint' => 'Run scripts/audit_location_users.php for this location, then set the canonical owner in location_owners.',
    ]);
} catch (Exception $e) {
    error_log('[api/auth/ghl_autologin.php] Auto-login failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Auto-login failed.']);
}
