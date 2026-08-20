# 2-Way SMS Audit and Implementation Handoff

Date: 2026-08-19

Audience: backend team, frontend team, QA, and implementation lead.

## Executive Verdict

The current NOLA SMS Pro architecture can support 2-way SMS through UniSMS virtual numbers, but the existing implementation is not yet production-ready for all conversation paths.

The main correction is that 2-way SMS must be routed by the assigned receiving virtual number first, not by the sender phone's most recent local conversation. Once virtual numbers are delivered, each inbound message must resolve the owning `location_id` from the `to` number before any conversation lookup happens.

The second correction is outbound reply routing. Current UniSMS sending uses the normal sender-ID endpoint shape (`recipient`, `content`, `sender_id`). True virtual-number replies need a dedicated virtual-number send path using `from`, `to`, and `content`.

## Current Architecture Snapshot

Relevant backend files:

- `api/webhook/send_sms.php` - primary NOLA send path for app sends, GHL workflow/custom action sends, billing, idempotency, provider send, local message logging, and best-effort GHL sync.
- `api/webhook/ghl_provider.php` - GHL Conversation Provider outbound webhook path.
- `api/webhook/receive_sms_unisms.php` - UniSMS webhook receiver for inbound/status callbacks.
- `api/webhook/receive_sms.php` - legacy Semaphore inbound webhook.
- `api/services/SmsGatewayService.php` - provider routing and failover orchestration.
- `api/services/providers/UniSmsProvider.php` - current UniSMS sender-ID based provider.
- `api/services/MessageSyncService.php` - shared local message, sms log, inbound compatibility, and conversation writer.
- `api/services/GhlSyncService.php` - syncs outbound/inbound events into HighLevel Conversations.
- `api/services/GhlNativeMessageSyncService.php` - native/GHL message sync support.
- `api/templates.php` - NOLA-owned SMS templates.
- `api/notification-settings.php`, `api/notifications.php`, `api/services/NotificationService.php` - notification settings and alert delivery support.
- `api/account-sender.php`, `api/sender-requests.php`, `api/admin_sender_requests.php` - sender/provider configuration and approvals.

Relevant docs:

- `docs/BACKEND_2WAY_SMS_IMPLEMENTATION_PLAN.md`
- `docs/FRONTEND_HANDOFF_UNISMS_INTEGRATION.md`
- `docs/MARKETPLACE_INSTALLATION_AND_SCOPE_GUIDE.md`
- `docs/FRONTEND_USER_TEST_HANDOFF.md`
- `docs/USER_SIDE_TEST_CASES.csv`

External platform facts checked against HighLevel public documentation on 2026-08-19:

- HighLevel Conversation Providers can replace the default SMS provider or add a custom SMS conversation channel.
- Default SMS provider mode supports standard SMS modules, workflows, and bulk actions.
- Custom/additional SMS provider mode is available in Conversations, but standard SMS workflow modules are not the same path; premium workflow actions/custom actions are needed.
- Conversation Provider outbound Delivery URLs must verify signed provider webhook requests.
- Add Inbound Message can use `contactId` or `conversationId` and is the correct path for pushing provider replies into HighLevel Conversations.
- HighLevel outbound/inbound message webhooks can mirror native SMS activity, but mirroring is not the same as NOLA owning provider dispatch or billing.

Reference URLs:

- HighLevel Conversation Providers: https://marketplace.gohighlevel.com/docs/marketplace-modules/ConversationProviders/index.html
- HighLevel Provider Outbound Message webhook: https://marketplace.gohighlevel.com/docs/2021-07-28/webhook/ProviderOutboundMessage/index.html
- HighLevel generic Outbound Message webhook: https://marketplace.gohighlevel.com/docs/webhook/OutboundMessage/
- HighLevel generic Inbound Message webhook: https://marketplace.gohighlevel.com/docs/webhook/InboundMessage/index.html
- HighLevel Conversations messages APIs: https://marketplace.gohighlevel.com/docs/ghl/conversations/messages/index.html
- HighLevel Add Inbound Message API: https://marketplace.gohighlevel.com/docs/ghl/conversations/add-an-inbound-message/index.html
- HighLevel Custom Webhook workflow action: https://help.gohighlevel.com/support/solutions/articles/155000003305/

