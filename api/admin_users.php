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
    
    // Global CRM view of subaccounts
    $integrations = $db->collection('integrations')->limit($limit)->documents();
    
    $subaccounts = [];
    foreach ($integrations as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            
            $subaccounts[] = [
                'id' => $doc->id(),
                'location_id' => $data['location_id'] ?? '',
                'location_name' => $data['location_name'] ?? 'Unnamed Subaccount',
                'company_id' => $data['companyId'] ?? $data['company_id'] ?? '',
                'agency_name' => $data['agency_name'] ?? 'Unknown Agency',
                'credit_balance' => (int)($data['credit_balance'] ?? 0),
            ];
        }
    }
    
    echo json_encode($subaccounts); // Response array of objects globally
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
