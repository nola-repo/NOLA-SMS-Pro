<?php

namespace Nola\Services;

require_once __DIR__ . '/GhlClient.php';

/**
 * GhlSyncService — Handles synchronization of messages and statuses to GHL.
 */
class GhlSyncService
{
    private $db;
    private $ghlClient;
    private string $locationId;

    public function __construct($db, string $locationId)
    {
        $this->db = $db;
        $this->locationId = $locationId;
        $this->ghlClient = new \GhlClient($db, $locationId);
    }

    /**
     * Sync an outbound message sent from NOLA back to GHL Conversations.
     */
    public function syncOutboundMessage(string $phone, string $message, ?string $contactId = null): array
    {
        try {
            $resolvedContactId = $contactId;
            $ghlConvId = $this->resolveConversation($phone, $resolvedContactId);

            if (!$ghlConvId || !$resolvedContactId) {
                return ['success' => false, 'error' => 'Could not resolve GHL conversation or contact'];
            }

            // Deduplication flag to prevent loops
            $dedupKey = md5($this->locationId . $phone . $message);
            $this->db->collection('ghl_sync_dedup')->document($dedupKey)->set([
                'timestamp' => time(),
                'source' => 'nola_outbound'
            ]);

            $resp = $this->ghlClient->request(
                'POST',
                '/conversations/messages',
                json_encode([
                    'type' => 'SMS',
                    'contactId' => $resolvedContactId,
                    'conversationId' => $ghlConvId,
                    'locationId' => $this->locationId,
                    'message' => $message,
                ]),
                '2021-04-15'
            );

            return ['success' => $resp['status'] < 300, 'ghl_response' => $resp];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sync an inbound message received by NOLA to GHL Conversations.
     */
    public function syncInboundMessage(string $phone, string $message): array
    {
        try {
            $contactId = null;
            $ghlConvId = $this->resolveConversation($phone, $contactId);

            if (!$ghlConvId || !$contactId) {
                return ['success' => false, 'error' => 'Could not resolve GHL conversation or contact'];
            }

            $resp = $this->ghlClient->request(
                'POST',
                '/conversations/messages/inbound',
                json_encode([
                    'type' => 'SMS',
                    'contactId' => $contactId,
                    'conversationId' => $ghlConvId,
                    'locationId' => $this->locationId,
                    'message' => $message,
                ]),
                '2021-04-15'
            );

            return ['success' => $resp['status'] < 300, 'ghl_response' => $resp];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sync message delivery status to GHL.
     */
    public function syncMessageStatus(string $ghlMessageId, string $status): array
    {
        if (!$ghlMessageId) return ['success' => false, 'error' => 'Missing GHL Message ID'];

        try {
            $ghlStatus = (strtolower($status) === 'sent' || strtolower($status) === 'delivered') ? 'delivered' : 'failed';

            $resp = $this->ghlClient->request(
                'POST',
                '/conversations/messages/status',
                json_encode([
                    'locationId' => $this->locationId,
                    'messageId' => $ghlMessageId,
                    'status' => $ghlStatus,
                ]),
                '2021-04-15'
            );

            return ['success' => $resp['status'] < 300, 'ghl_response' => $resp];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Internal Helpers ──────────────────────────────────────────────────

    private function resolveConversation(string $phone, ?string &$contactId): ?string
    {
        $localConvId = $this->locationId . '_conv_' . $phone;
        $convSnap = $this->db->collection('conversations')->document($localConvId)->snapshot();
        
        if ($convSnap->exists()) {
            $data = $convSnap->data();
            if (!$contactId) $contactId = $data['ghl_contact_id'] ?? null;
            if ($data['ghl_conversation_id'] ?? null) return $data['ghl_conversation_id'];
        }

        // If not cached, search GHL by phone
        if (!$contactId) {
            $searchPhoneUrl = '/contacts/?locationId=' . urlencode($this->locationId) . '&query=' . urlencode($phone);
            $resp = $this->ghlClient->request('GET', $searchPhoneUrl);
            $data = json_decode($resp['body'], true);
            $contactId = $data['contacts'][0]['id'] ?? null;
        }

        if ($contactId) {
            // Create/Find GHL Conversation
            $resp = $this->ghlClient->request(
                'POST',
                '/conversations/',
                json_encode(['locationId' => $this->locationId, 'contactId' => $contactId]),
                '2021-04-15'
            );
            $data = json_decode($resp['body'], true);
            
            $ghlConvId = null;
            if ($resp['status'] === 400 && str_contains($resp['body'], 'already exists')) {
                $searchResp = $this->ghlClient->request(
                    'GET',
                    '/conversations/search?contactId=' . urlencode($contactId) . '&locationId=' . urlencode($this->locationId),
                    null,
                    '2021-04-15'
                );
                $searchData = json_decode($searchResp['body'], true);
                $ghlConvId = $searchData['conversations'][0]['id'] ?? null;
            } else {
                $ghlConvId = $data['conversation']['id'] ?? $data['id'] ?? null;
            }

            if ($ghlConvId) {
                $this->db->collection('conversations')->document($localConvId)->set([
                    'ghl_conversation_id' => $ghlConvId,
                    'ghl_contact_id' => $contactId,
                ], ['merge' => true]);
                return $ghlConvId;
            }
        }

        return null;
    }
}
