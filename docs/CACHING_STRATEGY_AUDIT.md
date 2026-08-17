# NOLA SMS Pro Caching Strategy Audit

Date: 2026-06-23

## Purpose

This document evaluates the current caching behavior across the NOLA SMS Pro system and recommends a consistent caching strategy for these access surfaces:

- GoHighLevel iframe sessions.
- External public-domain sessions.
- Agency portals and agency-scoped access.
- Administrative and internal management interfaces.

The goal is to keep the product fast while avoiding stale balances, cross-location data bleed, inconsistent agency views, and repeated expensive reads against Firestore or external APIs.

## Current Behavior Assessed

The system already has meaningful caching in place, but it is uneven.

Backend response caching is centered on `api/cache_helper.php`. `NolaCache` attempts Redis when the PHP Redis extension is available, then falls back to a file cache under `api/cache/data`. It supports TTL-based reads, direct key deletion, registry-based invalidation, admin cache invalidation, agency dashboard invalidation, and conservative HTTP headers through `sendApiCacheHeaders`.

There is also a separate simple file cache class in `api/services/Cache.php`, currently used by `GhlClient` for token-cache clearing. This should remain separate from UI response caching because OAuth token freshness and locking have different correctness rules.

Several hot API surfaces already cache:

- Account profile: `account_profile_{locationId}` for 5 minutes, registry-scoped by location.
- Conversations list: `conversations_list_{locationId}_{paramsHash}` for 5 minutes, invalidated by sends, inbound webhooks, receive handlers, and conversation changes.
- Templates: `templates_list_{locationId}` for 10 minutes, invalidated on create, update, and delete.
- Credits: `credits_data_{locationId}` for 30 seconds, registry-scoped by location.
- Credit transactions: 5 minutes in `get_credit_transactions.php`; 60 seconds in billing transactions.
- GHL contacts: `ghl_contacts_list_{locationId}` for 30 minutes plus a 7-day last-good fallback for temporary GHL failures.
- Agency subaccounts and install checks: generally 2 to 5 minutes, keyed by agency/company ID.
- Agency profile: 10 minutes, keyed by agency user ID.
- Admin lists/settings: generally 5 minutes, global admin keys.

Frontend caching is mostly session/profile oriented:

- `safeStorage` is used for auth token, role, company ID, location ID, profile, theme, settings, sender IDs, and preferences.
- `AuthContext` reads a `?token=` URL parameter first to support iframe and private-storage environments, then stores it through `safeStorage` and removes it from the URL.
- `useUserProfile` renders a cached profile immediately from storage, then fetches `/api/auth/me.php` or `/api/agency/profile.php` in the background on mount.
- `useGhlLocation` prefers current URL location parameters over stored values, which helps avoid stale location reuse inside GoHighLevel.
- `settingsStorage` also prefers current URL location values over cached `nola_settings_account` data.

Important current limitations:

- Production fallback file cache is per container instance and not shared across Cloud Run replicas. This improves single-instance latency but does not reliably reduce load or guarantee coherent invalidation during high traffic.
- Many cached endpoints do not emit consistent `X-Nola-Cache`, `Cache-Control`, or freshness metadata, making it hard for the frontend to show accurate cached/updating states.
- There is no shared frontend query cache or request de-duplication layer. Route switches can trigger repeated requests for profile, credits, conversations, templates, contacts, and agency/account data.
- Message history reads call `StatusSync::checkAndSyncSingleMessage` while serving responses. This keeps statuses fresh but can make page loads slow and variable.
- `api/contacts.php` reads Firestore directly without response caching. `api/ghl_contacts.php` has stronger caching and last-good fallback, so contact behavior differs depending on which endpoint the frontend uses.
- Public whitelabel branding reads Firestore directly on every request and has no cache headers, even though it is domain-scoped and highly cacheable.
- Admin caches are global and broad. They reduce repeated Firestore scans but need stronger invalidation discipline and optional `refresh` support everywhere.
- GHL contacts cache ignores pagination/filter dimensions because it caches the entire fetched list per location. This is acceptable for the current "fetch all up to cap" behavior, but should be documented as an all-contacts snapshot.

