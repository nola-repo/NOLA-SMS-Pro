# Test Subaccount Cleanup Implementation Plan

## 1. Objective

Build a controlled cleanup workflow for NOLA SMS Pro test subaccounts that:

1. uninstalls NOLA SMS Pro from the selected HighLevel location;
2. prevents native Conversation-tab, workflow, and API SMS from sending;
3. deletes the NOLA account and candidate-scoped NOLA data;
4. preserves native HighLevel conversations and message history;
5. preserves production, shared, financial, and unresolved records; and
6. produces an auditable result for every location.

The workflow must never delete a HighLevel subaccount/location. Deleting an
entire HighLevel location is outside this project and requires a separate,
explicitly approved operation.

## 2. Current baseline

The read-only analysis in `scripts/cleanup_analysis.php` currently reports:

- 6,323 Firestore documents scanned;
- 96 cleanup candidates;
- 837 candidate-scoped documents eligible for deletion;
- 410 financial-history documents to retain;
- 51 shared-dependency documents to retain;
- 4 non-zero-balance documents requiring review; and
- 3 pending/in-flight documents requiring review.

No cleanup mutations have been performed by that analysis.

Protected production locations:

- NOLA CRM: `ugBqfQsPtGijLjrmLdmA`
- Maxiemizer: `UorU5d43qIWssU2z55fO`
- J&K Car Rental - Angeles City: `Is3CjRqD4xzqonUZIOEo`

## 3. Required behavior

### 3.1 Delete from NOLA

For an approved test location, delete candidate-scoped records such as:

- NOLA `accounts` and `users` records;
- user/subaccount and owner/member links;
- `agency_subaccounts` configuration;
- location OAuth tokens and integration configuration;
- templates and test sender requests;
- local NOLA conversations, messages, inbound messages, and SMS logs;
- location-scoped notifications;
- idempotency, sync, and install artifacts that are not shared; and
- cache entries and registries belonging only to the deleted location/user.

### 3.2 Remove or disable inside HighLevel

Before deleting the OAuth token, call HighLevel:

```http
DELETE /marketplace/app/{appId}/installations
Version: 2023-02-21
Authorization: Bearer {location_access_token}
Content-Type: application/json

{
  "locationId": "{locationId}",
  "reason": "Removing unused NOLA SMS Pro test installation"
}
```

Use `GhlClient::request()` so proactive refresh and one retry after a 401 are
retained. Treat only a documented successful response as remote uninstall
confirmation.

The remote uninstall must occur before the local token is cleared. Otherwise,
NOLA loses the authorization required to remove its installation from GHL.

### 3.3 Preserve inside HighLevel

Do not call the HighLevel conversation-delete API. Preserve:

- native HighLevel conversations;
- sent and received HighLevel message history;
- contacts in HighLevel; and
- the HighLevel subaccount/location itself.

After uninstall, the NOLA conversation provider should no longer be usable.
If HighLevel still displays stale provider UI temporarily, NOLA's provider
endpoint must reject the request and no provider submission or credit deduction
may occur.

## 4. Retention policy

The executor must use deny-by-default deletion. A record is deleted only when
the generated decision is exactly `would_delete` and every runtime guard passes.

Always retain:

- all records for the three protected production locations;
- any document referencing both a cleanup candidate and production;
- agency/company records shared by more than one location;
- credit transactions and billing/audit history;
- unresolved credit requests;
- records with a non-zero balance;
- pending messages, sender requests, jobs, or other in-flight work;
- users or owners linked to a protected or non-candidate location; and
- the cleanup audit record itself.

The current dry-run's 410 financial-history and 51 shared-dependency records
remain protected. The seven manual-review records remain blocked until resolved.

## 5. Cleanup state machine

Track every location independently using these states:

```text
ANALYZED
  -> APPROVED
  -> SMS_BLOCKED
  -> GHL_UNINSTALL_REQUESTED
  -> GHL_UNINSTALLED
  -> LOCAL_DELETE_READY
  -> LOCAL_DELETED
  -> VERIFIED
```

Exceptional terminal/intervention states:

- `BLOCKED_PRODUCTION`
- `BLOCKED_SHARED_DEPENDENCY`
- `BLOCKED_NONZERO_BALANCE`
- `BLOCKED_PENDING_WORK`
- `RECONNECT_REQUIRED`
- `REMOTE_UNINSTALL_FAILED`
- `LOCAL_DELETE_FAILED`
- `VERIFICATION_FAILED`

Never advance a location to `LOCAL_DELETE_READY` until remote uninstall is
confirmed or an operator has explicitly approved `LOCAL_BLOCK_ONLY`. The latter
means NOLA sending is blocked locally, but the app could not be removed remotely
because usable GHL authorization was unavailable.

