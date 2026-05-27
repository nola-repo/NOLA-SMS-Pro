<?php

/**
 * NotificationService — Dispatch logic for system notifications.
 *
 * Handles:
 * - Low balance alerts (with 24-hour circuit breaker)
 * - Delivery report notifications (Failed/Delivered)
 * - Email dispatch (placeholder — swap for SendGrid/Mailgun when ready)
 */
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../install_helpers.php';

class NotificationService
{
    /**
     * Resolve account owner name and email using the authoritative profile logic.
     *
     * @return array{email: string|null, name: string|null}
     */
    public static function getAccountDetails($db, string $locationId): array
    {
        $userEmail = null;
        $userName = null;

        try {
            // 1. Query users where active_location_id matches locId
            $userQuery = $db->collection('users')
                ->where('active_location_id', '=', $locationId)
                ->limit(1)
                ->documents();

            foreach ($userQuery as $doc) {
                if ($doc->exists()) {
                    $userData = $doc->data();
                    $userEmail = isset($userData['email']) ? trim((string)$userData['email']) : null;
                    $userName = isset($userData['name']) ? trim((string)$userData['name']) : '';
                    if (!$userName) {
                        $fn = $userData['firstName'] ?? '';
                        $ln = $userData['lastName'] ?? '';
                        $userName = trim("$fn $ln") ?: null;
                    }
                    break;
                }
            }

            // 2. Fallback: subaccounts collectionGroup
            if ($userEmail === null || $userEmail === '') {
                $subQuery = $db->collectionGroup('subaccounts')
                    ->where('location_id', '=', $locationId)
                    ->limit(1)
                    ->documents();

                foreach ($subQuery as $subDoc) {
                    if ($subDoc->exists()) {
                        $parentUserRef = $subDoc->reference()->parent()->parent();
                        if ($parentUserRef !== null) {
                            $parentSnap = $parentUserRef->snapshot();
                            if ($parentSnap->exists()) {
                                $userData = $parentSnap->data();
                                $userEmail = isset($userData['email']) ? trim((string)$userData['email']) : null;
                                $userName = isset($userData['name']) ? trim((string)$userData['name']) : '';
                                if (!$userName) {
                                    $fn = $userData['firstName'] ?? '';
                                    $ln = $userData['lastName'] ?? '';
                                    $userName = trim("$fn $ln") ?: null;
                                }
                            }
                        }
                        break;
                    }
                }
            }

            // 3. Fallback: install_linked_account_for_location
            if ($userEmail === null || $userEmail === '') {
                $linked = install_linked_account_for_location($db, $locationId, false);
                if ($linked !== null) {
                    $userEmail = $linked['email'] !== '' ? $linked['email'] : null;
                    $userName = $linked['name'] !== '' ? $linked['name'] : null;
                }
            }

            // 4. Fallback: ghl_tokens
            if ($userEmail === null || $userEmail === '') {
                $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
                if ($tokenSnap->exists()) {
                    $tokenData = $tokenSnap->data();
                    $userEmail = $tokenData['email'] ?? $tokenData['user_email'] ?? null;
                    $userName = $tokenData['location_name'] ?? null;
                }
            }

            // 5. Fallback: integrations
            if ($userEmail === null || $userEmail === '') {
                $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
                $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
                if ($intSnap->exists()) {
                    $intData = $intSnap->data();
                    $userEmail = $intData['email'] ?? $intData['user_email'] ?? null;
                    $userName = $intData['location_name'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            error_log("[NotificationService] getAccountDetails failed: " . $e->getMessage());
        }

        return [
            'email' => $userEmail,
            'name'  => $userName,
        ];
    }

    /**
     * Get the email address associated with this location.
     * Checks authoritative profile sources.
     *
     * @return string|null
     */
    public static function getAccountEmail($db, string $locationId): ?string
    {
        $details = self::getAccountDetails($db, $locationId);
        return $details['email'];
    }

    /**
     * Create or update a GHL contact for the account owner email and sync custom fields and tags.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param string $email
     * @param string $name
     * @param array  $customFields
     * @param bool   $cycleTag
     * @return string|null Managed Contact ID
     */
    /**
     * Resolve NOLA SMS friendly custom field keys to GoHighLevel Custom Field IDs.
     * Checks stored cache first under integrations/ghl_{locId}.notification_preferences.ghl_custom_field_ids
     * and falls back to fetching them dynamically via GHL /locations/{locId}/customFields endpoint.
     */
    private static function resolveGhlCustomFieldIds($db, $ghlClient, string $locationId): array
    {
        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
        $docRef = $db->collection('integrations')->document($intDocId);
        $snap = $docRef->snapshot();
        
        $cachedIds = [];
        if ($snap->exists()) {
            $data = $snap->data();
            $cachedIds = $data['notification_preferences']['ghl_custom_field_ids'] ?? [];
        }

        $requiredKeys = [
            'nola_sms_balance',
            'nola_sms_low_balance_threshold',
            'nola_sms_alert_type',
            'nola_sms_alert_id',
            'nola_sms_alerted_at',
            'nola_sms_message_id',
            'nola_sms_delivery_status',
            'nola_sms_recipient',
        ];

        $hasAll = true;
        foreach ($requiredKeys as $rk) {
            if (empty($cachedIds[$rk])) {
                $hasAll = false;
                break;
            }
        }

        if ($hasAll) {
            return $cachedIds;
        }

        // Fetch from GHL to dynamically populate the IDs
        try {
            $response = $ghlClient->request('GET', "/locations/{$locationId}/customFields");
            if ($response['status'] === 200) {
                $respData = json_decode($response['body'], true);
                $fieldsList = $respData['customFields'] ?? [];
                if (is_array($fieldsList)) {
                    $newMapping = is_array($cachedIds) ? $cachedIds : [];
                    foreach ($fieldsList as $field) {
                        $fieldName = $field['name'] ?? '';
                        $fieldKey = $field['fieldKey'] ?? '';
                        $fieldId = $field['id'] ?? '';

                        if (!$fieldId) continue;

                        // Clean names and keys to see if they match any required key
                        $normName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', trim($fieldName)));
                        $normKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', str_replace('contact.', '', $fieldKey)));

                        foreach ($requiredKeys as $rk) {
                            if ($normName === $rk || $normKey === $rk) {
                                $newMapping[$rk] = $fieldId;
                            }
                        }
                    }

                    // Save resolved mapping back to Firestore
                    $existingPrefs = [];
                    if ($snap->exists()) {
                        $existingPrefs = $snap->data()['notification_preferences'] ?? [];
                    }
                    $existingPrefs['ghl_custom_field_ids'] = $newMapping;
                    $docRef->set([
                        'notification_preferences' => $existingPrefs
                    ], ['merge' => true]);
                    $cachedIds = $newMapping;
                }
            } else {
                error_log("[NotificationService::resolveGhlCustomFieldIds] Failed to fetch custom fields from GHL (HTTP " . $response['status'] . "): " . $response['body']);
            }
        } catch (\Throwable $e) {
            error_log("[NotificationService::resolveGhlCustomFieldIds] Exception fetching custom fields: " . $e->getMessage());
        }

        return $cachedIds;
    }

    /**
     * Create or update a GHL contact for the account owner email and sync custom fields and tags.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param string $email
     * @param string $name
     * @param array  $customFields
     * @param bool   $cycleTag
     * @return string|null Managed Contact ID
     */
    private static function syncGhlContactBridge($db, string $locationId, string $email, string $name, array $customFields, bool $cycleTag = true): ?string
    {
        require_once __DIR__ . '/GhlClient.php';

        try {
            // Resolve registry key
            $jwtCtx = auth_get_optional_jwt_context($db);
            $tokenRegistryId = auth_resolve_ghl_token_registry_id($db, $jwtCtx, $locationId);

            // Fallback for background contexts (no JWT) when no direct token doc exists for the location
            if (!$jwtCtx) {
                $directDocExists = $db->collection('ghl_tokens')->document($locationId)->snapshot()->exists();
                if (!$directDocExists) {
                    require_once __DIR__ . '/GhlTokenProvider.php';
                    $companyId = \GhlTokenProvider::resolveCompanyIdForLocation($db, $locationId);
                    if ($companyId) {
                        $companyDocExists = $db->collection('ghl_tokens')->document($companyId)->snapshot()->exists();
                        if ($companyDocExists) {
                            $tokenRegistryId = $companyId;
                            error_log("[NotificationService::syncGhlContactBridge] Background call fallback resolved company tokenRegistryId: {$companyId} for location: {$locationId}");
                        }
                    }
                }
            }

            $ghlClient = new \GhlClient($db, $locationId, $tokenRegistryId);
        } catch (\Throwable $e) {
            error_log("[NotificationService::syncGhlContactBridge] Failed to initialize GhlClient: " . $e->getMessage());
            return null;
        }

        $contactId = null;

        // 1. Search for existing contact by email
        try {
            $searchUrl = '/contacts/?locationId=' . urlencode($locationId) . '&query=' . urlencode($email);
            $searchResp = $ghlClient->request('GET', $searchUrl);
            if ($searchResp['status'] === 200) {
                $searchData = json_decode($searchResp['body'], true);
                $contacts = $searchData['contacts'] ?? $searchData['data'] ?? [];
                if (is_array($contacts)) {
                    foreach ($contacts as $c) {
                        if (isset($c['email']) && strtolower(trim((string)$c['email'])) === strtolower(trim($email))) {
                            $contactId = $c['id'];
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[NotificationService::syncGhlContactBridge] Contact search failed: " . $e->getMessage());
        }

        // Split name into first and last name
        $firstName = 'NOLA SMS';
        $lastName = 'Alert Contact';
        if ($name) {
            $parts = explode(' ', $name, 2);
            $firstName = $parts[0];
            $lastName = $parts[1] ?? '';
        }

        // Resolve NOLA friendly keys to real GHL Custom Field IDs
        $fieldIdMap = self::resolveGhlCustomFieldIds($db, $ghlClient, $locationId);

        // Prepare GHL payload format for custom fields
        $ghlCustomFields = [];
        foreach ($customFields as $k => $v) {
            $fieldId = $fieldIdMap[$k] ?? $k;
            if ($fieldId === $k) {
                error_log("[NotificationService::syncGhlContactBridge] Warning: Friendly key '{$k}' could not be resolved to GHL custom field ID.");
            }
            $ghlCustomFields[] = [
                'id' => $fieldId,
                'value' => $v,
            ];
        }

        $contactPayload = [
            'locationId'   => $locationId,
            'email'        => $email,
            'firstName'    => $firstName,
            'lastName'     => $lastName,
            'customFields' => $ghlCustomFields,
        ];

        // 2. Upsert (Create or Update)
        if ($contactId) {
            // Update
            try {
                $updateResp = $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($contactPayload));
                if ($updateResp['status'] >= 400) {
                    error_log("[NotificationService::syncGhlContactBridge] GHL Contact Update FAIL! LocationID: {$locationId}, Email: {$email}, Status: " . $updateResp['status'] . ", Response: " . $updateResp['body']);
                }
            } catch (\Throwable $e) {
                error_log("[NotificationService::syncGhlContactBridge] Contact update exception: " . $e->getMessage());
            }
        } else {
            // Create
            try {
                $createResp = $ghlClient->request('POST', '/contacts/', json_encode($contactPayload));
                if ($createResp['status'] === 200 || $createResp['status'] === 201) {
                    $createData = json_decode($createResp['body'], true);
                    $contactId = $createData['contact']['id'] ?? $createData['id'] ?? null;
                } else {
                    error_log("[NotificationService::syncGhlContactBridge] GHL Contact Create FAIL! LocationID: {$locationId}, Email: {$email}, Status: " . $createResp['status'] . ", Response: " . $createResp['body']);
                }
            } catch (\Throwable $e) {
                error_log("[NotificationService::syncGhlContactBridge] Contact creation exception: " . $e->getMessage());
            }
        }

        if ($contactId) {
            // Save the contact ID to integration document notification_preferences.ghl_alert_contact_id
            try {
                $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
                $docRef = $db->collection('integrations')->document($intDocId);
                $snap = $docRef->snapshot();
                $existingPrefs = [];
                if ($snap->exists()) {
                    $existingPrefs = $snap->data()['notification_preferences'] ?? [];
                }
                $existingPrefs['ghl_alert_contact_id'] = $contactId;
                $docRef->set([
                    'notification_preferences' => $existingPrefs
                ], ['merge' => true]);
            } catch (\Throwable $e) {
                error_log("[NotificationService::syncGhlContactBridge] Failed to save contact ID to Firestore: " . $e->getMessage());
            }

            // 3. Optional Tag Fallback
            if ($cycleTag) {
                try {
                    // Remove tag if exists
                    $ghlClient->request('DELETE', "/contacts/{$contactId}/tags", json_encode(['tags' => ['nola-low-balance-alert']]));
                    // Add tag
                    $ghlClient->request('POST', "/contacts/{$contactId}/tags", json_encode(['tags' => ['nola-low-balance-alert']]));
                } catch (\Throwable $e) {
                    error_log("[NotificationService::syncGhlContactBridge] Tag cycle failed: " . $e->getMessage());
                }
            }
        }

        return $contactId;
    }

    /**
     * Check if a low-balance alert should be sent after a credit deduction.
     *
     * Circuit breaker: only sends once per 24 hours while balance remains
     * at or below the threshold.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param int    $currentBalance  Balance AFTER the deduction
     */
    public static function checkLowBalance($db, string $locationId, int $currentBalance): void
    {
        $prefs = self::getPreferences($db, $locationId);

        if (!$prefs['low_balance_alert_enabled']) {
            return;
        }

        $threshold = $prefs['low_balance_threshold'];

        if ($currentBalance > $threshold) {
            // Balance is healthy — clear last_low_balance_notified_at so the user can be alerted again immediately after dropping below threshold next time
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
            $docRef = $db->collection('integrations')->document($intDocId);
            $snap = $docRef->snapshot();
            if ($snap->exists()) {
                $existingPrefs = $snap->data()['notification_preferences'] ?? [];
                if (isset($existingPrefs['last_low_balance_notified_at'])) {
                    unset($existingPrefs['last_low_balance_notified_at']);
                    $docRef->set([
                        'notification_preferences' => $existingPrefs
                    ], ['merge' => true]);
                    error_log("[LowBalanceAlert] Cleared last_low_balance_notified_at for location {$locationId} because currentBalance {$currentBalance} > threshold {$threshold}");
                }
            }
            return;
        }

        if (!$prefs['ghl_workflow_sync_enabled']) {
            return; // Stop if ghl_workflow_sync_enabled is false
        }

        // ── Circuit breaker: check last notification time ───────────────
        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
        $docRef = $db->collection('integrations')->document($intDocId);
        $snap = $docRef->snapshot();
        $existingPrefs = [];

        if ($snap->exists()) {
            $data = $snap->data();
            $existingPrefs = $data['notification_preferences'] ?? [];
            $lastNotified = $existingPrefs['last_low_balance_notified_at'] ?? null;
            if ($lastNotified) {
                $lastTs = $lastNotified instanceof \Google\Cloud\Core\Timestamp
                    ? $lastNotified->get()->getTimestamp()
                    : (int) $lastNotified;

                // Suppress if notified within the last 24 hours
                if (time() - $lastTs < 86400) {
                    return;
                }
            }
        }

        // ── Send the alert via GHL Contact Bridge ───────────────────────
        $details = self::getAccountDetails($db, $locationId);
        $email = $details['email'];
        $name = $details['name'] ?? 'NOLA Owner';

        if (!$email) {
            error_log("[LowBalanceAlert] No registered account email found for location {$locationId}, skipping bridge.");
            return;
        }

        $now = new \DateTimeImmutable();
        $timestamp = $now->format('c'); // ISO 8601
        $alertId = "low_balance_{$locationId}_" . $now->format('YmdHis');

        $customFields = [
            'nola_sms_balance'               => $currentBalance,
            'nola_sms_low_balance_threshold' => $threshold,
            'nola_sms_alert_type'            => 'low_balance',
            'nola_sms_alert_id'              => $alertId,
            'nola_sms_alerted_at'            => $timestamp,
        ];

        error_log("[LowBalanceAlert] Triggering GHL Contact Bridge for location {$locationId} (email: {$email}, balance: {$currentBalance})");
        $contactId = self::syncGhlContactBridge($db, $locationId, $email, $name, $customFields, true);

        // Update circuit breaker timestamp inside integration preferences
        $existingPrefs = [];
        $snap = $docRef->snapshot();
        if ($snap->exists()) {
            $existingPrefs = $snap->data()['notification_preferences'] ?? [];
        }
        $existingPrefs['last_low_balance_notified_at'] = new \Google\Cloud\Core\Timestamp($now);
        if ($contactId) {
            $existingPrefs['ghl_alert_contact_id'] = $contactId;
        }

        $docRef->set([
            'notification_preferences' => $existingPrefs
        ], ['merge' => true]);

        error_log("[LowBalanceAlert] Updated last_low_balance_notified_at and bridge for location {$locationId}");
    }

    /**
     * Notify about a terminal message delivery status (Delivered/Failed).
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param string $messageId   Semaphore message ID
     * @param string $status      Terminal status (Delivered, Failed)
     * @param string $recipient   Phone number of the recipient
     */
    public static function notifyDeliveryStatus($db, string $locationId, string $messageId, string $status, string $recipient): void
    {
        $prefs = self::getPreferences($db, $locationId);

        if (!$prefs['delivery_reports_enabled']) {
            return;
        }

        $statusLabel = ucfirst(strtolower($status));

        if ($prefs['ghl_workflow_sync_enabled']) {
            $isFailed = (strtolower($status) === 'failed' || strtolower($status) === 'expired' || strtolower($status) === 'rejected' || strtolower($status) === 'undelivered');
            if ($isFailed) {
                $details = self::getAccountDetails($db, $locationId);
                $email = $details['email'];
                $name = $details['name'] ?? 'NOLA Owner';

                if (!$email) {
                    error_log("[DeliveryFailureAlert] No registered account email found for location {$locationId}, skipping bridge.");
                    return;
                }

                $now = new \DateTimeImmutable();
                $timestamp = $now->format('c');
                $alertId = "delivery_failure_{$locationId}_{$messageId}_" . $now->format('YmdHis');

                $customFields = [
                    'nola_sms_alert_type'      => 'delivery_failure',
                    'nola_sms_alert_id'        => $alertId,
                    'nola_sms_message_id'      => $messageId,
                    'nola_sms_delivery_status' => $statusLabel,
                    'nola_sms_recipient'       => $recipient,
                    'nola_sms_alerted_at'      => $timestamp,
                ];

                error_log("[DeliveryFailureAlert] Triggering GHL Contact Bridge for location {$locationId} (email: {$email}, messageId: {$messageId})");
                self::syncGhlContactBridge($db, $locationId, $email, $name, $customFields, true);
            }
        } else {
            $email = self::getAccountEmail($db, $locationId);
            if (!$email) {
                return;
            }

            $subject = "NOLA SMS Pro — Message {$statusLabel}: {$recipient}";
            $body = "Delivery Report\n"
                  . "───────────────\n"
                  . "Recipient:  {$recipient}\n"
                  . "Status:     {$statusLabel}\n"
                  . "Message ID: {$messageId}\n"
                  . "Location:   {$locationId}\n\n"
                  . "This is an automated delivery notification from NOLA SMS Pro.";

            self::sendEmail($email, $subject, $body);
            error_log("[DeliveryReport] Sent {$statusLabel} report for message {$messageId} to {$email}");
        }
    }

    /**
     * Load notification preferences from Firestore with defaults.
     *
     * @return array{delivery_reports_enabled: bool, low_balance_alert_enabled: bool, low_balance_threshold: int, marketing_emails_enabled: bool, ghl_workflow_sync_enabled: bool, ghl_alert_contact_id: string|null}
     */
    public static function getPreferences($db, string $locationId): array
    {
        $defaults = [
            'delivery_reports_enabled'  => false,
            'low_balance_alert_enabled' => true,
            'low_balance_threshold'     => 50,
            'marketing_emails_enabled'  => false,
            'ghl_workflow_sync_enabled' => false,
            'ghl_alert_contact_id'      => null,
        ];

        try {
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
            $snap = $db->collection('integrations')->document($intDocId)->snapshot();
            if ($snap->exists()) {
                $data = $snap->data();
                if (isset($data['notification_preferences']) && is_array($data['notification_preferences'])) {
                    $d = $data['notification_preferences'];
                    return [
                        'delivery_reports_enabled'  => (bool) ($d['delivery_reports_enabled'] ?? $defaults['delivery_reports_enabled']),
                        'low_balance_alert_enabled' => (bool) ($d['low_balance_alert_enabled'] ?? $defaults['low_balance_alert_enabled']),
                        'low_balance_threshold'     => (int)  ($d['low_balance_threshold'] ?? $defaults['low_balance_threshold']),
                        'marketing_emails_enabled'  => (bool) ($d['marketing_emails_enabled'] ?? $defaults['marketing_emails_enabled']),
                        'ghl_workflow_sync_enabled' => (bool) ($d['ghl_workflow_sync_enabled'] ?? $defaults['ghl_workflow_sync_enabled']),
                        'ghl_alert_contact_id'      => $d['ghl_alert_contact_id'] ?? $defaults['ghl_alert_contact_id'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log("[NotificationService] Failed to load preferences for {$locationId}: " . $e->getMessage());
        }

        return $defaults;
    }

    /**
     * Send an email notification.
     *
     * PLACEHOLDER: Currently logs inline. Replace this method body with
     * SendGrid/Mailgun/SES when an email provider is configured.
     *
     * @param string $to      Recipient email
     * @param string $subject Email subject
     * @param string $body    Plain-text body
     */
    public static function sendEmail(string $to, string $subject, string $body): void
    {
        // TODO: Replace with actual email provider (SendGrid, Mailgun, etc.)
        // For now, log the email so it appears in Cloud Run logs.
        error_log("[NotificationService::sendEmail] TO: {$to} | SUBJECT: {$subject}");
        error_log("[NotificationService::sendEmail] BODY: {$body}");
    }
}
