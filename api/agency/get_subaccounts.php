<?php
/**
 * GET /api/agency/get_subaccounts
 * 
 * Fetches all subaccounts (GHL Locations) under a specific agency (Company ID).
 * Sources location list from Firestore ghl_tokens (same as check_installs.php)
 * to avoid dependency on the agency-level GHL API token.
 */
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../install_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
$agencyId = validate_agency_request();

// Fallback: allow agency_id as a query param when header-based ID is absent
if (!$agencyId) {
    $agencyId = $_GET['agency_id'] ?? '';
}

if (!$agencyId) {
    http_response_code(400);
    echo json_encode(['error' => 'agency_id is required (via X-Agency-ID header or ?agency_id= query param)']);
    exit;
}

require_once __DIR__ . '/../cache_helper.php';
$cacheKey = 'subaccounts_' . $agencyId;
$cacheTtl = 300;

$bypassCache = isset($_GET['refresh']) || isset($_GET['bypass_cache']);
$cachedResponse = null;
if (!$bypassCache) {
    $cachedResponse = NolaCache::get($cacheKey);
}

if ($cachedResponse !== null) {
    NolaCache::sendApiCacheHeaders($cacheTtl, true);
    echo json_encode($cachedResponse);
    exit;
}

try {
    $db = get_firestore();

    // 1. Get Agency metadata from ghl_tokens/{agencyId} using the robust resolver.
    //    Falls back to checking aliased fields (company_name, companyName, location_name)
    //    and can call the GHL Companies API when a token is available.
    $agencyDoc = $db->collection('ghl_tokens')->document($agencyId)->snapshot();
    $agencyName = '';
    if ($agencyDoc->exists()) {
        $agencyData = $agencyDoc->data();
        $agencyName = install_resolve_agency_name_from_token_doc($agencyData, $agencyId);
    }

    // 2. Get all installed subaccount tokens from Firestore (same logic as check_installs.php)
    //    This does NOT require a valid agency access token — pure Firestore query.
    $tokenDocs = $db->collection('ghl_tokens')->where('companyId', '=', $agencyId)->documents();

    $locationIds = [];
    $locationNames = [];
    foreach ($tokenDocs as $doc) {
        if (!$doc->exists()) continue;
        $data = $doc->data();

        // Skip the agency-level token itself
        $isAgency = ($data['appType'] ?? '') === 'agency' || $doc->id() === $agencyId;
        if ($isAgency) continue;
        if (($data['install_state'] ?? '') === INSTALL_STATE_PENDING_OAUTH) continue;
        if (!install_token_active_for_sms(true, $data)) continue;

        $locId = $data['locationId'] ?? $data['location_id'] ?? $doc->id();
        if ($locId && !in_array($locId, $locationIds)) {
            $locationIds[] = $locId;
            $locationNames[$locId] = $data['location_name'] ?? $data['locationName'] ?? 'Unnamed Location';
        }
    }

    // 3. Fetch existing configs from agency_subaccounts
    $results = $db->collection('agency_subaccounts')->where('agency_id', '=', $agencyId)->documents();
    $dbConfigs = [];
    foreach ($results as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $locId = $data['location_id'] ?? $doc->id();
            $dbConfigs[$locId] = $data;
        }
    }

    // Fallback: if ghl_tokens had no resolvable name, check agency_subaccounts records —
    // they are written during OAuth and reliably contain agency_name from GHL.
    if ($agencyName === '') {
        foreach ($dbConfigs as $cfg) {
            $candidate = trim((string)($cfg['agency_name'] ?? $cfg['company_name'] ?? ''));
            if ($candidate !== '') {
                $agencyName = $candidate;
                break;
            }
        }
    }

    // Final fallback label (never "Unknown Agency" in the DB — keep generic)
    if ($agencyName === '') {
        $agencyName = 'Unnamed Agency';
    }

    $creditManager = new CreditManager();

    // Pre-fetch integrations to avoid O(N) queries
    $integrationsSnap = $db->collection('integrations')->documents();
    $integrationMap = [];
    foreach ($integrationsSnap as $doc) {
        if ($doc->exists()) {
            $integrationMap[$doc->id()] = $doc->data();
        }
    }

    // 4. Merge and return
    $subaccounts = [];
    foreach ($locationIds as $locId) {
        $locName = $locationNames[$locId] ?? ($dbConfigs[$locId]['location_name'] ?? 'Unnamed Location');
        $config  = $dbConfigs[$locId] ?? [];

        // Subaccount balance: users.credit_balance (via CreditManager) with legacy fallback
        $creditBalance = $creditManager->get_balance((string)$locId);

        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $intData = $integrationMap[$intDocId] ?? $integrationMap[$locId] ?? null;
        $freeUsageCount = 0;
        $freeCreditsTotal = 10;
        if ($intData) {
            $freeUsageCount = (int)($intData['free_usage_count'] ?? 0);
            $freeCreditsTotal = (int)($intData['free_credits_total'] ?? 10);
        }

        $subaccountData = [
            'location_id'             => $locId,
            'location_name'           => $locName,
            'agency_id'               => $agencyId,
            'agency_name'             => $agencyName,
            'toggle_enabled'          => isset($config['toggle_enabled']) ? (bool)$config['toggle_enabled'] : true,
            'rate_limit'              => (int)($config['rate_limit'] ?? 5),
            'attempt_count'           => (int)($config['attempt_count'] ?? 0),
            'toggle_activation_count' => (int)($config['toggle_activation_count'] ?? 0),
            'credit_balance'          => $creditBalance
        ];

        // Auto-sync into agency_subaccounts if missing or stale
        if (empty($config) || ($config['agency_name'] ?? '') !== $agencyName || ($config['location_name'] ?? '') !== $locName) {
            $db->collection('agency_subaccounts')->document($locId)->set($subaccountData, ['merge' => true]);
        }

        // Include the dynamic/fresh trial fields for response only
        $subaccountData['free_usage_count'] = $freeUsageCount;
        $subaccountData['free_credits_total'] = $freeCreditsTotal;

        $subaccounts[] = $subaccountData;
    }

    $response = [
        'status'      => 'success',
        'subaccounts' => $subaccounts
    ];

    NolaCache::set($cacheKey, $response, $cacheTtl);
    NolaCache::sendApiCacheHeaders($cacheTtl, false);

    echo json_encode($response);

} catch (\Throwable $e) {
    $msg = $e->getMessage();
    http_response_code(500);
    echo json_encode([
        'error' => 'Fetch failed: ' . $msg,
        'line'  => $e->getLine(),
        'file'  => $e->getFile()
    ]);
}
