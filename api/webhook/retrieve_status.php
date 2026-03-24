<?php
require_once __DIR__ . '/../cors.php';
date_default_timezone_set('UTC');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/cron_execution.log';

file_put_contents(
    $logFile,
    "[" . date('Y-m-d H:i:s') . "] Cron executed\n",
    FILE_APPEND
);


$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';

$apiKey = $config['SEMAPHORE_API_KEY'];
$db = get_firestore();

require __DIR__ . '/../services/StatusSync.php';

$updatedCount = \Nola\Services\StatusSync::runSync($db, $apiKey);

echo "Status update complete. Updated $updatedCount messages.";