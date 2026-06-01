# Backend Handoff — `credit_balance` on `users/{userId}`

**Audience:** Backend team (`C:\Users\User\nola-sms-pro-backend` or equivalent deploy tree)  
**Scope:** Handoff only — apply changes in the backend repo; no frontend API contract change.  
**Scanned from:** Registration and wallet code paths (May 2026).

---

## Summary

Every **normal user** document in Firestore `users/{userId}` should include a root-level **`credit_balance`** integer (paid wallet credits), default **`0`** at registration time.

- **`credit_balance`** = paid wallet credits (SMS sends with approved/custom sender, top-ups, agency transfers).
- **`free_credits_total` / `free_usage_count`** on `integrations/{ghl_*}` remain the **install trial** bucket (10 free shared-sender credits). Do not conflate these fields.

`CreditManager` already prefers `users/{id}.credit_balance` when a user is linked via `active_location_id`, and falls back to legacy `integrations/{ghl_*}.credit_balance`. New registrations must seed the **users** wallet so deductions and UI reads are consistent without relying on integration-only balances.

---

## Scan Findings (current behavior)

| Location | Finding |
|----------|---------|
| `api/auth/register.php` | Creates `users` or `agency_users` via `->add($data)`. The `$data` array has **no** `credit_balance`. Normal `role: "user"` signups therefore omit the field. |
| `api/auth/register_from_install.php` — **new user** (§2b, ~L577–613) | `$userData` written with `->set($userData)` has **no** `credit_balance`. Marketplace install signups omit the field. |
| `api/auth/register_from_install.php` — **existing / link** (§2a, ~L404–456) | `$updates` merged into the user doc has **no** `credit_balance`. Reinstall / link flows do not backfill missing wallets. |
| `api/auth/register_from_install.php` — **agency install** | Writes to `agency_users`, not `users`. **Out of scope** for this handoff (agency wallet uses `balance` on `agency_users` / `agency_wallet`; see CreditManager). |
| `api/agency/install_provision.php` (~L239–249) | On **new** `integrations/{ghl_*}` only, sets `credit_balance: 0` (plus `free_credits_total: 10`). Does **not** create or update `users/{id}.credit_balance`. |
| `api/install_helpers.php` (~L1218–1317) | Finalize/provision paths set `integrations/{ghl_*}.credit_balance` only when **missing** on the integration doc — not on `users/{id}`. |
| `api/services/CreditManager.php` | `deduct_subaccount_only` reads/writes `credit_balance` on the resolved subaccount doc (`users/{id}` when `active_location_id` matches, else legacy `integrations/{ghl_*}`). Missing field is treated as `0` at read time but should still be persisted at registration. |
| `tmp/migrate_wallets.php` | One-time copy `integrations.credit_balance` → `users.credit_balance` for **existing** linked users. Not a substitute for registration defaults on new signups. |

---

## Required Backend Behavior

### 1. Normal registration — `POST /api/auth/register.php`

When `$role === 'user'` and the document is written to the **`users`** collection, include:

```php
'credit_balance' => 0,
```

in the `$data` array (alongside `firstName`, `email`, etc.).

**Do not** add `credit_balance` to **`agency_users`** documents in this endpoint unless product explicitly extends agency wallet shape (`balance` is the agency field today).

### 2. Marketplace install — new user — `POST /api/auth/register-from-install`

In the **§2b NEW ACCOUNT** block, add to `$userData` before `$newUserDoc->set($userData)`:

```php
'credit_balance' => 0,
```

Applies to location-level installs (`role: "user"`). Agency-install branch that writes `agency_users` is unchanged.

### 3. Marketplace install — existing / link — same file, **§2a**

Before `$usersRef->document($existingId)->set($updates, ['merge' => true])`, backfill only when absent:

```php
if (!array_key_exists('credit_balance', $existingDoc)) {
    $updates['credit_balance'] = 0;
}
```

Use **`array_key_exists`**, not `empty()` or `??`, so a legitimate balance of **`0`** is not overwritten and existing balances (e.g. **`169`**) are preserved.

### 4. Must NOT do

| Rule | Reason |
|------|--------|
| Do **not** overwrite existing `credit_balance` on link/reinstall | Protects migrated or purchased balances. |
| Do **not** set `credit_balance` on `agency_users` in this task | Agency wallet uses `balance`; separate product decision. |
| Do **not** remove or repurpose `free_credits_total` / `free_usage_count` on integrations | Trial credits stay integration-scoped per `backend_handoff_install_credits.md`. |
| Do **not** change frontend API response shapes for this task | Firestore document shape only. |

