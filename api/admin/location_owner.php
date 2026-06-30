<?php
/**
 * Admin API for choosing the default NOLA user used by GHL location autologin.
 *
 * GET  /api/admin/location_owner?location_id=...
 * POST /api/admin/location_owner
 *   { "location_id": "...", "owner_user_id": "..." }
 *
 * This endpoint intentionally writes only location_owners/{locationId}. The
 * GHL install/token collections remain install-state sources, not session-user
 * sources.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../admin_auth_helper.php';
require_once __DIR__ . '/../services/LocationUserResolver.php';
require_once __DIR__ . '/../cache_helper.php';

function admin_location_owner_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_location_owner_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
}

function admin_location_owner_doc_id(string $locationId): string
{
    return 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($locationId));
}

function admin_location_owner_clean($value): string
{
    return trim((string)($value ?? ''));
}

function admin_location_owner_email($value): string
{
    return strtolower(trim((string)($value ?? '')));
}

function admin_location_owner_name(array $data): string
{
    return trim((string)(
        $data['name']
        ?? $data['full_name']
        ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? ''))
    ));
}

function admin_location_owner_payload(string $id, array $data, string $locationId, ?string $defaultOwnerId): array
{
    return [
        'id' => $id,
        'email' => admin_location_owner_email($data['email'] ?? ''),
        'name' => admin_location_owner_name($data),
        'role' => $data['role'] ?? 'user',
        'active' => !array_key_exists('active', $data) || !empty($data['active']),
        'eligible_for_autologin' => LocationUserResolver::isEligibleUser($data, $locationId),
        'is_default_autologin_account' => $defaultOwnerId !== null && $defaultOwnerId === $id,
        'active_location_id' => $data['active_location_id'] ?? null,
        'location_id' => $data['location_id'] ?? null,
        'ghl_location_id' => $data['ghl_location_id'] ?? null,
        'ghl_token_ref' => $data['ghl_token_ref'] ?? null,
        'company_id' => $data['company_id'] ?? null,
    ];
}

function admin_location_owner_add_candidate(array &$candidates, string $id, array $data): void
{
    $id = trim($id);
    if ($id === '') {
        return;
    }
    $candidates[$id] = ['id' => $id, 'data' => $data];
}

/**
 * @return array<string,array{id:string,data:array}>
 */
function admin_location_owner_candidates($db, string $locationId): array
{
    $matches = [];
    $candidates = array_values(array_unique([$locationId, admin_location_owner_doc_id($locationId)]));

    foreach (['active_location_id', 'location_id', 'ghl_location_id'] as $field) {
        foreach ($candidates as $candidate) {
            foreach ($db->collection('users')->where($field, '=', $candidate)->limit(100)->documents() as $doc) {
                if ($doc->exists()) {
                    $data = $doc->data();
                    if (is_array($data)) {
                        admin_location_owner_add_candidate($matches, $doc->id(), $data);
                    }
                }
            }
        }
    }

    foreach ($db->collection('users')->where('ghl_token_ref', '=', 'ghl_tokens/' . $locationId)->limit(100)->documents() as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            if (is_array($data)) {
                admin_location_owner_add_candidate($matches, $doc->id(), $data);
            }
        }
    }

    return $matches;
}

function admin_location_owner_user_linked($db, string $userId, array $userData, string $locationId): bool
{
    if (LocationUserResolver::isEligibleUser($userData, $locationId)) {
        return true;
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
    } catch (Throwable $e) {
        error_log('[admin/location_owner] subaccount validation failed for ' . $locationId . '/' . $userId . ': ' . $e->getMessage());
    }

    return false;
}

