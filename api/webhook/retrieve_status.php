<?php
require_once __DIR__ . '/../cors.php';
date_default_timezone_set('UTC');

$logFile = __DIR__ . '/logs/cron_execution.log';

file_put_contents(
    $logFile,
    "[" . date('Y-m-d H:i:s') . "] Cron executed\n",
    FILE_APPEND
);


$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';

$apiKey = $config['SEMAPHORE_API_KEY'];
$db = get_firestore();

// Find all SMS logs with status Queued or Pending
$query = $db->collection('sms_logs')
    ->where('status', 'in', ['Queued', 'Pending']);

$documents = $query->documents();

foreach ($documents as $doc) {
    if (!$doc->exists()) {
        continue;
    }

    $data = $doc->data();
    $messageId = $data['message_id'] ?? null;

    if (!$messageId) {
        continue;
    }

    $url = "https://api.semaphore.co/api/v4/messages/$messageId?apikey=$apiKey";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);

    if ($response !== false) {
        $decoded = json_decode($response, true);

        if (isset($decoded[0]['status'])) {
            $newStatus = $decoded[0]['status'];

            // Update in sms_logs
            $doc->reference()->update([
                ['path' => 'status', 'value' => $newStatus],
            ]);

            // Also update the main UI 'messages' collection
            // In our system, the document ID in 'messages' collection is the Semaphore messageId (string)
            $messageRef = $db->collection('messages')->document($messageId);
            try {
                $messageRef->update([
                    ['path' => 'status', 'value' => $newStatus],
                ]);
            }
            catch (\Exception $e) {
                // If the document doesn't exist in 'messages', we can ignore it or log it
                error_log("Failed to update status in 'messages' for ID $messageId: " . $e->getMessage());
            }
        }
    }
}

echo "Status update complete.";