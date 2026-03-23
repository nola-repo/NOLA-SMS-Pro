<?php
require_once __DIR__ . '/../cors.php';
date_default_timezone_set('UTC');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/cron_execution.log';

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
$keyCache = []; // locationId => apiKey

foreach ($documents as $doc) {
    if (!$doc->exists()) {
        continue;
    }

    $data = $doc->data();
    $messageId = $data['message_id'] ?? null;
    $locId = $data['location_id'] ?? null;

    if (!$messageId) {
        continue;
    }

    // Determine the correct API key for this location
    $activeKey = $apiKey; // Default to system key
    if ($locId) {
        if (isset($keyCache[$locId])) {
            $activeKey = $keyCache[$locId];
        } else {
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
            $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
            if ($intSnap->exists()) {
                $intData = $intSnap->data();
                $customKey = $intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? null);
                if ($customKey) {
                    $activeKey = $customKey;
                }
            }
            $keyCache[$locId] = $activeKey;
        }
    }

    $url = "https://api.semaphore.co/api/v4/messages/$messageId?apikey=$activeKey";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);

    if ($response !== false) {
        $decoded = json_decode($response, true);

        if (isset($decoded[0]['status'])) {
            $newStatus = $decoded[0]['status'];

            // Status Guard: Only update if the new status is not a "downgrade" 
            // (e.g. don't overwrite "Sent" with "Pending" if the API is lagging)
            $currentStatus = $data['status'] ?? 'Queued';
            $statusPriority = [
                'Queued'    => 1,
                'Pending'   => 2,
                'Sent'      => 3,
                'Delivered' => 4,
                'Failed'    => 4,
                'Expired'   => 4
            ];

            $newPriority = $statusPriority[$newStatus] ?? 0;
            $oldPriority = $statusPriority[$currentStatus] ?? 0;

            // Only update if it's an upgrade or the same priority but different status
            if ($newPriority < $oldPriority) {
                continue; // Skip downgrade
            }

            if ($newStatus === $currentStatus) {
                continue; // No change needed
            }

            // Update in sms_logs
            $doc->reference()->update([
                ['path' => 'status', 'value' => $newStatus],
                ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())],
            ]);

            // Also update the main UI 'messages' collection
            $messageRef = $db->collection('messages')->document($messageId);
            try {
                $messageRef->update([
                    ['path' => 'status', 'value' => $newStatus],
                    ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())],
                ]);
            }
            catch (\Exception $e) {
                // Ignore if not present in messages
            }
        }
    }
}

echo "Status update complete.";