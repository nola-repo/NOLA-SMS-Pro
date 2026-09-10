# Staging Merge, Install Redirects, and CRM Domain — Refactor Plan

Date: August 28, 2026  
Branch: `staging` (do not merge to `main` until the gates in section 8 pass)  
Owner: Backend  
Related: `docs/install-routing-architecture.md`, `api/request_context.php`, `pages/ghl_callback.php`, `pages/install-login.php`, `pages/install-register.php`

## Goal

Keep production marketplace installs stable while finishing three remaining problems:

1. After install/login/register, send the user to **their** HighLevel subaccount custom page, on the **correct CRM host** (`app.nolacrm.io` vs `app.gohighlevel.com` / LeadConnector), never a hardcoded NOLA CRM location.
2. Make staging behave like production for auth/install **without** sending traffic or OAuth to production URLs, and without overwriting live `ghl_tokens`.
3. Split the current `staging` delta into merge-safe slices so `main` does not take URL, JWT, GET-hardening, and CRM-host fixes in one untested dump.

This plan does **not** change the login-vs-register decision. Linked + existing account still goes to login; fresh / token-only / no owner still goes to register.

---

## Current state (what is already true)

| Area | Production (`main` / `sms-api`) | Staging (`sms-api-staging`) |
| --- | --- | --- |
| Login vs register routing | Already correct | Same algorithm |
| NOLA API host in install pages | Hardcoded `https://smspro-api.nolacrm.io` | Dynamic via `nola_public_base_url()` |
| OAuth `redirect_uri` | Hardcoded production callback | Dynamic from request host |
| JWT missing on Apache | Not the live incident | Fixed (secrets split + `nola_jwt_secret()` + `PassEnv`) |
| CRM host after install | `install_detect_crm_base_url()` exists; empty Referer falls back to `app.nolacrm.io` | Same detector; URL helpers did not fix this |
| Shared Firestore / Redis / GHL app / JWT | Same project | Same keys copied on purpose |

The broken URL the team keeps seeing:

`https://app.nolacrm.io/v2/location/ugBqfQsPtGijLjrmLdmA/custom-page-link/69a642aae76974824fd39bb6`

is three independent fields:

- CRM host defaulted to `app.nolacrm.io`
- Location id resolved as NOLA CRM (`ugBqfQsPtGijLjrmLdmA`) instead of the installing subaccount
- Custom page id from `GHL_CUSTOM_PAGE_ID` (this part is correct and should stay env-driven)

Yesterday’s staging work made **NOLA’s** hosts dynamic (API, standalone app, agency). It did **not** make the **HighLevel CRM** host or selected location id reliable when OAuth has no Referer.

---

## Recommended solutions (chosen vs rejected)

### A. CRM host (LeadConnector vs nolacrm) — chosen

**Do not** set `GHL_CRM_BASE_URL=https://app.nolacrm.io` on Cloud Run. That would freeze every install onto NOLA’s white-label.

**Do** resolve CRM host in this order, then **persist** it:

1. OAuth `state.origin` / `state.crm_domain` if it is an allowlisted HighLevel host.
2. `Referer` / `Origin` if allowlisted (`nolacrm.io` → `https://app.nolacrm.io`; `gohighlevel.com` or `leadconnectorhq.com` → `https://app.gohighlevel.com`; custom agency domain if it matches `https://` + host).
3. After the location id is known, GHL `GET /locations/{locationId}` domain / white-label fields (and company domain when present).
4. Last stored `crm_domain` on `ghl_tokens/{locationId}`.
5. Only then `GHL_CRM_BASE_URL` if set.
6. Last resort: `https://app.gohighlevel.com` for marketplace/LeadConnector-shaped installs, **not** `app.nolacrm.io`, unless the location/company is known to be on the NOLA white-label.

Persist `crm_domain` on:

- install JWT claims (already started)
- login query `crm_domain` (already started)
- `ghl_tokens/{locationId}.crm_domain` (new, so later welcome-back does not re-guess)

Pass that value through `install-login` and `install-register` as the only source for `/v2/location/{id}/custom-page-link/{pageId}`. Do not use `$reactApp` / `REACT_APP` as a silent fallback to `app.nolacrm.io` when `crm_domain` is empty; treat empty as “detect again,” not “NOLA CRM.”