## 6. Components to implement

### 6.1 Extend the analyzer

Update `scripts/cleanup_analysis.php` to add, per candidate:

- resolved `appId` and token registry document path, without exporting secrets;
- whether a usable location token appears available;
- all linked user/account IDs;
- all protected/shared dependency reasons;
- balance and pending-work guard results;
- expected deletion count and collection breakdown;
- a deterministic manifest version and SHA-256 digest; and
- the expected remote uninstall action.

The analyzer remains read-only.

### 6.2 Add a Marketplace uninstall service

Create `api/services/GhlMarketplaceUninstallService.php` with one location-level
operation. It should:

1. validate `locationId` and `appId`;
2. reject protected locations before creating a client;
3. use the location token through `GhlClient`;
4. call the uninstall endpoint with API version `2023-02-21`;
5. classify 200, 400, 401, 403, 404, 422, 429, and 5xx responses;
6. retry only safe transient failures using bounded backoff;
7. never log access or refresh tokens; and
8. return a structured result containing request ID, status, safe response
   classification, and timestamp.

An already-uninstalled response may be treated as success only after a
read-only verification confirms that the installation is absent or unusable.

### 6.3 Add a cleanup executor

Create `scripts/cleanup_execute.php`. Required inputs:

- an exact analysis JSON path;
- the analysis digest;
- an explicit list of approved location IDs;
- the exact Marketplace application ID (never inferred from an OAuth client ID);
- an operator identity/reason; and
- `--dry-run` by default, with a separate `--execute` flag.

Safety controls:

- refuse incomplete scans;
- refuse stale manifests beyond an agreed validity window;
- refuse location IDs absent from the manifest;
- refuse protected location or company IDs;
- refuse a manifest whose digest has changed;
- refuse any decision other than `would_delete`;
- refuse all non-zero-balance and pending-work candidates;
- enforce a configurable maximum locations per run;
- process one location at a time initially;
- support `--location=...` for a one-location canary;
- require a second explicit confirmation value for execution; and
- make reruns idempotent.

### 6.4 Add an audit ledger

Create a `cleanup_runs/{runId}` record and location result children containing:

- manifest digest and generation time;
- operator and reason;
- approved location IDs;
- state transitions and timestamps;
- GHL uninstall result classification;
- paths scheduled, deleted, retained, skipped, or failed;
- preflight balance/pending results;
- verification results; and
- failure information with secrets removed.

Keep the ledger separate from candidate-linked deletion so it survives cleanup.

## 7. Per-location execution algorithm

### Phase A: Revalidate

1. Confirm the location is an approved candidate.
2. Re-read all protected-location and protected-company constants.
3. Re-run dependency discovery for that location.
4. Recalculate balances and pending work from live data.
5. Confirm the user/account is not linked to production or another retained
   location.
6. Recalculate the exact deletion set and compare it with the signed manifest.
7. Stop on any mismatch; do not expand the deletion set automatically.

### Phase B: Block new sending

1. Set the location token/integration state to an intermediate cleanup lock:
   `cleanup_in_progress=true` and `toggle_enabled=false`.
2. Make all SMS gates reject `cleanup_in_progress` before billing or provider
   submission.
3. Stop or skip scheduled location work.
4. Verify native provider, workflow, and direct API sends are blocked.

This lock closes the race where a message could begin while uninstall/deletion
is running.

### Phase C: Uninstall from HighLevel

1. Resolve and validate the Marketplace `appId`.
2. Ensure the token is usable; allow `GhlClient` to refresh when appropriate.
3. Call the location-level uninstall endpoint.
4. Record the safe response and request ID.
5. Accept the matching `UNINSTALL` webhook idempotently.
6. Confirm local lifecycle state becomes `UNINSTALLED`, `is_live=false`, and
   `toggle_enabled=false`.
7. Verify the NOLA provider cannot send from the Conversation tab.

If the token is expired or revoked and cannot refresh, stop in
`RECONNECT_REQUIRED` or use an authorized agency token only when its scope and
location ownership are positively verified. Otherwise require manual uninstall
inside GHL. Do not delete local OAuth state while remote status is unresolved.

### Phase D: Delete NOLA account and candidate data

Delete only the path-deduplicated `would_delete` entries approved for the
location. Use bounded Firestore batches and record each committed batch.

Recommended ordering:

1. scheduled/idempotency/sync artifacts;
2. notifications, templates, and non-pending sender requests;
3. NOLA-local messages, inbound messages, SMS logs, and conversations;
4. location-specific contacts stored by NOLA;
5. ownership/member/subaccount links;
6. candidate-only user and account records;
7. integrations and nested configuration;
8. agency-subaccount record; and
9. OAuth token last.

