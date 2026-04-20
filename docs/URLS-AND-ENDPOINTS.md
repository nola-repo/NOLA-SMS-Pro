# NOLA SMS Pro — URLs and Endpoints

## Cloud Run / production base URL

```
https://smspro-api.nolacrm.io
```

Use this base for all endpoints below (no trailing slash).

---

## All endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/webhook/send_sms` | `X-Webhook-Secret` | Send outbound SMS (GHL, API, Web UI). Body: JSON with `customData.number`, `customData.message`, etc. |
| POST | `/webhook/receive_sms` | None | Inbound SMS webhook (Semaphore callback). Writes to Firestore `inbound_messages`. |
| GET  | `/webhook/retrieve_status` | None | Cron: update delivery status of queued messages in `sms_logs` (call via Cloud Scheduler). |
| GET  | `/webhook/send_sms` | `X-Webhook-Secret` | Returns last sent payload (debug). |
| GET  | `/api/messages` | `X-Webhook-Secret` | Fetch message history. Query: `direction` (outbound\|inbound\|all), `limit`, `offset`, `status`. Use `conversation_id` to load one chat (e.g. `conv_09761761036` or `group_batch_xxx`) and fix bulk mixing. |
| GET  | `/api/conversations` | `X-Webhook-Secret` | Fetch conversation list for sidebar. Query: `limit`, `offset`, optional `type` (direct\|bulk). |
| GET  | `/api/contacts` | `X-Webhook-Secret` | List contacts. Query: `limit`, `offset`, `phone`. |
| POST | `/api/contacts` | `X-Webhook-Secret` | Create contact. Body: `{ "name", "phone", "email" }`. |
| GET  | `/api/credits`  | `X-Webhook-Secret` | Get current credit balance for the default account. |
| GET  | `/api/get_credit_transactions` | `X-Webhook-Secret` | Fetch credit ledger array. Query: `account_id` (defaults to 'default'), `limit`. |
| GET  | `/api/billing/agency_wallet.php` | `Bearer JWT` | Fetch agency wallet config and balance. Query: `agency_id`. |
| POST | `/api/billing/agency_wallet.php` | `Bearer JWT` | Agency wallet POST actions (e.g., `gift`, `set_auto_recharge`). |
| GET  | `/api/billing/subaccount_wallet.php` | `Bearer JWT` | Fetch subaccount wallet config and balance. Query: `location_id`. |
| POST | `/api/billing/subaccount_wallet.php` | `Bearer JWT` | Subaccount wallet POST actions (e.g., `request_credits`, `set_auto_recharge`). |
| GET  | `/api/billing/credit_requests.php` | `Bearer JWT` | List subaccount credit requests. Query: `agency_id`, `status`. |
| POST | `/api/billing/credit_requests.php` | `Bearer JWT` | Process credit requests (e.g., `approve`, `deny`). |
| GET  | `/api/billing/transactions.php` | `Bearer JWT` | Paged fetch of `credit_transactions`. Query: `scope`, `page`. |
| GET  | `/oauth/callback` | None | GHL OAuth callback. GHL redirects here with `?code=...` (optional `&state=locationId`). Writes tokens to Firestore `ghl_tokens/{locationId}`. Each subaccount/location gets its own doc. |

---

## Full URLs (production)

- **Send SMS (webhook):**  
  `https://smspro-api.nolacrm.io/webhook/send_sms`

- **Receive SMS (Semaphore):**  
  `https://smspro-api.nolacrm.io/webhook/receive_sms`

- **Retrieve status (cron):**  
  `https://smspro-api.nolacrm.io/webhook/retrieve_status`

- **Message history (Web UI):**  
  `https://smspro-api.nolacrm.io/api/messages`

- **Conversations (Web UI sidebar):**  
  `https://smspro-api.nolacrm.io/api/conversations`

- **Contacts list:**  
  `https://smspro-api.nolacrm.io/api/contacts`

- **Create contact:**  
  `https://smspro-api.nolacrm.io/api/contacts` (POST)

