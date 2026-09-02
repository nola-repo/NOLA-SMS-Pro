## Backend handoff: Credit logic changes (sender tiers)

### Goal

Ensure **all outbound SMS sends are charged consistently**:

- **Free trial**: increment `free_usage_count` only (no `credit_balance` deduction)
- **Paid**: deduct from `credit_balance` once the free quota is exhausted
- **Custom sender** selection affects **delivery provider only** (Semaphore API key + sendername), **not** whether credits are charged

### What changed

File updated:

- `backend/api/webhook/send_sms.php`

Key behavior changes:

- **Removed “Tier 1 = no deduction”** behavior.
  - Previously, if `approved_sender_id` + `nola_pro_api_key` were set and the request was treated as custom-sender, the backend could skip system credit charging.
- Credit charging now depends only on trial quota:
  - If `free_usage_count + recipients <= free_credits_total`:
    - Update Firestore doc `integrations/{ghl_<locationId>}` → increment `free_usage_count`
    - Do **not** call `CreditManager::deduct_credits()`
  - Else:
    - Call `CreditManager::deduct_credits()` against the same `integrations/{ghl_<locationId>}` doc
    - If insufficient, return **HTTP 403** with `error=insufficient_credits`
- **Sender selection still works**:
  - If request `sendername` equals `approved_sender_id` and `nola_pro_api_key` exists → send via customer’s Semaphore API key (custom provider)
  - Otherwise → send via system Semaphore API key + system sender IDs

### Where charging happens

Endpoint:

- `POST backend/api/webhook/send_sms.php`

Firestore fields involved (document: `integrations/ghl_<locationId>`):

- `free_usage_count` (int)
- `free_credits_total` (int, default 10)
- `credit_balance` (int)
- `approved_sender_id` (string|null) — delivery selection only
- `nola_pro_api_key` (string|null) — delivery selection only

### Deployment notes

- This is a **backend-only change**.
- No schema migration required (fields already exist).

### Verification checklist

- **Trial send (within quota)**:
  - Send to \(N\) recipients where `free_usage_count + N <= free_credits_total`
  - Expect:
    - `free_usage_count` increases by \(N\)
    - `credit_balance` unchanged
- **Paid send (quota exhausted)**:
  - Ensure `free_usage_count` already at/over quota
  - Send a message
  - Expect:
    - `credit_balance` decreases by `CreditManager::calculateRequiredCredits(message, N)`
    - A record appears in `credit_transactions` with `type=deduction`
- **Insufficient paid credits**:
  - With quota exhausted and `credit_balance < required_credits`
  - Expect:
    - HTTP 403
    - response includes `"error": "insufficient_credits"`

### Rollback

Revert commit containing changes to `backend/api/webhook/send_sms.php`.

