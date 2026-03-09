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

// User Provided Credentials
$clientId = '6999da2b8f278296d95f7274-mm9wv85e';
$clientSecret = 'dfc4380f-6132-49b3-8246-92e14f55ee78';

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
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ]);

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