## Strategy Principles

1. Cache by tenant boundary first.
   Every key must include the narrowest relevant identity: location ID for subaccounts, company/agency ID for agency views, user ID for personal profile, admin scope for admin views, and domain for public whitelabel.

2. Treat realtime data differently from reference data.
   Conversations, messages, credits, and billing actions need short TTLs plus mutation invalidation. Templates, sender IDs, profile metadata, agency location lists, and whitelabel branding can tolerate longer TTLs.

3. Prefer shared production cache.
   Use Redis/Memorystore for production and high-traffic environments. Keep file cache only as local fallback for development, emergency degraded mode, and single-instance deployments.

4. Serve last-good data when external systems are temporarily down.
   The GHL contacts endpoint already does this. Extend the pattern to dashboards, agency subaccounts, reports, and whitelabel where safe.

5. Mutations must invalidate before the success response finishes.
   If an action changes balances, messages, sender approvals, settings, agency config, install state, or user profile fields, invalidate the affected registries synchronously.

6. The frontend should render cached data as valid but possibly refreshing.
   Users should see the last known good state quickly, then a quiet refresh indicator when fresh data is being fetched.

7. Do not cache authorization failures as data.
   Always run auth and tenant checks before cache reads. Current protected endpoints generally do this and should keep doing it.

## Environment-Specific Behavior

### GoHighLevel Iframe

Recommended behavior:

- Session storage should be resilient when third-party storage is blocked. Continue supporting token handoff through URL or postMessage, then keep a memory-backed session if persistent storage is unavailable.
- Browser cache keys must be scoped by `{surface: "ghl-iframe", role, locationId, companyId}`.
- URL location parameters must always override stored location values.
- Iframe route changes should reuse cached profile, account, sender ID, templates, and conversation summaries while refreshing in the background.
- Avoid relying on cookies unless they are explicitly configured for iframe use with `SameSite=None`, `Secure`, and preferably partitioned-cookie support.

TTL guidance:

- Profile/account shell: 5 to 10 minutes, refresh on focus or iframe visibility return.
- Credits: 15 to 30 seconds, force refresh after send, top-up, gift, credit request approval, or auto-recharge action.
- Conversations sidebar: 15 to 60 seconds client-side stale time, server cache no more than 60 to 120 seconds once near-realtime polling exists.
- Message thread: 0 to 15 seconds stale time, or realtime/polling while a thread is open.
- Templates and sender IDs: 5 to 10 minutes, invalidate after mutation.

### External Public Domain

Recommended behavior:

- Same backend cache keys as iframe, but browser cache keys should include `{surface: "external", origin, role, locationId, companyId}`.
- Use browser HTTP caching for static frontend assets with immutable hashed filenames.
- Use short private API caching for authenticated JSON, never public shared caching.
- Whitelabel branding should be cached by domain with a longer TTL and explicit invalidation after agency branding changes.

TTL guidance:

- Static JS/CSS/assets: 1 year immutable when filenames are content-hashed.
- Whitelabel/domain branding: 15 to 60 minutes, stale fallback 24 hours.
- Authenticated profile/settings: 5 to 10 minutes, private only.
- Dashboard aggregates: 30 to 120 seconds depending on freshness need.

### Agency Portal

Recommended behavior:

- Cache by agency/company ID, never only by location ID when the response contains multiple subaccounts.
- Subaccount lists should cache for 2 to 5 minutes, but must be invalidated by install provisioning, uninstall, sync locations, toggle subaccount, rate-limit update, agency wallet gift, and credit request approval.
- Agency wallet and credit request counts should refresh more frequently than static location metadata.
- For bulk install/status screens, use cached install checks and expose a manual refresh.

TTL guidance:

