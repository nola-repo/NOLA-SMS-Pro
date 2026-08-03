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

$cacheKey = "admin_system_health_status_v2";
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

// 2. Load BOTH providers' status and balance
$activeProviderName = 'system';
$providerDetails = [];

try {
    NolaPerformance::begin('provider_api');
    $gateway = new SmsGatewayService();
    $activeProviderName = $gateway->getProviderName();

    $semProvider = $gateway->getProviderInstance('semaphore');
    $uniProvider = $gateway->getProviderInstance('unisms');

    $semCheck = $semProvider->checkAccount();
    $uniCheck = $uniProvider->checkAccount();

    $semCredits = (int)($semCheck['credits'] ?? 0);
    $uniCredits = (int)($uniCheck['credits'] ?? 0);

    $providerDetails = [
        'active_provider' => $activeProviderName,
        'all_providers' => [
            'semaphore' => [
                'name'        => 'Semaphore',
                'status'      => $semCheck['status'] ?? 'inactive',
                'credits'     => $semCredits,
                'configured'  => ($semCheck['status'] ?? '') === 'active',
                'is_active'   => in_array($activeProviderName, ['semaphore', 'auto_failover'], true),
                'warning'     => $semCredits < 1000 && $semCredits >= 300 && ($semCheck['status'] ?? '') === 'active',
                'critical'    => $semCredits < 300 && ($semCheck['status'] ?? '') === 'active',
                'error'       => null,
            ],
            'unisms' => [
                'name'        => 'UniSMS',
                'status'      => $uniCheck['status'] ?? 'inactive',
                'credits'     => $uniCredits,
                'email'       => $uniCheck['email'] ?? null,
                'sid_tokens'  => isset($uniCheck['sid_tokens']) ? (int)$uniCheck['sid_tokens'] : null,
                'configured'  => ($uniCheck['status'] ?? '') === 'active',
                'is_active'   => $activeProviderName === 'unisms',
                'warning'     => $uniCredits < 200 && $uniCredits >= 50 && ($uniCheck['status'] ?? '') === 'active',
                'critical'    => $uniCredits < 50 && ($uniCheck['status'] ?? '') === 'active',
                'error'       => null,
            ],
        ],
    ];
    NolaPerformance::end('provider_api');
} catch (\Throwable $e) {
    NolaPerformance::end('provider_api');
    error_log("[admin_health.php] Provider health check failed: " . $e->getMessage());
    $providerDetails = [
        'active_provider' => $activeProviderName,
        'all_providers'   => [],
        'error'           => $e->getMessage(),
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
            
            $logs[] = array_filter([
                'id' => $doc->id(),
                'type' => 'message',
                'timestamp' => $ts,
                'status' => $data['status'] ?? ($data['delivery_status'] ?? null),
                'location_id' => $data['location_id'] ?? null,
                'provider' => $data['provider'] ?? ($data['source'] ?? null),
                'provider_status' => $data['provider_status'] ?? null,
                'provider_error' => $data['provider_error'] ?? null,
                'direction' => $data['direction'] ?? null,
                'batch_id' => $data['batch_id'] ?? null,
                'ghl_sync_success' => $data['ghl_sync_success'] ?? null,
                'ghl_sync_skipped' => $data['ghl_sync_skipped'] ?? null,
            ], static fn($v) => $v !== null);
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
$subaccountScanLimit = 1000;
$subaccountStatsTruncated = false;
$subaccountStatsSource = 'live_scan';

try {
    NolaPerformance::increment('firestore_queries');
    $statsSnap = $db->collection('admin_config')->document('dashboard_stats')->snapshot();
    $statsData = $statsSnap->exists() ? $statsSnap->data() : [];
    $generatedAt = $statsData['generated_at'] ?? null;
    $generatedAtUnix = $generatedAt instanceof \Google\Cloud\Core\Timestamp ? $generatedAt->get()->getTimestamp() : 0;

    if ($generatedAtUnix > 0 && (time() - $generatedAtUnix) <= 600) {
        $accounts = is_array($statsData['accounts'] ?? null) ? $statsData['accounts'] : [];
        $totalSubaccounts = (int)($statsData['total_subaccounts'] ?? 0);
        $lowBalanceCount = (int)($statsData['low_balance_subaccounts'] ?? 0);
        $subaccountStatsSource = 'dashboard_stats';
    } else {
        $locationToCreditMap = [];
        $usersScanned = 0;
        NolaPerformance::increment('firestore_queries');
        $users = $db->collection('users')->limit($subaccountScanLimit)->documents();
        foreach ($users as $userDoc) {
            $usersScanned++;
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

        $integrationsScanned = 0;
        NolaPerformance::increment('firestore_queries');
        $integrations = $db->collection('integrations')->limit($subaccountScanLimit)->documents();
        foreach ($integrations as $intDoc) {
            $integrationsScanned++;
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
        $subaccountStatsTruncated = $usersScanned >= $subaccountScanLimit || $integrationsScanned >= $subaccountScanLimit;
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
        'cache' => NolaCache::getDiagnostics(),
        'provider' => $providerDetails,
        'stats' => [
            'total_messages' => $totalMessages,
            'sent_messages' => $sentCount,
            'failed_messages' => $failedCount,
            'pending_messages' => $pendingCount,
            'delivery_rate' => $deliveryRate,
            'total_subaccounts' => $totalSubaccounts,
            'low_balance_subaccounts' => $lowBalanceCount,
            'subaccount_stats_truncated' => $subaccountStatsTruncated,
            'subaccount_stats_source' => $subaccountStatsSource,
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
