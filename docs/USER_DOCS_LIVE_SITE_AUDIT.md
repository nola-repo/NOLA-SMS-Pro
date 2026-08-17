# NOLA SMS Pro Live Docs Audit

Audited site: https://nola-sms-pro-docs.vercel.app  
Audit date: July 10, 2026

## Summary

The live docs are moving in the right direction because they are no longer
publishing the backend handoff files. The current site is mostly user-facing,
but it is still too detailed and sometimes sounds like internal product or
implementation documentation.

The documentation should feel like a guided walkthrough for a HighLevel user:

- what they see during installation;
- what they click next;
- what setup items are required before sending;
- what the dashboard sections mean;
- what the default SMS behavior is after install; and
- what to do when a common setup or sending issue appears.

Avoid internal terms unless the user sees the same words in the app.

## Main Issues Found

1. Too many separate pages for a simple first-time user journey.

The live site has 19 article routes. That is more than most new users need.
Several pages overlap, especially Welcome, Introduction, Quick Start,
Marketplace Install, Create Account, Sign In, Dashboard Overview, and First SMS
Checklist.

2. Some wording is still too technical.

Replace phrases like:

- "least-privilege scoping"
- "oauth"
- "secure tokens"
- "sandbox billing"
- "workspace connection parameters"
- "credit ledger"
- "carrier approval workflow"

Use plain user-facing wording:

- "requested permissions"
- "connection"
- "you may stay signed in"
- "checkout"
- "connected location"
- "credit history"
- "Sender ID approval"

3. The docs should explain default SMS behavior more clearly.

After the app is installed and setup is complete, users need to know:

- the default sender is `NOLASMSPro`;
- SMS will only send when credits are available;
- one normal 160-character SMS usually uses 1 credit;
- longer or special-character messages may use more credits;
- first test messages should be natural, not just "test";
- message status should be checked in Message History; and
- users should not click Send repeatedly if a message fails or stays pending.

4. Some pages describe tools instead of guiding the actual workflow.

The user does not need a long explanation of every feature. They need a
sequence: install, create account, verify dashboard, check Sender ID and
credits, add contact, send first SMS, check status.

## Page-by-Page Notes

### Welcome

Status: Needs trimming.

Keep the short explanation that NOLA SMS Pro runs inside HighLevel and does not
require a download. Remove instructions about using the documentation UI, such
as Ctrl+K, "On This Page", and theme toggles. Those do not help users install
or send SMS.

### Introduction

Status: Merge into Getting Started.

This repeats Quick Start. Keep only the four-part flow:

1. Install from HighLevel Marketplace.
2. Select the correct sub-account/location.
3. Create or sign in to the NOLA account.
4. Confirm dashboard, credits, and Sender ID before sending.

### Quick Start

Status: Good foundation, but make it the primary first page.

Keep the seven-step flow. Add a final step to check Message History after the
first SMS. Mention that the default sender is `NOLASMSPro`.

### Install From HighLevel

Status: Mostly correct, but remove technical wording.

Replace "contacts, conversations, locations, and oauth" with "contacts,
conversations, location details, and permission to connect the app."

Replace the "least-privilege scoping" note with:

"Only approve the install when the selected sub-account/location is correct."

### Create Account

Status: Correct.

Keep it short. Make clear that if the location is already registered, the user
should sign in with the existing owner account instead of creating another
account.

### Signing In

Status: Correct, but remove token language.

Replace "secure tokens" with "When you open NOLA SMS Pro from HighLevel, you
may already be signed in."

### Dashboard Overview

Status: Important page.

This should be more visual and practical. For each dashboard area, describe
what the user sees and what they use it for:

- Home: credits, recent activity, alerts.
- Compose: send individual or bulk SMS.
- Contacts: add/search contacts.
- Templates: save reusable messages.
- Message History: check status.
- Settings: profile, Sender IDs, notifications, credits.

### First SMS Checklist

Status: Keep, but make it less beta/test-heavy.

Rename to "Send Your First SMS". It should be a simple checklist for normal
users, not an "interactive onboarding check tool."

Required reminders:

- confirm the correct location;
- confirm credits are available;
- use `NOLASMSPro` for the first send;
- use a valid `09XXXXXXXXX` phone number;
- send one natural message;
- check Message History.

