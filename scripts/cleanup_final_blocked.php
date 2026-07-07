<?php

/** Final explicit cleanup for test accounts with balances or pending work. */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/cache_helper.php';
require_once __DIR__ . '/../api/install_helpers.php';
require_once __DIR__ . '/../api/services/CleanupSafety.php';
require_once __DIR__ . '/../api/services/GhlMarketplaceUninstallService.php';

use Google\Cloud\Core\Timestamp;

function cfb_arg(string $name, string $default = ''): string
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

function cfb_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function cfb_now(): Timestamp
{
    return new Timestamp(new DateTimeImmutable());
}

$manifestPath = cfb_arg('manifest');
$digest = cfb_arg('digest');
$locationIds = array_values(array_unique(array_filter(array_map('trim', explode(',', cfb_arg('locations'))))));
$appId = trim(cfb_arg('app-id'));
$execute = cfb_flag('execute');
$errors = [];

if (!is_file($manifestPath)) {
    $errors[] = 'Readable manifest required.';
}
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
if (!is_array($manifest)) {
    $manifest = [];
    $errors[] = 'Valid manifest required.';
} else {
    $errors = array_merge($errors, CleanupSafety::validateManifest($manifest, $digest, 3600));
}
if ($locationIds === [] || count($locationIds) > 10) {
    $errors[] = 'One to ten explicit locations required.';
}
if (!preg_match('/^[A-Za-z0-9_-]{8,160}$/', $appId)) {
    $errors[] = 'Valid Marketplace app ID required.';
}

$candidateMap = CleanupSafety::candidateMap($manifest);
foreach ($locationIds as $locationId) {
    if (CleanupSafety::isProtectedLocation($locationId) || !isset($candidateMap[$locationId])) {
        $errors[] = 'Protected or unknown location refused: ' . $locationId;
        continue;
    }
    $blockers = CleanupSafety::candidateBlockers($candidateMap[$locationId]);
    if (!array_intersect($blockers, ['nonzero_balance', 'pending_work'])) {
        $errors[] = $locationId . ' is not a final blocked candidate.';
    }
}

$selected = array_fill_keys($locationIds, true);
$actions = ['would_delete', 'manual_review_nonzero_balance', 'manual_review_pending'];
$decisions = [];
foreach (($manifest['unique_document_decisions'] ?? []) as $decision) {
    if (!in_array((string)($decision['final_action'] ?? ''), $actions, true)
        || !in_array((string)($decision['collection'] ?? ''), CleanupSafety::DELETABLE_COLLECTIONS, true)) {
        continue;
    }
    $candidateIds = array_values(array_unique(array_map('strval', $decision['candidate_ids'] ?? [])));
    if ($candidateIds === []) {
        continue;
    }
    $allSelected = true;
    foreach ($candidateIds as $candidateId) {
        if (!isset($selected[$candidateId])) {
            $allSelected = false;
            break;
        }
    }
    if ($allSelected) {
        $decisions[] = $decision;
    }
}
if ($decisions === []) {
    $errors[] = 'No final cleanup decisions found.';
}

