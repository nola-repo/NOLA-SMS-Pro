<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/../webhook/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';

// Standardized auth check
validate_api_request();

// Get location scope
$locationId = get_ghl_location_id();
if (!$locationId) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Missing Location ID (X-GHL-Location-ID header required)"]));
}

// Parameters
$conversation_id = $_GET['conversation_id'] ?? '';
$number = $_GET['number'] ?? '';
$batch_id = $_GET['batch_id'] ?? '';
$recipient_key = $_GET['recipient_key'] ?? '';

try {
    $db = get_firestore();
    $collection = $db->collection('messages');

    if ($conversation_id) {
        $query = $collection
            ->where('location_id', '==', $locationId)
            ->where('conversation_id', '=', $conversation_id);
    }
    elseif ($recipient_key) {
        $query = $collection
            ->where('location_id', '==', $locationId)
            ->where('recipient_key', '=', $recipient_key);
    }
    elseif ($batch_id) {
        $query = $collection
            ->where('location_id', '==', $locationId)
            ->where('batch_id', '=', $batch_id);
    }
    elseif ($number) {
        $query = $collection
            ->where('location_id', '==', $locationId)
            ->where('number', '=', $number);
    }
    else {
        // Default: recent messages for this location
        $query = $collection
            ->where('location_id', '==', $locationId);
    }

    $logs = $query
        ->orderBy('date_created', 'DESC')
        ->limit(100)
        ->documents();

    $results = [];
    foreach ($logs as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();

            if (isset($data['date_created']) && $data['date_created'] instanceof \Google\Cloud\Core\Timestamp) {
                $data['date_created'] = $data['date_created']->get()->format('c');
            }

            $results[] = array_merge(['id' => $doc->id()], $data);
        }
    }

    echo json_encode($results);
}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Firestore error: " . $e->getMessage()
    ]);
}