function admin_location_owner_install_state($db, string $locationId): array
{
    $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
    $intDocId = admin_location_owner_doc_id($locationId);
    $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
    $tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
    $intData = $intSnap->exists() ? $intSnap->data() : [];

    return [
        'token_exists' => $tokenSnap->exists(),
        'integration_exists' => $intSnap->exists(),
        'token_doc_id' => $tokenSnap->exists() ? $locationId : null,
        'integration_doc_id' => $intSnap->exists() ? $intDocId : null,
        'company_id' => admin_location_owner_clean(
            $tokenData['companyId']
            ?? $tokenData['company_id']
            ?? $intData['companyId']
            ?? $intData['company_id']
            ?? ''
        ) ?: null,
        'location_name' => admin_location_owner_clean(
            $tokenData['location_name']
            ?? $tokenData['locationName']
            ?? $intData['location_name']
            ?? $intData['locationName']
            ?? ''
        ) ?: null,
        'token_install_state' => $tokenData['install_state'] ?? null,
        'integration_install_state' => $intData['install_state'] ?? null,
    ];
}

$claims = require_secure_admin_auth(['super_admin', 'support']);
$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    admin_location_owner_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}

try {
    if ($method === 'GET') {
        $locationId = admin_location_owner_clean($_GET['location_id'] ?? $_GET['locationId'] ?? '');
        if ($locationId === '') {
            admin_location_owner_json(400, ['status' => 'error', 'message' => 'location_id is required']);
        }

        $ownerSnap = $db->collection('location_owners')->document($locationId)->snapshot();
        $ownerData = $ownerSnap->exists() ? $ownerSnap->data() : [];
        $ownerId = admin_location_owner_clean(
            $ownerData['owner_user_id']
            ?? $ownerData['owner_uid']
            ?? $ownerData['user_id']
            ?? $ownerData['uid']
            ?? ''
        );
        $ownerId = $ownerId !== '' ? $ownerId : null;

        $candidates = admin_location_owner_candidates($db, $locationId);
        if ($ownerId !== null && !isset($candidates[$ownerId])) {
            $ownerUserSnap = $db->collection('users')->document($ownerId)->snapshot();
            if ($ownerUserSnap->exists()) {
                $ownerUserData = $ownerUserSnap->data();
                if (is_array($ownerUserData)) {
                    admin_location_owner_add_candidate($candidates, $ownerId, $ownerUserData);
                }
            }
        }

        $users = [];
        foreach ($candidates as $candidate) {
            $users[] = admin_location_owner_payload($candidate['id'], $candidate['data'], $locationId, $ownerId);
        }
        usort($users, static fn(array $a, array $b): int => strcmp((string)($a['email'] ?? ''), (string)($b['email'] ?? '')));

        $issues = [];
        $eligibleCount = count(array_filter($users, static fn(array $u): bool => !empty($u['eligible_for_autologin'])));
        if ($ownerId === null && $eligibleCount > 1) {
            $issues[] = 'LEGACY_MULTIPLE_USERS_REQUIRE_MIGRATION';
        }
        if ($ownerId !== null && !array_filter($users, static fn(array $u): bool => !empty($u['is_default_autologin_account']) && !empty($u['active']))) {
            $issues[] = 'DEFAULT_OWNER_USER_MISSING_OR_INACTIVE';
        }

        admin_location_owner_json(200, [
            'status' => 'success',
            'location_id' => $locationId,
            'install' => admin_location_owner_install_state($db, $locationId),
            'default_owner' => [
                'exists' => $ownerSnap->exists(),
                'user_id' => $ownerId,
                'email' => $ownerData['owner_email'] ?? null,
                'name' => $ownerData['owner_name'] ?? null,
                'source' => $ownerData['source'] ?? null,
                'updated_at' => isset($ownerData['updated_at']) && $ownerData['updated_at'] instanceof \Google\Cloud\Core\Timestamp
                    ? $ownerData['updated_at']->get()->format(DATE_ATOM)
                    : null,
            ],
            'matched_user_count' => count($users),
            'eligible_user_count' => $eligibleCount,
            'issues' => $issues,
            'users' => $users,
        ]);
    }

    $input = admin_location_owner_body();
    $locationId = admin_location_owner_clean($input['location_id'] ?? $input['locationId'] ?? '');
    $ownerUserId = admin_location_owner_clean($input['owner_user_id'] ?? $input['ownerUserId'] ?? $input['user_id'] ?? '');

    if ($locationId === '' || $ownerUserId === '') {
        admin_location_owner_json(400, ['status' => 'error', 'message' => 'location_id and owner_user_id are required']);
    }

    $install = admin_location_owner_install_state($db, $locationId);
    if (empty($install['token_exists']) && empty($install['integration_exists'])) {
        admin_location_owner_json(404, [
            'status' => 'error',
            'message' => 'No installed GHL token or integration exists for this location.',
            'code' => 'LOCATION_NOT_INSTALLED',
            'location_id' => $locationId,
        ]);
    }

    $userSnap = $db->collection('users')->document($ownerUserId)->snapshot();
    if (!$userSnap->exists()) {
        admin_location_owner_json(404, ['status' => 'error', 'message' => 'Selected user does not exist.', 'code' => 'USER_NOT_FOUND']);
    }

    $userData = $userSnap->data();
    if (!is_array($userData) || !LocationUserResolver::isActiveNonAgencyUser($userData)) {
        admin_location_owner_json(400, [
            'status' => 'error',
            'message' => 'Selected user is inactive or is not a subaccount user.',
            'code' => 'USER_NOT_ELIGIBLE',
        ]);
    }

    if (!admin_location_owner_user_linked($db, $ownerUserId, $userData, $locationId)) {
        admin_location_owner_json(400, [
            'status' => 'error',
            'message' => 'Selected user is not linked to this GHL location.',
            'code' => 'USER_NOT_LINKED_TO_LOCATION',
            'location_id' => $locationId,
            'owner_user_id' => $ownerUserId,
        ]);
    }

    foreach ($db->collection('location_owners')->where('owner_user_id', '=', $ownerUserId)->limit(2)->documents() as $ownedLocation) {
        if ($ownedLocation->exists() && $ownedLocation->id() !== $locationId) {
            admin_location_owner_json(409, [
                'status' => 'error',
                'message' => 'Selected user already owns another GHL location.',
                'code' => 'USER_ALREADY_OWNS_LOCATION',
                'owner_user_id' => $ownerUserId,
            ]);
        }
    }

    $ownerRef = $db->collection('location_owners')->document($locationId);
    $previousSnap = $ownerRef->snapshot();
    $previousData = $previousSnap->exists() ? $previousSnap->data() : [];
    $previousOwnerId = admin_location_owner_clean(
        $previousData['owner_user_id']
        ?? $previousData['owner_uid']
        ?? $previousData['user_id']
        ?? $previousData['uid']
        ?? ''
    );

    $now = new \DateTimeImmutable();
    $adminEmail = admin_location_owner_email($claims['email'] ?? $claims['username'] ?? '');
    $payload = [
        'entity_id' => $locationId,
        'location_id' => $locationId,
        'owner_user_id' => $ownerUserId,
        'owner_uid' => $ownerUserId,
        'owner_email' => admin_location_owner_email($userData['email'] ?? ''),
        'owner_name' => admin_location_owner_name($userData),
        'source' => 'admin_selected_default_autologin',
        'repair_source' => 'api/admin/location_owner.php',
        'selected_by_admin_email' => $adminEmail !== '' ? $adminEmail : null,
        'previous_owner_user_id' => $previousOwnerId !== '' && $previousOwnerId !== $ownerUserId ? $previousOwnerId : null,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ];
    if (!$previousSnap->exists()) {
        $payload['created_at'] = new \Google\Cloud\Core\Timestamp($now);
    }

    $ownerRef->set($payload, ['merge' => true]);

    try {
        NolaCache::delete('account_profile_' . $locationId);
        NolaCache::invalidateAdminDashboard();
    } catch (Throwable $ignored) {
    }

    admin_location_owner_json(200, [
        'status' => 'success',
        'message' => 'Default autologin account updated.',
        'location_id' => $locationId,
        'owner_user_id' => $ownerUserId,
        'owner_email' => $payload['owner_email'],
        'previous_owner_user_id' => $payload['previous_owner_user_id'],
    ]);
} catch (Throwable $e) {
    error_log('[admin/location_owner] failed: ' . $e->getMessage());
    admin_location_owner_json(500, ['status' => 'error', 'message' => 'Failed to update location owner.', 'detail' => $e->getMessage()]);
}
