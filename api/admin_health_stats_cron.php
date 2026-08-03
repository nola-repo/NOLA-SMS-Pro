<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
if (function_exists('set_time_limit')) {
    @set_time_limit(180);
}

header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/cache_helper.php';

use Google\Cloud\Core\Timestamp;

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $cronSecret = getenv('CRON_SECRET');
    $providedSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['cron_secret'] ?? null;
    if ($cronSecret === false || trim((string)$cronSecret) === '' || !hash_equals((string)$cronSecret, (string)$providedSecret)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: invalid or missing cron secret.']);
        exit;
    }
}

try {
    $db = get_firestore();
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $ts = new Timestamp($now);

    $locationToCreditMap = [];
    $usersScanned = 0;
    $integrationsScanned = 0;
    $accounts = [];
    $totalSubaccounts = 0;
    $lowBalanceCount = 0;

    $users = $db->collection('users')->documents();
    foreach ($users as $userDoc) {
        $usersScanned++;
        if (!$userDoc->exists()) {
            continue;
        }
        $uData = $userDoc->data();
        $bal = isset($uData['credit_balance']) ? (int)$uData['credit_balance'] : null;
        if ($bal === null) {
            continue;
        }
        foreach (['active_location_id', 'location_id'] as $field) {
            $loc = trim((string)($uData[$field] ?? ''));
            if ($loc !== '') {
                $locationToCreditMap[$loc] = $bal;
                $locationToCreditMap['ghl_' . $loc] = $bal;
            }
        }
    }

    $integrations = $db->collection('integrations')->documents();
    foreach ($integrations as $intDoc) {
        $integrationsScanned++;
        if (!$intDoc->exists()) {
            continue;
        }

        $intData = $intDoc->data();
        $intDocId = $intDoc->id();
        $locId = $intData['location_id'] ?? str_replace('ghl_', '', $intDocId);
        if ($locId === 'ghl') {
            continue;
        }

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
                'credit_balance' => $creditBalance,
            ],
        ];
    }

    $payload = [
        'generated_at' => $ts,
        'users_scanned' => $usersScanned,
        'integrations_scanned' => $integrationsScanned,
        'total_subaccounts' => $totalSubaccounts,
        'low_balance_subaccounts' => $lowBalanceCount,
        'accounts' => $accounts,
    ];

    $db->collection('admin_config')->document('dashboard_stats')->set($payload, ['merge' => true]);
    NolaCache::delete('admin_system_health_status_v2');

    echo json_encode([
        'status' => 'success',
        'generated_at' => $now->format('c'),
        'users_scanned' => $usersScanned,
        'integrations_scanned' => $integrationsScanned,
        'total_subaccounts' => $totalSubaccounts,
        'low_balance_subaccounts' => $lowBalanceCount,
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    error_log('[admin_health_stats_cron] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to compute dashboard stats.',
        'error' => $e->getMessage(),
    ]);
}
