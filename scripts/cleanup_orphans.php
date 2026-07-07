<?php

/** Delete safe local-only orphan records from a signed cleanup manifest. */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/cache_helper.php';
require_once __DIR__ . '/../api/services/CleanupSafety.php';

use Google\Cloud\Core\Timestamp;

function co_arg(string $name, string $default = ''): string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string)$arg, $prefix)) {
            return substr((string)$arg, strlen($prefix));
        }
    }
    return $default;
}

function co_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function co_now(): Timestamp
{
    return new Timestamp(new DateTimeImmutable());
}

function co_live_pending(array $data): bool
{
    $status = strtolower(trim((string)($data['status'] ?? $data['install_state'] ?? '')));
    return in_array($status, ['pending', 'queued', 'sending', 'processing', 'provisioning', 'pending_payment_provider'], true);
}

function co_live_balance(array $data): bool
{
    foreach (['balance', 'credit_balance', 'credits', 'current_balance', 'wallet_balance'] as $field) {
        if (isset($data[$field]) && is_numeric($data[$field]) && (float)$data[$field] != 0.0) {
            return true;
        }
    }
    return false;
}

$manifestPath = co_arg('manifest');
$digest = co_arg('digest');
$execute = co_flag('execute');
$errors = [];
if (!is_file($manifestPath)) {
    $errors[] = 'Readable manifest required.';
}
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
if (!is_array($manifest)) {
    $manifest = [];
    $errors[] = 'Valid manifest JSON required.';
} else {
    $errors = array_merge($errors, CleanupSafety::validateManifest($manifest, $digest, 3600));
}

$orphanIds = [];
foreach (CleanupSafety::candidateMap($manifest) as $locationId => $candidate) {
    if (CleanupSafety::isProtectedLocation($locationId)) {
        continue;
    }
    if (CleanupSafety::candidateBlockers($candidate) !== []) {
        continue;
    }
    if (($candidate['remote_uninstall']['token_appears_usable'] ?? false) === true) {
        continue;
    }
    $hasDeletion = false;
    foreach (($manifest['unique_document_decisions'] ?? []) as $decision) {
        if (($decision['final_action'] ?? '') === 'would_delete'
            && in_array((string)($decision['collection'] ?? ''), CleanupSafety::DELETABLE_COLLECTIONS, true)
            && in_array($locationId, $decision['candidate_ids'] ?? [], true)) {
            $hasDeletion = true;
            break;
        }
    }
    if ($hasDeletion) {
        $orphanIds[] = $locationId;
    }
}
$orphanIds = array_values(array_unique($orphanIds));
$decisions = CleanupSafety::approvedDeletionDecisions($manifest, $orphanIds);
if ($orphanIds === [] || $decisions === []) {
    $errors[] = 'No safe orphan cleanup work remains.';
}

if ($execute) {
    if (co_arg('confirm') !== 'DELETE-NOLA-ORPHANS') {
        $errors[] = 'Orphan confirmation phrase missing.';
    }
    if (strtolower(co_arg('backup-confirmed')) !== 'yes') {
        $errors[] = 'Backup confirmation missing.';
    }
    if (trim(co_arg('operator')) === '' || trim(co_arg('reason')) === '') {
        $errors[] = 'Operator and reason required.';
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Orphan cleanup refused:\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(2);
}

if (!$execute) {
    echo json_encode([
        'mode' => 'dry_run',
        'orphan_candidate_count' => count($orphanIds),
        'deletion_document_count' => count($decisions),
        'protected_locations' => array_keys(CleanupSafety::PROTECTED_LOCATIONS),
        'financial_and_shared_history' => 'preserved',
        'native_ghl_conversations' => 'preserved',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$db = get_firestore();

// Runtime revalidation of every high-risk record immediately before deletion.
foreach ($decisions as $decision) {
    $collection = (string)($decision['collection'] ?? '');
    if (!in_array($collection, ['accounts', 'integrations', 'messages', 'sender_id_requests'], true)) {
        continue;
    }
    $path = (string)($decision['path'] ?? '');
    $snap = $path !== '' ? $db->document($path)->snapshot() : null;
    if (!$snap || !$snap->exists()) {
        continue;
    }
    $data = $snap->data();
    if ((in_array($collection, ['accounts', 'integrations'], true) && co_live_balance($data))
        || co_live_pending($data)) {
        fwrite(STDERR, "Live balance/pending blocker discovered at {$path}.\n");
        exit(3);
    }
}

$runId = gmdate('Ymd_His') . '_orphans_' . bin2hex(random_bytes(4));
$runRef = $db->collection('cleanup_runs')->document($runId);
$runRef->set([
    'run_id' => $runId,
    'state' => 'ORPHAN_CLEANUP_APPROVED',
    'operator' => co_arg('operator'),
    'reason' => co_arg('reason'),
    'manifest_sha256' => CleanupSafety::manifestDigest($manifest),
    'orphan_location_ids' => $orphanIds,
    'protected_location_ids' => array_keys(CleanupSafety::PROTECTED_LOCATIONS),
    'native_ghl_conversations' => 'preserved',
    'created_at' => co_now(),
    'updated_at' => co_now(),
]);

$deleted = [];
$failed = [];
foreach (array_chunk($decisions, 200) as $chunk) {
    $batch = $db->batch();
    $paths = [];
    foreach ($chunk as $decision) {
        $path = trim((string)($decision['path'] ?? ''));
        if ($path === '' || str_starts_with($path, 'cleanup_runs/')) {
            continue;
        }
        $batch->delete($db->document($path));
        $paths[] = $path;
    }
    try {
        $batch->commit();
        $deleted = array_merge($deleted, $paths);
    } catch (Throwable $e) {
        $failed = array_merge($failed, $paths);
        break;
    }
}

$verificationFailed = [];
foreach ($deleted as $path) {
    if ($db->document($path)->snapshot()->exists()) {
        $verificationFailed[] = $path;
    }
}

NolaCache::invalidateAdminDashboard();
$success = $failed === [] && $verificationFailed === [];
$runRef->set([
    'state' => $success ? 'ORPHAN_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deleted),
    'failed_paths' => $failed,
    'verification_failed_paths' => $verificationFailed,
    'updated_at' => co_now(),
], ['merge' => true]);

echo json_encode([
    'run_id' => $runId,
    'state' => $success ? 'ORPHAN_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'orphan_candidate_count' => count($orphanIds),
    'deleted_document_count' => count($deleted),
    'failed_document_count' => count($failed),
    'verification_failed_document_count' => count($verificationFailed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($success ? 0 : 4);
