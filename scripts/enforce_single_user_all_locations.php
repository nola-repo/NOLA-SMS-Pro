<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the CLI.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/LocationUserResolver.php';

function one_to_one_location_ids(array $data): array
{
    $ids = [];
    foreach (['active_location_id', 'location_id', 'ghl_location_id'] as $field) {
        $value = trim((string)($data[$field] ?? ''));
        if ($value !== '') {
            $ids[] = preg_replace('/^ghl_/', '', $value);
        }
    }
    $tokenRef = trim((string)($data['ghl_token_ref'] ?? ''));
    if (preg_match('#^ghl_tokens/([^/]+)$#', $tokenRef, $matches)) {
        $ids[] = $matches[1];
    }
    return array_values(array_unique(array_filter($ids)));
}

function one_to_one_location(array &$locations, string $locationId): void
{
    if ($locationId === '') {
        return;
    }
    if (!isset($locations[$locationId])) {
        $locations[$locationId] = [
            'owner_doc' => null,
            'owner_data' => [],
            'members' => [],
            'links' => [],
            'candidate_ids' => [],
        ];
    }
}

function one_to_one_owner_id(array $data): string
{
    return trim((string)($data['owner_user_id'] ?? $data['owner_uid'] ?? $data['user_id'] ?? $data['uid'] ?? ''));
}

function one_to_one_email(array $data): string
{
    return strtolower(trim((string)($data['email'] ?? '')));
}

function one_to_one_name(array $data): string
{
    return trim((string)($data['name'] ?? $data['full_name'] ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? ''))));
}

