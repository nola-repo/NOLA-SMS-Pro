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
    echo json_encode(["status" => "error", "message" => "Missing Location ID (X-GHL-Location-ID header required)"]);
    exit;
}

try {
    $db = get_firestore();
    $collection = $db->collection('messages');

    // Fetch bulk messages scoped to this location only
    $query = $collection
        ->where('location_id', '==', $locationId)
        ->where('batch_id', '!=', '')
        ->orderBy('batch_id')
        ->orderBy('date_created', 'DESC');

    $logs = $query->limit(1000)->documents();

    // Group by batch_id
    $batches = [];
    foreach ($logs as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $batchId = $data['batch_id'] ?? '';

            if ($batchId) {
                if (isset($data['date_created']) && $data['date_created'] instanceof \Google\Cloud\Core\Timestamp) {
                    $data['date_created'] = $data['date_created']->get()->format('c');
                }

                if (!isset($batches[$batchId])) {
                    $batches[$batchId] = [
                        'batch_id'      => $batchId,
                        'messages'      => [],
                        'recipients'    => [],
                        'first_message' => $data['message'] ?? '',
                        'date_created'  => $data['date_created'],
                        'sender_id'     => $data['sender_id'] ?? 'NOLACRM',
                    ];
                }

                $batches[$batchId]['messages'][] = array_merge(['id' => $doc->id()], $data);

                $number = $data['number'] ?? '';
                if ($number && !in_array($number, $batches[$batchId]['recipients'])) {
                    $batches[$batchId]['recipients'][] = $number;
                }

                // Track oldest timestamp as the campaign's start time
                $thisDate  = strtotime($data['date_created'] ?? 0);
                $firstDate = strtotime($batches[$batchId]['date_created'] ?? 0);
                if ($thisDate < $firstDate) {
                    $batches[$batchId]['first_message'] = $data['message'] ?? '';
                    $batches[$batchId]['date_created']  = $data['date_created'];
                }
            }
        }
    }

    $results = array_values(array_map(function ($batch) {
        return [
            'batch_id'        => $batch['batch_id'],
            'message'         => $batch['first_message'],
            'recipientCount'  => count($batch['recipients']),
            'recipientNumbers'=> $batch['recipients'],
            'timestamp'       => $batch['date_created'],
            'sender_id'       => $batch['sender_id'],
            'messageCount'    => count($batch['messages']),
        ];
    }, $batches));

    usort($results, function ($a, $b) {
        return strtotime($b['timestamp'] ?? 0) - strtotime($a['timestamp'] ?? 0);
    });

    echo json_encode($results);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Firestore error: " . $e->getMessage()
    ]);
}