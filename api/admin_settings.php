<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/cache_helper.php';

$method = $_SERVER['REQUEST_METHOD'];
$claims = require_secure_admin_auth($method === 'GET'
    ? ['super_admin', 'support', 'viewer']
    : ['super_admin']);
$db = get_firestore();
$configRef = $db->collection('system_settings')->document('core');
$pricingRef = $db->collection('admin_config')->document('global_pricing');
$smsProviderRef = $db->collection('admin_config')->document('sms_provider');

function nola_settings_mask_secret(?string $secret): ?string
{
    $secret = trim((string)($secret ?? ''));
    if ($secret === '') {
        return null;
    }
    return substr($secret, 0, 3) . '...' . substr($secret, -4);
}

if ($method === 'GET') {
    $cacheKey = "admin_settings";
    $cachedData = NolaCache::get($cacheKey);
    if ($cachedData !== null) {
        echo json_encode($cachedData);
        exit;
    }

    try {
        $snapshot = $configRef->snapshot();
        $pricingSnap = $pricingRef->snapshot();
        $providerSnap = $smsProviderRef->snapshot();
        
        $raw = $snapshot->exists() ? $snapshot->data() : [];
        $pricing = $pricingSnap->exists() ? $pricingSnap->data() : [];
        $provider = $providerSnap->exists() ? $providerSnap->data() : [];

        // Return only the fields the frontend expects (exclude Firestore metadata)
        $data = [
            'sender_default'   => $raw['sender_default'] ?? 'NOLASMSPro',
            'free_limit'       => (int) ($raw['free_limit'] ?? 10),
            'maintenance_mode' => (bool) ($raw['maintenance_mode'] ?? false),
            'poll_interval'    => (int) ($raw['poll_interval'] ?? 15),
            'provider_cost'    => isset($pricing['provider_cost']) ? (float)$pricing['provider_cost'] : 0.02,
            'charged_rate'     => isset($pricing['charged']) ? (float)$pricing['charged'] : 0.05,
            'sms_provider'     => [
                'active_provider' => $provider['active_provider'] ?? 'semaphore',
                'unisms_configured' => !empty($provider['unisms_api_key']),
                'unisms_api_key_masked' => nola_settings_mask_secret($provider['unisms_api_key'] ?? null),
                'unisms_sender_id' => $provider['unisms_sender_id'] ?? '',
                'unisms_endpoint' => $provider['unisms_endpoint'] ?? 'https://unismsapi.com/api',
                'unisms_timeout_seconds' => (int)($provider['unisms_timeout_seconds'] ?? 15),
                'failover_timeout_seconds' => (int)($provider['failover_timeout_seconds'] ?? 8),
                'failover_log_enabled' => (bool)($provider['failover_log_enabled'] ?? true),
            ],
        ];

        $responsePayload = [
            'status' => 'success',
            'data' => $data
        ];
        NolaCache::set($cacheKey, $responsePayload, 300); // 5 minutes cache
        echo json_encode($responsePayload);
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

    $providerInput = is_array($input['sms_provider'] ?? null) ? $input['sms_provider'] : $input;
    $providerData = [];
    if (isset($providerInput['active_provider'])) {
        if (!in_array($providerInput['active_provider'], ['semaphore', 'unisms', 'auto_failover'], true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid active SMS provider']);
            exit;
        }
        $providerData['active_provider'] = $providerInput['active_provider'];
    }
    if (array_key_exists('unisms_api_key', $providerInput) && trim((string)$providerInput['unisms_api_key']) !== '') {
        $providerData['unisms_api_key'] = trim((string)$providerInput['unisms_api_key']);
        $providerData['unisms_api_key_last4'] = substr(trim((string)$providerInput['unisms_api_key']), -4);
    }
    if (isset($providerInput['unisms_sender_id'])) $providerData['unisms_sender_id'] = trim((string)$providerInput['unisms_sender_id']);
    if (isset($providerInput['unisms_endpoint'])) $providerData['unisms_endpoint'] = trim((string)$providerInput['unisms_endpoint']);
    if (isset($providerInput['unisms_timeout_seconds'])) $providerData['unisms_timeout_seconds'] = max(3, (int)$providerInput['unisms_timeout_seconds']);
    if (isset($providerInput['failover_timeout_seconds'])) $providerData['failover_timeout_seconds'] = max(3, (int)$providerInput['failover_timeout_seconds']);
    if (isset($providerInput['failover_log_enabled'])) $providerData['failover_log_enabled'] = (bool)$providerInput['failover_log_enabled'];
    
    if (empty($saveData) && empty($pricingData) && empty($providerData)) {
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
        if (!empty($providerData)) {
            $providerData['updated_at'] = new \Google\Cloud\Core\Timestamp(new \DateTime());
            $smsProviderRef->set($providerData, ['merge' => true]);
        }
        NolaCache::invalidateAdminDashboard();
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
