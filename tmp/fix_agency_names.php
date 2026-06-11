<?php
/**
 * Backfill missing agency_name / company_name for an agency and its subaccounts.
 *
 * Usage (dry-run, default):
 *   php tmp/fix_agency_names.php --company_id=0OYXPGWM9ep2l37dgxAo
 *
 * Apply writes:
 *   php tmp/fix_agency_names.php --company_id=0OYXPGWM9ep2l37dgxAo --apply=1
 */

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/install_helpers.php';
require_once __DIR__ . '/../api/cache_helper.php';

function fix_agency_parse_args(): array
{
    $companyId = '';
    $apply = false;

    foreach ($GLOBALS['argv'] ?? [] as $arg) {
        if (str_starts_with($arg, '--company_id=')) {
            $companyId = trim(substr($arg, strlen('--company_id=')));
        } elseif ($arg === '--apply=1' || $arg === '--apply') {
            $apply = true;
        }
    }

    return ['company_id' => $companyId, 'apply' => $apply];
}

function fix_agency_company_name_empty($value): bool
{
    if ($value === null) {
        return true;
    }
    if (!is_scalar($value)) {
        return true;
    }

    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return true;
    }

    $placeholders = ['unknown agency', 'no agency', 'unnamed agency', 'unknown', 'your ghl company'];
    return in_array(strtolower($trimmed), $placeholders, true);
}


function fix_agency_is_subaccount_doc(array $data, string $docId, string $companyId): bool
{
    $docCompanyId = trim((string)($data['companyId'] ?? $data['company_id'] ?? ''));
    if ($docCompanyId !== $companyId) {
        return false;
    }

    if ($docId === $companyId) {
        return false;
    }

    $appType = strtolower(trim((string)($data['appType'] ?? '')));
    if ($appType === 'agency') {
        return false;
    }

    $userType = strtolower(trim((string)($data['userType'] ?? '')));
    if ($userType === 'company') {
        return false;
    }

    return true;
}

function fix_agency_invalidate_caches(): void
{
    try {
        NolaCache::delete('admin_accounts_list');
        NolaCache::delete('admin_agencies_list');
    } catch (\Throwable $e) {
        // Non-fatal
    }
}

$args = fix_agency_parse_args();
$companyId = $args['company_id'];
$apply = $args['apply'];

if ($companyId === '') {
    fwrite(STDERR, "Missing --company_id\n");
    exit(1);
}

