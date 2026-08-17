<?php

require __DIR__ . '/services/CreditManager.php';

$cm = new CreditManager();
$balance = $cm->get_balance('default');

echo "Current Balance: " . $balance . "\n";

echo "Adding 100 credits...\n";
$newBal = $cm->add_credits('default', 100, 'test_add', 'Test Top-Up');
echo "New Balance: " . $newBal . "\n";

echo "Deducting 10 credits...\n";
$newBal = $cm->deduct_credits('default', 10, 'test_deduct', 'Test Deduction');
echo "New Balance: " . $newBal . "\n";
