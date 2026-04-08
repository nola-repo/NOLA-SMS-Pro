<?php
// tmp/test_credits_logic_mock.php

// Mocking Firestore classes to test logic without live connection
class MockSnapshot {
    public $data;
    public function __construct($data) { $this->data = $data; }
    public function data() { return $this->data; }
    public function exists() { return $this->data !== null; }
}

class MockDoc {
    public $data;
    public function __construct($data) { $this->data = $data; }
    public function snapshot() { return new MockSnapshot($this->data); }
    public function set($newData, $options = []) {
        if (!isset($options['merge']) || !$options['merge']) {
            $this->data = $newData;
        } else {
            foreach($newData as $k => $v) {
                if ($v instanceof MockIncrement) {
                    $this->data[$k] = ($this->data[$k] ?? 0) + $v->amount;
                } else {
                    $this->data[$k] = $v;
                }
            }
        }
        echo "[MOCK] Firestore set: " . json_encode($newData) . "\n";
    }
}

class MockIncrement {
    public $amount;
    public function __construct($amount) { $this->amount = $amount; }
}

class MockCollection {
    public function document($id) { return new MockDoc([]); }
}

// Minimal CreditManager for testing logic in send_sms.php
class MockCreditManager {
    public static function calculateRequiredCredits($message, $num_recipients) {
        return mb_strlen($message) > 160 ? 2 * $num_recipients : 1 * $num_recipients;
    }
    public function deduct_credits($account_id, $amount, $ref, $desc) {
        echo "[MOCK] Deducting $amount credits from $account_id\n";
        return true;
    }
}

function verify_logic($name, $freeUsageCount, $freeCreditsTotal, $numRecipients, $message) {
    echo "--- Testing: $name ---\n";
    
    // Logic from send_sms.php
    $required_credits = MockCreditManager::calculateRequiredCredits($message, $numRecipients);
    $usingFreeCredits = ($freeUsageCount + $numRecipients <= $freeCreditsTotal);
    
    if ($usingFreeCredits) {
        echo "RESULT: Charging via Trial Quota (free_usage_count += $numRecipients)\n";
    } else {
        echo "RESULT: Charging via Paid Balance (deduct $required_credits)\n";
    }
    echo "--------------------\n\n";
}

// Scenario 1: Trial Send (within quota)
verify_logic("Trial Within Quota", 5, 10, 2, "Hello world");

// Scenario 2: Paid Send (quota exhausted)
verify_logic("Paid Quota Exhausted", 10, 10, 1, "Hello world");

// Scenario 3: Mixed (Trial just barely fits)
verify_logic("Trial Fits Exactly", 8, 10, 2, "Hello world");

// Scenario 4: Mixed (Trial just barely misses)
verify_logic("Trial Over Limit", 9, 10, 2, "Hello world");
