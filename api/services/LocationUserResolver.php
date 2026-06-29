<?php

class LocationUserResolutionException extends \RuntimeException
{
}

class LocationUserNotLinkedException extends \RuntimeException
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
        return self::isActiveNonAgencyUser($data)
            && self::userMatchesLocation($data, $locationId);
    }

    public static function isActiveNonAgencyUser(array $data): bool
    {
        $isActive = !array_key_exists('active', $data) || !empty($data['active']);
        $role = strtolower(trim((string)($data['role'] ?? 'user')));

        return $isActive && $role !== 'agency';
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

    /**
     * @param array<string,array{id:string,data:array}> $matches
     * @return array{id:string,data:array}|null
     */
    public static function chooseCanonicalMatch(array $matches): ?array
    {
        if ($matches === []) {
            return null;
        }

        uasort($matches, static function (array $a, array $b): int {
            $aData = is_array($a['data'] ?? null) ? $a['data'] : [];
            $bData = is_array($b['data'] ?? null) ? $b['data'] : [];
            $aKey = [self::createdSortValue($aData), trim((string)($a['id'] ?? ''))];
            $bKey = [self::createdSortValue($bData), trim((string)($b['id'] ?? ''))];

            return $aKey <=> $bKey;
        });

        $first = reset($matches);
        return is_array($first) ? $first : null;
    }

    private static function createdSortValue(array $data): int
    {
        foreach (['registered_at', 'registeredAt', 'created_at', 'createdAt', 'created'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            try {
                if ($value instanceof \Google\Cloud\Core\Timestamp) {
                    return $value->get()->getTimestamp();
                }
                if ($value instanceof \DateTimeInterface) {
                    return $value->getTimestamp();
                }
                if (is_int($value)) {
                    return $value;
                }
                if (is_float($value)) {
                    return (int)$value;
                }
                if (is_string($value) && trim($value) !== '') {
                    $parsed = strtotime($value);
                    if ($parsed !== false) {
                        return (int)$parsed;
                    }
                }
            } catch (\Throwable $ignored) {
            }
        }

        return PHP_INT_MAX;
    }

    /**
     * @param array{id:string,data:array} $chosen
     * @param array<string,array{id:string,data:array}> $matches
     */
    private static function backfillCanonicalOwner($db, string $locationId, array $chosen, array $matches): void
    {
        $ownerId = trim((string)($chosen['id'] ?? ''));
        if ($ownerId === '') {
            return;
        }

        $data = is_array($chosen['data'] ?? null) ? $chosen['data'] : [];
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $name = trim((string)(
            $data['name']
            ?? $data['full_name']
            ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? ''))
        ));
        $now = new \DateTimeImmutable();
        $payload = [
            'entity_id' => $locationId,
            'location_id' => $locationId,
            'owner_user_id' => $ownerId,
            'owner_uid' => $ownerId,
            'owner_email' => $email,
            'owner_name' => $name,
            'source' => 'LocationUserResolver:first_registered',
            'legacy_match_count' => count($matches),
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ];

        try {
            $ref = $db->collection('location_owners')->document($locationId);
            $snap = $ref->snapshot();
            if (!$snap->exists()) {
                $ref->set($payload + ['created_at' => new \Google\Cloud\Core\Timestamp($now)], ['merge' => true]);
            }

            foreach ($matches as $match) {
                $memberId = trim((string)($match['id'] ?? ''));
                if ($memberId === '' || $memberId === $ownerId) {
                    continue;
                }
                $memberData = is_array($match['data'] ?? null) ? $match['data'] : [];
                $memberEmail = strtolower(trim((string)($memberData['email'] ?? '')));
                $memberName = trim((string)(
                    $memberData['name']
                    ?? $memberData['full_name']
                    ?? trim((string)($memberData['firstName'] ?? '') . ' ' . (string)($memberData['lastName'] ?? ''))
                ));
                $ref->collection('members')->document($memberId)->set([
                    'entity_id' => $locationId,
                    'location_id' => $locationId,
                    'user_id' => $memberId,
                    'owner_user_id' => $memberId,
                    'email' => $memberEmail,
                    'owner_email' => $memberEmail,
                    'name' => $memberName,
                    'owner_name' => $memberName,
                    'is_additional_location_member' => true,
                    'source' => 'LocationUserResolver:first_registered_member',
                    'updated_at' => new \Google\Cloud\Core\Timestamp($now),
                ], ['merge' => true]);
            }
        } catch (\Throwable $e) {
            error_log('[LocationUserResolver] canonical owner backfill failed for ' . $locationId . ': ' . $e->getMessage());
        }
    }

    public static function createOrUpdateLink($db, string $locationId, string $userId, ?string $ghlUserId, ?string $emailNormalized): void
    {
        $now = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());
        
        if ($ghlUserId !== null && trim($ghlUserId) !== '') {
            $ghlUserId = trim($ghlUserId);
            $ghlUserHash = hash('sha256', $ghlUserId);
            $linkId = $locationId . '_ghl_user_' . $ghlUserHash;
            $db->collection('location_user_links')->document($linkId)->set([
                'location_id' => $locationId,
                'user_id' => $userId,
                'identity_type' => 'ghl_user_id',
                'identity_value_hash' => $ghlUserHash,
                'ghl_user_id' => $ghlUserId,
                'email_normalized' => $emailNormalized,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['merge' => true]);
        }
        
        if ($emailNormalized !== null && trim($emailNormalized) !== '') {
            $emailNormalized = strtolower(trim($emailNormalized));
            $emailHash = hash('sha256', $emailNormalized);
            $linkId = $locationId . '_email_' . $emailHash;
            $db->collection('location_user_links')->document($linkId)->set([
                'location_id' => $locationId,
                'user_id' => $userId,
                'identity_type' => 'email',
                'identity_value_hash' => $emailHash,
                'ghl_user_id' => $ghlUserId,
                'email_normalized' => $emailNormalized,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['merge' => true]);
        }
    }

    public static function findForIframeIdentity($db, string $locationId, ?string $ghlUserId, ?string $email): ?array
    {
        $ghlUserId = trim((string)$ghlUserId);
        $email = trim((string)$email);
        $emailNormalized = $email !== '' ? strtolower($email) : null;

        // 1. If ghl_user_id is present, look up active location_user_links by location_id + ghl_user_id.
        if ($ghlUserId !== '') {
            $ghlUserHash = hash('sha256', $ghlUserId);
            $linkId = $locationId . '_ghl_user_' . $ghlUserHash;
            $linkSnap = $db->collection('location_user_links')->document($linkId)->snapshot();
            if ($linkSnap->exists()) {
                $linkData = $linkSnap->data();
                if (!empty($linkData['active'])) {
                    $userId = trim((string)($linkData['user_id'] ?? ''));
                    if ($userId !== '') {
                        $userSnap = $db->collection('users')->document($userId)->snapshot();
                        if ($userSnap->exists()) {
                            $userData = $userSnap->data();
                            if (is_array($userData) && self::isActiveNonAgencyUser($userData)) {
                                return ['id' => $userId, 'data' => $userData, 'source' => 'location_user_links_ghl_user'];
                            }
                        }
                    }
                }
            }
        }

        // 2. If no match and email is present, look up active location_user_links by location_id + normalized email.
        if ($emailNormalized !== null && $emailNormalized !== '') {
            $emailHash = hash('sha256', $emailNormalized);
            $linkId = $locationId . '_email_' . $emailHash;
            $linkSnap = $db->collection('location_user_links')->document($linkId)->snapshot();
            if ($linkSnap->exists()) {
                $linkData = $linkSnap->data();
                if (!empty($linkData['active'])) {
                    $userId = trim((string)($linkData['user_id'] ?? ''));
                    if ($userId !== '') {
                        $userSnap = $db->collection('users')->document($userId)->snapshot();
                        if ($userSnap->exists()) {
                            $userData = $userSnap->data();
                            if (is_array($userData) && self::isActiveNonAgencyUser($userData)) {
                                return ['id' => $userId, 'data' => $userData, 'source' => 'location_user_links_email'];
                            }
                        }
                    }
                }
            }
        }

        // 3. If no link exists but exactly one eligible user in this location has the same normalized email, create/backfill the link and return that user.
        if ($emailNormalized !== null && $emailNormalized !== '') {
            $matchedUsers = [];
            
            // Query 1: email == $emailNormalized
            foreach ($db->collection('users')->where('email', '=', $emailNormalized)->documents() as $doc) {
                if ($doc->exists()) {
                    $matchedUsers[$doc->id()] = $doc->data();
                }
            }
            // Query 2: email_normalized == $emailNormalized
            foreach ($db->collection('users')->where('email_normalized', '=', $emailNormalized)->documents() as $doc) {
                if ($doc->exists()) {
                    $matchedUsers[$doc->id()] = $doc->data();
                }
            }

            // Filter by isEligibleUser
            $eligibleUsers = [];
            foreach ($matchedUsers as $id => $data) {
                if (self::isEligibleUser($data, $locationId)) {
                    $eligibleUsers[$id] = $data;
                }
            }

            if (count($eligibleUsers) === 1) {
                $userId = array_key_first($eligibleUsers);
                $userData = $eligibleUsers[$userId];
                
                // Create/backfill links
                self::createOrUpdateLink($db, $locationId, $userId, $ghlUserId, $emailNormalized);
                
                return ['id' => $userId, 'data' => $userData, 'source' => 'legacy_email_backfill'];
            } elseif (count($eligibleUsers) > 1) {
                throw new LocationUserResolutionException('Multiple users match the normalized email identity.');
            }
        }

        // 4. If no identity is present, fall back to the admin-selected canonical owner in location_owners/{locationId}.
        if ($ghlUserId === '' && ($emailNormalized === null || $emailNormalized === '')) {
            return self::find($db, $locationId);
        }

        // Identity is present but not linked/found.
        throw new LocationUserNotLinkedException('Location user not linked for the provided identity.');
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
            if (!is_array($userData) || !self::isActiveNonAgencyUser($userData)) {
                throw new LocationUserResolutionException('Canonical location owner is inactive or invalid.');
            }

            $ownerSource = trim((string)($ownerData['source'] ?? $ownerData['repair_source'] ?? ''));
            $adminSelectedOwner = in_array($ownerSource, [
                'admin_selected_default_autologin',
                'manual_repair_default_autologin',
                'api/admin/location_owner.php',
                'scripts/repair_location_owner.php',
            ], true);

            $memberLinked = false;
            try {
                $memberLinked = $db->collection('location_owners')
                    ->document($locationId)
                    ->collection('members')
                    ->document($ownerId)
                    ->snapshot()
                    ->exists();
            } catch (\Throwable $ignored) {
            }

            if (!self::userMatchesLocation($userData, $locationId) && !$memberLinked && !$adminSelectedOwner) {
                throw new LocationUserResolutionException('Canonical location owner is not linked to this location.');
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
            throw new LocationUserResolutionException('Multiple eligible location users exist without an admin-selected default owner.');
        }

        if (count($unique) === 1) {
            $chosen = array_values($unique)[0];
            self::backfillCanonicalOwner($db, $locationId, $chosen, $unique);

            return $chosen + ['source' => 'legacy_user_fields'];
        }

        return null;
    }
}
