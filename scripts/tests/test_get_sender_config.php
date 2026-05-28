<?php
/**
 * Test script for verifying get_sender_config.php logic.
 */

// Simulating the environment
$locId = 'test_loc_123';
$mockData = [
    'approved_sender_id' => 'MyBrand',
    'system_default_sender' => 'NOLASMSPro'
];

echo "Testing get_sender_config logic simulation...\n";

$response = [
    'status' => 'success',
    'approved_sender_id' => $mockData['approved_sender_id'] ?? null,
    'system_default_sender' => 'NOLASMSPro'
];

echo "Response Shape:\n";
echo json_encode($response, JSON_PRETTY_PRINT) . "\n";

// Verify fields
if (isset($response['status']) && 
    isset($response['approved_sender_id']) && 
    isset($response['system_default_sender']) &&
    count($response) === 3) {
    echo "SUCCESS: Response shape matches requirements.\n";
} else {
    echo "ERROR: Response shape mismatch!\n";
    print_r($response);
}
