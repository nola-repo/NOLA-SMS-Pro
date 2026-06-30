<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/LocationUserResolver.php';

/**
 * Read-only audit for the collections that connect install state to user
 * sessions:
 *
 * - ghl_tokens
 * - integrations
 * - agency_subaccounts
 * - location_owners
 * - users
 *
 * Usage:
 *   php scripts/audit_subaccount_session_integrity.php --location=sxtUvcl8Sm3ki3TRQz3x
 *   php scripts/audit_subaccount_session_integrity.php --limit=500
 */

function audit_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string)$arg, $prefix)) {
            return substr((string)$arg, strlen($prefix));
        }
    }
    return $default;
}

function audit_has_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function audit_firestore()
{
    $transport = strtolower(audit_clean_string(audit_arg('transport', getenv('FIRESTORE_TRANSPORT') ?: 'auto')));
    if ($transport === 'rest') {
        return new \Google\Cloud\Firestore\FirestoreClient([
            'projectId' => getenv('GOOGLE_CLOUD_PROJECT') ?: 'nola-sms-pro',
            'transport' => 'rest',
        ]);
    }

    return get_firestore();
}

function audit_location_doc_id(string $locationId): string
{
    return 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($locationId));
}

function audit_clean_string($value): string
{
    return trim((string)($value ?? ''));
}

function audit_norm_email($value): string
{
    return strtolower(trim((string)($value ?? '')));
}

function audit_location_from_integration_doc(string $docId, array $data): string
{
    $fromData = audit_clean_string(
        $data['location_id']
        ?? $data['locationId']
        ?? $data['ghl_location_id']
        ?? $data['ghlLocationId']
        ?? ''
    );
    if ($fromData !== '') {
        return $fromData;
    }

    return str_starts_with($docId, 'ghl_') ? substr($docId, 4) : $docId;
}

function audit_location_from_token_doc(string $docId, array $data): string
{
    $fromData = audit_clean_string(
        $data['location_id']
        ?? $data['locationId']
        ?? $data['ghl_location_id']
        ?? $data['ghlLocationId']
        ?? ''
    );
    if ($fromData !== '') {
        return $fromData;
    }

    return $docId;
}

function audit_user_location_ids(array $data): array
{
    $ids = [];
    foreach (['active_location_id', 'location_id', 'locationId', 'ghl_location_id', 'ghlLocationId'] as $field) {
        $value = audit_clean_string($data[$field] ?? '');
        if ($value !== '') {
            $ids[] = $value;
            if (str_starts_with($value, 'ghl_')) {
                $ids[] = substr($value, 4);
            } else {
                $ids[] = audit_location_doc_id($value);
            }
        }
    }

    $tokenRef = audit_clean_string($data['ghl_token_ref'] ?? '');
    if (preg_match('#^ghl_tokens/([^/]+)$#', $tokenRef, $m)) {
        $ids[] = $m[1];
    }

    return array_values(array_unique(array_filter($ids)));
}

function audit_owner_like(array $data, string $source): ?array
{
    $uid = audit_clean_string(
        $data['owner_user_id']
        ?? $data['owner_uid']
        ?? $data['user_id']
        ?? $data['uid']
        ?? $data['linked_user_id']
        ?? $data['linked_account_user_id']
        ?? $data['account_user_id']
        ?? ''
    );
    $email = audit_norm_email(
        $data['owner_email']
        ?? $data['user_email']
        ?? $data['account_email']
        ?? $data['linked_email']
        ?? $data['linked_account_email']
        ?? $data['email']
        ?? ''
    );
    $name = audit_clean_string(
        $data['owner_name']
        ?? $data['name']
        ?? $data['full_name']
        ?? $data['account_name']
        ?? ''
    );

    if ($uid === '' && $email === '') {
        return null;
    }

    return [
        'source' => $source,
        'user_id' => $uid !== '' ? $uid : null,
        'email' => $email !== '' ? $email : null,
        'name' => $name !== '' ? $name : null,
    ];
}

function audit_public_user(string $id, array $data, string $locationId): array
{
    return [
        'id' => $id,
        'email' => audit_norm_email($data['email'] ?? ''),
        'role' => $data['role'] ?? 'user',
        'active' => !array_key_exists('active', $data) || !empty($data['active']),
        'eligible_for_autologin' => LocationUserResolver::isEligibleUser($data, $locationId),
        'active_location_id' => $data['active_location_id'] ?? null,
        'location_id' => $data['location_id'] ?? null,
        'ghl_location_id' => $data['ghl_location_id'] ?? null,
        'ghl_token_ref' => $data['ghl_token_ref'] ?? null,
        'company_id' => $data['company_id'] ?? null,
    ];
}

