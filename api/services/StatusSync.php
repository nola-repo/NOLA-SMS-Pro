<?php

namespace Nola\Services;

class StatusSync
{
    /**
     * Syncs SMS statuses from Semaphore API to Firestore.
     * Designed to be stateless and Cloud Run compatible.
     * 
     * @param \Google\Cloud\Firestore\FirestoreClient $db Firestore client instance
     * @param string $apiKey Semaphore API key
     * @return int Number of messages successfully updated
     */
    public static function runSync($db, $apiKey)
    {
        $updatedCount = 0;

        try {
            // Find all SMS logs with status Queued or Pending
            $query = $db->collection('sms_logs')
                ->where('status', 'in', ['Queued', 'Pending']);

            $documents = $query->documents();

            // Loop through all pending/queued messages
            foreach ($documents as $doc) {
                if (!$doc->exists()) {
                    continue;
                }

                $data = $doc->data();
                $messageId = $data['message_id'] ?? null;

                if (!$messageId) {
                    continue;
                }

                // Call Semaphore API to get the latest status
                $url = "https://api.semaphore.co/api/v4/messages/$messageId?apikey=$apiKey";

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch)) {
                    error_log("[StatusSync] cURL error for message ID $messageId: " . curl_error($ch));
                    curl_close($ch);
                    continue; // Skip this iteration, try again next cron run
                }
                curl_close($ch);

                if ($httpCode !== 200) {
                    error_log("[StatusSync] Semaphore API returned HTTP $httpCode for message ID $messageId. response: " . $response);
                    continue;
                }

                if ($response !== false) {
                    $decoded = json_decode($response, true);

                    // Ensure the response is valid and contains status
                    if ($decoded && is_array($decoded) && isset($decoded[0]['status'])) {
                        $newStatus = $decoded[0]['status'];

                        // Only update if the status actually changed
                        if ($newStatus !== $data['status']) {

                            // 1. Update the 'sms_logs' collection
                            $doc->reference()->update([
                                ['path' => 'status', 'value' => $newStatus],
                            ]);

                            // 2. Update the main UI 'messages' collection
                            // The document ID in 'messages' collection is the Semaphore messageId (string)
                            $messageRef = $db->collection('messages')->document($messageId);
                            try {
                                $messageRef->update([
                                    ['path' => 'status', 'value' => $newStatus],
                                ]);
                            }
                            catch (\Exception $e) {
                                // Log the error if the 'messages' document doesn't exist, but don't crash
                                error_log("[StatusSync] Failed to update status in 'messages' collection for ID $messageId: " . $e->getMessage());
                            }

                            $updatedCount++;
                            error_log("[StatusSync] Successfully updated message ID $messageId to status: $newStatus");
                        }
                    }
                    else {
                        error_log("[StatusSync] Empty or unexpected response format for message ID $messageId: " . $response);
                    }
                }
            }
        }
        catch (\Exception $e) {
            // Log any critical errors (like Firestore connection issues)
            error_log("[StatusSync] Critical error during sync: " . $e->getMessage());
        }

        return $updatedCount;
    }
}