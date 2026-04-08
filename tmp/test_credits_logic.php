<?php
// tmp/test_credits_logic.php

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/CreditManager.php';

use Google\Cloud\Core\Timestamp;

function test_scenario($name, $locationId, $initialData, $numRecipients, $message) {
    echo "--- Testing Scenario: $name ---\n";
    $db = get_firestore();
    $intDocId = 'ghl_' . $locationId;
    $intRef = $db->collection('integrations')->document($intDocId);
    
    // Setup initial state
    $intRef->set($initialData);
    
    // Mock the logic from send_sms.php
    $intData = $initialData;
    $freeUsageCount = $intData['free_usage_count'] ?? 0;
    $freeCreditsTotal = $intData['free_credits_total'] ?? 10;
    
    $required_credits = CreditManager::calculateRequiredCredits($message, $numRecipients);
    $usingFreeCredits = ($freeUsageCount + $numRecipients <= $freeCreditsTotal);
    
    $creditManager = new CreditManager();
    $account_id = $locationId;
    
    $result = "N/A";
    $error = null;

    try {
        if ($usingFreeCredits) {
            echo "Charging via Trial Quota...\n";
            $intRef->set([
                'free_usage_count' => $freeUsageCount + $numRecipients,
                'updated_at' => new Timestamp(new \DateTime()),
            ], ['merge' => true]);
            $result = "TRIAL_USED";
        } else {
            echo "Charging via Paid Balance...\n";
            $creditManager->deduct_credits(
                $account_id,
                $required_credits,
                'test_' . bin2hex(random_bytes(4)),
                "Test deduction"
            );
            $result = "PAID_DEDUCTED";
        }
    } catch (\Exception $e) {
        $result = "ERROR";
        $error = $e->getMessage();
    }
    
    // Verify results
    $finalSnap = $intRef->snapshot();
    $finalData = $finalSnap->data();
    
    echo "Result: $result\n";
    if ($error) echo "Error: $error\n";
    echo "New free_usage_count: " . ($finalData['free_usage_count'] ?? 0) . "\n";
    echo "New credit_balance: " . ($finalData['credit_balance'] ?? 0) . "\n\n";
}

$testLoc = 'test_loc_' . time();

// Scenario 1: Trial Send (within quota)
test_scenario("Trial Within Quota", $testLoc, [
    'free_usage_count' => 5,
    'free_credits_total' => 10,
    'credit_balance' => 100
], 2, "Hello world");

// Scenario 2: Paid Send (quota exhausted)
test_scenario("Paid Quota Exhausted", $testLoc, [
    'free_usage_count' => 10,
    'free_credits_total' => 10,
    'credit_balance' => 100
], 1, "Hello world");

// Scenario 3: Insufficient Paid Credits
test_scenario("Insufficient Credits", $testLoc, [
    'free_usage_count' => 10,
    'free_credits_total' => 10,
    'credit_balance' => 0
], 1, "Hello world");

// Cleanup (optional but good)
// $db->collection('integrations')->document('ghl_' . $testLoc)->delete();
