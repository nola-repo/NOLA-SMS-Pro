# Frontend Handoff: Urgent Console Error Cleanup

Date: 2026-06-25

## Backend Resolution Completed

The credit status endpoint now normalizes legacy integration documents that were missing `created_at`.

Changed:
- `api/credits.php`

Behavior:
- Existing `integrations/{ghl_location}` documents with no valid `created_at` are backfilled on read.
- If `updated_at` exists, `created_at` is backfilled from that value.
- If neither timestamp exists, `created_at` is backfilled with the current server timestamp.
- The API response remains backward-compatible:
  - `success: true`
  - `credit_balance`
  - `free_usage_count`
  - `free_credits_total`
  - `currency`
  - `created_at`
  - `updated_at`
  - `stats`
  - `cached`
  - `meta.cache`

The pasted response with `success: true` and `cached: false` was not a backend failure. `cached: false` / `MISS` is expected on the first read or after cache expiry.

## Urgent Frontend Requirements

### 1. Do Not Treat Successful Credit Responses as Errors

The credits UI must treat this shape as a successful response:

```json
{
  "success": true,
  "account_id": "ugBqfQsPtGijLjrmLdmA",
  "credit_balance": 97,
  "free_usage_count": 10,
  "free_credits_total": 10,
  "currency": "PHP",
  "created_at": "2026-06-19 08:41:14",
  "updated_at": "2026-06-19 08:41:14",
  "stats": {
    "sent_today": 27,
    "credits_used_today": 27,
    "credits_used_month": 764
  },
  "cached": false,
  "meta": {
    "cache": {
      "status": "MISS",
      "cached": false,
      "stale": false,
      "generated_at": "2026-06-25T05:15:35+00:00",
      "cache_ttl": 30,
      "cache_key_scope": "location"
    }
  }
}
```

Frontend action:
- Update `fetchCreditStatus` to validate `success === true`.
- Do not flag `cached: false`, `MISS`, or `created_at` fallback values as errors.
- Only show an error state when `success === false`, the HTTP status is non-2xx, or JSON parsing fails.

### 2. Fix Firebase Authorized Domains

Console messages showed:
- `app.nolasmspro.com` is not authorized for Firebase OAuth operations.
- `app.nolacrm.io` is not authorized for Firebase OAuth operations.

Frontend/platform action:
- Add both domains in Firebase Console > Authentication > Settings > Authorized domains.
- Retest all embedded and direct app flows after deployment.

Acceptance criteria:
- No Firebase iframe warning for either production domain.
- Any Firebase popup/redirect auth flow, if still used by the embedded shell/custom page, is not blocked by domain authorization.

### 3. Fix the Broken Custom Page Link

Console showed:

```text
GET https://app.nolacrm.io/v2/location/{locationId}/custom-page-link/69a642aae76974824fd39bb6 404
```

Frontend/GHL action:
- Confirm the GHL custom menu/page ID still exists.
- Replace stale custom-page-link IDs in GHL app/menu configuration.
- Ensure the destination URL includes required `location_id` / `locationId` context if the page depends on location state.

Acceptance criteria:
- Opening the custom page from GHL no longer produces a 404.
- The app resolves the active location on first load.

### 4. Confirm the Deployed Frontend Has the Credit API Client

This repository imports:

```ts
import { fetchCreditStatus, fetchCreditTransactions, fetchCreditPackages } from "../api/credits";
```

But this workspace snapshot does not include a frontend `api/credits.ts` file.

Frontend action:
- Confirm the deployed frontend source contains this module.
- Ensure `fetchCreditStatus(locationId)` calls `/api/credits.php` with the active location context.
- Prefer `X-GHL-Location-ID` plus query/body fallback where applicable.
- Ensure the function returns the raw successful backend payload or a normalized object with all fields used by `Settings.tsx`.

Acceptance criteria:
- Credits tab renders balance, trial usage, and stats from `/api/credits.php`.
- Empty/missing `locationId` does not silently call the endpoint and then display misleading zeroes.
- Network or auth failures produce a visible but non-crashing UI state.

### 5. Separate Third-Party Console Noise From App Errors

The following console messages are from LeadConnector/GHL/Sentry or injected scripts, not our backend:
- `PerformanceObserver does not support buffered flag...`
- LeadConnector module federation version warnings.
- `backend.leadconnectorhq.com` WhatsApp/voice/reporting 400s.
- Sentry `429 Too Many Requests`.
- `153000002887.js Cannot read properties of undefined (reading 'widget_id')`, unless that script was manually added in our GHL custom JS/tracking settings.

Frontend action:
- Do not block release on these unless a user-facing flow is broken.
- If `153000002887.js` was configured by our team in GHL custom code, either remove it or guard its initialization until widget config exists.
- Add a QA note that console triage should prioritize domains owned by us:
  - `app.nolasmspro.com`
  - `app.nolacrm.io`
  - `smspro-api.nolacrm.io`
  - `/api/*`

## Retest Checklist

- Load the app inside GHL for location `ugBqfQsPtGijLjrmLdmA`.
- Open Settings > Credits.
- Verify `/api/credits.php` returns HTTP 200 and `success: true`.
- Verify `created_at` is no longer `null` after a fresh, non-cached read.
- Verify balance, trial usage, sent today, credits used today, and credits used month render correctly.
- Verify refreshing within 30 seconds may show `cached: true` / `HIT` and the UI still treats it as valid.
- Verify `app.nolasmspro.com` and `app.nolacrm.io` no longer emit Firebase authorized-domain warnings.
- Verify the GHL custom page link no longer 404s.
