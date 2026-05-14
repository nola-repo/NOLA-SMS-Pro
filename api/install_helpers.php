<?php

/**
 * Shared install/reinstall helpers for the GHL Marketplace flow.
 *
 * Keep the callback, registration page, login page, and account API aligned on
 * what the selected GHL location is and whether it is installed vs registered.
 */

if (!defined('INSTALL_STATE_FRESH_INSTALL')) {
    define('INSTALL_STATE_FRESH_INSTALL', 'FRESH_INSTALL');
    define('INSTALL_STATE_TOKEN_ONLY', 'TOKEN_ONLY');
    define('INSTALL_STATE_LINKED_ACCOUNT', 'LINKED_ACCOUNT');
    define('INSTALL_STATE_AMBIGUOUS', 'AMBIGUOUS');
    define('INSTALL_STATE_COMPANY_MISMATCH', 'COMPANY_MISMATCH');
}

function install_clean_location_id($value): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '' || strpos($value, '{{') !== false) {
        return null;
    }

    return preg_match('/^[A-Za-z0-9_-]{8,}$/', $value) ? $value : null;
}

function install_unique_ids(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $clean = install_clean_location_id($id);
        if ($clean !== null && !in_array($clean, $out, true)) {
            $out[] = $clean;
        }
    }

    return $out;
}