function audit_add_location(array &$locations, string $locationId, string $source, string $docId, array $data = []): void
{
    $locationId = audit_clean_string($locationId);
    if ($locationId === '') {
        return;
    }
    if (!isset($locations[$locationId])) {
        $locations[$locationId] = [
            'location_id' => $locationId,
            'sources' => [],
            'token' => null,
            'integration' => null,
            'agency_subaccount' => null,
            'owner' => null,
            'members' => [],
            'matched_users' => [],
            'issues' => [],
        ];
    }
    $locations[$locationId]['sources'][] = $source;

    if ($source === 'ghl_tokens') {
        $locations[$locationId]['token'] = [
            'doc_id' => $docId,
            'company_id' => audit_clean_string($data['companyId'] ?? $data['company_id'] ?? ''),
            'user_type' => $data['userType'] ?? null,
            'app_type' => $data['appType'] ?? null,
            'install_state' => $data['install_state'] ?? null,
            'is_live' => $data['is_live'] ?? null,
            'owner_like' => audit_owner_like($data, 'ghl_tokens'),
        ];
    } elseif ($source === 'integrations') {
        $locations[$locationId]['integration'] = [
            'doc_id' => $docId,
            'company_id' => audit_clean_string($data['companyId'] ?? $data['company_id'] ?? ''),
            'install_state' => $data['install_state'] ?? null,
            'is_live' => $data['is_live'] ?? null,
            'owner_like' => audit_owner_like($data, 'integrations'),
        ];
    } elseif ($source === 'agency_subaccounts') {
        $locations[$locationId]['agency_subaccount'] = [
            'doc_id' => $docId,
            'agency_id' => audit_clean_string($data['agency_id'] ?? $data['company_id'] ?? ''),
            'enabled' => $data['enabled'] ?? $data['is_live'] ?? null,
        ];
    }
}

function audit_collect_location_users($db, string $locationId): array
{
    $matches = [];
    $candidates = array_values(array_unique([$locationId, audit_location_doc_id($locationId)]));
    foreach (['active_location_id', 'location_id', 'ghl_location_id'] as $field) {
        foreach ($candidates as $candidate) {
            foreach ($db->collection('users')->where($field, '=', $candidate)->limit(50)->documents() as $doc) {
                if ($doc->exists()) {
                    $matches[] = ['id' => $doc->id(), 'data' => $doc->data()];
                }
            }
        }
    }
    foreach ($db->collection('users')->where('ghl_token_ref', '=', 'ghl_tokens/' . $locationId)->limit(50)->documents() as $doc) {
        if ($doc->exists()) {
            $matches[] = ['id' => $doc->id(), 'data' => $doc->data()];
        }
    }

    return LocationUserResolver::deduplicateMatches($matches);
}

