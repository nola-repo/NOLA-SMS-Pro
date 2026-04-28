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
$appType = $_GET['app_type'] ?? 'user';
if ($appType === 'agency') {
    $clientId = getenv('GHL_AGENCY_CLIENT_ID') ?: '69d31f33b3071b25dbcc5656-mnqxvtt3';
    $clientSecret = getenv('GHL_AGENCY_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322';
} else {
    $clientId = getenv('GHL_USER_CLIENT_ID') ?: '6999da2b8f278296d95f7274-mm9wv85e';
    $clientSecret = getenv('GHL_USER_CLIENT_SECRET') ?: 'dfc4380f-6132-49b3-8246-92e14f55ee78';

}

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
        
        // --- New: Fetch Location Name from GHL API ---
        $locationName = '';
        try {
            $locationUrl = 'https://services.leadconnectorhq.com/locations/' . $locationId;
            $ch = curl_init($locationUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $result['access_token'],
                'Accept: application/json',
                'Version: 2021-07-28',
            ]);
            $locResponse = curl_exec($ch);
            $locHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($locHttpCode === 200) {
                $locData = json_decode($locResponse, true);
                $locationName = $locData['location']['name'] ?? '';
            }
        } catch (Exception $e) {
            // Log error but proceed
            error_log("Failed to fetch location name: " . $e->getMessage());
        }

        // PRIMARY WRITE: ghl_tokens/{locationId} — canonical store that GhlClient reads
        $db->collection('ghl_tokens')
            ->document($locationId)
            ->set([
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_at'    => new \Google\Cloud\Core\Timestamp($expiresAt),
                'scope'         => $result['scope'] ?? '',
                'location_id'   => $locationId,
                'location_name' => $locationName,
                'companyId'     => $result['companyId'] ?? '',
                'userType'      => $result['userType'] ?? 'Location',
                'client_id'     => $clientId,
                'is_live'       => true,
                'toggle_enabled' => true,
                'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
            ], ['merge' => true]);

        // LEGACY WRITE: integrations/{docId} — kept for any legacy code paths still reading it
        $db->collection('integrations')
            ->document($docId)
            ->set([
            'access_token'  => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_at'    => new \Google\Cloud\Core\Timestamp($expiresAt),
            'scope'         => $result['scope'] ?? '',
            'location_id'   => $locationId,
            'location_name' => $locationName,
            'client_id'     => $clientId,
            'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
            'created_at'    => new \Google\Cloud\Core\Timestamp($now)
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
