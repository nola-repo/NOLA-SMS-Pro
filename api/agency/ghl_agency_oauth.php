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

require __DIR__ . '/../webhook/firestore_client.php';

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['code'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing authorization code']);
    exit;
}

$code = $payload['code'];
$redirectUri = $payload['redirectUri'] ?? '';

// Agency App Credentials — do NOT fall back to GHL_CLIENT_ID (that's the Subaccount app)
$clientId = getenv('GHL_AGENCY_CLIENT_ID') ?: '69cb813b4b007d172f7e7a35-mneicksx';
$clientSecret = getenv('GHL_AGENCY_CLIENT_SECRET') ?: 'f2c52910-fa01-47b1-9cf7-d812464fe2ad';

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
    $companyId = $result['companyId'] ?? '';

    if (!$companyId) {
        http_response_code(400);
        echo json_encode(['error' => 'No companyId found in response']);
        exit;
    }

    // Save token in ghl_tokens as agency
    try {
        $db->collection('ghl_tokens')
            ->document($companyId)
            ->set([
                'appType' => 'agency',
                'appId' => $clientId,
                'userType' => 'Company',
                'companyId' => $companyId,
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_at' => time() + (int)($result['expires_in'] ?? 86399),
                'location_name' => 'Agency Name',
                'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
            ]);

        // 2. Update the user document associated with this specifically linked company_id
        // We only update if a user ALREADY exists for this ID. If not, ghl_autologin.php
        // will handle creating the user securely when they first open the iframe.
        $userResults = $db->collection('users')
            ->where('role', '=', 'agency')
            ->where('company_id', '=', $companyId)
            ->limit(1)
            ->documents();

        foreach ($userResults as $userDoc) {
            if ($userDoc->exists()) {
                $db->collection('users')
                    ->document($userDoc->id())
                    ->set(['company_id' => $companyId, 'updatedAt' => new \Google\Cloud\Core\Timestamp(new \DateTime())], ['merge' => true]);
                break;
            }
        }

        echo json_encode([
            "status" => "success",
            "companyId" => $companyId
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
        "details" => $result
    ]);
}