try {
    $db = get_firestore();
    $locations = [];
    $users = [];

    foreach ($db->collection('users')->documents() as $userDoc) {
        if (!$userDoc->exists()) {
            continue;
        }
        $data = $userDoc->data();
        if (!is_array($data)) {
            continue;
        }
        $users[$userDoc->id()] = ['snapshot' => $userDoc, 'data' => $data];
        foreach (one_to_one_location_ids($data) as $locationId) {
            one_to_one_location($locations, $locationId);
            $locations[$locationId]['candidate_ids'][$userDoc->id()] = true;
        }
    }

    foreach ($db->collection('location_owners')->documents() as $ownerDoc) {
        if (!$ownerDoc->exists()) {
            continue;
        }
        $locationId = $ownerDoc->id();
        one_to_one_location($locations, $locationId);
        $data = $ownerDoc->data();
        $locations[$locationId]['owner_doc'] = $ownerDoc;
        $locations[$locationId]['owner_data'] = is_array($data) ? $data : [];
        $ownerId = one_to_one_owner_id($locations[$locationId]['owner_data']);
        if ($ownerId !== '') {
            $locations[$locationId]['candidate_ids'][$ownerId] = true;
        }
    }

    foreach ($db->collectionGroup('members')->documents() as $memberDoc) {
        if (!$memberDoc->exists()) {
            continue;
        }
        $path = $memberDoc->path();
        if (!preg_match('#^location_owners/([^/]+)/members/([^/]+)$#', $path, $matches)) {
            continue;
        }
        $locationId = $matches[1];
        $userId = $matches[2];
        one_to_one_location($locations, $locationId);
        $locations[$locationId]['members'][$memberDoc->id()] = $memberDoc;
        $locations[$locationId]['candidate_ids'][$userId] = true;
    }

    foreach ($db->collection('location_user_links')->documents() as $linkDoc) {
        if (!$linkDoc->exists()) {
            continue;
        }
        $data = $linkDoc->data();
        $locationId = trim((string)($data['location_id'] ?? ''));
        $userId = trim((string)($data['user_id'] ?? ''));
        if ($locationId === '') {
            continue;
        }
        one_to_one_location($locations, $locationId);
        $locations[$locationId]['links'][$linkDoc->id()] = $linkDoc;
        if ($userId !== '') {
            $locations[$locationId]['candidate_ids'][$userId] = true;
        }
    }

    ksort($locations);
    $plans = [];
    $canonicalLocationsByUser = [];
    foreach ($locations as $locationId => $row) {
        $existingOwnerId = one_to_one_owner_id($row['owner_data']);
        $canonicalId = '';
        if ($existingOwnerId !== '' && isset($users[$existingOwnerId]) && LocationUserResolver::isActiveNonAgencyUser($users[$existingOwnerId]['data'])) {
            $canonicalId = $existingOwnerId;
        }

        $validCandidates = [];
        foreach (array_keys($row['candidate_ids']) as $userId) {
            if (isset($users[$userId]) && LocationUserResolver::isActiveNonAgencyUser($users[$userId]['data'])) {
                $validCandidates[$userId] = ['id' => $userId, 'data' => $users[$userId]['data']];
            }
        }
        if ($canonicalId === '') {
            $chosen = LocationUserResolver::chooseCanonicalMatch($validCandidates);
            $canonicalId = trim((string)($chosen['id'] ?? ''));
        }
        if ($canonicalId !== '') {
            $canonicalLocationsByUser[$canonicalId][] = $locationId;
        }

        $nonCanonicalIds = array_values(array_filter(
            array_keys($validCandidates),
            static fn(string $userId): bool => $userId !== $canonicalId
        ));
        $needsRepair = $canonicalId === ''
            || $existingOwnerId !== $canonicalId
            || count($nonCanonicalIds) > 0
            || count($row['members']) > 0
            || count($row['links']) > 0;

        if ($needsRepair) {
            $plans[$locationId] = [
                'location_id' => $locationId,
                'canonical_user_id' => $canonicalId !== '' ? $canonicalId : null,
                'canonical_email' => $canonicalId !== '' ? one_to_one_email($users[$canonicalId]['data']) : null,
                'previous_owner_user_id' => $existingOwnerId !== '' ? $existingOwnerId : null,
                'noncanonical_user_ids' => $nonCanonicalIds,
                'member_document_count' => count($row['members']),
                'identity_link_count' => count($row['links']),
                'repairable' => $canonicalId !== '',
                '_row' => $row,
            ];
        }
    }

    $publicPlans = [];
    foreach ($plans as $plan) {
        $public = $plan;
        unset($public['_row']);
        $publicPlans[] = $public;
    }
    $summary = [
        'success' => true,
        'dry_run' => !$apply,
        'scanned_location_count' => count($locations),
        'repair_location_count' => count($plans),
        'repairable_location_count' => count(array_filter($plans, static fn(array $plan): bool => $plan['repairable'])),
        'blocked_location_count' => count(array_filter($plans, static fn(array $plan): bool => !$plan['repairable'])),
        'locations' => $publicPlans,
    ];

    if (!$apply) {
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $now = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());
    $applied = [];
    $blocked = [];
    foreach ($plans as $locationId => $plan) {
        if (!$plan['repairable']) {
            $blocked[] = $locationId;
            continue;
        }
        $canonicalId = $plan['canonical_user_id'];
        if (count($canonicalLocationsByUser[$canonicalId] ?? []) > 1) {
            $blocked[] = $locationId;
            continue;
        }

        $canonicalData = $users[$canonicalId]['data'];
        $ownerRef = $db->collection('location_owners')->document($locationId);
        $ownerRef->set([
            'entity_id' => $locationId,
            'location_id' => $locationId,
            'owner_user_id' => $canonicalId,
            'owner_uid' => $canonicalId,
            'owner_email' => one_to_one_email($canonicalData),
            'owner_name' => one_to_one_name($canonicalData),
            'source' => 'single_user_global_migration',
            'updated_at' => $now,
        ], ['merge' => true]);

        $db->collection('users')->document($canonicalId)->set([
            'active' => true,
            'active_location_id' => $locationId,
            'location_id' => $locationId,
            'ghl_token_ref' => 'ghl_tokens/' . $locationId,
            'updated_at' => $now,
        ], ['merge' => true]);

        foreach ($plan['noncanonical_user_ids'] as $userId) {
            if (!empty($canonicalLocationsByUser[$userId])) {
                continue;
            }
            $db->collection('users')->document($userId)->set([
                'active' => false,
                'active_location_id' => \Google\Cloud\Firestore\FieldValue::deleteField(),
                'location_id' => \Google\Cloud\Firestore\FieldValue::deleteField(),
                'ghl_location_id' => \Google\Cloud\Firestore\FieldValue::deleteField(),
                'ghl_token_ref' => \Google\Cloud\Firestore\FieldValue::deleteField(),
                'deactivated_reason' => 'single_user_global_migration',
                'deactivated_location_id' => $locationId,
                'updated_at' => $now,
            ], ['merge' => true]);
            $subaccountRef = $db->collection('users')->document($userId)->collection('subaccounts')->document($locationId);
            if ($subaccountRef->snapshot()->exists()) {
                $subaccountRef->delete();
            }
        }

        foreach ($plan['_row']['members'] as $memberDoc) {
            $memberDoc->reference()->delete();
        }
        foreach ($plan['_row']['links'] as $linkDoc) {
            $linkDoc->reference()->delete();
        }
        $applied[] = $locationId;
    }

    $summary['dry_run'] = false;
    $summary['applied_locations'] = $applied;
    $summary['blocked_locations'] = $blocked;
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'Global single-user migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
