# Backend Handoff - Backend Scan Follow-up for Templates and GHL Low Balance Emails

> Superseded for low-balance email delivery: use `backend_handoff_central_ghl_low_balance.md` for the current free central NOLA CRM workflow approach. Keep this file only for historical context and template/category notes.

Date: 2026-05-27

Backend scanned: `C:\Users\User\nola-sms-pro-backend`

Frontend behavior expected:

- Templates tab now shows built-in templates automatically, grouped by category.
- Notification settings UI now has one customer-facing toggle: Low Balance Email Alert.
- That one toggle saves both `lowBalanceAlert = true` and `ghlWorkflowSyncEnabled = true`.
- The email recipient is read-only and must come from the registered email in NOLA Account Details.
- No `ghlWebhookUrl` is needed. Do not use HighLevel Inbound Webhook, because that is a premium trigger.

## Executive Summary

The backend is partly implemented already. The scan shows:

- `api/templates.php` already accepts, stores, and returns `category`.
- `api/notification-settings.php` already stores preferences in `integrations/ghl_{locId}.notification_preferences`.
- `api/services/NotificationService.php` already reads the integration preferences and has a GHL contact bridge.
- `api/webhook/send_sms.php` calls `NotificationService::checkLowBalance()` after normal paid SMS credit deduction.
- `api/services/StatusSync.php` calls `NotificationService::notifyDeliveryStatus()` after terminal status sync.

The main remaining issues are:

1. GHL contact may not appear because `api/webhook/ghl_provider.php` deducts credits but does not call `NotificationService::checkLowBalance()`.
2. GHL custom fields may not update because `NotificationService.php` sends custom field keys as `id` values. HighLevel usually expects the actual custom field ID in `customFields[].id`, not the friendly key such as `nola_sms_balance`.
3. `notification-settings.php` saves correctly but POST/PUT returns only `{ success, message }`. It should return the full settings data after save.
4. `templates.php` PUT saves correctly but returns no updated template `data`. It should return the updated template row.
5. Delivery report email is not customer-facing in the frontend now. Keep backend support, but low-balance is the priority.

## Files Reviewed

- `api/templates.php`
- `api/notification-settings.php`
- `api/services/NotificationService.php`
- `api/services/GhlClient.php`
- `api/webhook/send_sms.php`
- `api/webhook/ghl_provider.php`
- `api/services/StatusSync.php`
- `api/auth_helpers.php`
- `api/ghl_contacts.php`

## 1. Templates API

File: `api/templates.php`

Current status: mostly done.

Already present:

- GET reads from `integrations/ghl_{locId}/templates`.
- GET returns `category`, defaulting to `General`.
- POST accepts `category`.
- POST validates allowed categories.
- PUT accepts `category`.
- PUT validates allowed categories.
- Cache invalidation exists.

Required backend cleanup:

### Return full data on PUT

Current PUT response:

```json
{
  "success": true,
  "message": "Template updated"
}
```

Please return the updated template row:

```json
{
  "success": true,
  "message": "Template updated",
  "data": {
    "id": "tpl_...",
    "name": "Updated title",
    "content": "Updated message",
    "category": "Marketing",
    "created_at": "2026-05-27T00:00:00Z",
    "updated_at": "2026-05-27T00:00:00Z"
  }
}
```

The frontend can tolerate wrapped or raw responses, but returning `data` prevents stale or blank UI rows after edit.

### Optional POST improvement

POST currently returns the main fields. Please also return `created_at` and `updated_at` if easy.

Allowed categories:

```text
Appointments
Marketing
Transactional
General
```

Firestore path:

```text
integrations/ghl_{locId}/templates/{templateId}
```

## 2. Notification Settings API

File: `api/notification-settings.php`

Current status: mostly done.

Already present:

- Reads and writes `integrations/ghl_{locId}.notification_preferences`.
- Supports `deliveryReports`, `lowBalanceAlert`, `lowBalanceThreshold`, `marketingEmails`, `ghlWorkflowSyncEnabled`.
- Preserves system fields like `ghl_alert_contact_id`.
- Resolves `alertEmail` server-side from profile/account sources.

Required backend cleanup:

### Return full data after save

POST/PUT currently returns:

```json
{
  "success": true,
  "message": "Notification settings updated"
}
```

Please return the same shape as GET after saving:

