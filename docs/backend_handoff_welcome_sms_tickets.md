# Backend Handoff — Welcome Notifications & Sender ID Automation

This handoff details the changes needed in the PHP backend to trigger GHL Central Location workflows for:
1. **Welcome Email & SMS Workflow** on successful sub-account installation and account registration.
2. **Sender ID Onboarding Status Sync** when a customer submits a Sender ID request, which updates `nola_sms_sender_id_registered = 'yes'` to exit them from onboarding reminders.

---

## 1. Summary of Backend Dev Tasks

| Task | Target File | Description |
| :--- | :--- | :--- |
| **Add Custom Field** | `api/services/NotificationService.php` | Add `nola_sms_sender_id_registered` to `$requiredKeys` mapping. |
| **Add `notifyWelcome` Method** | `api/services/NotificationService.php` | Add method to sync contact and cycle `nola-welcome-alert` tag. |
| **Update Sender ID Sync** | `api/services/NotificationService.php` | Update `syncCentralSenderIdContact` to set `nola_sms_sender_id_registered = 'yes'`. |
| **Integrate in Install Callback** | `api/auth/register_from_install.php` | Call `notifyWelcome` during the finalization step of the install callback. |
| **Integrate in Manual Register** | `api/auth/register.php` | Call `notifyWelcome` on successful new user signup. |

---

## 2. NotificationService.php Changes