### Contacts

Status: Good.

Keep the phone format guidance. This is useful and user-facing.

### Templates

Status: Good.

Keep it short. Avoid overexplaining carrier filtering. Say "Use clear,
natural wording."

### Sending SMS

Status: Important page, but should be clearer on default behavior.

Add a "Before You Send" checklist:

- correct location;
- approved/default Sender ID;
- enough credits;
- valid recipient number;
- natural message.

Keep the warning not to click Send repeatedly.

### Sender ID

Status: Correct but too formal.

Rename from "Sender ID & Carrier Approval Workflow" to "Sender IDs".
Lead with the default behavior:

"You can send with `NOLASMSPro` right away if credits are available. Custom
Sender IDs must be requested and approved before they appear in Compose."

### SMS Credits

Status: Correct but too technical.

Rename from "SMS Credits & Refill Workflow" to "SMS Credits".
Replace "sandbox billing" and "ledger" with "checkout" and "credit history."

### Message History

Status: Good.

Keep the statuses: Sending, Sent, Failed. Add what the user should do:

- wait and refresh if Sending stays visible;
- check the failed reason if available;
- contact support with screenshot, number, and send time.

### Reports & Analytics

Status: Optional.

Keep only if this exists in the live app for users. If not, hide it until the
feature is visible. Documentation should only describe screens the user can
actually access.

### Settings & Notifications

Status: Correct but simplify.

Replace "workspace connection parameters" with "connected location details."
Keep the warning to stop if the wrong location appears.

### Troubleshooting

Status: Useful.

Keep common issues, but shorten the page. Make every issue follow this pattern:

- what the user sees;
- what it means;
- what to do next.

### FAQ

Status: Useful, but reduce overlap.

Keep only short answers that do not repeat full article content.

### Support

Status: Good.

Keep this page. Add what users should include when reporting SMS issues:

- screenshot;
- HighLevel location name;
- recipient number;
- send time;
- message status;
- visible error message.

## Recommended Sidebar

Use fewer pages:

1. Welcome
2. Install NOLA SMS Pro
3. Create or Sign In to Your Account
4. Dashboard Overview
5. Send Your First SMS
6. Contacts
7. Templates
8. Sender IDs
9. SMS Credits
10. Message History
11. Settings
12. Troubleshooting
13. FAQ
14. Support

Hide or merge:

- Introduction: merge into Welcome or Install.
- Quick Start: make it the main Getting Started page or merge into Install.
- Reports & Analytics: hide unless the screen is live and user-visible.

## Screenshot Placeholder Plan

Add one clear image placeholder near the top of each main guide page. The
placeholder should show what the user is expected to see at that step. Use real
screenshots once available, and keep each caption short.

Recommended image style:

- Use full-screen or cropped screenshots from the actual HighLevel/NOLA flow.
- Blur or hide customer names, phone numbers, emails, tokens, location IDs, and
  payment details.
- Use the same browser size and theme where possible so the guide feels
  consistent.
- Do not use decorative stock images. Every image should show a real screen,
  button, menu, form, dashboard section, or setup state.
- If a page has multiple possible states, use a small "Possible screens" group
  instead of writing long explanations.

### Image Placeholders by Page

#### 1. Welcome

Placeholder filename:

```text
/images/docs/welcome-nola-inside-highlevel.png
```

Alt text:

```text
NOLA SMS Pro opened inside the HighLevel sub-account menu.
```

Caption:

```text
NOLA SMS Pro runs inside your HighLevel sub-account after installation.
```

#### 2. Install NOLA SMS Pro

Placeholder filenames:

```text
/images/docs/install-marketplace-listing.png
/images/docs/install-select-subaccount.png
/images/docs/install-allow-permissions.png
```

Captions:

```text
Find NOLA SMS Pro in the HighLevel Marketplace.
Select the sub-account/location where the app should be installed.
Review the install screen, then click Allow & Install.
```

#### 3. Create or Sign In to Your Account

Placeholder filenames:

```text
/images/docs/account-create-form.png
/images/docs/account-sign-in-existing-owner.png
```

Captions:

```text
New locations show the account creation form after installation.
Already registered locations ask the existing owner to sign in.
```

#### 4. Dashboard Overview

