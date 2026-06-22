<?php

/**
 * Validates API key from X-Webhook-Secret header.
 * Call at the top of protected endpoints.
 */
function validate_api_request(): void
{
    // Try standard PHP server headers first
    $receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

    if (!$receivedSecret) {
        // Fallback: search all headers for the secret (Apache/Cloud Run compatibility)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-Webhook-Secret') === 0) {
                $receivedSecret = $value;
                break;
            }
        }
    }

    // Fallback: Check Query String for Webhooks that don't support custom headers (e.g Semaphore, CRON)
    if (!$receivedSecret) {
        $receivedSecret = $_GET['secret'] ?? $_GET['token'] ?? '';
    }

    $expectedSecret = getenv('WEBHOOK_SECRET');
    if ($expectedSecret === false || trim((string)$expectedSecret) === '') {
        Logger::error('Server misconfiguration: WEBHOOK_SECRET missing', ['method' => 'webhook-secret']);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server misconfiguration: WEBHOOK_SECRET missing']);
        exit;
    }

    if (!hash_equals($expectedSecret, (string)$receivedSecret)) {
        Logger::auth(false, 'webhook-secret', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access']);
        exit;
    }

    Logger::auth(true, 'webhook-secret', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
}

/**
 * Gets the GHL Location ID from headers or query parameters.
 * Required for multi-tenant data scoping.
 */
function get_ghl_location_id(): ?string
{
    // Try standard PHP server headers (case-insensitive search)
    $locId = $_SERVER['HTTP_X_GHL_LOCATION_ID'] ??
        $_SERVER['HTTP_X_GHL_LOCATIONID'] ??
        null;

    if (!$locId) {
        // Fallback to searching all headers (some environments don't use HTTP_ prefix correctly)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-GHL-Location-Id') === 0 || strcasecmp($key, 'X-GHL-LocationID') === 0) {
                $locId = $value;
                break;
            }
        }
    }

    if (!$locId) {
        $locId = $_GET['location_id'] ?? $_GET['locationId'] ?? null;
    }

    // Special robust handling for GHL's dynamic values:
    // If it looks like a variable template that wasn't replaced, treat as null
    if ($locId && strpos((string)$locId, '{{') !== false) {
        return null;
    }

    return $locId ? (string)$locId : null;
}

/**
 * Validates the JWT from the Authorization: Bearer header.
 * Uses the centralized jwt_helper.php library.
 *
 * @return array  Decoded token payload (sub, email, role, …)
 * @exit          Sends 401 JSON and exits on failure
 */
function validate_jwt(): array
{
    require_once __DIR__ . '/jwt_helper.php';

    // --- Extract the Bearer token ---
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authHeader) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        Logger::auth(false, 'jwt', ['reason' => 'missing-bearer-header']);
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
        exit;
    }

    $token = substr($authHeader, 7); // strip "Bearer "
    $secret = getenv('JWT_SECRET');
    if ($secret === false || trim((string)$secret) === '') {
        Logger::error('Server misconfiguration: JWT secret missing', ['method' => 'jwt']);
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfiguration: JWT secret missing.']);
        exit;
    }

    $payload = jwt_verify($token, $secret);

    if (!$payload) {
        Logger::auth(false, 'jwt', ['reason' => 'invalid-or-expired-token']);
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
        exit;
    }

    Logger::auth(true, 'jwt', ['sub' => $payload['sub'] ?? null, 'role' => $payload['role'] ?? null]);
    return $payload;
}

/**
 * List distinct GHL location IDs that have a sub-account token under this company
 * (excludes the agency-level ghl_tokens/{companyId} document).
 *
 * @return array<string, string> location_id => location_name (name may be empty)
 */
function auth_locations_under_company($db, string $companyId): array
{
    $out = [];
    $companyId = (string) $companyId;
    $tokenDocs = $db->collection('ghl_tokens')->where('companyId', '=', $companyId)->documents();

    foreach ($tokenDocs as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        $data = $doc->data();
        $isAgency = ($data['appType'] ?? '') === 'agency' || $doc->id() === $companyId;
        if ($isAgency) {
            continue;
        }
        $locId = $data['locationId'] ?? $data['location_id'] ?? $doc->id();
        if (!$locId || (string) $locId === $companyId) {
            continue;
        }
        $locId = (string) $locId;
        if (!isset($out[$locId])) {
            $out[$locId] = (string) ($data['location_name'] ?? $data['locationName'] ?? '');
        }
    }

    return $out;
}

