<?php
/**
 * GET /api/agency/get_subaccounts
 * 
 * Fetches all subaccounts (GHL Locations) under a specific agency (Company ID).
 * This endpoint queries the ghl_tokens collection where companyId == X-Agency-ID.
 */
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$webhookSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
if ($webhookSecret !== 'f7RkQ2pL9zV3tX8cB1nS4yW6') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$agencyId = $_SERVER['HTTP_X_AGENCY_ID'] ?? '';
if (!$agencyId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing X-Agency-ID header.']);
    exit;
}

try {
    $db = get_firestore();
    
    // 1. Get Agency Token from ghl_tokens
    $agencyDoc = $db->collection('ghl_tokens')->document($agencyId)->snapshot();
    if (!$agencyDoc->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'Agency GoHighLevel connection not found.']);
        exit;
    }
    
    $agencyData = $agencyDoc->data();
    $accessToken = $agencyData['access_token'] ?? '';
    $agencyName = $agencyData['agency_name'] ?? 'Unnamed Agency';
    
    if (!$accessToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Agency access token missing.']);
        exit;
    }

    // 2. Fetch Locations from GHL
    $ch = curl_init('https://services.leadconnectorhq.com/locations/search?companyId=' . urlencode($agencyId) . '&limit=100');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Version: 2021-07-28',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $errorResponse = json_decode($response, true);
        throw new Exception("GHL API Error ({$httpCode}): " . ($errorResponse['message'] ?? $response));
    }
    
    $apiData = json_decode($response, true);
    $ghlLocations = $apiData['locations'] ?? [];

    // 3. Fetch existing configs from agency_subaccounts
    $results = $db->collection('agency_subaccounts')->where('agency_id', '=', $agencyId)->documents();
    $dbConfigs = [];
    foreach ($results as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $locId = $data['location_id'] ?? $doc->id();
            $dbConfigs[$locId] = $data;
        }
    }
    
    // 4. Merge API Locations, Auto-Sync, and return
    $subaccounts = [];
    foreach ($ghlLocations as $loc) {
        $locId = $loc['id'];
        $locName = $loc['name'] ?? 'Unnamed Location';
        $config = $dbConfigs[$locId] ?? [];
        
        $subaccountData = [
            'location_id'             => $locId,
            'location_name'           => $locName,
            'agency_id'               => $agencyId,
            'agency_name'             => $agencyName,
            'toggle_enabled'          => (bool)($config['toggle_enabled'] ?? false),
            'rate_limit'              => (int)($config['rate_limit'] ?? 5),
            'attempt_count'           => (int)($config['attempt_count'] ?? 0),
            'toggle_activation_count' => (int)($config['toggle_activation_count'] ?? 0)
        ];
        
        // Auto-sync into agency_subaccounts if missing or if names have changed
        if (empty($config) || ($config['agency_name'] ?? '') !== $agencyName || ($config['location_name'] ?? '') !== $locName) {
            $db->collection('agency_subaccounts')->document($locId)->set($subaccountData, ['merge' => true]);
        }
        
        $subaccounts[] = $subaccountData;
    }
    
    echo json_encode([
        'status' => 'success',
        'subaccounts' => $subaccounts
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Fetch failed: ' . $e->getMessage()]);
}