function install_extract_company_name(array $data): string
{
    foreach (['companyName', 'company_name', 'agencyName', 'agency_name'] as $key) {
        if (!isset($data[$key])) {
            continue;
        }

        if (!is_scalar($data[$key])) {
            continue;
        }

        $value = trim((string)$data[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * @return array{ids: array<int,string>, names: array<string,string>}
 */
function install_location_rows_from_ghl($locations): array
{
    $ids = [];
    $names = [];

    if (!is_array($locations)) {
        return ['ids' => [], 'names' => []];
    }

    foreach ($locations as $loc) {
        if (!is_array($loc)) {
            continue;
        }

        $id = install_clean_location_id($loc['id'] ?? $loc['locationId'] ?? $loc['location_id'] ?? null);
        if ($id === null) {
            continue;
        }

        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }

        $name = trim((string)($loc['name'] ?? $loc['location_name'] ?? $loc['locationName'] ?? ''));
        if ($name !== '') {
            $names[$id] = $name;
        }
    }

    return ['ids' => $ids, 'names' => $names];
}

/**
 * Resolve the one selected subaccount from trusted GHL callback signals.
 *
 * Registration state is never a selection signal; only explicit GHL/OAuth or
 * signed install-session signals are allowed to choose a location.
 *
 * @return array{
 *   ok: bool,
 *   location_id: ?string,
 *   source: string,
 *   reason: string,
 *   candidate_ids: array<int,string>,
 *   location_names: array<string,string>,
 *   conflict?: array<string,mixed>
 * }
 */
function install_resolve_selected_location(array $signals): array
{
    $rows = install_location_rows_from_ghl($signals['locations'] ?? []);
    $locationIds = $rows['ids'];
    $locationNames = $rows['names'];

    $approvedIds = install_unique_ids(array_merge(
        is_array($signals['approved_location_ids'] ?? null) ? $signals['approved_location_ids'] : [],
        is_array($signals['query_approved_location_ids'] ?? null) ? $signals['query_approved_location_ids'] : []
    ));

    $candidateIds = install_unique_ids(array_merge($locationIds, $approvedIds));
    $tokenLocationId = install_clean_location_id($signals['token_location_id'] ?? null);
    $queryLocationId = install_clean_location_id($signals['query_location_id'] ?? null);
    $stateLocationId = install_clean_location_id($signals['state_location_id'] ?? null);
    $sessionLocationId = install_clean_location_id($signals['session_location_id'] ?? null);

    $selectedFromList = null;
    $selectedSource = '';

    if (count($locationIds) === 1) {
        $selectedFromList = $locationIds[0];
        $selectedSource = 'locations_single';
    }

    if (count($approvedIds) === 1) {
        $approvedOnly = $approvedIds[0];
        if (empty($locationIds) || in_array($approvedOnly, $locationIds, true)) {
            $selectedFromList = $approvedOnly;
            $selectedSource = 'approved_locations_single';
        } elseif ($selectedFromList !== null && $selectedFromList !== $approvedOnly) {
            return [
                'ok' => false,
                'location_id' => null,
                'source' => 'picker_conflict',
                'reason' => 'locations_single_conflicts_with_approved_locations_single',
                'candidate_ids' => $candidateIds,
                'location_names' => $locationNames,
                'conflict' => [
                    'locations_single' => $selectedFromList,
                    'approved_locations_single' => $approvedOnly,
                ],
            ];
        }
    }

    if ($selectedFromList !== null) {
        $conflict = [];
        foreach ([
            'session_location_id' => $sessionLocationId,
            'query_location_id' => $queryLocationId,
            'state_location_id' => $stateLocationId,
        ] as $key => $directId) {
            if ($directId !== null && $directId !== $selectedFromList) {
                return [
                    'ok' => false,
                    'location_id' => null,
                    'source' => $key,
                    'reason' => 'exact_location_signal_conflicts_with_picker',
                    'candidate_ids' => $candidateIds,
                    'location_names' => $locationNames,
                    'conflict' => [
                        'picker_location_id' => $selectedFromList,
                        $key => $directId,
                    ],
                ];
            }
        }
        if ($tokenLocationId !== null && $tokenLocationId !== $selectedFromList) {
            $conflict['token_location_id'] = $tokenLocationId;
        }

        $result = [
            'ok' => true,
            'location_id' => $selectedFromList,
            'source' => $selectedSource,
            'reason' => empty($conflict) ? 'selected_from_ghl_picker' : 'selected_picker_value_overrode_conflicting_direct_signal',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
        if (!empty($conflict)) {
            $result['conflict'] = $conflict;
        }

        return $result;
    }

    $trustedDirectSignals = [];
    foreach ([
        'signed_install_session' => $sessionLocationId,
        'query_location_field' => $queryLocationId,
        'oauth_state' => $stateLocationId,
        'token_location_field' => $tokenLocationId,
    ] as $source => $directId) {
        if ($directId !== null) {
            $trustedDirectSignals[] = ['source' => $source, 'id' => $directId];
        }
    }

    $nonTokenExact = array_values(array_filter(
        $trustedDirectSignals,
        static fn(array $row): bool => $row['source'] !== 'token_location_field'
    ));

    $selectedDirect = null;
    $selectedDirectSource = '';
    $directConflicts = [];
    foreach (!empty($nonTokenExact) ? $nonTokenExact : $trustedDirectSignals as $row) {
        if ($selectedDirect === null) {
            $selectedDirect = $row['id'];
            $selectedDirectSource = $row['source'];
            continue;
        }

        if ($selectedDirect !== $row['id']) {
            $directConflicts[$row['source']] = $row['id'];
        }
    }

    if (!empty($directConflicts)) {
        $directConflicts[$selectedDirectSource] = $selectedDirect;
        return [
            'ok' => false,
            'location_id' => null,
            'source' => 'direct_signal_conflict',
            'reason' => 'conflicting_exact_location_signals',
            'candidate_ids' => install_unique_ids(array_merge($candidateIds, array_values($directConflicts))),
            'location_names' => $locationNames,
            'conflict' => $directConflicts,
        ];
    }

    if ($selectedDirect !== null) {
        $conflict = [];
        if (!empty($candidateIds) && !in_array($selectedDirect, $candidateIds, true)) {
            return [
                'ok' => false,
                'location_id' => null,
                'source' => $selectedDirectSource,
                'reason' => 'direct_location_conflicts_with_ghl_candidates',
                'candidate_ids' => $candidateIds,
                'location_names' => $locationNames,
                'conflict' => ['direct_location_id' => $selectedDirect],
            ];
        }

        if ($tokenLocationId !== null && $selectedDirectSource !== 'token_location_field' && $tokenLocationId !== $selectedDirect) {
            $conflict['token_location_id'] = $tokenLocationId;
        }

        $result = [
            'ok' => true,
            'location_id' => $selectedDirect,
            'source' => $selectedDirectSource,
            'reason' => 'direct_location_signal',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
        if (!empty($conflict)) {
            $result['conflict'] = $conflict;
        }

        return $result;
    }

    if (count($candidateIds) === 1) {
        return [
            'ok' => true,
            'location_id' => $candidateIds[0],
            'source' => 'single_trusted_candidate',
            'reason' => 'only_one_trusted_candidate',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
    }

    return [
        'ok' => false,
        'location_id' => null,
        'source' => 'unresolved',
        'reason' => count($candidateIds) > 1 ? 'ambiguous_location_candidates' : 'no_location_signal',
        'candidate_ids' => $candidateIds,
        'location_names' => $locationNames,
    ];
}

function install_token_doc_exists($db, string $locationId): bool
{
    $locationId = trim($locationId);
    if ($locationId === '') {
        return false;
    }

    try {
        return $db->collection('ghl_tokens')->document($locationId)->snapshot()->exists();
    } catch (Exception $e) {
        error_log("[install_helpers] token exists check failed for {$locationId}: " . $e->getMessage());
        return false;
    }
}

function install_location_company_mismatch($db, string $locationId, ?string $companyId): bool
{
    $companyId = trim((string)$companyId);
    if ($locationId === '') {
        return false;
    }

    try {
        $snap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
        if (!$snap->exists()) {
            return false;
        }
        $data = $snap->data();
        if (($data['appType'] ?? '') === 'agency') {
            return true;
        }
        if ($companyId === '') {
            return false;
        }
        $storedCompany = trim((string)($data['companyId'] ?? $data['company_id'] ?? ''));
        return $storedCompany !== '' && $storedCompany !== $companyId;
    } catch (Exception $e) {
        error_log("[install_helpers] company mismatch check failed for {$locationId}: " . $e->getMessage());
        return false;
    }
}

function install_norm_email($value): string
{
    return strtolower(trim((string)$value));
}

function install_is_active_user(array $data): bool
{
    return !array_key_exists('active', $data) || !empty($data['active']);
}

function install_user_location_ids(array $data): array
{
    $ids = [];
    foreach ([
        'active_location_id',
        'location_id',
        'locationId',
        'ghl_location_id',
        'ghlLocationId',
        'selected_location_id',
        'selectedLocationId',
    ] as $key) {
        $clean = install_clean_location_id($data[$key] ?? null);
        if ($clean !== null) {
            $ids[] = $clean;
        }
    }

    $tokenRef = trim((string)($data['ghl_token_ref'] ?? ''));
    if ($tokenRef !== '' && preg_match('#^ghl_tokens/([^/]+)$#', $tokenRef, $m)) {
        $clean = install_clean_location_id($m[1]);
        if ($clean !== null) {
            $ids[] = $clean;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_from_user_doc($doc, string $locationId, string $source): ?array
{
    if (!$doc || !$doc->exists()) {
        return null;
    }

    $data = $doc->data();
    if (!is_array($data) || !install_is_active_user($data)) {
        return null;
    }

    $role = strtolower(trim((string)($data['role'] ?? 'user')));
    if ($role === 'agency') {
        return null;
    }

    if (!in_array($locationId, install_user_location_ids($data), true)) {
        return null;
    }

    $email = install_norm_email($data['email'] ?? '');
    if ($email === '') {
        return null;
    }

    return [
        'id' => $doc->id(),
        'email' => $email,
        'name' => trim((string)($data['name'] ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? '')))),
        'source' => $source,
    ];
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_from_owner_like_doc($doc, string $source): ?array
{
    if (!$doc || !$doc->exists()) {
        return null;
    }

    $data = $doc->data();
    if (!is_array($data)) {
        return null;
    }

    $email = install_norm_email(
        $data['owner_email']
            ?? $data['email']
            ?? $data['user_email']
            ?? $data['account_email']
            ?? ''
    );
    if ($email === '') {
        return null;
    }

    return [
        'id' => trim((string)($data['owner_user_id'] ?? $data['owner_uid'] ?? $data['user_id'] ?? $data['uid'] ?? '')),
        'email' => $email,
        'name' => trim((string)($data['owner_name'] ?? $data['name'] ?? $data['full_name'] ?? '')),
        'source' => $source,
    ];
}

function install_backfill_location_owner($db, string $locationId, ?array $linkedAccount): void
{
    if ($locationId === '' || empty($linkedAccount['email'])) {
        return;
    }

    $now = new DateTimeImmutable();
    if (install_claim_owner_lock(
        $db,
        'location_owners',
        $locationId,
        (string)($linkedAccount['id'] ?? ''),
        install_norm_email($linkedAccount['email']),
        trim((string)($linkedAccount['name'] ?? '')),
        $now,
        'install_self_heal:' . (string)($linkedAccount['source'] ?? 'unknown')
    )) {
        return;
    }

    error_log("[install_helpers] owner backfill skipped for {$locationId}: canonical owner conflict");
}

function install_claim_owner_lock(
    $db,
    string $collection,
    string $entityId,
    string $ownerUserId,
    string $ownerEmail,
    string $ownerName,
    ?DateTimeImmutable $now = null,
    string $source = 'install_registration'
): bool {
    $entityId = trim($entityId);
    $ownerUserId = trim($ownerUserId);
    $ownerEmail = install_norm_email($ownerEmail);
    if ($entityId === '' || ($ownerUserId === '' && $ownerEmail === '')) {
        return false;
    }

    $now = $now ?: new DateTimeImmutable();
    $payload = [
        'entity_id' => $entityId,
        'owner_user_id' => $ownerUserId,
        'owner_email' => $ownerEmail,
        'owner_name' => $ownerName,
        'source' => $source,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ];

    try {
        $createPayload = $payload + [
            'created_at' => new \Google\Cloud\Core\Timestamp($now),
        ];
        $db->collection($collection)->document($entityId)->create($createPayload);
        return true;
    } catch (Exception $e) {
        // Existing lock is expected on reinstall/continuation. Validate before merging.
    }

    try {
        $ref = $db->collection($collection)->document($entityId);
        $snap = $ref->snapshot();
        if (!$snap->exists()) {
            $ref->set($payload + ['created_at' => new \Google\Cloud\Core\Timestamp($now)], ['merge' => true]);
            return true;
        }

        $data = $snap->data();
        $existingUid = trim((string)($data['owner_user_id'] ?? $data['owner_uid'] ?? $data['user_id'] ?? $data['uid'] ?? ''));
        $existingEmail = install_norm_email($data['owner_email'] ?? $data['email'] ?? $data['user_email'] ?? $data['account_email'] ?? '');

        if ($existingUid !== '' && $ownerUserId !== '' && $existingUid !== $ownerUserId) {
            return false;
        }
        if ($existingEmail !== '' && $ownerEmail !== '' && $existingEmail !== $ownerEmail) {
            return false;
        }

        $ref->set($payload, ['merge' => true]);
        return true;
    } catch (Exception $e) {
        error_log("[install_helpers] owner lock claim failed for {$collection}/{$entityId}: " . $e->getMessage());
    }

    return false;
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_for_location($db, string $locationId, bool $deepFallback = true): ?array
{
    $locationId = trim($locationId);
    if ($locationId === '') {
        return null;
    }

    try {
        $ownerSnap = $db->collection('location_owners')->document($locationId)->snapshot();
        $linked = install_linked_account_from_owner_like_doc($ownerSnap, 'location_owners');
        if ($linked !== null) {
            return $linked;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] owner lookup failed for {$locationId}: " . $e->getMessage());
    }

    foreach ([
        'active_location_id',
        'location_id',
        'locationId',
        'ghl_location_id',
        'ghlLocationId',
        'selected_location_id',
        'selectedLocationId',
    ] as $field) {
        try {
            $userQuery = $db->collection('users')
                ->where($field, '=', $locationId)
                ->limit(1)
                ->documents();
            foreach ($userQuery as $doc) {
                $linked = install_linked_account_from_user_doc($doc, $locationId, 'users.' . $field);
                if ($linked !== null) {
                    install_backfill_location_owner($db, $locationId, $linked);
                    return $linked;
                }
            }
        } catch (Exception $e) {
            error_log("[install_helpers] user {$field} lookup failed for {$locationId}: " . $e->getMessage());
        }
    }

    if (!$deepFallback) {
        return install_linked_account_for_location_owner_fallbacks($db, $locationId);
    }

    foreach ([
        'location_id',
        'locationId',
        'entity_id',
        'id',
    ] as $field) {
        try {
            $subQuery = $db->collectionGroup('subaccounts')
                ->where($field, '=', $locationId)
                ->limit(1)
                ->documents();
            foreach ($subQuery as $subDoc) {
                $linked = install_linked_account_from_subaccount_doc($subDoc, $locationId, 'users.subaccounts.' . $field);
                if ($linked !== null) {
                    install_backfill_location_owner($db, $locationId, $linked);
                    return $linked;
                }
            }
        } catch (Exception $e) {
            error_log("[install_helpers] subaccount {$field} lookup failed for {$locationId}: " . $e->getMessage());
        }
    }

    try {
        $subQuery = $db->collectionGroup('subaccounts')
            ->where(\Google\Cloud\Firestore\FieldPath::documentId(), '=', $locationId)
            ->limit(1)
            ->documents();
        foreach ($subQuery as $subDoc) {
            $linked = install_linked_account_from_subaccount_doc($subDoc, $locationId, 'users.subaccounts.document_id');
            if ($linked !== null) {
                install_backfill_location_owner($db, $locationId, $linked);
                return $linked;
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] subaccount document-id lookup failed for {$locationId}: " . $e->getMessage());
    }

    $linked = install_linked_account_for_location_owner_fallbacks($db, $locationId);
    if ($linked !== null) {
        install_backfill_location_owner($db, $locationId, $linked);
    }

    return $linked;
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_from_subaccount_doc($subDoc, string $locationId, string $source): ?array
{
    if (!$subDoc || !$subDoc->exists()) {
        return null;
    }

    $subData = $subDoc->data();
    $subLoc = install_clean_location_id($subData['location_id'] ?? $subData['locationId'] ?? $subData['entity_id'] ?? $subData['id'] ?? null);
    if ($subLoc !== null && $subLoc !== $locationId) {
        return null;
    }

    $parentUserRef = $subDoc->reference()->parent()->parent();
    if ($parentUserRef === null) {
        return null;
    }

    try {
        $parentSnap = $parentUserRef->snapshot();
        if (!$parentSnap->exists()) {
            return null;
        }

        $data = $parentSnap->data();
        if (!is_array($data) || !install_is_active_user($data)) {
            return null;
        }

        $role = strtolower(trim((string)($data['role'] ?? 'user')));
        if ($role === 'agency') {
            return null;
        }

        $email = install_norm_email($data['email'] ?? '');
        if ($email === '') {
            return null;
        }

        return [
            'id' => $parentSnap->id(),
            'email' => $email,
            'name' => trim((string)($data['name'] ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? '')))),
            'source' => $source,
        ];
    } catch (Exception $e) {
        error_log("[install_helpers] subaccount parent lookup failed for {$locationId}: " . $e->getMessage());
        return null;
    }
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_for_location_owner_fallbacks($db, string $locationId): ?array
{
    try {
        $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
        $linked = install_linked_account_from_owner_like_doc($tokenSnap, 'ghl_tokens.owner');
        if ($linked !== null) {
            return $linked;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] token owner fallback failed for {$locationId}: " . $e->getMessage());
    }

    try {
        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
        $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
        $linked = install_linked_account_from_owner_like_doc($intSnap, 'integrations.owner');
        if ($linked !== null) {
            return $linked;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] integration owner fallback failed for {$locationId}: " . $e->getMessage());
    }

    return null;
}

function install_user_linked_to_location($db, string $uid, string $locationId, ?string $email = null): bool
{
    $uid = trim($uid);
    $locationId = trim($locationId);
    if ($uid === '' || $locationId === '') {
        return false;
    }

    try {
        $ownerSnap = $db->collection('location_owners')->document($locationId)->snapshot();
        if ($ownerSnap->exists()) {
            $ownerData = $ownerSnap->data();
            $ownerUid = trim((string)($ownerData['owner_user_id'] ?? $ownerData['owner_uid'] ?? $ownerData['user_id'] ?? $ownerData['uid'] ?? ''));
            $ownerEmail = install_norm_email($ownerData['owner_email'] ?? $ownerData['email'] ?? $ownerData['user_email'] ?? $ownerData['account_email'] ?? '');

            if ($ownerUid !== '') {
                return $ownerUid === $uid;
            }

            if ($email !== null && $ownerEmail !== '') {
                return $ownerEmail === install_norm_email($email);
            }

            return false;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] user linked owner check failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    try {
        $userSnap = $db->collection('users')->document($uid)->snapshot();
        if (!$userSnap->exists()) {
            return false;
        }
        $userData = $userSnap->data();
        if (in_array($locationId, install_user_location_ids($userData), true)) {
            install_backfill_location_owner($db, $locationId, install_linked_account_from_user_doc($userSnap, $locationId, 'users.legacy_verify'));
            return true;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] user linked root check failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    try {
        return $db->collection('users')->document($uid)->collection('subaccounts')->document($locationId)->snapshot()->exists();
    } catch (Exception $e) {
        error_log("[install_helpers] user linked subaccount check failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    try {
        foreach (['location_id', 'locationId', 'entity_id', 'id'] as $field) {
            $subQuery = $db->collection('users')->document($uid)->collection('subaccounts')
                ->where($field, '=', $locationId)
                ->limit(1)
                ->documents();
            foreach ($subQuery as $subDoc) {
                if ($subDoc->exists()) {
                    return true;
                }
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] user linked subaccount field check failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    return false;
}

/**
 * @return array{status:string,token_exists:bool,linked:bool,linked_account:?array,mismatch:bool}
 */
function install_classify_location($db, string $locationId, ?string $companyId = null, ?bool $tokenExistedBefore = null): array
{
    $locationId = trim($locationId);
    $tokenExists = $tokenExistedBefore ?? install_token_doc_exists($db, $locationId);
    $mismatch = install_location_company_mismatch($db, $locationId, $companyId);
    $linkedAccount = install_linked_account_for_location($db, $locationId);
    $linked = $linkedAccount !== null;
    if ($linked) {
        install_backfill_location_owner($db, $locationId, $linkedAccount);
    }

    if ($mismatch) {
        $status = INSTALL_STATE_COMPANY_MISMATCH;
    } elseif ($linked) {
        $status = INSTALL_STATE_LINKED_ACCOUNT;
    } elseif ($tokenExists) {
        $status = INSTALL_STATE_TOKEN_ONLY;
    } else {
        $status = INSTALL_STATE_FRESH_INSTALL;
    }

    return [
        'status' => $status,
        'token_exists' => $tokenExists,
        'linked' => $linked,
        'linked_account' => $linkedAccount,
        'mismatch' => $mismatch,
    ];
}

function install_registration_status_for_account($db, string $locationId): string
{
    $classification = install_classify_location($db, $locationId);
    if (($classification['status'] ?? '') === INSTALL_STATE_LINKED_ACCOUNT) {
        return 'registered';
    }
    if (($classification['status'] ?? '') === INSTALL_STATE_TOKEN_ONLY) {
        return 'unregistered';
    }

    return 'not_installed';
}

function install_build_registration_url(
    string $jwtSecret,
    string $locationId,
    string $locationName,
    ?string $companyId,
    string $companyName,
    string $resolutionSource,
    string $installStatus = ''
): string {
    $payload = [
        'type' => 'install',
        'location_id' => $locationId,
        'location_name' => $locationName,
        'company_id' => $companyId,
        'company_name' => $companyName,
        'resolution_source' => $resolutionSource,
    ];
    if ($installStatus !== '') {
        $payload['install_status'] = $installStatus;
    }

    return 'https://smspro-api.nolacrm.io/register?install_token=' . urlencode(jwt_sign($payload, $jwtSecret, 900));
}

/**
 * Exchange a company-scoped token into a location-scoped token.
 *
 * @return array{ok:bool, code:int, data:array, raw:string, format:string, failures:array}
 */
function install_exchange_location_token(string $companyToken, string $companyId, string $locationId): array
{
    $attempts = [
        [
            'format' => 'form',
            'body' => http_build_query(['companyId' => $companyId, 'locationId' => $locationId]),
            'headers' => [
                'Authorization: Bearer ' . $companyToken,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ],
        [
            'format' => 'json',
            'body' => json_encode(['companyId' => $companyId, 'locationId' => $locationId]),
            'headers' => [
                'Authorization: Bearer ' . $companyToken,
                'Content-Type: application/json',
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ],
        [
            'format' => 'query',
            'url' => 'https://services.leadconnectorhq.com/oauth/locationToken?companyId=' . urlencode($companyId) . '&locationId=' . urlencode($locationId),
            'body' => '',
            'headers' => [
                'Authorization: Bearer ' . $companyToken,
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ],
    ];

    $failures = [];
    foreach ($attempts as $attempt) {
        $ltCurl = curl_init($attempt['url'] ?? 'https://services.leadconnectorhq.com/oauth/locationToken');
        curl_setopt_array($ltCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $attempt['body'],
            CURLOPT_HTTPHEADER => $attempt['headers'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($ltCurl);
        $code = curl_getinfo($ltCurl, CURLINFO_HTTP_CODE);
        curl_close($ltCurl);
        $data = json_decode($raw ?: '', true);
        $jsonDecodeOk = is_array($data);
        if (!$jsonDecodeOk) {
            $data = [];
        }

        if (empty($data['access_token']) && is_string($raw) && $raw !== '') {
            if (preg_match('/"access_token"\s*:\s*"([^"]+)"/', $raw, $m)) {
                $data['access_token'] = stripcslashes($m[1]);
            }
            if (preg_match('/"refresh_token"\s*:\s*"([^"]+)"/', $raw, $m)) {
                $data['refresh_token'] = stripcslashes($m[1]);
            }
            if (preg_match('/"expires_in"\s*:\s*(\d+)/', $raw, $m)) {
                $data['expires_in'] = (int)$m[1];
            }
        }

        if (($code === 200 || $code === 201) && !empty($data['access_token'])) {
            return [
                'ok' => true,
                'code' => $code,
                'data' => $data,
                'raw' => (string)$raw,
                'format' => $attempt['format'],
                'failures' => $failures,
            ];
        }

        $rawText = is_string($raw) ? $raw : '';
        $sanitizedRaw = preg_replace('/"(access_token|refresh_token)"\s*:\s*"[^"]*"/i', '"$1":"[REDACTED]"', $rawText);
        $failures[] = [
            'format' => $attempt['format'],
            'code' => $code,
            'json_decode_ok' => $jsonDecodeOk,
            'has_access_token_field' => !empty($data['access_token']) || (is_string($raw) && strpos($raw, '"access_token"') !== false),
            'raw' => substr($sanitizedRaw, 0, 400),
        ];
    }

    return [
        'ok' => false,
        'code' => 0,
        'data' => [],
        'raw' => '',
        'format' => 'none',
        'failures' => $failures,
    ];
}

function install_fetch_location_name_with_token(string $accessToken, string $locationId, string $fallback = ''): string
{
    if ($accessToken === '' || $locationId === '') {
        return $fallback;
    }

    try {
        $ch = curl_init('https://services.leadconnectorhq.com/locations/' . urlencode($locationId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            $body = json_decode((string)$resp, true);
            $name = trim((string)($body['location']['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] location name fetch failed for {$locationId}: " . $e->getMessage());
    }

    return $fallback;
}

/**
 * @return array{kind:string,status:string,url:?string,classification:array}
 */
function install_decide_location_redirect(
    $db,
    string $jwtSecret,
    string $locationId,
    string $locationName,
    ?string $companyId,
    string $companyName,
    string $resolutionSource,
    ?bool $tokenExistedBefore = null
): array {
    $classification = install_classify_location($db, $locationId, $companyId, $tokenExistedBefore);

    if (($classification['status'] ?? '') === INSTALL_STATE_COMPANY_MISMATCH) {
        return [
            'kind' => 'error',
            'status' => INSTALL_STATE_COMPANY_MISMATCH,
            'url' => null,
            'classification' => $classification,
        ];
    }

    if (!empty($classification['linked'])) {
        $url = 'https://smspro-api.nolacrm.io/login?welcome_back=1&name=' . urlencode($locationName ?: 'Your Sub-Account')
            . '&location_id=' . urlencode($locationId)
            . '&install_status=' . urlencode(INSTALL_STATE_LINKED_ACCOUNT)
            . '&resolution_source=' . urlencode($resolutionSource);
        if ($companyName !== '') {
            $url .= '&company=' . urlencode($companyName);
        }

        return [
            'kind' => 'login',
            'status' => INSTALL_STATE_LINKED_ACCOUNT,
            'url' => $url,
            'classification' => $classification,
        ];
    }

    $status = (string)($classification['status'] ?? INSTALL_STATE_FRESH_INSTALL);

    return [
        'kind' => 'register',
        'status' => $status,
        'url' => install_build_registration_url(
            $jwtSecret,
            $locationId,
            $locationName,
            $companyId,
            $companyName,
            $resolutionSource,
            $status
        ),
        'classification' => $classification,
    ];
}
