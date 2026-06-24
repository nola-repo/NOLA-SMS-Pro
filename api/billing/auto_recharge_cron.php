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

function auto_recharge_can_credit_without_payment(): bool
{
    return (string)getenv('AUTO_RECHARGE_ALLOW_UNCONFIRMED_CREDITS') === '1';
}

function auto_recharge_record_pending($db, string $scope, string $accountId, int $balance, int $threshold, int $amount): void
{
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', "{$scope}_{$accountId}");
    $ref = $db->collection('auto_recharge_attempts')->document($safeId);
    $ts = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());

    $ref->set([
        'scope' => $scope,
        'account_id' => $accountId,
        'status' => 'pending_payment_provider',
        'balance_at_trigger' => $balance,
        'threshold' => $threshold,
        'amount' => $amount,
        'payment_required' => true,
        'reason' => 'Auto-recharge threshold reached, but no payment-provider charge has been confirmed.',
        'updated_at' => $ts,
        'last_checked_at' => $ts,
    ], ['merge' => true]);
}

// 1. Process Agency Wallets
$agencyQuery = $db->collection('agency_wallet')->where('auto_recharge_enabled', '==', true)->documents();
foreach ($agencyQuery as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    
    $balance = $creditManager->get_agency_balance($doc->id());
    $threshold = (int)($data['auto_recharge_threshold'] ?? 100);
    $amount = (int)($data['auto_recharge_amount'] ?? 500);
    
    if ($balance < $threshold && $amount > 0) {
        echo "- Triggering recharge for agency {$doc->id()} (balance: {$balance} < {$threshold}). Adding {$amount}... ";

        if (!auto_recharge_can_credit_without_payment()) {
            auto_recharge_record_pending($db, 'agency', $doc->id(), $balance, $threshold, $amount);
            echo "PENDING_PAYMENT_PROVIDER (credits not added)\n";
            continue;
        }
        
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

        if (!auto_recharge_can_credit_without_payment()) {
            auto_recharge_record_pending($db, 'subaccount', $doc->id(), $balance, $threshold, $amount);
            echo "PENDING_PAYMENT_PROVIDER (credits not added)\n";
            continue;
        }
        
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

            // Strip 'ghl_' prefix to get pure location_id
            $locationId = str_replace('ghl_', '', $doc->id());
            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                NotificationService::notifyTopUpSuccess($db, $locationId, $amount, $balance + $amount);
            } catch (\Throwable $e) {
                error_log("[auto_recharge_cron.php] Failed to send top up success notification: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
        }
    }
}

echo "[AutoRecharge Cron] Finished.\n";
