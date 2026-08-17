# NOLA SMS Pro User Guide and Beta Test Walkthrough

**Audience:** Sub-account users, agency installers, and beta testers  
**Reviewed against the current project:** July 7, 2026

## 1. What NOLA SMS Pro does

NOLA SMS Pro is a web app installed in GoHighLevel (GHL). It lets a business:

- send individual and bulk SMS messages;
- use GHL contacts and conversation history;
- create reusable SMS templates;
- monitor delivery status and SMS credit usage;
- request and manage Sender IDs;
- buy or request credits;
- configure alerts; and
- submit support tickets.

There is no desktop program to download. "Installation" means authorizing the
NOLA SMS Pro Marketplace app for a GHL sub-account and then completing the NOLA
account setup.

## 2. Know which account type you are testing

### Sub-account user

A sub-account user works in one GHL location. Each GHL location has exactly one
canonical NOLA account owner. A second NOLA account cannot be created for the
same location.

### Agency user

An agency user can install NOLA for designated sub-accounts, manage access to
more than one installed location, and review sub-account credit requests.

For the current recommended setup, an **Agency Owner or Agency Admin performs
the Marketplace installation**. The intended sub-account owner then completes
NOLA registration or signs in.

## 3. Before installation

Prepare the following:

- an Agency Owner/Admin login that can install Marketplace apps;
- a disposable GHL test sub-account (do not begin with a live customer);
- the intended NOLA owner's full name, email, and phone number;
- a unique email that is not already linked to another NOLA sub-account;
- a valid Philippine mobile number, such as `09XXXXXXXXX`, for sending tests;
- access to the email inbox for password-reset tests;
- test credits or an approved test-payment method; and
- permission for browser pop-ups when testing credit checkout.

Record the agency name, GHL company ID, selected location name, and GHL
location ID before starting. This makes wrong-location defects easy to spot.

## 4. Install from HighLevel

1. Sign in to HighLevel and switch to **Agency View**.
2. Open the Marketplace and select **NOLA SMS Pro**.
3. Click **Install**.
4. Select only the disposable sub-account(s) that should use NOLA.
5. Review and approve the requested permissions.
6. Wait while HighLevel redirects to NOLA and the selected location is
   provisioned.
7. If this is the location's first installation, complete the NOLA registration
   form. Enter the owner's full name, email, phone number, and a password of at
   least eight characters. Review the details, accept the agreement, and create
   the account.
8. If NOLA says the location is already registered, do not create a different
   account. Use the existing owner's email and password on the sign-in page.
9. Continue to the dashboard and confirm that the displayed location is the
   location selected during installation.

If installing from **Sub-account View** is enabled, only the current sub-account
should appear. That is expected. Use Agency View when choosing between multiple
locations.

### Installation passes when

- only the selected location(s) are connected;
- registration or existing-owner sign-in completes;
- the NOLA dashboard opens without a manual token or Location ID prompt;
- **Settings > Account** shows the correct owner and location;
- contacts load from the correct location; and
- refreshing the page keeps the user signed in.

Stop immediately if the wrong location appears. Do not send messages or add
credits until the location mapping is correct.

## 5. First login and screen tour

Open NOLA from the installed app inside the GHL sub-account. You can also use
the standalone NOLA web login with the same email and password.

The main menu contains:

- **Home** - balance, sending statistics, recent activity, and conversations;
- **Compose** - individual or bulk SMS sending;
- **Contacts** - contacts belonging to the current GHL location;
- **Templates** - reusable message content;
- **Tickets** - support requests and their status/history; and
- **Settings** - Account, Sender IDs, Notifications, and Credits.

The desktop theme button is at the top right. On mobile, open the menu to reach
navigation and appearance controls.

## 6. Complete the initial account setup

### Confirm the account and location

Go to **Settings > Account** and verify:

- full name, email, and phone number;
- location name and agency/company name;
- GHL Location ID; and
- workspace status.

Inside the GHL embedded app, the Location ID should be detected automatically
and should be read-only. In the standalone app, **Connect GHL** starts the OAuth
connection flow. If a manual Location ID field is available, use only the ID for
the installed test location.

### Configure a Sender ID

1. Go to **Settings > Sender IDs**.
2. Confirm that the system sender `NOLASMSPro` is present.
3. To request a custom sender, click **Request New**.
4. Enter an alphanumeric sender name of 3-11 characters, its purpose, and a
   sample message.
5. Submit the request. It remains **Pending** until an administrator reviews it.
6. After approval, set it as the default if desired.

Pending or rejected Sender IDs must never be available for sending.

### Configure notifications

Go to **Settings > Notifications** and select the desired options:

- SMS Delivery Reports;
- Low Balance Alert, including its threshold; and
- Marketing & Updates.

Click **Save Changes**, refresh the page, and confirm the choices remain saved.

### Check credits

Go to **Settings > Credits** and confirm the available balance. A user may:

- refresh the balance;
- request credits from the agency with an optional note;
- choose a package and open the Buy Credits checkout; and
- review credit transactions by month.

Allow browser pop-ups for checkout. Treat auto-recharge as unverified until a
real payment is confirmed and exactly one matching ledger credit appears.

## 7. Everyday use

