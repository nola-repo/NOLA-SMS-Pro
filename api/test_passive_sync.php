<?php
/**
 * Test script for verifying Passive Status Sync logic.
 */

require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/webhook/config.php';
require_once __DIR__ . '/services/StatusSync.php';

$db = get_firestore();
$config = require __DIR__ . '/webhook/config.php';
$apiKey = $config['SEMAPHORE_API_KEY'];

echo "--- Passive Status Sync Test ---\n";

// 1. Test Force Sync
echo "Step 1: Running forced sync...\n";
$updatedCount = \Nola\Services\StatusSync::syncIfNecessary($db, $apiKey, true);
echo "  [RESULT] Updated $updatedCount messages.\n";

// 2. Test Rate Limiting
echo "Step 2: Running immediate follow-up sync (should be skipped)...\n";
$wasRun = \Nola\Services\StatusSync::syncIfNecessary($db, $apiKey, false);
if ($wasRun === false) {
    echo "  [SUCCESS] Sync was correctly skipped due to rate limiting.\n";
} else {
    echo "  [ERROR] Sync was NOT skipped!\n";
}

echo "\nVerification complete.\n";
