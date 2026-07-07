<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/AgencyNameResolver.php';
require_once __DIR__ . '/../api/install_helpers.php';

function manr_arg(string $name, string $default = ''): string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string)$arg, $prefix)) {
            return trim(substr((string)$arg, strlen($prefix)));
        }
    }
    return $default;
}

function manr_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

$locationIds = array_values(array_filter(array_map('trim', explode(',', manr_arg('locations')))));
if ($locationIds === []) {
    fwrite(STDERR, "Provide --locations=id1,id2\n");
    exit(2);
}

$execute = manr_flag('execute');
if ($execute && manr_arg('confirm') !== 'MIGRATE-AGENCY-NAME-REGISTRY') {
    fwrite(STDERR, "Execution requires --confirm=MIGRATE-AGENCY-NAME-REGISTRY\n");
    exit(3);
}

$db = get_firestore();
$agencyNameMap = [];
foreach (['agency_users', 'agencies'] as $collection) {
    foreach ($db->collection($collection)->documents() as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        $data = $doc->data();
        $companyId = AgencyNameResolver::companyId($data, $doc->id());
        $companyName = $collection === 'agencies'
            ? AgencyNameResolver::agencyName($data)
            : AgencyNameResolver::agencyUserCompanyName($data);
        if ($companyId !== '' && $companyName !== '') {
            $agencyNameMap[$companyId] = ['name' => $companyName, 'source' => $collection];
        }
    }
}

$preview = [];
foreach ($locationIds as $locationId) {
    $tokenRef = $db->collection('ghl_tokens')->document($locationId);
    $tokenSnap = $tokenRef->snapshot();
    $tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
    $intDocId = 'ghl_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $locationId);
    $intRef = $db->collection('integrations')->document($intDocId);
    $intSnap = $intRef->snapshot();
    $intData = $intSnap->exists() ? $intSnap->data() : [];

    $companyId = AgencyNameResolver::firstNonEmpty($tokenData, ['companyId', 'company_id']);
    if ($companyId === '') {
        $companyId = AgencyNameResolver::firstNonEmpty($intData, ['companyId', 'company_id']);
    }
    $resolved = $companyId !== '' ? ($agencyNameMap[$companyId] ?? null) : null;
    $tokenHasEmptyName = array_key_exists('company_name', $tokenData)
        && trim((string)$tokenData['company_name']) === '';
    $integrationHasEmptyName = array_key_exists('company_name', $intData)
        && trim((string)$intData['company_name']) === '';

    $actions = [];
    if ($resolved !== null) {
        $actions[] = 'upsert_agencies_registry';
    } else {
        $actions[] = 'agency_name_unresolved_no_registry_write';
    }
    if ($tokenHasEmptyName) {
        $actions[] = 'delete_empty_ghl_tokens_company_name';
    }
    if ($integrationHasEmptyName) {
        $actions[] = 'delete_empty_integrations_company_name';
    }

    if ($execute) {
        if ($resolved !== null) {
            install_upsert_agency_registry(
                $db,
                $companyId,
                (string)$resolved['name'],
                'agency_name_registry_migration'
            );
        }
        if ($tokenHasEmptyName) {
            $tokenRef->set([
                'company_name' => \Google\Cloud\Firestore\FieldValue::deleteField(),
            ], ['merge' => true]);
        }
        if ($integrationHasEmptyName) {
            $intRef->set([
                'company_name' => \Google\Cloud\Firestore\FieldValue::deleteField(),
            ], ['merge' => true]);
        }
    }

    $preview[] = [
        'location_id' => $locationId,
        'company_id' => $companyId !== '' ? $companyId : null,
        'resolved_agency_name' => $resolved['name'] ?? null,
        'name_source' => $resolved['source'] ?? null,
        'actions' => $actions,
    ];
}

echo json_encode([
    'mode' => $execute ? 'execute' : 'dry_run',
    'location_count' => count($preview),
    'locations' => $preview,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
