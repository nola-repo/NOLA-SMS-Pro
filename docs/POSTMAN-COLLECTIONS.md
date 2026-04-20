# API Collections & Endpoints Guide

This document organizes the core NOLA SMS Pro endpoints into logical collections (Auth, Billing, SMS), making it easy to test via Postman or integrate into new frontends. It explains what each endpoint processes, required scopes/headers, and expected payloads.

---

## 📁 1. Auth Collection
These endpoints manage user and agency authentication, generating the JSON Web Tokens (JWT) required to securely access other APIs.

### 1.1. Login
- **URL:** `https://smspro-api.nolacrm.io/api/auth/login.php`
- **Method:** `POST`
- **Auth Required:** None
- **What it processes:** 
  Accepts an email and password, verifies them against the `users` Firestore collection, and generates an `HS256` signed JWT. This token encodes the user's ID (`sub`), role (e.g., `agency`), and `company_id`.
- **Payload Example:**
  ```json
  {
    "email": "admin@agency.com",
    "password": "securepassword"
  }
  ```

### 1.2. GHL OAuth Callback
- **URL:** `https://smspro-api.nolacrm.io/oauth/callback`
- **Method:** `GET`
- **Auth Required:** None (Triggered by GoHighLevel)
- **What it processes:** 
  The redirect hook when an agency installs the NOLA application into a subaccount. It takes the `?code=` URL parameter passed by GHL, exchanges it for an Access/Refresh token pair, and writes those tokens into the `ghl_tokens/{locationId}` document to allow background sync.

---

## 📁 2. Billing Collection
These endpoints manage the dual-wallet system, allowing independent credit tracking for Agencies and their enclosed Subaccounts. All Billing endpoints require a valid JWT passed in the Authorization header.

*Header requirement for all endpoints below: `Authorization: Bearer <your_jwt_token>`*

### 2.1. Agency Wallet
- **URL:** `https://smspro-api.nolacrm.io/api/billing/agency_wallet.php`
- **GET Request:** Retrieves the current credit balance and auto-recharge settings for the agency. Automatically locates the `agency_id` based on the JWT claims.
- **POST Request (Action: set_auto_recharge):**
  Updates the `agency_wallet` document thresholds.
  ```json
  { "action": "set_auto_recharge", "enabled": true, "amount": 500, "threshold": 100 }
  ```

### 2.2. Subaccount Wallet
- **URL:** `https://smspro-api.nolacrm.io/api/billing/subaccount_wallet.php`
- **GET Request:** Retrieves the subaccount's specific `credit_balance` from the `integrations/{location_id}` document. Requires `?location_id=XYZ`.
- **POST Request (Action: request_credits):**
  Creates a new document in the `credit_requests` collection tagged as `pending`.
  ```json
  { "action": "request_credits", "location_id": "XYZ", "amount": 250, "note": "Low balance" }
  ```

### 2.3. Credit Requests
- **URL:** `https://smspro-api.nolacrm.io/api/billing/credit_requests.php`
- **GET Request:** Lists all pending/approved/denied requests for the agency. Pass `?agency_id=XYZ&status=pending`.
- **POST Request (Action: approve):**
  Executes an atomic dual-transaction. It deducts credits from the Agency Wallet, adds credits to the Subaccount Wallet, updates the request to `approved`, and writes two records to the `credit_transactions` ledger simultaneously. If the agency lacks funds, the entire transfer rolls back and returns a 400 error.
  ```json
  { "action": "approve", "request_id": "REQ_123" }
  ```

### 2.4. Transactions Ledger
- **URL:** `https://smspro-api.nolacrm.io/api/billing/transactions.php`
- **GET Request:** Provides paginated visibility into the `credit_transactions` collection. 
  - **Scopes:** Pass `?scope=agency&agency_id=XYZ` to see top-ups and subaccount gifts. Pass `?scope=subaccount&location_id=XYZ` to see SMS deductions and received gifts.

---

## 📁 3. SMS Collection
These endpoints handle the core routing of outbound and inbound messages, directly interfacing with the Semaphore API and GoHighLevel.

*Header requirement for outbound hooks: `X-Webhook-Secret: <your_secret>`*

### 3.1. Send SMS (Main Engine)
- **URL:** `https://smspro-api.nolacrm.io/webhook/send_sms.php`
- **Method:** `POST`
- **What it processes:** 
  Triggered by GHL Workflows or the Web UI. It reads the recipient numbers, deducts the exact required credits from *both* the Agency Wallet and the Subaccount Wallet (blocking the send if either wallet is empty), pushes the payload to Semaphore, and writes the status to the `messages` and `sms_logs` Firestore collections.

### 3.2. GHL Provider (Chat Window)
- **URL:** `https://smspro-api.nolacrm.io/webhook/ghl_provider.php`
- **Method:** `POST`
- **What it processes:** 
  A specialized clone of `send_sms` customized strictly for the GHL "Custom Conversation Provider" payload format. Triggered when someone types directly into the GHL interactive SMS chat bubble. Uses identically enforced dual-deduction limits.

### 3.3. Receive Inbound SMS
- **URL:** `https://smspro-api.nolacrm.io/webhook/receive_sms.php`
- **Method:** `POST`
- **What it processes:** 
  The webhook endpoint registered directly inside the Semaphore Developer Portal. When a client replies to a text, Semaphore pushes the reply here. The script writes to `inbound_messages` and uses the GHL API to inject the reply back into the correct contact's Conversation tab.

### 3.4. Retrieve Delivery Status (Cron)
- **URL:** `https://smspro-api.nolacrm.io/webhook/retrieve_status.php`
- **Method:** `GET`
- **What it processes:** 
  Orchestrated by Google Cloud Scheduler to run periodically. It scrapes `sms_logs` for any messages stuck in `Queued` or `Sending`, hits the Semaphore status API for updates, and updates the Firestore records to `Delivered` or `Failed`.
