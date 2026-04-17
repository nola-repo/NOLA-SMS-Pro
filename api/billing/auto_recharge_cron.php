<?php

/**
 * Auto-Recharge Cron Script
 * 
 * Invoked by Cloud Scheduler (or cron) every ~15 minutes.
 * Checks for agency wallets and subaccount wallets that have auto_recharge_enabled=true,
 * and processes a recharge if their balance drops below auto_recharge_threshold.
 * 
 * Usage: php api/billing/auto_recharge_cron.php
 */

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../services/CreditManager.php';

$db = get_firestore();
$creditManager = new CreditManager();
$now = new \DateTime();

echo "[AutoRecharge Cron] Started at " . $now->format('Y-m-d H:i:s') . "\n";

// 1. Process Agency Wallets
$agencyQuery = $db->collection('agency_wallet')->where('auto_recharge_enabled', '==', true)->documents();
foreach ($agencyQuery as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    
    $balance = (int)($data['balance'] ?? 0);
    $threshold = (int)($data['auto_recharge_threshold'] ?? 100);
    $amount = (int)($data['auto_recharge_amount'] ?? 500);
    
    if ($balance < $threshold && $amount > 0) {
        echo "- Triggering recharge for agency {$doc->id()} (balance: {$balance} < {$threshold}). Adding {$amount}... ";
        
        // TODO: Call payment provider checkout/charge logic here in real life
        
        try {
            $creditManager->add_credits(
                $doc->id(), 
                $amount, 
                'auto_recharge_' . uniqid(), 
                'Auto-recharge trigger', 
                'auto_recharge', 
                'agency'
            );
            echo "SUCCESS\n";
        } catch (\Exception $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
        }
    }
}

// 2. Process Subaccount Wallets
$subaccountQuery = $db->collection('integrations')->where('auto_recharge_enabled', '==', true)->documents();
foreach ($subaccountQuery as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    
    $balance = (int)($data['credit_balance'] ?? 0);
    $threshold = (int)($data['auto_recharge_threshold'] ?? 25);
    $amount = (int)($data['auto_recharge_amount'] ?? 250);
    
    if ($balance < $threshold && $amount > 0) {
        echo "- Triggering recharge for subaccount {$doc->id()} (balance: {$balance} < {$threshold}). Adding {$amount}... ";
        
        // TODO: Call payment provider checkout/charge logic here in real life
        
        try {
            // Document ID is already fully formed (e.g., 'ghl_Hsh3jk4k2')
            // Using a raw document update is safest, or we can use $doc->id() directly if format aligns with CreditManager
            $creditManager->add_credits(
                $doc->id(), 
                $amount, 
                'auto_recharge_' . uniqid(), 
                'Auto-recharge trigger', 
                'auto_recharge', 
                'subaccount'
            );
            echo "SUCCESS\n";
        } catch (\Exception $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
        }
    }
}

echo "[AutoRecharge Cron] Finished.\n";
