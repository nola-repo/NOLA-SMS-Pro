<?php

/**
 * NotificationService — Dispatch logic for system notifications.
 *
 * Handles:
 * - Low balance alerts via central NOLA CRM GHL location (24-hour circuit breaker)
 * - Delivery report notifications (Failed/Delivered) via customer-location GHL contact bridge
 * - Email dispatch through central GHL workflow (no external email provider required)
 *
 * Required env vars for central low-balance alerts:
 *   NOLA_ALERT_GHL_LOCATION_ID         — Central NOLA CRM GHL location id (required)
 *   NOLA_ALERT_GHL_TOKEN_REGISTRY_ID   — Firestore ghl_tokens doc id (defaults to NOLA_ALERT_GHL_LOCATION_ID)
 *   NOLA_ALERT_GHL_TAG                 — Tag to cycle on alert (default: nola-low-balance-alert)
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
                $updatePayload = $contactPayload;
                unset($updatePayload['locationId']);
                $updateResp = $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($updatePayload));
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

    // ═══════════════════════════════════════════════════════════════════════
    // Central Low-Balance Alert Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resolve NOLA SMS central custom field keys to GoHighLevel Custom Field IDs.
     *
     * Cache is stored in admin_config/nola_alerts.custom_field_ids.
     * Never writes to customer integrations/ghl_{locId} documents.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param \GhlClient                              $ghlClient   Client initialised for the central location
     * @param string                                  $centralLocationId
     * @return array<string, string>  Map of friendly key → real GHL custom field id
     */
    private static function resolveCentralGhlCustomFieldIds($db, $ghlClient, string $centralLocationId): array
    {
        $adminRef  = $db->collection('admin_config')->document('nola_alerts');
        $adminSnap = $adminRef->snapshot();

        $cachedIds = [];
        if ($adminSnap->exists()) {
            $cachedIds = $adminSnap->data()['custom_field_ids'] ?? [];
        }

        $requiredKeys = [
            'nola_sms_alert_type',
            'nola_sms_alert_id',
            'nola_sms_balance',
            'nola_sms_low_balance_threshold',
            'nola_sms_alerted_at',
            'nola_sms_registered_email',
            'nola_sms_source_location_id',
            'nola_sms_source_location_name',
            'nola_sms_requested_sender_id',
            'nola_sms_admin_notes',
            'nola_sms_otp_code',
            'nola_sms_sender_id_registered',
        ];

        // Return immediately if all required IDs are already cached
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

        // Fetch custom fields from the central GHL location
        try {
            $response = $ghlClient->request('GET', "/locations/{$centralLocationId}/customFields");
            if ($response['status'] === 200) {
                $respData   = json_decode($response['body'], true);
                $fieldsList = $respData['customFields'] ?? [];
                if (is_array($fieldsList)) {
                    $newMapping = is_array($cachedIds) ? $cachedIds : [];
                    foreach ($fieldsList as $field) {
                        $fieldName = $field['name']     ?? '';
                        $fieldKey  = $field['fieldKey'] ?? '';
                        $fieldId   = $field['id']       ?? '';
                        if (!$fieldId) continue;

                        // Normalise name and key, strip GHL "contact." prefix
                        $normName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', trim($fieldName)));
                        $normKey  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', str_replace('contact.', '', $fieldKey)));

                        foreach ($requiredKeys as $rk) {
                            if ($normName === $rk || $normKey === $rk) {
                                $newMapping[$rk] = $fieldId;
                            }
                        }
                    }

                    // Persist resolved mapping to admin_config/nola_alerts
                    $adminRef->set(['custom_field_ids' => $newMapping], ['merge' => true]);
                    $cachedIds = $newMapping;
                }
            } else {
                error_log(
                    "[NotificationService::resolveCentralGhlCustomFieldIds] Failed to fetch custom fields "
                    . "from central location {$centralLocationId} (HTTP " . $response['status'] . "): "
                    . $response['body']
                );
            }
        } catch (\Throwable $e) {
            error_log("[NotificationService::resolveCentralGhlCustomFieldIds] Exception: " . $e->getMessage());
        }

        return $cachedIds;
    }

    /**
     * Create or update a contact in the central NOLA CRM GHL location and
     * populate the eight NOLA alert custom fields. Optionally cycles the
     * alert tag so the central workflow triggers.
     *
     * Required env vars:
     *   NOLA_ALERT_GHL_LOCATION_ID       — central GHL location id
     *   NOLA_ALERT_GHL_TOKEN_REGISTRY_ID — Firestore ghl_tokens key (defaults to location id)
     *   NOLA_ALERT_GHL_TAG               — tag name (default: nola-low-balance-alert)
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $sourceLocationId    Customer GHL location id
     * @param string $email               Registered account email
     * @param string $name                Account owner name
     * @param int    $currentBalance      Balance after deduction
     * @param int    $threshold           Low-balance threshold
     * @param string $sourceLocationName  Customer workspace display name
     * @return string|null  Central GHL contact id on success, null on failure
     */
    private static function syncCentralLowBalanceAlertContact(
        $db,
        string $sourceLocationId,
        string $email,
        string $name,
        int $currentBalance,
        int $threshold,
        string $sourceLocationName,
        string $alertType
    ): ?string {
        require_once __DIR__ . '/GhlClient.php';

        // ── 1. Read required env vars ──────────────────────────────────────
        $centralLocationId = getenv('NOLA_ALERT_GHL_LOCATION_ID') ?: '';
        if ($centralLocationId === '') {
            error_log(
                "[LowBalanceAlert::syncCentral] NOLA_ALERT_GHL_LOCATION_ID is not set. "
                . "Cannot send low-balance email for source location {$sourceLocationId}."
            );
            return null;
        }

        $centralTokenRegistryId = getenv('NOLA_ALERT_GHL_TOKEN_REGISTRY_ID') ?: $centralLocationId;
        $alertTag               = getenv('NOLA_ALERT_GHL_TAG') ?: 'nola-low-balance-alert';

        // ── 2. Initialise GHL client for the central location ──────────────
        try {
            $ghlClient = new \GhlClient($db, $centralLocationId, $centralTokenRegistryId);
        } catch (\Throwable $e) {
            error_log(
                "[LowBalanceAlert::syncCentral] Failed to initialise GhlClient "
                . "(central={$centralLocationId}, registry={$centralTokenRegistryId}, "
                . "source={$sourceLocationId}): " . $e->getMessage()
            );
            return null;
        }

        $contactId = null;

        // ── 3. Search for existing contact by email in central location ────
        try {
            $searchUrl  = '/contacts/?locationId=' . urlencode($centralLocationId) . '&query=' . urlencode($email);
            $searchResp = $ghlClient->request('GET', $searchUrl);
            if ($searchResp['status'] === 200) {
                $searchData = json_decode($searchResp['body'], true);
                $contacts   = $searchData['contacts'] ?? $searchData['data'] ?? [];
                if (is_array($contacts)) {
                    foreach ($contacts as $c) {
                        if (isset($c['email']) && strtolower(trim((string)$c['email'])) === strtolower(trim($email))) {
                            $contactId = $c['id'];
                            break;
                        }
                    }
                }
            } else {
                error_log(
                    "[LowBalanceAlert::syncCentral] Contact search failed "
                    . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                    . "operation=search, http=" . $searchResp['status'] . "): "
                    . substr($searchResp['body'], 0, 500)
                );
            }
        } catch (\Throwable $e) {
            error_log(
                "[LowBalanceAlert::syncCentral] Contact search exception "
                . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
            );
        }

        // ── 4. Resolve real GHL custom field IDs from cache or API ─────────
        $fieldIdMap = self::resolveCentralGhlCustomFieldIds($db, $ghlClient, $centralLocationId);

        // ── 5. Build alert custom fields payload ───────────────────────────
        $now       = new \DateTimeImmutable();
        $timestamp = $now->format('c');
        $alertId   = "{$alertType}_{$sourceLocationId}_" . $now->format('YmdHis');

        $alertFields = [
            'nola_sms_alert_type'            => $alertType,
            'nola_sms_alert_id'              => $alertId,
            'nola_sms_balance'               => $currentBalance,
            'nola_sms_low_balance_threshold' => $threshold,
            'nola_sms_alerted_at'            => $timestamp,
            'nola_sms_registered_email'      => $email,
            'nola_sms_source_location_id'    => $sourceLocationId,
            'nola_sms_source_location_name'  => $sourceLocationName,
        ];

        $ghlCustomFields = [];
        foreach ($alertFields as $k => $v) {
            $fieldId = $fieldIdMap[$k] ?? null;
            if (!$fieldId) {
                error_log("[LowBalanceAlert::syncCentral] Warning: no GHL field ID for key '{$k}' — field will be skipped.");
                continue;
            }
            // GHL API requires string values for TEXT custom fields; ints may be silently ignored.
            $ghlCustomFields[] = ['id' => $fieldId, 'value' => (string) $v];
        }

        // ── 6. Build contact name parts ────────────────────────────────────
        $firstName = 'NOLA SMS';
        $lastName  = 'Alert Contact';
        if ($name) {
            $parts     = explode(' ', trim($name), 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? '';
        }

        $contactPayload = [
            'locationId'   => $centralLocationId,
            'email'        => $email,
            'firstName'    => $firstName,
            'lastName'     => $lastName,
            'customFields' => $ghlCustomFields,
        ];

        // ── 7. Upsert contact (update if found, create if not) ─────────────
        if ($contactId) {
            try {
                $updatePayload = $contactPayload;
                unset($updatePayload['locationId']);
                $updateResp = $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($updatePayload));
                if ($updateResp['status'] >= 400) {
                    error_log(
                        "[LowBalanceAlert::syncCentral] Contact update failed "
                        . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                        . "contact={$contactId}, operation=update, http=" . $updateResp['status'] . "): "
                        . substr($updateResp['body'], 0, 500)
                    );
                    return null;
                }
            } catch (\Throwable $e) {
                error_log(
                    "[LowBalanceAlert::syncCentral] Contact update exception "
                    . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
                );
                return null;
            }
        } else {
            try {
                $createResp = $ghlClient->request('POST', '/contacts/', json_encode($contactPayload));
                if ($createResp['status'] === 200 || $createResp['status'] === 201) {
                    $createData = json_decode($createResp['body'], true);
                    $contactId  = $createData['contact']['id'] ?? $createData['id'] ?? null;
                } else {
                    error_log(
                        "[LowBalanceAlert::syncCentral] Contact create failed "
                        . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                        . "operation=create, http=" . $createResp['status'] . "): "
                        . substr($createResp['body'], 0, 500)
                    );
                    return null;
                }
            } catch (\Throwable $e) {
                error_log(
                    "[LowBalanceAlert::syncCentral] Contact create exception "
                    . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
                );
                return null;
            }
        }

        if (!$contactId) {
            error_log(
                "[LowBalanceAlert::syncCentral] No contact id after upsert "
                . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email})"
            );
            return null;
        }

        // ── 8. Cycle alert tag to trigger the central workflow ─────────────
        try {
            $ghlClient->request('DELETE', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
            $tagResp = $ghlClient->request('POST', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
            if ($tagResp['status'] >= 400) {
                // Non-fatal: contact is updated; only the tag failed
                error_log(
                    "[LowBalanceAlert::syncCentral] Tag cycle failed "
                    . "(central={$centralLocationId}, source={$sourceLocationId}, contact={$contactId}, "
                    . "operation=tag, http=" . $tagResp['status'] . "): "
                    . substr($tagResp['body'], 0, 300)
                );
            }
        } catch (\Throwable $e) {
            error_log(
                "[LowBalanceAlert::syncCentral] Tag cycle exception "
                . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
            );
        }

        return $contactId;
    }

    /**
     * Dispatch welcome alert and sync registration details to central GHL.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param string $email
     * @param string $fullName
     * @param string $phone
     * @param string $role
     */
    public static function notifyWelcome($db, string $locationId, string $email, string $fullName, string $phone, string $role): void
    {
        $centralLocationId = getenv('NOLA_ALERT_GHL_LOCATION_ID') ?: '';
        if ($centralLocationId === '') {
            error_log("[WelcomeAlert] NOLA_ALERT_GHL_LOCATION_ID is not set. Skipping GHL delivery.");
            return;
        }

        $email = strtolower(trim($email));
        if ($email === '') {
            error_log("[WelcomeAlert] Email is empty. Skipping GHL delivery.");
            return;
        }

        require_once __DIR__ . '/GhlClient.php';

        $centralTokenRegistryId = getenv('NOLA_ALERT_GHL_TOKEN_REGISTRY_ID') ?: $centralLocationId;
        $alertTag = getenv('NOLA_ALERT_WELCOME_TAG') ?: 'nola-welcome-alert';

        try {
            $ghlClient = new \GhlClient($db, $centralLocationId, $centralTokenRegistryId);

            $contactId = null;
            $searchUrl = '/contacts/?locationId=' . urlencode($centralLocationId) . '&query=' . urlencode($email);
            $searchResp = $ghlClient->request('GET', $searchUrl);
            if ($searchResp['status'] === 200) {
                $searchData = json_decode($searchResp['body'], true);
                $contacts = $searchData['contacts'] ?? $searchData['data'] ?? [];
                if (is_array($contacts)) {
                    foreach ($contacts as $contact) {
                        if (isset($contact['email']) && strtolower(trim((string) $contact['email'])) === $email) {
                            $contactId = $contact['id'];
                            break;
                        }
                    }
                }
            }

            $fieldIdMap = self::resolveCentralGhlCustomFieldIds($db, $ghlClient, $centralLocationId);

            $locationName = '';
            if ($locationId !== '') {
                try {
                    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
                    $snap = $db->collection('integrations')->document($intDocId)->snapshot();
                    if ($snap->exists()) {
                        $locationName = (string) ($snap->data()['location_name'] ?? '');
                    }
                    if ($locationName === '') {
                        $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
                        if ($tokenSnap->exists()) {
                            $tokenData = $tokenSnap->data();
                            $locationName = (string) ($tokenData['location_name'] ?? '');
                            if ($locationName === '') {
                                $locationName = install_extract_company_name($tokenData) ?: '';
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("[WelcomeAlert] Could not resolve location name for {$locationId}: " . $e->getMessage());
                }
            }

            $now = new \DateTimeImmutable();
            $alertId = 'welcome_' . ($locationId !== '' ? $locationId : $role) . '_' . $now->format('YmdHis');

            $alertFields = [
                'nola_sms_alert_type'            => 'welcome',
                'nola_sms_alert_id'              => $alertId,
                'nola_sms_alerted_at'            => $now->format('c'),
                'nola_sms_registered_email'      => $email,
                'nola_sms_source_location_id'    => $locationId,
                'nola_sms_source_location_name'  => $locationName,
                'nola_sms_sender_id_registered'  => 'no',
            ];

            $ghlCustomFields = [];
            foreach ($alertFields as $key => $value) {
                $fieldId = $fieldIdMap[$key] ?? null;
                if (!$fieldId) {
                    error_log("[WelcomeAlert] Warning: no GHL field ID for key '{$key}' - field will be skipped.");
                    continue;
                }
                $ghlCustomFields[] = ['id' => $fieldId, 'value' => (string) $value];
            }

            $firstName = 'NOLA SMS';
            $lastName = 'User';
            if (trim($fullName) !== '') {
                $parts = explode(' ', trim($fullName), 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? '';
            }

            $contactPayload = [
                'locationId'   => $centralLocationId,
                'email'        => $email,
                'firstName'    => $firstName,
                'lastName'     => $lastName,
                'customFields' => $ghlCustomFields,
            ];
            if (trim($phone) !== '') {
                $contactPayload['phone'] = trim($phone);
            }

            if ($contactId) {
                $updatePayload = $contactPayload;
                unset($updatePayload['locationId']);
                $updateResp = $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($updatePayload));
                if ($updateResp['status'] >= 400) {
                    error_log(
                        "[WelcomeAlert] Contact update failed "
                        . "(central={$centralLocationId}, source={$locationId}, email={$email}, "
                        . "contact={$contactId}, http=" . $updateResp['status'] . "): "
                        . substr($updateResp['body'], 0, 500)
                    );
                    return;
                }
            } else {
                $createResp = $ghlClient->request('POST', '/contacts/', json_encode($contactPayload));
                if ($createResp['status'] === 200 || $createResp['status'] === 201) {
                    $createData = json_decode($createResp['body'], true);
                    $contactId = $createData['contact']['id'] ?? $createData['id'] ?? null;
                } else {
                    error_log(
                        "[WelcomeAlert] Contact create failed "
                        . "(central={$centralLocationId}, source={$locationId}, email={$email}, "
                        . "http=" . $createResp['status'] . "): "
                        . substr($createResp['body'], 0, 500)
                    );
                    return;
                }
            }

            if ($contactId) {
                $ghlClient->request('DELETE', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
                $tagResp = $ghlClient->request('POST', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
                if ($tagResp['status'] >= 400) {
                    error_log(
                        "[WelcomeAlert] Tag cycle failed "
                        . "(central={$centralLocationId}, source={$locationId}, contact={$contactId}, "
                        . "http=" . $tagResp['status'] . "): "
                        . substr($tagResp['body'], 0, 300)
                    );
                } else {
                    error_log("[WelcomeAlert] GHL welcome sync completed successfully: contactId={$contactId}");
                }
            }
        } catch (\Throwable $e) {
            error_log("[WelcomeAlert] GHL welcome sync failed: " . $e->getMessage());
        }
    }

    /**
     * Create or update a contact in the central NOLA CRM GHL location and
     * populate the NOLA alert custom fields for a Sender ID request.
     * Optionally cycles the alert tag so the central workflow triggers.
     *
     * Required env vars:
     *   NOLA_ALERT_GHL_LOCATION_ID       — central GHL location id
     *   NOLA_ALERT_GHL_TOKEN_REGISTRY_ID — Firestore ghl_tokens key (defaults to location id)
     *   NOLA_ALERT_SENDER_ID_TAG         — tag name (default: nola-sender-id-alert)
     */
    private static function syncCentralSenderIdContact(
        $db,
        string $sourceLocationId,
        string $email,
        string $name,
        string $requestedSenderId,
        string $alertType, // 'sender_id_pending', 'sender_id_approved', 'sender_id_rejected'
        ?string $adminNotes,
        string $sourceLocationName
    ): ?string {
        require_once __DIR__ . '/GhlClient.php';

        // ── 1. Read required env vars ──────────────────────────────────────
        $centralLocationId = getenv('NOLA_ALERT_GHL_LOCATION_ID') ?: '';
        if ($centralLocationId === '') {
            error_log(
                "[SenderIdAlert::syncCentral] NOLA_ALERT_GHL_LOCATION_ID is not set. "
                . "Cannot send sender ID email for source location {$sourceLocationId}."
            );
            return null;
        }

        $centralTokenRegistryId = getenv('NOLA_ALERT_GHL_TOKEN_REGISTRY_ID') ?: $centralLocationId;
        $alertTag               = getenv('NOLA_ALERT_SENDER_ID_TAG') ?: 'nola-sender-id-alert';

        // ── 2. Initialise GHL client for the central location ──────────────
        try {
            $ghlClient = new \GhlClient($db, $centralLocationId, $centralTokenRegistryId);
        } catch (\Throwable $e) {
            error_log(
                "[SenderIdAlert::syncCentral] Failed to initialise GhlClient "
                . "(central={$centralLocationId}, registry={$centralTokenRegistryId}, "
                . "source={$sourceLocationId}): " . $e->getMessage()
            );
            return null;
        }

        $contactId = null;

        // ── 3. Search for existing contact by email in central location ────
        try {
            $searchUrl  = '/contacts/?locationId=' . urlencode($centralLocationId) . '&query=' . urlencode($email);
            $searchResp = $ghlClient->request('GET', $searchUrl);
            if ($searchResp['status'] === 200) {
                $searchData = json_decode($searchResp['body'], true);
                $contacts   = $searchData['contacts'] ?? $searchData['data'] ?? [];
                if (is_array($contacts)) {
                    foreach ($contacts as $c) {
                        if (isset($c['email']) && strtolower(trim((string)$c['email'])) === strtolower(trim($email))) {
                            $contactId = $c['id'];
                            break;
                        }
                    }
                }
            } else {
                error_log(
                    "[SenderIdAlert::syncCentral] Contact search failed "
                    . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                    . "operation=search, http=" . $searchResp['status'] . "): "
                    . substr($searchResp['body'], 0, 500)
                );
            }
        } catch (\Throwable $e) {
            error_log(
                "[SenderIdAlert::syncCentral] Contact search exception "
                . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
            );
        }

        // ── 4. Resolve real GHL custom field IDs from cache or API ─────────
        $fieldIdMap = self::resolveCentralGhlCustomFieldIds($db, $ghlClient, $centralLocationId);

        // ── 5. Build alert custom fields payload ───────────────────────────
        $now       = new \DateTimeImmutable();
        $timestamp = $now->format('c');
        $alertId   = "sender_id_{$sourceLocationId}_{$requestedSenderId}_" . $now->format('YmdHis');

        $alertFields = [
            'nola_sms_alert_type'            => $alertType,
            'nola_sms_alert_id'              => $alertId,
            'nola_sms_alerted_at'            => $timestamp,
            'nola_sms_registered_email'      => $email,
            'nola_sms_source_location_id'    => $sourceLocationId,
            'nola_sms_source_location_name'  => $sourceLocationName,
            'nola_sms_requested_sender_id'   => $requestedSenderId,
            'nola_sms_admin_notes'           => $adminNotes ?? '',
            'nola_sms_sender_id_registered'  => 'yes',
        ];

        $ghlCustomFields = [];
        foreach ($alertFields as $k => $v) {
            $fieldId = $fieldIdMap[$k] ?? null;
            if (!$fieldId) {
                error_log("[SenderIdAlert::syncCentral] Warning: no GHL field ID for key '{$k}' — field will be skipped.");
                continue;
            }
            $ghlCustomFields[] = ['id' => $fieldId, 'value' => (string) $v];
        }

        // ── 6. Build contact name parts ────────────────────────────────────
        $firstName = 'NOLA SMS';
        $lastName  = 'Alert Contact';
        if ($name) {
            $parts     = explode(' ', trim($name), 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? '';
        }

        $contactPayload = [
            'locationId'   => $centralLocationId,
            'email'        => $email,
            'firstName'    => $firstName,
            'lastName'     => $lastName,
            'customFields' => $ghlCustomFields,
        ];

        // ── 7. Upsert contact (update if found, create if not) ─────────────
        if ($contactId) {
            try {
                $updatePayload = $contactPayload;
                unset($updatePayload['locationId']);
                $updateResp = $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($updatePayload));
                if ($updateResp['status'] >= 400) {
                    error_log(
                        "[SenderIdAlert::syncCentral] Contact update failed "
                        . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                        . "contact={$contactId}, operation=update, http=" . $updateResp['status'] . "): "
                        . substr($updateResp['body'], 0, 500)
                    );
                    return null;
                }
            } catch (\Throwable $e) {
                error_log(
                    "[SenderIdAlert::syncCentral] Contact update exception "
                    . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
                );
                return null;
            }
        } else {
            try {
                $createResp = $ghlClient->request('POST', '/contacts/', json_encode($contactPayload));
                if ($createResp['status'] === 200 || $createResp['status'] === 201) {
                    $createData = json_decode($createResp['body'], true);
                    $contactId  = $createData['contact']['id'] ?? $createData['id'] ?? null;
                } else {
                    error_log(
                        "[SenderIdAlert::syncCentral] Contact create failed "
                        . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                        . "operation=create, http=" . $createResp['status'] . "): "
                        . substr($createResp['body'], 0, 500)
                    );
                    return null;
                }
            } catch (\Throwable $e) {
                error_log(
                    "[SenderIdAlert::syncCentral] Contact create exception "
                    . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
                );
                return null;
            }
        }

        if (!$contactId) {
            error_log(
                "[SenderIdAlert::syncCentral] No contact id after upsert "
                . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email})"
            );
            return null;
        }

        // ── 8. Cycle alert tag to trigger the central workflow ─────────────
        try {
            $ghlClient->request('DELETE', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
            $tagResp = $ghlClient->request('POST', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
            if ($tagResp['status'] >= 400) {
                error_log(
                    "[SenderIdAlert::syncCentral] Tag cycle failed "
                    . "(central={$centralLocationId}, source={$sourceLocationId}, contact={$contactId}, "
                    . "operation=tag, http=" . $tagResp['status'] . "): "
                    . substr($tagResp['body'], 0, 300)
                );
            }
        } catch (\Throwable $e) {
            error_log(
                "[SenderIdAlert::syncCentral] Tag cycle exception "
                . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
            );
        }

        return $contactId;
    }

    /**
     * Dispatch email notification for Sender ID requests and status updates.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param string $requestedSenderId
     * @param string $status                  'pending', 'approved', 'rejected'
     * @param string|null $adminNotes         Notes or rejection reason
     */
    public static function notifySenderIdStatus($db, string $locationId, string $requestedSenderId, string $status, ?string $adminNotes = null): void
    {
        // ── 1. Resolve registered account details ────────────────────────
        $details = self::getAccountDetails($db, $locationId);
        $email   = $details['email'];
        $name    = $details['name'] ?? 'NOLA Owner';

        if (!$email) {
            error_log("[SenderIdAlert] No registered account email found for location {$locationId}, skipping central sync.");
            return;
        }

        // ── 2. Resolve source location name ──────────────────────────────
        $sourceLocationName = '';
        try {
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
            $snap = $db->collection('integrations')->document($intDocId)->snapshot();
            if ($snap->exists()) {
                $sourceLocationName = (string) ($snap->data()['location_name'] ?? '');
            }
            if ($sourceLocationName === '') {
                $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
                if ($tokenSnap->exists()) {
                    $sourceLocationName = (string) ($tokenSnap->data()['location_name'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            error_log("[SenderIdAlert] Could not resolve location name for {$locationId}: " . $e->getMessage());
        }

        // ── 3. Normalize alert type ──────────────────────────────────────
        $alertType = 'sender_id_' . strtolower($status);

        // ── 4. Sync to central GHL location ──────────────────────────────
        error_log("[SenderIdAlert] Triggering central GHL sync for location {$locationId} (email: {$email}, sender: {$requestedSenderId}, status: {$status})");

        self::syncCentralSenderIdContact(
            $db,
            $locationId,
            $email,
            $name,
            $requestedSenderId,
            $alertType,
            $adminNotes,
            $sourceLocationName
        );
    }

    /**
     * Create or update a contact in the central NOLA CRM GHL location and
     * populate the NOLA alert custom fields for a Top-up Success event.
     * Optionally cycles the alert tag so the central workflow triggers.
     *
     * Required env vars:
     *   NOLA_ALERT_GHL_LOCATION_ID       — central GHL location id
     *   NOLA_ALERT_GHL_TOKEN_REGISTRY_ID — Firestore ghl_tokens key (defaults to location id)
     *   NOLA_ALERT_TOP_UP_SUCCESS_TAG    — tag name (default: nola-top-up-success-alert)
     */
    private static function syncCentralTopUpSuccessContact(
        $db,
        string $sourceLocationId,
        string $email,
        string $name,
        int $newBalance,
        string $description,
        string $sourceLocationName
    ): ?string {
        require_once __DIR__ . '/GhlClient.php';

        // ── 1. Read required env vars ──────────────────────────────────────
        $centralLocationId = getenv('NOLA_ALERT_GHL_LOCATION_ID') ?: '';
        if ($centralLocationId === '') {
            error_log(
                "[TopUpSuccessAlert::syncCentral] NOLA_ALERT_GHL_LOCATION_ID is not set. "
                . "Cannot send top-up success email for source location {$sourceLocationId}."
            );
            return null;
        }

        $centralTokenRegistryId = getenv('NOLA_ALERT_GHL_TOKEN_REGISTRY_ID') ?: $centralLocationId;
        $alertTag               = getenv('NOLA_ALERT_TOP_UP_SUCCESS_TAG') ?: 'nola-top-up-success-alert';

        // ── 2. Initialise GHL client for the central location ──────────────
        try {
            $ghlClient = new \GhlClient($db, $centralLocationId, $centralTokenRegistryId);
        } catch (\Throwable $e) {
            error_log(
                "[TopUpSuccessAlert::syncCentral] Failed to initialise GhlClient "
                . "(central={$centralLocationId}, registry={$centralTokenRegistryId}, "
                . "source={$sourceLocationId}): " . $e->getMessage()
            );
            return null;
        }

        $contactId = null;

        // ── 3. Search for existing contact by email in central location ────
        try {
            $searchUrl  = '/contacts/?locationId=' . urlencode($centralLocationId) . '&query=' . urlencode($email);
            $searchResp = $ghlClient->request('GET', $searchUrl);
            if ($searchResp['status'] === 200) {
                $searchData = json_decode($searchResp['body'], true);
                $contacts   = $searchData['contacts'] ?? $searchData['data'] ?? [];
                if (is_array($contacts)) {
                    foreach ($contacts as $c) {
                        if (isset($c['email']) && strtolower(trim((string)$c['email'])) === strtolower(trim($email))) {
                            $contactId = $c['id'];
                            break;
                        }
                    }
                }
            } else {
                error_log(
                    "[TopUpSuccessAlert::syncCentral] Contact search failed "
                    . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                    . "operation=search, http=" . $searchResp['status'] . "): "
                    . substr($searchResp['body'], 0, 500)
                );
            }
        } catch (\Throwable $e) {
            error_log(
                "[TopUpSuccessAlert::syncCentral] Contact search exception "
                . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
            );
        }

        // ── 4. Resolve real GHL custom field IDs from cache or API ─────────
        $fieldIdMap = self::resolveCentralGhlCustomFieldIds($db, $ghlClient, $centralLocationId);

        // ── 5. Build alert custom fields payload ───────────────────────────
        $now       = new \DateTimeImmutable();
        $timestamp = $now->format('c');
        $alertId   = "top_up_{$sourceLocationId}_" . $now->format('YmdHis');

        $alertFields = [
            'nola_sms_alert_type'            => 'top_up_success',
            'nola_sms_alert_id'              => $alertId,
            'nola_sms_alerted_at'            => $timestamp,
            'nola_sms_registered_email'      => $email,
            'nola_sms_source_location_id'    => $sourceLocationId,
            'nola_sms_source_location_name'  => $sourceLocationName,
            'nola_sms_balance'               => $newBalance,
            'nola_sms_admin_notes'           => $description,
        ];

        $ghlCustomFields = [];
        foreach ($alertFields as $k => $v) {
            $fieldId = $fieldIdMap[$k] ?? null;
            if (!$fieldId) {
                error_log("[TopUpSuccessAlert::syncCentral] Warning: no GHL field ID for key '{$k}' — field will be skipped.");
                continue;
            }
            $ghlCustomFields[] = ['id' => $fieldId, 'value' => (string) $v];
        }

        // ── 6. Build contact name parts ────────────────────────────────────
        $firstName = 'NOLA SMS';
        $lastName  = 'Alert Contact';
        if ($name) {
            $parts     = explode(' ', trim($name), 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? '';
        }

        $contactPayload = [
            'locationId'   => $centralLocationId,
            'email'        => $email,
            'firstName'    => $firstName,
            'lastName'     => $lastName,
            'customFields' => $ghlCustomFields,
        ];

        // ── 7. Upsert contact (update if found, create if not) ─────────────
        if ($contactId) {
            try {
                $updatePayload = $contactPayload;
                unset($updatePayload['locationId']);
                $updateResp = $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($updatePayload));
                if ($updateResp['status'] >= 400) {
                    error_log(
                        "[TopUpSuccessAlert::syncCentral] Contact update failed "
                        . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                        . "contact={$contactId}, operation=update, http=" . $updateResp['status'] . "): "
                        . substr($updateResp['body'], 0, 500)
                    );
                    return null;
                }
            } catch (\Throwable $e) {
                error_log(
                    "[TopUpSuccessAlert::syncCentral] Contact update exception "
                    . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
                );
                return null;
            }
        } else {
            try {
                $createResp = $ghlClient->request('POST', '/contacts/', json_encode($contactPayload));
                if ($createResp['status'] === 200 || $createResp['status'] === 201) {
                    $createData = json_decode($createResp['body'], true);
                    $contactId  = $createData['contact']['id'] ?? $createData['id'] ?? null;
                } else {
                    error_log(
                        "[TopUpSuccessAlert::syncCentral] Contact create failed "
                        . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email}, "
                        . "operation=create, http=" . $createResp['status'] . "): "
                        . substr($createResp['body'], 0, 500)
                    );
                    return null;
                }
            } catch (\Throwable $e) {
                error_log(
                    "[TopUpSuccessAlert::syncCentral] Contact create exception "
                    . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
                );
                return null;
            }
        }

        if (!$contactId) {
            error_log(
                "[TopUpSuccessAlert::syncCentral] No contact id after upsert "
                . "(central={$centralLocationId}, source={$sourceLocationId}, email={$email})"
            );
            return null;
        }

        // ── 8. Cycle alert tag to trigger the central workflow ─────────────
        try {
            $ghlClient->request('DELETE', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
            $tagResp = $ghlClient->request('POST', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
            if ($tagResp['status'] >= 400) {
                error_log(
                    "[TopUpSuccessAlert::syncCentral] Tag cycle failed "
                    . "(central={$centralLocationId}, source={$sourceLocationId}, contact={$contactId}, "
                    . "operation=tag, http=" . $tagResp['status'] . "): "
                    . substr($tagResp['body'], 0, 300)
                );
            }
        } catch (\Throwable $e) {
            error_log(
                "[TopUpSuccessAlert::syncCentral] Tag cycle exception "
                . "(central={$centralLocationId}, source={$sourceLocationId}): " . $e->getMessage()
            );
        }

        return $contactId;
    }

    /**
     * Dispatch email notification for a successful Top-up.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param int $amount
     * @param int $newBalance
     */
    public static function notifyTopUpSuccess($db, string $locationId, int $amount, int $newBalance): void
    {
        // ── 1. Resolve registered account details ────────────────────────
        $details = self::getAccountDetails($db, $locationId);
        $email   = $details['email'];
        $name    = $details['name'] ?? 'NOLA Owner';

        if (!$email) {
            error_log("[TopUpSuccessAlert] No registered account email found for location {$locationId}, skipping central sync.");
            return;
        }

        // ── 2. Resolve source location name ──────────────────────────────
        $sourceLocationName = '';
        try {
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
            $snap = $db->collection('integrations')->document($intDocId)->snapshot();
            if ($snap->exists()) {
                $sourceLocationName = (string) ($snap->data()['location_name'] ?? '');
            }
            if ($sourceLocationName === '') {
                $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
                if ($tokenSnap->exists()) {
                    $sourceLocationName = (string) ($tokenSnap->data()['location_name'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            error_log("[TopUpSuccessAlert] Could not resolve location name for {$locationId}: " . $e->getMessage());
        }

        $description = "+{$amount} credits successfully loaded";

        // ── 3. Sync to central GHL location ──────────────────────────────
        error_log("[TopUpSuccessAlert] Triggering central GHL sync for location {$locationId} (email: {$email}, amount: {$amount}, balance: {$newBalance})");

        self::syncCentralTopUpSuccessContact(
            $db,
            $locationId,
            $email,
            $name,
            $newBalance,
            $description,
            $sourceLocationName
        );
    }

    /**
     * Check if a low-balance alert should be sent after a credit deduction.
     *
     * Uses the central NOLA CRM GHL location to send a single workflow email
     * for every connected NOLA SMS Pro account. No customer-subaccount workflow
     * is required. Central sync is gated only on low_balance_alert_enabled.
     *
     * Circuit breaker: fires at most once per 24 hours while balance stays at
     * or below the threshold. Resets automatically when balance rises above it.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId    Source customer GHL location id
     * @param int    $currentBalance Balance AFTER the deduction
     */
    public static function checkLowBalance($db, string $locationId, int $currentBalance): void
    {
        // 1. Load source-location notification preferences
        $prefs = self::getPreferences($db, $locationId);

        if (!$prefs['low_balance_alert_enabled']) {
            return;
        }

        $threshold = $prefs['low_balance_threshold'];

        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
        $docRef   = $db->collection('integrations')->document($intDocId);

        // 2. Reset circuit breakers if balance recovers above threshold
        if ($currentBalance >= $threshold) {
            $snap = $docRef->snapshot();
            if ($snap->exists()) {
                $existingPrefs = $snap->data()['notification_preferences'] ?? [];
                if (isset($existingPrefs['last_low_balance_notified_at']) || isset($existingPrefs['last_zero_balance_notified_at'])) {
                    $existingPrefs['last_low_balance_notified_at'] = \Google\Cloud\Firestore\FieldValue::deleteField();
                    $existingPrefs['last_zero_balance_notified_at'] = \Google\Cloud\Firestore\FieldValue::deleteField();
                    $docRef->set(['notification_preferences' => $existingPrefs], ['merge' => true]);
                    error_log("[LowBalanceAlert] Cleared last_low_balance_notified_at and last_zero_balance_notified_at for location {$locationId}");
                }
            }
            return;
        }

        // 3. Determine alert type
        $alertType = ($currentBalance <= 0) ? 'zero_balance' : 'low_balance';

        // 4. Check appropriate circuit breaker (24-hour limit)
        $snap = $docRef->snapshot();
        $existingPrefs = [];
        if ($snap->exists()) {
            $data = $snap->data();
            $existingPrefs = $data['notification_preferences'] ?? [];
            
            $breakerField = ($alertType === 'zero_balance') 
                ? 'last_zero_balance_notified_at' 
                : 'last_low_balance_notified_at';
                
            $lastNotified = $existingPrefs[$breakerField] ?? null;
            if ($lastNotified) {
                $lastTs = $lastNotified instanceof \Google\Cloud\Core\Timestamp
                    ? $lastNotified->get()->getTimestamp()
                    : (int) $lastNotified;
                
                if (time() - $lastTs < 86400) {
                    // Suppress duplicate alert (already sent within last 24h)
                    return;
                }
            }
        }

        // 5. Resolve registered account details
        $details = self::getAccountDetails($db, $locationId);
        $email   = $details['email'];
        $name    = $details['name'] ?? 'NOLA Owner';

        if (!$email) {
            error_log("[LowBalanceAlert] No registered account email found for location {$locationId}, skipping alert.");
            return;
        }

        $sourceLocationName = '';
        if ($snap->exists()) {
            $sourceLocationName = (string) ($snap->data()['location_name'] ?? '');
        }

        // 6. Sync to Central GHL Location (triggers GHL Workflow)
        error_log("[LowBalanceAlert] Triggering central GHL sync for location {$locationId} (email: {$email}, balance: {$currentBalance}, type: {$alertType})");
        $centralContactId = self::syncCentralLowBalanceAlertContact(
            $db,
            $locationId,
            $email,
            $name,
            $currentBalance,
            $threshold,
            $sourceLocationName,
            $alertType // Pass the dynamically resolved alert type
        );

        // 7. Save metadata and update circuit breaker key
        $now = new \DateTimeImmutable();
        $timestampField = ($alertType === 'zero_balance') 
            ? 'last_zero_balance_notified_at' 
            : 'last_low_balance_notified_at';

        $existingPrefs[$timestampField] = new \Google\Cloud\Core\Timestamp($now);
        $existingPrefs['last_low_balance_email_sent_to'] = $email;

        if ($centralContactId !== null) {
            $existingPrefs['last_low_balance_email_status']  = 'sent';
            $existingPrefs['central_ghl_alert_contact_id']   = $centralContactId;
        } else {
            $existingPrefs['last_low_balance_email_status'] = 'failed';
        }
        $docRef->set(['notification_preferences' => $existingPrefs], ['merge' => true]);

        // 8. Create Internal Admin Notification in Firestore
        try {
            $db->collection('admin_notifications')->add([
                'type'          => $alertType,
                'location_id'   => $locationId,
                'location_name' => $sourceLocationName,
                'email'         => $email,
                'balance'       => $currentBalance,
                'threshold'     => $threshold,
                'created_at'    => new \Google\Cloud\Core\Timestamp($now),
                'read'          => false
            ]);
            error_log("[AdminNotification] Successfully logged {$alertType} notification for {$locationId}");
        } catch (\Throwable $e) {
            error_log("[AdminNotification] Failed to log internal admin notification: " . $e->getMessage());
        }
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

    // ═══════════════════════════════════════════════════════════════════════
    // Admin Notification Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resolve a GHL location's display name from Firestore.
     *
     * Checks integrations/ghl_{locId}.location_name first, then falls back
     * to ghl_tokens/{locId}.location_name. Returns empty string if not found.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @return string
     */
    public static function resolveLocationName($db, string $locationId): string
    {
        try {
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
            $snap = $db->collection('integrations')->document($intDocId)->snapshot();
            if ($snap->exists()) {
                $name = (string)($snap->data()['location_name'] ?? '');
                if ($name !== '') {
                    return $name;
                }
            }
            $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
            if ($tokenSnap->exists()) {
                return (string)($tokenSnap->data()['location_name'] ?? '');
            }
        } catch (\Throwable $e) {
            error_log("[NotificationService::resolveLocationName] Failed for {$locationId}: " . $e->getMessage());
        }
        return '';
    }

    /**
     * Write a single document to the admin_notifications Firestore collection.
     *
     * Automatically sets created_at (server-side timestamp) and read=false.
     * The $fields array must include at minimum: type, location_id, location_name.
     * Optional keys: email, balance, threshold, metadata (array).
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param array $fields  Notification fields (excluding created_at / read).
     */
    public static function createAdminNotification($db, array $fields): void
    {
        try {
            $payload = array_merge([
                'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
                'read'       => false,
            ], $fields);

            // Ensure metadata is always a proper Firestore map, never null
            if (!isset($payload['metadata']) || !is_array($payload['metadata'])) {
                unset($payload['metadata']); // omit entirely so the field doesn't exist
            }

            $db->collection('admin_notifications')->add($payload);
            error_log('[AdminNotification] Logged notification type=' . ($fields['type'] ?? '?')
                . ' location=' . ($fields['location_id'] ?? '?'));
        } catch (\Throwable $e) {
            error_log('[AdminNotification] Failed to write notification: ' . $e->getMessage());
        }
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

    /**
     * Dispatch OTP code via central GHL workflow.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $email
     * @param string $otp
     */
    public static function notifyForgotPasswordOtp($db, string $email, string $otp): void
    {
        $centralLocationId = getenv('NOLA_ALERT_GHL_LOCATION_ID') ?: '';
        if ($centralLocationId === '') {
            error_log("[forgot_password_otp] NOLA_ALERT_GHL_LOCATION_ID not set. Skipping GHL delivery.");
            return;
        }

        require_once __DIR__ . '/GhlClient.php';
        $centralTokenRegistryId = getenv('NOLA_ALERT_GHL_TOKEN_REGISTRY_ID') ?: $centralLocationId;
        $alertTag = getenv('NOLA_ALERT_OTP_TAG') ?: 'nola-otp-alert';

        try {
            $ghlClient = new \GhlClient($db, $centralLocationId, $centralTokenRegistryId);
            
            // 1. Search for contact in central location
            $contactId = null;
            $searchUrl = '/contacts/?locationId=' . urlencode($centralLocationId) . '&query=' . urlencode($email);
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

            // 2. Resolve custom field IDs
            $fieldIdMap = self::resolveCentralGhlCustomFieldIds($db, $ghlClient, $centralLocationId);
            
            $now = new \DateTimeImmutable();
            $timestamp = $now->format('c');
            $alertId = "otp_{$email}_" . $now->format('YmdHis');

            $alertFields = [
                'nola_sms_alert_type' => 'forgot_password_otp',
                'nola_sms_alert_id'   => $alertId,
                'nola_sms_otp_code'   => $otp,
                'nola_sms_alerted_at' => $timestamp,
            ];

            $ghlCustomFields = [];
            foreach ($alertFields as $k => $v) {
                $fieldId = $fieldIdMap[$k] ?? null;
                if ($fieldId) {
                    $ghlCustomFields[] = ['id' => $fieldId, 'value' => (string) $v];
                }
            }

            // 2.5 Fetch user name from database
            $firstName = '';
            $lastName = '';

            // Search in admins collection
            $adminSnap = $db->collection('admins')->document($email)->snapshot();
            if ($adminSnap->exists()) {
                $userData = $adminSnap->data();
                $firstName = $userData['firstName'] ?? '';
                $lastName = $userData['lastName'] ?? '';
                if ($firstName === '' && $lastName === '') {
                    $name = $userData['name'] ?? '';
                    if ($name !== '') {
                        $parts = explode(' ', trim($name), 2);
                        $firstName = $parts[0];
                        $lastName = $parts[1] ?? '';
                    }
                }
            }

            if ($firstName === '' && $lastName === '') {
                $results = $db->collection('admins')->where('email', '=', $email)->limit(1)->documents();
                foreach ($results as $doc) {
                    if ($doc->exists()) {
                        $userData = $doc->data();
                        $firstName = $userData['firstName'] ?? '';
                        $lastName = $userData['lastName'] ?? '';
                        if ($firstName === '' && $lastName === '') {
                            $name = $userData['name'] ?? '';
                            if ($name !== '') {
                                $parts = explode(' ', trim($name), 2);
                                $firstName = $parts[0];
                                $lastName = $parts[1] ?? '';
                            }
                        }
                        break;
                    }
                }
            }

            // Search in agency_users collection
            if ($firstName === '' && $lastName === '') {
                $results = $db->collection('agency_users')->where('email', '=', $email)->limit(1)->documents();
                foreach ($results as $doc) {
                    if ($doc->exists()) {
                        $userData = $doc->data();
                        $firstName = $userData['firstName'] ?? '';
                        $lastName = $userData['lastName'] ?? '';
                        if ($firstName === '' && $lastName === '') {
                            $name = $userData['name'] ?? '';
                            if ($name !== '') {
                                $parts = explode(' ', trim($name), 2);
                                $firstName = $parts[0];
                                $lastName = $parts[1] ?? '';
                            }
                        }
                        break;
                    }
                }
            }

            // Search in users collection
            if ($firstName === '' && $lastName === '') {
                $results = $db->collection('users')->where('email', '=', $email)->limit(1)->documents();
                foreach ($results as $doc) {
                    if ($doc->exists()) {
                        $userData = $doc->data();
                        $firstName = $userData['firstName'] ?? '';
                        $lastName = $userData['lastName'] ?? '';
                        if ($firstName === '' && $lastName === '') {
                            $name = $userData['name'] ?? '';
                            if ($name !== '') {
                                $parts = explode(' ', trim($name), 2);
                                $firstName = $parts[0];
                                $lastName = $parts[1] ?? '';
                            }
                        }
                        break;
                    }
                }
            }

            if ($firstName === '') {
                $firstName = 'NOLA SMS';
            }
            if ($lastName === '') {
                $lastName = 'User';
            }

            $contactPayload = [
                'locationId'   => $centralLocationId,
                'email'        => $email,
                'firstName'    => $firstName,
                'lastName'     => $lastName,
                'customFields' => $ghlCustomFields,
            ];

            // 3. Upsert
            if ($contactId) {
                unset($contactPayload['locationId']);
                $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($contactPayload));
            } else {
                $createResp = $ghlClient->request('POST', '/contacts/', json_encode($contactPayload));
                if ($createResp['status'] === 200 || $createResp['status'] === 201) {
                    $createData = json_decode($createResp['body'], true);
                    $contactId = $createData['contact']['id'] ?? $createData['id'] ?? null;
                }
            }

            // 4. Cycle tag to trigger GHL Workflow
            if ($contactId) {
                $ghlClient->request('DELETE', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
                $ghlClient->request('POST', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
                error_log("[forgot_password_otp] Successfully synced OTP contact to GHL: {$contactId}");
            }
        } catch (\Throwable $e) {
            error_log("[forgot_password_otp] GHL Contact Bridge sync failed: " . $e->getMessage());
        }
    }
}
