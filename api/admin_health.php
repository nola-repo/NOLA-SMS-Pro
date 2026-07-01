<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/services/SmsGatewayService.php';
require_once __DIR__ . '/cache_helper.php';
require_once __DIR__ . '/performance_logger.php';

NolaPerformance::start('/api/admin_health.php');

// Authenticate GET requests (super_admin, support, viewer allowed)
NolaPerformance::begin('auth');
$claims = require_secure_admin_auth(['super_admin', 'support', 'viewer']);
NolaPerformance::end('auth');

$db = get_firestore();

$cacheKey = "admin_system_health_status";
NolaPerformance::begin('cache_read');
$cachedPayload = NolaCache::get($cacheKey);
NolaPerformance::end('cache_read');
if ($cachedPayload !== null) {
    NolaPerformance::cache('HIT');
    echo json_encode($cachedPayload);
    exit;
}
NolaPerformance::cache('MISS');
NolaPerformance::begin('data_load');

// 1. Test database connection
$dbConnected = false;
try {
    NolaPerformance::increment('firestore_document_reads');
    $db->collection('system_settings')->document('core')->snapshot();
    $dbConnected = true;
} catch (\Throwable $e) {
    error_log("[admin_health.php] Database connection test failed: " . $e->getMessage());
}

// 2. Load active provider status and balance
$providerName = 'system';
$providerStatus = 'unknown';
$providerBalance = 0;
$providerConfigured = false;
$providerDetails = [];

try {
    NolaPerformance::begin('provider_api');
    $gateway = new SmsGatewayService();
    $providerName = $gateway->getProviderName();
    $resolvedProvider = ($providerName === 'auto_failover') ? 'semaphore' : $providerName;
    $providerInstance = $gateway->getProviderInstance($resolvedProvider);
    
    // Check account balance via API call to Semaphore or UniSMS
    $accCheck = $providerInstance->checkAccount();
    $providerStatus = $accCheck['status'] ?? 'inactive';
    $providerBalance = $accCheck['credits'] ?? 0;
    $providerConfigured = ($providerStatus === 'active');
    
    $providerDetails = [
        'name' => $providerName,
        'resolved_provider' => $resolvedProvider,
        'status' => $providerStatus,
        'balance' => $providerBalance,
        'configured' => $providerConfigured,
        'email' => $accCheck['email'] ?? null,
    ];
    NolaPerformance::end('provider_api');
} catch (\Throwable $e) {
    NolaPerformance::end('provider_api');
    error_log("[admin_health.php] Provider health check failed: " . $e->getMessage());
    $providerDetails = [
        'name' => $providerName,
        'status' => 'error',
        'balance' => 0,
        'configured' => false,
        'error' => $e->getMessage()
    ];
}

// 3. Compute SMS statistics and fetch diagnostics logs
$logs = [];
$totalMessages = 0;
$sentCount = 0;
$failedCount = 0;
$pendingCount = 0;

try {
    NolaPerformance::increment('firestore_queries');
    $messages = $db->collection('messages')->orderBy('date_created', 'DESC')->limit(30)->documents();
    foreach ($messages as $doc) {
        if ($doc->exists()) {
            NolaPerformance::increment('documents_processed');
            $data = $doc->data();
            $ts = isset($data['date_created']) && $data['date_created'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['date_created']->get()->format('c') : null;
            
            $status = strtolower(trim((string)($data['status'] ?? $data['delivery_status'] ?? '')));
            if (in_array($status, ['sent', 'delivered', 'success', 'successful', 'completed'])) {
                $sentCount++;
            } elseif (in_array($status, ['failed', 'rejected', 'revoked', 'error', 'denied', 'undelivered'])) {
                $failedCount++;
            } else {
                $pendingCount++;
            }
            
            $totalMessages++;
            
            $logs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'message',
                'timestamp' => $ts
            ]);
        }
    }

    // Fetch sender requests for the unified logs
    NolaPerformance::increment('firestore_queries');
    $requests = $db->collection('sender_id_requests')->orderBy('created_at', 'DESC')->limit(20)->documents();
    foreach ($requests as $doc) {
        if ($doc->exists()) {
            NolaPerformance::increment('documents_processed');
            $data = $doc->data();
            $ts = isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['created_at']->get()->format('c') : null;
            
            $logs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'sender_request',
                'timestamp' => $ts
            ]);
        }
    }

    // Fetch credit transactions for the unified logs
    NolaPerformance::increment('firestore_queries');
    $purchases = $db->collection('credit_transactions')->orderBy('created_at', 'DESC')->limit(20)->documents();
    foreach ($purchases as $doc) {
        if ($doc->exists()) {
            NolaPerformance::increment('documents_processed');
            $data = $doc->data();
            $ts = isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp 
                  ? $data['created_at']->get()->format('c') : null;
            
            $logs[] = array_merge($data, [
                'id' => $doc->id(),
                'type' => 'credit_purchase',
                'timestamp' => $ts
            ]);
        }
    }

    // Sort combined logs by timestamp descending
    usort($logs, function($a, $b) {
        $timeA = strtotime($a['timestamp'] ?? '1970-01-01');
        $timeB = strtotime($b['timestamp'] ?? '1970-01-01');
        return $timeB - $timeA;
    });

    $logs = array_slice($logs, 0, 50);

} catch (\Throwable $e) {
    error_log("[admin_health.php] Failed to fetch system logs: " . $e->getMessage());
}

