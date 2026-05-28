<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = get_firestore();
$configRef = $db->collection('system_settings')->document('core');
$pricingRef = $db->collection('admin_config')->document('global_pricing');

if ($method === 'GET') {
    try {
        $snapshot = $configRef->snapshot();
        $pricingSnap = $pricingRef->snapshot();
        
        $raw = $snapshot->exists() ? $snapshot->data() : [];
        $pricing = $pricingSnap->exists() ? $pricingSnap->data() : [];

        // Return only the fields the frontend expects (exclude Firestore metadata)
        $data = [
            'sender_default'   => $raw['sender_default'] ?? 'NOLASMSPro',
            'free_limit'       => (int) ($raw['free_limit'] ?? 10),
            'maintenance_mode' => (bool) ($raw['maintenance_mode'] ?? false),
            'poll_interval'    => (int) ($raw['poll_interval'] ?? 15),
            'provider_cost'    => isset($pricing['provider_cost']) ? (float)$pricing['provider_cost'] : 0.02,
            'charged_rate'     => isset($pricing['charged']) ? (float)$pricing['charged'] : 0.05,
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
    
    $pricingData = [];
    if (isset($input['provider_cost'])) $pricingData['provider_cost'] = (float)$input['provider_cost'];
    if (isset($input['charged_rate'])) {
        $pricingData['charged'] = (float)$input['charged_rate'];
    } elseif (isset($input['charged'])) {
        $pricingData['charged'] = (float)$input['charged'];
    }
    
    if (empty($saveData) && empty($pricingData)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No valid settings provided']);
        exit;
    }
    
    try {
        if (!empty($saveData)) {
            $configRef->set($saveData, ['merge' => true]);
        }
        if (!empty($pricingData)) {
            $pricingRef->set($pricingData, ['merge' => true]);
        }
        echo json_encode(['status' => 'success', 'message' => 'Settings updated.']);
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
