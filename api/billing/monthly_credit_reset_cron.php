<?php
/**
 * Monthly Credit Reset Cron Script
 *
 * Invoked on the 1st of every month at 00:00 (via Cloud Scheduler or Cron).
 * Automatically resets credit balances of all active subaccounts to the configured
 * monthly allocation amount (e.g., 500 credits). Unused credits do NOT carry over.
 *
 * Security: Requires CRON_SECRET env var. Pass as:
 *   - Header: X-Cron-Secret: <secret>   (Cloud Scheduler)
 *   - Query:  ?cron_secret=<secret>     (manual/CLI testing via HTTP)
 *
 * Usage:
 *   php api/billing/monthly_credit_reset_cron.php        (CLI — bypasses HTTP auth)
 *   POST /api/billing/monthly_credit_reset_cron          (Cloud Scheduler + X-Cron-Secret)
 *   GET  ...?cron_secret=xxx&force=1                     (admin manual force run)
 */

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../services/ReferenceId.php';
require_once __DIR__ . '/../cache_helper.php';

use Google\Cloud\Core\Timestamp;

header('Content-Type: application/json');

// ─── Risk 1 Fix: Security Guard ──────────────────────────────────────────────
// All HTTP requests must supply CRON_SECRET. CLI (php ...) bypasses this check.
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $cronSecret     = getenv('CRON_SECRET');
    $providedSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['cron_secret'] ?? null;

    if (empty($cronSecret) || $providedSecret !== $cronSecret) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: invalid or missing cron secret.']);
        exit;
    }
}

$db  = get_firestore();
$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
$ts  = new Timestamp($now);

// Check system configuration
$configRef  = $db->collection('admin_config')->document('monthly_credit_reset');
$configSnap = $configRef->snapshot();
$configData = $configSnap->exists() ? $configSnap->data() : [];

$enabled           = (bool)($configData['enabled'] ?? false);
$monthlyAllocation = max(0, (int)($configData['monthly_allocation'] ?? 500));
$isForce           = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');

if (!$enabled && !$isForce) {
    echo json_encode([
        'status'    => 'skipped',
        'message'   => 'Monthly credit reset is disabled in system settings.',
        'timestamp' => $now->format('Y-m-d H:i:s')
    ]);
    exit;
}

// ─── Risk 2 Fix: Same-Month Idempotency Check ─────────────────────────────────
// Prevents double-reset if Cloud Scheduler retries or admin force-triggers in same month.
if (!$isForce && isset($configData['last_reset_at']) && $configData['last_reset_at'] instanceof Timestamp) {
    $lastResetMonth = $configData['last_reset_at']->get()->format('Y-m');
    $currentMonth   = $now->format('Y-m');
    if ($lastResetMonth === $currentMonth) {
        echo json_encode([
            'status'    => 'skipped',
            'message'   => "Monthly reset already ran for {$currentMonth}. Pass ?force=1 with cron_secret to override.",
            'timestamp' => $now->format('Y-m-d H:i:s')
        ]);
        exit;
    }
}

$usersSnap = $db->collection('users')->documents();
$resetCount = 0;
$skippedCount = 0;
$resetUsers = [];

foreach ($usersSnap as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();

    // Skip inactive users or accounts with monthly reset disabled
    if (array_key_exists('active', $d) && $d['active'] === false) {
        $skippedCount++;
        continue;
    }
    if (array_key_exists('monthly_reset_enabled', $d) && $d['monthly_reset_enabled'] === false) {
        $skippedCount++;
        continue;
    }

    $locId = $d['active_location_id'] ?? $d['location_id'] ?? '';
    if (empty($locId)) {
        $skippedCount++;
        continue;
    }

    $currentBalance = (int)($d['credit_balance'] ?? 0);
    $diffAmount     = $monthlyAllocation - $currentBalance;
    $intDocId       = CreditManager::integration_doc_id_for_location((string)$locId);

    // ── Risk 3 Fix: Firestore Transaction per user ────────────────────────────
    // Atomically reads + writes each user's balance to prevent race conditions
    // with simultaneous SMS deductions or top-up purchases.
    try {
        $db->runTransaction(function (\Google\Cloud\Firestore\Transaction $txn) use (
            $db, $doc, $intDocId, $monthlyAllocation, $ts
        ) {
            // Re-read inside transaction for consistency
            $freshSnap = $txn->snapshot($doc->reference());

            $txn->update($doc->reference(), [
                ['path' => 'credit_balance',        'value' => $monthlyAllocation],
                ['path' => 'last_monthly_reset_at', 'value' => $ts],
                ['path' => 'updated_at',            'value' => $ts],
            ]);

            $intRef  = $db->collection('integrations')->document($intDocId);
            $intSnap = $txn->snapshot($intRef);
            if ($intSnap->exists()) {
                $txn->update($intRef, [
                    ['path' => 'credit_balance', 'value' => $monthlyAllocation],
                    ['path' => 'updated_at',     'value' => $ts],
                ]);
            }
        });
    } catch (\Throwable $e) {
        error_log("[monthly_credit_reset_cron] Transaction failed for user {$doc->id()}: " . $e->getMessage());
        $skippedCount++;
        continue;
    }

    // Log transaction (outside transaction since newDocument() uses auto-IDs)
    try {
        $txRef = $db->collection('credit_transactions')->newDocument();
        $txRef->set([
            'transaction_id'           => $txRef->id(),
            'transaction_reference_id' => ReferenceId::generate('TXN'),
            'account_id'               => $intDocId,
            'wallet_scope'             => 'subaccount',
            'type'                     => 'monthly_reset',
            'amount'                   => $diffAmount,
            'balance_before'           => $currentBalance,
            'balance_after'            => $monthlyAllocation,
            'reference_id'             => 'monthly_reset_' . $now->format('Y_m'),
            'description'              => "Monthly credit reset allocation ($monthlyAllocation credits)",
            'created_at'               => $ts
        ]);
    } catch (\Throwable $e) {
        // Non-fatal: balance is already updated; log warning and continue
        error_log("[monthly_credit_reset_cron] Failed to write tx log for {$doc->id()}: " . $e->getMessage());
    }

    $resetCount++;
    $resetUsers[] = [
        'user_id'          => $doc->id(),
        'location_id'      => $locId,
        'previous_balance' => $currentBalance,
        'new_balance'      => $monthlyAllocation
    ];

    // Invalidate subaccount credit cache
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
