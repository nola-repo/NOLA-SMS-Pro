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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$jwt = auth_extract_bearer_token();
if (!$jwt) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing auth token. Provide Authorization: Bearer <token>.']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
$payload   = jwt_verify($jwt, $jwtSecret);

if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Token is invalid or expired.']);
    exit;
}

$userId = $payload['sub'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token payload.']);
    exit;
}

try {
    $db   = get_firestore();
    $snap = $db->collection('users')->document($userId)->snapshot();

    if (!$snap->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found.']);
        exit;
    }

    $d = $snap->data();

    $subaccounts = [];
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

    echo json_encode([
        'user' => auth_user_payload_for_api($d),
        'subaccounts' => $subaccounts,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch profile: ' . $e->getMessage()]);
}
