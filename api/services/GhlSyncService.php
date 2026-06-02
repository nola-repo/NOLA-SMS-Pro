<?php

namespace Nola\Services;

require_once __DIR__ . '/GhlClient.php';
require_once __DIR__ . '/../install_helpers.php';

/**
 * GhlSyncService — Handles synchronization of messages and statuses to GHL.
 */
class GhlSyncService
{
    private $db;
    private $ghlClient;
    private string $locationId;

    public function __construct($db, string $locationId, ?string $tokenRegistryId = null)
    {
        $this->db = $db;
        $this->locationId = $locationId;
        $this->ghlClient = new \GhlClient($db, $locationId, $tokenRegistryId);
    }

    /**
     * Sync an outbound message sent from NOLA back to GHL Conversations.
     */
    public function syncOutboundMessage(string $phone, string $message, ?string $contactId = null): array
    {
        try {
            $installSkip = $this->skipIfInstallInactive();
            if ($installSkip !== null) {
                return $installSkip;
            }

            if ($this->isConversationSyncDisabled()) {
                return ['success' => true, 'skipped' => true, 'reason' => 'conversation_sync_disabled_for_location'];
            }

            $resolvedContactId = $contactId;
            $ghlConvId = $this->resolveConversation($phone, $resolvedContactId);

            if (!$ghlConvId || !$resolvedContactId) {
                return ['success' => false, 'error' => 'Could not resolve GHL conversation or contact'];
            }

            // Deduplication flag to prevent loops (key format must match ghl_provider.php)
            $dedupKey = md5($this->locationId . '_outbound_' . $phone . $message);
            $this->db->collection('ghl_sync_dedup')->document($dedupKey)->set([
                'timestamp' => time(),
                'source' => 'nola_outbound'
            ]);

            // GHL API requires companyId on conversation message POST
            $integration = $this->ghlClient->getIntegration();
            $companyId   = $integration['companyId'] ?? $integration['hashed_companyId'] ?? '';

            // Fallback: try ghl_tokens doc if integration data doesn't have companyId
            if (empty($companyId)) {
                $tokenSnap = $this->db->collection('ghl_tokens')->document($this->locationId)->snapshot();
                if ($tokenSnap->exists()) {
                    $tokenData = $tokenSnap->data();
                    $companyId = $tokenData['companyId'] ?? $tokenData['company_id'] ?? '';
                }
            }

            $body = [
                'type'           => 'SMS',
                'contactId'      => $resolvedContactId,
                'conversationId' => $ghlConvId,
                'locationId'     => $this->locationId,
                'message'        => $message,
            ];
            if ($companyId) {
                $body['companyId'] = $companyId;
            }

            $resp = $this->ghlClient->request(
                'POST',
                '/conversations/messages',
                json_encode($body),
                '2021-04-15'
            );

            if ($this->isTrialModeErrorResponse($resp)) {
                $this->disableConversationSync('trial_mode_not_enabled_for_agency', $resp);
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'trial_mode_not_enabled_for_agency',
                    'ghl_response' => $resp
                ];
            }
            $ghlMessageId = null;
            if ($resp['status'] < 300 && !empty($resp['body'])) {
                $respData = json_decode($resp['body'], true);
                $ghlMessageId = $respData['messageId'] ?? null;
            }

            return [
                'success' => $resp['status'] < 300,
                'ghl_response' => $resp,
                'ghl_message_id' => $ghlMessageId
            ];
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
            $installSkip = $this->skipIfInstallInactive();
            if ($installSkip !== null) {
                return $installSkip;
            }

            if ($this->isConversationSyncDisabled()) {
                return ['success' => true, 'skipped' => true, 'reason' => 'conversation_sync_disabled_for_location'];
            }

            $contactId = null;
            $ghlConvId = $this->resolveConversation($phone, $contactId);

            if (!$ghlConvId || !$contactId) {
                return ['success' => false, 'error' => 'Could not resolve GHL conversation or contact'];
            }

            $integration = $this->ghlClient->getIntegration();
            $companyId   = $integration['companyId'] ?? $integration['hashed_companyId'] ?? '';

            // Fallback: try ghl_tokens doc if integration data doesn't have companyId
            if (empty($companyId)) {
                $tokenSnap = $this->db->collection('ghl_tokens')->document($this->locationId)->snapshot();
                if ($tokenSnap->exists()) {
                    $tokenData = $tokenSnap->data();
                    $companyId = $tokenData['companyId'] ?? $tokenData['company_id'] ?? '';
                }
            }

            $body = [
                'type'           => 'SMS',
                'contactId'      => $contactId,
                'conversationId' => $ghlConvId,
                'locationId'     => $this->locationId,
                'message'        => $message,
            ];
            if ($companyId) {
                $body['companyId'] = $companyId;
            }

            $resp = $this->ghlClient->request(
                'POST',
                '/conversations/messages/inbound',
                json_encode($body),
                '2021-04-15'
            );

            if ($this->isTrialModeErrorResponse($resp)) {
                $this->disableConversationSync('trial_mode_not_enabled_for_agency', $resp);
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'trial_mode_not_enabled_for_agency',
                    'ghl_response' => $resp
                ];
            }

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

