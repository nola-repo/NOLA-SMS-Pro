<?php

/**
 * One-off / cron-safe migration: copy legacy ghl_tokens rows into canonical IDs.
 *
 * When tokens were stored under a wrong Firestore document ID but `location_id`
 * matches the real GHL location, merge that payload into ghl_tokens/{location_id}.
 *
 * Usage (CLI on a host with Firestore credentials):
 *   php tmp/backfill_ghl_tokens_canonical.php
 *
 * Dry-run (no writes):
 *   php tmp/backfill_ghl_tokens_canonical.php --dry-run
 */

require_once __DIR__ . '/../api/webhook/firestore_client.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$db = get_firestore();
$merged = 0;
$skipped = 0;

foreach ($db->collection('ghl_tokens')->documents() as $doc) {
    if (!$doc->exists()) {
        continue;
    }
    $data = $doc->data();
    $legacyId = $doc->id();
    $locId = $data['location_id'] ?? $data['locationId'] ?? null;
    $locId = $locId !== null ? (string) $locId : '';

    if ($locId === '' || $locId === $legacyId) {
        $skipped++;
        continue;
    }

    $isAgency = ($data['appType'] ?? '') === 'agency' || ($data['userType'] ?? '') === 'Company';
    if ($isAgency && $legacyId === ($data['companyId'] ?? '')) {
        $skipped++;
        continue;
    }

    $payload = $data;
    echo "Merge legacy doc {$legacyId} -> canonical {$locId}\n";

    if (!$dryRun) {
        $db->collection('ghl_tokens')->document($locId)->set($payload, ['merge' => true]);
        error_log("[GHL_TOKEN_MIGRATION] backfill canonical_id={$locId} from_legacy_doc={$legacyId}");
    }
    $merged++;
}

echo $dryRun ? "Dry-run complete. Would merge: {$merged}, skipped: {$skipped}\n" : "Done. Merged: {$merged}, skipped: {$skipped}\n";
