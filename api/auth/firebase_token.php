<?php

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../jwt_helper.php';

function firebase_token_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function firebase_service_account_config(): array
{
    $json = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    if (is_string($json) && trim($json) !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $clientEmail = getenv('FIREBASE_CLIENT_EMAIL');
    $privateKey = getenv('FIREBASE_PRIVATE_KEY');
    if (is_string($clientEmail) && trim($clientEmail) !== '' && is_string($privateKey) && trim($privateKey) !== '') {
        return [
            'client_email' => trim($clientEmail),
            'private_key' => str_replace('\\n', "\n", trim($privateKey)),
            'project_id' => getenv('FIREBASE_PROJECT_ID') ?: getenv('GOOGLE_CLOUD_PROJECT') ?: 'nola-sms-pro',
        ];
    }

    $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if (is_string($credentialsPath) && trim($credentialsPath) !== '' && is_readable($credentialsPath)) {
        $decoded = json_decode((string) file_get_contents($credentialsPath), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    firebase_token_error(500, 'Firebase service account credentials are not configured for custom token signing.');
}

function firebase_sign_custom_token(string $uid, array $claims): string
{
    $serviceAccount = firebase_service_account_config();
    $clientEmail = trim((string) ($serviceAccount['client_email'] ?? ''));
    $privateKey = str_replace('\\n', "\n", trim((string) ($serviceAccount['private_key'] ?? '')));

    if ($clientEmail === '' || $privateKey === '') {
        firebase_token_error(500, 'Firebase service account credentials are missing client_email or private_key.');
    }

    $now = time();
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'iss' => $clientEmail,
        'sub' => $clientEmail,
        'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
        'iat' => $now,
        'exp' => $now + 3600,
        'uid' => $uid,
        'claims' => $claims,
    ]));

    $signature = '';
    $ok = openssl_sign($header . '.' . $payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        firebase_token_error(500, 'Could not sign Firebase custom token.');
    }

    return $header . '.' . $payload . '.' . base64url_encode($signature);
}

function firebase_location_claims($db, array $jwtCtx): array
{
    $profile = $jwtCtx['profile'] ?? [];
    $payload = $jwtCtx['payload'] ?? [];
    $collection = (string) ($jwtCtx['firestore_collection'] ?? 'users');
    $role = (string) ($payload['role'] ?? $profile['role'] ?? ($collection === 'agency_users' ? 'agency' : 'user'));

    if ($collection === 'agency_users' || $role === 'agency') {
        $companyId = trim((string) ($profile['company_id'] ?? $profile['agency_id'] ?? $payload['company_id'] ?? ''));
        if ($companyId === '') {
            firebase_token_error(403, 'Agency profile missing company_id.');
        }

        return [
            'role' => 'agency',
            'company_id' => $companyId,
            'location_ids' => [],
        ];
    }

    $locationId = trim((string) (
        $profile['active_location_id']
        ?? $profile['location_id']
        ?? $payload['active_location_id']
        ?? $payload['location_id']
        ?? ''
    ));

    $refParsed = auth_parse_ghl_token_ref(trim((string) ($profile['ghl_token_ref'] ?? '')));
    if ($locationId === '' && $refParsed !== null) {
        $locationId = $refParsed['id'];
    }

    if ($locationId === '') {
        firebase_token_error(403, 'User profile missing location_id for Firestore realtime access.');
    }

    auth_assert_ghl_api_location_allowed($db, $jwtCtx, $locationId);

    return [
        'role' => 'user',
        'location_id' => $locationId,
        'location_ids' => [$locationId],
    ];
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    firebase_token_error(405, 'Method not allowed');
}

$db = get_firestore();
$jwtCtx = auth_get_optional_jwt_context($db);
if ($jwtCtx === null) {
    firebase_token_error(401, 'Missing app auth token.');
}

$uid = (string) ($jwtCtx['uid'] ?? '');
if ($uid === '' || strlen($uid) > 128) {
    $uid = 'nola_' . substr(hash('sha256', (string) ($jwtCtx['firestore_collection'] ?? 'user') . ':' . $uid), 0, 40);
}

$claims = firebase_location_claims($db, $jwtCtx);
$token = firebase_sign_custom_token($uid, $claims);

echo json_encode([
    'success' => true,
    'token' => $token,
    'expires_in' => 3600,
    'claims' => $claims,
]);