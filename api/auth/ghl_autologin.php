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

function nola_auth_autologin_log(string $code, ?string $locationId = null, ?string $companyId = null, array $context = []): void
{
    error_log('[api/auth/ghl_autologin.php] ' . json_encode(array_merge([
        'code' => $code,
        'location_id' => $locationId,
        'company_id' => $companyId,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'path' => $_SERVER['REQUEST_URI'] ?? '/api/auth/ghl_autologin',
    ], $context)));
}

function nola_auth_error_response(
    int $status,
    string $code,
    string $message,
    ?string $locationId = null,
    ?string $companyId = null,
    array $extra = []
): void {
    nola_auth_autologin_log($code, $locationId, $companyId, $extra);
    http_response_code($status);
    echo json_encode(array_merge([
        'error' => $message,
        'code' => $code,
    ], $extra));
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
            nola_auth_error_response(
                404,
                'LOCATION_NOT_INSTALLED',
                'No agency account is linked to this GHL company. Please install the Agency App first.',
                $locationId,
                $companyId
            );
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
        nola_auth_error_response(
            403,
            'LOCATION_INACTIVE',
            'This agency account has been deactivated.',
            $locationId,
            $companyId,
            ['auth_collection' => $authCollection]
        );
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
    nola_auth_error_response(422, 'INVALID_GHL_LOCATION_ID', 'The provided location_id does not match an installed GHL subaccount. Check frontend location detection.', $locationId, $companyId, [
        'location_id' => $locationId,
        'reason' => 'numeric_only_not_installed',
    ]);
}

try {
    $db = get_firestore();

    if ($locationId === '' && $companyId !== '') {
        nola_auth_agency_autologin($db, $companyId, $jwtSecret);
        exit;
    }

    if ($locationId === '') {
        nola_auth_error_response(400, 'LOCATION_ID_REQUIRED', 'location_id is required.', null, $companyId !== '' ? $companyId : null);
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

        nola_auth_error_response(404, 'LOCATION_NOT_INSTALLED', 'NOLA SMS Pro is not installed for this location.', $locationId, $companyId !== '' ? $companyId : null, [
            'token_exists' => false,
            'integration_exists' => false,
        ]);
    }

    if ($tokenSnap->exists() && !install_token_active_for_sms(true, $tokenData)) {
        nola_auth_error_response(403, 'LOCATION_INACTIVE', 'NOLA SMS Pro is not active for this location.', $locationId, $companyId !== '' ? $companyId : null, [
            'token_exists' => true,
            'integration_exists' => $intSnap->exists(),
        ]);
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
            nola_auth_error_response(403, 'LOCATION_COMPANY_MISMATCH', 'Location is not authorized for this company.', $locationId, $companyId, [
                'token_company_id' => $tokenCompanyId,
                'resolved_company_id' => $resolvedCompanyId,
            ]);
        }

        if (!$tokenSnap->exists() || $resolvedCompanyId === '' || $tokenCompanyId === '' || $tokenCompanyId !== $resolvedCompanyId) {
            nola_auth_error_response(404, 'LOCATION_USER_NOT_FOUND', 'No user account is linked to this GHL location. Please finish installation or log in manually.', $locationId, $companyId !== '' ? $companyId : null, [
                'token_exists' => $tokenSnap->exists(),
                'integration_exists' => $intSnap->exists(),
                'token_company_id' => $tokenCompanyId,
                'resolved_company_id' => $resolvedCompanyId,
            ]);
        }

        if (!nola_auth_has_agency_token($db, $resolvedCompanyId)) {
            nola_auth_error_response(
                404,
                'LOCATION_NOT_INSTALLED',
                'No agency account is linked to this GHL company. Please install the Agency App first.',
                $locationId,
                $resolvedCompanyId,
                ['token_company_id' => $tokenCompanyId]
            );
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
        nola_auth_error_response(403, 'LOCATION_INACTIVE', 'This user account has been deactivated.', $locationId, $companyId !== '' ? $companyId : null, [
            'user_id' => (string)$userId,
        ]);
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

    // The same user may legitimately own or belong to more than one installed
    // location. Return a session payload scoped to the iframe's requested
    // location so stale profile fields cannot put the frontend back into a
    // different subaccount immediately after successful auto-login.
    $userData['active_location_id'] = $locationId;
    $userData['location_id'] = $locationId;
    $userData['ghl_token_ref'] = 'ghl_tokens/' . $locationId;

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
    nola_auth_error_response(409, 'DUPLICATE_LOCATION_USERS', 'Multiple users are linked to this location.', $locationId ?? null, $companyId !== '' ? $companyId : null, [
        'location_id' => $locationId ?? null,
        'repair_hint' => 'Run scripts/audit_location_users.php for this location, then set the canonical owner in location_owners.',
    ]);
} catch (Exception $e) {
    nola_auth_error_response(500, 'AUTOLOGIN_FAILED', 'Auto-login failed.', $locationId ?? null, $companyId !== '' ? $companyId : null, [
        'message' => $e->getMessage(),
    ]);
}
