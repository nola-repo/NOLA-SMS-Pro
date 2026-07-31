<?php
/**
 * Monthly Credit Reset Cron Script
 * 
 * Invoked on the 1st of every month at 00:00 (via Cloud Scheduler or Cron).
 * Automatically resets credit balances of all active subaccounts to the configured
 * monthly allocation amount (e.g., 500 credits). Unused credits do NOT carry over.
 * 
 * Usage:
 *   php api/billing/monthly_credit_reset_cron.php
 *   GET /api/billing/monthly_credit_reset_cron?force=1 (from Admin)
 */

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../services/ReferenceId.php';
require_once __DIR__ . '/../cache_helper.php';

use Google\Cloud\Core\Timestamp;

header('Content-Type: application/json');

$db = get_firestore();
$now = new \DateTimeImmutable();
$ts = new Timestamp($now);

// Check system configuration
$configRef = $db->collection('admin_config')->document('monthly_credit_reset');
$configSnap = $configRef->snapshot();
$configData = $configSnap->exists() ? $configSnap->data() : [];

$enabled = (bool)($configData['enabled'] ?? false);
$monthlyAllocation = max(0, (int)($configData['monthly_allocation'] ?? 500));
$isForce = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');

if (!$enabled && !$isForce) {
    echo json_encode([
        'status' => 'skipped',
        'message' => 'Monthly credit reset is disabled in system settings.',
        'timestamp' => $now->format('Y-m-d H:i:s')
    ]);
    exit;
}

$usersSnap = $db->collection('users')->documents();
$resetCount = 0;
$skippedCount = 0;
$resetUsers = [];

foreach ($usersSnap as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();

    // Skip inactive users
    if (array_key_exists('active', $d) && $d['active'] === false) {
        $skippedCount++;
        continue;
    }

    $locId = $d['active_location_id'] ?? $d['location_id'] ?? '';
    if (empty($locId)) {
        $skippedCount++;
        continue;
    }

    $currentBalance = (int)($d['credit_balance'] ?? 0);
    $diffAmount = $monthlyAllocation - $currentBalance;

    // Update user document
    $doc->reference()->set([
        'credit_balance' => $monthlyAllocation,
        'last_monthly_reset_at' => $ts,
        'updated_at' => $ts
    ], ['merge' => true]);

    // Also sync integrations doc if it exists
    $intDocId = CreditManager::integration_doc_id_for_location((string)$locId);
    $intRef = $db->collection('integrations')->document($intDocId);
    $intSnap = $intRef->snapshot();
    if ($intSnap->exists()) {
        $intRef->set([
            'credit_balance' => $monthlyAllocation,
            'updated_at' => $ts
        ], ['merge' => true]);
    }

    // Log transaction
    $txRef = $db->collection('credit_transactions')->newDocument();
    $txRef->set([
        'transaction_id' => $txRef->id(),
        'transaction_reference_id' => ReferenceId::generate('TXN'),
        'account_id' => $intDocId,
        'wallet_scope' => 'subaccount',
        'type' => 'monthly_reset',
        'amount' => $diffAmount,
        'balance_after' => $monthlyAllocation,
        'reference_id' => 'monthly_reset_' . $now->format('Y_m'),
        'description' => "Monthly credit reset allocation ($monthlyAllocation credits)",
        'created_at' => $ts
    ]);

    $resetCount++;
    $resetUsers[] = [
        'user_id' => $doc->id(),
        'location_id' => $locId,
        'previous_balance' => $currentBalance,
        'new_balance' => $monthlyAllocation
    ];

    // Invalidate subaccount cache
    NolaCache::deleteRegistry("credits_registry_" . $locId);
    NolaCache::delete("credits_data_" . $locId);
}

// Update config status
$configRef->set([
    'last_reset_at' => $ts,
    'last_reset_count' => $resetCount,
    'updated_at' => $ts
], ['merge' => true]);

NolaCache::invalidateAdminDashboard();

echo json_encode([
    'status' => 'success',
    'monthly_allocation' => $monthlyAllocation,
    'reset_count' => $resetCount,
    'skipped_count' => $skippedCount,
    'timestamp' => $now->format('Y-m-d H:i:s'),
    'details' => $resetUsers
], JSON_PRETTY_PRINT);