## Audit Findings

### A1. Inbound UniSMS routing is not yet virtual-number first

Current `receive_sms_unisms.php` resolves inbound location by querying `conversations.members array-contains senderNumber`, then taking the newest conversation. This avoids some cross-account bleeding, but it is not the correct primary model for virtual numbers.

Required behavior:

- Extract inbound `message.to`.
- Normalize to a stable E.164 key, for example `+639171234567`.
- Resolve `location_id` from a dedicated virtual-number registry.
- Resolve/create the local direct conversation under that location.
- Only use recent sender history as a fallback for legacy callbacks that do not include `to`.

Risk if not fixed:

- First inbound reply for a new contact can be dropped.
- Same customer phone replying to two different subaccounts can be attached to the wrong subaccount.
- Multiple virtual numbers per location or per campaign cannot be supported safely.

### A2. Outbound UniSMS does not yet send from a virtual number

Current `UniSmsProvider::sendSingle()` posts a sender-ID payload using `recipient`, `content`, and `sender_id`. The 2-way plan requires `POST /virtual_numbers/sms` with `from`, `to`, and `content`.

Required behavior:

- Keep existing sender-ID based UniSMS send for normal one-way sender-name traffic.
- Add a separate virtual-number send method for 2-way/direct reply traffic.
- Select a `from` virtual number from the location's assigned number registry.
- Record the virtual number and UniSMS text conversation identifiers on both `messages` and `sms_logs`.

Risk if not fixed:

- Contacts can reply to the virtual number, but NOLA replies may still go out as an alphanumeric sender and break the two-way thread.

### A3. Provider metadata is partially planned but not persisted end-to-end

`api/messages.php` already exposes `unisms_virtual_number_id` and `unisms_txt_conversation_id`, and the plan calls for those fields. `MessageSyncService` must persist them in the shared schema.

Required behavior:

- Persist `virtual_number`, `virtual_number_id`, `unisms_virtual_number_id`, `unisms_txt_conversation_id`, `provider_reference_id`, `provider_message_id`, `provider_status`, and `provider_response`.
- Return those fields from conversation thread APIs where useful.
- Keep old `inbound_messages` compatibility writes during migration.

### A4. GHL integration paths must be deliberately separated

There are four different SMS ingress/egress paths, and they should not be treated as one.

- NOLA web app composer: frontend calls NOLA backend, backend sends through provider.
- GHL workflow custom action: HighLevel workflow calls `/webhook/send_sms` or equivalent NOLA API.
- GHL Conversation Provider: HighLevel sends provider outbound webhook to `ghl_provider.php`, NOLA sends provider SMS, then updates message status.
- Native HighLevel/Twilio/LC Phone SMS: HighLevel native provider sends outside NOLA unless NOLA is configured as the default SMS provider or a workflow/webhook mirrors the event into NOLA.

Required behavior:

- Document which paths NOLA owns.
- Do not claim NOLA controls native Twilio/LC Phone SMS unless GHL provider configuration routes that SMS through NOLA.
- Avoid double-sending when both native SMS and NOLA workflow action exist in the same automation.

### A5. Notification coverage needs explicit 2-way events

Existing notification logic covers some app events, but 2-way SMS needs its own event matrix.

Required notification families:

- New inbound SMS received.
- Missed/unread inbound SMS after threshold.
- Provider delivery failure.
- Low balance before reply send.
- Virtual number assignment/activation failed.
- Sender/provider disabled or install blocked.
- Admin alert when webhook auth fails repeatedly.

Notification channels:

