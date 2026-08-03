<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

header('Content-Type: application/json');

require __DIR__ . '/firestore_client.php';
require_once __DIR__ . '/../services/GhlSyncJobService.php';

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

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($isCli && isset($argv[1])) {
    $limit = (int)$argv[1];
}
$limit = max(1, min(50, $limit));

try {
    $db = get_firestore();
    $service = new \Nola\Services\GhlSyncJobService($db);
    $result = $service->processDueJobs($limit);

    echo json_encode([
        'status' => 'success',
        'limit' => $limit,
        'processed' => $result['processed'],
        'results' => $result['results'],
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    error_log('[process_ghl_sync_jobs] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to process GHL sync jobs.',
        'error' => $e->getMessage(),
    ]);
}