File: [api/services/NotificationService.php](file:///C:/Users/User/nola-sms-pro-backend/api/services/NotificationService.php)

### 2.1 Add Custom Field Keys
In the `resolveCentralGhlCustomFieldIds` method, add the registration state key to the `$requiredKeys` array:

```php
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
            'nola_sms_sender_id_registered', // <-- ADD THIS
        ];
```

### 2.2 Update `syncCentralSenderIdContact`
Update the `$alertFields` array in `syncCentralSenderIdContact` to write `'yes'` (or `'pending'`) to `nola_sms_sender_id_registered` so that GHL can terminate the onboarding reminder loops:

```php
        $alertFields = [
            'nola_sms_alert_type'            => $alertType,
            'nola_sms_alert_id'              => $alertId,
            'nola_sms_alerted_at'            => $timestamp,
            'nola_sms_registered_email'      => $email,
            'nola_sms_source_location_id'    => $sourceLocationId,
            'nola_sms_source_location_name'  => $sourceLocationName,
            'nola_sms_requested_sender_id'   => $requestedSenderId,
            'nola_sms_admin_notes'           => $adminNotes ?? '',
            'nola_sms_sender_id_registered'  => 'yes', // <-- ADD THIS
        ];
```

### 2.3 Add `notifyWelcome` Method
Add the following public static function to the `NotificationService` class:

```php
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

        require_once __DIR__ . '/GhlClient.php';
        $centralTokenRegistryId = getenv('NOLA_ALERT_GHL_TOKEN_REGISTRY_ID') ?: $centralLocationId;
        $alertTag = 'nola-welcome-alert';

        try {
            $ghlClient = new \GhlClient($db, $centralLocationId, $centralTokenRegistryId);

            // 1. Search for existing contact by email
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

            // 2. Resolve custom fields
            $fieldIdMap = self::resolveCentralGhlCustomFieldIds($db, $ghlClient, $centralLocationId);

            // Fetch location name
            $locationName = '';
            try {
                $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
                $snap = $db->collection('integrations')->document($intDocId)->snapshot();
                if ($snap->exists()) {
                    $locationName = (string) ($snap->data()['location_name'] ?? '');
                }
                if ($locationName === '') {
                    $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
                    if ($tokenSnap->exists()) {
                        $locationName = (string) ($tokenSnap->data()['location_name'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                error_log("[WelcomeAlert] Could not resolve location name: " . $e->getMessage());
            }

            $now = new \DateTimeImmutable();
            $alertId = 'welcome_' . $locationId . '_' . $now->format('YmdHis');

            $alertFields = [
                'nola_sms_alert_type'            => 'welcome',
                'nola_sms_alert_id'              => $alertId,
                'nola_sms_alerted_at'            => $now->format('c'),
                'nola_sms_registered_email'      => $email,
                'nola_sms_source_location_id'    => $locationId,
                'nola_sms_source_location_name'  => $locationName,
                'nola_sms_sender_id_registered'  => 'no', // Welcome initially starts with no Sender ID
            ];

            $ghlCustomFields = [];
            foreach ($alertFields as $k => $v) {
                $fieldId = $fieldIdMap[$k] ?? null;
                if ($fieldId) {
                    $ghlCustomFields[] = ['id' => $fieldId, 'value' => (string) $v];
                }
            }

            $firstName = 'NOLA SMS';
            $lastName = 'User';
            if ($fullName) {
                $parts = explode(' ', trim($fullName), 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? '';
            }

            $contactPayload = [
                'locationId'   => $centralLocationId,
                'email'        => $email,
                'phone'        => $phone,
                'firstName'    => $firstName,
                'lastName'     => $lastName,
                'customFields' => $ghlCustomFields,
            ];

            // 3. Upsert contact
            if ($contactId) {
                unset($contactPayload['locationId']);
                $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($contactPayload));
            } else {
                $createResp = $ghlClient->request('POST', '/contacts/', json_encode($contactPayload));
                if (in_array($createResp['status'], [200, 201])) {
                    $createData = json_decode($createResp['body'], true);
                    $contactId = $createData['contact']['id'] ?? $createData['id'] ?? null;
                }
            }

            // 4. Cycle tag to fire workflow
            if ($contactId) {
                $ghlClient->request('DELETE', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
                $ghlClient->request('POST',   "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
                error_log("[WelcomeAlert] GHL welcome sync completed successfully: contactId={$contactId}");
            }
        } catch (\Throwable $e) {
            error_log("[WelcomeAlert] GHL welcome sync failed: " . $e->getMessage());
        }
    }
```

---

## 3. Register from Install Callback Trigger

File: [api/auth/register_from_install.php](file:///C:/Users/User/nola-sms-pro-backend/api/auth/register_from_install.php)

In `register_from_install_run_deferred_finalize()`, add the call to `NotificationService::notifyWelcome()` to trigger the sync after successful registration and marketplace install. Since this method already runs after `fastcgi_finish_request()`, it will execute asynchronously in the background.

```php
function register_from_install_run_deferred_finalize(
    $db,
    string $locationId,
    array $ownerContext,
    DateTimeImmutable $now,
    string $rid,
    float $sinceStart
): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    $finalizeStart = microtime(true);
    try {
        install_finalize_registered_location_fast($db, $locationId, $ownerContext, $now);
        
        // ── TRIGGER WELCOME WORKFLOW ─────────────────────────────────────────
        if (!empty($ownerContext['owner_email'])) {
            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                \NotificationService::notifyWelcome(
                    $db,
                    $locationId,
                    $ownerContext['owner_email'],
                    $ownerContext['owner_name'] ?? 'NOLA Owner',
                    $ownerContext['owner_phone'] ?? '',
                    'user'
                );
            } catch (\Throwable $ex) {
                error_log('[register_from_install] notifyWelcome trigger failed: ' . $ex->getMessage());
            }
        }
        // ─────────────────────────────────────────────────────────────────────

    } catch (Exception $finalizeError) {
        error_log('[register_from_install] finalize_registered_fast failed rid=' . $rid . ' location=' . $locationId . ': ' . $finalizeError->getMessage());
    }
    register_from_install_log_timing($rid, 'finalize_fast', $finalizeStart);
    register_from_install_log_timing($rid, 'total', $sinceStart);
}
```

---

## 4. Manual Registration Trigger

File: [api/auth/register.php](file:///C:/Users/User/nola-sms-pro-backend/api/auth/register.php)

Trigger the welcome alert immediately after successfully registering a new user manually (e.g. from the signup page):

```php
    $collection = ($role === 'agency') ? 'agency_users' : 'users';
    $db->collection($collection)->add($data);

    // ── TRIGGER WELCOME WORKFLOW ─────────────────────────────────────────────
    try {
        require_once __DIR__ . '/../services/NotificationService.php';
        \NotificationService::notifyWelcome(
            $db,
            $locationId ?: ($companyId ?: ''),
            $email,
            trim($firstName . ' ' . $lastName),
            $phone,
            $role
        );
    } catch (\Throwable $ex) {
        error_log('[register.php] notifyWelcome trigger failed: ' . $ex->getMessage());
    }
    // ─────────────────────────────────────────────────────────────────────────

    http_response_code(201);
    echo json_encode(['status' => 'success', 'message' => 'Account created.']);
```

---

## 5. Free System Notifications & Sender ID Override

File: [api/webhook/send_sms.php](file:///C:/Users/User/nola-sms-pro-backend/api/webhook/send_sms.php)

To ensure system notifications (e.g. Welcome SMS and Support Ticket confirmations) sent from GHL workflows in your **Central Agency GHL Location** are **completely free** (bypassing subaccount credit deduction) and **forced to use the `NOLASMSPro` Sender ID** (even if a subaccount has a custom approved Sender ID set), implement the following modifications:

### 5.1 Resolve System Notification Flag
At the top of the request body parsing (around line 180), extract the triggering location and determine if this is an authorized system notification.

```php
// 1. Identify Central Location and determine if this is an authorized system notification
$triggeringLocationId = $payload['location']['id'] ?? $payload['location_id'] ?? null;
$centralLocationId = getenv('NOLA_ALERT_GHL_LOCATION_ID') ?: '';

$isSystemNotification = false;
if (!empty($centralLocationId)) {
    // Check if the webhook runs directly within the central admin location
    if ($triggeringLocationId === $centralLocationId) {
        $isSystemNotification = true;
    }
    
    // Support an explicit bypass flag in customData (only trusted if triggered by or targeted at the central location)
    $reqSystemFlag = $customData['is_system_notification'] ?? $payload['is_system_notification'] ?? null;
    if (($reqSystemFlag === true || $reqSystemFlag === 'true' || $reqSystemFlag === 1 || $reqSystemFlag === '1') && 
        ($locId === $centralLocationId || $triggeringLocationId === $centralLocationId)) {
        $isSystemNotification = true;
    }
}
```

### 5.2 Override Sender ID for System Notifications
In the Sender ID resolution block (around line 325, **PATH B: Master billing gateway**), insert a check to bypass the subaccount's custom approved sender ID if `isSystemNotification` is true, ensuring it uses the system sender `NOLASMSPro`:

```php
    // ── PATH B: Master billing gateway ──────────────────────────────────────
    // If Admin has approved a custom sender for this subaccount, TRUST IT.
    error_log("[send_sms] Resolving Sender ID for Loc: {$locId} (requested: '{$requestedSender}')");
    
    if ($isSystemNotification) {
        // System notifications bypass subaccount custom sender IDs to use system sender
        $sender = !empty($requestedSender) ? $requestedSender : ($SENDER_IDS[0] ?? 'NOLASMSPro');
        error_log("[send_sms] Result: System notification override. Forcing sender to '{$sender}'.");
    } elseif (!empty($approvedSenderId)) {
        // Safe because it was approved by an Admin in the dashboard
        $sender = $approvedSenderId;
        error_log("[send_sms] Result: Using approved_sender_id '{$sender}' from Firestore.");
    } elseif (!empty($requestedSender) && in_array($requestedSender, $MASTER_APPROVED_SENDERS)) {
        // User requested a specifically approved system name
        $sender = $requestedSender;
        error_log("[send_sms] Result: Using requested whitelist sender '{$sender}'.");
    } else {
        // Fallback to system default
        ...
```

### 5.3 Billing Bypass Logic
In the charging/credit deduction block (around line 370), set a `$bypassBilling` boolean and wrap the free trial/paid deduction checks:

```php
$bypassBilling = $isSystemNotification;

// ── Credit Deduction & Trial ──────────────────────────────────────────────────
if ($bypassBilling) {
    error_log("[send_sms] BILLING BYPASS: System notification. Skipping credit deduction for loc={$locId}.");
} else {
    if ($usingFreeCredits) {
        // Free Trial (PATH B only) → increment counter, no paid credit deduction
        $intRef->set([
            'free_usage_count' => $freeUsageCount + $required_credits,
            'updated_at'       => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);

        try {
            $desc = "SMS (Trial) to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
            $creditManager->record_trial_usage(
                $account_id,
                $required_credits,
                $batch_id ?? ('trial_' . bin2hex(random_bytes(4))),
                $desc
            );
        } catch (\Exception $e) {
            error_log("Trial logging failed: " . $e->getMessage());
        }

    } else {
        // Paid deduction — applies to ALL sends (both PATH A and non-trial PATH B).
        ...
        // (Rest of normal paid deduction, low balance warning, etc.)
    }
}
```

### 5.4 Update Logging to Show 0 Credits Used
In the **SAVE FIRESTORE** block (around line 550), set the logged credit cost to `0` for bypassed sends so they are displayed accurately as free notifications in the user's dashboard history:

```php
    // Calculate credits per message for logging (0 if bypassed)
    $credits_per_message = $bypassBilling ? 0 : CreditManager::calculateRequiredCredits($message, 1);
```

### 5.5 Update Response Output
In the final JSON response output (around line 690), ensure `credits` returns `0` when billing is bypassed:

```php
// GHL Legacy/Success response structure
echo json_encode([
    "status" => $ghlStatus,
    "message" => $sender,
    "execution_log" => "Workflow SMS sent via $sender to $summary. Credits: " . ($bypassBilling ? 0 : $required_credits) . ".",
    "action_executed_from" => "Nola Web",
    "event_details" => [
        "Status" => "Success",
        "Recipient(s)" => implode(', ', $validNumbers),
        "SMS Message" => $message,
        "Credits Used" => ($bypassBilling ? 0 : $required_credits),
        "Sender ID" => $sender,
        "Location ID" => $locId,
        "Timestamp" => date('Y-m-d H:i:s')
    ],
    "output" => [
        "success" => ($total_status == 200),
        "summary" => $summary,
        "credits" => ($bypassBilling ? 0 : $required_credits),
        "location_id" => $locId,
        "message_ids" => $saved_message_ids ?? []
    ],
    "debug_info" => [
        "location_id" => $locId,
        "ghl_sync_status" => isset($msgSyncResp) ? $msgSyncResp : "skipped",
        "is_custom_provider" => $usingCustomSender,
        "is_free_trial" => $usingFreeCredits,
        "used_credits" => ($bypassBilling ? 0 : $required_credits)
    ]
]);
```

