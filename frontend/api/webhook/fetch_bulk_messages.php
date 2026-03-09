// CORS Headers
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-Webhook-Secret, Content-Type');
header('Access-Control-Max-Age: 86400');

// Handle OPTIONS preflight request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

require __DIR__ . '/firestore_client.php';

// Authenticate
$headers = getallheaders();
$receivedSecret = $headers['X-Webhook-Secret'] ?? $headers['x-webhook-secret'] ?? '';

if ($receivedSecret !== 'f7RkQ2pL9zV3tX8cB1nS4yW6') {
    http_response_code(401);
    die(json_encode(["status" => "error", "message" => "Unauthorized"]));
}

try {
    $db = get_firestore();
    // Search in 'messages' collection which is the new standard
    $collection = $db->collection('messages');

    // Get all outbound messages with batch_id.
    // Ensure direction is outbound and batch_id exists and is not empty.
    $query = $collection->where('direction', '=', 'outbound')
        ->where('batch_id', '!=', '')
        ->orderBy('batch_id')
        ->orderBy('date_created', 'DESC');

    $logs = $query->limit(500)->documents();

    // Group by batch_id
    $batches = [];
    foreach ($logs as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $batchId = $data['batch_id'] ?? '';

            if ($batchId) {
                // Format timestamp
                if (isset($data['date_created']) && $data['date_created'] instanceof \Google\Cloud\Core\Timestamp) {
                    $data['date_created'] = $data['date_created']->get()->format('c');
                }

                if (!isset($batches[$batchId])) {
                    $batches[$batchId] = [
                        'batch_id' => $batchId,
                        'messages' => [],
                        'recipients' => [],
                        'first_message' => $data['message'] ?? '',
                        'date_created' => $data['date_created'],
                        'sender_id' => $data['sender_id'] ?? 'NOLACRM',
                    ];
                }

                $batches[$batchId]['messages'][] = array_merge(['id' => $doc->id()], $data);

                // Add unique recipients
                $number = $data['number'] ?? '';
                if ($number && !in_array($number, $batches[$batchId]['recipients'])) {
                    $batches[$batchId]['recipients'][] = $number;
                }

                // Update first message if this is older
                $thisDate = strtotime($data['date_created'] ?? 0);
                $firstDate = strtotime($batches[$batchId]['date_created'] ?? 0);
                if ($thisDate < $firstDate) {
                    $batches[$batchId]['first_message'] = $data['message'] ?? '';
                    $batches[$batchId]['date_created'] = $data['date_created'];
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
        'messageCount' => count($batch['messages']),
        ];
    }, $batches));

    // Sort by most recent first
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
