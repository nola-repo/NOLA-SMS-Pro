<?php

/**
 * Background provider balance refresh.
 *
 * Cloud Scheduler should call this endpoint with X-Cron-Secret. It is the only
 * path that needs to fan out to provider account APIs for admin dashboard data.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(55);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../services/SemaphoreBalanceFetcher.php';
require_once __DIR__ . '/../cache_helper.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $cronSecret = getenv('CRON_SECRET');
    $providedSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['cron_secret'] ?? null;
    if (empty($cronSecret) || $providedSecret !== $cronSecret) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: invalid or missing cron secret.']);
        exit;
    }
}

$startedAt = microtime(true);

try {
    $db = get_firestore();
    $fetcher = new SemaphoreBalanceFetcher();
    $summary = $fetcher->getDashboardSummary($db);
    $updatedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

    $db->collection('admin_config')->document('provider_balance_summary')->set([
        'summary' => $summary,
        'updated_at' => $updatedAt,
        'refresh_source' => 'provider_balance_refresh_cron',
    ], ['merge' => true]);

    NolaCache::delete('admin_provider_balances');
    NolaCache::delete('admin_system_health_status');

    echo json_encode([
        'status' => 'ok',
        'updated_at' => $updatedAt,
        'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
        'summary' => $summary,
    ]);
} catch (\Throwable $e) {
    error_log('[provider_balance_refresh_cron][FATAL] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
    ]);
}
