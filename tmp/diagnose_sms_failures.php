<?php
// Diagnostic Script to check SMS failure reasons for a specific subaccount
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../api/webhook/config.php';
require_once __DIR__ . '/../api/webhook/firestore_client.php';

$db = get_firestore();

// Location from the user's issue
$locationId = 'ugBqfQsPtGijLjrmLdmA';

echo "==================================================\n";
echo "   Diagnostic Report for Location: $locationId\n";
echo "==================================================\n\n";

try {
    // 1. Check Integration Configuration
    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    $snap = $db->collection('integrations')->document($intDocId)->snapshot();
    
    echo "[1. Integration / API Key Check]\n";
    if ($snap->exists()) {
        $data = $snap->data();
        $apiKey = $data['nola_pro_api_key'] ?? $data['semaphore_api_key'] ?? 'MISSING';
        echo "Document ID: " . $intDocId . " => FOUND\n";
        echo "Approved Sender ID: " . ($data['approved_sender_id'] ?? 'Not set') . "\n";
        echo "Has Custom API Key: " . ($apiKey !== 'MISSING' ? 'Yes' : 'No (will use system default API key)') . "\n";
    } else {
        echo "Document ID: " . $intDocId . " => NOT FOUND\n";
        echo "This subaccount has no custom integrations set up. Will use system default keys.\n";
    }
    echo "\n";

    // 2. Check Recent Messages
    echo "[2. Status of Recent SMS Logs]\n";
    echo "Fetching last 15 messages...\n";
    echo str_repeat('-', 50) . "\n";

    $query = $db->collection('sms_logs')
        ->where('location_id', '=', $locationId)
        ->orderBy('date_created', 'desc')
        ->limit(15);

    $docs = $query->documents();
    
    $count = 0;
    foreach ($docs as $doc) {
        $count++;
        $data = $doc->data();
        $status = $data['status'] ?? 'Unknown';
        $errorReason = $data['error_reason'] ?? 'None provided';
        
        // Parse date_created
        $dateCreatedObj = $data['date_created'] ?? null;
        $dateCreated = 'Unknown';
        if ($dateCreatedObj && method_exists($dateCreatedObj, 'get')) {
             $dateCreated = $dateCreatedObj->get()->format('Y-m-d H:i:s') . ' UTC';
        } elseif (is_array($dateCreatedObj) && isset($dateCreatedObj['seconds'])) {
             $dateCreated = date('Y-m-d H:i:s', $dateCreatedObj['seconds']) . ' UTC';
        } elseif (is_string($dateCreatedObj) || is_numeric($dateCreatedObj)) {
            $dateCreated = date('Y-m-d H:i:s', (int)$dateCreatedObj) . ' UTC';
        }
        
        // Parse updated_at
        $updatedAtObj = $data['updated_at'] ?? null;
        $updatedAt = 'Unknown';
        if ($updatedAtObj && method_exists($updatedAtObj, 'get')) {
             $updatedAt = $updatedAtObj->get()->format('Y-m-d H:i:s') . ' UTC';
        } elseif (is_array($updatedAtObj) && isset($updatedAtObj['seconds'])) {
             $updatedAt = date('Y-m-d H:i:s', $updatedAtObj['seconds']) . ' UTC';
        }

        echo "Message ID   : " . ($data['message_id'] ?? 'N/A') . "\n";
        echo "To Number    : " . (implode(', ', (array)($data['numbers'] ?? []))) . "\n";
        echo "Status       : " . $status . "\n";
        echo "Date Created : " . $dateCreated . "\n";
        echo "Last Updated : " . $updatedAt . "\n";
        
        if (strtolower($status) === 'failed') {
            echo "⚠️  ERROR REASON: " . $errorReason . "\n";
        }
        echo str_repeat('-', 50) . "\n";
    }

    if ($count === 0) {
        echo "No messages found for this location_id in the recent logs.\n";
    }

} catch (\Exception $e) {
    echo "Error running diagnostic: " . $e->getMessage() . "\n";
}
echo "\nDone.\n";
