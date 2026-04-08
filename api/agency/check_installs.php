<?php
/**
 * GET /api/agency/check_installs.php?company_id={{company_id}}
 * 
 * Returns a list of location_ids that exist in the ghl_tokens collection 
 * for this agency.
 */
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$companyId = $_GET['company_id'] ?? '';
if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing company_id parameter.']);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
$agencyId = validate_agency_request();

// Ensure the requested company_id belongs to the authenticated agency owner
if ($agencyId !== $companyId) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $db = get_firestore();
    
    // Query the Firestore ghl_tokens collection: WHERE companyId == <provided company_id>
    $results = $db->collection('ghl_tokens')->where('companyId', '=', $companyId)->documents();
    
    $installedLocations = [];
    foreach ($results as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $locId = $data['locationId'] ?? $doc->id();
            
            // The agency-level token itself has companyId as document ID, we want to exclude that one
            if ($locId && $locId !== $companyId && !in_array($locId, $installedLocations)) {
                $installedLocations[] = $locId;
            }
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'installed_locations' => array_values($installedLocations)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Fetch failed: ' . $e->getMessage()]);
}