function audit_classify_location($db, array $row): array
{
    $locationId = $row['location_id'];
    $issues = [];

    $token = $row['token'];
    $integration = $row['integration'];
    $owner = $row['owner'];
    $users = $row['matched_users'];
    $eligibleUsers = array_values(array_filter($users, static fn(array $u): bool => !empty($u['eligible_for_autologin'])));

    if ($token === null && $integration === null) {
        $issues[] = [
            'code' => 'LOCATION_NOT_INSTALLED_RECORD_MISSING',
            'severity' => 'high',
            'message' => 'No ghl_tokens or integrations record was found for this location.',
        ];
    }

    $tokenCompany = audit_clean_string($token['company_id'] ?? '');
    $integrationCompany = audit_clean_string($integration['company_id'] ?? '');
    if ($tokenCompany !== '' && $integrationCompany !== '' && $tokenCompany !== $integrationCompany) {
        $issues[] = [
            'code' => 'TOKEN_INTEGRATION_COMPANY_MISMATCH',
            'severity' => 'high',
            'message' => 'ghl_tokens and integrations disagree on company_id.',
            'token_company_id' => $tokenCompany,
            'integration_company_id' => $integrationCompany,
        ];
    }

    if (count($eligibleUsers) > 1 && $owner === null) {
        $issues[] = [
            'code' => 'LEGACY_MULTIPLE_LOCATION_USERS',
            'severity' => 'high',
            'message' => 'Legacy user records still link multiple NOLA accounts to this location. Runtime autologin will choose one canonical owner.',
            'repair' => 'Choose the owner and run scripts/enforce_single_location_user.php.',
        ];
    }

    if (!empty($row['members'])) {
        $issues[] = [
            'code' => 'LEGACY_LOCATION_MEMBERS_PRESENT',
            'severity' => 'medium',
            'message' => 'Legacy location member documents remain and should be removed by the single-user migration.',
            'repair' => 'Run scripts/enforce_single_location_user.php for this location.',
        ];
    }

    if ($owner !== null) {
        $ownerId = audit_clean_string($owner['user_id'] ?? '');
        $ownerEmail = audit_norm_email($owner['email'] ?? '');
        $ownerUserData = null;
        if ($ownerId !== '') {
            $ownerSnap = $db->collection('users')->document($ownerId)->snapshot();
            if ($ownerSnap->exists()) {
                $ownerUserData = $ownerSnap->data();
            } else {
                $issues[] = [
                    'code' => 'DEFAULT_OWNER_USER_MISSING',
                    'severity' => 'critical',
                    'message' => 'location_owners points to a user document that does not exist.',
                    'owner_user_id' => $ownerId,
                ];
            }
        }

        if ($ownerUserData === null && $ownerEmail !== '') {
            foreach ($db->collection('users')->where('email', '=', $ownerEmail)->limit(2)->documents() as $doc) {
                if ($doc->exists()) {
                    $ownerUserData = $doc->data();
                    $ownerId = $doc->id();
                    break;
                }
            }
        }

        if (is_array($ownerUserData)) {
            if (!LocationUserResolver::isEligibleUser($ownerUserData, $locationId)) {
                $issues[] = [
                    'code' => 'DEFAULT_OWNER_NOT_ELIGIBLE_FOR_LOCATION',
                    'severity' => 'critical',
                    'message' => 'Default owner exists, but user profile is inactive, agency role, or not scoped to this location.',
                    'owner_user_id' => $ownerId !== '' ? $ownerId : null,
                ];
            }
        } elseif ($ownerId === '' && $ownerEmail !== '') {
            $issues[] = [
                'code' => 'DEFAULT_OWNER_EMAIL_NOT_FOUND',
                'severity' => 'critical',
                'message' => 'location_owners has owner email but no matching user was found.',
                'owner_email' => $ownerEmail,
            ];
        }
    }

    if (count($eligibleUsers) === 1 && $owner === null) {
        $issues[] = [
            'code' => 'SINGLE_USER_NO_DEFAULT_OWNER',
            'severity' => 'medium',
            'message' => 'Exactly one eligible user exists but location_owners is missing; backend may self-heal, but admin should backfill.',
            'suggested_owner_user_id' => $eligibleUsers[0]['id'] ?? null,
        ];
    }

    if (count($eligibleUsers) === 0 && ($token !== null || $integration !== null)) {
        $issues[] = [
            'code' => 'INSTALLED_LOCATION_NO_ELIGIBLE_USER',
            'severity' => 'high',
            'message' => 'Installed location has no eligible users linked in users/location_owners.',
        ];
    }

    return $issues;
}

if (audit_has_flag('help') || audit_has_flag('h')) {
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/audit_subaccount_session_integrity.php --location=<location_id>\n");
    fwrite(STDOUT, "  php scripts/audit_subaccount_session_integrity.php --limit=500\n");
    fwrite(STDOUT, "  php scripts/audit_subaccount_session_integrity.php --location=<location_id> --transport=rest\n");
    fwrite(STDOUT, "\nThis script is read-only.\n");
    exit(0);
}

$targetLocation = audit_clean_string(audit_arg('location', ''));
$limit = max(1, min((int)audit_arg('limit', '500'), 5000));