- In-app notification.
- Email notification.
- SMS notification, only if credits/provider state allow it.
- Optional HighLevel workflow alert via central notification location and configured tags/custom fields.

### A6. Templates must work in every send context without becoming provider logic

Templates should remain content preparation, not provider routing.

Required behavior:

- NOLA web app can insert templates into replies.
- GHL workflow custom actions can pass rendered message content to NOLA.
- GHL Conversation Provider replies usually arrive as raw message text from HighLevel; NOLA should not require a template ID there.
- Backend stores `template_id` and `template_name` when the frontend uses a NOLA template.
- Backend does not trust client-provided template metadata for billing, routing, or permissions.

## Target Capability Matrix

| Capability | NOLA Web App | GHL Workflow Custom Action | GHL Conversation Provider | Native GHL/Twilio/LC Phone | Required Owner |
| --- | --- | --- | --- | --- | --- |
| Send outbound SMS | Yes, through `/api/sms` or `/webhook/send_sms` | Yes, through `/webhook/send_sms` | Yes, through `ghl_provider.php` | Only if routed through NOLA/provider config | Backend |
| Receive inbound SMS | Yes, after provider webhook writes local thread | Yes, after provider webhook writes local thread | Yes, after provider webhook and GHL inbound sync | Only via GHL webhooks or NOLA as default provider | Backend |
| Reply from same virtual number | Required | Required when automation is part of 2-way thread | Required | Not controlled unless NOLA is provider | Backend |
| Show conversation thread | Required | Required | Required | Read-only/mirrored only if synced | Frontend |
| Message templates | Required | Rendered before send or passed as body | Optional/raw message from GHL | Native GHL templates outside NOLA unless mirrored | Frontend + Backend |
| Billing and credits | Required | Required | Required | Only for NOLA-routed sends | Backend |
| Status polling/callback | Required | Required | Required | Only if NOLA sends or receives provider status | Backend |
| Notifications | Required | Required | Required | Mirrored only where event data reaches NOLA | Backend + Frontend |
| Idempotency | Required | Required | Required | Required only on NOLA-owned sends | Backend + Frontend |

## Road to Implementation - Backend Team

### Phase 0: Confirm provider contracts before coding

Deliverables:

- Confirm the exact UniSMS virtual-number inbound webhook payload, status callback payload, auth header, and outbound endpoint.
- Confirm each assigned virtual number and its provider reference ID once numbers are issued.
- Confirm whether NOLA will be configured in HighLevel as:
  - default SMS provider replacement;
  - additional custom SMS conversation provider;
  - workflow custom action only;
  - or mixed per-location.

Acceptance criteria:

- One written provider contract sample exists for inbound, outbound virtual-number send, and delivery status callback.
- The chosen HighLevel provider mode is documented per environment and per test location.

### Phase 1: Add virtual-number registry

Add a registry collection, for example:

```text
virtual_numbers/{e164_number}
  number_e164: "+639171234567"
  number_local: "09171234567"
  provider: "unisms"
  provider_number_id: "vn_xxx"
  status: "active"
  location_id: "GHL_LOCATION_ID"
  agency_id: "GHL_COMPANY_ID"
  mode: "two_way"
  default_for_location: true
  assigned_at: Timestamp
  updated_at: Timestamp
```

Optional subcollection:

```text
integrations/ghl_{locationId}/virtual_numbers/{e164_number}
```

Implementation notes:

- Use E.164 number as the canonical document ID.
- Store `location_id` redundantly for fast lookups.
- Add a uniqueness guard so one number cannot be assigned to two active locations.
- Add admin tooling or script to import numbers once the boss provides them.

Acceptance criteria:

- Backend can resolve `+639...`, `639...`, and `09...` to the same virtual-number record.
- Unassigned number inbound webhook is acknowledged with `200 ignored` and logged without creating a message.

### Phase 2: Create virtual-number outbound send path

Extend `UniSmsProvider` without breaking existing sender-ID sends:

