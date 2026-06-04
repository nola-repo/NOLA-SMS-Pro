<USER_REQUEST>
ok i will proceed in the next implementation based on the handoff please analyze carefully i dont want to touch that custom provider again because its all working now and for the next we will implement this:


# Backend Handoff — Free System Notifications & Sender ID Override

This handoff details the modifications required in the PHP backend (api/webhook/send_sms.php) to support *free system notifications* sent from GoHighLevel (GHL) workflows.

These modifications cover the following central admin workflows:
1. *NOLA SMS Pro - Welcome & Sender ID Onboarding Notification* (Sent to new signups/installs)
2. *NOLA SMS Pro - Low Balance Notification* (Sent to subaccounts whose credits fall below their threshold)
3. *NOLA SMS Pro - Top-up Success Notification* (Sent when a subaccount successfully purchases or receives credits)
4. *NOLA SMS Pro - Support Ticket Submission Notification* (Confirmations and alerts when a ticket is submitted)

---

## 1. Core Objectives
*   *Zero-Cost SMS*: Bypasses trial credit checks and paid subaccount wallet deductions.
*   *Sender ID Override*: Guarantees the message is sent using the system's NOLASMSPro Sender ID, overriding the subaccount's custom approved_sender_id (e.g., NOLA CRM).
*   *Security & Guardrails*: Restricts billing bypass exclusively to workflows executed inside the Central Agency GHL Location (NOLA_ALERT_GHL_LOCATION_ID), preventing unauthorized subaccounts from exploiting the bypass.
*   *Accurate Dashboard Reporting*: Logs the message to Firestore with credits_used = 0 so it appears as a free system transaction in the user's dashboard.

---

## 2. GoHighLevel (GHL) Webhook Configuration
For all central workflows sending system notifications, the *Custom Webhook* action must pass the following payload structure:

{
  "customData": {
    "number": "{{contact.phone}}",
    "message": "Hi {{contact.first_name}}! Welcome to NOLA SMS Pro...",
    "location_id": "{{contact.nola_sms_source_location_id}}",
    "contactId": "{{co
<truncated 5811 bytes>
 (around line 690) to return 0 credits for system notifications:

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

---

## 4. Verification & Testing

### Test Case A: Validate Low Balance Webhook
1.  Temporarily lower a subaccount's balance below their low balance threshold in Firestore.
2.  Wait for checkLowBalance to trigger the central GHL workflow contact tag.
3.  Observe that GHL triggers the webhook with payload "is_system_notification": "true".
4.  Verify in api/webhook/send_sms.php logs:
    *   BILLING BYPASS: System notification. Skipping credit deduction.
    *   Result: System notification override. Forcing sender to 'NOLASMSPro'.
5.  Check the subaccount's message log in the Firestore console or dashboard UI to verify credits_used is logged as 0.
</USER_REQUEST>
<ADDITIONAL_METADATA>
The current local time is: 2026-06-02T19:49:27+08:00.
</ADDITIONAL_METADATA>