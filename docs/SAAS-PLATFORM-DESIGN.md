# NOLA SMS Pro — SaaS Platform Design

This document covers Firestore schema, send flow, API structure, architecture, and security for evolving NOLA SMS Pro from a webhook engine into a full SaaS SMS platform.

---

## 1. Firestore Schema

### 1.1 Collections Overview

| Collection | Purpose |
|------------|---------|
| `accounts` | Tenant/organization; holds approval, balance, settings |
| `account_users` | Membership: which users belong to which account + role |
| `users` | User profile (email, name); auth can be Firebase Auth or custom |
| `sender_ids` | Sender IDs per account; approval status |
| `credit_ledger` | Immutable log of every credit add/deduct (per account) |
| `messages` | Outbound/inbound SMS; links to account, cost, status |
| `templates` | Reusable message templates per account |
| `settings` | Optional: key-value settings per account (e.g. timezone, defaults) |

Legacy: Keep `sms_logs` and `inbound_messages` for backward compatibility during migration, or gradually map them into `messages` with an `account_id` field.

---

### 1.2 `accounts`

Document ID: `{accountId}` (e.g. UUID or slug).

```text
accounts/{accountId}
├── name: string
├── status: "pending" | "approved" | "rejected"
├── credit_balance: number          // Current balance (integer; 1 credit = 160 chars)
├── currency: string                // e.g. "PHP"
├── created_at: timestamp
├── updated_at: timestamp
├── approved_at: timestamp | null
├── rejected_reason: string | null
├── ghl_location_id: string | null  // Optional GHL integration
├── ghl_api_key_encrypted: string | null
└── metadata: map (optional)
```

- **Rules**: Only server (Cloud Run) or admins write; clients never write `credit_balance` directly (only via server-side transactions).

---

### 1.3 `account_users`

Document ID: `{accountId}_{userId}` or subcollection `accounts/{accountId}/members/{userId}`.

```text
account_users/{accountId}_{userId}
├── account_id: string
├── user_id: string                 // Firebase Auth UID or your user id
├── role: "owner" | "admin" | "member" | "billing"
├── created_at: timestamp
├── invited_by: string | null
└── status: "active" | "suspended"
```

- Use for authorization: "Can this user perform action X in this account?"

---

### 1.4 `users`

Document ID: `{userId}` (match Firebase Auth UID if using Firebase Auth).

```text
users/{userId}
├── email: string
├── display_name: string
├── phone: string | null
├── created_at: timestamp
├── updated_at: timestamp
├── last_login_at: timestamp | null
└── preferences: map (optional)
```

---

### 1.5 `sender_ids`

Subcollection recommended: `accounts/{accountId}/sender_ids/{senderId}`.

```text
accounts/{accountId}/sender_ids/{senderId}
├── sender_id: string               // e.g. "NOLACRM"
├── status: "pending" | "approved" | "rejected"
├── requested_at: timestamp
├── approved_at: timestamp | null
├── rejected_reason: string | null
├── created_at: timestamp
└── updated_at: timestamp
```

- Sending is allowed only when `status == "approved"` and the sender belongs to the account.

---

### 1.6 `credit_ledger`

Subcollection: `accounts/{accountId}/credit_ledger/{transactionId}`.

```text
accounts/{accountId}/credit_ledger/{transactionId}
├── type: "top_up" | "deduction" | "refund" | "adjustment"
├── amount: number                  // Positive for top_up/refund, negative for deduction
├── balance_after: number           // Snapshot after this tx (for auditing)
├── reference_type: "message" | "manual" | "payment"
├── reference_id: string | null     // e.g. message_id or payment_id
├── created_at: timestamp
├── created_by: string | null       // user_id or "system"
└── note: string | null
```

- Append-only; never update or delete. Balance is derived from latest `balance_after` or computed by summing (with index on `created_at`).

---

### 1.7 `messages`

Single collection with `account_id` for multi-tenant querying; or subcollection `accounts/{accountId}/messages/{messageId}`.