- Keep `sendSingle()` for one-way sender-ID SMS.
- Add `sendVirtualNumberSms($fromVirtualNumber, $toNumber, $message, ?$apiKey)`.
- Add `sendVirtualNumberBulk()` only if provider supports it; otherwise send one at a time like the current implementation.
- Return the same normalized result shape used by `ProviderResultService`.

Add routing in `SmsGatewayService`:

- If send request has `conversation_mode: "two_way"` or `reply_from_virtual_number: true`, use the virtual-number method.
- Resolve the `from` virtual number from the location registry.
- Reject virtual-number sends if no active number exists for that location.
- Preserve idempotency and credit deduction before dispatch.

Acceptance criteria:

- Outbound reply from NOLA uses the assigned virtual number as `from`.
- Existing one-way sender-ID sends still work.
- Provider failure creates local failed rows and refunds/rolls back per current policy.

### Phase 3: Make inbound UniSMS routing virtual-number first

Update `receive_sms_unisms.php`:

- Accept `webhook-secret-key` and existing secret paths if provider supports both.
- Extract `message.from`, `message.to`, `message.content`, `message.virtual_number_id`, `message.txt_conversation_id`, and provider reference IDs.
- Resolve `location_id` from `message.to`.
- Build local conversation ID as `{location_id}_conv_{sender_local_number}`.
- Write through `MessageSyncService::recordMessageEvent()`.
- Acknowledge UniSMS fast, then run best-effort GHL sync after response flush where possible.

Fallback policy:

- If `message.to` is absent, allow recent-conversation fallback only when exactly one unambiguous conversation exists.
- Never use sender-only routing when more than one location has the same sender in conversation history.

Acceptance criteria:

- First inbound message from a new customer is saved.
- Same customer can message two different assigned virtual numbers and land in two different locations.
- Unknown virtual number is ignored safely.
- Duplicate provider webhook does not create duplicate messages.

### Phase 4: Extend shared message schema

Update `MessageSyncService` to persist provider-specific fields:

```text
virtual_number
virtual_number_id
unisms_virtual_number_id
unisms_txt_conversation_id
provider_conversation_id
reply_channel
conversation_provider_id
template_id
template_name
notification_event_id
```

Also update:

- `api/messages.php`
- `api/conversations.php`
- `api/webhook/fetch_logs.php`
- admin activity/log endpoints if they display SMS records
- Firestore indexes if query errors request composites

Acceptance criteria:

- Thread API returns inbound and outbound rows in correct timestamp order.
- Inbound rows include `direction: "inbound"` and `status: "Received"`.
- Outbound virtual-number replies include the actual `from` virtual number.

### Phase 5: GHL Conversation Provider alignment

For `api/webhook/ghl_provider.php`:

- Verify HighLevel provider outbound signature before processing.
- Treat `messageId` from HighLevel as an external idempotency key.
- Send via virtual number when the location has active 2-way mode.
- Update HighLevel message status using the marketplace app/location token after provider result.
- Store `ghl_message_id`, `ghl_conversation_id`, `ghl_contact_id`, and `conversationProviderId` if present.

For inbound to GHL:

- Prefer Add Inbound Message with `contactId` when known.
- Fall back to `conversationId` when contact is unavailable but conversation is known.
- Keep the local message even when GHL sync fails, and record retry metadata.

Acceptance criteria:

- Sending from HighLevel Conversations tab through NOLA produces one provider SMS and one local message row.
- Reply from customer appears in NOLA and HighLevel under the expected contact thread.
- Status updates do not require a second marketplace app token that lacks permission.

### Phase 6: Workflow actions, automations, and trigger safety

Owned NOLA automation paths:

- HighLevel Custom Webhook action calling `/webhook/send_sms`.
- Marketplace premium workflow action if implemented later.
- NOLA internal scheduled sends or system alerts.

Backend requirements:

