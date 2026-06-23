<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the CLI.\n");
    exit(1);
}

$locationId = trim((string)($argv[1] ?? ''));
$ownerUserId = trim((string)($argv[2] ?? ''));
$apply = in_array('--apply', $argv, true);

if ($locationId === '' || $ownerUserId === '' || in_array($locationId, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php scripts/repair_location_owner.php <location_id> <owner_user_id> [--apply]\n");
    fwrite(STDERR, "Dry-run is default. Pass --apply to write location_owners/{location_id}.\n");
    exit(($locationId === '' || $ownerUserId === '') ? 1 : 0);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/LocationUserResolver.php';

try {
    $db = get_firestore();
    $userSnap = $db->collection('users')->document($ownerUserId)->snapshot();
    if (!$userSnap->exists()) {
        throw new RuntimeException("User {$ownerUserId} does not exist.");
    }

    $userData = $userSnap->data();
    if (!is_array($userData) || !LocationUserResolver::isEligibleUser($userData, $locationId)) {
        throw new RuntimeException("User {$ownerUserId} is not an active eligible owner for {$locationId}.");
    }

    $payload = [
        'location_id' => $locationId,
        'owner_user_id' => $ownerUserId,
        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
        'repair_source' => 'scripts/repair_location_owner.php',
    ];

    if ($apply) {
        $db->collection('location_owners')->document($locationId)->set($payload, ['merge' => true]);
    }

    echo json_encode([
        'success' => true,
        'dry_run' => !$apply,
        'location_id' => $locationId,
        'owner_user_id' => $ownerUserId,
        'owner_email' => $userData['email'] ?? null,
        'action' => $apply ? 'location_owner_written' : 'validated_only',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'Location owner repair failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