        $normalised = strtolower(trim($status));

        // Only sync terminal statuses to GHL.
        // In-progress states (queued, pending, sending) must NEVER be reported as
        // 'failed' — doing so triggers the GHL "Retry" badge even on delivered messages.
        if (in_array($normalised, ['sent', 'delivered', 'success'])) {
            $ghlStatus = 'delivered';
        } elseif (in_array($normalised, ['failed', 'expired', 'rejected', 'undelivered'])) {
            $ghlStatus = 'failed';
        } else {
            // 'queued', 'pending', 'sending' — still in-flight, skip GHL update entirely.
            error_log("[GhlSyncService] syncMessageStatus skipped for in-progress status '{$status}' (messageId={$ghlMessageId})");
            return ['success' => true, 'skipped' => true, 'reason' => 'status_not_terminal'];
        }

        try {
            $resp = $this->ghlClient->request(
                'POST',
                '/conversations/messages/' . urlencode($ghlMessageId) . '/status',
                json_encode([
                    'status'     => $ghlStatus,
                    'locationId' => $this->locationId,
                ]),
                '2021-04-15'
            );

            error_log("[GhlSyncService] syncMessageStatus sent '{$ghlStatus}' for GHL messageId={$ghlMessageId} (src status='{$status}') → HTTP {$resp['status']} body=" . substr((string)($resp['body'] ?? ''), 0, 400));
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

    private function integrationDocRef()
    {
        $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->locationId);
        return $this->db->collection('integrations')->document($docId);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function skipIfInstallInactive(): ?array
    {
        $gate = install_location_sms_gate($this->db, $this->locationId);
        if (!empty($gate['allowed'])) {
            return null;
        }

        return [
            'success' => true,
            'skipped' => true,
            'reason' => (string)($gate['code'] ?? 'app_uninstalled'),
        ];
    }

    private function isConversationSyncDisabled(): bool
    {
        try {
            $snap = $this->integrationDocRef()->snapshot();
            if (!$snap->exists()) {
                return false;
            }
            $data = $snap->data();
            return !empty($data['disable_ghl_conversation_sync']);
        } catch (\Throwable $e) {
            error_log('[GhlSyncService] Failed to read sync toggle: ' . $e->getMessage());
            return false;
        }
    }

    private function disableConversationSync(string $reason, array $resp = []): void
    {
        try {
            $now = new \DateTimeImmutable();
            $this->integrationDocRef()->set([
                'disable_ghl_conversation_sync' => true,
                'ghl_conversation_sync_disabled_reason' => $reason,
                'ghl_conversation_sync_disabled_at' => new \Google\Cloud\Core\Timestamp($now),
                'ghl_conversation_sync_last_error' => [
                    'status' => $resp['status'] ?? null,
                    'body' => isset($resp['body']) ? substr((string)$resp['body'], 0, 1000) : null,
                ],
                'updated_at' => new \Google\Cloud\Core\Timestamp($now),
            ], ['merge' => true]);
            error_log("[GhlSyncService] Disabled conversation sync for {$this->locationId}: {$reason}");
        } catch (\Throwable $e) {
            error_log('[GhlSyncService] Failed to disable conversation sync: ' . $e->getMessage());
        }
    }

    private function isTrialModeErrorResponse(array $resp): bool
    {
        $status = (int)($resp['status'] ?? 0);
        if ($status < 400) {
            return false;
        }

        $body = (string)($resp['body'] ?? '');
        if ($body === '') {
            return false;
        }

        $decoded = json_decode($body, true);
        $rawMessage = strtolower($body);

        $msg = '';
        $code = '';
        if (is_array($decoded)) {
            $msg = strtolower((string)($decoded['error']['msg'] ?? $decoded['message'] ?? ''));
            $code = (string)($decoded['error']['code'] ?? $decoded['code'] ?? '');
        }

        return $code === '101'
            || str_contains($msg, 'trial mode not enabled')
            || str_contains($rawMessage, 'trial mode not enabled');
    }
}
