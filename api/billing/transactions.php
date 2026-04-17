<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';

// Auth checks
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$authHeader) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
    }
}
$bearerToken = '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $bearerToken = $m[1];
}

if (!$bearerToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization token required.']);
    exit;
}

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scope = $_GET['scope'] ?? 'subaccount'; // 'agency' or 'subaccount'
    $agency_id = $_GET['agency_id'] ?? null;
    $location_id = $_GET['location_id'] ?? null;
    
    // Ideally we would enforce month limits using >= and <= on created_at 
    // but in Firestore we'd use orderby and limits for simple pagination.
    $page = (int)($_GET['page'] ?? 1);
    $limit = 50;

    $query = $db->collection('credit_transactions')->where('wallet_scope', '==', $scope);

    if ($scope === 'agency' && $agency_id) {
        $query = $query->where('account_id', '==', $agency_id);
    } else if ($scope === 'subaccount' && $location_id) {
        $docId = (strpos($location_id, 'ghl_') === 0) ? $location_id : 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $location_id);
        $query = $query->where('account_id', '==', $docId);
    }

    // Manual client-side sort since we don't know if composite index exists
    $docs = $query->documents();
    $transactions = [];
    foreach ($docs as $doc) {
        $data = $doc->data();
        $transactions[] = [
            'id' => $data['transaction_id'] ?? $doc->id(),
            'type' => $data['type'] ?? '',
            'amount' => $data['amount'] ?? 0,
            'balance_after' => $data['balance_after'] ?? 0,
            'description' => $data['description'] ?? '',
            'timestamp' => isset($data['created_at']) ? $data['created_at']->get()->format('Y-m-d\TH:i:s\Z') : ''
        ];
    }

    usort($transactions, function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']); // Descending
    });

    $total = count($transactions);
    $offset = ($page - 1) * $limit;
    $paginated = array_slice($transactions, $offset, $limit);

    echo json_encode([
        'transactions' => $paginated,
        'total' => $total,
        'page' => $page,
        'limit' => $limit
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
