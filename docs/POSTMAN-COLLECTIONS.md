# NOLA SMS Pro — Postman Collections Guide

**Base URL:** `https://smspro-api.nolacrm.io`

> **Tip:** Create a Postman Environment with variable `webhook_secret = f7RkQ2pL9zV3tX8cB1nS4yW6` and reference it as `{{webhook_secret}}` in all request headers.

---

## Auth Types

| Key | Header | Value |
|-----|--------|-------|
| Webhook Secret | `X-Webhook-Secret` | `f7RkQ2pL9zV3tX8cB1nS4yW6` |

---

## 📁 Collection 1 — Auth

| # | Method | URL | Auth | Body |
|---|--------|-----|------|------|
| 1.1 | POST | `/api/auth/login.php` | None | `{"email":"you@agency.com","password":"yourpass"}` |
| 1.2 | POST | `/api/auth/register.php` | None | `{"email":"...","password":"...","name":"..."}` |
| 1.3 | POST | `/api/auth/register-from-install` | None | `{"full_name":"...", "phone":"...", "email":"...", "password":"...", "location_id":"...", "company_id":"..."}` (Unified location/agency install, syncs owner info) |

---

## 📁 Collection 2 — Agency Billing

**Header for all:** `X-Webhook-Secret: {{webhook_secret}}`

### Agency Wallet (`/api/billing/agency_wallet.php`)

| # | Method | Params / Body | Description |
|---|--------|---------------|-------------|
| 2.1 | GET | `?agency_id=X&action=balance` | Fetch agency wallet balance + settings |
| 2.2 | POST | `{"action":"set_auto_recharge","agency_id":"X","enabled":true,"amount":500,"threshold":100}` | Update auto-recharge settings |
| 2.3 | POST | `{"action":"set_master_lock","agency_id":"X","enabled":false}` | Toggle master balance lock |
| 2.4 | POST | `{"action":"gift","agency_id":"X","location_id":"abc12345","amount":100,"note":"Monthly allocation"}` | Transfer credits to a subaccount (atomic) |
|      |      |                                                                                                      | *Note: `location_id` injection is being automated for background routing.* |

### Subaccount Wallet (`/api/billing/subaccount_wallet.php`)

| # | Method | Params / Body | Description |
|---|--------|---------------|-------------|
| 2.5 | GET | `?location_id=X` | Fetch subaccount credit balance + settings |
| 2.6 | POST | `{"action":"set_auto_recharge","location_id":"X","enabled":true,"amount":250,"threshold":25}` | Update subaccount auto-recharge |
| 2.7 | POST | `{"action":"request_credits","location_id":"X","amount":250,"note":"Low balance"}` | Submit a credit request to agency |

### Credit Requests (`/api/billing/credit_requests.php`)

| # | Method | Params / Body | Description |
|---|--------|---------------|-------------|
| 2.8 | GET | `?agency_id=X&status=pending` | List credit requests (status: `pending`, `approved`, `denied`) |
| 2.9 | POST | `{"action":"approve","request_id":"REQ_DOC_ID"}` | Approve request — atomic transfer + dual tx log |
| 2.10 | POST | `{"action":"deny","request_id":"REQ_DOC_ID"}` | Deny a pending request |

### Transactions Ledger (`/api/billing/transactions.php`)

| # | Method | Params | Description |
|---|--------|--------|-------------|
| 2.11 | GET | `?scope=agency&agency_id=X&page=1` | Agency-scoped transaction history |
| 2.12 | GET | `?scope=subaccount&location_id=X&month=2026-04&page=1` | Subaccount-scoped history, optional month filter `YYYY-MM` |

> **Note:** Payload now natively outputs `message_body`, `chars`, `to_number`, `agency_name`, and `subaccount_name` mapping fields for objects where `type == sms_usage`.

---

## 📁 Collection 3 — SMS / Webhooks

**Header:** `X-Webhook-Secret: {{webhook_secret}}`

| # | Method | URL | Auth | Description |
|---|--------|-----|------|-------------|
| 3.1 | POST | `/webhook/send_sms.php` | Required | Main outbound SMS engine (GHL Workflow / Web UI) |
| 3.2 | POST | `/webhook/ghl_provider.php` | Required | GHL Custom Conversation Provider (chat bubble sends) |
| 3.3 | POST | `/webhook/receive_sms.php` | None | Semaphore inbound SMS callback |
| 3.4 | GET  | `/webhook/retrieve_status.php` | None | Cron: pull delivery status updates from Semaphore |

---

## 📁 Collection 4 — General API

**Header:** `X-Webhook-Secret: {{webhook_secret}}`

| # | Method | URL | Params / Body | Description |
|---|--------|-----|---------------|-------------|
| 4.1 | GET | `/api/messages.php` | `?direction=all&limit=50&offset=0` | Message history |
| 4.2 | GET | `/api/conversations.php` | `?limit=20&offset=0` | Conversation sidebar list |
| 4.3 | GET | `/api/contacts.php` | `?limit=50&offset=0` | List contacts |
| 4.4 | POST | `/api/contacts.php` | `{"name":"John","phone":"09171234567","email":"j@x.com"}` | Create contact |
| 4.5 | GET | `/api/credits.php` | — | Legacy global credit balance |
| 4.6 | GET | `/api/get_credit_transactions.php` | `?limit=50&month=2026-04` | Subaccount detailed credit history, optional `month` filter |

---

## 📁 Collection 5 — Agency Management

**Header:** `X-Webhook-Secret: {{webhook_secret}}`

| # | Method | URL | Params / Body | Description |
|---|--------|-----|---------------|-------------|
| 5.1 | GET | `/api/agency/get_subaccounts.php` | `?agency_id=X` | List subaccounts for an agency |
| 5.2 | GET | `/api/agency/check_installs.php` | `?agency_id=X` | Check GHL install status per subaccount |
| 5.3 | POST | `/api/agency/toggle_subaccount.php` | `{"location_id":"X","active":true}` | Enable / disable a subaccount |
| 5.4 | POST | `/api/agency/sync_locations.php` | `{"agency_id":"X"}` | Sync GHL locations into Firestore |

---

## 📁 Collection 6 — CORS / Health Checks

| # | Method | URL | Headers | Expected Response |
|---|--------|-----|---------|-------------------|
| 6.1 | OPTIONS | `/api/billing/agency_wallet.php` | `Origin: https://agency.nolasmspro.com`, `Access-Control-Request-Method: GET` | `204 No Content` |
| 6.2 | GET | `/api/billing/agency_wallet.php` | `X-Webhook-Secret: wrong` | `401 {"status":"error","message":"Unauthorized Access"}` |

---

## 📁 Collection 7 — Admin Settings

**Header:** `X-Webhook-Secret: {{webhook_secret}}`

| # | Method | URL | Params / Body | Description |
|---|--------|-----|---------------|-------------|
| 7.1 | GET | `/api/admin_settings.php` | — | Fetch core system settings and global pricing (`provider_cost`, `charged_rate`) |
| 7.2 | POST | `/api/admin_settings.php` | `{"provider_cost": 0.02, "charged_rate": 0.05}` | Update system settings and/or global pricing (Saves to `admin_config` for dynamic pricing) |
| 7.3 | GET | `/api/admin_sender_requests.php` | `?action=accounts&company_id=xyz` | Unified subaccount list (with optional company_id scoping and enriched token metadata) |
