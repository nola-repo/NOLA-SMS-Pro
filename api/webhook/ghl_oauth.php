<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Ensure these files exist on your server
require __DIR__ . '/firestore_client.php';
// if config.php exists and has things, require it, else it's fine.

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['code'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing authorization code']);
    exit;
}

$code = $payload['code'];
$redirectUri = $payload['redirectUri'] ?? '';

// User Provided Credentials (from Cloud Run Env)
$appType = $_GET['app_type'] ?? 'user';
if ($appType === 'agency') {
    $clientId = getenv('GHL_AGENCY_CLIENT_ID') ?: '69d31f33b3071b25dbcc5656-mnqxvtt3';
    $clientSecret = getenv('GHL_AGENCY_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322';
} else {
    $clientId = getenv('GHL_CLIENT_ID') ?: '6999da2b8f278296d95f7274-mm9wv85e';
    $clientSecret = getenv('GHL_CLIENT_SECRET') ?: 'dfc4380f-6132-49b3-8246-92e14f55ee78';
}

if (!$clientId || !$clientSecret) {
    http_response_code(500);
    echo json_encode(['error' => 'GHL_CLIENT_ID or GHL_CLIENT_SECRET not configured on server.']);
    exit;
}

$postData = http_build_query([
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $redirectUri
]);

$ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/x-www-form-urlencoded",
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

$response = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    http_response_code(500);
    echo json_encode(["status" => "error", "error" => curl_error($ch)]);
    exit;
}

$result = json_decode($response, true);

if ($http_status == 200 && is_array($result) && isset($result['access_token'])) {
    $db = get_firestore();
    $locationId = $result['locationId'] ?? '';

    if (!$locationId) {
        http_response_code(400);
        echo json_encode(['error' => 'No locationId found in response']);
        exit;
    }

    $expiresIn = (int)($result['expires_in'] ?? 86399);
    $expiresAtTimestamp = time() + $expiresIn;

    // Save tokens securely in Firestore
    try {
        $db->collection('ghl_tokens')
            ->document($locationId)
            ->set([
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_at' => $expiresAtTimestamp,
            'scope' => $result['scope'] ?? '',
            'userType' => $result['userType'] ?? 'Location',
            'companyId' => $result['companyId'] ?? '',
            'userId' => $result['userId'] ?? '',
            'client_id' => $clientId,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ]);

        // Also register in ghl_tokens for Agency Dashboard install verification
        $db->collection('ghl_tokens')->document($locationId)->set([
            'location_id' => $locationId,
            'companyId' => $result['companyId'] ?? '',
            'userType' => $result['userType'] ?? 'Location',
            'is_live' => true,
            'toggle_enabled' => true,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);

        echo json_encode([
            "status" => "success",
            "locationId" => $locationId
        ]);
    }
    catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "error" => "Failed to save tokens: " . $e->getMessage()]);
    }
}
else {
    http_response_code(400);
    echo json_encode([
        "status" => "failed",
        "error" => "Failed to exchange token",
        "details" => $result,
        "sent_data" => [
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId
        ]
    ]);
}
