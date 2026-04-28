<?php
/**
 * copy_token_to_location.php — One-time fix script
 *
 * Uses GHL's /oauth/locationToken API to exchange the company bulk-install
 * token for a proper location-scoped token, then saves it to Firestore.
 *
 * Usage: https://smspro-api.nolacrm.io/copy_token_to_location.php?key=nola_fix_2026
 * Optional: &from=DOC_ID to specify source company doc explicitly.
 * DELETE THIS FILE after running.
 */

if (($_GET['key'] ?? '') !== 'nola_fix_2026') {
    http_response_code(403);
    die('Forbidden');
}

require __DIR__ . '/api/webhook/firestore_client.php';
header('Content-Type: application/json');

$db = get_firestore();

$LOCATION_DOC_ID      = 'ugBqfQsPtGijLjrmLdmA';
$SUBACCOUNT_CLIENT_ID = '6999da2b8f278296d95f7274-mmn30t4f';
$SUBACCOUNT_SECRET    = getenv('GHL_CLIENT_SECRET') ?: 'd91017ad-f4eb-461f-8967-b1d51cd1c1eb';

// ── Step 1: Find the company token doc ───────────────────────────────────────
$companyData    = null;
$COMPANY_DOC_ID = $_GET['from'] ?? null;

if ($COMPANY_DOC_ID) {
    $snap = $db->collection('ghl_tokens')->document($COMPANY_DOC_ID)->snapshot();
    if ($snap->exists()) {
        $companyData = $snap->data();
    }
} else {
    // Auto-find: newest subaccount-app token doc that isn't the location doc itself
    $allDocs = [];
    foreach ($db->collection('ghl_tokens')->documents() as $doc) {
        if (!$doc->exists() || $doc->id() === $LOCATION_DOC_ID) continue;
        $d = $doc->data();
        $cid = $d['client_id'] ?? $d['appId'] ?? '';
        if (str_contains($cid, '6999da2b8f278296d95f7274') && !empty($d['access_token'])) {
            $allDocs[] = ['id' => $doc->id(), 'data' => $d];
        }
    }
    if (!empty($allDocs)) {
        usort($allDocs, fn($a, $b) => ($b['data']['updated_at'] ?? 0) <=> ($a['data']['updated_at'] ?? 0));
        $COMPANY_DOC_ID = $allDocs[0]['id'];
        $companyData    = $allDocs[0]['data'];
    }
}

if (!$companyData || empty($companyData['access_token'])) {
    // List all docs so user can identify the right one
    $list = [];
    foreach ($db->collection('ghl_tokens')->documents() as $doc) {
        if (!$doc->exists()) continue;
        $d = $doc->data();
        $list[] = [
            'id'        => $doc->id(),
            'client_id' => $d['client_id'] ?? $d['appId'] ?? '(none)',
            'userType'  => $d['userType'] ?? '?',
            'has_token' => !empty($d['access_token']),
        ];
    }
    echo json_encode([
        'error'       => 'No company token found. Add &from=DOC_ID',
        'all_docs'    => $list,
    ], JSON_PRETTY_PRINT);
    exit;
}

$companyAccessToken = $companyData['access_token'];
$companyId = $companyData['companyId'] ?? $COMPANY_DOC_ID;

// ── Step 2: Call GHL /oauth/locationToken to get a location-scoped token ─────
$locationTokenUrl = 'https://services.leadconnectorhq.com/oauth/locationToken';

$ch = curl_init($locationTokenUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'companyId'  => $companyId,
        'locationId' => $LOCATION_DOC_ID,
    ]),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $companyAccessToken,
        'Content-Type: application/json',
        'Version: 2021-07-28',
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$tokenData = json_decode($response, true);

if ($httpCode !== 200 || empty($tokenData['access_token'])) {
    echo json_encode([
        'error'       => 'GHL locationToken exchange failed.',
        'http_code'   => $httpCode,
        'ghl_response'=> $tokenData ?? $response,
        'tip'         => 'The company token may be expired. Re-install the app then run this script again.',
    ], JSON_PRETTY_PRINT);
    exit;
}

// ── Step 3: Save location-scoped token to Firestore ───────────────────────────
$now           = new DateTimeImmutable();
$expires       = (int)($tokenData['expires_in'] ?? 86400);
$expiresAtUnix = time() + $expires;

$locationPayload = [
    'access_token'  => $tokenData['access_token'],
    'refresh_token' => $companyData['refresh_token'], // location tokens use company refresh
    'expires_at'    => $expiresAtUnix,
    'client_id'     => $SUBACCOUNT_CLIENT_ID,
    'appId'         => $SUBACCOUNT_CLIENT_ID,
    'appType'       => 'subaccount',
    'userType'      => 'Location',
    'location_id'   => $LOCATION_DOC_ID,
    'location_name' => $companyData['company_name'] ?? $companyData['location_name'] ?? 'NOLA CRM',
    'companyId'     => $companyId,
    'scope'         => $companyData['scope'] ?? null,
    'is_live'       => true,
    'toggle_enabled'=> true,
    'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
    'copied_from'   => $COMPANY_DOC_ID,
    'raw'           => $tokenData,
];

$db->collection('ghl_tokens')->document($LOCATION_DOC_ID)->set($locationPayload, ['merge' => true]);

// ── Step 4: Sync access_token into integrations doc ──────────────────────────
$intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $LOCATION_DOC_ID);
$db->collection('integrations')->document($intDocId)->set([
    'access_token'  => $tokenData['access_token'],
    'expires_at'    => $expiresAtUnix,
    'client_id'     => $SUBACCOUNT_CLIENT_ID,
    'app_type'      => 'subaccount',
    'location_id'   => $LOCATION_DOC_ID,
    'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
], ['merge' => true]);

echo json_encode([
    'success'       => true,
    'method'        => 'GHL /oauth/locationToken exchange',
    'source_doc'    => $COMPANY_DOC_ID,
    'written_to'    => 'ghl_tokens/' . $LOCATION_DOC_ID,
    'integration'   => 'integrations/' . $intDocId,
    'userType'      => 'Location',
    'token_head'    => substr($tokenData['access_token'], 0, 25) . '…',
    'expires_in'    => $expires,
    'message'       => 'Location token saved. Test contacts now. Delete this file!',
], JSON_PRETTY_PRINT);
