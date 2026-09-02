## Backend handoff: Agency subaccounts realtime counters (rate limit + sends used + activation count)

### Goal

Make the Agency Panel → **Subaccounts** table show accurate, near-realtime values for:

- **Credit limit** (stored as `rate_limit`)
- **Sends used** (stored as `attempt_count`)
- **Activation count** (stored as `toggle_activation_count`)

And ensure the “limit reached → reset → can send again” flow is **persisted in Firestore**, not just the UI.

### Current data model (Firestore)

Canonical per-location subaccount doc:

- Collection: `ghl_tokens`
- Document ID: `<locationId>`

Fields used by the Agency Subaccounts tab:

- `toggle_enabled` (bool) — whether sending is allowed
- `rate_limit` (int) — displayed as “Credit Limit”
- `attempt_count` (int) — displayed as “Sends Used”
- `toggle_activation_count` (int) — displayed as “x/3 activations”
- `updated_at` (timestamp)

Legacy/compat display layer (kept in sync but not required by the Agency UI):

- Collection: `agency_subaccounts`
- Document ID: `<locationId>`

### What changed (backend)

Files updated:

- `backend/api/webhook/send_sms.php`
- `backend/api/agency/update_subaccount.php`

#### 1) `send_sms.php`: enforce rate limit + persist sends used

Before sending, the webhook now:

- Reads `ghl_tokens/<locationId>` for `rate_limit` and `attempt_count`
- **Blocks** the request with **HTTP 403** when `attempt_count >= rate_limit`
  - Response includes `error=rate_limit_reached`
- Otherwise, it **atomically reserves** an attempt by incrementing `attempt_count` in a Firestore transaction
  - This prevents parallel requests from exceeding the limit
  - If the provider fails, the attempt still counts (anti-abuse; avoids unlimited retries)

This makes “Sends Used” update in realtime because the Agency UI subscribes to `ghl_tokens`.

#### 2) `update_subaccount.php`: persist reset + return updated counters

The agency update endpoint now:

- Calls `validate_api_request()` (same protection as `get_subaccounts.php`)
- Tracks the computed values:
  - `attempt_count` (0 when `reset_counter=true`)
  - `toggle_activation_count` (incremented on OFF → ON, max 3)
- Mirrors these values into `agency_subaccounts/<locationId>` for any legacy readers
- Returns a richer JSON payload:
  - `toggle_enabled`, `rate_limit`, `attempt_count`, `toggle_activation_count`

### Expected behavior

- **Credit limit updates**:
  - Changing “Credit Limit” writes `rate_limit` to `ghl_tokens` and is reflected in the Agency UI immediately.
- **Sends used increments**:
  - Each successful webhook call reserves \(+1\) on `attempt_count` in `ghl_tokens`.
- **If limit is reached**:
  - Webhook responds **403** with `error=rate_limit_reached` and no SMS is sent.
- **Reset flow**:
  - Agency clicks reset → `attempt_count` is set to 0 in Firestore → location can send again.

### Verification checklist

- **Increment**:
  - Trigger a send for a location and confirm `ghl_tokens/<locationId>.attempt_count` increments by 1.
- **Block at limit**:
  - Set `rate_limit=1`, `attempt_count=1` then attempt send → expect HTTP 403 and `error=rate_limit_reached`.
- **Reset**:
  - Call `POST /api/agency/update_subaccount.php` with `reset_counter=true` → expect `attempt_count=0`.
- **Activation count**:
  - Toggle OFF → ON repeatedly and confirm:
    - `toggle_activation_count` increments to max 3
    - The 4th enable returns HTTP 403 with `status=limit_reached`.