```json
{
  "success": true,
  "message": "Notification settings updated",
  "data": {
    "deliveryReports": false,
    "lowBalanceAlert": true,
    "lowBalanceThreshold": 50,
    "marketingEmails": false,
    "ghlWorkflowSyncEnabled": true,
    "alertEmail": "registered@example.com",
    "ghlAlertContactId": "abc123"
  }
}
```

Important:

- `alertEmail` must always be resolved server-side.
- Never trust `alertEmail` from the request body.
- Frontend hides delivery and marketing toggles, but the backend can keep these fields for future use.

Firestore map:

```json
{
  "notification_preferences": {
    "delivery_reports_enabled": false,
    "low_balance_alert_enabled": true,
    "low_balance_threshold": 50,
    "marketing_emails_enabled": false,
    "ghl_workflow_sync_enabled": true,
    "ghl_alert_contact_id": "abc123",
    "last_low_balance_notified_at": "timestamp"
  }
}
```

## 3. Low Balance Trigger Coverage

Files:

- `api/webhook/send_sms.php`
- `api/webhook/ghl_provider.php`
- `api/services/NotificationService.php`

### Normal app send path

Current status: implemented.

`api/webhook/send_sms.php` deducts paid credits, gets the new balance, and calls:

```php
NotificationService::checkLowBalance($db, $locId, $newBalance);
```

This means low-balance email sync can trigger only after a successful paid credit deduction.

### GHL provider path

Current status: missing.

`api/webhook/ghl_provider.php` also deducts paid credits through `CreditManager`, but it does not call `NotificationService::checkLowBalance()` after deduction.

Add the same low-balance check after the paid deduction block:

```php
try {
    require_once __DIR__ . '/../services/NotificationService.php';
    $newBalance = $creditManager->get_balance($account_id);
    NotificationService::checkLowBalance($db, $locationId, $newBalance);
} catch (\Throwable $e) {
    error_log('[LowBalanceAlert][ghl_provider] ' . $e->getMessage());
}
```

Use the actual location id variable in that file. In the scanned file, the GHL provider route uses `$locationId`.

Why this matters:

- If the user sends SMS from the normal NOLA frontend, the low-balance bridge can run.
- If the user sends through the GHL custom provider route, credits are deducted but the GHL contact/email workflow is never triggered.
- This is one likely reason the contact is not appearing in GHL.

## 4. GHL Contact Bridge Fix

File: `api/services/NotificationService.php`

Current status: partially implemented, but high risk.

Current bridge builds fields like:

```php
$ghlCustomFields[] = [
    'id' => $k,
    'value' => $v,
];
```

Where `$k` is a friendly key like:

```text
nola_sms_balance
nola_sms_alert_id
```

This may not work. HighLevel contact update payloads usually require the real custom field ID, not the friendly field key, in `customFields[].id`.

Required change:

### Add custom field key-to-ID resolution

Backend should resolve each NOLA field key to the actual GHL custom field ID before sending contact create/update requests.

Recommended fields:

```text
nola_sms_balance
nola_sms_low_balance_threshold
nola_sms_alert_type
nola_sms_alert_id
nola_sms_alerted_at
nola_sms_message_id
nola_sms_delivery_status
nola_sms_recipient
```

Implementation options:

1. Preferred: fetch location custom fields from HighLevel, cache the mapping by key/name, then use the real IDs.
2. Acceptable: store a map in Firestore under `integrations/ghl_{locId}.notification_preferences.ghl_custom_field_ids`.
3. Temporary: configure environment or admin settings with the custom field IDs.

Example saved map:

```json
{
  "notification_preferences": {
    "ghl_custom_field_ids": {
      "nola_sms_balance": "abcFieldId1",
      "nola_sms_low_balance_threshold": "abcFieldId2",
      "nola_sms_alert_type": "abcFieldId3",
      "nola_sms_alert_id": "abcFieldId4",
      "nola_sms_alerted_at": "abcFieldId5"
    }
  }
}
```

Then build:

```php
$ghlCustomFields[] = [
    'id' => $fieldId,
    'value' => $value,
];
```

Do not assume the friendly key can be used as the `id` unless tested successfully against HighLevel.

### Log the exact GHL response

When contact create/update fails, log:

- HTTP status
- response body
- location id
- email
- whether this was create or update

This is necessary to diagnose why the contact is not visible in GHL.

## 5. GHL Contact Upsert Behavior