### Add and use a contact

1. Open **Contacts**.
2. Add a contact with a name, phone number, and optional email. Phone is
   required.
3. Search for the contact by name, phone, or email.
4. Select the contact and choose the action to use it in Compose.

Do not create duplicate contacts for the same phone number. Contacts from one
GHL location must never appear while using another location.

### Create a template

1. Open **Templates** and create a template.
2. Enter a name, message content, and category: Appointments, Marketing,
   Transactional, or General.
3. Save it, then edit it once to confirm updates persist.
4. Open **Compose** and insert the template.

### Send an individual SMS

1. Open **Compose**.
2. Select a contact or enter a valid Philippine mobile number.
3. Choose an approved Sender ID.
4. Enter the message or insert a template.
5. Review the character count, SMS segment count, and expected credit use.
6. Click **Send** once and wait for the confirmation.
7. Open the conversation and watch the status progress from **Sending** to
   **Sent** or **Failed**.
8. Confirm the correct debit appears in **Settings > Credits**.

Messages longer than one SMS segment can use multiple credits. The displayed
segment count and actual ledger debit should agree.

### Send a bulk SMS

1. Open **Compose** and select bulk sending.
2. Add multiple valid recipients.
3. Review the total recipients and expected credits before sending.
4. Send once.
5. Open the resulting bulk conversation/campaign and confirm that each
   recipient has a separate status and message history.

Never treat a general "campaign created" message as proof of delivery. Check
each recipient and the ledger.

### Use conversations

Open a conversation to view inbound and outbound messages together. Confirm
that conversations are sorted by the latest activity. Where the interface
offers the actions, test rename and delete with a disposable conversation.
Only use Reply if the action is visible and supported in the current build.

### Request support

Open **Tickets**, create a ticket with a clear subject, description, and
priority, and submit it. Confirm it appears in the ticket list and that its
status/history can be reopened after a page refresh.

## 8. Recommended beta test run

Use this order for your first complete test. Save screenshots and the time of
every failure.

1. Fresh agency install into one disposable location.
2. New owner registration and automatic dashboard login.
3. Logout, normal login, refresh persistence, and invalid-password test.
4. Forgot-password OTP and login with the new password.
5. Verify Account details and automatic GHL Location ID detection.
6. Load contacts; add, search, and use one contact in Compose.
7. Create, edit, use, and delete one template.
8. Send one short SMS with the system sender; verify status and one matching
   ledger debit.
9. Send a 160+ character message; verify segment count and debit.
10. Send to multiple recipients; verify isolated per-recipient statuses.
11. Verify invalid phone, blank message, pending Sender ID, and zero-credit
    sends are blocked without false success records.
12. Submit a custom Sender ID request; test pending, approved, rejected, and
    default states with an administrator.
13. Save notification preferences; test a low-balance and failed-delivery
    alert once.
14. Request agency credits and verify agency receipt/approval.
15. Complete a sandbox top-up; verify one payment, one credit entry, and the
    refreshed balance.
16. Filter the ledger and download a monthly report.
17. Submit and reopen a support ticket.
18. Reinstall the same location; confirm it routes to the existing owner and
    does not create a duplicate.
19. Uninstall; confirm sending is disabled but customer records are not
    silently deleted.
20. Install two designated locations from Agency View and confirm no unselected
    sibling location is provisioned.

For every test, record **Pass**, **Fail**, or **Blocked**, plus the account,
location ID, browser, time, expected result, actual result, screenshot, and any
visible request/error ID.

## 9. Troubleshooting

### Install token is missing, invalid, or expired

Restart from the NOLA SMS Pro Marketplace listing. Do not manually reuse an old
registration URL.

### Location is already registered

Sign in with the existing canonical owner's account. If the owner is unknown,
ask an administrator to verify the location owner mapping; do not create a
second account.

### Wrong location or no contacts

Stop sending. Confirm the active GHL sub-account, compare its Location ID with
**Settings > Account**, then reconnect or reinstall from the correct location.

### Sender ID cannot be selected

Only approved Sender IDs are selectable. Use the system sender while a request
is pending, or ask an administrator to review the request.

### Insufficient credits

Request credits from the agency or complete a top-up. Refresh the balance after
the credit is approved. Do not repeatedly click Send.

### Checkout does not open

Allow pop-ups for NOLA and retry. Confirm the checkout contains the correct
name, email, phone, and Location ID before paying.

### Delivery remains in Sending

Wait up to five minutes for provider status synchronization, then refresh. If
it remains unchanged, save the phone number, send time, conversation ID, and
request ID for support.

### Notification does not arrive

Confirm the preference is enabled and saved. Notification delivery also
depends on the central GHL alert location, matching custom fields/tags, and
published workflows, so report the event type and exact trigger time.

## 10. Release decision

Do not declare the app ready for public beta from a successful login or a
single successful SMS. At minimum, installation scoping, location isolation,
single-owner enforcement, credits/payment integrity, message status sync,
notification delivery, reinstall, and uninstall must pass.

The current repository documentation still treats the full release matrix as
acceptance work to be completed. In particular, verify real payment-backed
auto-recharge, report download, all notification workflow prerequisites, and
the disposable-location install/uninstall scenarios before release.
