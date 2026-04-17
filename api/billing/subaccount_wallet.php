<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';

// Try standard JWT check first
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$authHeader) {
    // Check if called via GHL token fallback for subaccounts
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
    }
}

$bearerToken = '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $bearerToken = $m[1];
}

$db = get_firestore();

// Optional JWT decode
if ($bearerToken) {
    $jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
    $claims = jwt_verify($bearerToken, $jwtSecret);
}

$location_id = $_GET['location_id'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $location_id = $input['location_id'] ?? $location_id;
    $action = $input['action'] ?? $_GET['action'] ?? '';
} else {
    $action = $_GET['action'] ?? '';
}

if (!$location_id) {
    http_response_code(400);
    echo json_encode(['error' => 'location_id is required']);
    exit;
}

$docId = (strpos($location_id, 'ghl_') === 0) ? $location_id : 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $location_id);
$subaccountRef = $db->collection('integrations')->document($docId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $snapshot = $subaccountRef->snapshot();
    $data = $snapshot->exists() ? $snapshot->data() : [];
    
    echo json_encode([
        'balance' => $data['credit_balance'] ?? 0,
        'auto_recharge_enabled' => $data['auto_recharge_enabled'] ?? false,
        'auto_recharge_amount' => $data['auto_recharge_amount'] ?? 250,
        'auto_recharge_threshold' => $data['auto_recharge_threshold'] ?? 25,
        'updated_at' => isset($data['updated_at']) ? $data['updated_at']->get()->format('Y-m-d\TH:i:s\Z') : null
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'set_auto_recharge') {
        $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : false;
        $amount = isset($input['amount']) ? (int)$input['amount'] : 250;
        $threshold = isset($input['threshold']) ? (int)$input['threshold'] : 25;

        $subaccountRef->set([
            'auto_recharge_enabled' => $enabled,
            'auto_recharge_amount'  => $amount,
            'auto_recharge_threshold' => $threshold,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'request_credits') {
        $amount = isset($input['amount']) ? (int)$input['amount'] : 0;
        $note = $input['note'] ?? '';

        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'amount must be greater than zero']);
            exit;
        }

        // To submit a request, we need to know the agency_id. Look it up.
        $agencyDoc = $db->collection('agency_subaccounts')->document($location_id)->snapshot();
        $agency_id = $agencyDoc->exists() ? ($agencyDoc->data()['agency_id'] ?? null) : null;
        $location_name = $agencyDoc->exists() ? ($agencyDoc->data()['name'] ?? 'Unnamed Location') : 'Unnamed Location';
        
        if (!$agency_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Could not determine agency_id for this subaccount']);
            exit;
        }

        $requestRef = $db->collection('credit_requests')->newDocument();
        $now = new \DateTime();
        
        $requestRef->set([
            'request_id' => $requestRef->id(),
            'agency_id' => $agency_id,
            'location_id' => $location_id,
            'location_name' => $location_name,
            'amount' => $amount,
            'status' => 'pending',
            'note' => $note,
            'created_at' => new \Google\Cloud\Core\Timestamp($now),
            'resolved_at' => null,
            'resolved_by' => null
        ]);

        echo json_encode(['success' => true, 'request_id' => $requestRef->id()]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
