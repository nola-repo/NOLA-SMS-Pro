<?php
/**
 * POST|PATCH /api/agency/toggle_subaccount
 *
 * Accepts both calling conventions so the frontend works regardless of
 * which field names / HTTP method it sends:
 *
 *  Modern (POST from agency.ts):
 *    { "location_id": "<id>", "enabled": true|false }
 *
 *  Legacy (PATCH):
 *    { "subaccount_id": "<id>", "enabled": true|false }
 *
 * Delegates fully to the same logic as update_subaccount.php so both
 * collections (agency_subaccounts + ghl_tokens) stay in sync.
 */
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

// Accept POST or PATCH — reject everything else
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
$agencyId = validate_agency_request(true);

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

// Normalise field names — accept location_id or subaccount_id
$locationId = $payload['location_id'] ?? $payload['subaccount_id'] ?? null;

if (!$locationId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
    exit;
}

// Normalise toggle field — accept enabled or toggle_enabled
if (isset($payload['enabled'])) {
    $enabled = filter_var($payload['enabled'], FILTER_VALIDATE_BOOLEAN);
} elseif (isset($payload['toggle_enabled'])) {
    $enabled = filter_var($payload['toggle_enabled'], FILTER_VALIDATE_BOOLEAN);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing enabled flag']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';

try {
    $db     = get_firestore();
    $docRef = $db->collection('agency_subaccounts')->document($locationId);
    $snap   = $docRef->snapshot();

    if (!$snap->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Subaccount not found']);
        exit;
    }

    $data = $snap->data();

    // Ownership check
    if (trim($data['agency_id'] ?? '') !== trim($agencyId)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: Subaccount does not belong to your agency']);
        exit;
    }

    $currentlyEnabled    = (bool)($data['toggle_enabled'] ?? false);
    $activation_count    = (int)($data['toggle_activation_count'] ?? 0);

    // Enforce 3-activation limit only when turning ON from OFF
    if ($enabled && !$currentlyEnabled) {
        if ($activation_count >= 3) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'limit_reached',
                'error'   => 'Activation Limit Reached',
                'message' => 'Max activation limit reached. Please upgrade to add more.'
            ]);
            exit;
        }
        $activation_count++;
    }

    $updateData = [
        'toggle_enabled'          => $enabled,
        'toggle_activation_count' => $activation_count,
        'updated_at'              => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
    ];

    // Write 1: agency_subaccounts (UI display source)
    $docRef->set($updateData, ['merge' => true]);

    // Write 2: ghl_tokens (enforcement layer for ghl_provider + send_sms)
    $tokenRef  = $db->collection('ghl_tokens')->document($locationId);
    $tokenSnap = $tokenRef->snapshot();
    if ($tokenSnap->exists()) {
        $tokenRef->set([
            'toggle_enabled' => $enabled,
            'updated_at'     => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
        ], ['merge' => true]);
    }

    echo json_encode([
        'status'                  => 'success',
        'subaccount_id'           => $locationId,
        'toggle_enabled'          => $enabled,
        'toggle_activation_count' => $activation_count,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()]);
}