File: `api/services/NotificationService.php`

Expected behavior:

1. Resolve registered account email from the same source as Account Details.
2. Search GHL contacts by that email.
3. If found, update the existing contact.
4. If not found, create a new contact.
5. Save the contact id in:

```text
integrations/ghl_{locId}.notification_preferences.ghl_alert_contact_id
```

The frontend Account Details email is the intended recipient. For example, if Account Details shows `raely@gmail.com`, the GHL contact should be created or updated with that email. The GHL workflow sends to that contact.

Also verify that `GhlClient` can initialize from background contexts. `NotificationService::syncGhlContactBridge()` calls:

```php
$jwtCtx = auth_get_optional_jwt_context($db);
$tokenRegistryId = auth_resolve_ghl_token_registry_id($db, $jwtCtx, $locationId);
```

When no JWT is present, this falls back to `$locationId`. That is okay only if `ghl_tokens/{locationId}` exists. If this app sometimes stores the usable OAuth token under an agency/company token registry id, add a fallback resolver so background calls can still find the correct token.

## 6. Low Balance Flow

File: `api/services/NotificationService.php`

Current flow is mostly correct:

1. Load preferences from `integrations/ghl_{locId}.notification_preferences`.
2. Stop if `low_balance_alert_enabled` is false.
3. Stop if current balance is above threshold.
4. Stop if `ghl_workflow_sync_enabled` is false.
5. Stop if `last_low_balance_notified_at` is within 24 hours.
6. Resolve registered email.
7. Sync GHL contact.
8. Save `last_low_balance_notified_at`.
9. Save `ghl_alert_contact_id`.

Backend should keep this behavior.

Suggested optional improvement:

- If balance rises above the threshold, clear or ignore `last_low_balance_notified_at` so the user can be alerted again immediately after topping up and later dropping below the threshold.

## 7. Delivery Failure Flow

Files:

- `api/services/StatusSync.php`
- `api/services/NotificationService.php`

Current status:

- `StatusSync.php` calls `NotificationService::notifyDeliveryStatus()` when status becomes `Sent` or `Failed`.
- `notifyDeliveryStatus()` returns immediately unless `delivery_reports_enabled` is true.
- Frontend currently saves `deliveryReports = false`, because the customer-facing toggle was removed.

Recommendation:

- Leave delivery failure support in backend.
- Do not expect delivery failure emails until the product team decides to expose that toggle again or turns it on by policy.
- If enabled later, reuse the same GHL contact bridge and create a workflow branch where `NOLA SMS Alert Type equals delivery_failure`.

Delivery fields:

```text
nola_sms_alert_type = delivery_failure
nola_sms_alert_id = delivery_failure_{locId}_{messageId}_{timestamp}
nola_sms_message_id
nola_sms_delivery_status
nola_sms_recipient
nola_sms_alerted_at
```

## 8. GHL Workflow Setup for Non-Premium Email Alert

This does not use Inbound Webhook.

### Step 1: Create GHL contact custom fields

In the target GHL location:

1. Go to Settings.
2. Go to Custom Fields.
3. Create Contact custom fields:

| Field Label | Suggested Key |
| --- | --- |
| NOLA SMS Balance | `nola_sms_balance` |
| NOLA SMS Low Balance Threshold | `nola_sms_low_balance_threshold` |
| NOLA SMS Alert Type | `nola_sms_alert_type` |
| NOLA SMS Alert ID | `nola_sms_alert_id` |
| NOLA SMS Alerted At | `nola_sms_alerted_at` |

For delivery failure later:

| Field Label | Suggested Key |
| --- | --- |
| NOLA SMS Message ID | `nola_sms_message_id` |
| NOLA SMS Delivery Status | `nola_sms_delivery_status` |
| NOLA SMS Recipient | `nola_sms_recipient` |

Backend must know the real GHL custom field IDs for these fields.

### Step 2: Create workflow

1. Go to Automation.
2. Open Workflows.
3. Create a new workflow from scratch.
4. Name it `NOLA SMS Pro - Low Balance Email`.

### Step 3: Add trigger

Use:

```text
Contact Changed
```

Configure it to fire when:

```text
NOLA SMS Alert ID has changed
```

### Step 4: Add If/Else

Condition:

```text
NOLA SMS Alert Type is low_balance
```

This prevents delivery-failure or future NOLA events from sending the low-balance email.

