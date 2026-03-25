<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = get_firestore();
$configRef = $db->collection('admin_config')->document('global');

if ($method === 'GET') {
    try {
        $snapshot = $configRef->snapshot();
        $data = $snapshot->exists() ? $snapshot->data() : [
            'sender_default' => 'NOLASMSPro',
            'free_limit' => 10,
            'maintenance_mode' => false,
            'poll_interval' => 15
        ];
        
        echo json_encode([
            'status' => 'success',
            'data' => $data
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch settings: ' . $e->getMessage()]);
        exit;
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Whitelist fields to save
    $saveData = [];
    if (isset($input['sender_default'])) $saveData['sender_default'] = $input['sender_default'];
    if (isset($input['free_limit'])) $saveData['free_limit'] = (int)$input['free_limit'];
    if (isset($input['maintenance_mode'])) $saveData['maintenance_mode'] = (bool)$input['maintenance_mode'];
    if (isset($input['poll_interval'])) $saveData['poll_interval'] = (int)$input['poll_interval'];
    
    if (empty($saveData)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No valid settings provided']);
        exit;
    }
    
    try {
        $configRef->set($saveData, ['merge' => true]);
        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}