if ($execute) {
    if (cfb_arg('confirm') !== 'FORFEIT-TEST-BALANCES-CANCEL-PENDING') {
        $errors[] = 'Final cleanup confirmation phrase missing.';
    }
    if (strtolower(cfb_arg('backup-confirmed')) !== 'yes') {
        $errors[] = 'Backup confirmation missing.';
    }
    if (trim(cfb_arg('operator')) === '' || trim(cfb_arg('reason')) === '') {
        $errors[] = 'Operator and reason required.';
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Final cleanup refused:\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(2);
}

$actionCounts = [];
foreach ($decisions as $decision) {
    $action = (string)$decision['final_action'];
    $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;
}
if (!$execute) {
    echo json_encode([
        'mode' => 'dry_run',
        'locations' => $locationIds,
        'deletion_document_count' => count($decisions),
        'action_counts' => $actionCounts,
        'financial_history' => 'preserved',
        'production_shared_records' => 'preserved',
        'native_ghl_conversations' => 'preserved',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$db = get_firestore();
$runId = gmdate('Ymd_His') . '_final_' . bin2hex(random_bytes(4));
$runRef = $db->collection('cleanup_runs')->document($runId);
$runRef->set([
    'run_id' => $runId,
    'state' => 'FINAL_TEST_CLEANUP_APPROVED',
    'operator' => cfb_arg('operator'),
    'reason' => cfb_arg('reason'),
    'manifest_sha256' => CleanupSafety::manifestDigest($manifest),
    'approved_location_ids' => $locationIds,
    'test_balances_forfeited' => (int)($actionCounts['manual_review_nonzero_balance'] ?? 0),
    'pending_test_work_cancelled' => (int)($actionCounts['manual_review_pending'] ?? 0),
    'financial_history' => 'preserved',
    'native_ghl_conversations' => 'preserved',
    'created_at' => cfb_now(),
    'updated_at' => cfb_now(),
]);

$uninstallService = new GhlMarketplaceUninstallService();
$remoteResults = [];
foreach ($locationIds as $locationId) {
    $candidate = $candidateMap[$locationId];
    $remote = is_array($candidate['remote_uninstall'] ?? null) ? $candidate['remote_uninstall'] : [];
    $tokenPath = trim((string)($remote['token_registry_path'] ?? ''));
    if ($tokenPath !== '') {
        $db->document($tokenPath)->set([
            'cleanup_in_progress' => true,
            'toggle_enabled' => false,
            'cleanup_run_id' => $runId,
            'updated_at' => cfb_now(),
        ], ['merge' => true]);
    }
    $integrationId = 'ghl_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $locationId);
    $db->collection('integrations')->document($integrationId)->set([
        'cleanup_in_progress' => true,
        'toggle_enabled' => false,
        'cleanup_run_id' => $runId,
        'updated_at' => cfb_now(),
    ], ['merge' => true]);

    $remoteResult = [
        'success' => false,
        'classification' => 'no_usable_location_token_local_only',
        'http_status' => 0,
    ];
    if (($remote['token_appears_usable'] ?? false) === true && $tokenPath !== '') {
        $tokenRegistryId = basename(str_replace('\\', '/', $tokenPath));
        $remoteResult = $uninstallService->uninstall(
            $db,
            $locationId,
            $appId,
            $tokenRegistryId,
            'Approved final test-account cleanup'
        );
        if (!empty($remoteResult['success'])) {
            install_mark_location_uninstalled(
                $db,
                $locationId,
                'approved_final_test_cleanup:' . $runId,
                $candidate['company_ids'][0] ?? null
            );
        }
    }
    $remoteResults[$locationId] = $remoteResult;
    $runRef->collection('locations')->document($locationId)->set([
        'location_id' => $locationId,
        'state' => !empty($remoteResult['success']) ? 'GHL_UNINSTALLED' : 'LOCAL_ONLY_REQUIRED',
        'remote_result' => $remoteResult,
        'updated_at' => cfb_now(),
    ]);
}

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
foreach ($locationIds as $locationId) {
    $runRef->collection('locations')->document($locationId)->set([
        'state' => $success ? 'FINAL_LOCAL_VERIFIED' : 'FINAL_LOCAL_FAILED',
        'ghl_app_may_remain_visible' => empty($remoteResults[$locationId]['success']),
        'updated_at' => cfb_now(),
    ], ['merge' => true]);
}
$runRef->set([
    'state' => $success ? 'FINAL_TEST_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deleted),
    'failed_paths' => $failed,
    'verification_failed_paths' => $verificationFailed,
    'remote_results' => $remoteResults,
    'updated_at' => cfb_now(),
], ['merge' => true]);

echo json_encode([
    'run_id' => $runId,
    'state' => $success ? 'FINAL_TEST_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'location_count' => count($locationIds),
    'deleted_document_count' => count($deleted),
    'failed_document_count' => count($failed),
    'verification_failed_document_count' => count($verificationFailed),
    'ghl_uninstall_success_count' => count(array_filter($remoteResults, static fn(array $r): bool => !empty($r['success']))),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($success ? 0 : 4);
