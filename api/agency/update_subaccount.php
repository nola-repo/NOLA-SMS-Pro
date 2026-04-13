<?php
/**
 * POST /api/agency/update_subaccount
 * 
 * Updates the SMS settings (toggle, rate limit, attempt resets) 
 * for a specific subaccount inside ghl_tokens.
 */
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
$agencyId = validate_agency_request();
$input = json_decode(file_get_contents('php://input'), true);
$locationId = $input['location_id'] ?? '';
if (!$locationId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing location_id.']);
    exit;
}
try {
    $db = get_firestore();
    
    // Validate that the location actually belongs to this agency
    $docRef = $db->collection('agency_subaccounts')->document($locationId);
    $snapshot = $docRef->snapshot();
    
    if (!$snapshot->exists() || trim($snapshot->data()['agency_id'] ?? '') !== trim($agencyId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Subaccount not found for this agency.']);
        exit;
    }
    $currentData = $snapshot->data();
    
    $toggleEnabled = isset($input['toggle_enabled']) ? (bool)$input['toggle_enabled'] : ($currentData['toggle_enabled'] ?? true);
    $rateLimit = isset($input['rate_limit']) ? (int)$input['rate_limit'] : ($currentData['rate_limit'] ?? 5);
    $resetCounter = isset($input['reset_counter']) ? (bool)$input['reset_counter'] : false;
    
    $updates = [
        'toggle_enabled' => $toggleEnabled,
        'rate_limit' => $rateLimit,
        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable())
    ];
    
    // Enforce 3 max activations for "toggle_enabled"
    if ($toggleEnabled && !($currentData['toggle_enabled'] ?? false)) {
        $activations = (int)($currentData['toggle_activation_count'] ?? 0);
        if ($activations >= 3) {
            http_response_code(403);
            echo json_encode(['error' => 'Activation Limit Reached', 'status' => 'limit_reached']);
            exit;
        }
        $updates['toggle_activation_count'] = $activations + 1;
    }
    // Reset attempt_count logic
    if ($resetCounter) {
        $updates['attempt_count'] = 0;
    }
    // Apply updates
    $docRef->set($updates, ['merge' => true]);

    // ── Mirror toggle_enabled into ghl_tokens (enforcement layer) ──────────────
    $tokenRef = $db->collection('ghl_tokens')->document($locationId);
    $tokenSnap = $tokenRef->snapshot();
    if ($tokenSnap->exists()) {
        $tokenRef->set([
            'toggle_enabled' => $toggleEnabled,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable())
        ], ['merge' => true]);
    }

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed: ' . $e->getMessage()]);
}
