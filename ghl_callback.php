<?php

require __DIR__ . '/api/webhook/firestore_client.php';

function ghl_send_json(array $data, int $status = 200): void
{
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

$clientId = getenv('GHL_CLIENT_ID');
$clientSecret = getenv('GHL_CLIENT_SECRET');
$redirectUri = 'https://smspro-api.nolacrm.io/oauth/callback';

if (!$clientId || !$clientSecret) {
    ghl_send_json([
        'success' => false,
        'error'   => 'GHL_CLIENT_ID or GHL_CLIENT_SECRET not configured on server.',
    ], 500);
}

if (!isset($_GET['code'])) {
    ghl_send_json([
        'success' => false,
        'error'   => 'No authorization code received.',
    ], 400);
}

$code  = $_GET['code'];
// state = subaccount/location id from install link (e.g. &state=location123)
$state = $_GET['state'] ?? null;

$tokenUrl = 'https://services.leadconnectorhq.com/oauth/token';
$postData = [
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'user_type'     => 'Location',
    'redirect_uri'  => $redirectUri,
];

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/x-www-form-urlencoded',
    'Version: 2021-07-28',
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    ghl_send_json([
        'success' => false,
        'error'   => 'cURL error: ' . $curlError,
    ], 500);
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !is_array($data)) {
    ghl_send_json([
        'success'    => false,
        'error'      => 'GHL token exchange failed.',
        'http_code'  => $httpCode,
        'ghl_result' => $data ?: $response,
    ], $httpCode);
}

$db       = get_firestore();
$now      = new DateTimeImmutable();
$expires  = (int)($data['expires_in'] ?? 0);
$expiresAt = (clone $now)->modify('+' . $expires . ' seconds');

// Per-subaccount: doc id = ghl_{locationId} when state or GHL locationId prsent, else ghl
$locationId = $state ?? $data['locationId'] ?? $data['location_id'] ?? null;
$docId      = $locationId ? 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locationId) : 'ghl';

$db->collection('integrations')
    ->document($docId)
    ->set([
        'access_token'  => $data['access_token'] ?? null,
        'refresh_token' => $data['refresh_token'] ?? null,
        'scope'         => $data['scope'] ?? null,
        'location_id'   => $data['locationId'] ?? $data['location_id'] ?? $locationId,
        'expires_at'    => new \Google\Cloud\Core\Timestamp($expiresAt),
        'raw'           => $data,
        'created_at'    => new \Google\Cloud\Core\Timestamp($now),
        'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
        'state'         => $state,
    ], ['merge' => true]);

ghl_send_json([
    'success'     => true,
    'message'     => 'GHL tokens stored successfully.',
    'document_id' => $docId,
    'location_id' => $locationId,
]);

