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

function repair_location_owner_clean($value): string
{
    return trim((string)($value ?? ''));
}

function repair_location_owner_email($value): string
{
    return strtolower(trim((string)($value ?? '')));
}

function repair_location_owner_name(array $data): string
{
    return trim((string)(
        $data['name']
        ?? $data['full_name']
        ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? ''))
    ));
}

function repair_location_owner_user_linked($db, string $locationId, string $userId, array $userData): bool
{
    if (LocationUserResolver::isEligibleUser($userData, $locationId)) {
        return true;
    }

    try {
        if ($db->collection('location_owners')->document($locationId)->collection('members')->document($userId)->snapshot()->exists()) {
            return true;
        }
    } catch (Throwable $ignored) {
    }

    try {
        if ($db->collection('users')->document($userId)->collection('subaccounts')->document($locationId)->snapshot()->exists()) {
            return true;
        }
        foreach (['location_id', 'locationId', 'entity_id', 'id'] as $field) {
            foreach ($db->collection('users')->document($userId)->collection('subaccounts')->where($field, '=', $locationId)->limit(1)->documents() as $doc) {
                if ($doc->exists()) {
                    return true;
                }
            }
        }
    } catch (Throwable $ignored) {
    }

    return false;
}

try {
    $db = get_firestore();
    $userSnap = $db->collection('users')->document($ownerUserId)->snapshot();
    if (!$userSnap->exists()) {
        throw new RuntimeException("User {$ownerUserId} does not exist.");
    }

    $userData = $userSnap->data();
    if (!is_array($userData) || !LocationUserResolver::isActiveNonAgencyUser($userData)) {
        throw new RuntimeException("User {$ownerUserId} is inactive or not a subaccount user.");
    }

    if (!repair_location_owner_user_linked($db, $locationId, $ownerUserId, $userData)) {
        throw new RuntimeException("User {$ownerUserId} is not linked to {$locationId}.");
    }

    $ownerRef = $db->collection('location_owners')->document($locationId);
    $previousSnap = $ownerRef->snapshot();
    $previousData = $previousSnap->exists() ? $previousSnap->data() : [];
    $previousOwnerId = repair_location_owner_clean(
        $previousData['owner_user_id']
        ?? $previousData['owner_uid']
        ?? $previousData['user_id']
        ?? $previousData['uid']
        ?? ''
    );
    $now = new \DateTimeImmutable();

    $payload = [
        'entity_id' => $locationId,
        'location_id' => $locationId,
        'owner_user_id' => $ownerUserId,
        'owner_uid' => $ownerUserId,
        'owner_email' => repair_location_owner_email($userData['email'] ?? ''),
        'owner_name' => repair_location_owner_name($userData),
        'previous_owner_user_id' => $previousOwnerId !== '' && $previousOwnerId !== $ownerUserId ? $previousOwnerId : null,
        'source' => 'manual_repair_default_autologin',
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        'repair_source' => 'scripts/repair_location_owner.php',
    ];
    if (!$previousSnap->exists()) {
        $payload['created_at'] = new \Google\Cloud\Core\Timestamp($now);
    }

    if ($apply) {
        $ownerRef->set($payload, ['merge' => true]);
        $ownerRef->collection('members')->document($ownerUserId)->set([
            'entity_id' => $locationId,
            'location_id' => $locationId,
            'user_id' => $ownerUserId,
            'email' => repair_location_owner_email($userData['email'] ?? ''),
            'name' => repair_location_owner_name($userData),
            'active' => true,
            'is_default_autologin_account' => true,
            'source' => 'manual_repair_default_autologin',
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ], ['merge' => true]);
    }

    echo json_encode([
        'success' => true,
        'dry_run' => !$apply,
        'location_id' => $locationId,
        'owner_user_id' => $ownerUserId,
        'owner_email' => $userData['email'] ?? null,
        'previous_owner_user_id' => $payload['previous_owner_user_id'],
        'action' => $apply ? 'location_owner_written' : 'validated_only',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'Location owner repair failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