### B. Location id in the deep link — chosen

Never substitute `ugBqfQsPtGijLjrmLdmA` (NOLA CRM / marketplace media location). Remove unused `GHL_MARKETPLACE_LOC_ID` from `install-register.php`.

The deep-link location id must be the **resolved install location** from `install_resolve_selected_location()` / install token `location_id` / login `location_id`. If those are missing, show an error or the location picker; do **not** deep-link to the NOLA HQ location.

Optional guard on staging: refuse to finalize install (or refuse token writes) for a denylist/allowlist of location ids so QA cannot overwrite paying subaccounts. Start with an allowlist of internal test locations.

### C. OAuth redirect URI for merge — chosen

Keep `nola_ghl_oauth_redirect_uri()` but **pin production**:

- If `GHL_REDIRECT_URI` is set, always use it.
- Else if `K_SERVICE` is `sms-api` (production), force `https://smspro-api.nolacrm.io/oauth/callback`.
- Else (staging), use the public request host + `/oauth/callback`.

Register the staging callback URL as an **additional** redirect URI on the HighLevel marketplace app (or a dedicated staging app). Same client id without a matching redirect URI will fail token exchange on staging, which is safer than stealing production’s URI.

Do **not** copy production `APP_BASE_URL` / `FRONTEND_APP_URL` / `AGENCY_APP_URL` / `GHL_REDIRECT_URI` onto `sms-api-staging`.

### D. Shared secrets — short term vs later

**Now (before more staging QA):** keep shared JWT/GHL/SMS keys so staging can talk to the same providers, but **only test internal locations**. Turn `GHL_INSTALL_TRACE=1` on `sms-api-staging` only.

**Later (separate project):** staging Firestore database or collection prefix, separate `JWT_SECRET` / `WEBHOOK_SECRET`, and either a HighLevel **draft/staging app** or strictly extra redirect URIs. Same GHL client id means staging and production are the same marketplace app; data isolation is the real remaining risk, not Cloud Run service names.

Rejected for this pass: copying all production URL env vars onto staging; a second Firestore just to ship the CRM-host fix.

### E. Staging `PassEnv` / `APP_ENV`

Extend `docker-entrypoint.sh` `PassEnv` to include every Cloud Run key PHP actually reads (`UNISMS_API_KEY`, `GHL_AGENCY_*`, `GHL_USER_*`, `GHL_SSO_SECRET`, `REDIS_HOST`, `CRON_SECRET`, `GHL_INSTALL_TRACE`, `GHL_CRM_BASE_URL`). Set `APP_ENV=staging` on `sms-api-staging` only (not on `sms-api`). Keep Laravel `APP_DEBUG=false` in Cloud Run.

### F. GET hardening / credit retry / GHL status PUT — split from URL merge

These are useful (`ghl_contacts` empty `200`, account-sender GET fallback, Firestore contention retry, message status `PUT`) but they change behavior for **all** installed subaccounts. Merge them in a **second** PR after URL/JWT/CRM-host is proven on staging.

---

## Implementation phases

### Phase 0 — Operate staging safely (no code)

1. On Cloud Run `sms-api-staging` only: set `GHL_INSTALL_TRACE=1` or delete the var. Do not change production unless debugging a live install.
2. Do not set `APP_BASE_URL`, `FRONTEND_APP_URL`, `AGENCY_APP_URL`, or production `GHL_REDIRECT_URI` on staging.
3. QA only on internal HighLevel locations, never paying `ghl_tokens` docs.
4. Confirm HighLevel app redirect URIs include staging callback if you will test marketplace install on staging.

### Phase 1 — CRM host + location id (the remaining product bug)

Files:

- `api/install_helpers.php` — `install_detect_crm_base_url()`, persist helper, never default LeadConnector-shaped traffic to `app.nolacrm.io`
- `pages/ghl_callback.php` — detect after location resolution; pass `crm_domain` into redirect builder; trace host + location id when tracing is on
- `pages/install-login.php` / `pages/install-register.php` — deep link only from resolved `location_id` + stored `crm_domain`; remove `GHL_MARKETPLACE_LOC_ID`
- `api/auth/register_from_install.php` — return `user.crm_domain` and `user.location_id` from the install token / token doc
- `laravel/tests/Unit/CrmDomainDetectionTest.php` — add cases: empty Referer; leadconnector marketplace Referer; persisted token domain; never emit `ugBqfQsPtGijLjrmLdmA` unless it was the selected location

