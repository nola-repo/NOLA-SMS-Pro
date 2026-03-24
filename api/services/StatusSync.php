<?php

namespace Nola\Services;

class StatusSync
{
    /**
     * Syncs statuses from Semaphore if necessary (rate limited to once every 5 minutes by default).
     */
    public static function syncIfNecessary($db, $systemApiKey, $force = false)
    {
        $syncDocRef = $db->collection('system_config')->document('status_sync');
        $syncSnap = $syncDocRef->snapshot();
        
        $now = new \DateTime();
        $lastSync = null;

        if ($syncSnap->exists()) {
            $lastSyncTs = $syncSnap->data()['last_sync_at'] ?? null;
            if ($lastSyncTs instanceof \Google\Cloud\Core\Timestamp) {
                $lastSync = $lastSyncTs->get();
            }
        }

        // Only sync if 5 minutes have passed since the last sync (unless forced)
        if (!$force && $lastSync && ($now->getTimestamp() - $lastSync->getTimestamp() < 300)) {
            return false; // Skip sync
        }

        // Update last_sync_at immediately to prevent concurrent triggers
        $syncDocRef->set([
            'last_sync_at' => new \Google\Cloud\Core\Timestamp($now),
            'updated_at' => new \Google\Cloud\Core\Timestamp($now)
        ], ['merge' => true]);

        return self::runSync($db, $systemApiKey);
    }

    /**
     * Core sync logic (Migrated from retrieve_status.php)
     */
    public static function runSync($db, $systemApiKey)
    {
        $query = $db->collection('sms_logs')
            ->where('status', 'in', ['Queued', 'Pending']);

        $documents = $query->documents();
        $keyCache = [];
        $updatedCount = 0;

        foreach ($documents as $doc) {
            if (!$doc->exists()) continue;

            $data = $doc->data();
            $messageId = $data['message_id'] ?? null;
            $locId = $data['location_id'] ?? null;

            if (!$messageId) continue;

            // Determine API Key
            $activeKey = $systemApiKey;
            if ($locId) {
                if (isset($keyCache[$locId])) {
                    $activeKey = $keyCache[$locId];
                } else {
                    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
                    $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
                    if ($intSnap->exists()) {
                        $intData = $intSnap->data();
                        $customKey = $intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? null);
                        if ($customKey) $activeKey = $customKey;
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
                    $currentStatus = $data['status'] ?? 'Queued';
                    
                    if (self::shouldUpdateStatus($currentStatus, $newStatus)) {
                        $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());
                        
                        // Update sms_logs
                        $doc->reference()->update([
                            ['path' => 'status', 'value' => $newStatus],
                            ['path' => 'updated_at', 'value' => $ts],
                        ]);

                        // Update messages
                        try {
                            $db->collection('messages')->document($messageId)->update([
                                ['path' => 'status', 'value' => $newStatus],
                                ['path' => 'updated_at', 'value' => $ts],
                            ]);
                        } catch (\Exception $e) { /* Ignore if not found */ }
                        
                        $updatedCount++;
                    }
                }
            }
        }

        return $updatedCount;
    }

    private static function shouldUpdateStatus($current, $new)
    {
        $statusPriority = [
            'Queued'    => 1,
            'Pending'   => 2,
            'Sent'      => 3,
            'Delivered' => 4,
            'Failed'    => 4,
            'Expired'   => 4
        ];

        $newPriority = $statusPriority[$new] ?? 0;
        $oldPriority = $statusPriority[$current] ?? 0;

        return ($newPriority >= $oldPriority) && ($new !== $current);
    }
}
