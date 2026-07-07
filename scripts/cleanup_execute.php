<?php

/**
 * Dry-run-first executor for approved NOLA test-subaccount cleanup manifests.
 *
 * No mutation occurs unless every destructive flag is supplied. Run --help for
 * the complete contract. Native HighLevel conversations are never deleted.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/install_helpers.php';
require_once __DIR__ . '/../api/cache_helper.php';
require_once __DIR__ . '/../api/services/CleanupSafety.php';
require_once __DIR__ . '/../api/services/GhlMarketplaceUninstallService.php';

use Google\Cloud\Core\Timestamp;

function ce_arg(string $name, ?string $default = null): ?string
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

function ce_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function ce_ids(?string $value): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn(string $id): string => trim($id),
        explode(',', (string)$value)
    ))));
}

function ce_now(): Timestamp
{
    return new Timestamp(new DateTimeImmutable());
}

function ce_action_total(array $candidate, string $action): int
{
    $total = 0;
    foreach (($candidate['counts'] ?? []) as $categoryCounts) {
        if (is_array($categoryCounts)) {
            $total += (int)($categoryCounts[$action] ?? 0);
        }
    }
    return $total;
}

function ce_is_pending(array $data): bool
{
    $status = strtolower(trim((string)($data['status'] ?? $data['install_state'] ?? '')));
    return in_array($status, ['pending', 'queued', 'sending', 'processing', 'provisioning', 'pending_payment_provider'], true);
}

function ce_has_nonzero_balance(array $data): bool
{
    foreach (['balance', 'credit_balance', 'credits', 'current_balance', 'wallet_balance'] as $field) {
        if (isset($data[$field]) && is_numeric($data[$field]) && (float)$data[$field] != 0.0) {
            return true;
        }
    }
    return false;
}

/** Recheck high-risk live records immediately before applying the cleanup lock. */
function ce_live_blockers($db, array $candidate): array
{
    $blockers = [];
    foreach (($candidate['documents'] ?? []) as $document) {
        if (!is_array($document)) {
            continue;
        }
        $collection = (string)($document['collection'] ?? '');
        if (!in_array($collection, ['accounts', 'integrations', 'messages', 'sender_id_requests'], true)) {
            continue;
        }
        $path = trim((string)($document['path'] ?? ''));
        if ($path === '') {
            continue;
        }
        $snapshot = $db->document($path)->snapshot();
        if (!$snapshot->exists()) {
            continue;
        }
        $data = $snapshot->data();
        if (in_array($collection, ['accounts', 'integrations'], true) && ce_has_nonzero_balance($data)) {
            $blockers[] = 'live_nonzero_balance:' . $path;
        }
        if (ce_is_pending($data)) {
            $blockers[] = 'live_pending_work:' . $path;
        }
    }
    return array_values(array_unique($blockers));
}

function ce_safe_audit_set($ref, array $data): void
{
    // Callers construct an allowlisted payload; never pass token documents or
    // remote bodies into the audit ledger.
    $ref->set($data, ['merge' => true]);
}

if (ce_flag('help') || ce_flag('h')) {
    echo <<<'HELP'
NOLA test-subaccount cleanup executor (dry-run by default).

Required for all runs:
  --manifest=PATH
  --digest=SHA256
  --locations=LOCATION_ID[,LOCATION_ID]
  --app-id=MARKETPLACE_APPLICATION_ID

Additional requirements for mutation:
  --execute
  --confirm=DELETE-NOLA-TEST-DATA
  --operator=IDENTITY
  --reason=TEXT
  --backup-confirmed=yes

Optional:
  --max-age-seconds=3600
  --max-locations=5
  --transport=rest

The executor uninstalls NOLA from GHL before deleting local OAuth/account data.
It never calls a GHL conversation-delete or location-delete endpoint.
HELP;
    echo "\n";
    exit(0);
}

$manifestPath = trim((string)ce_arg('manifest', ''));
$expectedDigest = trim((string)ce_arg('digest', ''));
$approvedLocationIds = ce_ids(ce_arg('locations', ''));
$marketplaceAppId = trim((string)ce_arg('app-id', ''));
$execute = ce_flag('execute');
$maxAge = max(60, (int)ce_arg('max-age-seconds', '3600'));
$maxLocations = max(1, min(20, (int)ce_arg('max-locations', '5')));

$errors = [];
if ($manifestPath === '' || !is_file($manifestPath)) {
    $errors[] = 'A readable --manifest path is required.';
}
if ($approvedLocationIds === []) {
    $errors[] = 'At least one explicit --locations value is required.';
}
if (!preg_match('/^[A-Za-z0-9_-]{8,160}$/', $marketplaceAppId)) {
    $errors[] = 'A valid explicit --app-id Marketplace application ID is required.';
}
if (count($approvedLocationIds) > $maxLocations) {
    $errors[] = 'Approved location count exceeds --max-locations.';
}

$manifest = [];
if ($errors === []) {
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        $errors[] = 'Manifest JSON is invalid.';
    } else {
        $errors = array_merge($errors, CleanupSafety::validateManifest($manifest, $expectedDigest, $maxAge));
    }
}