Keeping the OAuth token until the final batch preserves remote-uninstall and
diagnostic capability. Before deleting a user/account, run a final reverse-link
query to ensure it has no retained location association.

### Phase E: Cache cleanup

Invalidate:

- admin dashboard caches;
- agency subaccount/dashboard caches;
- account and user profile caches;
- credits registries;
- conversation/contact/template caches for the candidate location; and
- any location lifecycle/bootstrap cache.

### Phase F: Verify

For every completed location, verify:

- bootstrap reports not installed rather than ready;
- Conversation-tab provider sends cannot reach an SMS gateway;
- workflow and direct API sends return a not-installed/cleanup response;
- no credit is deducted after cleanup starts;
- NOLA login for the deleted account fails;
- no candidate-only OAuth or integration secret remains;
- all approved local deletion paths are absent;
- retained financial and shared records still exist;
- native HighLevel conversation history still exists; and
- all three protected production locations can bootstrap and send normally.

Mark the location `VERIFIED` only after every required check passes.

## 8. Webhook and idempotency changes

Keep `install_handle_marketplace_webhook()` idempotent:

- duplicate `UNINSTALL` events must succeed without recreating deleted records;
- events arriving during cleanup should update the audit state;
- events arriving after local deletion must not recreate integration or account
  documents; and
- an uninstall event for a protected production location should disable that
  location according to normal lifecycle behavior but must never invoke the
  test cleanup executor automatically.

Remote uninstall and local destructive cleanup must remain separate actions.
Receiving an ordinary customer uninstall webhook must not delete their account,
messages, or billing history.

## 9. Failure and recovery rules

- **GHL transient error:** keep the cleanup lock, retry with bounded backoff,
  then stop for operator review.
- **Invalid/expired token:** do not delete the token; request reconnect or manual
  uninstall.
- **Unexpected GHL response:** record safely and stop.
- **Partial Firestore batch failure:** resume only from the audit ledger; never
  regenerate a broader deletion set mid-run.
- **Protected/shared dependency discovered:** stop immediately and retain it.
- **Balance becomes non-zero or work becomes pending:** stop before deletion.
- **Verification failure:** keep SMS blocked and mark for investigation.

Rollback is limited because deletion is destructive. Before the first production
cleanup run, export the exact local documents scheduled for deletion to a
restricted backup with a retention deadline. OAuth secrets should not be placed
in ordinary reports; any secret backup requires encrypted restricted storage.

## 10. Testing plan

### Unit/contract tests

- protected locations and companies can never enter execution;
- only `would_delete` decisions are accepted;
- digest mismatch and stale/incomplete manifests are rejected;
- non-zero balances and pending work block cleanup;
- shared users/accounts are retained;
- uninstall request uses the correct method, path, version, and location body;
- 401 refresh/retry and response classification behave correctly;
- tokens are never written to logs or audit records;
- cleanup state transitions are valid and idempotent; and
- duplicate uninstall webhooks do not recreate deleted data.

### Integration tests

- one disposable GHL test location uninstalls successfully;
- NOLA disappears or becomes unusable as the native SMS provider;
- Conversation-tab, workflow, and API sends are blocked;
- no gateway request and no credit deduction occur after the cleanup lock;
- NOLA local test data is removed;
- native GHL conversations remain visible; and
- a second execution produces no destructive surprises.

### Production-protection regression tests

- each protected location remains ready;
- production conversation-provider sending still works;
- shared agency data remains present; and
- retained financial totals are unchanged.

## 11. Rollout plan

1. Implement services, executor, ledger, and tests without enabling execution.
2. Generate a new read-only analysis and compare it with the July 3 baseline.
3. Have an operator approve the candidate list and seven manual-review cases.
4. Run an executor dry run for one disposable test location.
5. Execute one-location canary cleanup.
6. Verify HighLevel UI/provider behavior and conversation preservation manually.
7. Run a small batch of 3-5 locations.
8. Review audit results, Firestore counts, billing totals, and production health.
9. Continue in bounded batches with a stop between batches.
10. Produce a final report listing cleaned, retained, blocked, and failed
    locations.

## 12. Definition of done

The implementation is complete when:

- every approved test installation is removed from HighLevel or explicitly
  recorded as requiring manual uninstall;
- native NOLA SMS cannot send after cleanup begins;
- approved NOLA test accounts and candidate-only records are deleted;
- native HighLevel conversations remain untouched;
- protected, shared, financial, and unresolved records remain intact;
- all cleanup runs are reproducible and auditable;
- rerunning cleanup is safe and idempotent; and
- canary and bounded-batch acceptance tests pass without production regression.
