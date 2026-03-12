<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['code'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing authorization code']);
    exit;
}

$code = $payload['code'];
$redirectUri = $payload['redirectUri'] ?? '';

// Use Environment Variables
$clientId = getenv('GHL_CLIENT_ID');
$clientSecret = getenv('GHL_CLIENT_SECRET');

if (!$clientId || !$clientSecret) {
    http_response_code(500);
    echo json_encode(['error' => 'GHL credentials not configured on server.']);
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
    "Accept: application/json",
    "Version: 2021-07-28"
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
    $locationId = $result['locationId'] ?? $result['location_id'] ?? '';

    if (!$locationId) {
        http_response_code(400);
        echo json_encode(['error' => 'No locationId found in response']);
        exit;
    }

    $expiresIn = (int)($result['expires_in'] ?? 86399);
    $now = new DateTimeImmutable();
    $expiresAt = $now->modify('+' . $expiresIn . ' seconds');

    // Save tokens securely in Firestore
    try {
        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locationId);
        
        $db->collection('integrations')
            ->document($docId)
            ->set([
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_at' => new \Google\Cloud\Core\Timestamp($expiresAt),
            'scope' => $result['scope'] ?? '',
            'location_id' => $locationId,
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
            'created_at' => new \Google\Cloud\Core\Timestamp($now)
        ], ['merge' => true]);

        echo json_encode([
            "status" => "success",
            "locationId" => $locationId,
            "document_id" => $docId
        ]);
    }
    catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "error" => "Failed to save tokens: " . $e->getMessage()]);
    }
}
else {
    http_response_code($http_status ?: 400);
    echo json_encode([
        "status" => "failed",
        "error" => "Failed to exchange token",
        "ghl_response" => $result ?: $response
    ]);
}
