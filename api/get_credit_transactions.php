<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

validate_api_request();

$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Method not allowed',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $locId = get_ghl_location_id();
    $accountId = $locId ?: 'default';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    
    // Query credit_transactions for this account, sorted by newest first
    $transactionsRef = $db->collection('credit_transactions');
    $query = $transactionsRef->where('account_id', '=', $accountId)
                             ->orderBy('created_at', 'DESC')
                             ->limit($limit);
                             
    $documents = $query->documents();
    
    $transactions = [];
    foreach ($documents as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            
            // Format timestamp if present
            if (isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
                $data['created_at'] = $data['created_at']->formatAsString();
            }
            
            $transactions[] = $data;
        }
    }

    echo json_encode([
        'success'      => true,
        'account_id'   => $accountId,
        'count'        => count($transactions),
        'transactions' => $transactions,
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to fetch credit transactions',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