- Require `location_id` or `X-GHL-Location-ID`.
- Require `X-Webhook-Secret` or signed provider auth depending on source.
- Require idempotency key for workflow sends; derive one if absent.
- Add `source_context` fields: `nola_app`, `ghl_workflow_action`, `ghl_conversation_provider`, `system_notification`.
- Add guardrails to prevent double-send when the same workflow has native SMS plus NOLA SMS action.

Suggested double-send guard:

```text
automation_send_guard/{location_id}_{contact_id}_{workflow_id}_{step_id}_{message_hash}
  status: "processing|completed|failed"
  created_at
  expires_at
  provider_message_id
```

Acceptance criteria:

- Retried HighLevel workflow webhook does not double-bill or double-send.
- Native SMS and NOLA action in the same workflow are documented as mutually exclusive for the same message.
- Workflow send response returns clear user-visible error codes.

### Phase 7: Native SMS and Twilio/LC Phone boundary

NOLA should handle native/Twilio-style SMS only when one of these is true:

- NOLA is configured as the default SMS Conversation Provider for the location.
- HighLevel sends outbound provider webhook events to NOLA.
- A HighLevel workflow custom webhook mirrors the native event to NOLA for logging only.
- A separate HighLevel inbound message webhook is configured and NOLA has permission to read/sync it.

Backend requirements:

- Add a `native_sms_mirror` mode only for read/logging if NOLA did not send the message.
- Do not deduct NOLA credits for native provider sends that NOLA did not dispatch.
- Mark mirrored records with `billing_scope: "external_native_provider"`.
- Do not update native Twilio/LC Phone statuses unless HighLevel exposes the message and NOLA has the correct app token.

Acceptance criteria:

- Product documentation clearly says which native SMS flows are owned, mirrored, or out of scope.
- Mirrored native messages cannot trigger duplicate provider sends.

### Phase 8: Templates

Backend requirements:

- Keep `api/templates.php` location-scoped and permission checked.
- Let frontend render template content before calling send.
- Accept optional `template_id` and `template_name` for audit only.
- Store template metadata on message records when present.
- Never use template ID alone to infer message body, billing, provider, or recipient.

Acceptance criteria:

- Template-created NOLA sends show template metadata in logs.
- GHL Conversation Provider sends without template metadata still send normally.
- Deleted/renamed templates do not break old message history.

### Phase 9: Notifications

Backend notification events to add or verify:

- `inbound_sms_received`
- `inbound_sms_unread_threshold`
- `outbound_sms_failed`
- `delivery_status_failed`
- `low_balance_before_reply`
- `virtual_number_assigned`
- `virtual_number_inactive`
- `provider_webhook_auth_failed`
- `ghl_sync_failed`
- `sender_id_approved`
- `sender_id_rejected`

Notification destinations:

- `notifications` collection for in-app alerts.
- Email via configured transactional email provider or current mail fallback.
- SMS notification through provider only when the location has credits and sending is not blocked.
- Optional central HighLevel notification workflow by updating contact custom fields and cycling configured tags.

Backend safety:

- Notification failure must be non-fatal to SMS processing.
- Add idempotency per event and recipient.
- Do not send SMS notification about SMS failure through the same failing provider unless failover is verified.
- Add admin logs for repeated webhook auth failures.

Acceptance criteria:

- New inbound SMS can notify assigned users without duplicating alerts.
- Failed SMS can create in-app and email alerts even if SMS alert is skipped.
- Notification settings are respected per location.

### Phase 10: Observability, indexes, and jobs

Add or verify:

- Structured logs for provider inbound, provider outbound, GHL sync, billing, refund, idempotency, and notification events.
- Dead-letter or retry collection for failed GHL sync after inbound.
- Status update flow for UniSMS virtual-number messages.
- Firestore indexes for:
  - `messages`: `location_id`, `conversation_id`, `date_created`
  - `messages`: `location_id`, `direction`, `date_created`
  - `conversations`: `location_id`, `last_message_at`
  - `virtual_numbers`: `location_id`, `status`
  - notification queries by `location_id`, `read`, `created_at`

