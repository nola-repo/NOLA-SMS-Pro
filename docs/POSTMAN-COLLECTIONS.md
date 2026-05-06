# NOLA SMS Pro — Postman Collections Guide

**Base URL:** `https://smspro-api.nolacrm.io`

---

## ⚙️ Postman Quick Setup Guide

Before making requests, set up your Postman environment so you don't have to copy-paste the secret key every time.

1. Open Postman and click **Environments** in the left sidebar.
2. Click **+ (New)** to create a new environment and name it **"NOLA Production"**.
3. Add the following variables:
   - Variable: `base_url` | Initial Value: `https://smspro-api.nolacrm.io`
   - Variable: `webhook_secret` | Initial Value: `f7RkQ2pL9zV3tX8cB1nS4yW6`
4. Save the environment and make sure **"NOLA Production"** is selected in the environment dropdown at the top right of Postman.

### How to input these into Postman:
- **URL:** Paste the provided URL into the main address bar.
- **Method:** Select the method (GET, POST, etc.) from the dropdown next to the URL.
- **Headers Tab:** For endpoints requiring auth, go to the **Headers** tab and add:
  - Key: `X-Webhook-Secret` | Value: `{{webhook_secret}}`
  - Key: `Content-Type` | Value: `application/json` (for POST requests with JSON)
- **Params Tab:** For GET requests with query strings (like `?agency_id=X`), go to the **Params** tab to add them neatly.
- **Body Tab:** For POST requests, go to the **Body** tab, select **raw**, and click the blue **Text** dropdown to change it to **JSON**. Paste the provided JSON body there.

---

## 📁 Collection 1 — Auth

### 1.1 Login
- **Method:** `POST`
- **URL:** `{{base_url}}/api/auth/login.php`
- **Headers:** None required
- **Body (raw JSON):**
  ```json
  {
    "email": "you@agency.com",
    "password": "yourpass"
  }
  ```

### 1.2 Register
- **Method:** `POST`
- **URL:** `{{base_url}}/api/auth/register.php`
- **Headers:** None required
- **Body (raw JSON):**
  ```json
  {
    "email": "test@example.com",
    "password": "securepassword",
    "name": "Test User"
  }
  ```

### 1.3 Register from Install
- **Method:** `POST`
- **URL:** `{{base_url}}/api/auth/register-from-install`
- **Headers:** None required
- **Body (raw JSON):**
  ```json
  {
    "full_name": "Jane Smith",
    "phone": "+15551234567",
    "email": "jane@example.com",
    "password": "securepassword",
    "location_id": "LOC_123",
    "company_id": "COMP_123"
  }
  ```

---

## 📁 Collection 2 — Agency Billing

*(For all requests below, add `X-Webhook-Secret: {{webhook_secret}}` in the **Headers** tab)*

### 2.1 Fetch Agency Wallet
- **Method:** `GET`
- **URL:** `{{base_url}}/api/billing/agency_wallet.php`
- **Params (Query):**
  - `agency_id`: `X`
  - `action`: `balance`

### 2.2 Update Auto-Recharge (Agency)
- **Method:** `POST`
- **URL:** `{{base_url}}/api/billing/agency_wallet.php`
- **Body (raw JSON):**
  ```json
  {
    "action": "set_auto_recharge",
    "agency_id": "X",
    "enabled": true,
    "amount": 500,
    "threshold": 100
  }
  ```

### 2.3 Toggle Master Balance Lock
- **Method:** `POST`
- **URL:** `{{base_url}}/api/billing/agency_wallet.php`
- **Body (raw JSON):**
  ```json
  {
    "action": "set_master_lock",
    "agency_id": "X",
    "enabled": false
  }
  ```

### 2.4 Gift Credits to Subaccount
- **Method:** `POST`
- **URL:** `{{base_url}}/api/billing/agency_wallet.php`
- **Body (raw JSON):**
  ```json
  {
    "action": "gift",
    "agency_id": "X",
    "location_id": "abc12345",
    "amount": 100,
    "note": "Monthly allocation"
  }
  ```

### 2.5 Fetch Subaccount Wallet
- **Method:** `GET`
- **URL:** `{{base_url}}/api/billing/subaccount_wallet.php`
- **Params (Query):**
  - `location_id`: `X`

### 2.6 Update Auto-Recharge (Subaccount)
- **Method:** `POST`
- **URL:** `{{base_url}}/api/billing/subaccount_wallet.php`
- **Body (raw JSON):**
  ```json
  {
    "action": "set_auto_recharge",
    "location_id": "X",
    "enabled": true,
    "amount": 250,
    "threshold": 25
  }
  ```

### 2.7 Request Credits from Agency
- **Method:** `POST`
- **URL:** `{{base_url}}/api/billing/subaccount_wallet.php`
- **Body (raw JSON):**
  ```json
  {
    "action": "request_credits",
    "location_id": "X",
    "amount": 250,
    "note": "Low balance"
  }
  ```

### 2.8 List Credit Requests
- **Method:** `GET`
- **URL:** `{{base_url}}/api/billing/credit_requests.php`
- **Params (Query):**
  - `agency_id`: `X`
  - `status`: `pending` (can also be `approved` or `denied`)