---

## Suggested Patches (reference)

### `api/auth/register.php`

Inside `$data` (before `$collection = ...`), for user role only:

```php
if ($role === 'user') {
    $data['credit_balance'] = 0;
}
```

Alternatively inline `'credit_balance' => 0` only when building data for `users` (if agency branch uses a separate array).

### `api/auth/register_from_install.php`

**Existing user** — after building `$updates`, before merge set:

```php
if (!array_key_exists('credit_balance', $existingDoc)) {
    $updates['credit_balance'] = 0;
}
```

**New user** — in `$userData`:

```php
'credit_balance' => 0,
```

---

## Public Data Shape

### Firestore `users/{userId}` (normal users)

| Field | Type | Default | Notes |
|-------|------|---------|--------|
| `credit_balance` | integer | `0` | Paid wallet; 1 credit ≈ 1 SMS segment (160 GSM-7 chars). |
| `free_credits_total` | — | — | **Not** on `users`; lives on `integrations/{ghl_*}`. |
| `free_usage_count` | — | — | **Not** on `users`; lives on `integrations/{ghl_*}`. |

### Unchanged collections (reference)

| Collection | Wallet field | Notes |
|------------|--------------|--------|
| `integrations/{ghl_*}` | `credit_balance` (legacy), `free_credits_total`, `free_usage_count` | Install finalize may still set integration `credit_balance` when missing; trial fields unchanged. |
| `agency_users/{id}` | `balance` (not `credit_balance`) | Agency bulk/install flows unchanged. |

### Runtime consumers (no change required for this task)

- `api/services/CreditManager.php` — subaccount deductions / top-ups
- `api/account.php` — may surface balance via resolved wallet
- `api/credits.php`, `api/billing/*` — existing `?? 0` fallbacks remain valid

---

## Test Plan

Execute against a staging Firestore project after deploying backend changes.

| # | Action | Expected `users/{id}` |
|---|--------|-------------------------|
| 1 | Register new user via `POST /api/auth/register.php` with `role: "user"` | `credit_balance` **=== 0** |
| 2 | Register new sub-account via `POST /api/auth/register-from-install` (valid `install_token`, new email) | `credit_balance` **=== 0** |
| 3 | Link/reinstall with existing user doc **without** `credit_balance` | Field added, **=== 0** |
| 4 | Link/reinstall with existing user doc **`credit_balance: 169`** | Stays **169** |
| 5 | Agency Marketplace install / `register.php` with `role: "agency"` | Still writes **`agency_users`** only; no new `credit_balance` on agency docs unless separately approved |
| 6 | Agency bulk `install_provision.php` | Still creates `integrations/{ghl_*}` with `credit_balance: 0` + trial fields; **users** row still gets `credit_balance` only when user registers via paths 1–3 |
| 7 | Send SMS with approved sender after test user registration | Deduction uses `users/{id}.credit_balance` (verify in `credit_transactions` / balance decrement) |

### Optional regression

- Run `php tmp/migrate_wallets.php --dry-run` on staging: should report **no** needed migrations for users created after this fix.

---

## Assumptions

1. **`credit_balance` on `users`** means paid wallet credits, not the 10 install trial credits (`free_*` on integrations).
2. **Handoff only** — implementation happens in `nola-sms-pro-backend`; this file documents intent and exact touch points.
3. **Historical users** without the field may still rely on `tmp/migrate_wallets.php` or the existing-user install link backfill; new signups should not depend on migration.
4. **Agency wallet** (`agency_users.balance`) is intentionally excluded unless backend owners request a follow-up handoff.

---

## Related Docs

- `backend_handoff_install_credits.md` — trial credits on `integrations`
- `backend_handoff_registration_install.md` — Marketplace install registration flow
- `docs/SAAS-DESIGN.md` — credit ledger and account model
- `tmp/migrate_wallets.php` — integrations → users balance migration (existing deployments)

---

## Checklist for backend PR

- [ ] `register.php` sets `credit_balance: 0` for new `users` documents
- [ ] `register_from_install.php` sets `credit_balance: 0` on new install users
- [ ] `register_from_install.php` backfills `credit_balance: 0` only when `!array_key_exists('credit_balance', $existingDoc)`
- [ ] No overwrite of non-zero / existing balances on merge paths
- [ ] Staging test plan rows 1–5 pass
- [ ] No frontend contract changes required
