<?php

namespace Nola\Services;

class StatusSync
{
    /**
     * @var GhlSyncService[] Cache of GHL sync services per location
     */
    private static $ghlSyncServices = [];

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

        // Instantiate gateway once for the whole sync session (avoids one Firestore
        // config read per message inside the loop).
        require_once __DIR__ . '/SmsGatewayService.php';
        $gateway = new SmsGatewayService();
        $startTime = time();
        $maxExecutionTime = 240; // 4 minutes safety limit
        $apiKeyCache = [];

        try {
            // Find all SMS logs in a non-final state
            $query = $db->collection('sms_logs')
                ->where('status', 'in', ['Sending', 'Queued', 'Pending', 'queued', 'pending', 'sending'])
                ->orderBy('date_created', 'asc')
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



                // ── Dynamic Provider API Call ────────────────────────────────
                $providerName = $data['provider'] ?? 'semaphore';
                $providerInstance = $gateway->getProviderInstance($providerName);

                try {
                    $statusRes = $providerInstance->checkStatus($messageId, $activeApiKey);
                    $rawStatus = $statusRes['status'] ?? 'sending';

                    if ($rawStatus === 'not_found') {
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

                    if ($rawStatus !== 'error') {
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
                            error_log("[StatusSync] $messageId ($providerName): $currentStatus -> $newStatus (raw: $rawStatus)");
                        } else {
                            $doc->reference()->update([
                                ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())]
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("[StatusSync] Check status failed for $messageId on $providerName: " . $e->getMessage());
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

    /**
     * Inline single message status checker.
     * Throttled to max 1 check per 8 seconds per message.
     * Max 3 checks per execution flow (managed by a static request counter).
     */
    private static $inlineCheckCount = 0;
    private static $maxInlineChecks = 3;

    public static function checkAndSyncSingleMessage($db, &$data, $messageId, $systemApiKey, &$apiKeyCache)
    {
        // 1. Only process outbound messages that are in a non-final state
        $status = $data['status'] ?? '';
        if (!in_array(strtolower($status), ['queued', 'pending', 'sending'])) {
            return;
        }

        // 2. Global request limit guard (max 3 checks per request)
        if (self::$inlineCheckCount >= self::$maxInlineChecks) {
            return;
        }

        // 3. Time window guard: only check messages sent in the last 15 minutes
        $now = time();
        $dateCreated = self::parseTs($data['date_created'] ?? $data['created_at'] ?? null);
        if (!$dateCreated || ($now - $dateCreated > 900)) {
            // Let background cron handle older ones
            return;
        }

        // 4. Throttle guard: only check once every 8 seconds
        $updatedAt = self::parseTs($data['updated_at'] ?? null);
        if ($updatedAt && ($now - $updatedAt < 8)) {
            return;
        }

        // Increment our active request check counter
        self::$inlineCheckCount++;

        // 5. Select the correct API key
        $locId = $data['location_id'] ?? '';
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
                    error_log("[StatusSync] Inline key fetch error for $locId: " . $e->getMessage());
                }
                $apiKeyCache[$locId] = $activeApiKey;
            }
            $activeApiKey = $apiKeyCache[$locId];
        }

        // 6. Make the Live API Call to Gateway
        try {
            // Re-use a cached gateway singleton to avoid repeated Firestore reads
            // across the multiple inline checks within one request.
            static $gatewayInstance = null;
            if ($gatewayInstance === null) {
                require_once __DIR__ . '/SmsGatewayService.php';
                $gatewayInstance = new SmsGatewayService();
            }
            $providerName = $data['provider'] ?? 'semaphore';
            $providerInstance = $gatewayInstance->getProviderInstance($providerName);

            $statusRes = $providerInstance->checkStatus($messageId, $activeApiKey);
            $rawStatus = $statusRes['status'] ?? 'sending';

            $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());

            if ($rawStatus === 'not_found') {
                $updates = [['path' => 'updated_at', 'value' => $ts]];
                try {
                    $db->collection('sms_logs')->document($messageId)->update($updates);
                    $db->collection('messages')->document($messageId)->update($updates);
                } catch (\Exception $e) {}
                $data['updated_at'] = $ts;
                return;
            }

            if ($rawStatus !== 'error') {
                $newStatus = 'Sending';
                if (in_array($rawStatus, ['sent', 'success', 'delivered'])) {
                    $newStatus = 'Sent';
                } elseif (in_array($rawStatus, ['failed', 'expired', 'rejected', 'undelivered'])) {
                    $newStatus = 'Failed';
                }

                if (self::isUpgrade($status, $newStatus)) {
                    self::finalize($db, null, $messageId, $newStatus);
                    $data['status'] = $newStatus;
                    $data['updated_at'] = $ts;
                    error_log("[StatusSync] Inline upgrade for $messageId ($providerName): $status -> $newStatus (raw: $rawStatus)");
                } else {
                    $updates = [['path' => 'updated_at', 'value' => $ts]];
                    try {
                        $db->collection('sms_logs')->document($messageId)->update($updates);
                        $db->collection('messages')->document($messageId)->update($updates);
                    } catch (\Exception $e) {}
                    $data['updated_at'] = $ts;
                }
            }
        } catch (\Exception $e) {
            error_log("[StatusSync] Inline sync exception for $messageId ($providerName): " . $e->getMessage());
        }
    }

    private static function finalize($db, $doc, $messageId, $status, $reason = null) {
        $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());
        $updates = [['path' => 'status', 'value' => $status], ['path' => 'updated_at', 'value' => $ts]];
        if ($reason) $updates[] = ['path' => 'error_reason', 'value' => $reason];
        
        $data = null;
        if ($doc instanceof \Google\Cloud\Firestore\DocumentSnapshot) {
            $doc->reference()->update($updates);
            $data = $doc->data();
        } else {
            // Direct ID updates when called without a document snapshot
            try {
                $db->collection('sms_logs')->document($messageId)->update($updates);
            } catch (\Exception $e) {}
            try {
                $snap = $db->collection('sms_logs')->document($messageId)->snapshot();
                if ($snap->exists()) {
                    $data = $snap->data();
                }
            } catch (\Exception $e) {}
        }

        try {
            $db->collection('messages')->document($messageId)->update($updates);
        } catch (\Exception $e) {}

        if ($status === 'Sent' || $status === 'Failed') {
            try {
                if ($data) {
                    $locId = $data['location_id'] ?? null;
                    if ($locId) {
                        require_once __DIR__ . '/NotificationService.php';
                        \NotificationService::notifyDeliveryStatus($db, $locId, $messageId, $status, $data['number'] ?? ($data['numbers'][0] ?? 'unknown'));

                        // ── GHL Status Sync ─────────────────────────────────────
                        try {
                            require_once __DIR__ . '/GhlSyncService.php';
                            if (!isset(self::$ghlSyncServices[$locId])) {
                                self::$ghlSyncServices[$locId] = new GhlSyncService($db, $locId);
                            }
                            
                            $ghlMessageId = $data['ghl_message_id'] ?? null;
                            if ($ghlMessageId) {
                                self::$ghlSyncServices[$locId]->syncMessageStatus($ghlMessageId, $status);
                            }
                        } catch (\Exception $e) {
                            error_log("[StatusSync] GHL Status Sync failed: " . $e->getMessage());
                        }
                    }
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