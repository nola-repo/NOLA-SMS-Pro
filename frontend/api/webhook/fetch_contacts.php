<?php
header('Content-Type: application/json');

// Enable error reporting for debugging (disable in production)
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require __DIR__ . '/firestore_client.php';

// 1. Authenticate with standardized header
$headers = getallheaders();
$receivedSecret = $headers['X-Webhook-Secret'] ?? $headers['x-webhook-secret'] ?? '';

// Using the same secret as send_sms.php for consistency
if ($receivedSecret !== 'f7RkQ2pL9zV3tX8cB1nS4yW6') {
    http_response_code(401);
    die(json_encode(["status" => "error", "message" => "Unauthorized"]));
}

try {
    // 2. Query Firestore - get all sms_logs to extract unique phone numbers
    $db = get_firestore();

    // Get all documents with number field
    $logs = $db->collection('sms_logs')
        ->select(['number', 'message', 'date_created'])
        ->orderBy('date_created', 'DESC')
        ->limit(1000)
        ->documents();

    $contactsMap = [];
    foreach ($logs as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $number = $data['number'] ?? null;
            
            if ($number && !isset($contactsMap[$number])) {
                // Get the most recent message for this contact
                $lastMessage = $data['message'] ?? '';
                $lastDate = null;
                
                if (isset($data['date_created']) && $data['date_created'] instanceof \Google\Cloud\Core\Timestamp) {
                    $lastDate = $data['date_created']->get()->format('c');
                }
                
                $contactsMap[$number] = [
                    'id' => $number,
                    'phone' => $number,
                    'name' => $number, // Use phone as name since we don't have contact names
                    'lastMessage' => $lastMessage,
                    'lastSentAt' => $lastDate
                ];
            }
        }
    }

    // Convert to array and sort by lastSentAt descending
    $contacts = array_values($contactsMap);
    usort($contacts, function($a, $b) {
        $dateA = $a['lastSentAt'] ?? '';
        $dateB = $b['lastSentAt'] ?? '';
        return strcmp($dateB, $dateA);
    });

    echo json_encode($contacts);
}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Firestore error: " . $e->getMessage()
    ]);
}