/**
 * When the install JWT has company_id but no location_id, return the only location
 * under that company if it is unambiguous. Otherwise null (zero or multiple locations).
 *
 * @return array{location_id: string, location_name: string}|null
 */
function auth_infer_single_location_for_company($db, string $companyId): ?array
{
    $map = auth_locations_under_company($db, $companyId);
    if (count($map) !== 1) {
        return null;
    }
    $locationId = array_key_first($map);
    $locationName = $map[$locationId] ?? '';

    return ['location_id' => $locationId, 'location_name' => $locationName];
}

/**
 * True if ghl_tokens/{locationId} exists and is scoped to this GHL company (not the agency doc).
 */
function auth_location_belongs_to_company($db, string $locationId, string $companyId): bool
{
    $locationId = (string) $locationId;
    $companyId = (string) $companyId;
    if ($locationId === '' || $companyId === '' || $locationId === $companyId) {
        return false;
    }
    $snap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
    if ($snap->exists()) {
        $data = $snap->data();
        if (($data['appType'] ?? '') === 'agency') {
            return false;
        }
        $co = $data['companyId'] ?? $data['company_id'] ?? null;

        return $co !== null && (string) $co === $companyId;
    }

    $subSnap = $db->collection('agency_subaccounts')->document($locationId)->snapshot();
    if ($subSnap->exists()) {
        $aid = trim((string) ($subSnap->data()['agency_id'] ?? ''));

        return $aid !== '' && $aid === $companyId;
    }

    return false;
}

/**
 * Bearer token extractor (multiple header forms). Returns raw JWT without "Bearer ".
 */
function auth_extract_bearer_token_optional(): ?string
{
    $headerCandidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['Authorization'] ?? '',
        $_SERVER['HTTP_X_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        try {
            foreach (getallheaders() ?: [] as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    continue;
                }
                if (strcasecmp($key, 'Authorization') === 0) {
                    $headerCandidates[] = $value;
                }
                if (strcasecmp($key, 'X-Authorization') === 0 || strcasecmp($key, 'X-Auth-Token') === 0) {
                    $headerCandidates[] = $value;
                }
            }
        } catch (\Throwable $ignored) {
        }
    }

    foreach ($headerCandidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($candidate), $m)) {
            return trim((string) $m[1]);
        }
        if (substr_count($candidate, '.') === 2) {
            return trim($candidate);
        }
    }

    return null;
}

/**
 * Parses `ghl_tokens/{documentId}` reference stored on profile docs.
 *
 * @return array{collection:string,id:string}|null
 */
function auth_parse_ghl_token_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    if (!preg_match('#^ghl_tokens/([^/]+)$#', $ref, $m)) {
        return null;
    }

    return ['collection' => 'ghl_tokens', 'id' => $m[1]];
}

function auth_json_error(int $status, string $error, string $code, array $extra = []): void
{
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode(array_merge([
        'error' => $error,
        'code' => $code,
    ], $extra));
    exit;
}

/**
 * When `Authorization` is omitted: returns null (webhook-secret-only callers unchanged).
 * When present: verifies JWT + loads Firestore profile; exits on 401/404/500 as appropriate.
 *
 * @return array{payload: array, profile: array, firestore_collection: string, uid: string}|null
 */