### 2.9 Approve Credit Request
- **Method:** `POST`
- **URL:** `{{base_url}}/api/billing/credit_requests.php`
- **Body (raw JSON):**
  ```json
  {
    "action": "approve",
    "request_id": "REQ_DOC_ID"
  }
  ```

### 2.10 Deny Credit Request
- **Method:** `POST`
- **URL:** `{{base_url}}/api/billing/credit_requests.php`
- **Body (raw JSON):**
  ```json
  {
    "action": "deny",
    "request_id": "REQ_DOC_ID"
  }
  ```

### 2.11 Agency Transactions Ledger
- **Method:** `GET`
- **URL:** `{{base_url}}/api/billing/transactions.php`
- **Params (Query):**
  - `scope`: `agency`
  - `agency_id`: `X`
  - `page`: `1`

### 2.12 Subaccount Transactions Ledger
- **Method:** `GET`
- **URL:** `{{base_url}}/api/billing/transactions.php`
- **Params (Query):**
  - `scope`: `subaccount`
  - `location_id`: `X`
  - `month`: `2026-04` (Optional YYYY-MM format)
  - `page`: `1`

---

## 📁 Collection 3 — SMS / Webhooks

*(For all requests below, add `X-Webhook-Secret: {{webhook_secret}}` in the **Headers** tab)*

### 3.1 Send SMS (Outbound Engine)
- **Method:** `POST`
- **URL:** `{{base_url}}/webhook/send_sms.php`
- **Body (raw JSON):** *(Refer to SMS payload docs for required fields)*

### 3.2 GHL Custom Provider
- **Method:** `POST`
- **URL:** `{{base_url}}/webhook/ghl_provider.php`
- **Body (raw JSON):** *(Refer to GHL Conversation payload docs)*

### 3.3 Receive SMS (Semaphore Callback)
- **Method:** `POST`
- **URL:** `{{base_url}}/webhook/receive_sms.php`
- **Headers:** No secret needed (handled by Semaphore payload)

### 3.4 Retrieve Delivery Status (Cron)
- **Method:** `GET`
- **URL:** `{{base_url}}/webhook/retrieve_status.php`

---

## 📁 Collection 4 — General API

*(For all requests below, add `X-Webhook-Secret: {{webhook_secret}}` in the **Headers** tab)*

### 4.1 Message History
- **Method:** `GET`
- **URL:** `{{base_url}}/api/messages.php`
- **Params (Query):**
  - `direction`: `all`
  - `limit`: `50`
  - `offset`: `0`

### 4.2 Conversation List
- **Method:** `GET`
- **URL:** `{{base_url}}/api/conversations.php`
- **Params (Query):**
  - `limit`: `20`
  - `offset`: `0`

### 4.3 List Contacts
- **Method:** `GET`
- **URL:** `{{base_url}}/api/contacts.php`
- **Params (Query):**
  - `limit`: `50`
  - `offset`: `0`

### 4.4 Create Contact
- **Method:** `POST`
- **URL:** `{{base_url}}/api/contacts.php`
- **Body (raw JSON):**
  ```json
  {
    "name": "John Doe",
    "phone": "09171234567",
    "email": "johndoe@example.com"
  }
  ```

### 4.5 Global Credit Balance (Legacy)
- **Method:** `GET`
- **URL:** `{{base_url}}/api/credits.php`

### 4.6 Subaccount Detailed Credit History
- **Method:** `GET`
- **URL:** `{{base_url}}/api/get_credit_transactions.php`
- **Params (Query):**
  - `limit`: `50`
  - `month`: `2026-04` (Optional)

---

## 📁 Collection 5 — Agency Management

*(For all requests below, add `X-Webhook-Secret: {{webhook_secret}}` in the **Headers** tab)*

### 5.1 List Subaccounts
- **Method:** `GET`
- **URL:** `{{base_url}}/api/agency/get_subaccounts.php`
- **Params (Query):**
  - `agency_id`: `X`

### 5.2 Check GHL Install Status
- **Method:** `GET`
- **URL:** `{{base_url}}/api/agency/check_installs.php`
- **Params (Query):**
  - `agency_id`: `X`

### 5.3 Toggle Subaccount
- **Method:** `POST`
- **URL:** `{{base_url}}/api/agency/toggle_subaccount.php`
- **Body (raw JSON):**
  ```json
  {
    "location_id": "X",
    "active": true
  }
  ```

### 5.4 Sync Locations
- **Method:** `POST`
- **URL:** `{{base_url}}/api/agency/sync_locations.php`
- **Body (raw JSON):**
  ```json
  {
    "agency_id": "X"
  }
  ```

---

## 📁 Collection 6 — Admin Settings

*(For all requests below, add `X-Webhook-Secret: {{webhook_secret}}` in the **Headers** tab)*

### 6.1 Fetch Admin Settings
- **Method:** `GET`
- **URL:** `{{base_url}}/api/admin_settings.php`

### 6.2 Update Global Pricing
- **Method:** `POST`
- **URL:** `{{base_url}}/api/admin_settings.php`
- **Body (raw JSON):**
  ```json
  {
    "provider_cost": 0.02,
    "charged_rate": 0.05
  }
  ```

### 6.3 Unified Subaccount List (Admin)
- **Method:** `GET`
- **URL:** `{{base_url}}/api/admin_sender_requests.php`
- **Params (Query):**
  - `action`: `accounts`
  - `company_id`: `xyz` (Optional)
