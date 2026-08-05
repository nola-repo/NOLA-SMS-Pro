Backend Handoff - Free Central GHL Low Balance Email Alerts
Date: 2026-05-27

This handoff supersedes the per-customer-location GHL workflow approach for low-balance emails.

Goal
Send low-balance email notifications for every NOLA SMS Pro connected account through one central NOLA CRM workflow, without premium Inbound Webhooks and without a paid external email provider.

The backend should update a contact in one central GHL location. That central location owns the single workflow that sends the email.

Required Config
Add these environment variables:

NOLA_ALERT_GHL_LOCATION_ID=<central NOLA CRM location id>
NOLA_ALERT_GHL_TOKEN_REGISTRY_ID=<central token registry id, defaults to NOLA_ALERT_GHL_LOCATION_ID>
NOLA_ALERT_GHL_TAG=nola-low-balance-alert
NOLA_ALERT_GHL_LOCATION_ID is required. If it is missing, low-balance alert sync should log an error and return without blocking SMS sending.

Central GHL Custom Fields
Create these contact custom fields only in the central NOLA CRM location:

nola_sms_alert_type
nola_sms_alert_id
nola_sms_balance
nola_sms_low_balance_threshold
nola_sms_alerted_at
nola_sms_registered_email
nola_sms_source_location_id
nola_sms_source_location_name
Cache central custom field IDs in:

admin_config/nola_alerts.custom_field_ids
Do not cache central field IDs inside customer integrations/ghl_{locId} documents.

NotificationService Changes
File: api/services/NotificationService.php

Replace Customer-Location Email Sync With Central Sync
Low-balance emails must no longer depend on a workflow in the customer subaccount.

In checkLowBalance($db, string $locationId, int $currentBalance):

Load source-location preferences from integrations/ghl_{locationId}.notification_preferences.
Return if low_balance_alert_enabled is false.
If currentBalance > low_balance_threshold, clear last_low_balance_notified_at and return.
Return if last_low_balance_notified_at is less than 24 hours old.
Resolve the registered Account Details email using the existing account-resolution logic.
Build central alert fields.
Call a new syncCentralLowBalanceAlertContact(...).
Save metadata back to the source integration document.
Do not require ghl_workflow_sync_enabled for this central workflow path. The visible customer setting is low_balance_alert_enabled.

Add Central Contact Sync Helper
Add:

private static function syncCentralLowBalanceAlertContact(
    $db,
    string $sourceLocationId,
    string $email,
    string $name,
    int $currentBalance,
    int $threshold,
    string $sourceLocationName
): ?string
Behavior:

Read NOLA_ALERT_GHL_LOCATION_ID.
Read NOLA_ALERT_GHL_TOKEN_REGISTRY_ID, defaulting to central location id.
Create GhlClient($db, $centralLocationId, $centralTokenRegistryId).
Search central GHL contacts by registered email:
GET /contacts/?locationId={centralLocationId}&query={email}
If exact email match exists, update it.
If no exact match exists, create it.
Update central custom fields using real central GHL custom field IDs.
Optionally cycle tag NOLA_ALERT_GHL_TAG.
Return the central GHL contact id.
Central Alert Field Values
For a low-balance event, write these field values to the central contact:

{
  "nola_sms_alert_type": "low_balance",
  "nola_sms_alert_id": "low_balance_{sourceLocationId}_{timestamp}",
  "nola_sms_balance": 50,
  "nola_sms_low_balance_threshold": 50,
  "nola_sms_alerted_at": "2026-05-27T12:30:00+08:00",
  "nola_sms_registered_email": "owner@example.com",
  "nola_sms_source_location_id": "customer_ghl_location_id",
  "nola_sms_source_location_name": "Customer Workspace Name"
}
Use a fresh nola_sms_alert_id every time the alert should fire. The central workflow trigger depends on that field changing.

Resolve Central Custom Field IDs
If a custom field resolver already exists, update it so it can resolve fields for the central location and store the cache in admin_config/nola_alerts.

Expected resolver behavior:

