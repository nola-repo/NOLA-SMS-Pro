<?php

/**
 * Explicit fallback for cleanup locations whose GHL uninstall is impossible.
 * Requires a prior audited reconnect_required result and an active SMS lock.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/cache_helper.php';
require_once __DIR__ . '/../api/services/CleanupSafety.php';

use Google\Cloud\Core\Timestamp;

function clo_arg(string $name, string $default = ''): string
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

function clo_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function clo_now(): Timestamp
{
    return new Timestamp(new DateTimeImmutable());
}

$manifestPath = clo_arg('manifest');
$digest = clo_arg('digest');
$priorRunId = clo_arg('prior-run');
$locationIds = array_values(array_unique(array_filter(array_map('trim', explode(',', clo_arg('locations'))))));
$execute = clo_flag('execute');
$errors = [];

if (!is_file($manifestPath)) {
    $errors[] = 'Readable manifest required.';
}
$manifest = is_file($manifestPath)
    ? json_decode((string)file_get_contents($manifestPath), true)
    : null;
if (!is_array($manifest)) {
    $errors[] = 'Valid manifest JSON required.';
    $manifest = [];
} else {
    $errors = array_merge($errors, CleanupSafety::validateManifest($manifest, $digest, 3600));
}
if ($locationIds === [] || count($locationIds) > 5) {
    $errors[] = 'One to five explicit locations required.';
}
if ($priorRunId === '') {
    $errors[] = 'Prior audited cleanup run required.';
}

$candidateMap = CleanupSafety::candidateMap($manifest);
foreach ($locationIds as $locationId) {
    if (CleanupSafety::isProtectedLocation($locationId) || !isset($candidateMap[$locationId])) {
        $errors[] = 'Protected or unknown location refused: ' . $locationId;
        continue;
    }
    $blockers = CleanupSafety::candidateBlockers($candidateMap[$locationId]);
    if ($blockers !== []) {
        $errors[] = $locationId . ' blocked: ' . implode(', ', $blockers);
    }
}

$decisions = CleanupSafety::approvedDeletionDecisions($manifest, $locationIds);
if ($decisions === []) {
    $errors[] = 'No candidate-only deletion decisions found.';
}
if ($execute) {
    if (clo_arg('confirm') !== 'DELETE-NOLA-LOCAL-ONLY') {
        $errors[] = 'Local-only confirmation phrase missing.';
    }
    if (strtolower(clo_arg('backup-confirmed')) !== 'yes') {
        $errors[] = 'Backup confirmation missing.';
    }
    if (trim(clo_arg('operator')) === '' || trim(clo_arg('reason')) === '') {
        $errors[] = 'Operator and reason are required.';
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Local-only cleanup refused:\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(2);
}

if (!$execute) {
    echo json_encode([
        'mode' => 'dry_run',
        'locations' => $locationIds,
        'deletion_document_count' => count($decisions),
        'ghl_app_status' => 'may_remain_visible_but_unusable',
        'native_ghl_conversations' => 'preserved',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$db = get_firestore();

// Runtime proof: each location must have failed uninstall authorization in the
// named prior run and must still be locked against SMS.
foreach ($locationIds as $locationId) {
    $priorSnap = $db->collection('cleanup_runs')->document($priorRunId)
        ->collection('locations')->document($locationId)->snapshot();
    $prior = $priorSnap->exists() ? $priorSnap->data() : [];
    $classification = (string)($prior['remote_result']['classification'] ?? '');
    if (($prior['state'] ?? '') !== 'REMOTE_UNINSTALL_FAILED'
        || !in_array($classification, ['reconnect_required', 'unauthorized'], true)) {
        fwrite(STDERR, "Prior remote authorization failure not proven for {$locationId}.\n");
        exit(3);
    }
    $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
    $token = $tokenSnap->exists() ? $tokenSnap->data() : [];
    if (($token['cleanup_in_progress'] ?? false) !== true || ($token['toggle_enabled'] ?? true) !== false) {
        fwrite(STDERR, "SMS cleanup lock not proven for {$locationId}.\n");
        exit(3);
    }
}

$runId = gmdate('Ymd_His') . '_local_' . bin2hex(random_bytes(4));
$runRef = $db->collection('cleanup_runs')->document($runId);
$runRef->set([
    'run_id' => $runId,
    'state' => 'LOCAL_ONLY_APPROVED',
    'prior_run_id' => $priorRunId,
    'operator' => clo_arg('operator'),
    'reason' => clo_arg('reason'),
    'manifest_sha256' => CleanupSafety::manifestDigest($manifest),
    'approved_location_ids' => $locationIds,
    'remote_app_may_remain_visible' => true,
    'native_ghl_conversations' => 'preserved',
    'created_at' => clo_now(),
    'updated_at' => clo_now(),
]);

$deletedPaths = [];
$failedPaths = [];
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
        $deletedPaths = array_merge($deletedPaths, $paths);
    } catch (Throwable $e) {
        $failedPaths = array_merge($failedPaths, $paths);
        break;
    }
}

$verificationFailed = [];
foreach ($deletedPaths as $path) {
    if ($db->document($path)->snapshot()->exists()) {
        $verificationFailed[] = $path;
    }
}

NolaCache::invalidateAdminDashboard();
foreach ($locationIds as $locationId) {
    NolaCache::delete('account_profile_' . $locationId);
    NolaCache::deleteRegistry('credits_registry_' . $locationId);
    $runRef->collection('locations')->document($locationId)->set([
        'location_id' => $locationId,
        'state' => $failedPaths === [] && $verificationFailed === [] ? 'LOCAL_ONLY_VERIFIED' : 'LOCAL_ONLY_FAILED',
        'updated_at' => clo_now(),
    ], ['merge' => true]);
}

$success = $failedPaths === [] && $verificationFailed === [];
$runRef->set([
    'state' => $success ? 'LOCAL_ONLY_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deletedPaths),
    'failed_paths' => $failedPaths,
    'verification_failed_paths' => $verificationFailed,
    'updated_at' => clo_now(),
], ['merge' => true]);

echo json_encode([
    'run_id' => $runId,
    'state' => $success ? 'LOCAL_ONLY_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deletedPaths),
    'failed_document_count' => count($failedPaths),
    'verification_failed_document_count' => count($verificationFailed),
    'ghl_app_status' => 'may_remain_visible_but_unusable',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit($success ? 0 : 4);