$candidateMap = CleanupSafety::candidateMap($manifest);
$approvedCandidates = [];
foreach ($approvedLocationIds as $locationId) {
    if (CleanupSafety::isProtectedLocation($locationId)) {
        $errors[] = 'Protected production location refused: ' . $locationId;
        continue;
    }
    if (!isset($candidateMap[$locationId])) {
        $errors[] = 'Location is absent from the manifest: ' . $locationId;
        continue;
    }
    $candidate = $candidateMap[$locationId];
    $blockers = CleanupSafety::candidateBlockers($candidate);
    if ($blockers !== []) {
        $errors[] = $locationId . ' blocked: ' . implode(', ', $blockers);
        continue;
    }
    $remote = is_array($candidate['remote_uninstall'] ?? null) ? $candidate['remote_uninstall'] : [];
    $manifestAppId = trim((string)($remote['app_id'] ?? ''));
    if ($manifestAppId !== '' && !hash_equals($manifestAppId, $marketplaceAppId)) {
        $errors[] = $locationId . ' manifest Marketplace app ID does not match --app-id.';
    }
    if (trim((string)($remote['token_registry_path'] ?? '')) === '') {
        $errors[] = $locationId . ' has no token registry path.';
    }
    if (($remote['token_appears_usable'] ?? false) !== true) {
        $errors[] = $locationId . ' does not appear to have usable GHL authorization.';
    }
    $approvedCandidates[$locationId] = $candidate;
}

$deletionDecisions = CleanupSafety::approvedDeletionDecisions($manifest, $approvedLocationIds);
if ($deletionDecisions === []) {
    $errors[] = 'The approved set has no candidate-only deletions.';
}

if ($execute) {
    if (!hash_equals(CleanupSafety::EXECUTION_CONFIRMATION, (string)ce_arg('confirm', ''))) {
        $errors[] = 'Execution confirmation phrase is missing or incorrect.';
    }
    if (trim((string)ce_arg('operator', '')) === '') {
        $errors[] = '--operator is required for execution.';
    }
    if (trim((string)ce_arg('reason', '')) === '') {
        $errors[] = '--reason is required for execution.';
    }
    if (strtolower(trim((string)ce_arg('backup-confirmed', ''))) !== 'yes') {
        $errors[] = '--backup-confirmed=yes is required for execution.';
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Cleanup refused:\n- " . implode("\n- ", array_values(array_unique($errors))) . "\n");
    exit(2);
}

$plan = [
    'mode' => $execute ? 'execute' : 'dry_run',
    'manifest' => realpath($manifestPath) ?: $manifestPath,
    'manifest_sha256' => CleanupSafety::manifestDigest($manifest),
    'approved_locations' => $approvedLocationIds,
    'marketplace_app_id' => $marketplaceAppId,
    'candidate_count' => count($approvedCandidates),
    'deletion_document_count' => count($deletionDecisions),
    'native_ghl_conversations' => 'preserved',
    'ghl_locations' => 'preserved',
];

if (!$execute) {
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo "DRY RUN ONLY: no Firestore mutation or GHL request was performed.\n";
    exit(0);
}

$transport = strtolower(trim((string)ce_arg('transport', getenv('FIRESTORE_TRANSPORT') ?: 'auto')));
$db = $transport === 'rest'
    ? new \Google\Cloud\Firestore\FirestoreClient([
        'projectId' => getenv('GOOGLE_CLOUD_PROJECT') ?: 'nola-sms-pro',
        'transport' => 'rest',
    ])
    : get_firestore();

$runId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
$runRef = $db->collection('cleanup_runs')->document($runId);
ce_safe_audit_set($runRef, [
    'run_id' => $runId,
    'state' => 'APPROVED',
    'operator' => trim((string)ce_arg('operator', '')),
    'reason' => trim((string)ce_arg('reason', '')),
    'manifest_sha256' => $plan['manifest_sha256'],
    'approved_location_ids' => $approvedLocationIds,
    'backup_confirmed' => true,
    'created_at' => ce_now(),
    'updated_at' => ce_now(),
]);

$uninstallService = new GhlMarketplaceUninstallService();
$successfulLocations = [];

foreach ($approvedCandidates as $locationId => $candidate) {
    $locationAudit = $runRef->collection('locations')->document($locationId);
    $liveBlockers = ce_live_blockers($db, $candidate);
    if ($liveBlockers !== []) {
        ce_safe_audit_set($locationAudit, [
            'location_id' => $locationId,
            'state' => 'BLOCKED_LIVE_REVALIDATION',
            'blockers' => $liveBlockers,
            'updated_at' => ce_now(),
        ]);
        continue;
    }

    $remote = $candidate['remote_uninstall'];
    $tokenPath = (string)$remote['token_registry_path'];
    $tokenRegistryId = basename(str_replace('\\', '/', $tokenPath));
    $integrationId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);

    // Close the send race before making the remote uninstall request.
    $lock = [
        'cleanup_in_progress' => true,
        'cleanup_run_id' => $runId,
        'toggle_enabled' => false,
        'updated_at' => ce_now(),
    ];
    $db->document($tokenPath)->set($lock, ['merge' => true]);
    $db->collection('integrations')->document($integrationId)->set($lock, ['merge' => true]);
    ce_safe_audit_set($locationAudit, [
        'location_id' => $locationId,
        'state' => 'SMS_BLOCKED',
        'updated_at' => ce_now(),
    ]);

    $result = $uninstallService->uninstall(
        $db,
        $locationId,
        $marketplaceAppId,
        $tokenRegistryId,
        trim((string)ce_arg('reason', ''))
    );
    ce_safe_audit_set($locationAudit, [
        'state' => $result['success'] ? 'GHL_UNINSTALLED' : 'REMOTE_UNINSTALL_FAILED',
        'remote_result' => $result,
        'updated_at' => ce_now(),
    ]);
    if (!$result['success']) {
        continue;
    }

    // Idempotently apply the same local lifecycle state as the GHL webhook.
    install_mark_location_uninstalled(
        $db,
        $locationId,
        'approved_test_cleanup:' . $runId,
        $candidate['company_ids'][0] ?? null
    );
    $successfulLocations[] = $locationId;
}

