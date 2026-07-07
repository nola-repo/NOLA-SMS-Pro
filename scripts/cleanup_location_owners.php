<?php

/** Keep only protected production location-owner documents and their children. */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/CleanupSafety.php';

use Google\Cloud\Core\Timestamp;

function clo2_arg(string $name, string $default = ''): string
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

function clo2_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

$execute = clo2_flag('execute');
if ($execute && (
    clo2_arg('confirm') !== 'KEEP-ONLY-PROTECTED-LOCATION-OWNERS'
    || strtolower(clo2_arg('backup-confirmed')) !== 'yes'
    || trim(clo2_arg('operator')) === ''
)) {
    fwrite(STDERR, "Execution confirmation, backup confirmation, and operator required.\n");
    exit(2);
}

$db = get_firestore();
$deletePaths = [];
$protectedPaths = [];
foreach ($db->collection('location_owners')->documents() as $ownerSnap) {
    if (!$ownerSnap->exists()) {
        continue;
    }
    $locationId = $ownerSnap->id();
    if (CleanupSafety::isProtectedLocation($locationId)) {
        $protectedPaths[] = $ownerSnap->reference()->path();
        continue;
    }
    foreach ($ownerSnap->reference()->collections() as $childCollection) {
        foreach ($childCollection->documents() as $childSnap) {
            if ($childSnap->exists()) {
                $deletePaths[] = $childSnap->reference()->path();
            }
        }
    }
    $deletePaths[] = $ownerSnap->reference()->path();
}
$deletePaths = array_values(array_unique($deletePaths));

if (!$execute) {
    echo json_encode([
        'mode' => 'dry_run',
        'deletion_document_count' => count($deletePaths),
        'deletion_paths' => $deletePaths,
        'protected_document_count' => count($protectedPaths),
        'protected_locations' => CleanupSafety::PROTECTED_LOCATIONS,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$runId = gmdate('Ymd_His') . '_owners_' . bin2hex(random_bytes(4));
$runRef = $db->collection('cleanup_runs')->document($runId);
$runRef->set([
    'run_id' => $runId,
    'state' => 'LOCATION_OWNER_CLEANUP_APPROVED',
    'operator' => clo2_arg('operator'),
    'reason' => clo2_arg('reason', 'Keep only three protected production location owners'),
    'protected_location_ids' => array_keys(CleanupSafety::PROTECTED_LOCATIONS),
    'planned_delete_count' => count($deletePaths),
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
    'state' => $success ? 'LOCATION_OWNER_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deleted),
    'failed_paths' => $failed,
    'verification_failed_paths' => $verificationFailed,
    'updated_at' => new Timestamp(new DateTimeImmutable()),
], ['merge' => true]);

echo json_encode([
    'run_id' => $runId,
    'state' => $success ? 'LOCATION_OWNER_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deleted),
    'failed_document_count' => count($failed),
    'verification_failed_document_count' => count($verificationFailed),
    'protected_document_count' => count($protectedPaths),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($success ? 0 : 4);