try {
    $db = audit_firestore();
    $locations = [];

    if ($targetLocation !== '') {
        $tokenSnap = $db->collection('ghl_tokens')->document($targetLocation)->snapshot();
        if ($tokenSnap->exists()) {
            audit_add_location($locations, audit_location_from_token_doc($targetLocation, $tokenSnap->data()), 'ghl_tokens', $targetLocation, $tokenSnap->data());
        } else {
            audit_add_location($locations, $targetLocation, 'requested', $targetLocation, []);
        }

        $intDocId = audit_location_doc_id($targetLocation);
        $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
        if ($intSnap->exists()) {
            audit_add_location($locations, audit_location_from_integration_doc($intDocId, $intSnap->data()), 'integrations', $intDocId, $intSnap->data());
        }

        $agencySubSnap = $db->collection('agency_subaccounts')->document($targetLocation)->snapshot();
        if ($agencySubSnap->exists()) {
            audit_add_location($locations, $targetLocation, 'agency_subaccounts', $targetLocation, $agencySubSnap->data());
        }
    } else {
        foreach ($db->collection('ghl_tokens')->limit($limit)->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            if (($data['appType'] ?? '') === 'agency' || ($data['userType'] ?? '') === 'Company') {
                continue;
            }
            audit_add_location($locations, audit_location_from_token_doc($doc->id(), $data), 'ghl_tokens', $doc->id(), $data);
        }

        foreach ($db->collection('integrations')->limit($limit)->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            audit_add_location($locations, audit_location_from_integration_doc($doc->id(), $data), 'integrations', $doc->id(), $data);
        }

        foreach ($db->collection('agency_subaccounts')->limit($limit)->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            $loc = audit_clean_string($data['location_id'] ?? $data['locationId'] ?? $doc->id());
            audit_add_location($locations, $loc, 'agency_subaccounts', $doc->id(), $data);
        }
    }

    $ownerDocs = [];
    if ($targetLocation !== '') {
        $ownerSnap = $db->collection('location_owners')->document($targetLocation)->snapshot();
        if ($ownerSnap->exists()) {
            $ownerDocs[] = $ownerSnap;
        }
    } else {
        foreach ($db->collection('location_owners')->limit($limit)->documents() as $ownerDoc) {
            if ($ownerDoc->exists()) {
                $ownerDocs[] = $ownerDoc;
            }
        }
    }

    foreach ($ownerDocs as $ownerDoc) {
        $loc = $ownerDoc->id();
        if (!isset($locations[$loc])) {
            audit_add_location($locations, $loc, 'location_owners', $loc, []);
        }
        $ownerData = $ownerDoc->data();
        $locations[$loc]['owner'] = audit_owner_like($ownerData, 'location_owners') ?? [
            'source' => 'location_owners',
            'user_id' => null,
            'email' => null,
            'name' => null,
        ];

        try {
            foreach ($db->collection('location_owners')->document($loc)->collection('members')->limit(100)->documents() as $memberDoc) {
                if (!$memberDoc->exists()) {
                    continue;
                }
                $memberData = $memberDoc->data();
                $locations[$loc]['members'][] = [
                    'user_id' => $memberDoc->id(),
                    'email' => audit_norm_email($memberData['email'] ?? $memberData['owner_email'] ?? ''),
                    'active' => !array_key_exists('active', $memberData) || !empty($memberData['active']),
                ];
            }
        } catch (\Throwable $e) {
            $locations[$loc]['issues'][] = [
                'code' => 'MEMBERS_READ_FAILED',
                'severity' => 'medium',
                'message' => $e->getMessage(),
            ];
        }
    }

    foreach (array_keys($locations) as $loc) {
        $matches = audit_collect_location_users($db, $loc);
        foreach ($matches as $id => $match) {
            $locations[$loc]['matched_users'][] = audit_public_user($id, $match['data'], $loc);
        }
        $locations[$loc]['issues'] = array_merge(
            $locations[$loc]['issues'],
            audit_classify_location($db, $locations[$loc])
        );
        $locations[$loc]['sources'] = array_values(array_unique($locations[$loc]['sources']));
        $locations[$loc]['matched_user_count'] = count($locations[$loc]['matched_users']);
        $locations[$loc]['eligible_user_count'] = count(array_filter(
            $locations[$loc]['matched_users'],
            static fn(array $u): bool => !empty($u['eligible_for_autologin'])
        ));
        $locations[$loc]['member_count'] = count($locations[$loc]['members']);
    }

    $issueCounts = [];
    $severityCounts = [];
    foreach ($locations as $row) {
        foreach ($row['issues'] as $issue) {
            $code = (string)($issue['code'] ?? 'UNKNOWN');
            $sev = (string)($issue['severity'] ?? 'unknown');
            $issueCounts[$code] = ($issueCounts[$code] ?? 0) + 1;
            $severityCounts[$sev] = ($severityCounts[$sev] ?? 0) + 1;
        }
    }
    ksort($issueCounts);
    ksort($severityCounts);

    $problemLocations = array_values(array_filter($locations, static fn(array $row): bool => count($row['issues']) > 0));

    echo json_encode([
        'dry_run' => true,
        'target_location' => $targetLocation !== '' ? $targetLocation : null,
        'limit' => $targetLocation !== '' ? null : $limit,
        'audited_location_count' => count($locations),
        'problem_location_count' => count($problemLocations),
        'issue_counts' => $issueCounts,
        'severity_counts' => $severityCounts,
        'locations' => $targetLocation !== '' ? array_values($locations) : $problemLocations,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'Subaccount session integrity audit failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
