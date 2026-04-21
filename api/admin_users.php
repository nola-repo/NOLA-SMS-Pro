<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    
    // Pre-fetch all agencies to create an ID -> Name dictionary
    $agenciesMap = [];
    $agencyDocs = $db->collection('ghl_tokens')->where('appType', '=', 'agency')->documents();
    foreach ($agencyDocs as $agDoc) {
        if ($agDoc->exists()) {
            $agData = $agDoc->data();
            $agId = $agData['companyId'] ?? $agDoc->id();
            $agenciesMap[$agId] = $agData['company_name'] ?? $agData['locationName'] ?? $agData['agency_name'] ?? 'Unnamed Agency';
        }
    }

    // Global CRM view of subaccounts
    $integrations = $db->collection('integrations')->limit($limit)->documents();
    
    $subaccounts = [];
    foreach ($integrations as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            
            // Auto-resolve location ID if missing from fields
            $locId = $data['location_id'] ?? '';
            if (empty($locId)) {
                $locId = str_replace('ghl_', '', $doc->id());
            }

            // Fallback to ghl_tokens if company_id isn't cached natively in integration doc
            $companyId = $data['companyId'] ?? $data['company_id'] ?? '';
            if (empty($companyId) && !empty($locId)) {
                // Safety guard: Firestore throws an exception on reserved IDs like "__location_id__"
                if (!preg_match('/^__.*__$/', $locId) && strpos($locId, '/') === false) {
                    try {
                        $tokenSnap = $db->collection('ghl_tokens')->document($locId)->snapshot();
                        if ($tokenSnap->exists()) {
                            $tokenData = $tokenSnap->data();
                            $companyId = $tokenData['companyId'] ?? $tokenData['company_id'] ?? '';
                        }
                    } catch (Exception $e) {
                        // Suppress individual corrupted doc ID errors to let the loop continue
                    }
                }
            }

            // Map agency name
            $agencyName = $data['agency_name'] ?? 'Unknown Agency';
            if (!empty($companyId) && isset($agenciesMap[$companyId])) {
                $agencyName = $agenciesMap[$companyId];
            }
            
            $subaccounts[] = [
                'id' => $doc->id(),
                'location_id' => $locId,
                'location_name' => $data['location_name'] ?? 'Unnamed Subaccount',
                'company_id' => $companyId,
                'agency_name' => $agencyName,
                'credit_balance' => (int)($data['credit_balance'] ?? 0),
            ];
        }
    }
    
    echo json_encode(['status' => 'success', 'data' => $subaccounts]); // Standardized response wrapper
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
