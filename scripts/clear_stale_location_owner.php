<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the CLI.\n");
    exit(1);
}

$locationId = trim((string)($argv[1] ?? ''));
$apply = in_array('--apply', $argv, true);
if ($locationId === '' || in_array($locationId, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php scripts/clear_stale_location_owner.php <location_id> [--apply]\n");
    fwrite(STDERR, "Dry-run is default. --apply deletes only an owner lock with no active user.\n");
    exit($locationId === '' ? 1 : 0);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/install_helpers.php';

try {
    $db = get_firestore();
    $ref = $db->collection('location_owners')->document($locationId);
    $snap = $ref->snapshot();
    if (!$snap->exists()) {
        echo json_encode([
            'success' => true,
            'dry_run' => !$apply,
            'location_id' => $locationId,
            'action' => 'nothing_to_clear',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $data = $snap->data();
    $ownerUserId = trim((string)($data['owner_user_id'] ?? $data['owner_uid'] ?? $data['user_id'] ?? $data['uid'] ?? ''));
    $ownerEmail = install_norm_email($data['owner_email'] ?? $data['email'] ?? $data['user_email'] ?? '');
    $activeOwner = install_active_user_for_owner_lock($db, $ownerUserId, $ownerEmail);
    if ($activeOwner !== null) {
        throw new RuntimeException('Refusing to delete: the owner lock resolves to an active user.');
    }

    if ($apply) {
        // Re-read immediately before deletion so the command cannot clear a
        // lock that changed while validation was running.
        $latest = $ref->snapshot();
        $latestData = $latest->exists() ? $latest->data() : [];
        $latestUid = trim((string)($latestData['owner_user_id'] ?? $latestData['owner_uid'] ?? $latestData['user_id'] ?? $latestData['uid'] ?? ''));
        $latestEmail = install_norm_email($latestData['owner_email'] ?? $latestData['email'] ?? $latestData['user_email'] ?? '');
        if (!$latest->exists() || $latestUid !== $ownerUserId || $latestEmail !== $ownerEmail) {
            throw new RuntimeException('Refusing to delete: the owner lock changed during validation.');
        }
        if (install_active_user_for_owner_lock($db, $latestUid, $latestEmail) !== null) {
            throw new RuntimeException('Refusing to delete: an active owner appeared during validation.');
        }
        $ref->delete();
    }

    echo json_encode([
        'success' => true,
        'dry_run' => !$apply,
        'location_id' => $locationId,
        'stale_owner_user_id' => $ownerUserId !== '' ? $ownerUserId : null,
        'action' => $apply ? 'stale_owner_lock_deleted' : 'stale_owner_lock_would_be_deleted',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Stale owner cleanup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
