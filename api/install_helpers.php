<?php

/**
 * Shared install/reinstall helpers for the GHL Marketplace flow.
 *
 * Keep the callback, registration page, login page, and account API aligned on
 * what the selected GHL location is and whether it is installed vs registered.
 */

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
 * State is never accepted by itself because opaque OAuth state has previously
 * looked like a location id and caused the wrong subaccount to be selected.
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
        }
    }

    if ($selectedFromList !== null) {
        $conflict = [];
        if ($tokenLocationId !== null && $tokenLocationId !== $selectedFromList) {
            $conflict['token_location_id'] = $tokenLocationId;
        }
        if ($queryLocationId !== null && $queryLocationId !== $selectedFromList) {
            $conflict['query_location_id'] = $queryLocationId;
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

    foreach ([
        'token_location_field' => $tokenLocationId,
        'query_location_field' => $queryLocationId,
    ] as $source => $directId) {
        if ($directId === null) {
            continue;
        }

        if (!empty($candidateIds) && !in_array($directId, $candidateIds, true)) {
            return [
                'ok' => false,
                'location_id' => null,
                'source' => $source,
                'reason' => 'direct_location_conflicts_with_ghl_candidates',
                'candidate_ids' => $candidateIds,
                'location_names' => $locationNames,
                'conflict' => ['direct_location_id' => $directId],
            ];
        }

        return [
            'ok' => true,
            'location_id' => $directId,
            'source' => $source,
            'reason' => 'direct_location_signal',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
    }

    if ($stateLocationId !== null && in_array($stateLocationId, $candidateIds, true)) {
        return [
            'ok' => true,
            'location_id' => $stateLocationId,
            'source' => 'state_matched_candidate',
            'reason' => 'oauth_state_matched_known_candidate',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
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
        'reason' => $stateLocationId !== null && empty($candidateIds)
            ? 'standalone_state_location_not_trusted'
            : (count($candidateIds) > 1 ? 'ambiguous_location_candidates' : 'no_location_signal'),
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
    if ($locationId === '' || $companyId === '') {
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
        $storedCompany = trim((string)($data['companyId'] ?? $data['company_id'] ?? ''));
        return $storedCompany !== '' && $storedCompany !== $companyId;
    } catch (Exception $e) {
        error_log("[install_helpers] company mismatch check failed for {$locationId}: " . $e->getMessage());
        return false;
    }
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
        if ($ownerSnap->exists()) {
            $ownerData = $ownerSnap->data();
            $email = strtolower(trim((string)($ownerData['owner_email'] ?? '')));
            if ($email !== '') {
                return [
                    'id' => trim((string)($ownerData['owner_user_id'] ?? '')),
                    'email' => $email,
                    'name' => trim((string)($ownerData['owner_name'] ?? '')),
                    'source' => 'location_owners',
                ];
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] owner lookup failed for {$locationId}: " . $e->getMessage());
    }

    try {
        $userQuery = $db->collection('users')
            ->where('active_location_id', '=', $locationId)
            ->limit(1)
            ->documents();
        foreach ($userQuery as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            $email = strtolower(trim((string)($data['email'] ?? '')));
            if ($email !== '') {
                return [
                    'id' => $doc->id(),
                    'email' => $email,
                    'name' => trim((string)($data['name'] ?? '')),
                    'source' => 'users.active_location_id',
                ];
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] active_location lookup failed for {$locationId}: " . $e->getMessage());
    }

    if (!$deepFallback) {
        return null;
    }

    try {
        $subQuery = $db->collectionGroup('subaccounts')
            ->where('location_id', '=', $locationId)
            ->limit(1)
            ->documents();
        foreach ($subQuery as $subDoc) {
            if (!$subDoc->exists()) {
                continue;
            }

            $parentUserRef = $subDoc->reference()->parent()->parent();
            if ($parentUserRef === null) {
                continue;
            }

            $parentSnap = $parentUserRef->snapshot();
            if (!$parentSnap->exists()) {
                continue;
            }

            $data = $parentSnap->data();
            $email = strtolower(trim((string)($data['email'] ?? '')));
            if ($email !== '') {
                return [
                    'id' => $parentSnap->id(),
                    'email' => $email,
                    'name' => trim((string)($data['name'] ?? '')),
                    'source' => 'users.subaccounts',
                ];
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] subaccount lookup failed for {$locationId}: " . $e->getMessage());
    }

    return null;
}

function install_user_linked_to_location($db, string $uid, string $locationId): bool
{
    $uid = trim($uid);
    $locationId = trim($locationId);
    if ($uid === '' || $locationId === '') {
        return false;
    }

    try {
        $userSnap = $db->collection('users')->document($uid)->snapshot();
        if (!$userSnap->exists()) {
            return false;
        }
        $userData = $userSnap->data();
        if ((string)($userData['active_location_id'] ?? '') === $locationId) {
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

    if ($mismatch) {
        $status = 'ambiguous_or_mismatch';
    } elseif ($linked) {
        $status = 'reinstall_registered';
    } elseif ($tokenExists) {
        $status = 'reinstall_unregistered';
    } else {
        $status = 'fresh_install';
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
    if ($classification['linked']) {
        return 'registered';
    }
    if ($classification['token_exists']) {
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

    if (($classification['status'] ?? '') === 'ambiguous_or_mismatch') {
        return [
            'kind' => 'error',
            'status' => 'ambiguous_or_mismatch',
            'url' => null,
            'classification' => $classification,
        ];
    }

    if (!empty($classification['linked'])) {
        $url = 'https://smspro-api.nolacrm.io/login?welcome_back=1&name=' . urlencode($locationName ?: 'Your Sub-Account')
            . '&location_id=' . urlencode($locationId);
        if ($companyName !== '') {
            $url .= '&company=' . urlencode($companyName);
        }

        return [
            'kind' => 'login',
            'status' => 'reinstall_registered',
            'url' => $url,
            'classification' => $classification,
        ];
    }

    $status = (string)($classification['status'] ?? 'fresh_install');

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
