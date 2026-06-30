<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the CLI.\n");
    exit(1);
}

$locationId = trim((string)($argv[1] ?? ''));
if ($locationId === '' || in_array($locationId, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php scripts/audit_location_users.php <location_id>\n");
    exit($locationId === '' ? 1 : 0);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/LocationUserResolver.php';

function audit_timestamp($value): ?string
{
    try {
        if ($value instanceof \Google\Cloud\Core\Timestamp) {
            return $value->get()->format(DATE_ATOM);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    } catch (\Throwable $ignored) {
    }
    return null;
}

try {
    $db = get_firestore();
    $matches = [];
    $candidates = array_values(array_unique([
        $locationId,
        LocationUserResolver::locationDocId($locationId),
    ]));

    foreach (['active_location_id', 'location_id', 'ghl_location_id'] as $field) {
        foreach ($candidates as $candidate) {
            foreach ($db->collection('users')->where($field, '=', $candidate)->documents() as $doc) {
                if ($doc->exists()) {
                    $matches[] = ['id' => $doc->id(), 'data' => $doc->data()];
                }
            }
        }
    }

    foreach ($db->collection('users')
        ->where('ghl_token_ref', '=', 'ghl_tokens/' . $locationId)
        ->documents() as $doc) {
        if ($doc->exists()) {
            $matches[] = ['id' => $doc->id(), 'data' => $doc->data()];
        }
    }

    $unique = LocationUserResolver::deduplicateMatches($matches);
    $ownerSnap = $db->collection('location_owners')->document($locationId)->snapshot();
    $ownerData = $ownerSnap->exists() ? $ownerSnap->data() : [];
    $ownerId = trim((string)(
        $ownerData['owner_user_id']
        ?? $ownerData['owner_uid']
        ?? $ownerData['user_id']
        ?? $ownerData['uid']
        ?? ''
    ));

    $users = [];
    foreach ($unique as $id => $match) {
        $data = $match['data'];
        $users[] = [
            'id' => $id,
            'email' => strtolower(trim((string)($data['email'] ?? ''))),
            'role' => $data['role'] ?? 'user',
            'active' => !empty($data['active']),
            'eligible_for_autologin' => LocationUserResolver::isEligibleUser($data, $locationId),
            'is_canonical_owner' => $ownerId !== '' && $ownerId === $id,
            'active_location_id' => $data['active_location_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'ghl_location_id' => $data['ghl_location_id'] ?? null,
            'company_id' => $data['company_id'] ?? null,
            'ghl_token_ref' => $data['ghl_token_ref'] ?? null,
            'source' => $data['source'] ?? null,
            'created_at' => audit_timestamp($data['created_at'] ?? $data['createdAt'] ?? null),
            'updated_at' => audit_timestamp($data['updated_at'] ?? $data['updatedAt'] ?? null),
        ];
    }

    $eligible = array_values(array_filter($users, static fn(array $user): bool => $user['eligible_for_autologin']));
    $classification = 'healthy';
    if (!$ownerSnap->exists()) {
        $classification = count($eligible) > 1 ? 'legacy_multiple_users_require_migration' : 'missing_owner_record';
    } elseif ($ownerId === '' || !isset($unique[$ownerId]) || !LocationUserResolver::isEligibleUser($unique[$ownerId]['data'], $locationId)) {
        $classification = 'stale_owner_record';
    } elseif (count($eligible) > 1) {
        $classification = 'legacy_multiple_users_require_migration';
    }

    echo json_encode([
        'dry_run' => true,
        'location_id' => $locationId,
        'classification' => $classification,
        'canonical_owner_user_id' => $ownerId !== '' ? $ownerId : null,
        'matched_user_count' => count($users),
        'eligible_user_count' => count($eligible),
        'users' => $users,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'Location audit failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
