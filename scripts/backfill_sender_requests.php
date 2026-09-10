<?php
/**
 * backfill_sender_requests.php
 *
 * Backfills sender_id_requests documents for integrations that already have
 * an approved_sender_id but no corresponding request document (e.g. accounts
 * that were set up before the sender-request workflow existed).
 *
 * Usage:
 *   php scripts/backfill_sender_requests.php --dry-run          # preview (default)
 *   php scripts/backfill_sender_requests.php --apply            # write to Firestore + bust cache
 *   php scripts/backfill_sender_requests.php --invalidate-cache # only bust admin list cache
 */

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/cache_helper.php';

// ── Arg parsing (use $argv directly; getopt mishandles hyphenated long options) ─
$isApply           = in_array('--apply',            $argv, true);
$isInvalidateOnly  = in_array('--invalidate-cache', $argv, true);
$dryRun            = !$isApply;

// ── Cache-only mode ────────────────────────────────────────────────────────────
if ($isInvalidateOnly && !$isApply) {
    echo "=== Cache Invalidation Only ===\n";
    try {
        NolaCache::delete('admin_sender_requests_list_100');
        NolaCache::invalidateAdminDashboard();
        echo "Done. admin_sender_requests_list_100 cleared.\n";
    } catch (\Throwable $e) {
        echo "[WARN] Cache invalidation failed: " . $e->getMessage() . "\n";
    }
    exit(0);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return the raw HighLevel location ID from an integrations document.
 * Firestore field `location_id` stores the clean ID (without "ghl_" prefix).
 * Fall back to stripping the well-known "ghl_" prefix from the doc ID.
 */
function backfill_extract_location_id(string $docId, array $data): string
{
    $fromField = trim((string)($data['location_id'] ?? ''));
    if ($fromField !== '') {
        // Defensive: also strip accidental ghl_ stored in the field
        return str_starts_with($fromField, 'ghl_') ? substr($fromField, 4) : $fromField;
    }
    return str_starts_with($docId, 'ghl_') ? substr($docId, 4) : $docId;
}

/**
 * Return true when an approved sender_id_requests doc already exists for this
 * location + sender combination.  Checks requested_id, requested_id_lower,
 * and the legacy sender_id field so no format variant causes a double-write.
 */
function backfill_request_exists($db, string $locationId, string $senderId): bool
{
    $senderLower = strtolower($senderId);

    // canonical field written by this script and admin approval
    $q1 = $db->collection('sender_id_requests')
        ->where('location_id', '=', $locationId)
        ->where('requested_id', '=', $senderId)
        ->limit(1)->documents();
    foreach ($q1 as $d) { if ($d->exists()) return true; }

    // case-insensitive variant
    $q2 = $db->collection('sender_id_requests')
        ->where('location_id', '=', $locationId)
        ->where('requested_id_lower', '=', $senderLower)
        ->limit(1)->documents();
    foreach ($q2 as $d) { if ($d->exists()) return true; }

    // legacy field name used by older docs
    $q3 = $db->collection('sender_id_requests')
        ->where('location_id', '=', $locationId)
        ->where('sender_id', '=', $senderId)
        ->limit(1)->documents();
    foreach ($q3 as $d) { if ($d->exists()) return true; }

    return false;
}

// ── Main ──────────────────────────────────────────────────────────────────────

echo "=== Sender Requests Backfill Script ===\n";
if ($dryRun) {
    echo "[!] DRY RUN MODE: No changes will be saved to Firestore.\n";
    echo "Run with --apply to execute the backfill.\n\n";
}

try {
    $db               = get_firestore();
    $integrationsSnap = $db->collection('integrations')->documents();
    $processedCount   = 0;
    $skippedCount     = 0;
    $errorCount       = 0;

    foreach ($integrationsSnap as $doc) {
        if (!$doc->exists()) continue;

        $data             = $doc->data();
        $docId            = $doc->id();
        $locationId       = backfill_extract_location_id($docId, $data);
        $approvedSenderId = trim((string)($data['approved_sender_id'] ?? ''));

        if ($approvedSenderId === '') continue;

        // Exclude jnkrental (explicitly excluded from migration scripts)
        if (in_array($locationId, ['jnkrental'], true)) {
            $skippedCount++;
            echo "[SKIPPED] {$locationId} (Sender: {$approvedSenderId}) - Excluded account\n";
            continue;
        }

        // Exclude UniSMS accounts — they manage their own sender routing
        $providerPref    = (string)($data['provider_preference'] ?? '');
        $approvedProvider = (string)($data['approved_provider'] ?? '');
        if (stripos($providerPref, 'unisms') !== false || strtolower($approvedProvider) === 'unisms') {
            $skippedCount++;
            echo "[SKIPPED] {$locationId} (Sender: {$approvedSenderId}) - Uses UniSMS\n";
            continue;
        }

        try {
            if (backfill_request_exists($db, $locationId, $approvedSenderId)) {
                $skippedCount++;
                echo "[SKIPPED] {$locationId} (Sender: {$approvedSenderId}) - Request doc already exists\n";
                continue;
            }
        } catch (\Throwable $e) {
            $errorCount++;
            echo "[ERROR]   {$locationId} (Sender: {$approvedSenderId}) - Existence check failed: " . $e->getMessage() . "\n";
            continue;
        }

        $processedCount++;
        echo "[BACKFILL] {$locationId} (Sender: {$approvedSenderId})\n";

        if (!$dryRun) {
            try {
                // Doc ID format: {locationId}_{md5(lower(senderId))}
                $requestDocId = $locationId . '_' . md5(strtolower($approvedSenderId));
                $db->collection('sender_id_requests')->document($requestDocId)->set([
                    'location_id'        => $locationId,
                    'requested_id'       => $approvedSenderId,
                    'requested_id_lower' => strtolower($approvedSenderId),
                    'sender_id'          => $approvedSenderId,
                    'status'             => 'approved',
                    'provider'           => 'semaphore',
                    'created_at'         => \Google\Cloud\Firestore\FieldValue::serverTimestamp(),
                    'updated_at'         => \Google\Cloud\Firestore\FieldValue::serverTimestamp(),
                    'approved_at'        => \Google\Cloud\Firestore\FieldValue::serverTimestamp(),
                    'notes'              => 'Backfilled from existing integrations record during API key migration',
                ]);
            } catch (\Throwable $e) {
                $errorCount++;
                echo "[ERROR]   {$locationId} (Sender: {$approvedSenderId}) - Write failed: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n=== Backfill Summary ===\n";
    echo "Processed : {$processedCount}\n";
    echo "Skipped   : {$skippedCount}\n";
    echo "Errors    : {$errorCount}\n";

    if ($dryRun) {
        echo "Status    : DRY RUN COMPLETE\n";
    } else {
        echo "Status    : APPLY COMPLETE\n";
        // Bust admin list cache so new docs appear immediately
        try {
            NolaCache::delete('admin_sender_requests_list_100');
            NolaCache::invalidateAdminDashboard();
            echo "Cache     : admin_sender_requests_list_100 cleared\n";
        } catch (\Throwable $e) {
            echo "[WARN] Cache invalidation failed (non-fatal): " . $e->getMessage() . "\n";
        }
    }

} catch (\Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
