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
        $startTime = time();
        $maxExecutionTime = 240; // 4 minutes safety limit

        try {
            // Find all SMS logs with status Queued or Pending (Try both cases)
            // Optimization: Limit to 50 per run and order by date_created (FIFO)
            $query = $db->collection('sms_logs')
                ->where('status', 'in', ['Queued', 'Pending', 'queued', 'pending'])
                ->orderBy('date_created', 'asc')
                ->limit(50);

            $documents = $query->documents();
            
            error_log("[StatusSync] Starting sync session (Limit: 50 messages, Order: Oldest First)");

            // Loop through all pending/queued messages
            foreach ($documents as $doc) {
                // Safety: Check if we are approaching the 5-minute cron interval
                if (time() - $startTime > $maxExecutionTime) {
                    error_log("[StatusSync] Reached 4-minute safety limit. Stopping current session to prevent cron overlap.");
                    break;
                }

                if (!$doc->exists()) {
                    continue;
                }

                $data = $doc->data();
                $messageId = $data['message_id'] ?? null;

                if (!$messageId) {
                    continue;
                }

                // Expiration Filter: Mark as "Expired" if older than 3 days (prevent infinite polling of stuck messages)
                $now = time();
                $dateCreated = null;
                if (isset($data['date_created'])) {
                    $dateCreated = $data['date_created'] instanceof \Google\Cloud\Core\Timestamp 
                        ? $data['date_created']->get()->getTimestamp() 
                        : strtotime((string)$data['date_created']);
                } elseif (isset($data['created_at'])) {
                    $dateCreated = $data['created_at'] instanceof \Google\Cloud\Core\Timestamp 
                        ? $data['created_at']->get()->getTimestamp() 
                        : strtotime((string)$data['created_at']);
                }

                if ($dateCreated && ($now - $dateCreated > 259200)) { // 3 days in seconds
                    $doc->reference()->update([
                        ['path' => 'status', 'value' => 'Expired'],
                        ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())],
                    ]);
                    
                    try {
                        $db->collection('messages')->document($messageId)->update([
                            ['path' => 'status', 'value' => 'Expired'],
                        ]);
                    } catch (\Exception $e) {}
                    
                    error_log("[StatusSync] Message ID $messageId is older than 3 days. Marked as Expired to save API quota.");
                    continue; // Skip the Semaphore API call
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
                    if ($httpCode !== 404) {
                        error_log("[StatusSync] Semaphore API returned HTTP $httpCode for message ID $messageId. response: " . $response);
                    }
                    continue;
                }

                if ($response !== false) {
                    $decoded = json_decode($response, true);

                    // Ensure the response is valid and contains status
                    if ($decoded && is_array($decoded) && isset($decoded[0]['status'])) {
                        $newStatus = $decoded[0]['status'];

                        // Only update if the status is an upgrade (e.g., Pending -> Sent)
                        if (self::shouldUpdateStatus($data['status'], $newStatus)) {

                            // 1. Update the 'sms_logs' collection
                            $doc->reference()->update([
                                ['path' => 'status', 'value' => $newStatus],
                                ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())],
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

                            // ── Delivery Report Notification ──────────────────────────
                            if (in_array($newStatus, ['Delivered', 'Failed', 'delivered', 'failed'])) {
                                try {
                                    $msgLocId = $data['location_id'] ?? null;
                                    if ($msgLocId) {
                                        require_once __DIR__ . '/NotificationService.php';
                                        \NotificationService::notifyDeliveryStatus(
                                            $db, $msgLocId, $messageId, $newStatus,
                                            $data['number'] ?? $data['numbers'][0] ?? 'unknown'
                                        );
                                    }
                                } catch (\Throwable $e) {
                                    error_log('[DeliveryReport] ' . $e->getMessage());
                                }
                            }
                        }
                    }
                    else {
                        error_log("[StatusSync] Empty or unexpected response format for message ID $messageId: " . $response);
                    }
                }

                // Respect Semaphore Rate Limits: 30 calls per minute (1 every 2 seconds)
                usleep(2000000); // 2 seconds delay
            }
        }
        catch (\Exception $e) {
            // Log any critical errors (like Firestore connection issues)
            error_log("[StatusSync] Critical error during sync: " . $e->getMessage());
        }

        return $updatedCount;
    }

    /**
     * Prevents status downgrades (e.g. Sent -> Pending)
     */
    private static function shouldUpdateStatus($current, $new)
    {
        $statusPriority = [
            'Queued'    => 1,
            'queued'    => 1,
            'Pending'   => 2,
            'pending'   => 2,
            'Sent'      => 3,
            'sent'      => 3,
            'Delivered' => 4,
            'delivered' => 4,
            'Failed'    => 4,
            'failed'    => 4,
            'Expired'   => 4,
            'expired'   => 4
        ];

        $newPriority = $statusPriority[$new] ?? 0;
        $oldPriority = $statusPriority[$current] ?? 0;

        // Condition: New priority must be >= old priority, AND status must actually be different
        return ($newPriority >= $oldPriority) && ($new !== $current);
    }
}