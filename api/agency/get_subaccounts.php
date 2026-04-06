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
$agencyId = $_SERVER['HTTP_X_AGENCY_ID'] ?? '';
if (!$agencyId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing X-Agency-ID header.']);
    exit;
}
try {
    $db = get_firestore();
    // DEBUG: Fetch all to verify companyId string matching logic and avoid index constraints / whitespace mismatches
    $results = $db->collection('ghl_tokens')->documents();
    $subaccounts = [];
    foreach ($results as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $dbCompanyId = $data['companyId'] ?? '';
            
            // Filter out the agency itself, we only want actual subaccount locations
            $isLocation = isset($data['location_id']) || ($data['appType'] ?? '') !== 'agency';
            
            if (trim($dbCompanyId) === trim($agencyId) && $isLocation) {
                $subaccounts[] = [
                    'location_id'             => $data['location_id'] ?? $doc->id(),
                    'location_name'           => $data['location_name'] ?? 'Unnamed Location',
                    'toggle_enabled'          => (bool)($data['toggle_enabled'] ?? false),
                    'rate_limit'              => (int)($data['rate_limit'] ?? 5),
                    'attempt_count'           => (int)($data['attempt_count'] ?? 0),
                    'toggle_activation_count' => (int)($data['toggle_activation_count'] ?? 0)
                ];
            }
        }
    }
    echo json_encode([
        'status' => 'success',
        'subaccounts' => $subaccounts
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Fetch failed: ' . $e->getMessage()]);
}
