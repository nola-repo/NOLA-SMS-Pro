<?php

/** Safely remove known test/template wallet documents after a dependency scan. */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';

use Google\Cloud\Core\Timestamp;

const CPA_TARGETS = [
    'test_subaccount_123',
    '{{location.id',
    '{{location.id}}',
];
const CPA_PROTECTED = [
    'default',
    'ugBqfQsPtGijLjrmLdmA',
    'UorU5d43qIWssU2z55fO',
    'Is3CjRqD4xzqonUZIOEo',
];

function cpa_arg(string $name, string $default = ''): string
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

function cpa_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function cpa_contains_target($value, array $targets): array
{
    $found = [];
    if (is_array($value)) {
        foreach ($value as $child) {
            $found = array_merge($found, cpa_contains_target($child, $targets));
        }
    } elseif (is_string($value)) {
        foreach ($targets as $target) {
            if ($value === $target) {
                $found[] = $target;
            }
        }
    }
    return array_values(array_unique($found));
}

function cpa_scan_collection($collection, array $targets, array &$references, array &$children): void
{
    $nestedParentCollections = ['integrations', 'users', 'location_owners'];
    foreach ($collection->documents() as $snapshot) {
        if (!$snapshot->exists()) {
            continue;
        }
        $path = $snapshot->reference()->path();
        $selfTarget = null;
        foreach ($targets as $target) {
            if ($path === 'accounts/' . $target) {
                $selfTarget = $target;
                break;
            }
        }
        $matches = cpa_contains_target($snapshot->data(), $targets);
        if ($selfTarget === null && $matches !== []) {
            $references[] = ['path' => $path, 'targets' => $matches];
        }
        if (in_array($collection->id(), $nestedParentCollections, true)) {
            foreach ($snapshot->reference()->collections() as $childCollection) {
                cpa_scan_collection($childCollection, $targets, $references, $children);
            }
        }
    }
}

$execute = cpa_flag('execute');
if ($execute && (
    cpa_arg('confirm') !== 'DELETE-PLACEHOLDER-ACCOUNTS'
    || strtolower(cpa_arg('backup-confirmed')) !== 'yes'
    || trim(cpa_arg('operator')) === ''
)) {
    fwrite(STDERR, "Execution confirmation, backup confirmation, and operator required.\n");
    exit(2);
}

foreach (CPA_TARGETS as $target) {
    if (in_array($target, CPA_PROTECTED, true)) {
        fwrite(STDERR, "A protected account was included in the deletion set.\n");
        exit(3);
    }
}

$db = get_firestore();
$accounts = [];
$deletePaths = [];
foreach (CPA_TARGETS as $target) {
    $snapshot = $db->collection('accounts')->document($target)->snapshot();
    $data = $snapshot->exists() ? $snapshot->data() : [];
    $accounts[] = [
        'path' => 'accounts/' . $target,
        'exists' => $snapshot->exists(),
        'credit_balance' => $data['credit_balance'] ?? null,
        'currency' => $data['currency'] ?? null,
    ];
    if ($snapshot->exists()) {
        $deletePaths[] = 'accounts/' . $target;
    }
}

$references = [];
$children = [];
foreach ($db->collections() as $collection) {
    cpa_scan_collection($collection, CPA_TARGETS, $references, $children);
}
$deletePaths = array_values(array_unique(array_merge($children, $deletePaths)));

if (!$execute) {
    echo json_encode([
        'mode' => 'dry_run',
        'accounts' => $accounts,
        'delete_paths' => $deletePaths,
        'external_reference_count' => count($references),
        'external_references' => $references,
        'protected_accounts' => CPA_PROTECTED,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($references !== []) {
    fwrite(STDERR, "Deletion stopped: external references exist.\n");
    exit(4);
}

$runId = gmdate('Ymd_His') . '_placeholder_accounts_' . bin2hex(random_bytes(4));
$runRef = $db->collection('cleanup_runs')->document($runId);
$runRef->set([
    'run_id' => $runId,
    'state' => 'PLACEHOLDER_ACCOUNT_CLEANUP_APPROVED',
    'operator' => cpa_arg('operator'),
    'reason' => cpa_arg('reason', 'Remove test and malformed template wallet documents'),
    'planned_delete_paths' => $deletePaths,
    'protected_account_ids' => CPA_PROTECTED,
    'created_at' => new Timestamp(new DateTimeImmutable()),
]);

$batch = $db->batch();
foreach ($deletePaths as $path) {
    $batch->delete($db->document($path));
}
$batch->commit();

$verificationFailed = [];
foreach ($deletePaths as $path) {
    if ($db->document($path)->snapshot()->exists()) {
        $verificationFailed[] = $path;
    }
}
$success = $verificationFailed === [];
$runRef->set([
    'state' => $success ? 'PLACEHOLDER_ACCOUNT_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deletePaths) - count($verificationFailed),
    'verification_failed_paths' => $verificationFailed,
    'updated_at' => new Timestamp(new DateTimeImmutable()),
], ['merge' => true]);

echo json_encode([
    'run_id' => $runId,
    'state' => $success ? 'PLACEHOLDER_ACCOUNT_CLEANUP_VERIFIED' : 'REQUIRES_REVIEW',
    'deleted_document_count' => count($deletePaths) - count($verificationFailed),
    'verification_failed_count' => count($verificationFailed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($success ? 0 : 5);
