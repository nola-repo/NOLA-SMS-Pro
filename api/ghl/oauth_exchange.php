<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['code'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing authorization code']);
    exit;
}

$code = $payload['code'];
$redirectUri = $payload['redirect_uri'] ?? '';

// Use Environment Variables
$clientId = getenv('GHL_CLIENT_ID');
$clientSecret = getenv('GHL_CLIENT_SECRET');

if (!$clientId || !$clientSecret) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'GHL credentials not configured on server.']);
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
    echo json_encode(["success" => false, "error" => curl_error($ch)]);
    exit;
}

$result = json_decode($response, true);

if ($http_status == 200 && is_array($result) && isset($result['access_token'])) {
    
    // The oauth response could contain companyId for agency-level apps, or locationId
    $companyId = $result['companyId'] ?? $result['company_id'] ?? '';
    if (!$companyId) {
        // Fallback in case they linked a location level but meant it to be an agency level
        $companyId = $result['locationId'] ?? $result['location_id'] ?? ''; 
    }

    if (!$companyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No companyId found in oauth response']);
        exit;
    }

    // Try fetching company name if possible
    $companyName = 'Your GHL Company';
    try {
        $companyUrl = 'https://services.leadconnectorhq.com/companies/' . $companyId;
        $ch2 = curl_init($companyUrl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $result['access_token'],
            'Accept: application/json',
            'Version: 2021-07-28',
        ]);
        $compResponse = curl_exec($ch2);
        $compHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($compHttpCode === 200) {
            $compData = json_decode($compResponse, true);
            $companyName = $compData['company']['name'] ?? $companyName;
        }
    } catch (Exception $e) {
        error_log("Failed to fetch company name: " . $e->getMessage());
    }

    try {
        $db = get_firestore();
        $expiresIn = (int)($result['expires_in'] ?? 86399);
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+' . $expiresIn . ' seconds');

        $db->collection('ghl_tokens')
            ->document($companyId)
            ->set([
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_at' => new \Google\Cloud\Core\Timestamp($expiresAt),
                'scope' => $result['scope'] ?? '',
                'company_id' => $companyId,
                'company_name' => $companyName,
                'updated_at' => new \Google\Cloud\Core\Timestamp($now),
                'created_at' => new \Google\Cloud\Core\Timestamp($now)
            ], ['merge' => true]);
            
        // Also update company_id on matching user documents so login.php can return it
        try {
            // Find agency users whose agency_id matches this companyId
            $userQuery = $db->collection('users')
                ->where('agency_id', '=', $companyId)
                ->documents();

            foreach ($userQuery as $userDoc) {
                if ($userDoc->exists()) {
                    $userDoc->reference()->set([
                        'company_id'   => $companyId,
                        'company_name' => $companyName,
                        'updated_at'   => new \Google\Cloud\Core\Timestamp($now),
                    ], ['merge' => true]);
                }
            }
        } catch (Exception $ue) {
            // Non-fatal: tokens were saved, user doc update is best-effort
            error_log("OAuth exchange - failed to update user doc company_id: " . $ue->getMessage());
        }
    } catch (Exception $e) {
        error_log("Failed to save tokens: " . $e->getMessage());
        // We still return success to frontend since token was retrieved, but tokens aren't saved!
        // Properly we should fail so user tries again.
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Failed to save tokens."]);
        exit;
    }

    // Monitor log — visible in Cloud Run / server logs
    error_log(sprintf(
        '[OAUTH_EXCHANGE] company_id detected: %s | company_name: %s | time: %s',
        $companyId,
        $companyName,
        (new DateTimeImmutable())->format('Y-m-d H:i:s')
    ));

    echo json_encode([
        "success" => true,
        "company_id" => $companyId,
        "company_name" => $companyName
    ]);
} else {
    http_response_code($http_status ?: 400);
    echo json_encode([
        "success" => false,
        "error" => "Failed to exchange token",
        "ghl_response" => $result ?: $response
    ]);
}
