<?php

class LocationUserResolutionException extends \RuntimeException
{
}

class LocationUserResolver
{
    public static function locationDocId(string $locationId): string
    {
        return 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    }

    public static function userMatchesLocation(array $data, string $locationId): bool
    {
        $accepted = [$locationId, self::locationDocId($locationId)];
        $storedLocations = [];
        foreach (['active_location_id', 'location_id', 'ghl_location_id'] as $field) {
            $stored = trim((string)($data[$field] ?? ''));
            if ($stored !== '') {
                $storedLocations[] = $stored;
            }
        }

        $tokenRef = trim((string)($data['ghl_token_ref'] ?? ''));
        if ($tokenRef !== '') {
            if (!preg_match('#^ghl_tokens/([^/]+)$#', $tokenRef, $matches)) {
                return false;
            }
            $storedLocations[] = $matches[1];
        }

        if ($storedLocations === []) {
            return false;
        }

        foreach ($storedLocations as $stored) {
            if (!in_array($stored, $accepted, true)) {
                return false;
            }
        }

        return true;
    }

    public static function isEligibleUser(array $data, string $locationId): bool
    {
        $isActive = !empty($data['active']);
        $role = strtolower(trim((string)($data['role'] ?? 'user')));

        return $isActive && $role !== 'agency' && self::userMatchesLocation($data, $locationId);
    }

    /**
     * @param array<int,array{id:string,data:array}> $matches
     * @return array<string,array{id:string,data:array}>
     */
    public static function deduplicateMatches(array $matches): array
    {
        $unique = [];
        foreach ($matches as $match) {
            $id = trim((string)($match['id'] ?? ''));
            if ($id !== '' && isset($match['data']) && is_array($match['data'])) {
                $unique[$id] = ['id' => $id, 'data' => $match['data']];
            }
        }
        return $unique;
    }

    public static function find($db, string $locationId): ?array
    {
        $ownerSnap = $db->collection('location_owners')->document($locationId)->snapshot();
        if ($ownerSnap->exists()) {
            $ownerData = $ownerSnap->data();
            $ownerId = trim((string)(
                $ownerData['owner_user_id']
                ?? $ownerData['owner_uid']
                ?? $ownerData['user_id']
                ?? $ownerData['uid']
                ?? ''
            ));

            if ($ownerId === '') {
                throw new LocationUserResolutionException('Canonical location owner is missing a user reference.');
            }

            $userSnap = $db->collection('users')->document($ownerId)->snapshot();
            if (!$userSnap->exists()) {
                throw new LocationUserResolutionException('Canonical location owner user does not exist.');
            }

            $userData = $userSnap->data();
            if (!is_array($userData) || !self::isEligibleUser($userData, $locationId)) {
                throw new LocationUserResolutionException('Canonical location owner is inactive or linked to another location.');
            }

            return ['id' => $ownerId, 'data' => $userData, 'source' => 'location_owners'];
        }

        $matches = [];
        $candidates = array_values(array_unique([$locationId, self::locationDocId($locationId)]));
        foreach (['active_location_id', 'location_id', 'ghl_location_id'] as $field) {
            foreach ($candidates as $candidate) {
                foreach ($db->collection('users')->where($field, '=', $candidate)->limit(20)->documents() as $doc) {
                    if (!$doc->exists()) {
                        continue;
                    }
                    $data = $doc->data();
                    if (is_array($data) && self::isEligibleUser($data, $locationId)) {
                        $matches[] = ['id' => $doc->id(), 'data' => $data];
                    }
                }
            }
        }

        foreach ($db->collection('users')
            ->where('ghl_token_ref', '=', 'ghl_tokens/' . $locationId)
            ->limit(20)
            ->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            if (is_array($data) && self::isEligibleUser($data, $locationId)) {
                $matches[] = ['id' => $doc->id(), 'data' => $data];
            }
        }

        $unique = self::deduplicateMatches($matches);
        if (count($unique) > 1) {
            throw new LocationUserResolutionException('Multiple users are linked to this location.');
        }

        return count($unique) === 1 ? array_values($unique)[0] + ['source' => 'legacy_user_fields'] : null;
    }
}