// 4. Fetch low-balance and total subaccounts count
$accounts = [];
$totalSubaccounts = 0;
$lowBalanceCount = 0;

try {
    $locationToCreditMap = [];
    NolaPerformance::increment('firestore_queries');
    $users = $db->collection('users')->documents();
    foreach ($users as $userDoc) {
        if ($userDoc->exists()) {
            NolaPerformance::increment('documents_processed');
            $uData = $userDoc->data();
            $bal = isset($uData['credit_balance']) ? (int)$uData['credit_balance'] : null;
            if ($bal !== null) {
                foreach (['active_location_id', 'location_id'] as $field) {
                    $loc = trim((string)($uData[$field] ?? ''));
                    if ($loc !== '') {
                        $locationToCreditMap[$loc] = $bal;
                        $locationToCreditMap['ghl_' . $loc] = $bal;
                    }
                }
            }
        }
    }

    NolaPerformance::increment('firestore_queries');
    $integrations = $db->collection('integrations')->documents();
    foreach ($integrations as $intDoc) {
        if ($intDoc->exists()) {
            NolaPerformance::increment('documents_processed');
            $intData = $intDoc->data();
            $intDocId = $intDoc->id();
            $locId = $intData['location_id'] ?? str_replace('ghl_', '', $intDocId);
            if ($locId === 'ghl') continue;
            
            $totalSubaccounts++;
            
            $locationName = $intData['location_name'] ?? 'Unknown Location';
            $creditBalance = $locationToCreditMap[$locId] ?? $locationToCreditMap['ghl_' . $locId] ?? (int)($intData['credit_balance'] ?? 0);
            
            if ($creditBalance <= 10) {
                $lowBalanceCount++;
            }
            
            $accounts[] = [
                'id' => $intDocId,
                'data' => [
                    'location_id' => $locId,
                    'location_name' => $locationName,
                    'credit_balance' => $creditBalance
                ]
            ];
        }
    }
} catch (\Throwable $e) {
    error_log("[admin_health.php] Failed to compute subaccount counts: " . $e->getMessage());
}

$deliveryRate = $totalMessages > 0 ? round(($sentCount / $totalMessages) * 100) : 100;

// Fetch settings values required by front-end settings validation
$settingsData = null;
try {
    NolaPerformance::increment('firestore_document_reads', 2);
    $settingsSnap = $db->collection('system_settings')->document('core')->snapshot();
    $providerSnap = $db->collection('admin_config')->document('sms_provider')->snapshot();
    $coreSettings = $settingsSnap->exists() ? $settingsSnap->data() : [];
    $provSettings = $providerSnap->exists() ? $providerSnap->data() : [];
    $settingsData = [
        'sender_default' => $coreSettings['sender_default'] ?? 'NOLASMSPro',
        'free_limit' => (int)($coreSettings['free_limit'] ?? 10),
        'maintenance_mode' => (bool)($coreSettings['maintenance_mode'] ?? false),
        'poll_interval' => (int)($coreSettings['poll_interval'] ?? 15),
        'sms_provider' => [
            'active_provider' => $provSettings['active_provider'] ?? 'semaphore',
            'unisms_configured' => !empty($provSettings['unisms_api_key']),
            'unisms_sender_id' => $provSettings['unisms_sender_id'] ?? '',
        ]
    ];
} catch (\Throwable $e) {
    error_log("[admin_health.php] Failed to fetch config settings: " . $e->getMessage());
}

$responsePayload = [
    'status' => 'success',
    'data' => [
        'database_connected' => $dbConnected,
        'provider' => $providerDetails,
        'stats' => [
            'total_messages' => $totalMessages,
            'sent_messages' => $sentCount,
            'failed_messages' => $failedCount,
            'pending_messages' => $pendingCount,
            'delivery_rate' => $deliveryRate,
            'total_subaccounts' => $totalSubaccounts,
            'low_balance_subaccounts' => $lowBalanceCount,
        ],
        'logs' => $logs,
        'accounts' => $accounts,
        'settings' => $settingsData
    ]
];

// Cache for 15 seconds to prevent spamming APIs while maintaining freshness
NolaPerformance::end('data_load');
NolaPerformance::begin('cache_write');
NolaCache::set($cacheKey, $responsePayload, 15);
NolaPerformance::end('cache_write');

echo json_encode($responsePayload);
exit;