function auth_get_optional_jwt_context($db): ?array
{
    $jwt = auth_extract_bearer_token_optional();
    if ($jwt === null || $jwt === '') {
        return null;
    }

    require_once __DIR__ . '/jwt_helper.php';

    $secret = getenv('JWT_SECRET');
    if ($secret === false || trim((string) $secret) === '') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
        exit;
    }

    $payload = jwt_verify($jwt, $secret);
    if (!$payload) {
        Logger::auth(false, 'optional-jwt', ['reason' => 'invalid-or-expired-token']);
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token.']);
        exit;
    }

    $userId = $payload['sub'] ?? null;
    if (!$userId) {
        Logger::auth(false, 'optional-jwt', ['reason' => 'missing-sub-in-payload']);
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid token payload.']);
        exit;
    }

    $role = (string) ($payload['role'] ?? 'user');
    $authCollection = (string) ($payload['auth_collection'] ?? '');
    $collection = $authCollection !== '' ? $authCollection : ($role === 'agency' ? 'agency_users' : 'users');
    $snap = $db->collection($collection)->document((string) $userId)->snapshot();

    if (!$snap->exists() && $collection !== 'users') {
        $collection = 'users';
        $snap = $db->collection('users')->document((string) $userId)->snapshot();
    }

    if (!$snap->exists()) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'User profile not found.']);
        exit;
    }

    Logger::auth(true, 'optional-jwt', [
        'sub'  => (string) $userId,
        'role' => $payload['role'] ?? null,
    ]);

    return [
        'payload' => $payload,
        'profile' => $snap->data(),
        'firestore_collection' => $collection,
        'uid' => (string) $userId,
    ];
}

/**
 * Authorizes agency billing routes.
 *
 * Browser callers should use an agency JWT whose company_id matches the
 * requested agency. Legacy server/internal callers can still use WEBHOOK_SECRET.
 */
function auth_assert_agency_billing_allowed($db, string $agencyId): void
{
    $agencyId = trim($agencyId);
    if ($agencyId === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => 'agency_id is required.']);
        exit;
    }

    $jwtCtx = auth_get_optional_jwt_context($db);
    if ($jwtCtx === null) {
        validate_api_request();
        return;
    }

    if (($jwtCtx['firestore_collection'] ?? '') !== 'agency_users') {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Agency billing access requires an agency account.']);
        exit;
    }

    $profile = $jwtCtx['profile'] ?? [];
    $allowedAgencyIds = array_filter(array_map('strval', [
        $profile['company_id'] ?? null,
        $profile['agency_id'] ?? null,
        $jwtCtx['uid'] ?? null,
    ]));

    if (!in_array($agencyId, $allowedAgencyIds, true)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Not allowed to access this agency billing account.']);
        exit;
    }
}


/**
 * Allows browser JWT callers or legacy webhook-secret callers, then enforces
 * location ownership when a JWT is used.
 *
 * @return array{payload: array, profile: array, firestore_collection: string, uid: string}|null
 */
function auth_require_api_or_jwt_for_location($db, ?string $locationId = null): ?array
{
    $jwtCtx = auth_get_optional_jwt_context($db);
    if ($jwtCtx === null) {
        validate_api_request();
        return null;
    }

    if ($locationId !== null && trim((string)$locationId) !== '') {
        auth_assert_ghl_api_location_allowed($db, $jwtCtx, (string)$locationId);
    }

    return $jwtCtx;
}

/**
 * Authorizes read-only agency billing/report routes.
 *
 * Agency users can read only their own agency billing data. Admin users with
 * normal admin dashboard roles can read any agency billing data for reporting,
 * while legacy internal callers can still use WEBHOOK_SECRET.
 */
function auth_assert_agency_billing_read_allowed($db, string $agencyId): void
{
    $jwt = auth_extract_bearer_token_optional();
    if ($jwt !== null && $jwt !== '') {
        require_once __DIR__ . '/jwt_helper.php';

        $secret = getenv('JWT_SECRET');
        if ($secret === false || trim((string)$secret) === '') {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
            exit;
        }

        $payload = jwt_verify($jwt, $secret);
        if (!$payload) {
            Logger::auth(false, 'billing-read-jwt', ['reason' => 'invalid-or-expired-token']);
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token.']);
            exit;
        }

        $role = (string)($payload['role'] ?? '');
        if (in_array($role, ['super_admin', 'support', 'viewer'], true)) {
            require_once __DIR__ . '/admin_auth_helper.php';
            require_secure_admin_auth(['super_admin', 'support', 'viewer']);
            Logger::auth(true, 'billing-read-admin', [
                'role' => $role,
                'agency_id' => $agencyId,
            ]);
            return;
        }
    }

    auth_assert_agency_billing_allowed($db, $agencyId);
}

/**
 * Enforces GHL multi-tenant access when JWT context is present.
 *
 * @param array{payload: array, profile: array, firestore_collection: string, uid: string}|null $jwtCtx
 */
