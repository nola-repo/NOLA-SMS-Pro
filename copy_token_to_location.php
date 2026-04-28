<?php
/**
 * copy_token_to_location.php — One-time fix script
 * Copies the subaccount-app company token into the specific location document.
 *
 * Usage: https://smspro-api.nolacrm.io/copy_token_to_location.php?key=nola_fix_2026
 * Optional: add &from=DOC_ID to specify the source document ID explicitly.
 * DELETE THIS FILE after running.
 */

if (($_GET['key'] ?? '') !== 'nola_fix_2026') {
    http_response_code(403);
    die('Forbidden');
}

require __DIR__ . '/api/webhook/firestore_client.php';
header('Content-Type: application/json');

$db = get_firestore();

$LOCATION_DOC_ID = 'ugBqfQsPtGijLjrmLdmA';
$SUBACCOUNT_CLIENT_ID = '6999da2b8f278296d95f7274-mmn30t4f';

// ── Step 1: Find source doc ───────────────────────────────────────────────────
$companyData   = null;
$COMPANY_DOC_ID = $_GET['from'] ?? null;

if ($COMPANY_DOC_ID) {
    // Explicit doc ID provided via ?from=
    $snap = $db->collection('ghl_tokens')->document($COMPANY_DOC_ID)->snapshot();
    if ($snap->exists()) {
        $companyData = $snap->data();
    }
} else {
    // Auto-find: query for subaccount-app tokens (excluding the location doc itself)
    $query = $db->collection('ghl_tokens')
        ->where('client_id', '==', $SUBACCOUNT_CLIENT_ID)
        ->where('appType', '==', 'subaccount')
        ->orderBy('updated_at', 'DESC')
        ->limit(5);

    $docs = [];
    foreach ($query->documents() as $doc) {
        if (!$doc->exists()) continue;
        if ($doc->id() === $LOCATION_DOC_ID) continue; // skip target doc
        $docs[] = ['id' => $doc->id(), 'data' => $doc->data()];
    }

    if (empty($docs)) {
        // Fallback: list ALL ghl_tokens docs so user can pick
        $allDocs = [];
        foreach ($db->collection('ghl_tokens')->documents() as $doc) {
            if (!$doc->exists()) continue;
            $d = $doc->data();
            $allDocs[] = [
                'id'         => $doc->id(),
                'client_id'  => $d['client_id'] ?? $d['appId'] ?? '(none)',
                'appType'    => $d['appType'] ?? '?',
                'userType'   => $d['userType'] ?? '?',
                'has_token'  => !empty($d['access_token']),
                'updated_at' => isset($d['updated_at']) ? (string)$d['updated_at'] : '?',
            ];
        }
        echo json_encode([
            'error'       => 'No subaccount-app token doc found automatically.',
            'instruction' => 'Add &from=DOC_ID using one of the IDs below that has has_token=true and client_id ending in mmn30t4f.',
            'all_docs'    => $allDocs,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Use the first (most recent) match
    $COMPANY_DOC_ID = $docs[0]['id'];
    $companyData    = $docs[0]['data'];
}

if (!$companyData || empty($companyData['access_token'])) {
    echo json_encode([
        'error'   => 'Source doc has no access_token.',
        'doc_id'  => $COMPANY_DOC_ID,
        'tip'     => 'Pass &from=CORRECT_DOC_ID to specify the right source.',
    ]);
    exit;
}

// ── Step 2: Write location-scoped token ──────────────────────────────────────
$now = new DateTimeImmutable();
$locationPayload = [
    'access_token'  => $companyData['access_token'],
    'refresh_token' => $companyData['refresh_token'],
    'expires_at'    => $companyData['expires_at'] ?? null,
    'client_id'     => $companyData['client_id'] ?? $companyData['appId'] ?? $SUBACCOUNT_CLIENT_ID,
    'appId'         => $companyData['appId']      ?? $companyData['client_id'] ?? $SUBACCOUNT_CLIENT_ID,
    'appType'       => 'subaccount',
    'userType'      => 'Location',
    'location_id'   => $LOCATION_DOC_ID,
    'location_name' => $companyData['company_name'] ?? $companyData['location_name'] ?? 'NOLA CRM',
    'companyId'     => $companyData['companyId'] ?? $COMPANY_DOC_ID,
    'scope'         => $companyData['scope'] ?? null,
    'is_live'       => true,
    'toggle_enabled'=> true,
    'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
    'copied_from'   => $COMPANY_DOC_ID,
];

$db->collection('ghl_tokens')->document($LOCATION_DOC_ID)->set($locationPayload, ['merge' => true]);

// ── Step 3: Also sync to integrations doc ────────────────────────────────────
$intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $LOCATION_DOC_ID);
$db->collection('integrations')->document($intDocId)->set([
    'access_token'  => $companyData['access_token'],
    'refresh_token' => $companyData['refresh_token'],
    'expires_at'    => $companyData['expires_at'] ?? null,
    'client_id'     => $locationPayload['client_id'],
    'app_type'      => 'subaccount',
    'location_id'   => $LOCATION_DOC_ID,
    'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
], ['merge' => true]);

echo json_encode([
    'success'       => true,
    'source_doc'    => $COMPANY_DOC_ID,
    'written_to'    => 'ghl_tokens/' . $LOCATION_DOC_ID,
    'integration'   => 'integrations/' . $intDocId,
    'client_id'     => $locationPayload['client_id'],
    'userType'      => 'Location',
    'token_head'    => substr($companyData['access_token'], 0, 20) . '…',
    'message'       => 'Done. Test contacts. Delete this file when done.',
], JSON_PRETTY_PRINT);