- Agency profile: 10 minutes.
- Subaccount list: 2 to 5 minutes.
- Install status/checks: 2 to 5 minutes, force refresh after provision or sync.
- Agency wallet: 30 to 60 seconds.
- Credit requests: 30 to 60 seconds for pending view, 2 to 5 minutes for history.
- Agency transactions: 60 seconds, force refresh after gift or payment event.

### Admin and Internal Interfaces

Recommended behavior:

- Admin list caches can remain global if the payload is identical for every permitted admin role. If role-based filtering is introduced, include role and permission scope in the key.
- Admin dashboards should expose manual refresh and show the cache age.
- Mutation endpoints should continue using `invalidateAdminDashboard`, but this invalidation should include any new admin dashboard/report keys.
- Consider separate keys for dashboard summary, account list, sender requests, logs, and settings so one mutation does not always flush everything.

TTL guidance:

- Admin users, agencies, accounts: 5 minutes.
- Sender requests: 1 to 5 minutes, force refresh after approve, reject, or revoke.
- Admin dashboard logs: 30 to 60 seconds.
- Admin settings: 5 to 10 minutes, force refresh after save.
- Internal reports: 1 to 15 minutes depending on cost and required freshness.

## Feature-by-Feature Recommendations

### Dashboards

Current behavior:

- Dashboard data appears to be assembled from account, credits, conversations, sender requests, agency wallet, subaccount, and admin list endpoints rather than a single cached dashboard endpoint.

Recommended improvement:

- Add purpose-built dashboard summary endpoints per surface:
  - `dashboard_summary_location_{locationId}`.
  - `dashboard_summary_agency_{companyId}`.
  - `dashboard_summary_admin`.
- Cache summaries for 30 to 120 seconds.
- Use last-good fallback for dashboard cards if Firestore or GHL is temporarily slow.
- Include `generated_at`, `cache_ttl`, `cached`, and `stale` fields.
- Invalidate summary keys after sends, inbound messages, credit changes, sender approval changes, agency install changes, and profile/settings updates.

### Conversations and Messaging

Current behavior:

- `api/conversations.php` caches conversation lists for 5 minutes with registry invalidation.
- Send and receive webhook paths delete the conversation registry.
- `api/messages.php` does not cache and may sync message status during reads.

Recommended improvement:

- Reduce server TTL for active conversation lists to 60 to 120 seconds, keeping registry invalidation.
- Keep closed/inactive paginated history cacheable for 2 to 5 minutes.
- Do not cache an open message thread long unless it is explicitly historical.
- Split status sync from message-read latency where possible:
  - Continue scheduled status sync.
  - For open threads, poll lightweight status endpoint or use a short client refetch interval.
  - Avoid per-message external status checks during every page load.
- Add cache keys for historical message pages only:
  - `messages_{locationId}_{conversationId}_{pageHash}` with 15 to 60 second TTL for active conversations.
  - Longer TTL for archived date ranges.
- Force invalidation after send, inbound receive, conversation rename, delete, GHL conversation creation, and contact phone update.

### Contacts

Current behavior:

- `api/contacts.php` reads Firestore directly and does not cache.
- `api/ghl_contacts.php` caches the full GHL contact list for 30 minutes and has a 7-day last-good fallback.

Recommended improvement:

- Decide whether the frontend uses local Firestore contacts, GHL contacts, or a merged contacts API. Mixed use will cause inconsistent freshness.
- Add caching to local contacts list:
  - `contacts_list_{locationId}_{paramsHash}` for 5 to 10 minutes.
  - Registry: `contacts_registry_{locationId}`.
- Invalidate local and GHL contact registries on create, update, delete, import, or GHL webhook contact changes.
- Keep the GHL 7-day last-good fallback, but expose `stale: true` and `last_synced_at`.
- Consider background refresh for GHL contacts so first page load does not have to fetch up to 2,000 records.

### Reports and Analytics

Current behavior:

- Billing transactions and credit stats run Firestore queries and use short caches.
- Some month filtering is performed after broader Firestore reads because composite indexes are missing or avoided.