Acceptance criteria:

- A failed provider callback can be diagnosed from logs without exposing phone numbers in plaintext logs.
- Cloud Scheduler or worker paths do not regress existing status polling.

## Road to Implementation - Frontend Team

### Phase F1: Account and settings UI

Add UI support for:

- Assigned virtual number display.
- Virtual number status: `pending`, `active`, `inactive`, `failed`.
- 2-way SMS availability per location.
- Provider mode label:
  - NOLA app only;
  - GHL workflow action;
  - GHL Conversation Provider;
  - default SMS provider replacement;
  - native/mirrored only.

Do not expose:

- UniSMS API keys.
- Webhook secrets.
- Provider signing secrets.

Acceptance criteria:

- User can see whether replies are available before sending.
- User sees a clear blocked state if no virtual number is assigned.

### Phase F2: Compose and conversation reply behavior

Frontend requirements:

- Keep using backend send endpoint.
- Add `Idempotency-Key` for all sends.
- When replying inside a direct conversation, pass:
  - `conversation_id`
  - `contact_id` when known
  - `reply_from_virtual_number: true` or backend-agreed equivalent
  - optional `template_id` and `template_name`
- Do not let frontend choose arbitrary `from` virtual numbers unless backend has returned the allowed list.
- Disable reply button when account is paused, install blocked, credits are missing, or virtual number is inactive.

Acceptance criteria:

- Reply in NOLA thread stays in the same conversation.
- Inbound and outbound rows render together in timestamp order.
- Failed replies remain visible as failed local messages.

### Phase F3: Conversations list and thread rendering

Display fields:

- Direction: inbound/outbound.
- Status: `Received`, `Sending`, `Sent`, `Failed`.
- From number for virtual-number outbound sends.
- To/contact number.
- Provider name.
- Sync status if GHL sync failed.

Refresh behavior:

- Poll or refetch conversations after send.
- Poll or subscribe for inbound refresh if no realtime channel exists.
- Use `fresh=1` after send or inbound notification to bypass stale cache.

Acceptance criteria:

- User can see a customer reply without switching pages.
- Conversation list last message updates on inbound.

### Phase F4: GHL embedded app behavior

Frontend requirements:

- Continue sending `X-GHL-Location-ID`.
- Respect agency-selected subaccount context.
- Do not request conversations/notifications until location context is confirmed.
- Surface GHL sync warnings separately from provider send failures.

Acceptance criteria:

- Embedded app does not show cross-location conversation data.
- GHL sync failure does not make the user think SMS delivery failed when provider delivery succeeded.

### Phase F5: Templates

Frontend requirements:

- Keep templates as content insertion.
- Support templates in:
  - new compose;
  - conversation reply;
  - bulk send where applicable.
- Pass optional template metadata for audit.
- Validate final rendered message body, not just template body.

Acceptance criteria:

- Template insertion works in 2-way replies.
- Template metadata appears in backend logs/history where available.

### Phase F6: Notifications UI

Frontend requirements:

- Add/verify notification settings for:
  - inbound SMS received;
  - unread inbound SMS reminder;
  - failed delivery;
  - low balance;
  - virtual number issues.
- Show notification channel choices only if backend supports them.
- Show in-app notification list with event type, conversation link, status, and read/unread state.

Acceptance criteria:

- Clicking inbound SMS notification opens the correct conversation.
- Notification settings persist after refresh.

### Phase F7: Automation and workflow setup guidance

Frontend/admin UI should make setup state visible:

- GHL Conversation Provider installed and enabled.
- Default SMS provider mode or custom provider mode.
- Workflow custom action URL and auth header status.
- Warning when native SMS and NOLA SMS action are both configured for the same automation goal.

Acceptance criteria:

- Admin can tell whether 2-way SMS is enabled through NOLA or only through native HighLevel.
- User-facing screens avoid implying that NOLA controls native Twilio sends unless configured.

