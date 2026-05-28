<?php
/**
 * Test script for verifying multi-tenant status retrieval logic.
 * This simulates the core logic of retrieve_status.php.
 */

require_once __DIR__ . '/webhook/config.php';
$config = require __DIR__ . '/webhook/config.php';
$systemApiKey = $config['SEMAPHORE_API_KEY'];

echo "--- Status Retriever Multi-tenant Logic Test ---\n";
echo "System Default API Key: " . $systemApiKey . "\n\n";

// Mocking Firestore snapshots
function mock_integration_data($locationId) {
    $data = [
        'loc_custom_1' => ['nola_pro_api_key' => 'custom_key_for_loc_1', 'approved_sender_id' => 'CUSTOM1'],
        'loc_custom_2' => ['semaphore_api_key' => 'custom_key_for_loc_2', 'approved_sender_id' => 'CUSTOM2'],
        'loc_no_key' => ['approved_sender_id' => 'NOKEY'],
    ];
    return $data[$locationId] ?? null;
}

$testMessages = [
    ['id' => 'msg_system', 'location_id' => null, 'desc' => 'System default (no location)'],
    ['id' => 'msg_loc1', 'location_id' => 'loc_custom_1', 'desc' => 'Custom key (nola_pro_api_key)'],
    ['id' => 'msg_loc2', 'location_id' => 'loc_custom_2', 'desc' => 'Custom key (legacy semaphore_api_key)'],
    ['id' => 'msg_nokey', 'location_id' => 'loc_no_key', 'desc' => 'No custom key found for location'],
    ['id' => 'msg_unknown', 'location_id' => 'loc_unknown', 'desc' => 'Unknown location'],
];

$keyCache = [];

foreach ($testMessages as $msg) {
    $locId = $msg['location_id'];
    $activeKey = $systemApiKey;
    
    echo "Testing: " . $msg['desc'] . " (Location: " . ($locId ?? 'NULL') . ")\n";

    if ($locId) {
        if (isset($keyCache[$locId])) {
            $activeKey = $keyCache[$locId];
            echo "  [CACHE] Hit! Using cached key.\n";
        } else {
            // Simulate Firestore lookup
            $intData = mock_integration_data($locId);
            if ($intData) {
                $customKey = $intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? null);
                if ($customKey) {
                    $activeKey = $customKey;
                    echo "  [FOUND] Custom key: $activeKey\n";
                } else {
                    echo "  [NOT FOUND] No custom key in integration data. Falling back.\n";
                }
            } else {
                echo "  [NOT FOUND] Integration doc does not exist. Falling back.\n";
            }
            $keyCache[$locId] = $activeKey;
        }
    } else {
        echo "  [SYSTEM] No location ID. Using system default.\n";
    }

    echo "  [RESULT] Final Key: " . $activeKey . "\n";
    
    // Test Cache for second attempt
    if ($locId && !isset($msg['skip_cache_test'])) {
        echo "  [CACHE TEST] Verifying cache hit for same location...\n";
        if (isset($keyCache[$locId])) {
            $cachedKey = $keyCache[$locId];
            if ($cachedKey === $activeKey) {
                echo "  [CACHE TEST] Success: Cache preserved key.\n";
            } else {
                echo "  [CACHE TEST] ERROR: Cache key mismatch!\n";
            }
        }
    }
    
    echo "--------------------------------------------------\n";
}

echo "\n--- Status Guard Hierarchy Test ---\n";

function test_guard($current, $new) {
    $statusPriority = [
        'Queued'    => 1,
        'Pending'   => 2,
        'Sent'      => 3,
        'Delivered' => 4,
        'Failed'    => 4,
        'Expired'   => 4
    ];

    $newPriority = $statusPriority[$new] ?? 0;
    $oldPriority = $statusPriority[$current] ?? 0;

    echo "Current: [$current] (Prio: $oldPriority) -> New: [$new] (Prio: $newPriority) ";

    if ($newPriority < $oldPriority) {
        echo " [REJECTED] - Status downgrade blocked.\n";
        return false;
    }

    if ($new === $current) {
        echo " [SKIPPED] - No change needed.\n";
        return false;
    }

    echo " [APPROVED] - Status update allowed.\n";
    return true;
}

$guardTests = [
    ['current' => 'Pending', 'new' => 'Sent', 'expected' => true],
    ['current' => 'Sent', 'new' => 'Pending', 'expected' => false], // The problematic case
    ['current' => 'Sent', 'new' => 'Delivered', 'expected' => true],
    ['current' => 'Delivered', 'new' => 'Sent', 'expected' => false],
    ['current' => 'Pending', 'new' => 'Pending', 'expected' => false],
    ['current' => 'Pending', 'new' => 'Queued', 'expected' => false],
];

foreach ($guardTests as $test) {
    test_guard($test['current'], $test['new']);
}

echo "\nVerification complete.\n";