Read admin_config/nola_alerts.custom_field_ids.
If all required keys exist, use the cached IDs.
Otherwise query:
GET /locations/{centralLocationId}/customFields
Match fields by key/name where possible.
Save the resolved map to admin_config/nola_alerts.custom_field_ids.
Build GHL payload with real IDs:
[
    'id' => $realGhlCustomFieldId,
    'value' => $value,
]
Never send friendly keys like nola_sms_alert_type as customFields[].id.

Save Source Integration Metadata
After central sync succeeds, save under the original customer integration document:

integrations/ghl_{sourceLocationId}.notification_preferences
Fields:

{
  "last_low_balance_notified_at": "timestamp",
  "last_low_balance_email_status": "sent",
  "central_ghl_alert_contact_id": "central-contact-id",
  "last_low_balance_email_sent_to": "registered@example.com"
}
On central sync failure, do not block SMS sending. Save/log:

{
  "last_low_balance_email_status": "failed"
}
Include enough log detail to diagnose:

source location id
central location id
registered email
GHL HTTP status
GHL response body summary
operation: search/create/update/custom-fields/tag
Notification Settings API
File: api/notification-settings.php

Keep existing frontend compatibility:

{
  "deliveryReports": false,
  "lowBalanceAlert": true,
  "lowBalanceThreshold": 50,
  "marketingEmails": false,
  "ghlWorkflowSyncEnabled": true,
  "alertEmail": "registered@example.com"
}
Backend behavior:

lowBalanceAlert remains the customer-facing enable/disable setting.
ghlWorkflowSyncEnabled may still be accepted/returned for old clients.
Central low-balance email sync must not depend on ghlWorkflowSyncEnabled.
alertEmail must remain server-derived and read-only.
Send Path Coverage
Ensure both paid deduction paths call checkLowBalance():

api/webhook/send_sms.php
api/webhook/ghl_provider.php
The alert should run after a successful paid deduction. It should not run when settings are merely saved.

Central GHL Workflow Setup
Create this workflow only in the central NOLA CRM location:

Create the central custom fields listed above.
Create workflow: NOLA SMS Pro - Low Balance Email.
Trigger: Contact Changed.
Configure trigger to run when NOLA SMS Alert ID changes.
Add If/Else:
NOLA SMS Alert Type is low_balance
Add Send Email.
Recipient: workflow contact.
Email:
Subject: Your NOLA SMS Pro balance is low

Hi {{contact.first_name}},

Your NOLA SMS Pro balance is now {{contact.custom_fields.nola_sms_balance}} credits.

Alert threshold:
{{contact.custom_fields.nola_sms_low_balance_threshold}} credits

Workspace:
{{contact.custom_fields.nola_sms_source_location_name}}

Please top up your account to keep messages sending smoothly.
Use the custom value tokens inserted by the GHL editor if they differ from the examples.

Optional fallback:

Cycle tag nola-low-balance-alert after custom fields are updated.
If Contact Changed does not trigger reliably, switch the workflow trigger to Contact Tag Added.
Test Plan
Backend:

Configure central env vars.
Verify central GHL OAuth token exists and can update contacts.
Set a customer threshold to 50.
Set customer balance above threshold, for example 51.
Send a paid SMS that drops balance to 50 or below.
Confirm backend updates a contact in the central NOLA CRM location.
Confirm central contact has all NOLA fields populated.
Confirm source integration stores central_ghl_alert_contact_id.
Confirm central workflow If/Else branch evaluates true.
Confirm email sends to the registered Account Details email.
Confirm duplicate alerts are suppressed for 24 hours.
Raise balance above threshold and confirm the circuit breaker clears.
Drop below threshold again and confirm a new alert sends.
Frontend:

Save notification settings.
Confirm Low Balance Email Alert stays enabled after reload.
Confirm recipient is read-only.
Confirm customer-facing UI does not say the customer needs a GHL workflow.
Acceptance Criteria
One workflow in central NOLA CRM sends alerts for every connected NOLA SMS Pro account.
No workflow is required inside customer subaccounts.
No premium Inbound Webhook trigger is used.
No external email provider is used.
Email always goes to the registered Account Details email.
SMS sending is never blocked if central GHL alert sync fails.