<?php
/**
 * copy_token_to_location.php — One-time fix script
 * Copies the subaccount-app company token into the specific location document.
 *
 * Usage: https://smspro-api.nolacrm.io/copy_token_to_location.php?key=nola_fix_2026
 * DELETE THIS FILE after running.
 */

if (($_GET['key'] ?? '') !== 'nola_fix_2026') {
    http_response_code(403);
    die('Forbidden');
}

require __DIR__ . '/api/webhook/firestore_client.php';
header('Content-Type: application/json');

$db = get_firestore();

$COMPANY_DOC_ID  = '00YXPGWM9ep2I37dgxAo';   // bulk-install company token
$LOCATION_DOC_ID = 'ugBqfQsPtGijLjrmLdmA';    // target location doc

// 1. Read the company token doc
$companySnap = $db->collection('ghl_tokens')->document($COMPANY_DOC_ID)->snapshot();
if (!$companySnap->exists()) {
    http_response_code(404);
    echo json_encode(['error' => 'Company token doc not found: ' . $COMPANY_DOC_ID]);
    exit;
}

$companyData = $companySnap->data();

// Validate it has tokens
if (empty($companyData['access_token']) || empty($companyData['refresh_token'])) {
    http_response_code(422);
    echo json_encode(['error' => 'Company doc has no access_token or refresh_token']);
    exit;
}

// 2. Build location-scoped payload
$now = new DateTimeImmutable();
$locationPayload = [
    'access_token'  => $companyData['access_token'],
    'refresh_token' => $companyData['refresh_token'],
    'expires_at'    => $companyData['expires_at'] ?? null,
    'client_id'     => $companyData['client_id'] ?? $companyData['appId'] ?? '6999da2b8f278296d95f7274-mmn30t4f',
    'appId'         => $companyData['appId']      ?? $companyData['client_id'] ?? '6999da2b8f278296d95f7274-mmn30t4f',
    'appType'       => 'subaccount',
    'userType'      => 'Location',          // ← critical: override to Location
    'location_id'   => $LOCATION_DOC_ID,
    'location_name' => $companyData['company_name'] ?? 'NOLA CRM',
    'companyId'     => $COMPANY_DOC_ID,
    'scope'         => $companyData['scope'] ?? null,
    'is_live'       => true,
    'toggle_enabled'=> true,
    'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
    'copied_from'   => $COMPANY_DOC_ID,     // audit trail
];

// 3. Write to location doc
$db->collection('ghl_tokens')
   ->document($LOCATION_DOC_ID)
   ->set($locationPayload, ['merge' => true]);

// 4. Also ensure integrations doc has tokens
$intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $LOCATION_DOC_ID);
$db->collection('integrations')
   ->document($intDocId)
   ->set([
       'access_token'  => $companyData['access_token'],
       'refresh_token' => $companyData['refresh_token'],
       'expires_at'    => $companyData['expires_at'] ?? null,
       'client_id'     => $locationPayload['client_id'],
       'app_type'      => 'subaccount',
       'location_id'   => $LOCATION_DOC_ID,
       'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
   ], ['merge' => true]);

echo json_encode([
    'success'      => true,
    'written_to'   => 'ghl_tokens/' . $LOCATION_DOC_ID,
    'integration'  => 'integrations/' . $intDocId,
    'client_id'    => $locationPayload['client_id'],
    'userType'     => 'Location',
    'token_head'   => substr($companyData['access_token'], 0, 20) . '…',
    'message'      => 'Token copied. Test contacts now. Delete this file when done.',
]);
