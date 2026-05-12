# Frontend handoff: GHL contacts `requires_reconnect` UX

**Status:** Ready for implementation  
**Priority:** High (user-facing; currently misread as “no contacts”)  
**Frontend repo:** [nola-repo/nola-sms-pro-frontend](https://github.com/nola-repo/nola-sms-pro-frontend) (no backend clone required; this doc lives in the PHP API repo for handoff)

**Related backend doc:** [frontend_handoff_settings_ghl_fix.md](./frontend_handoff_settings_ghl_fix.md) (GHL iframe / `safeStorage` / profile — orthogonal but can confuse debugging if tokens are wrong in iframe)

---

## Problem statement

When GoHighLevel OAuth tokens for a location cannot be refreshed, `GET /api/ghl-contacts` returns **401** with JSON:

```json
{
  "error": "Token refresh failed",
  "requires_reconnect": true
}
```

Today, **`user/src/api/contacts.ts`** treats any `!res.ok` as a silent failure and returns **`[]`**. **`user/src/components/ContactsTab.tsx`** then shows **“No contacts available”**, which is misleading: CRM data may exist; the integration is disconnected.

---

## Backend contract (confirmed in PHP repo)

| Item | Detail |
|------|--------|
| **Endpoint** | `GET` same URL as today: `API_CONFIG.ghl_contacts` (e.g. `/api/ghl-contacts` on the app host; legacy API may use `https://smspro-api.nolacrm.io` per env). |
| **Auth** | `Authorization: Bearer <JWT>` (session) plus `X-GHL-Location-ID` and query `locationId` / `location_id` as already implemented. |
| **Success 200** | `{ "contacts": [ ... ] }` (array may be empty — that is the real “no contacts” case). |
| **Reconnect 401** | Body includes `"requires_reconnect": true` when token refresh fails (`api/services/GhlClient.php`). Other 401 shapes may exist; treat `requires_reconnect === true` as the reconnect banner case. |
| **Other errors** | Non-JSON or other 4xx/5xx — show a generic error, not the empty CRM state. |

**Other endpoint:** `api/agency/get_subaccounts.php` can also return `requires_reconnect` for agency flows — apply the same UX pattern anywhere subaccounts or GHL-backed lists are loaded.

---

## Root cause in frontend (current code)

**File:** `user/src/api/contacts.ts`

On `!res.ok`, the code logs `errorBody` but **`return []`**, so the UI cannot distinguish auth failure from an empty list.

**File:** `user/src/components/ContactsTab.tsx`

- Initial load: `fetchContacts(...).then(setContacts)` — always receives an array.
- Empty UI branch: `groupedContacts.length === 0` → **“No contacts available”** with no reconnect path.

---

## Implementation checklist (frontend team)

### 1. Extend `fetchContacts` result (recommended)

Avoid breaking every caller silently:

- **Option A (preferred):** Change return type to a discriminated union, e.g.  
  `{ ok: true; contacts: Contact[] } | { ok: false; error: 'reconnect' | 'other'; message?: string; status?: number }`  
  Update **all** `fetchContacts(` call sites (search the repo after editing).
- **Option B:** Add `fetchContactsMeta()` and keep `fetchContacts()` for legacy, or add an optional `onAuthError` callback (less clean).

Parse JSON on error (already partially done); read **`requires_reconnect`** from the parsed object when `res.status === 401`.

### 2. `ContactsTab.tsx` UI

- Add state, e.g. `ghlAuthError: null | 'reconnect' | 'generic'`.
- On `ok: false` with `error === 'reconnect'`: show a **full-width alert** above the list (not only the small `error` strip used for add/edit):
  - Title: e.g. “GoHighLevel connection expired”
  - Body: one sentence (“Reconnect your CRM to load contacts.”)
  - **Primary CTA:** navigate to **Settings** (same route your app already uses for GHL / marketplace connect — likely `Settings.tsx` / `/settings`). Use `Link` or `useNavigate` consistent with the app router.
- On `ok: false` with generic error: show retriable message + optional “Try again”.
- **Do not** show “No contacts available” when `ghlAuthError === 'reconnect'` (even if `contacts.length === 0`).

### 3. `refreshContacts` / pull-to-refresh

Clear `ghlAuthError` on successful fetch; on reconnect failure, set it again so pull-to-refresh does not look like a silent no-op.

### 4. POST/PUT/DELETE in `contacts.ts`

If the backend returns the same `401` + `requires_reconnect` on mutations, surface the same banner or toast instead of only throwing a generic error.

### 5. Optional hardening

- **`get_subaccounts` or agency lists:** same pattern if the UI shows empty subaccounts when GHL dropped.
- **Global:** a tiny helper `async function readJsonOrText(res: Response)` avoids duplicated parse logic across `user/src/api/*.ts`.

---

## How to verify when “backend is fine”

Frontend QA with a **healthy** location (tokens valid):

1. `GET` ghl-contacts returns **200** and non-empty `contacts` when GHL has data.
2. **200** + empty array → “No contacts available” is correct.

Frontend QA for **reconnect UX** (coordinate with backend or use a revoked test location):

1. Force **401** + `requires_reconnect: true` → banner + CTA, **not** empty list copy.
2. Complete reconnect in Settings → return to Contacts → list loads without hard refresh (or document if full refresh is required).

---

## Suggested `contacts.ts` shape (illustrative)

```ts
export type FetchContactsResult =
  | { ok: true; contacts: Contact[] }
  | {
      ok: false;
      contacts: [];
      kind: 'reconnect' | 'other';
      message?: string;
      status: number;
    };

// Inside fetchContacts, when !res.ok:
// const body = await parseJsonSafe(res);
// if (res.status === 401 && body && body.requires_reconnect === true) {
//   return { ok: false, contacts: [], kind: 'reconnect', message: body.error, status: 401 };
// }
// return { ok: false, contacts: [], kind: 'other', message: ..., status: res.status };
```

---

## Files to touch (from public `main` tree)

| Area | Path |
|------|------|
| API wrapper | `user/src/api/contacts.ts` |
| Contacts UI | `user/src/components/ContactsTab.tsx` |
| Callers | Run repo-wide search for `fetchContacts` after changing its return type |

---

## Backend owner note

No PHP change is **required** for this UX: the API already returns the right JSON. This handoff is entirely **frontend interpretation** of non-2xx responses. If you want a machine-readable header later (e.g. `X-NOLA-GHL: reconnect`), that would be additive and optional.