$committedPaths = [];
$failedPaths = [];
$verificationFailedPaths = [];
$safeDecisions = CleanupSafety::approvedDeletionDecisions($manifest, $successfulLocations);
foreach (array_chunk($safeDecisions, 200) as $decisionChunk) {
    $batch = $db->batch();
    $chunkPaths = [];
    foreach ($decisionChunk as $decision) {
        $path = trim((string)($decision['path'] ?? ''));
        if ($path === '' || str_starts_with($path, 'cleanup_runs/')) {
            continue;
        }
        $batch->delete($db->document($path));
        $chunkPaths[] = $path;
    }
    if ($chunkPaths === []) {
        continue;
    }
    try {
        $batch->commit();
        $committedPaths = array_merge($committedPaths, $chunkPaths);
    } catch (Throwable $e) {
        $failedPaths = array_merge($failedPaths, $chunkPaths);
        error_log('[cleanup_execute] run=' . $runId . ' batch_delete_failed count=' . count($chunkPaths));
        break;
    }
}

// Verify the local destructive postcondition before reporting success. A path
// that still exists is retained in the audit for a bounded, idempotent rerun.
foreach ($committedPaths as $path) {
    try {
        if ($db->document($path)->snapshot()->exists()) {
            $verificationFailedPaths[] = $path;
        }
    } catch (Throwable $e) {
        $verificationFailedPaths[] = $path;
    }
}
$verificationFailedPaths = array_values(array_unique($verificationFailedPaths));

NolaCache::invalidateAdminDashboard();
foreach ($approvedCandidates as $locationId => $candidate) {
    NolaCache::delete('account_profile_' . $locationId);
    NolaCache::deleteRegistry('credits_registry_' . $locationId);
    foreach (($candidate['company_ids'] ?? []) as $companyId) {
        NolaCache::invalidateAgencyDashboard((string)$companyId);
    }
    $locationPaths = [];
    foreach ($safeDecisions as $decision) {
        if (in_array($locationId, $decision['candidate_ids'] ?? [], true)) {
            $locationPaths[] = (string)($decision['path'] ?? '');
        }
    }
    $locationDeleteFailed = array_intersect($locationPaths, array_merge($failedPaths, $verificationFailedPaths)) !== [];
    $locationState = in_array($locationId, $successfulLocations, true)
        ? ($locationDeleteFailed ? 'LOCAL_VERIFICATION_FAILED' : 'LOCAL_VERIFIED')
        : 'REMOTE_UNINSTALL_FAILED';
    ce_safe_audit_set($runRef->collection('locations')->document($locationId), [
        'state' => $locationState,
        'local_expected_delete_count' => count($locationPaths),
        'manual_ghl_provider_verification_required' => $locationState === 'LOCAL_VERIFIED',
        'updated_at' => ce_now(),
    ]);
}

$finalState = $failedPaths === []
    && $verificationFailedPaths === []
    && count($successfulLocations) === count($approvedCandidates)
    ? 'LOCAL_VERIFIED_REQUIRES_GHL_UI_CHECK'
    : 'REQUIRES_REVIEW';
ce_safe_audit_set($runRef, [
    'state' => $finalState,
    'successful_location_ids' => $successfulLocations,
    'deleted_document_count' => count($committedPaths),
    'failed_document_count' => count($failedPaths),
    'failed_paths' => $failedPaths,
    'verification_failed_document_count' => count($verificationFailedPaths),
    'verification_failed_paths' => $verificationFailedPaths,
    'native_ghl_conversations' => 'preserved_no_delete_api_called',
    'manual_ghl_provider_verification_required' => true,
    'updated_at' => ce_now(),
]);

echo json_encode([
    'run_id' => $runId,
    'state' => $finalState,
    'successful_locations' => $successfulLocations,
    'deleted_document_count' => count($committedPaths),
    'failed_document_count' => count($failedPaths),
    'verification_failed_document_count' => count($verificationFailedPaths),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit($finalState === 'LOCAL_VERIFIED_REQUIRES_GHL_UI_CHECK' ? 0 : 3);