```text
messages/{messageId}
├── account_id: string
├── direction: "outbound" | "inbound"
├── recipient: string               // Phone number
├── sender_id: string               // Approved sender ID used
├── body: string
├── segments: number                // ceil(len(body)/160)
├── credits_used: number
├── semaphore_message_id: string | null
├── status: "Queued" | "Pending" | "Sent" | "Failed" | "Delivered" | ...
├── created_at: timestamp
├── created_by: string | null       // user_id
├── source: "api" | "web" | "mobile" | "ghl" | "webhook"
├── metadata: map (optional)
└── error_message: string | null
```

- **Indexes**: `account_id` + `created_at` (desc), `account_id` + `status`, optionally `recipient` + `created_at` for lookups.

---

### 1.8 `templates`

Subcollection: `accounts/{accountId}/templates/{templateId}`.

```text
accounts/{accountId}/templates/{templateId}
├── name: string
├── body: string
├── created_at: timestamp
├── updated_at: timestamp
└── created_by: string | null
```

---

## 2. Updated Send-SMS Flow (Account-Aware, Credit-Controlled)

### 2.1 Credit and segmentation rules

- 1 SMS credit = 160 characters (GSM-7). For Unicode, treat as 70 chars per segment (or use Semaphore's segment count if available).
- Segments = `ceil(character_count / 160)` (or 70 for Unicode).
- Total credits for one send = `segments * number_of_recipients`.

### 2.2 Flow (high level)

1. **Resolve identity**
   - From JWT or API key: resolve `account_id` and `user_id`.
   - If GHL-only path: resolve `account_id` from webhook payload or linked GHL location.

2. **Validate account**
   - Load `accounts/{accountId}`.
   - Require `status == "approved"`.
   - If rejected/pending, return `403` with message (e.g. "Account pending approval").

3. **Validate sender ID**
   - Ensure requested `sender_id` exists under `accounts/{accountId}/sender_ids/{senderId}` and `status == "approved"`.
   - If not, return `400` (e.g. "Sender ID not approved or not found").

4. **Validate recipients and body**
   - Normalize and validate phone numbers (existing `clean_numbers` logic).
   - Validate body length and compute `segments` and `credits_needed = segments * recipient_count`.

5. **Reserve and deduct credits (Firestore transaction)**
   - In a single Firestore transaction:
     - Read `accounts/{accountId}` → `credit_balance`.
     - If `credit_balance < credits_needed`: abort transaction, return `402` "Insufficient credits. Please top up."
     - Decrement `credit_balance` by `credits_needed`.
     - Write new document to `accounts/{accountId}/credit_ledger/{transactionId}` (type `deduction`, amount `-credits_needed`, `balance_after`, `reference_type: "message"`, `reference_id: messageId`).
     - Create `messages/{messageId}` (or under account subcollection) with `credits_used`, `status: "Queued"`, etc.
   - Commit transaction.

6. **Call Semaphore**
   - Send SMS via Semaphore API (existing logic).
   - Update message document with `semaphore_message_id`, `status` from response.
   - On Semaphore failure: run a compensating transaction (refund credits, set message `status: "Failed"`, add ledger entry type `refund`).

7. **Response**
   - Return success with `message_id`, `credits_used`, `balance_after` (optional).

### 2.3 Pseudocode (transaction)

```text
function send_sms(account_id, user_id, sender_id, recipients[], body):
  credits_needed = ceil(len(body)/160) * size(recipients)
  message_id = generate_id()

  run Firestore transaction:
    account = get accounts/{account_id}
    if account.status != "approved": throw 403
    if account.credit_balance < credits_needed: throw 402 "Please top up credits"
    account.credit_balance -= credits_needed
    set accounts/{account_id} with account
    write credit_ledger entry (deduction, -credits_needed, balance_after, message_id)
    write messages/{message_id} (account_id, credits_used, status: Queued, ...)
  end transaction

  response = call_semaphore_api(recipients, body, sender_id)
  update messages/{message_id} (semaphore_message_id, status from response)
  if response failed:
    run refund transaction (credit_ledger refund, account.credit_balance += credits_needed)
  return { message_id, credits_used, balance_after }
```

---

## 3. Secure Endpoint Restructuring for SaaS

### 3.1 Auth model

- **Web / mobile app**: JWT after login (Firebase Auth custom token + your backend, or your own JWT issuer). Each request: `Authorization: Bearer <jwt>`; backend resolves `account_id` and `user_id` from JWT (and optionally `account_users`).
- **Optional GHL integration**: Separate path using webhook secret + `account_id` (or GHL location id) in payload/header; server maps GHL location to `account_id` and uses that for credits/sender IDs.
- **API keys (optional)**: Per-account API key stored hashed; sent as `X-API-Key` or `Authorization: ApiKey <key>`; resolve to `account_id` and treat as "system" user for ledger.

### 3.2 Endpoint layout

- **Base path**: `/api/v1/` (versioned).

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST   | `/api/v1/auth/register` | None | Register; create user + pending account or link to invite |
| POST   | `/api/v1/auth/login`    | None | Login; return JWT (or Firebase custom token) |
| GET    | `/api/v1/me`            | JWT  | Current user + accounts |
| GET    | `/api/v1/accounts/{id}` | JWT  | Account details (balance, status); require membership |
| PATCH  | `/api/v1/accounts/{id}/settings` | JWT | Update settings |
| GET    | `/api/v1/accounts/{id}/sender-ids` | JWT | List sender IDs (status) |
| POST   | `/api/v1/accounts/{id}/sender-ids` | JWT | Request new sender ID |
| POST   | `/api/v1/accounts/{id}/messages`   | JWT | Send single/bulk (body: recipients[], body, sender_id) |
| GET    | `/api/v1/accounts/{id}/messages`    | JWT | List messages (filter, paginate) |
| GET    | `/api/v1/accounts/{id}/messages/{messageId}` | JWT | Single message |
| DELETE | `/api/v1/accounts/{id}/messages/{messageId}` | JWT | Delete message (soft delete or with confirmation) |
| POST   | `/api/v1/accounts/{id}/templates`  | JWT | Insert template |
| GET    | `/api/v1/accounts/{id}/templates`  | JWT | List templates |
| GET    | `/api/v1/accounts/{id}/credits`    | JWT | Balance + optional ledger summary |
| POST   | `/api/v1/accounts/{id}/credits/top-up` | JWT | Request top-up (creates intent or admin adjustment) |

- **Internal / legacy** (not exposed to end users as primary API):
  - `POST /webhook/send_sms` — Keep for GHL-only flows; validate webhook secret, resolve account from payload, then run same credit + send logic.
  - `POST /webhook/receive_sms` — Inbound; store in `messages` with `direction: inbound`, attach to account by phone/sender or default routing.
  - `GET /webhook/retrieve_status` — Cron; update `messages` status from Semaphore.

### 3.3 Response and errors

- Success: `200`/`201` with JSON body.
- Errors: `4xx`/`5xx` with body e.g. `{ "error": "code", "message": "Human message" }`.
- `401` Unauthorized (missing/invalid token).
- `402` Payment Required (insufficient credits).
- `403` Forbidden (account not approved, or no permission).

---

## 4. Scalable Architecture (Web, Mobile, Marketplace)

### 4.1 Components

```text
[ Web App ]     [ Mobile App ]     [ GHL / Partners ]
      |                |                    |
      +----------------+--------------------+
                       |
                 [ API Gateway / Load Balancer ]
                       |
                 [ Cloud Run: NOLA SMS Pro API ]
                       |
      +---------------+---------------+
      |               |               |
[ Firestore ]  [ Semaphore API ]  [ Firebase Auth (optional) ]
```

- **Cloud Run**: Same PHP 8.2 + Apache service; add routes under `/api/v1/` (e.g. via front controller or Apache rewrite). Scale by request; no state on instance.
- **Web app**: Separate frontend (React/Next/Vue) hosted on Firebase Hosting, Cloud Run, or static bucket; calls `https://smspro-api.nolacrm.io/api/v1/*` with JWT.
- **Mobile app**: Same API; JWT from login (or Firebase Auth). Optional: deep links, push for delivery reports.
- **Marketplace / white-label**: Same API; each partner gets an account; optional per-account API keys and custom branding (handled in frontend/settings).

### 4.2 GHL integration (optional)

- **Two-way contact sync**: Webhook from GHL on contact create/update → your API creates/updates a "contact" in Firestore under the account (optional collection `accounts/{id}/contacts`). Outbound: from your UI or API, optionally "sync to GHL" by calling GHL API. Map `account_id` ↔ GHL location/sub-account.
- **Trigger send from GHL**: Keep `POST /webhook/send_sms` with secret; payload includes account identifier (or GHL location id); backend resolves account, then runs same send flow (credits, sender ID, Semaphore).

### 4.3 Standalone mode

- No GHL fields required. Registration creates `users` + `accounts` (status `pending`). Admin approves account and (optionally) sender IDs. User tops up credits and sends via UI or API.

---

## 5. Production Security Best Practices

### 5.1 Auth and secrets

- Store **SEMAPHORE_API_KEY**, **WEBHOOK_SECRET**, JWT signing keys, and DB credentials in **Secret Manager** (or env vars set in Cloud Run, not in repo).
- Use **Firebase Auth** or a dedicated auth service; validate JWT on every API request; short-lived access tokens, refresh token rotation.
- Hash **API keys** (e.g. SHA-256); never log or return raw keys.

### 5.2 Network and transport

- Enforce **HTTPS only** (Cloud Run default).
- **CORS**: Restrict origins to your web app and mobile app domains; avoid `*` in production.

### 5.3 Input and output

- **Validate and sanitize** all inputs (phone numbers, message body, template name); reject oversized payloads.
- **Rate limiting**: Per account and per user (e.g. Cloud Run + Cloud Armor, or app-level counters in Firestore/Redis).
- **Logging**: Do not log full message bodies or tokens; log account_id, user_id, message_id, and credit deltas for audit.

### 5.4 Data and IAM

- **Firestore**: Use **security rules** so clients cannot read/write `accounts`, `credit_ledger`, or other sensitive collections; only the backend (service account) can. If clients read Firestore directly (e.g. for real-time UI), expose a minimal subset via rules and still enforce credits/send only on the backend.
- **IAM**: Cloud Run service account with least privilege (Firestore, Secret Manager); no project-wide owner/editor.

### 5.5 Operations

- **Audit**: Enable Cloud Audit Logs for Firestore and Admin API; retain and review.
- **Alerts**: Monitor failed sends, 402 rate, and unusual credit consumption; alert on anomalies.
- **Backups**: Firestore automated backups; consider export to GCS for long-term retention.

---

## 6. Implementation Order Suggestion

1. **Phase 1 — Schema and credits**
   - Add Firestore collections (`accounts`, `account_users`, `credit_ledger`, `sender_ids`); migrate or dual-write existing `sms_logs` into `messages` with `account_id`.
   - Implement credit top-up (manual or payment webhook) and ledger writes.

2. **Phase 2 — Send flow**
   - New internal "send_sms_for_account" function: account/sender validation + Firestore transaction (deduct credits, write ledger, create message) + Semaphore call + refund on failure.
   - Expose `POST /api/v1/accounts/{id}/messages` behind JWT (or API key); keep `/webhook/send_sms` for GHL using same internal function.

3. **Phase 3 — Auth and multi-user**
   - Add Firebase Auth (or custom JWT); registration/login; `account_users` and role checks on every request.

4. **Phase 4 — UI and features**
   - Web app: login, dashboard, send single/bulk, templates, credit balance, top-up.
   - Optional: mobile app, GHL two-way sync, marketplace wrappers.

---

*End of design document. Use this as the single source of truth for schema, flows, and security when implementing NOLA SMS Pro SaaS.*