try {
    $db = get_firestore();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Firestore init failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$agencyRef = $db->collection('ghl_tokens')->document($companyId);
$agencySnap = $agencyRef->snapshot();

if (!$agencySnap->exists()) {
    fwrite(STDERR, "Agency token document not found: ghl_tokens/{$companyId}\n");
    exit(1);
}

$agencyData = $agencySnap->data();
$accessToken = trim((string)($agencyData['access_token'] ?? ''));
if ($accessToken === '') {
    fwrite(STDERR, "No access_token on ghl_tokens/{$companyId}\n");
    exit(1);
}

$currentAgencyName = trim((string)(
    $agencyData['agency_name']
    ?? $agencyData['company_name']
    ?? $agencyData['companyName']
    ?? $agencyData['location_name']
    ?? ''
));

$fetchedAgencyName = install_resolve_agency_name_from_token_doc($agencyData, $companyId);
if ($fetchedAgencyName === '') {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'step' => 'fetch_company_name',
        'company_id' => $companyId,
        'error' => 'Unable to resolve agency name from GHL Companies API',
    ], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$ghlTokenUpdates = [];
$integrationUpdates = [];
$ghlTokenSkipped = [];
$integrationSkipped = [];

$tokenDocs = $db->collection('ghl_tokens')->where('companyId', '=', $companyId)->documents();
foreach ($tokenDocs as $doc) {
    if (!$doc->exists()) {
        continue;
    }

    $data = $doc->data();
    $docId = $doc->id();

    if (!fix_agency_is_subaccount_doc($data, $docId, $companyId)) {
        continue;
    }

    $current = trim((string)($data['company_name'] ?? $data['companyName'] ?? ''));
    if (fix_agency_company_name_empty($data['company_name'] ?? $data['companyName'] ?? null)) {
        $ghlTokenUpdates[] = [
            'collection' => 'ghl_tokens',
            'document_id' => $docId,
            'location_id' => $data['locationId'] ?? $data['location_id'] ?? $docId,
            'current_company_name' => $current,
            'new_company_name' => $fetchedAgencyName,
        ];
    } else {
        $ghlTokenSkipped[] = [
            'collection' => 'ghl_tokens',
            'document_id' => $docId,
            'company_name' => $current,
        ];
    }
}

$integrationDocs = $db->collection('integrations')->where('companyId', '=', $companyId)->documents();
foreach ($integrationDocs as $doc) {
    if (!$doc->exists()) {
        continue;
    }

    $data = $doc->data();
    $docId = $doc->id();
    $current = trim((string)($data['company_name'] ?? $data['companyName'] ?? ''));

    if (fix_agency_company_name_empty($data['company_name'] ?? $data['companyName'] ?? null)) {
        $integrationUpdates[] = [
            'collection' => 'integrations',
            'document_id' => $docId,
            'location_id' => $data['location_id'] ?? str_replace('ghl_', '', $docId),
            'current_company_name' => $current,
            'new_company_name' => $fetchedAgencyName,
        ];
    } else {
        $integrationSkipped[] = [
            'collection' => 'integrations',
            'document_id' => $docId,
            'company_name' => $current,
        ];
    }
}

// integrations may use company_id instead of companyId
$integrationDocsAlt = $db->collection('integrations')->where('company_id', '=', $companyId)->documents();
foreach ($integrationDocsAlt as $doc) {
    if (!$doc->exists()) {
        continue;
    }

    $data = $doc->data();
    $docId = $doc->id();

    $alreadyQueued = false;
    foreach ($integrationUpdates as $queued) {
        if ($queued['document_id'] === $docId) {
            $alreadyQueued = true;
            break;
        }
    }
    if ($alreadyQueued) {
        continue;
    }

    $current = trim((string)($data['company_name'] ?? $data['companyName'] ?? ''));
    if (fix_agency_company_name_empty($data['company_name'] ?? $data['companyName'] ?? null)) {
        $integrationUpdates[] = [
            'collection' => 'integrations',
            'document_id' => $docId,
            'location_id' => $data['location_id'] ?? str_replace('ghl_', '', $docId),
            'current_company_name' => $current,
            'new_company_name' => $fetchedAgencyName,
        ];
    } else {
        $integrationSkipped[] = [
            'collection' => 'integrations',
            'document_id' => $docId,
            'company_name' => $current,
        ];
    }
}

$uniqueSubaccountIds = [];
foreach ($ghlTokenUpdates as $row) {
    $uniqueSubaccountIds[$row['location_id']] = true;
}
foreach ($integrationUpdates as $row) {
    $uniqueSubaccountIds[$row['location_id']] = true;
}

$report = [
    'status' => $apply ? 'applied' : 'dry_run',
    'company_id' => $companyId,
    'current_agency_name' => $currentAgencyName,
    'fetched_agency_name' => $fetchedAgencyName,
    'agency_doc_needs_update' => fix_agency_company_name_empty($agencyData['agency_name'] ?? null)
        || fix_agency_company_name_empty($agencyData['company_name'] ?? null),
    'affected_subaccount_count' => count($uniqueSubaccountIds),
    'ghl_tokens_updates' => count($ghlTokenUpdates),
    'integrations_updates' => count($integrationUpdates),
    'ghl_tokens_skipped' => count($ghlTokenSkipped),
    'integrations_skipped' => count($integrationSkipped),
    'subaccount_location_ids' => array_keys($uniqueSubaccountIds),
    'details' => [
        'ghl_tokens' => $ghlTokenUpdates,
        'integrations' => $integrationUpdates,
    ],
];

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

    // Sync agency_subaccounts cache collection used by agency portal
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

    fix_agency_invalidate_caches();
    NolaCache::delete('subaccounts_' . $companyId);
}

echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
