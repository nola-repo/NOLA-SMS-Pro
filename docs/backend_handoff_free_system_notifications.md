# Backend Handoff — Free System Notifications & Sender ID Override

This handoff details the modifications required in the PHP backend (`api/webhook/send_sms.php`) to support **free system notifications** sent from GoHighLevel (GHL) workflows.

These modifications cover the following central admin workflows:
1. **NOLA SMS Pro - Welcome & Sender ID Onboarding Notification** (Sent to new signups/installs)
2. **NOLA SMS Pro - Low Balance Notification** (Sent to subaccounts whose credits fall below their threshold)
3. **NOLA SMS Pro - Top-up Success Notification** (Sent when a subaccount successfully purchases or receives credits)
4. **NOLA SMS Pro - Support Ticket Submission Notification** (Confirmations and alerts when a ticket is submitted)

---

## 1. Core Objectives
*   **Zero-Cost SMS**: Bypasses trial credit checks and paid subaccount wallet deductions.
*   **Sender ID Override**: Guarantees the message is sent using the system's `NOLASMSPro` Sender ID, overriding the subaccount's custom `approved_sender_id` (e.g., `NOLA CRM`).
*   **Security & Guardrails**: Restricts billing bypass exclusively to workflows executed inside the Central Agency GHL Location (`NOLA_ALERT_GHL_LOCATION_ID`), preventing unauthorized subaccounts from exploiting the bypass.
*   **Accurate Dashboard Reporting**: Logs the message to Firestore with `credits_used = 0` so it appears as a free system transaction in the user's dashboard.

---

## 2. GoHighLevel (GHL) Webhook Configuration
For all central workflows sending system notifications, the **Custom Webhook** action must pass the following payload structure:

```json
{
  "customData": {
    "number": "{{contact.phone}}",
    "message": "Hi {{contact.first_name}}! Welcome to NOLA SMS Pro...",
    "location_id": "{{contact.nola_sms_source_location_id}}",
    "contactId": "{{contact.id}}",
    "sendername": "NOLASMSPro",
    "is_system_notification": "true"
  }
}
```

*Note: In the payload above, `location_id` in customData points to the subaccount's location ID so that the SMS is logged under their conversation history, but the webhook is triggered from the central location.*

---

## 3. Backend Code Modifications

File: [api/webhook/send_sms.php](file:///C:/Users/User/nola-sms-pro-backend/api/webhook/send_sms.php)

### 3.1 Define & Validate System Notification Status
Locate the request body extraction block (around line 180). Add the logic to resolve `$isSystemNotification` by comparing the caller's location ID to the central GHL location environment variable:

```php
// ─────────────────────────────────────────────────────────────────────────────
// 1. Identify Central Location and determine if this is an authorized system notification
// ─────────────────────────────────────────────────────────────────────────────
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

### 3.2 Override Subaccount Sender ID
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

### 3.3 Bypass Credit Deduction & Free Trial Limits
In the charging/credit deduction block (around line 370), set a `$bypassBilling` boolean and wrap the free trial/paid deduction checks to allow a complete bypass:

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

### 3.4 Log Bypassed Transactions with 0 Credits Used
In the **SAVE FIRESTORE** block (around line 550), set the logged credit cost to `0` for bypassed sends so they are displayed accurately as free notifications in the user's dashboard history:

```php
    // Calculate credits per message for logging (0 if bypassed)
    $credits_per_message = $bypassBilling ? 0 : CreditManager::calculateRequiredCredits($message, 1);
```

### 3.5 Return 0 Credits in API Response
Update the final JSON response output (around line 690) to return `0` credits for system notifications:

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

---

## 4. Verification & Testing

### Test Case A: Validate Low Balance Webhook
1.  Temporarily lower a subaccount's balance below their low balance threshold in Firestore.
2.  Wait for `checkLowBalance` to trigger the central GHL workflow contact tag.
3.  Observe that GHL triggers the webhook with payload `"is_system_notification": "true"`.
4.  Verify in `api/webhook/send_sms.php` logs:
    *   `BILLING BYPASS: System notification. Skipping credit deduction.`
    *   `Result: System notification override. Forcing sender to 'NOLASMSPro'.`
5.  Check the subaccount's message log in the Firestore console or dashboard UI to verify `credits_used` is logged as `0`.
