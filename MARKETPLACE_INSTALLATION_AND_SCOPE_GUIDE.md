# NOLA SMS Pro Marketplace Installation and Scope Guide

**Status:** analysis and documentation only
**App/version:** `6999da2b8f278296d95f7274`
**Reviewed:** 2026-07-07

## 0. Live marketplace audit summary

The authenticated **Advanced > Auth** page was inspected on 2026-07-07. The
app is a **Sub-account target-user app**, the portal reports **12 scopes
selected**, and eight Agency-only scopes are unavailable for this target-user
type.

The 12 scopes currently selected are:

- `contacts.readonly`
- `contacts.write`
- `conversations.readonly`
- `conversations.write`
- `conversations/message.readonly`
- `conversations/message.write`
- `locations.readonly`
- `locations/customFields.readonly`
- `locations/customValues.readonly`
- `locations/customValues.write`
- `oauth.readonly`
- `oauth.write`

This is close to the desired least-privilege set. The two custom-value scopes
do not have a corresponding runtime API call in the current code and should be
removed unless a specific custom-value feature is planned. The other ten
selected scopes map to current messaging, contact, location, notification, and
agency-to-location installation behavior.

The portal currently contains two redirect URLs:

- `https://smspro-api.nolacrm.io/oauth/callback`
- `https://nolasmspro.com/oauth-callback`

Keep both only if both are active, tested callback entry points. Otherwise,
retire the legacy URL after confirming that no listing, install link, or live
version still uses it. One canonical callback reduces split installation state
and makes OAuth failures easier to diagnose.

## 1. Recommended marketplace model

NOLA SMS Pro is used inside a HighLevel sub-account, so the marketplace **target user should remain Sub-account**.

For the current product and onboarding message, the safest configuration is:

- **Target user:** Sub-account
- **Who can install:** Agency only
- **Bulk installation:** Enabled
- **User instruction:** Start installation from Agency view, choose only the intended sub-account(s), authorize, then finish NOLA registration.

This matches NOLA's current company-token to location-token flow. It also prevents a sub-account user from expecting to see or select other locations they are not authorized to manage.

HighLevel's updated distribution model intentionally gives a sub-account installer a `Location` token for the current sub-account. Seeing only that one sub-account in Sub-account view is therefore expected behavior, not a missing-location bug. An agency installer receives a `Company` token and NOLA must exchange it for a location token for every location on which the app is actually installed.

If self-service installation by individual sub-account admins is important later, change **Who can install** to **Both Agency and Sub-account** only after both token paths pass the acceptance tests in this guide. The live portal does not permit Agency-only scopes for this Sub-account target-user app, so agency/company metadata must not be a hard dependency of successful installation.

Official references:

