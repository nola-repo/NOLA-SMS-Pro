<?php

/**
 * One-time migration: copy subaccount credit_balance from integrations → users,
 * and agency balance from agency_wallet → agency_users.
 *
 * Usage (from project root, with GOOGLE_APPLICATION_CREDENTIALS / Firestore env set):
 *   php tmp/migrate_wallets.php
 *   php tmp/migrate_wallets.php --dry-run
 */

declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../api/webhook/firestore_client.php';

$db = get_firestore();

echo $dryRun ? "[DRY RUN] " : '';
echo "Starting Subaccount Wallet Migration (integrations → users.credit_balance)...\n";

$integrations = $db->collection('integrations')->documents();
$migratedSub = 0;
$skippedSub = 0;

foreach ($integrations as $doc) {
    if (!$doc->exists()) {
        continue;
    }
    $data = $doc->data();
    if (!array_key_exists('credit_balance', $data)) {
        continue;
    }

    $balance = (int)($data['credit_balance'] ?? 0);
    $locationId = isset($data['location_id']) && is_string($data['location_id']) && $data['location_id'] !== ''
        ? $data['location_id']
        : (string) preg_replace('/^ghl_/', '', $doc->id());

    if ($locationId === '' || $locationId === 'ghl') {
        echo "  skip integration {$doc->id()}: invalid location id\n";
        $skippedSub++;
        continue;
    }

    $userQuery = $db->collection('users')->where('active_location_id', '=', $locationId)->limit(1)->documents();
    $matched = false;
    foreach ($userQuery as $userDoc) {
        if (!$userDoc->exists()) {
            continue;
        }
        $matched = true;
        if ($dryRun) {
            echo "  [dry-run] would set users/{$userDoc->id()} credit_balance={$balance} (from integration {$doc->id()})\n";
        } else {
            $userDoc->reference()->set([
                'credit_balance' => $balance,
            ], ['merge' => true]);
            echo "  migrated balance {$balance} for location {$locationId} → users/{$userDoc->id()}\n";
        }
        $migratedSub++;
        break;
    }
    if (!$matched) {
        echo "  no users row for active_location_id={$locationId} (integration {$doc->id()})\n";
        $skippedSub++;
    }
}

echo $dryRun ? "[DRY RUN] " : '';
echo "Starting Agency Wallet Migration (agency_wallet → agency_users.balance)...\n";

$agencyWallets = $db->collection('agency_wallet')->documents();
$migratedAgency = 0;
$skippedAgency = 0;

foreach ($agencyWallets as $doc) {
    if (!$doc->exists()) {
        continue;
    }
    $data = $doc->data();
    if (!array_key_exists('balance', $data)) {
        continue;
    }

    $balance = (int)($data['balance'] ?? 0);
    $companyId = $doc->id();

    $agencyQuery = $db->collection('agency_users')->where('company_id', '=', $companyId)->limit(1)->documents();
    $matched = false;
    foreach ($agencyQuery as $agencyUserDoc) {
        if (!$agencyUserDoc->exists()) {
            continue;
        }
        $matched = true;
        if ($dryRun) {
            echo "  [dry-run] would set agency_users/{$agencyUserDoc->id()} balance={$balance} (company {$companyId})\n";
        } else {
            $agencyUserDoc->reference()->set([
                'balance' => $balance,
            ], ['merge' => true]);
            echo "  migrated agency balance {$balance} for company {$companyId} → agency_users/{$agencyUserDoc->id()}\n";
        }
        $migratedAgency++;
        break;
    }
    if (!$matched) {
        echo "  no agency_users row for company_id={$companyId}\n";
        $skippedAgency++;
    }
}

echo "\nDone. subaccount writes: {$migratedSub}, subaccount skipped: {$skippedSub}; agency writes: {$migratedAgency}, agency skipped: {$skippedAgency}\n";
