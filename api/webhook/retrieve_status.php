<?php

/**
 * Cloud Run Compatible SMS Status Synchronization Webhook
 * 
 * This script is triggered via Cloud Scheduler (e.g., every 5 minutes).
 * It updates the status of "Pending" or "Queued" SMS messages by checking the Semaphore API.
 */

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../auth_helpers.php';
// Requires ?secret= appended to Cloud Scheduler URL
validate_api_request();

date_default_timezone_set('UTC');

// 1. Replaced filesystem logging with standard error_log for Google Cloud Logging compatibility
error_log("[" . date('Y-m-d H:i:s') . "] Cron executed: Starting SMS status check.");

try {
    // 2. Load configurations and initialize database
    $config = require __DIR__ . '/config.php';
    require __DIR__ . '/firestore_client.php';
    require __DIR__ . '/../services/StatusSync.php';

    $apiKey = $config['SEMAPHORE_API_KEY'];
    $db = get_firestore();

    // --- Maintenance Mode Check ---
    $globalConfigRef = $db->collection('admin_config')->document('global');
    $globalConfigSnap = $globalConfigRef->snapshot();
    if ($globalConfigSnap->exists() && !empty($globalConfigSnap->data()['maintenance_mode'])) {
        error_log("[" . date('Y-m-d H:i:s') . "] Cron skipped: System is in maintenance mode.");
        echo "Maintenance mode active. Skipping sync.";
        exit;
    }
    // ------------------------------

    // 3. Keep the script stateless and delegate logic to the service class
    $updatedCount = \Nola\Services\StatusSync::runSync($db, $apiKey);

    // 4. Output success message so Cloud Scheduler records a successful HTTP response
    $responseMessage = "Status update complete. Updated $updatedCount messages.";
    error_log("[" . date('Y-m-d H:i:s') . "] Cron finished: $responseMessage");

    echo $responseMessage;

}
catch (\Exception $e) {
    // Basic error handling for initialization failures
    error_log("[retrieve_status.php] Fatal error during execution: " . $e->getMessage());
    http_response_code(500);
    echo "An error occurred while running the cron job.";
}