- [Marketplace App Distribution Model](https://marketplace.gohighlevel.com/docs/oauth/AppDistribution/index.html)
- [Handling Access Tokens for Target User: Sub-account](https://marketplace.gohighlevel.com/docs/Authorization/TargetUserSubAccount/index.html)

## 2. Important installation defect to correct

The current agency provisioning code calls:

`GET /locations/search?companyId=...`

and then provisions every returned location. That endpoint lists agency locations; it does not prove that NOLA was selected/installed for each location.

For designated sub-account access, provisioning must instead use:

`GET /oauth/installedLocations`

with `oauth.readonly`, then exchange only those installed location IDs through `POST /oauth/locationToken` with `oauth.write`. Continue listening for `INSTALL` events so future location installations can be provisioned individually. This is a release blocker for a trustworthy agency/bulk install: an agency selection must never silently authorize all agency locations in NOLA.

Relevant current code:

- `api/agency/install_provision.php` — currently fetches all company locations.
- `api/webhook/ghl_marketplace_events.php` and `api/install_helpers.php` — accept `INSTALL` and `UNINSTALL` lifecycle events.
- `ghl_callback.php` and `ghl_agency_callback.php` — exchange authorization codes and resolve company/location tokens.

There is also an OAuth-consent mismatch in the hard-coded reinstall links in
`ghl_callback.php` and `oauth_debug.php`. Those links still request
`workflows.readonly` and location tag scopes, which are not selected in the
live portal and are not required by the current runtime. They also omit
`oauth.readonly` and `oauth.write`, which the supported agency installation
flow needs. Generate reinstall links from one canonical scope constant or from
configuration so the live marketplace version and recovery paths cannot drift.

Official references:

- [Get locations where the app is installed](https://marketplace.gohighlevel.com/docs/ghl/oauth/get-installed-location/)
- [AppInstall webhook](https://marketplace.gohighlevel.com/docs/webhook/AppInstall/index.html)
- [AppUninstall webhook](https://marketplace.gohighlevel.com/docs/webhook/AppUninstall/)

## 3. Scope checklist

The checked state below was verified directly in **Advanced > Auth** on
2026-07-07 and compared with the code-derived target state.

| Scope | Live | Target | Why NOLA needs it |
|---|---:|---:|---|
| `contacts.readonly` | On | Keep | Search/list contacts and load a contact before conversation and notification operations. |
| `contacts.write` | On | Keep | Create/update/delete contacts and add/remove contact tags. Central alert workflows also depend on contact updates and tag cycling. |
| `conversations.readonly` | On | Keep | Search and read HighLevel conversation threads. |
| `conversations.write` | On | Keep | Create a conversation when a contact does not yet have one. |
| `conversations/message.readonly` | On | Keep | Read conversation messages and support inbound/outbound message synchronization. |
| `conversations/message.write` | On | Keep | Insert inbound/outbound messages and update delivery status in HighLevel. This is also tied to the conversation-provider outbound event. |
| `locations.readonly` | On | Keep | Resolve the authorized sub-account name/details. Do not use this scope to infer where the app was installed. |
| `locations/customFields.readonly` | On | Keep | Resolve the custom-field IDs used by low-balance, sender-ID, top-up, welcome, and related workflow alerts. |
| `oauth.write` | On | Keep | Exchange an agency/company token for a location token; also supports explicit marketplace uninstall operations. |
| `oauth.readonly` | On | Keep | Fetch only locations where this app is installed. Required for the corrected designated-location provisioning flow. |
| `locations/customValues.readonly` | On | Remove | No current NOLA runtime path reads HighLevel location custom values. |
| `locations/customValues.write` | On | Remove | No current NOLA runtime path creates, updates, or deletes HighLevel location custom values. |
| `companies.readonly` | Unavailable | Do not depend on it | The callback currently attempts an agency-name lookup, but this Agency-only scope is unavailable for the app's Sub-account target-user type. Use token/install payload metadata or a non-blocking fallback name. |

Do **not** enable these merely because their names look related:

| Scope | Recommendation | Explanation |
|---|---|---|
| `locations/tags.readonly` / `locations/tags.write` | Leave off | NOLA cycles tags on contacts through `/contacts/:contactId/tags`; `contacts.write` covers that. It does not manage the location tag catalog. |
| `workflows.readonly` / `workflows.write` | Leave off | NOLA triggers already-published HighLevel workflows by updating/tagging a contact; it does not read or edit workflow definitions. |
| `locations/customFields.write` | Leave off for now | NOLA reads field definitions and writes values to contacts. It does not create/edit field definitions. |
| `marketplace-installer-details.readonly` | Leave off for now | Current code does not call the installer-details endpoint. Add only with a concrete installer-audit feature. |
| Broad users, opportunities, calendars, payments, invoices, or SaaS scopes | Leave off | No current NOLA runtime path needs them. Least privilege reduces consent friction and review risk. |

The canonical HighLevel endpoint-to-scope list is the [HighLevel Scopes reference](https://marketplace.gohighlevel.com/docs/Authorization/Scopes/index.html).

## 4. What users should expect during installation

### Before installing

The installer must be an Agency Owner/Admin who can manage the intended sub-account(s). They should know which location(s) will use NOLA SMS Pro and have the NOLA account owner's name, email, and phone ready.

### Installation sequence

1. In HighLevel, switch to **Agency view**.
2. Open the NOLA SMS Pro Marketplace listing and choose Install.
3. Select only the designated sub-account(s).
4. Review and approve the requested permissions.
5. HighLevel redirects to NOLA. NOLA exchanges the agency token for location-scoped token(s).
6. Complete NOLA registration or sign in if the location already has an account.
7. Wait for the install status to show ready before sending a message.

### What installation covers

For each selected and successfully provisioned sub-account, installation should provide:

- authenticated, refreshable HighLevel location access;
- NOLA account registration/sign-in and location ownership mapping;
- contact listing and contact synchronization;
- HighLevel conversation creation, inbound/outbound message sync, and delivery-status sync;
- SMS sending through the configured provider and sender rules;
- wallet/credit balance and low-balance monitoring;
- Sender ID request submission and status history;
- top-up/credit request flows;
- in-app notifications and notification preferences;
- welcome onboarding after NOLA registration is completed;
- support ticket creation and ticket history.

Installation does not automatically approve a Sender ID, add credits, publish HighLevel workflows, or configure the central notification location. Those are separate operational prerequisites.

### Agency view versus Sub-account view

- **Agency view:** use this for the supported installation path and for selecting one or more designated locations.
- **Sub-account view:** only the current sub-account should appear. That is expected after HighLevel's marketplace distribution update, because the installer is operating inside one location and receives a location-scoped token. It should not expose sibling sub-accounts.
- If the wrong location is shown, stop before authorization, return to Agency view, and verify the active agency and selected sub-account.

The user-facing instruction should therefore say: **Install from Agency view if
you manage more than one sub-account or need to choose designated locations.
Install from Sub-account view only when you intend to authorize the one active
sub-account.** Do not describe the one-location Sub-account screen as an error.

### Reinstall and uninstall

- Reinstall should recognize an existing NOLA owner and route to sign-in/welcome-back without creating a duplicate owner.
- Uninstall must immediately disable sends and conversation-provider use for that location.
- An agency-level uninstall must disable all locations covered by that installation.
- NOLA data deletion is a separate controlled process; an ordinary Marketplace uninstall should not silently destroy customer records.

## 5. Notification coverage and current gaps

Marketplace scopes alone do not guarantee notifications. Most listed alerts update a contact in NOLA's central HighLevel location, write alert custom fields, and cycle a tag that a published HighLevel workflow must watch.

| Notification | Current trigger and expected behavior | Current assessment |
|---|---|---|
| Low Balance | Runs after a paid message deduction when the location preference is enabled and the balance is at/below its threshold. Uses a circuit breaker to avoid repeated alerts. | Implemented. Requires central token, custom fields, tag, and published workflow. |
| Sender ID Requests | Runs when a request is submitted and again when an admin approves or rejects it. | Implemented. Pending/approved/rejected workflow paths must all be tested. |
| Top Up Success | Runs after approved credit allocation and automatic recharge paths. | Implemented. Verify that every successful funding path calls the notifier exactly once. |
| Welcome Onboarding | Runs after NOLA registration succeeds, including registration completed from an install. | Implemented after registration, not after the raw Marketplace `INSTALL` event. An installer who abandons registration should not be described as onboarded. |
| Support Ticket Submission | Ticket is stored and returned to the user. | **Gap:** no notification dispatch is called on ticket creation. This cannot currently be promised as flawless. Add a support-ticket notifier and admin notification before advertising it. |
| Marketplace install received | `INSTALL` is acknowledged and used for location-selection/token resolution. | Partial. Add an idempotent internal admin audit notification if operations needs a visible “install received” event. Keep it separate from Welcome Onboarding. |

### Notification prerequisites

For Low Balance, Sender ID, Top Up Success, and Welcome Onboarding to work reliably:

- `NOLA_ALERT_GHL_LOCATION_ID` must point to the central notification location.
- `NOLA_ALERT_GHL_TOKEN_REGISTRY_ID` must resolve to a healthy token with `contacts.readonly`, `contacts.write`, and `locations/customFields.readonly`.
- The configured custom fields must exist and their IDs must resolve.
- The configured alert tags must exactly match the published HighLevel workflow triggers.
- Each workflow must have a valid sending domain/channel, recipient rules, and published status.
- Failures must be logged with event type, source location, contact, HTTP status, and retry classification without exposing OAuth tokens.
- Notification events need idempotency keys so retries do not send duplicates.

Recommended support-ticket implementation:

1. After `support_tickets` creation succeeds, create an internal `admin_notifications` record.
2. Call a new idempotent `notifySupportTicketSubmitted` service method.
3. Upsert the account owner in the central location, write ticket ID/subject/priority/source-location custom fields, and cycle a dedicated tag such as `nola-support-ticket-alert`.
4. Treat notification failure as non-fatal to ticket creation, but surface it in operational logs and retry it asynchronously.

Scope clarification: these five notification families do not each need a
special marketplace scope. Their central HighLevel bridge uses the existing
`contacts.readonly`, `contacts.write`, and
`locations/customFields.readonly` permissions. Reliability additionally
depends on NOLA's own event dispatch, central-location token health, custom
field/tag configuration, published workflows, idempotency, and retries. Adding
more marketplace scopes will not repair a missing notification call.

## 6. Release acceptance tests

Do not describe installation or notifications as flawless until these pass in disposable locations:

1. Agency install selecting one of three locations provisions only the selected location.
2. Agency bulk install selecting two locations provisions exactly those two.
3. A later `INSTALL` webhook provisions one newly selected location idempotently.
4. A direct sub-account install either works as a single-location flow or is unavailable by design when Agency-only is configured.
5. Token refresh retains the required scopes and remains location-scoped for runtime API calls.
6. Contacts list/create/update/tag operations succeed.
7. Conversation create/search, inbound/outbound sync, message reads, and delivery-status updates succeed.
8. Low-balance alert sends once at the threshold and respects preferences/cooldown.
9. Sender ID pending, approved, and rejected alerts each send once.
10. Manual top-up and auto-recharge each produce one Top Up Success alert.
11. New registration produces one Welcome Onboarding alert; reinstall does not create a duplicate owner.
12. Support ticket creation produces one internal notification and one configured workflow alert after the gap is implemented.
13. Location uninstall disables sending for that location without deleting customer data.
14. Agency uninstall disables all covered locations and repeated uninstall events remain harmless.

## 7. Recommended priority order

1. Replace all-location agency provisioning with installed-location provisioning (`oauth.readonly`).
2. Make the hard-coded reinstall scope list match the live marketplace scope set.
3. Remove the two unused custom-value scopes after a disposable-location regression test.
4. Decide explicitly between Agency-only installation now and future dual installer support.
5. Add the missing support-ticket notification path.
6. Add idempotency and observable delivery records for all five notification families.
7. Confirm whether both redirect URLs are still required and retire any legacy callback safely.
8. Run the acceptance matrix before changing the public listing language.