Recommended improvement:

- Cache analytics by `{scope, locationId|agencyId, dateRange, filters, page}`.
- TTL:
  - Current day: 60 to 120 seconds.
  - Current month: 5 minutes.
  - Closed months: 6 to 24 hours.
- Create or finish required Firestore composite indexes so reports can query bounded date ranges directly.
- Precompute daily aggregates for:
  - Messages sent.
  - Credits used.
  - Provider cost.
  - Charged amount.
  - Profit.
  - Failures.
- Admin and agency reports should read aggregates first, then drill into transactions only when users open details.

### Credits and Billing

Current behavior:

- `api/credits.php` caches current credit payload for 30 seconds and supports `fresh` or `no_cache`.
- Agency wallet caches for 60 seconds.
- Transactions cache for 60 seconds in billing endpoints.
- CreditManager invalidates credit registries and some agency dashboard keys.

Recommended improvement:

- Keep credit balance TTL short: 15 to 30 seconds.
- Always force refresh after:
  - SMS send or refund.
  - Top-up checkout success.
  - Agency gift.
  - Credit request approve/deny.
  - Auto-recharge state change.
  - Admin balance adjustment.
- Return cache metadata on all balance endpoints.
- Use optimistic UI only for pending actions that the backend confirms quickly. For balances, prefer "updating" state over speculative balances unless the mutation response contains the authoritative new balance.
- Consider a small, cached "billing summary" separate from full transaction ledger.

### User Profiles and Settings

Current behavior:

- Frontend stores a cached profile and refreshes it on mount.
- Account and agency profile endpoints are cached server-side.
- Settings storage can retain local values.

Recommended improvement:

- Keep profile endpoint caches user-scoped or location-scoped based on payload.
- Include `updated_at` in profile/settings responses.
- Frontend should treat stored profile/settings as last-known good, then refetch on app start, focus return, and after settings save.
- Avoid storing mutable cross-tenant account settings under unscoped localStorage keys. Introduce scoped keys like:
  - `nola:{surface}:{role}:{companyId}:{locationId}:settings_account`.
  - `nola:{surface}:{role}:{companyId}:{locationId}:sender_ids`.
- On logout, clear both legacy keys and scoped keys for the current user/session.

### Sender IDs

Current behavior:

- Sender approvals are surfaced through account/admin endpoints and sender request endpoints.
- Admin sender request mutations invalidate admin dashboard and some account/credit registries.

Recommended improvement:

- Cache approved sender IDs by location for 5 to 10 minutes.
- Invalidate on sender request submit, approve, reject, revoke, or admin sender settings change.
- The send path must always validate sender IDs against Firestore/current config, not only cached frontend data.
- Frontend can render cached sender IDs immediately but should show pending approval state until live refresh confirms.

### Agency Management

Current behavior:

- Agency subaccounts, install checks, agency wallet, locations, and profile are cached.
- Many agency mutations call `invalidateAgencyDashboard`.

Recommended improvement:

- Standardize agency cache keys under one prefix:
  - `agency:{agencyId}:profile`.
  - `agency:{agencyId}:subaccounts`.
  - `agency:{agencyId}:install_status:{hash}`.
  - `agency:{agencyId}:wallet`.
  - `agency:{agencyId}:credit_requests:{hash}`.
- Continue invalidating `agency_all_active_subaccounts` for global agency/admin views.
- Add cache metadata to every agency GET response.
- Use manual refresh and background refresh for sync-heavy screens.

### Administrative Tools

Current behavior:

- Admin user/account/agency/settings lists are cached for around 5 minutes.
- `invalidateAdminDashboard` flushes broad admin keys.

Recommended improvement:

- Preserve broad invalidation for correctness, then add narrower invalidation once admin surfaces grow.
- Every admin list endpoint should accept `refresh=1` or `bypass_cache=1`.
- Admin screens should show cache age and a refresh button.
- Admin analytics should use aggregate caches rather than scanning large collections on every page load.

### Integrations and External Services

