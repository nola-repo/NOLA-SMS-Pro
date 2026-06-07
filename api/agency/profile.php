<?php
/**
 * GET /api/agency/profile.php
 *
 * Returns the authenticated agency account profile for Settings.
 * Requires: Authorization: Bearer <token>
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/../auth/user_profile_helper.php';
require_once __DIR__ . '/../cache_helper.php';

function agency_profile_extract_bearer_token(): ?string
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
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($candidate), $m)) {
            return trim((string)$m[1]);
        }
        if (substr_count($candidate, '.') === 2) {
            return trim($candidate);
        }
    }

    $queryToken = trim((string)($_GET['token'] ?? ''));
    return $queryToken !== '' ? $queryToken : null;
}

function agency_profile_resolve_company_name($db, array $d): ?string
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
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$jwt = agency_profile_extract_bearer_token();
if (!$jwt) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing auth token. Provide Authorization: Bearer <token>.']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server misconfiguration: JWT secret missing.']);
    exit;
}

$payload = jwt_verify($jwt, $jwtSecret);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token is invalid or expired.']);
    exit;
}

if (($payload['role'] ?? '') !== 'agency') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: Agency role required']);
    exit;
}

$userId = $payload['sub'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token payload.']);
    exit;
}

$cacheKey = "agency_profile_" . $userId;
$cachedData = NolaCache::get($cacheKey);
if ($cachedData !== null) {
    echo json_encode($cachedData);
    exit;
}

try {
    $db = get_firestore();
    $collection = (string)($payload['auth_collection'] ?? 'agency_users');
    if ($collection !== 'agency_users') {
        $collection = 'agency_users';
    }

    $snap = $db->collection($collection)->document((string)$userId)->snapshot();
    if (!$snap->exists()) {
        $snap = $db->collection('users')->document((string)$userId)->snapshot();
    }

    if (!$snap->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Agency user not found.']);
        exit;
    }

    $data = $snap->data();
    if (($data['role'] ?? '') !== 'agency') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: Agency role required']);
        exit;
    }

    if (empty($data['company_id'])) {
        $jwtCompanyId = trim((string)($payload['company_id'] ?? ''));
        if ($jwtCompanyId !== '') {
            $data['company_id'] = $jwtCompanyId;
        }
    }

    if (empty($data['company_name'])) {
        $companyName = agency_profile_resolve_company_name($db, $data);
        if ($companyName !== null) {
            $data['company_name'] = $companyName;
        }
    }

    $profile = auth_user_payload_for_api($data);
    $responsePayload = [
        'status' => 'success',
        'user' => $profile,
        'data' => $profile,
    ];
    NolaCache::set($cacheKey, $responsePayload, 600); // 10 minutes cache

    echo json_encode($responsePayload);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch agency profile: ' . $e->getMessage()]);
}
