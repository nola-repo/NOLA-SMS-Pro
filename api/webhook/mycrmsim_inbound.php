<?php

/**
 * mycrmsim_inbound.php — Webhook for myCRMSIM status updates and inbound messages.
 */

require_once __DIR__ . '/firestore_client.php';
require_once __DIR__ . '/../services/GhlClient.php';

header('Content-Type: application/json');

// 1. Log Raw Payload for Debugging
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    error_log("[myCRMSIM Webhook] Invalid payload: " . $raw);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

error_log("[myCRMSIM Webhook] Received: " . $raw);

$db = get_firestore();
$type = $payload['type'] ?? ''; // STATUS-UPDATE or MESSAGE

try {
    if ($type === 'STATUS-UPDATE') {
        // Handle Delivery Status Updates
        $messageId = $payload['message_id'] ?? null;
        $status = $payload['status'] ?? null; // e.g., "delivered", "failed"

        if ($messageId && $status) {
            $newStatus = ucfirst(strtolower($status)); // Normalize to "Delivered", "Failed"
            
            // 1. Update 'messages' collection
            $msgRef = $db->collection('messages')->document($messageId);
            $msgSnap = $msgRef->snapshot();
            if ($msgSnap->exists()) {
                $msgRef->update([
                    ['path' => 'status', 'value' => $newStatus],
                    ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())]
                ]);
            }

            // 2. Update 'sms_logs' collection
            $logRef = $db->collection('sms_logs')->document($messageId);
            $logSnap = $logRef->snapshot();
            if ($logSnap->exists()) {
                $logRef->update([
                    ['path' => 'status', 'value' => $newStatus]
                ]);
            }

            error_log("[myCRMSIM Webhook] Updated message $messageId to $newStatus");
        }
    } 
    elseif ($type === 'MESSAGE') {
        // Handle Inbound Replies
        $locationId = $payload['location_id'] ?? null;
        $phone = $payload['phone'] ?? null;
        $message = $payload['message'] ?? '';
        $timestamp = $payload['timestamp'] ?? time();

        if ($locationId && $phone && $message) {
            // 1. Normalize Phone (assuming PH for now)
            $digits = preg_replace('/\D/', '', $phone);
            if (str_starts_with($digits, '63') && strlen($digits) === 12) {
                $normalizedPhone = '0' . substr($digits, 2);
            } else {
                $normalizedPhone = $phone;
            }

            $convId = $locationId . '_conv_' . $normalizedPhone;
            $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());
            $msgId = 'mycrmsim_in_' . bin2hex(random_bytes(6));

            // 2. Save Inbound Message to Firestore
            $msgData = [
                'conversation_id' => $convId,
                'location_id' => $locationId,
                'number' => $normalizedPhone,
                'message' => $message,
                'direction' => 'inbound',
                'status' => 'Received',
                'created_at' => $ts,
                'date_created' => $ts,
                'source' => 'mycrmsim',
            ];
            $db->collection('messages')->document($msgId)->set($msgData);

            // 3. Update Conversation Meta
            $db->collection('conversations')->document($convId)->set([
                'last_message' => $message,
                'last_message_at' => $ts,
                'updated_at' => $ts
            ], ['merge' => true]);

            // 4. Sync back to GHL Conversations (if possible)
            try {
                $ghlClient = new GhlClient($db, $locationId);
                // Similar logic to ghl_inbound.php or ghl_provider logic for inbound
                // For now, it's saved in Firestore for our UI.
            } catch (\Exception $e) {
                error_log("[myCRMSIM Webhook] GHL Sync failed: " . $e->getMessage());
            }

            error_log("[myCRMSIM Webhook] Logged inbound message from $normalizedPhone");
        }
    }

    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    error_log("[myCRMSIM Webhook] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