Current behavior:

- GHL API access uses `GhlClient` with proactive refresh, 401 retry, token refresh classification, and Firestore lock coordination.
- GHL contacts use last-good fallback on temporary GHL failures.
- CORS preflight cache is set to 86400 seconds.

Recommended improvement:

- Keep OAuth token handling separate from response cache.
- Add stampede protection for expensive external calls, especially GHL contacts and agency sync operations.
- Use last-good fallback for whitelabel, dashboard summary, and agency subaccount lists when Firestore or GHL is temporarily slow.
- Add rate-limit-aware backoff for external API failures and surface `retry_after` when available.

## Cache Metadata Contract

Every cached JSON endpoint should eventually return these fields, either at top level or under a `meta.cache` object:

```json
{
  "cached": true,
  "stale": false,
  "generated_at": "2026-06-23T05:00:00Z",
  "cache_ttl": 60,
  "cache_key_scope": "location"
}
```

HTTP response headers should be consistent:

- `Cache-Control: private, max-age=<ttl>, stale-while-revalidate=30` for authenticated JSON that the browser may privately reuse.
- `X-Nola-Cache: HIT|MISS|BYPASS|STALE`.
- `Vary: Origin, Authorization, X-GHL-Location-ID, X-Agency-ID` where applicable.
- Do not use public shared caching for authenticated JSON.

## Invalidation Matrix

| Action | Invalidate |
| --- | --- |
| Send SMS | conversations registry, active message/thread cache, credits registry, dashboard summary, reports current day |
| Receive inbound SMS | conversations registry, active message/thread cache, dashboard summary |
| Status sync changes message status | active message/thread cache, current day reports, dashboard summary if counts change |
| Create/update/delete contact | contacts registry, GHL contacts registry if mirrored, conversations registry if phone/name changes |
| Create/update/delete template | templates registry |
| Sender request submit/approve/reject/revoke | sender ID cache, account profile, admin dashboard, agency dashboard when agency-scoped |
| Top-up/payment success | credits registry, billing summary, transactions, dashboard summary |
| Agency gift credits | agency dashboard, agency wallet, subaccount credits registry, transactions |
| Credit request approve/deny | credit requests registry, agency dashboard, subaccount credits registry if approved |
| Profile/settings save | profile/settings key, account profile, admin dashboard if admin-visible |
| Agency install/provision/uninstall/sync | agency dashboard, agency locations, install checks, subaccount list, account profile |
| Whitelabel branding change | whitelabel domain key, agency profile if branding appears there |
| Admin settings change | admin settings, admin dashboard, dependent runtime config keys |

## Production Priorities

1. Make production cache shared.
   Configure Redis/Memorystore or an equivalent shared cache. File fallback should remain but should not be relied on for high-traffic consistency.

2. Add cache metadata everywhere.
   Extend `NolaCache::sendApiCacheHeaders` usage and response bodies so frontend states can be consistent.

3. Normalize frontend data fetching.
   Introduce a request/query cache with stable keys, stale times, background refresh, and mutation invalidation hooks.

4. Shorten active conversation cache and improve message refresh.
   Keep conversation list fast but reduce 5-minute staleness for active messaging surfaces.

5. Add local contacts and whitelabel caching.
   These are easy wins for slow loads and repeated public-domain requests.

6. Add stampede protection.
   Protect expensive admin scans, GHL contact fetches, dashboard summaries, and report generation with short locks.

7. Add analytics aggregates and indexes.
   Avoid broad transaction reads and client-side month filtering as data grows.

## Success Metrics

Track these before and after rollout:

- API p50, p95, and p99 latency by endpoint and surface.
- Cache hit ratio by endpoint and tenant scope.
- Firestore document reads per request and per active user.
- GHL API calls per active user/session.
- Dashboard time to first meaningful data.
- Conversation thread time to latest message.
- Credit balance freshness after send/top-up.
- Number of repeated identical frontend requests during route switches.
- Error rate during traffic spikes and external API degradation.

