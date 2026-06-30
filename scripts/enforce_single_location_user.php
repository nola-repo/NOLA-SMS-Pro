<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the CLI.\n");
    exit(1);
}

$locationId = trim((string)($argv[1] ?? ''));
$requestedOwnerId = trim((string)($argv[2] ?? ''));
$apply = in_array('--apply', $argv, true);

if ($locationId === '' || $requestedOwnerId === '' || in_array($locationId, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php scripts/enforce_single_location_user.php <location_id> <owner_user_id> [--apply]\n");
    fwrite(STDERR, "Dry-run is the default. The owner user id must be chosen explicitly.\n");
    exit(($locationId === '' || $requestedOwnerId === '') ? 1 : 0);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/LocationUserResolver.php';

function single_user_email(array $data): string
{
    return strtolower(trim((string)($data['email'] ?? '')));
}

function single_user_name(array $data): string
{
    return trim((string)(
        $data['name']
        ?? $data['full_name']
        ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? ''))
    ));
}

try {
    $db = get_firestore();
    $ownerSnap = $db->collection('users')->document($requestedOwnerId)->snapshot();
    if (!$ownerSnap->exists()) {
        throw new RuntimeException("Owner user {$requestedOwnerId} does not exist.");
    }

    $ownerData = $ownerSnap->data();
    if (!is_array($ownerData) || !LocationUserResolver::isActiveNonAgencyUser($ownerData)) {
        throw new RuntimeException("Owner user {$requestedOwnerId} is inactive or invalid.");
    }

    foreach ($db->collection('location_owners')->where('owner_user_id', '=', $requestedOwnerId)->documents() as $ownedLocation) {
        if ($ownedLocation->exists() && $ownedLocation->id() !== $locationId) {
            throw new RuntimeException("Owner user {$requestedOwnerId} already owns location {$ownedLocation->id()}.");
        }
    }

    $users = [$requestedOwnerId => $ownerSnap];
    $locationCandidates = array_values(array_unique([$locationId, LocationUserResolver::locationDocId($locationId)]));
    foreach (['active_location_id', 'location_id', 'ghl_location_id'] as $field) {
        foreach ($locationCandidates as $candidate) {
            foreach ($db->collection('users')->where($field, '=', $candidate)->documents() as $userDoc) {
                if ($userDoc->exists()) {
                    $users[$userDoc->id()] = $userDoc;
                }
            }
        }
    }
    foreach ($db->collection('users')->where('ghl_token_ref', '=', 'ghl_tokens/' . $locationId)->documents() as $userDoc) {
        if ($userDoc->exists()) {
            $users[$userDoc->id()] = $userDoc;
        }
    }

    $ownerRef = $db->collection('location_owners')->document($locationId);
    $memberDocs = [];
    foreach ($ownerRef->collection('members')->documents() as $memberDoc) {
        if (!$memberDoc->exists()) {
            continue;
        }
        $memberDocs[$memberDoc->id()] = $memberDoc;
        if (!isset($users[$memberDoc->id()])) {
            $memberUser = $db->collection('users')->document($memberDoc->id())->snapshot();
            if ($memberUser->exists()) {
                $users[$memberDoc->id()] = $memberUser;
            }
        }
    }

    $linkDocs = [];
    foreach ($db->collection('location_user_links')->where('location_id', '=', $locationId)->documents() as $linkDoc) {
        if ($linkDoc->exists()) {
            $linkDocs[$linkDoc->id()] = $linkDoc;
        }
    }

    $duplicateUserIds = array_values(array_filter(
        array_keys($users),
        static fn(string $userId): bool => $userId !== $requestedOwnerId
    ));

    $summary = [
        'success' => true,
        'dry_run' => !$apply,
        'location_id' => $locationId,
        'owner_user_id' => $requestedOwnerId,
        'owner_email' => single_user_email($ownerData),
        'duplicate_user_ids_to_deactivate' => $duplicateUserIds,
        'member_documents_to_delete' => array_keys($memberDocs),
        'identity_link_documents_to_delete' => array_keys($linkDocs),
    ];

    if (!$apply) {
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $now = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());
    $ownerRef->set([
        'entity_id' => $locationId,
        'location_id' => $locationId,
        'owner_user_id' => $requestedOwnerId,
        'owner_uid' => $requestedOwnerId,
        'owner_email' => single_user_email($ownerData),
        'owner_name' => single_user_name($ownerData),
        'source' => 'single_user_location_migration',
        'updated_at' => $now,
    ], ['merge' => true]);

    $db->collection('users')->document($requestedOwnerId)->set([
        'active' => true,
        'active_location_id' => $locationId,
        'location_id' => $locationId,
        'ghl_token_ref' => 'ghl_tokens/' . $locationId,
        'updated_at' => $now,
    ], ['merge' => true]);

    foreach ($duplicateUserIds as $duplicateUserId) {
        $db->collection('users')->document($duplicateUserId)->set([
            'active' => false,
            'active_location_id' => \Google\Cloud\Firestore\FieldValue::deleteField(),
            'location_id' => \Google\Cloud\Firestore\FieldValue::deleteField(),
            'ghl_location_id' => \Google\Cloud\Firestore\FieldValue::deleteField(),
            'ghl_token_ref' => \Google\Cloud\Firestore\FieldValue::deleteField(),
            'deactivated_reason' => 'single_user_location_migration',
            'deactivated_location_id' => $locationId,
            'updated_at' => $now,
        ], ['merge' => true]);

        $subaccountRef = $db->collection('users')->document($duplicateUserId)->collection('subaccounts')->document($locationId);
        if ($subaccountRef->snapshot()->exists()) {
            $subaccountRef->delete();
        }
    }

    foreach ($memberDocs as $memberDoc) {
        $memberDoc->reference()->delete();
    }
    foreach ($linkDocs as $linkDoc) {
        $linkDoc->reference()->delete();
    }

    $summary['action'] = 'single_user_location_enforced';
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'Single-user migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
