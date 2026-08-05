# Backend Handoff — Missing Endpoints Detail & Implementation Guide

**Target Repository:** `https://github.com/nola-repo/NOLA-SMS-Pro`

This document outlines the detailed implementation requirements for the backend endpoints missing from the main remote repository. These must be scaffolded and implemented for the React frontends (Admin, Agency, User) to operate without 404/500 errors.

---

## 1. Agency Scope & Billing Architecture

The frontends have moved to a dual-wallet, agency-based ecosystem. You must establish the `api/agency/` folder.

### `api/agency/get_subaccounts.php`
*   **Purpose:** Fetches a list of all subaccounts (locations) permanently linked to a specific parent agency.
*   **Method:** `GET ?agency_id={agency_id}`
*   **Logic:**
    1. Query the Firestore `integrations` collection.
    2. Filter where `agency_id == fetch_from_request()`.
    3. Iterate the snapshot and map the documents to extract necessary fields.
*   **Response payload:**
    ```json
    {
      "subaccounts": [
        {
          "location_id": "abc12345",
          "location_name": "J&K Auto Center",
          "credit_balance": 450,
          "address": "123 Street"
        }
      ]
    }
    ```

### `api/agency/ghl_sso_decrypt.php`
*   **Purpose:** Decrypts GoHighLevel Single Sign-On (SSO) payload from the agency app rendering context.
*   **Method:** `POST`
*   **Payload:** `{ "key": "SSO_ENCRYPTED_STRING" }`
*   **Logic:**
    1. Retrieve the `GHL_SSO_KEY` from the environment.
    2. Use standard AES-256-CBC decryption.
    3. Return the decrypted JSON containing the logged-in agency admin or subaccount context.
*   **Response payload:**
    ```json
    { "success": true, "data": { "companyId": "...", "userId": "..." } }
    ```

### Billing Endpoints (`api/billing/*.php`)
*(Note: These map exactly to the detailed requirements already established in `backend_handoff_billing.md`)*
*   **`agency_wallet.php`**: `GET` (returns `balance`, `auto_recharge` states) and `POST` actions (`gift`, `set_auto_recharge`, `set_master_lock`). MUST query/update the new `agency_wallet` Firestore collection.
*   **`subaccount_wallet.php`**: `GET` and `POST` directly to the specified `integrations/{location_id}` document for balance reading.
*   **`credit_requests.php`**: `GET` (all pending requests via query) and `POST` (action: 'approve' or 'deny'). Updates `credit_requests` collection.
*   **`transactions.php`**: `GET ?scope={agency|subaccount}`. Queries `credit_transactions` collection filtered by `wallet_scope`.

---

## 2. Admin Panel Endpoints (`api/`)

The React Admin dashboard requires top-level data access directly to root collections to manage all agencies and system configurations.

### `api/admin_agencies.php`
*   **Purpose:** Lists actual agency applications rather than mapping subaccount users. It identifies the root agencies that install the SaaS.
*   **Method:** `GET`
*   **Logic:**
    1. Query the `ghl_tokens` collection in Firestore.
    2. Filter where `appType == "agency"`. 
    3. Construct the response from the found documents by extracting `companyId` (or `company_id`) and `company_name`. Do NOT include individual end-users unless explicitly required; list the agencies directly.
*   **Response payload:**
    ```json
    {
      "agencies": [
        {
          "id": "0OYXPGWM9ep2I37dgxAo",
          "name": "NOLA CRM",
          "created_at": "April 14, 2026",
          "status": "active"
        }
      ]
    }
    ```

### `api/admin_users.php`
*   **Purpose:** The global CRM view of all configured subaccounts across the platform, regardless of parent agency.
*   **Method:** `GET`
*   **Logic:**
    1. Query `integrations` collection.
    2. Limit/Paginate.
    3. Include `credit_balance` and derived `agency_name` if possible.
*   **Response:** Array of subaccount objects globally.

### `api/admin_auth.php` (or `admin_login.php`)
*   **Purpose:** Specifically authenticates system super-admins interacting with the Admin UI dashboard.
*   **Method:** `POST`
*   **Payload:** `{ "email": "...", "password": "..." }`
*   **Logic:** Validate Firebase auth / Firestore credentials and return a signed JWT configured with `role: "admin"`.

### `api/admin_settings.php`
*   **Purpose:** Edits or reads global variable overrides (e.g., universal billing prices, default system tags, base sender IPs).
*   **Method:** `GET` / `POST`
*   **Logic:**
    1. Reads/writes to a unified `system_settings/core` Firestore document.

### `api/admin_sender_requests.php`
*   **Purpose:** The queue for admins to approve alphanumeric sender identities requested by subaccounts.
*   **Method:** `GET` (list pending), `POST` (approve/reject).
*   **Logic:** Queries `sender_requests` collection. On approve, writes the approved sender ID string back to `integrations/{location_id}.approved_sender_id`.

---

## 3. Advanced Features/Integrations

### `api/account-sender.php`
*   **Purpose:** Retrieves the active Sender ID for a location so the frontend knows what label is broadcasting SMS.
*   **Method:** `GET ?location_id={loc}`
*   **Logic:** Simple read from `integrations` returning `{ "sender_id": "NOLA", "verified": true }`.

### `api/templates.php`
*   **Purpose:** CRUD for SMS Templates.
*   **Method:** `GET` / `POST` / `DELETE`
*   **Logic:** Validates against `templates` subcollection located safely under `integrations/{location_id}/templates`.

### `api/notification-settings.php`
*   **Purpose:** Sets email preferences for balance exhaustion or SMS delivery failure metrics.
*   **Method:** `GET ?location_id={loc}`, `POST`
*   **Logic:** Updates `integrations/{loc}.notification_preferences` object mapping (`{ "on_low_balance": true, "email_recipient": "hello@test.com" }`).