Acceptance:

- Install from a **nolacrm.io** test subaccount → `https://app.nolacrm.io/v2/location/{THAT_LOCATION}/custom-page-link/{pageId}`
- Install from a **LeadConnector / app.gohighlevel.com** test subaccount → `https://app.gohighlevel.com/v2/location/{THAT_LOCATION}/custom-page-link/{pageId}`
- Linked welcome-back uses the same host + location, not NOLA HQ

### Phase 2 — Production-safe public URL helpers

Files:

- `api/request_context.php` — pin production OAuth callback; staging stays host-based
- `docker-entrypoint.sh` — complete `PassEnv` list; optional `APP_ENV` passthrough
- Cloud Run: `APP_ENV=staging` on `sms-api-staging` only

Acceptance:

- Hitting `smspro-api.nolacrm.io` still exchanges OAuth with the registered production redirect URI
- Hitting `sms-api-staging` does not emit `smspro-api.nolacrm.io` login/register links
- Standalone login with no `location_id` still goes to NOLA app (`app.nolasmspro.com` or staging frontend), not GHL

### Phase 3 — Staging write guard (lightweight)

Add a staging-only allowlist (env `STAGING_ALLOWED_LOCATION_IDS`, comma-separated). When `nola_is_staging()` and the location is not on the list, skip persisting/replacing `ghl_tokens` and return a clear install error. Production ignores the env.

This is the cheapest way to stop “same secrets” from mutating live installs during QA.

### Phase 4 — Merge slice A (auth/URL/CRM)

Merge to `main` only the URL/JWT/`PassEnv`/CRM-host/location-guard commits after Phase 1–3 pass on staging. Do not include GET-hardening in this slice.

### Phase 5 — Merge slice B (hardening)

Separate PR: contacts/sender GET fallbacks, credit transaction retry, GHL message status `PUT`. Staging test: contacts list, sender settings, send + status on a test location.

---

## Test plan

| Test | Where | Pass |
| --- | --- | --- |
| Fresh install, no linked user | Staging, test location | Register, then deep link to **that** location on the **correct** CRM host |
| Reinstall, linked user | Staging, same location | Login welcome-back, same deep link rule |
| LeadConnector / gohighlevel location | Staging | Host is `app.gohighlevel.com` (or that agency’s white-label), not `app.nolacrm.io` |
| nolacrm.io location | Staging | Host is `app.nolacrm.io` |
| Standalone login (no location_id) | Staging | Staging frontend, not GHL custom page |
| Marketplace OAuth | Staging | Token exchange succeeds against staging redirect URI |
| Production smoke after slice A | Production test location only after merge | Existing happy path still works; OAuth still uses `smspro-api.nolacrm.io/oauth/callback` |
| Paying location | Never on staging | Guard blocks or QA simply does not use those ids |

Logs: with `GHL_INSTALL_TRACE=1` on staging, confirm `[GHL_CALLBACK_INSTALL_TRACE]` includes `crm_domain` and resolved `locationId`.

---

## Explicit non-goals

- Rewriting install login vs register classification
- Moving secrets into Secret Manager in this pass
- A full second GCP project
- Changing `GHL_CUSTOM_PAGE_ID` unless HighLevel issues a new custom page
- Enabling install traces on production by default

---

## Merge gates (all required)

1. Phase 1 acceptance tests passed on staging (both CRM hosts + correct location id).
2. Production OAuth URI remains pinned when `K_SERVICE=sms-api`.
3. Staging URL overrides still unset (or set only to staging URLs).
4. No staging writes to non-allowlisted location ids (Phase 3) **or** documented QA-only locations.
5. Slice B hardening not mixed into slice A.
6. `GHL_INSTALL_TRACE` left at `1` on staging until QA sign-off, then can return to `0`.
