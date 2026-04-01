<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/auth_helper.php';
$agency_id = validate_agency_request(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$location_id = $payload['location_id'] ?? null;
if (!$location_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
    exit;
}

try {
    $docRef = $db->collection('agency_subaccounts')->document($location_id);
    $snapshot = $docRef->snapshot();

    if (!$snapshot->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Sub-account not found']);
        exit;
    }

    $updateData = [];
    if (isset($payload['toggle_enabled'])) {
        $isTurningOn = (bool)$payload['toggle_enabled'];
        $currentlyEnabled = $snapshot->data()['toggle_enabled'] ?? false;

        // Validations if they are trying to activate this sub-account
        if ($isTurningOn && !$currentlyEnabled) {
            // Fetch agency max limit from users collection
            $maxActive = 3; // default fallback
            $userQuery = $db->collection('users')->where('company_id', '=', $agency_id)->limit(1)->documents();
            foreach ($userQuery as $uDoc) {
                if ($uDoc->exists()) {
                    $maxActive = $uDoc->data()['max_active_subaccounts'] ?? 3;
                    break;
                }
            }
            
            // Count exactly how many are currently flipped to true for this agency
            $activeCountQuery = $db->collection('agency_subaccounts')
                ->where('agency_id', '=', $agency_id)
                ->where('toggle_enabled', '=', true)
                ->documents();
            
            $activeCount = 0;
            foreach ($activeCountQuery as $doc) {
                if ($doc->exists()) {
                    $activeCount++;
                }
            }

            if ($activeCount >= $maxActive) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => "Limit reached. You can only activate {$maxActive} sub-accounts. Please upgrade your plan or disable another account first."]);
                exit;
            }
        }
        
        $updateData['toggle_enabled'] = $isTurningOn;
    }
    if (isset($payload['rate_limit'])) {
        $updateData['rate_limit'] = (int)$payload['rate_limit'];
    }
    if (isset($payload['reset_counter']) && $payload['reset_counter'] === true) {
        $updateData['attempt_count'] = 0;
    }

    if (!empty($updateData)) {
        $updateData['updated_at'] = new \Google\Cloud\Core\Timestamp(new \DateTime());
        $docRef->set($updateData, ['merge' => true]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Sub-account updated successfully']);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
