<?php

// Mocking some server variables for the test
$_SERVER['HTTP_X_WEBHOOK_SECRET'] = 'f7RkQ2pL9zV3tX8cB1nS4yW6';
$_SERVER['HTTP_X_GHL_LOCATION_ID'] = 'ABC123XYZ_TEST';

echo "Testing /api/account.php execution...\n";

// We'll try to run the file directly but we need to capture the output
// Since it calls exit, we'll use a wrapper if needed or just check if we can mock enough.

// Actually, let's just write a script that tries to fetch a known document if possible,
// or at least checks for syntax and basic logic.

require_once __DIR__ . '/../api/webhook/firestore_client.php';

try {
    $db = get_firestore();
    $testId = 'ABC123XYZ_TEST';
    $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $testId);
    
    echo "Attempting to create a test document in 'integrations' collection: $docId\n";
    
    $db->collection('integrations')->document($docId)->set([
        'location_id' => $testId,
        'location_name' => 'Demo Subaccount TEST',
        'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTime())
    ]);
    
    echo "Test document created successfully.\n";
    
    echo "Running simulation of api/account.php...\n";
    
    // Simulate what account.php does
    $docRef = $db->collection('integrations')->document($docId);
    $snapshot = $docRef->snapshot();
    
    if ($snapshot->exists()) {
        $data = $snapshot->data();
        echo "SUCCESS: Found location_name: " . $data['location_name'] . "\n";
        
        $jsonResponse = json_encode([
            'status' => 'success',
            'data' => [
                'location_id' => $testId,
                'location_name' => $data['location_name']
            ]
        ], JSON_PRETTY_PRINT);
        
        echo "Projected JSON Response:\n" . $jsonResponse . "\n";
    } else {
        echo "FAILURE: Document not found.\n";
    }
    
    // Cleanup
    echo "Cleaning up test document...\n";
    $db->collection('integrations')->document($docId)->delete();
    echo "Test finished.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