- **Credit balance:**  
  `https://smspro-api.nolacrm.io/api/credits`

- **Credit transactions (legacy):**  
  `https://smspro-api.nolacrm.io/api/get_credit_transactions`

- **Agency Wallet Endpoint:**  
  `https://smspro-api.nolacrm.io/api/billing/agency_wallet.php`

- **Subaccount Wallet Endpoint:**  
  `https://smspro-api.nolacrm.io/api/billing/subaccount_wallet.php`

- **Credit Requests Endpoint:**  
  `https://smspro-api.nolacrm.io/api/billing/credit_requests.php`

- **Credit Transactions List (Billing system):**  
  `https://smspro-api.nolacrm.io/api/billing/transactions.php`

- **GHL OAuth redirect (set in Marketplace):**  
  `https://smspro-api.nolacrm.io/oauth/callback`

---

## Auth header (where required)

For endpoints that require auth, send:

```
X-Webhook-Secret: <value of WEBHOOK_SECRET in Cloud Run>
```

Example: `X-Webhook-Secret: f7RkQ2pL9zV3tX8cB1nS4yW6`

---

## Firestore collections created by this app

| Collection | Document(s) | Created by |
|------------|-------------|------------|
| **ghl_tokens** | `{locationId}` | `ghl_callback.php` — GHL OAuth tokens per subaccount. Each subaccount/location gets its own doc named after its raw `locationId`. |
| **messages** | `{message_id}` | `send_sms.php` — Every SMS for UI: `conversation_id`, `number`, `message`, `direction`, `sender_id`, `status`, `batch_id`, `created_at`. Frontend loads with `?conversation_id=conv_XXX` or `group_batch_XXX`. |
| **conversations** | `conv_{number}` or `group_{batch_id}` | `send_sms.php` — Chat sidebar: `type` (direct\|bulk), `members`, `last_message`, `last_message_at`, `name`. |
| **sms_logs** | `{message_id}` | `send_sms.php` — Low-level outbound logs (same data, for debugging/analytics). Fields include `credits_used`. |
| **inbound_messages** | `{message_id}` | `receive_sms.php` — Each inbound SMS (from, message, date_received). |
| **contacts** | Auto ID | `api/contacts.php` (POST) — Contact records (name, phone, email, created_at, updated_at). |
| **accounts** | `default` | `api/credits.php` / `CreditManager.php` — Holds `credit_balance`, `currency`, `created_at`, `updated_at` for the global pool. |
| **credit_transactions** | Auto ID | `CreditManager.php` — Ledger of every top-up and deduction (`amount`, `balance_after`, `type`). |

Project ID in code: `nola-sms-pro`. For `messages` queries by `conversation_id` + `created_at`, create the composite index suggested by Firestore when first run.

---

## Cloud Run URL (summary)

**Base URL:** `https://smspro-api.nolacrm.io`

| Purpose | Full URL |
|---------|----------|
| Send SMS | `https://smspro-api.nolacrm.io/webhook/send_sms` |
| Receive SMS (Semaphore) | `https://smspro-api.nolacrm.io/webhook/receive_sms` |
| Status cron | `https://smspro-api.nolacrm.io/webhook/retrieve_status` |
| Message history | `https://smspro-api.nolacrm.io/api/messages` |
| Contacts (list/create) | `https://smspro-api.nolacrm.io/api/contacts` |
| Credit balance | `https://smspro-api.nolacrm.io/api/credits` |
| Credit transactions (legacy) | `https://smspro-api.nolacrm.io/api/get_credit_transactions` |
| Agency Wallet API            | `https://smspro-api.nolacrm.io/api/billing/agency_wallet.php` |
| Subaccount Wallet API        | `https://smspro-api.nolacrm.io/api/billing/subaccount_wallet.php` |
| Credit Requests API          | `https://smspro-api.nolacrm.io/api/billing/credit_requests.php` |
| Scoped Transactions Log      | `https://smspro-api.nolacrm.io/api/billing/transactions.php` |
| GHL OAuth callback           | `https://smspro-api.nolacrm.io/oauth/callback` |
