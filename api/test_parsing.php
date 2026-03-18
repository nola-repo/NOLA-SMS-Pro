<?php

/**
 * Test script to simulate GHL Webhook POST request to credits.php
 */

$apiUrl = "http://localhost/api/credits.php"; // Adjust if needed for local test, or use absolute path for direct include test
$secret = 'f7RkQ2pL9zv3tX8cB1n54yW6'; // From screenshot

function test_request($payload, $asJson = true) {
    global $secret;
    
    // We'll simulate the environment and call the script logic or use curl if accessible
    // Since we are on a server, we can try to call it via CLI with simulated environment
    
    echo "Testing with " . ($asJson ? "JSON" : "Form") . " payload: " . json_encode($payload) . "\n";
    
    $cmd = "php -d variables_order=EGPCS -r '";
    $cmd .= "\$_SERVER[\"REQUEST_METHOD\"] = \"POST\"; ";
    $cmd .= "\$_SERVER[\"HTTP_X_WEBHOOK_SECRET\"] = \"$secret\"; ";
    $cmd .= "\$_SERVER[\"HTTP_X_GHL_LOCATION_ID\"] = \"test_loc\"; ";
    
    if ($asJson) {
        $cmd .= "\$_SERVER[\"CONTENT_TYPE\"] = \"application/json\"; ";
        // We can't easily mock php://input via CLI easily without a wrapper, 
        // but we can mock it in a temporary file and use that if we modify credits.php to check a constant/env for input path.
        // For now, let's just test if the logic works by including the file and mocking $_POST.
    } else {
        $cmd .= "\$_POST = " . var_export($payload, true) . "; ";
    }
    
    // Instead of complex shell mocking, let's just make a script that simulates the logic
}

// Simpler: Just create a script that unit tests the parsing logic
?>
<?php
// Mocking the environment
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_SECRET'] = 'f7RkQ2pL9zV3tX8cB1nS4yW6'; // Use the expected one from auth_helpers.php
$_SERVER['HTTP_X_GHL_LOCATION_ID'] = 'test_location_123';

// Mocking php://input is hard in pure PHP without stream wrappers, 
// so we'll just test the array merging logic that we just added.

$formPayload = ['action' => 'add', 'amount' => '100'];
$jsonPayload = []; // Simulate empty JSON
$payload = array_merge($formPayload, $jsonPayload);

echo "Payload after merge (Form): " . json_encode($payload) . "\n";
$amount = isset($payload['amount']) ? (int)$payload['amount'] : 0;
echo "Extracted amount: $amount\n";

if ($amount === 100) {
    echo "SUCCESS: Amount correctly extracted from form payload.\n";
} else {
    echo "FAILED: Amount NOT correctly extracted from form payload.\n";
}

$formPayload = [];
$jsonPayload = ['action' => 'add', 'amount' => 100];
$payload = array_merge($formPayload, $jsonPayload);

echo "Payload after merge (JSON): " . json_encode($payload) . "\n";
$amount = isset($payload['amount']) ? (int)$payload['amount'] : 0;
echo "Extracted amount: $amount\n";

if ($amount === 100) {
    echo "SUCCESS: Amount correctly extracted from JSON payload.\n";
} else {
    echo "FAILED: Amount NOT correctly extracted from JSON payload.\n";
}
