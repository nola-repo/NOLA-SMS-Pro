<?php
/**
 * One-time migration:
 *   location_members/{locationId}/members/{uid}
 *     -> users/{uid}/subaccounts/{locationId}
 *
 * Safe-by-default:
 * - merge writes only
 * - no deletes
 * - supports dry-run mode
 *
 * Usage:
 *   php migrate_location_members.php
 *   php migrate_location_members.php --dry-run
 */

require __DIR__ . '/api/webhook/firestore_client.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$db = get_firestore();
$now = new DateTimeImmutable();
$ts = new \Google\Cloud\Core\Timestamp($now);

$summary = [
    'dry_run' => $dryRun,
    'locations_scanned' => 0,
    'member_docs_scanned' => 0,
    'subaccounts_written' => 0,
    'missing_users' => 0,
    'errors' => 0,
];

echo '[MIGRATE] Starting location_members -> users/{uid}/subaccounts migration' . PHP_EOL;
echo '[MIGRATE] Mode: ' . ($dryRun ? 'DRY RUN' : 'LIVE WRITE') . PHP_EOL;

try {
    $locationDocs = $db->collection('location_members')->documents();

    foreach ($locationDocs as $locationDoc) {
        if (!$locationDoc->exists()) {
            continue;
        }

        $summary['locations_scanned']++;
        $locationId = $locationDoc->id();
        echo "[MIGRATE] Scanning location {$locationId}" . PHP_EOL;

        $memberDocs = $db->collection('location_members')
            ->document($locationId)
            ->collection('members')
            ->documents();

        foreach ($memberDocs as $memberDoc) {
            if (!$memberDoc->exists()) {
                continue;
            }

            $summary['member_docs_scanned']++;
            $uid = $memberDoc->id();
            $member = $memberDoc->data();

            try {
                $userRef = $db->collection('users')->document($uid);
                $userSnap = $userRef->snapshot();
                if (!$userSnap->exists()) {
                    $summary['missing_users']++;
                    echo "[WARN] Missing user doc for uid={$uid}, location={$locationId}" . PHP_EOL;
                    continue;
                }

                $user = $userSnap->data();
                $payload = [
                    'location_id' => $locationId,
                    'location_name' => $user['location_name'] ?? '',
                    'company_id' => $user['company_id'] ?? '',
                    'company_name' => $user['company_name'] ?? '',
                    'role' => $member['role'] ?? ($user['role'] ?? 'user'),
                    'is_active' => true,
                    'linked_at' => $member['joined_at'] ?? $ts,
                ];

                if (!$dryRun) {
                    $userRef->collection('subaccounts')->document($locationId)->set($payload, ['merge' => true]);
                }

                $summary['subaccounts_written']++;
                echo "[OK] {$uid} -> subaccounts/{$locationId}" . PHP_EOL;
            } catch (Exception $e) {
                $summary['errors']++;
                echo "[ERROR] uid={$uid} location={$locationId}: {$e->getMessage()}" . PHP_EOL;
            }
        }
    }
} catch (Exception $e) {
    $summary['errors']++;
    echo '[FATAL] ' . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . '[MIGRATE] Done' . PHP_EOL;
echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;

