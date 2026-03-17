<?php

// Mocking the behavior of extracting location name from GHL API response
function test_extraction($response) {
    if (!$response) return '';
    $data = json_decode($response, true);
    return $data['location']['name'] ?? '';
}

// Test Case 1: Successful response
$mockResponseOk = json_encode([
    'location' => [
        'id' => 'abc12345',
        'name' => 'Demo Subaccount',
        'address' => '123 Main St'
    ]
]);

$extractedOk = test_extraction($mockResponseOk);
echo "Test 1 (Successful Response): " . ($extractedOk === 'Demo Subaccount' ? "PASSED" : "FAILED") . " (Extracted: '$extractedOk')\n";

// Test Case 2: Missing name field
$mockResponseMissing = json_encode([
    'location' => [
        'id' => 'abc12345'
    ]
]);

$extractedMissing = test_extraction($mockResponseMissing);
echo "Test 2 (Missing Name): " . ($extractedMissing === '' ? "PASSED" : "FAILED") . " (Extracted: '$extractedMissing')\n";

// Test Case 3: Invalid JSON
$mockResponseInvalid = "Invalid JSON";
$extractedInvalid = test_extraction($mockResponseInvalid);
echo "Test 3 (Invalid JSON): " . ($extractedInvalid === '' ? "PASSED" : "FAILED") . " (Extracted: '$extractedInvalid')\n";
