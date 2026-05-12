<?php

/**
 * Post-reinstall GHL token verification helper.
 *
 * Usage:
 *   php tmp/post_reinstall_verify.php --locationId=<GHL_LOCATION_ID>
 *   php tmp/post_reinstall_verify.php --locationId=<GHL_LOCATION_ID> --companyId=<GHL_COMPANY_ID>
 */

require_once __DIR__ . '/../api/webhook/firestore_client.php';

function argValue(array $argv, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos((string) $arg, $prefix) === 0) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    return null;
}

function decodeJwtPayload(?string $jwt): ?array
{
    if (!$jwt || substr_count($jwt, '.') < 2) {
        return null;
    }

    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return null;
    }

    $payload = $parts[1];
    $payload = str_replace(['-', '_'], ['+', '/'], $payload);
    $pad = strlen($payload) % 4;
    if ($pad > 0) {
        $payload .= str_repeat('=', 4 - $pad);
    }

    $json = base64_decode($payload, true);
    if (!is_string($json) || $json === '') {
        return null;
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function toEpoch($v): int
{
    if ($v instanceof \Google\Cloud\Core\Timestamp) {
        return $v->get()->getTimestamp();
    }

    return (int) $v;
}

function line(string $k, $v): void
{
    if (is_array($v)) {
        echo str_pad($k, 34) . ': ' . json_encode($v) . PHP_EOL;
        return;
    }
    echo str_pad($k, 34) . ': ' . (string) $v . PHP_EOL;
}

$locationId = trim((string) (argValue($argv, 'locationId') ?? ''));
$companyIdArg = trim((string) (argValue($argv, 'companyId') ?? ''));

if ($locationId === '') {
    fwrite(STDERR, "Missing required --locationId\n");
    exit(1);
}

$db = get_firestore();

echo "=== Post-reinstall verification ===\n";
line('locationId', $locationId);

$locSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
if (!$locSnap->exists()) {
    echo "\n[FAIL] Missing canonical location doc: ghl_tokens/{$locationId}\n";
    exit(2);
}

$loc = $locSnap->data();
$companyId = $companyIdArg !== '' ? $companyIdArg : trim((string) ($loc['companyId'] ?? $loc['company_id'] ?? ''));
$now = time();

echo "\n-- Location token doc --\n";
line('doc', "ghl_tokens/{$locationId}");
line('userType(field)', $loc['userType'] ?? '');
line('appType(field)', $loc['appType'] ?? '');
line('companyId(field)', $companyId);
line('has_access_token', !empty($loc['access_token']) ? 'yes' : 'no');
line('has_refresh_token', !empty($loc['refresh_token']) ? 'yes' : 'no');
$locExp = toEpoch($loc['expires_at'] ?? 0);
line('expires_at_unix', $locExp);
line('expires_in_sec', $locExp > 0 ? ($locExp - $now) : 0);

$locJwt = decodeJwtPayload($loc['access_token'] ?? null);
if ($locJwt) {
    line('access.jwt.authClass', $locJwt['authClass'] ?? '(none)');
    line('access.jwt.locationId', $locJwt['locationId'] ?? '(none)');
    line('access.jwt.companyId', $locJwt['companyId'] ?? '(none)');
}

$company = null;
if ($companyId !== '') {
    $coSnap = $db->collection('ghl_tokens')->document($companyId)->snapshot();
    if ($coSnap->exists()) {
        $company = $coSnap->data();
        echo "\n-- Company token doc --\n";
        line('doc', "ghl_tokens/{$companyId}");
        line('userType(field)', $company['userType'] ?? '');
        line('appType(field)', $company['appType'] ?? '');
        line('has_access_token', !empty($company['access_token']) ? 'yes' : 'no');
        line('has_refresh_token', !empty($company['refresh_token']) ? 'yes' : 'no');
        $coExp = toEpoch($company['expires_at'] ?? 0);
        line('expires_at_unix', $coExp);
        line('expires_in_sec', $coExp > 0 ? ($coExp - $now) : 0);

        $coJwt = decodeJwtPayload($company['access_token'] ?? null);
        if ($coJwt) {
            line('access.jwt.authClass', $coJwt['authClass'] ?? '(none)');
            line('access.jwt.locationId', $coJwt['locationId'] ?? '(none)');
            line('access.jwt.companyId', $coJwt['companyId'] ?? '(none)');
        }
    } else {
        echo "\n[WARN] Company doc not found: ghl_tokens/{$companyId}\n";
    }
}

echo "\n-- Checks --\n";
$issues = [];

if (empty($loc['access_token'])) {
    $issues[] = 'location_doc_missing_access_token';
}
if (empty($loc['refresh_token']) && (!$company || empty($company['refresh_token']))) {
    $issues[] = 'no_refresh_token_on_location_or_company_doc';
}
if (!empty($locJwt['authClass']) && strtolower((string) $locJwt['authClass']) !== 'location') {
    $issues[] = 'location_doc_access_token_authClass_not_location';
}
if (($locExp - $now) < -60) {
    $issues[] = 'location_access_token_already_expired';
}
if (($loc['userType'] ?? '') === 'Location' && ($loc['appType'] ?? '') === 'subaccount' && !empty($loc['provisioned_from_bulk'])) {
    $issues[] = 'location_doc_marked_provisioned_from_bulk_may_force_wrong_refresh_path';
}

if (empty($issues)) {
    echo "[PASS] Token docs look healthy for post-reinstall usage.\n";
} else {
    echo "[WARN] Found potential issues:\n";
    foreach ($issues as $i) {
        echo " - {$i}\n";
    }
}

echo "\nDone.\n";