### Step 5: Add Send Email

Add a normal `Send Email` workflow action.

Recipient:

```text
Workflow Contact
```

Do not type a static email address. NOLA will keep the workflow contact synced to the registered Account Details email.

Example email:

```text
Subject: Your NOLA SMS Pro balance is low

Hi {{contact.first_name}},

Your SMS credit balance is now {{contact.custom_fields.nola_sms_balance}}.
Your alert threshold is {{contact.custom_fields.nola_sms_low_balance_threshold}}.

Please top up your account to keep messages sending smoothly.
```

Use the actual custom value tokens inserted by the GHL editor if they differ from the example.

### Step 6: Publish

Turn the workflow from Draft to Publish.

## 9. How to Test End-to-End

Important: Changing the threshold to 50 does not trigger the email by itself. The backend sends the alert after a paid credit deduction or a backend test call runs `NotificationService::checkLowBalance()`.

### Backend test path

Recommended temporary backend QA helper:

```php
NotificationService::checkLowBalance($db, $locationId, $testBalance);
```

Guard this behind admin auth or run it only in a local/backend test script. Suggested test input:

```text
locationId = target GHL location id
testBalance = 50
```

Preconditions:

- `low_balance_alert_enabled = true`
- `ghl_workflow_sync_enabled = true`
- `low_balance_threshold >= 50`
- `last_low_balance_notified_at` is empty or older than 24 hours
- Registered Account Details email exists
- GHL OAuth token is valid
- GHL custom field IDs are mapped correctly

Expected results:

1. Backend logs show `LowBalanceAlert Triggering GHL Contact Bridge`.
2. GHL contact exists with the registered Account Details email.
3. Contact custom fields are updated.
4. `ghl_alert_contact_id` is saved under `notification_preferences`.
5. `last_low_balance_notified_at` is saved.
6. GHL workflow enrollment appears.
7. Email is delivered to the registered Account Details email.

### Real send test path

1. Set NOLA balance to just above threshold, for example 51.
2. Set threshold to 50.
3. Enable Low Balance Email Alert in frontend Settings.
4. Send one paid SMS that deducts at least 1 credit.
5. Confirm new balance is 50 or lower.
6. Check GHL contact and workflow execution.

If balance is already below threshold and the 24-hour timestamp already exists, clear `last_low_balance_notified_at` for testing.

## 10. Why the Contact May Not Be Visible in GHL Right Now

Most likely causes from the scan:

1. The SMS was sent through `api/webhook/ghl_provider.php`, which currently deducts credits but does not call `checkLowBalance()`.
2. The alert already fired within the last 24 hours, so the circuit breaker blocked a repeat.
3. `ghl_workflow_sync_enabled` is false in Firestore.
4. Registered email could not be resolved from profile/account sources.
5. `GhlClient` could not load a usable OAuth token for that location.
6. GHL contact create/update failed because custom field keys were sent as IDs.
7. Required GHL custom fields do not exist in the target location.

## 11. Acceptance Checklist

Templates:

- GET returns category for every template.
- POST saves category.
- PUT saves category and returns updated `data`.
- DELETE still invalidates cache.

Notification settings:

- GET returns full camelCase frontend shape.
- POST/PUT saves to `integrations/ghl_{locId}.notification_preferences`.
- POST/PUT returns full camelCase data after save.
- `alertEmail` is derived from registered Account Details source only.

Low balance:

- `send_sms.php` triggers low-balance check after paid deduction.
- `ghl_provider.php` triggers low-balance check after paid deduction.
- Contact is created or updated in GHL using registered email.
- GHL custom fields use real GHL field IDs.
- `ghl_alert_contact_id` is saved.
- `last_low_balance_notified_at` is saved.
- Workflow sends email without Inbound Webhook.

## References

- HighLevel Contact Tag trigger: https://help.gohighlevel.com/support/solutions/articles/48001213546-workflow-trigger-contact-tag
- HighLevel Send Email workflow action: https://help.gohighlevel.com/support/solutions/articles/155000002472-action-send-email
- HighLevel trigger list: https://help.gohighlevel.com/support/solutions/articles/155000002292
- HighLevel Inbound Webhook premium trigger: https://help.gohighlevel.com/support/solutions/articles/48001237383
- HighLevel Update Contact API: https://marketplace.gohighlevel.com/docs/ghl/contacts/update-contact/
