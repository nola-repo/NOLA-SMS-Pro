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
    $tokensRef = $db->collection('ghl_tokens');
    $query = $tokensRef->where('appType', '=', 'agency')->documents();
    
    $agencies = [];
    foreach ($query as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $createdAt = $data['created_at'] ?? $data['createdAt'] ?? null;
            if ($createdAt instanceof \Google\Cloud\Core\Timestamp) {
                $createdAt = $createdAt->get()->format('F j, Y');
            } elseif (is_string($createdAt)) {
                $createdAt = date('F j, Y', strtotime($createdAt));
            } else {
                $createdAt = 'Unknown Date';
            }
            
            $companyId = $data['companyId'] ?? $data['company_id'] ?? $doc->id();
            
            $agencies[] = [
                'id' => $companyId,
                'name' => $data['company_name'] ?? $data['locationName'] ?? $data['agency_name'] ?? 'Unnamed Agency',
                'created_at' => $createdAt,
                'status' => $data['status'] ?? 'active'
            ];
        }
    }
    
    echo json_encode(['agencies' => $agencies]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
