<?php
/**
 * GET /api/auth/me
 * Returns the authenticated user's full profile from Firestore.
 * Used by the frontend to self-heal stale nola_user localStorage cache.
 *
 * Requires: Authorization: Bearer <token>
 * Response 200: { user: { firstName, lastName, email, phone, location_id, location_name, company_name, company_id } }
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/user_profile_helper.php';

function auth_json_error(int $status, string $code, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'error' => $message,
        'code' => $code,
    ], $extra));
    exit;
}

function auth_log_profile_failure(string $code, array $context = []): void
{
    error_log('[api/auth/me.php] ' . json_encode(array_merge([
        'code' => $code,
        'path' => $_SERVER['REQUEST_URI'] ?? '/api/auth/me',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    ], $context)));
}

/**
 * Best-effort token extraction for environments where Authorization headers
 * are stripped (proxy, iframe, some browsers).
 */
function auth_extract_bearer_token(): ?string
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
            $headers = getallheaders();
            if (is_array($headers)) {
                $headerCandidates[] = $headers['Authorization'] ?? '';
                $headerCandidates[] = $headers['authorization'] ?? '';
                $headerCandidates[] = $headers['X-Authorization'] ?? '';
                $headerCandidates[] = $headers['X-Auth-Token'] ?? '';
            }
        } catch (Exception $ignored) {
        }
    }

    foreach ($headerCandidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($candidate), $m)) {
            return trim((string)$m[1]);
        }
        // Some clients pass raw JWT in custom auth headers.
        if (substr_count($candidate, '.') === 2) {
            return trim($candidate);
        }
    }

    $queryToken = trim((string)($_GET['token'] ?? ''));
    if ($queryToken !== '') {
        return $queryToken;
    }

    $cookieCandidates = [
        $_COOKIE['nola_auth_token'] ?? '',
        $_COOKIE['auth_token'] ?? '',
        $_COOKIE['token'] ?? '',
    ];
    foreach ($cookieCandidates as $cookieToken) {
        if (is_string($cookieToken) && trim($cookieToken) !== '') {
            return trim($cookieToken);
        }
    }

    return null;
}

function auth_resolve_agency_company_name($db, array $d): ?string
{
    $companyId = trim((string)($d['company_id'] ?? ''));
    if ($companyId === '') {
        return null;
    }

    foreach (['ghl_agency_tokens', 'ghl_tokens'] as $collection) {
        try {
            $snap = $db->collection($collection)->document($companyId)->snapshot();
            if (!$snap->exists()) {
                continue;
            }
            $tokenData = $snap->data();
            $companyName = $tokenData['company_name']
                ?? $tokenData['companyName']
                ?? $tokenData['agency_name']
                ?? $tokenData['location_name']
                ?? null;
            if ($companyName !== null && trim((string)$companyName) !== '') {
                return trim((string)$companyName);
            }
        } catch (Exception $ignored) {
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    auth_json_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
}

$jwt = auth_extract_bearer_token();
if (!$jwt) {
    auth_log_profile_failure('AUTH_TOKEN_MISSING');
    auth_json_error(401, 'AUTH_TOKEN_MISSING', 'Missing auth token. Provide Authorization: Bearer <token>.');
}

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    auth_log_profile_failure('JWT_SECRET_MISSING');
    auth_json_error(500, 'JWT_SECRET_MISSING', 'Server misconfiguration: JWT secret missing.');
}
$payload   = jwt_verify($jwt, $jwtSecret);

if (!$payload) {
    auth_log_profile_failure('AUTH_TOKEN_INVALID');
    auth_json_error(401, 'AUTH_TOKEN_INVALID', 'Token is invalid or expired.');
}

$userId = $payload['sub'] ?? null;
if (!$userId) {
    auth_log_profile_failure('AUTH_TOKEN_PAYLOAD_INVALID');
    auth_json_error(401, 'AUTH_TOKEN_PAYLOAD_INVALID', 'Invalid token payload.');
}

try {
    $db = get_firestore();
    $role = (string)($payload['role'] ?? 'user');
    $authCollection = (string)($payload['auth_collection'] ?? '');
    $isAgency = $role === 'agency';
    $collection = $authCollection !== '' ? $authCollection : ($isAgency ? 'agency_users' : 'users');
    $snap = $db->collection($collection)->document($userId)->snapshot();

    // Backward compatibility for legacy tokens and pre-migration docs.
    if (!$snap->exists() && $collection !== 'users') {
        $collection = 'users';
        $snap = $db->collection($collection)->document($userId)->snapshot();
    }

    if (!$snap->exists()) {
        auth_log_profile_failure('AUTH_USER_NOT_FOUND', [
            'user_id' => (string)$userId,
            'role' => $role,
            'collection' => $collection,
        ]);
        auth_json_error(404, 'AUTH_USER_NOT_FOUND', 'User not found.');
    }

    $d = $snap->data();

    // Resolve company_id from JWT payload if missing in the Firestore doc
    if ($isAgency && empty($d['company_id'])) {
        $jwtCompanyId = trim((string)($payload['company_id'] ?? ''));
        if ($jwtCompanyId !== '') {
            $d['company_id'] = $jwtCompanyId;
        }
    }

    $fallbackNames = ['No Agency', 'Unnamed Agency', 'Unknown Agency', 'Unknown'];
    $existingCompanyName = trim((string)($d['company_name'] ?? ''));
    $needsResolution = (
        empty($existingCompanyName) ||
        in_array($existingCompanyName, $fallbackNames, true)
    );
    if ($needsResolution) {
        $companyName = auth_resolve_agency_company_name($db, $d);
        if ($companyName !== null) {
            $d['company_name'] = $companyName;
        }
    }

    $subaccounts = [];
    if ($collection === 'users') {
        try {
            $subSnap = $db->collection('users')->document($userId)->collection('subaccounts')->documents();
            foreach ($subSnap as $subDoc) {
                if (!$subDoc->exists()) {
                    continue;
                }
                $subData = $subDoc->data();
                if (!isset($subData['id'])) {
                    $subData['id'] = $subDoc->id();
                }
                $subaccounts[] = $subData;
            }
        } catch (Exception $ignored) {
        }
    }

    echo json_encode([
        'user' => auth_user_payload_for_api($d),
        'subaccounts' => $subaccounts,
    ]);
} catch (Exception $e) {
    auth_log_profile_failure('AUTH_PROFILE_FETCH_FAILED', [
        'message' => $e->getMessage(),
    ]);
    auth_json_error(500, 'AUTH_PROFILE_FETCH_FAILED', 'Failed to fetch profile: ' . $e->getMessage());
}
