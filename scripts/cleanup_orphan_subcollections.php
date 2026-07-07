<?php

/** Remove nested Firestore documents left beneath deleted parent documents. */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/CleanupSafety.php';

use Google\Cloud\Core\Timestamp;

function cos_arg(string $name, string $default = ''): string
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

function cos_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function cos_location_from_snapshot($snapshot): ?string
{
    $data = $snapshot->data();
    foreach (['location_id', 'locationId', 'ghl_location_id', 'subaccount_id'] as $field) {
        $value = trim((string)($data[$field] ?? ''));
        if ($value !== '') {
            return str_starts_with($value, 'ghl_') ? substr($value, 4) : $value;
        }
    }

    $path = $snapshot->reference()->path();
    $segments = explode('/', $path);
    $collection = $snapshot->reference()->parent()->id();
    if ($collection === 'subaccounts') {
        return $snapshot->id();
    }
    if ($collection === 'members') {
        $ownerIndex = array_search('location_owners', $segments, true);
        if ($ownerIndex !== false && isset($segments[$ownerIndex + 1])) {
            return $segments[$ownerIndex + 1];
        }
    }
    if ($collection === 'templates') {
        $integrationIndex = array_search('integrations', $segments, true);
        if ($integrationIndex !== false && isset($segments[$integrationIndex + 1])) {
            $integrationId = $segments[$integrationIndex + 1];
            return str_starts_with($integrationId, 'ghl_') ? substr($integrationId, 4) : null;
        }
    }
    return null;
}

$execute = cos_flag('execute');
if ($execute) {
    if (cos_arg('confirm') !== 'DELETE-ORPHAN-SUBCOLLECTIONS'
        || strtolower(cos_arg('backup-confirmed')) !== 'yes'
        || trim(cos_arg('operator')) === '') {
        fwrite(STDERR, "Execution confirmation, backup confirmation, and operator are required.\n");
        exit(2);
    }
}

$db = get_firestore();
$deletePaths = [];
$protectedPaths = [];
$unknownPaths = [];
$counts = [];
foreach (['subaccounts', 'members', 'templates'] as $collectionGroup) {
    foreach ($db->collectionGroup($collectionGroup)->documents() as $snapshot) {
        if (!$snapshot->exists()) {
            continue;
        }
        $path = $snapshot->reference()->path();
        $locationId = cos_location_from_snapshot($snapshot);
        if ($locationId === null || $locationId === '') {
            $unknownPaths[] = $path;
            continue;
        }
        if (CleanupSafety::isProtectedLocation($locationId)) {
            $protectedPaths[] = $path;
            continue;
        }
        $deletePaths[] = $path;
        $counts[$collectionGroup] = ($counts[$collectionGroup] ?? 0) + 1;
    }
}
$deletePaths = array_values(array_unique($deletePaths));

if (!$execute) {
    echo json_encode([
        'mode' => 'dry_run',
        'deletion_document_count' => count($deletePaths),
        'deletion_counts' => $counts,
        'protected_document_count' => count(array_unique($protectedPaths)),
        'unknown_retained_count' => count(array_unique($unknownPaths)),
        'protected_locations' => CleanupSafety::PROTECTED_LOCATIONS,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$runId = gmdate('Ymd_His') . '_nested_' . bin2hex(random_bytes(4));
$runRef = $db->collection('cleanup_runs')->document($runId);
$runRef->set([
    'run_id' => $runId,
    'state' => 'ORPHAN_SUBCOLLECTION_CLEANUP_APPROVED',
    'operator' => cos_arg('operator'),
    'reason' => cos_arg('reason', 'Remove nested documents beneath deleted test-account parents'),
    'protected_location_ids' => array_keys(CleanupSafety::PROTECTED_LOCATIONS),
    'planned_delete_count' => count($deletePaths),
    'protected_document_count' => count(array_unique($protectedPaths)),
    'unknown_retained_count' => count(array_unique($unknownPaths)),
    'created_at' => new Timestamp(new DateTimeImmutable()),
]);

$deleted = [];
$failed = [];
foreach (array_chunk($deletePaths, 200) as $paths) {
    $batch = $db->batch();
    foreach ($paths as $path) {
        $batch->delete($db->document($path));
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
$success = $failed === [] && $verificationFailed === [];
$runRef->set([
    'state' => $success ? 'ORPHAN_SUBCOLLECTION_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deleted),
    'failed_paths' => $failed,
    'verification_failed_paths' => $verificationFailed,
    'updated_at' => new Timestamp(new DateTimeImmutable()),
], ['merge' => true]);

echo json_encode([
    'run_id' => $runId,
    'state' => $success ? 'ORPHAN_SUBCOLLECTION_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deleted),
    'failed_document_count' => count($failed),
    'verification_failed_document_count' => count($verificationFailed),
    'protected_document_count' => count(array_unique($protectedPaths)),
    'unknown_retained_count' => count(array_unique($unknownPaths)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($success ? 0 : 4);
