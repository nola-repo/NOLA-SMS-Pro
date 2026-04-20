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

require_once __DIR__ . '/../services/Cache.php';
$cache = new Cache('data');
$cacheKey = 'subaccounts_' . $agencyId;

$cachedResponse = $cache->get($cacheKey, 600); // 10 minutes cache
if ($cachedResponse) {
    echo json_encode($cachedResponse);
    exit;
}

try {
    $db = get_firestore();

    // 1. Get Agency metadata from ghl_tokens (name only — no API call needed)
    $agencyDoc = $db->collection('ghl_tokens')->document($agencyId)->snapshot();
    $agencyName = 'Unnamed Agency';
    if ($agencyDoc->exists()) {
        $agencyData = $agencyDoc->data();
        $agencyName = $agencyData['agency_name'] ?? $agencyData['location_name'] ?? 'Unnamed Agency';
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

    // 4. Merge and return
    $subaccounts = [];
    foreach ($locationIds as $locId) {
        $locName = $locationNames[$locId] ?? ($dbConfigs[$locId]['location_name'] ?? 'Unnamed Location');
        $config  = $dbConfigs[$locId] ?? [];

        // Fetch credit_balance from integrations collection
        $creditBalance = 0;
        $intDocId  = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
        $intSnap   = $db->collection('integrations')->document($intDocId)->snapshot();
        if ($intSnap->exists()) {
            $creditBalance = (int)($intSnap->data()['credit_balance'] ?? 0);
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

        $subaccounts[] = $subaccountData;
    }

    $response = [
        'status'      => 'success',
        'subaccounts' => $subaccounts
    ];

    $cache->set($cacheKey, $response);

    echo json_encode($response);

} catch (\Throwable $e) {
    $msg = $e->getMessage();

    // Detect stale / revoked token errors so the frontend can prompt re-install
    $isTokenError = stripos($msg, 'refresh token') !== false
                 || stripos($msg, 'Invalid JWT') !== false
                 || stripos($msg, 'token refresh failed') !== false;

    if ($isTokenError) {
        http_response_code(401);
        echo json_encode([
            'error'              => 'Your GoHighLevel connection has expired.',
            'requires_reconnect' => true,
            'details'            => $msg
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'error' => 'Fetch failed: ' . $msg,
            'line'  => $e->getLine(),
            'file'  => $e->getFile()
        ]);
    }
}
