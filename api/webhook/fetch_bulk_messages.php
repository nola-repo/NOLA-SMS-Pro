<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// CORS + preflight
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Webhook-Secret');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';

validate_api_request();

try {
    $db = get_firestore();
    $collection = $db->collection('messages');

    // Campaigns = outbound messages with a batch_id
    // Use where('batch_id', '>', '') as a reliable non-empty filter
    $query = $collection
        ->where('direction', '==', 'outbound')
        ->where('batch_id', '>', '')
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
                $ts = null;
                if (isset($data['date_created']) && $data['date_created'] instanceof \Google\Cloud\Core\Timestamp) {
                    $ts = $data['date_created']->get()->format('c');
                } elseif (isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
                    $ts = $data['created_at']->get()->format('c');
                }

                if (!isset($batches[$batchId])) {
                    $batches[$batchId] = [
                        'batch_id' => $batchId,
                        'recipients' => [],
                        'first_message' => $data['message'] ?? '',
                        'date_created' => $ts,
                        'sender_id' => $data['sender_id'] ?? 'NOLACRM',
                    ];
                }

                // Add unique recipients
                $number = $data['number'] ?? '';
                if ($number && !in_array($number, $batches[$batchId]['recipients'], true)) {
                    $batches[$batchId]['recipients'][] = $number;
                }

                // Update first message if this is older
                $thisDate = strtotime($ts ?? 0);
                $firstDate = strtotime($batches[$batchId]['date_created'] ?? 0);
                if ($thisDate < $firstDate) {
                    $batches[$batchId]['first_message'] = $data['message'] ?? '';
                    $batches[$batchId]['date_created'] = $ts;
                }
            }
        }
    }

    // Convert to array and add metadata
    $results = array_values(array_map(function ($batch) {
        return [
        'batch_id' => $batch['batch_id'],
        'message' => $batch['first_message'],
        'recipientCount' => count($batch['recipients']),
        'recipientNumbers' => $batch['recipients'],
        'timestamp' => $batch['date_created'],
        'sender_id' => $batch['sender_id'],
        'messageCount' => count($batch['recipients']),
        ];
    }, $batches));

    // Sort by most recent first (Firestore now returns results ordered by batch_id)
    usort($results, function ($a, $b) {
        return strtotime($b['timestamp'] ?? 0) - strtotime($a['timestamp'] ?? 0);
    });

    echo json_encode($results);
}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Firestore error: " . $e->getMessage()
    ]);
}