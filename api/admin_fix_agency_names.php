<?php
/**
 * POST /api/admin_fix_agency_names.php
 *
 * Backfill missing agency_name / company_name for one agency and its subaccounts.
 *
 * Body:
 *   { "company_id": "...", "apply": false }
 *
 * Requires super_admin or support role.
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/install_helpers.php';
require_once __DIR__ . '/cache_helper.php';

require_secure_admin_auth(['super_admin', 'support']);

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
    exit;
}

$companyId = trim((string)($payload['company_id'] ?? ''));
$apply = !empty($payload['apply']);

if ($companyId === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'company_id is required']);
    exit;
}

function admin_fix_company_name_empty($value): bool
{
    if ($value === null || !is_scalar($value)) {
        return true;
    }

    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return true;
    }

    $placeholders = ['unknown agency', 'no agency', 'unnamed agency', 'unknown', 'your ghl company'];
    return in_array(strtolower($trimmed), $placeholders, true);
}

function admin_fix_is_subaccount_doc(array $data, string $docId, string $companyId): bool
{
    $docCompanyId = trim((string)($data['companyId'] ?? $data['company_id'] ?? ''));
    if ($docCompanyId !== $companyId || $docId === $companyId) {
        return false;
    }

    if (strtolower(trim((string)($data['appType'] ?? ''))) === 'agency') {
        return false;
    }

    if (strtolower(trim((string)($data['userType'] ?? ''))) === 'company') {
        return false;
    }

    return true;
}

try {
    $db = get_firestore();
    $agencyRef = $db->collection('ghl_tokens')->document($companyId);
    $agencySnap = $agencyRef->snapshot();

    if (!$agencySnap->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => "Agency token not found: ghl_tokens/{$companyId}"]);
        exit;
    }

    $agencyData = $agencySnap->data();
    $accessToken = trim((string)($agencyData['access_token'] ?? ''));
    if ($accessToken === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Agency access_token missing']);
        exit;
    }

    $currentAgencyName = trim((string)(
        $agencyData['agency_name']
        ?? $agencyData['company_name']
        ?? $agencyData['companyName']
        ?? $agencyData['location_name']
        ?? ''
    ));

    $fetchedAgencyName = install_resolve_agency_name_from_token_doc($agencyData, $companyId);
    $nameSource = $fetchedAgencyName !== '' ? 'ghl_api' : '';

    if ($fetchedAgencyName === '') {
        try {
            $agencyUsers = $db->collection('agency_users')->where('company_id', '=', $companyId)->limit(1)->documents();
            foreach ($agencyUsers as $userDoc) {
                if (!$userDoc->exists()) {
                    continue;
                }
                $userData = $userDoc->data();
                $fallbackName = trim((string)($userData['company_name'] ?? $userData['name'] ?? ''));
                if ($fallbackName !== '') {
                    $fetchedAgencyName = $fallbackName;
                    $nameSource = 'agency_users';
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    if ($fetchedAgencyName === '') {
        http_response_code(502);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch agency name from GHL Companies API',
            'company_id' => $companyId,
        ]);
        exit;
    }

    $ghlTokenUpdates = [];
    $integrationUpdates = [];
    $seenIntegrationDocs = [];

    $tokenDocs = $db->collection('ghl_tokens')->where('companyId', '=', $companyId)->documents();
    foreach ($tokenDocs as $doc) {
        if (!$doc->exists()) {
            continue;
        }

        $data = $doc->data();
        $docId = $doc->id();
        if (!admin_fix_is_subaccount_doc($data, $docId, $companyId)) {
            continue;
        }

        if (admin_fix_company_name_empty($data['company_name'] ?? $data['companyName'] ?? null)) {
            $ghlTokenUpdates[] = [
                'document_id' => $docId,
                'location_id' => $data['locationId'] ?? $data['location_id'] ?? $docId,
                'current_company_name' => trim((string)($data['company_name'] ?? $data['companyName'] ?? '')),
            ];
        }
    }

    foreach (['companyId', 'company_id'] as $companyField) {
        $integrationDocs = $db->collection('integrations')->where($companyField, '=', $companyId)->documents();
        foreach ($integrationDocs as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $docId = $doc->id();
            if (isset($seenIntegrationDocs[$docId])) {
                continue;
            }
            $seenIntegrationDocs[$docId] = true;

            $data = $doc->data();
            if (admin_fix_company_name_empty($data['company_name'] ?? $data['companyName'] ?? null)) {
                $integrationUpdates[] = [
                    'document_id' => $docId,
                    'location_id' => $data['location_id'] ?? str_replace('ghl_', '', $docId),
                    'current_company_name' => trim((string)($data['company_name'] ?? $data['companyName'] ?? '')),
                ];
            }
        }
    }

    $uniqueSubaccountIds = [];
    foreach ($ghlTokenUpdates as $row) {
        $uniqueSubaccountIds[$row['location_id']] = true;
    }
    foreach ($integrationUpdates as $row) {
        $uniqueSubaccountIds[$row['location_id']] = true;
    }

    if ($apply) {
        $now = new \Google\Cloud\Core\Timestamp(new DateTimeImmutable());
        $agencyRef->set([
            'agency_name' => $fetchedAgencyName,
            'company_name' => $fetchedAgencyName,
            'updated_at' => $now,
        ], ['merge' => true]);

        foreach ($ghlTokenUpdates as $row) {
            $db->collection('ghl_tokens')->document($row['document_id'])->set([
                'company_name' => $fetchedAgencyName,
                'updated_at' => $now,
            ], ['merge' => true]);
        }

        foreach ($integrationUpdates as $row) {
            $db->collection('integrations')->document($row['document_id'])->set([
                'company_name' => $fetchedAgencyName,
                'updated_at' => $now,
            ], ['merge' => true]);
        }

        foreach (array_keys($uniqueSubaccountIds) as $locId) {
            try {
                $db->collection('agency_subaccounts')->document($locId)->set([
                    'agency_name' => $fetchedAgencyName,
                    'agency_id' => $companyId,
                    'updated_at' => $now,
                ], ['merge' => true]);
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        try {
            NolaCache::delete('admin_accounts_list');
            NolaCache::delete('admin_agencies_list');
            NolaCache::delete('subaccounts_' . $companyId);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    echo json_encode([
        'status' => $apply ? 'applied' : 'dry_run',
        'company_id' => $companyId,
        'current_agency_name' => $currentAgencyName,
        'fetched_agency_name' => $fetchedAgencyName,
        'name_source' => $nameSource,
        'agency_doc_needs_update' => admin_fix_company_name_empty($agencyData['agency_name'] ?? null)
            || admin_fix_company_name_empty($agencyData['company_name'] ?? null),
        'affected_subaccount_count' => count($uniqueSubaccountIds),
        'ghl_tokens_updates' => count($ghlTokenUpdates),
        'integrations_updates' => count($integrationUpdates),
        'subaccount_location_ids' => array_keys($uniqueSubaccountIds),
        'details' => [
            'ghl_tokens' => $ghlTokenUpdates,
            'integrations' => $integrationUpdates,
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