Placeholder filename:

```text
/images/docs/dashboard-overview-home.png
```

Caption:

```text
The dashboard shows credits, recent activity, alerts, and shortcuts.
```

Recommended callouts on the image:

- SMS credit balance
- Recent message activity
- Main navigation
- Settings shortcut

#### 5. Send Your First SMS

Placeholder filenames:

```text
/images/docs/compose-first-sms.png
/images/docs/compose-default-sender.png
/images/docs/message-history-sent-status.png
```

Captions:

```text
Compose one natural test message before sending live SMS.
Use the default NOLASMSPro sender for your first message.
Check Message History after sending to confirm the status.
```

#### 6. Contacts

Placeholder filenames:

```text
/images/docs/contacts-list.png
/images/docs/contacts-add-contact.png
```

Captions:

```text
Contacts show the people available in the connected location.
Add a contact with a valid mobile number before sending a test SMS.
```

#### 7. Templates

Placeholder filenames:

```text
/images/docs/templates-list.png
/images/docs/templates-create-template.png
```

Captions:

```text
Templates save reusable SMS messages.
Create a short, natural message that can be inserted in Compose.
```

#### 8. Sender IDs

Placeholder filenames:

```text
/images/docs/sender-id-default.png
/images/docs/sender-id-request-form.png
/images/docs/sender-id-statuses.png
```

Captions:

```text
The default sender is NOLASMSPro.
Custom Sender IDs can be requested from Settings.
Only approved Sender IDs can be selected when sending SMS.
```

#### 9. SMS Credits

Placeholder filenames:

```text
/images/docs/credits-balance.png
/images/docs/credits-request-form.png
/images/docs/credits-history.png
```

Captions:

```text
Check your available credits before sending SMS.
Request more credits if your balance is low or zero.
Credit history shows recent credit changes and SMS usage.
```

#### 10. Message History

Placeholder filenames:

```text
/images/docs/message-history-list.png
/images/docs/message-history-failed-detail.png
```

Captions:

```text
Message History shows Sending, Sent, and Failed statuses.
Open failed messages to see the available error details.
```

#### 11. Settings

Placeholder filenames:

```text
/images/docs/settings-profile.png
/images/docs/settings-connected-location.png
/images/docs/settings-notifications.png
```

Captions:

```text
Profile settings show the user information for the account.
Connected Location should match the HighLevel sub-account you installed.
Notifications control alerts such as low balance and delivery updates.
```

#### 12. Troubleshooting

Placeholder filenames:

```text
/images/docs/error-wrong-location.png
/images/docs/error-zero-credits.png
/images/docs/error-sms-failed.png
/images/docs/error-reconnect-required.png
```

Captions:

```text
If the wrong location appears, stop and contact support before sending.
If credits are zero, request credits before sending SMS.
If SMS fails, check the number, credits, Sender ID, and Message History.
If reconnect is required, follow the reconnect prompt from the app.
```

#### 13. Support

Placeholder filename:

```text
/images/docs/support-ticket-form.png
```

Caption:

```text
Use the support form to report setup, credit, Sender ID, or SMS issues.
```

### Placeholder Component Copy

If the docs site has a reusable placeholder component, use this copy until real
screenshots are added:

```text
Screenshot coming soon
This image will show the exact screen you should see at this step.
```

When screenshots are added, replace the placeholder with:

```text
What you should see
```

## Replacement Tone Guide

Every page should answer these questions:

- What do I see on screen?
- What should I click?
- What information do I need before continuing?
- What should happen after I click?
- What should I do if it does not happen?

Keep each page short:

- one plain description;
- one short checklist or step list;
- one reminder box;
- one troubleshooting note if needed.

Do not include backend, API, OAuth, token, lifecycle, provider, database,
Firestore, implementation, or release/testing language unless the user sees
that exact term in the app.

## Most Important Content to Add

Add this reminder near Quick Start, Send Your First SMS, Sender IDs, and SMS
Credits:

> After installation, NOLA SMS Pro sends SMS using the default sender
> `NOLASMSPro` unless you select an approved custom Sender ID. Messages require
> available SMS credits. A normal 160-character SMS usually uses 1 credit, and
> longer messages may use more. Send one natural test message first, then check
> Message History for the status.
