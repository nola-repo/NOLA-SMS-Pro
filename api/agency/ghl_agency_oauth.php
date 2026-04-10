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

// User Provided Credentials
$clientId = getenv('GHL_AGENCY_CLIENT_ID') ?: getenv('GHL_CLIENT_ID') ?: '69d31f33b3071b25dbcc5656-mnqxvtt3';
$clientSecret = getenv('GHL_AGENCY_CLIENT_SECRET') ?: getenv('GHL_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322';

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
                'companyId' => $companyId,
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_at' => time() + (int)($result['expires_in'] ?? 86399),
                'location_name' => 'Agency Name',
                'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
            ]);

        // Find the NOLA users document where role == "agency" to update/merge company_id
        // NOTE: the requirement states: 'Find the NOLA users document where role == "agency" and update/merge it: { "company_id": "{companyId}" }'
        $usersQuery = $db->collection('users')->where('role', '=', 'agency')->documents();
        foreach ($usersQuery as $userDoc) {
            if ($userDoc->exists()) {
                $userData = $userDoc->data();
                // We'll update the first one we find that either matches or just any if none matching,
                // Or maybe just the first one? Requirements implies singular agency app user doc.
                $db->collection('users')
                    ->document($userDoc->id())
                    ->set(['company_id' => $companyId], ['merge' => true]);
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