## QA Test Matrix

### Provider and number routing

- Assigned virtual number receives first inbound from a new customer.
- Same customer sends to two different NOLA virtual numbers assigned to two different locations.
- Unknown virtual number inbound is ignored and logged.
- Inbound payload missing `to` uses fallback only when unambiguous.
- Duplicate inbound webhook creates one message.

### NOLA app sends

- Single direct outbound uses virtual number when replying in a 2-way thread.
- Normal one-way sender-ID send still works.
- Bulk send does not accidentally use direct-reply virtual-number mode unless designed.
- Template reply sends rendered body and stores template metadata.
- Insufficient credits blocks send and shows clear error.

### GHL workflow actions

- Workflow custom webhook sends one SMS.
- Retried workflow webhook does not double-send.
- Missing location ID returns a clear error.
- Native SMS plus NOLA SMS duplicate risk is documented and tested.

### GHL Conversation Provider

- Outbound from Conversations tab reaches `ghl_provider.php`.
- Signature verification blocks spoofed payload.
- HighLevel `messageId` is stored and used for idempotency/status updates.
- Customer reply is pushed back into HighLevel as inbound message.
- Status update succeeds with the correct marketplace app/location token.

### Native/Twilio/LC Phone boundaries

- Native SMS not routed through NOLA does not deduct NOLA credits.
- Mirrored native message is marked external/native if logging is configured.
- NOLA does not send a duplicate provider SMS for mirrored native events.

### Notifications

- Inbound received creates one in-app notification.
- Inbound unread threshold sends one reminder.
- Failed delivery creates in-app and email notification.
- SMS notification is skipped if provider is unavailable or balance is insufficient.
- Notification settings are respected.

### Security and privacy

- Provider webhook auth failure returns proper status and logs safely.
- Phone numbers in logs are hashed or minimized where possible.
- Cross-location JWT/session mismatch cannot read conversations.
- Provider keys are never returned to frontend.

## Backend Handoff Summary

Backend team owns:

- Virtual-number registry and import tooling.
- Virtual-number outbound provider method.
- Virtual-number-first inbound resolver.
- Message schema extension.
- GHL Conversation Provider security/status sync.
- Workflow action idempotency and duplicate guards.
- Notification event generation.
- Observability, retries, and indexes.

Backend should not change:

- Existing frontend send response shape unless coordinated.
- Existing sender-ID based UniSMS/Semaphore sends.
- Existing auth/location isolation behavior.

## Frontend Handoff Summary

Frontend team owns:

- Settings display for virtual-number assignment and 2-way readiness.
- Reply UI behavior for direct conversation threads.
- Template insertion in replies.
- Message rendering for inbound/outbound mixed threads.
- Notification preferences and notification deep links.
- Admin/setup clarity for GHL provider mode and workflow action mode.

Frontend should not do:

- Store provider credentials.
- Choose arbitrary virtual `from` numbers.
- Promise native Twilio/LC Phone support unless backend reports NOLA-owned provider mode.

## Implementation Order Recommendation

1. Backend virtual-number registry.
2. Backend inbound virtual-number-first routing.
3. Backend virtual-number outbound send path.
4. Message schema and API response extension.
5. Frontend settings and thread rendering.
6. NOLA app reply flow.
7. GHL Conversation Provider flow.
8. Workflow/custom action duplicate guard.
9. Notifications.
10. QA matrix and staging rollout.

## Release Gate

Do not mark 2-way SMS ready until these pass in staging:

- One assigned virtual number can receive inbound and reply outbound in the same NOLA thread.
- Same reply appears in HighLevel Conversation when GHL sync is enabled.
- GHL Conversation Provider outbound path sends through the assigned virtual number.
- Workflow custom action sends do not duplicate native SMS.
- Inbound and failed-delivery notifications work through in-app and email channels.
- Unknown or unassigned virtual numbers cannot create cross-location messages.