function auth_assert_ghl_api_location_allowed($db, ?array $jwtCtx, string $requestedLocationId): void
{
    if ($jwtCtx === null) {
        return;
    }

    $requestedLocationId = trim($requestedLocationId);
    $profile = $jwtCtx['profile'];
    $collection = $jwtCtx['firestore_collection'];

    $refRaw = trim((string) ($profile['ghl_token_ref'] ?? ''));
    $refParsed = $refRaw !== '' ? auth_parse_ghl_token_ref($refRaw) : null;

    if ($refRaw !== '' && $refParsed === null) {
        auth_json_error(400, 'Invalid ghl_token_ref on profile; expected ghl_tokens/{id}.', 'INVALID_TOKEN_REFERENCE', [
            'hint' => 'ghl_tokens/{documentId}',
        ]);
    }

    if ($collection === 'agency_users') {
        $companyId = trim((string) ($profile['company_id'] ?? ''));
        if ($companyId === '') {
            auth_json_error(403, 'Agency profile missing company_id.', 'LOCATION_NOT_AUTHORIZED');
        }
        if ($refParsed !== null && $refParsed['id'] !== $companyId) {
            auth_json_error(403, 'ghl_token_ref does not match company_id for agency profile.', 'PROFILE_LOCATION_CONFLICT');
        }
        if (!auth_location_belongs_to_company($db, $requestedLocationId, $companyId)) {
            auth_json_error(403, 'Not allowed to access this GHL location.', 'LOCATION_NOT_AUTHORIZED');
        }

        return;
    }

    $active = trim((string) ($profile['active_location_id'] ?? ''));
    if ($active !== '' && $refParsed !== null && $active !== $refParsed['id']) {
        auth_json_error(403, 'active_location_id and ghl_token_ref disagree.', 'PROFILE_LOCATION_CONFLICT');
    }
    if ($active !== '' && $active !== $requestedLocationId) {
        auth_json_error(403, 'Location does not match your active_location_id.', 'LOCATION_SESSION_MISMATCH');
    }
    if ($refParsed !== null && $refParsed['id'] !== $requestedLocationId) {
        auth_json_error(403, 'Location does not match ghl_token_ref.', 'LOCATION_NOT_AUTHORIZED');
    }
    if ($active === '' && $refParsed === null) {
        auth_json_error(403, 'Profile missing active_location_id and ghl_token_ref; cannot authorize GHL access.', 'LOCATION_NOT_AUTHORIZED');
    }
}

/**
 * Firestore document ID under `ghl_tokens/` used for OAuth load/refresh.
 *
 * Agency users prefer `ghl_tokens/{requestedLocation}` when that row exists for the agency
 * (contacts need location scope); otherwise `ghl_token_ref` / `company_id` company doc.
 *
 * @param array{payload: array, profile: array, firestore_collection: string, uid: string}|null $jwtCtx
 */
function auth_resolve_ghl_token_registry_id($db, ?array $jwtCtx, string $apiLocationId): string
{
    $apiLocationId = trim($apiLocationId);
    if ($jwtCtx === null) {
        return $apiLocationId;
    }

    $profile = $jwtCtx['profile'];
    $collection = $jwtCtx['firestore_collection'];
    $refParsed = auth_parse_ghl_token_ref(trim((string) ($profile['ghl_token_ref'] ?? '')));

    if ($collection === 'agency_users') {
        $companyId = trim((string) ($profile['company_id'] ?? ''));

        $locSnap = $db->collection('ghl_tokens')->document($apiLocationId)->snapshot();
        if ($locSnap->exists()) {
            $ld = $locSnap->data();
            if (($ld['appType'] ?? '') !== 'agency') {
                $co = trim((string) ($ld['companyId'] ?? $ld['company_id'] ?? ''));
                if ($companyId !== '' && $co === $companyId) {
                    error_log('[GHL_JWT] token_registry agency prefers location OAuth doc location_id=' . $apiLocationId);

                    return $apiLocationId;
                }
            }
        }

        if ($refParsed !== null && ($refParsed['id'] ?? '') !== '') {
            return $refParsed['id'];
        }

        return $companyId !== '' ? $companyId : $apiLocationId;
    }

    if ($refParsed !== null && ($refParsed['id'] ?? '') !== '') {
        return $refParsed['id'];
    }

    return $apiLocationId;
}
