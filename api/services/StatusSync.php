<?php

namespace Nola\Services;

class StatusSync
{
    /**
     * Syncs SMS statuses from Semaphore API to Firestore.
     * Designed to be stateless and Cloud Run compatible.
     * 
     * @param \Google\Cloud\Firestore\FirestoreClient $db Firestore client instance
     * @param string $systemApiKey Semaphore API key
     * @return int Number of messages successfully updated
     */
    public static function runSync($db, $systemApiKey)
    {
        $updatedCount = 0;
        $startTime = time();
        $maxExecutionTime = 240; // 4 minutes safety limit
        $apiKeyCache = [];

        try {
            // Find all SMS logs in a non-final state
            $query = $db->collection('sms_logs')
                ->where('status', 'in', ['Sending', 'Queued', 'Pending', 'queued', 'pending', 'sending'])
                ->limit(100);

            $documents = $query->documents();
            error_log("[StatusSync] Starting sync session (Limit: 100)");

            foreach ($documents as $doc) {
                if (time() - $startTime > $maxExecutionTime) {
                    error_log("[StatusSync] Approaching timeout. Stopping.");
                    break;
                }

                if (!$doc->exists()) continue;

                $data = $doc->data();
                $messageId = (string)($data['message_id'] ?? '');
                $locId = $data['location_id'] ?? '';
                $currentStatus = $data['status'] ?? '';

                if (!$messageId) continue;

                // ── API Key Selection ──────────────────────────────────────
                $activeApiKey = $systemApiKey;
                if ($locId) {
                    if (!isset($apiKeyCache[$locId])) {
                        try {
                            $intDoc = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
                            $snap = $db->collection('integrations')->document($intDoc)->snapshot();
                            if ($snap->exists()) {
                                $idat = $snap->data();
                                $activeApiKey = $idat['nola_pro_api_key'] ?? ($idat['semaphore_api_key'] ?? $systemApiKey);
                            }
                        } catch (\Exception $e) {
                            error_log("[StatusSync] Error fetching key for $locId: " . $e->getMessage());
                        }
                        $apiKeyCache[$locId] = $activeApiKey;
                    }
                    $activeApiKey = $apiKeyCache[$locId];
                }

                // ── Expiration Filter (3 Days) ─────────────────────────────
                $now = time();
                $dateCreated = self::parseTs($data['date_created'] ?? $data['created_at'] ?? null);
                if ($dateCreated && ($now - $dateCreated > 259200)) {
                    self::finalize($db, $doc, $messageId, 'Failed', 'Status check timeout (3 days)');
                    $updatedCount++;
                    continue;
                }

                // ── Semaphore API Call ────────────────────────────────
                $url = "https://api.semaphore.co/api/v4/messages/$messageId?apikey=$activeApiKey";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 404) {
                    $retries = ($data['sync_retries'] ?? 0) + 1;
                    if ($retries >= 3) {
                        self::finalize($db, $doc, $messageId, 'Failed', 'Message not found on provider after retries');
                        $updatedCount++;
                    } else {
                        $doc->reference()->update([
                            ['path' => 'sync_retries', 'value' => $retries],
                            ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())]
                        ]);
                    }
                    continue;
                }

                if ($httpCode === 200 && $resp) {
                    $decoded = json_decode($resp, true);
                    if ($decoded && is_array($decoded) && isset($decoded[0]['status'])) {
                        $rawStatus = strtolower($decoded[0]['status']);
                        
                        // Strict 3-state Mapping
                        $newStatus = 'Sending';
                        if (in_array($rawStatus, ['sent', 'success', 'delivered'])) {
                            $newStatus = 'Sent';
                        } elseif (in_array($rawStatus, ['failed', 'expired', 'rejected', 'undelivered'])) {
                            $newStatus = 'Failed';
                        }

                        if (self::isUpgrade($currentStatus, $newStatus)) {
                            self::finalize($db, $doc, $messageId, $newStatus);
                            $updatedCount++;
                            error_log("[StatusSync] $messageId: $currentStatus -> $newStatus (raw: $rawStatus)");
                        } else {
                            $doc->reference()->update([
                                ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())]
                            ]);
                        }
                    }
                }

                usleep(500000); 
            }
        } catch (\Exception $e) {
            error_log("[StatusSync] Critical error: " . $e->getMessage());
        }

        return $updatedCount;
    }

    private static function parseTs($ts) {
        if (!$ts) return null;
        if ($ts instanceof \Google\Cloud\Core\Timestamp) return $ts->get()->getTimestamp();
        return is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
    }

    private static function finalize($db, $doc, $messageId, $status, $reason = null) {
        $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());
        $updates = [['path' => 'status', 'value' => $status], ['path' => 'updated_at', 'value' => $ts]];
        if ($reason) $updates[] = ['path' => 'error_reason', 'value' => $reason];
        
        $doc->reference()->update($updates);
        try {
            $db->collection('messages')->document($messageId)->update($updates);
        } catch (\Exception $e) {}

        if ($status === 'Sent' || $status === 'Failed') {
            try {
                $data = $doc->data();
                $locId = $data['location_id'] ?? null;
                if ($locId) {
                    require_once __DIR__ . '/NotificationService.php';
                    \NotificationService::notifyDeliveryStatus($db, $locId, $messageId, $status, $data['number'] ?? 'unknown');
                }
            } catch (\Exception $e) {}
        }
    }

    private static function isUpgrade($curr, $new) {
        $priority = [
            'Queued' => 1, 'Pending' => 1, 'Sending' => 1,
            'queued' => 1, 'pending' => 1, 'sending' => 1,
            'Sent' => 2, 'Failed' => 2
        ];
        $pCurr = $priority[$curr] ?? 0;
        $pNew = $priority[$new] ?? 0;

        // 1. Progression (In-Process -> Resolved)
        if ($pNew > $pCurr) return true;

        // 2. Legacy Cleanup (Pending/Queued -> Sending)
        if ($pCurr === 1 && $pNew === 1 && $curr !== 'Sending' && $new === 'Sending') return true;

        return false;
    }
}