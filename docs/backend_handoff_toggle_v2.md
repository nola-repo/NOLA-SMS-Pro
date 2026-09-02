# Backend Handoff — Agency Toggle Fix (v2)

**Date**: April 13, 2026  
**Priority**: Critical  
**Repo Scanned**: https://github.com/nola-repo/NOLA-SMS-Pro  
**Affected Paths**: `api/agency/`, `api/webhook/`

---

## Problem Summary

The Agency Panel toggle (ON/OFF per subaccount) has **three separate backend bugs** that together cause:
- Subaccounts always show as OFF after install or sync
- Subaccounts can still send SMS even when toggled OFF in the Agency Dashboard

---

## Bug 1 — `api/agency/sync_locations.php` (CRITICAL — REMOTE ONLY)

**Line 110** hardcodes `toggle_enabled = false` for every new subaccount synced from GHL.

```diff
// Lines 108-114 — New sub-account default values:
  if (!$snapshot->exists()) {
-     $updateData['toggle_enabled'] = false;   // ← BUG: sets OFF by default
+     $updateData['toggle_enabled'] = true;    // ← FIX: ON by default
      $updateData['rate_limit']     = 100;
      $updateData['attempt_count']  = 0;
      $updateData['created_at']     = $now;
  }
```

**Impact**: Every time the agency runs "Sync Locations", all new subaccounts default to `toggle_enabled = false` in `agency_subaccounts`. The frontend shows them as OFF, and the agency has to manually toggle each one back on.

---

## Bug 2 — `api/agency/update_subaccount.php` (CRITICAL — BOTH LOCAL & REMOTE)

### Remote version
Writes `toggle_enabled` **only to `agency_subaccounts`**, but `send_sms.php` and `ghl_provider.php` **both read** from `ghl_tokens`. Toggling OFF in the agency panel has **no effect** on actual SMS enforcement.

### Local version (before fix)
Writes `toggle_enabled` to `ghl_tokens` only — does NOT mirror to `agency_subaccounts` — so the UI doesn't reflect the toggle state.

**Fix applied to local**: After `ghl_tokens` update, also mirrors to `agency_subaccounts`.

**Fix needed on remote**: After `agency_subaccounts` update, also mirror into `ghl_tokens`:

```diff
// After: $docRef->set($updates, ['merge' => true]);

  $docRef->set($updates, ['merge' => true]);

+ // ── Mirror toggle_enabled into ghl_tokens (enforcement layer) ──────────────
+ $tokenRef = $db->collection('ghl_tokens')->document($locationId);
+ $tokenSnap = $tokenRef->snapshot();
+ if ($tokenSnap->exists()) {
+     $tokenRef->set([
+         'toggle_enabled' => $toggleEnabled,
+         'updated_at'     => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable())
+     ], ['merge' => true]);
+ }

  echo json_encode(['status' => 'success']);
```

---

## Bug 3 — `api/webhook/ghl_provider.php` (STATUS)

- **Local version**: ✅ Already has the toggle check (lines 166-183)
- **Remote version**: ❌ Missing the check — need to deploy the local version OR apply the patch manually

**Fix** — Insert after the dedup `exit;` block, before `$intDocId =` loading integration config:

```php
// ── Check Agency Toggle ─────────────────────────────────────────────────────
if (!isset($db)) {
    $db = get_firestore();
}
$tokenRef  = $db->collection('ghl_tokens')->document($locationId);
$tokenSnap = $tokenRef->snapshot();
$tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
$toggleEnabled = isset($tokenData['toggle_enabled']) ? (bool)$tokenData['toggle_enabled'] : true;

if (!$toggleEnabled) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'SMS sending is currently disabled for this account. Please contact your agency.'
    ]);
    exit;
}
```

---

## Bug 4 — `ghl_oauth.php` — Status: OK ✅

Sets `'toggle_enabled' => true` when a subaccount first installs. **No change needed.**

---

## Collection Mapping Reference

| Collection | Written By | Read By | Role |
|---|---|---|---|
| `ghl_tokens` | `ghl_oauth.php`, `update_subaccount.php` (after fix) | `send_sms.php`, `ghl_provider.php`, `check_installs.php` | **SMS enforcement source** |
| `agency_subaccounts` | `sync_locations.php` (after fix), `update_subaccount.php` | `get_subaccounts.php` (UI display) | **UI display source** |

> Both collections MUST be in sync. `ghl_tokens.toggle_enabled` is authoritative for enforcement. `agency_subaccounts.toggle_enabled` is what the Agency Panel displays.

---

## Frontend Fix Applied — `agency/src/pages/Subaccounts.tsx`

**Problem**: Toggle was hidden behind `installedLocations.has(sub.location_id)`. If `check_installs.php` times out or returns an empty array, the toggle is hidden and shows "Not Installed" instead.

**Fix applied**:
```diff
- {installedLocations.has(sub.location_id) ? (
+ {(installedLocations.size === 0 || installedLocations.has(sub.location_id) || sub.is_live) ? (
```

Also added `toggleSubaccount` API function in `api.ts` that calls `toggle_subaccount.php` via PATCH — separate from the general `updateSubaccountSettings` (which handles rate limit and counter resets).

---

## Deployment Checklist

| # | File | Location | Status | Action |
|---|---|---|---|---|
| 1 | `api/agency/sync_locations.php` | Remote only | ❌ Not fixed | Change `toggle_enabled = false` → `true` line 110 |
| 2 | `api/agency/update_subaccount.php` | Local ✅ Fixed | Remote ❌ | Deploy local version OR apply mirror patch |
| 3 | `api/webhook/ghl_provider.php` | Local ✅ Fixed | Remote ❌ | Deploy local version |
| 4 | `agency/src/pages/Subaccounts.tsx` | Local ✅ Fixed | — | Deploy frontend |
| 5 | `agency/src/services/api.ts` | Local ✅ Fixed | — | Deploy frontend |

---

## One-Time Data Fix

Run this to correct all existing subaccounts that were set to OFF by `sync_locations.php`:

```php
<?php
// one_time_fix_toggle.php — run ONCE
require __DIR__ . '/webhook/firestore_client.php';
$db = get_firestore();

$results = $db->collection('agency_subaccounts')->documents();
$fixed = 0;
foreach ($results as $doc) {
    if (!$doc->exists()) continue;
    if (($doc->data()['toggle_enabled'] ?? true) === false) {
        $locId = $doc->id();
        $doc->reference()->set(['toggle_enabled' => true], ['merge' => true]);
        $tokenRef = $db->collection('ghl_tokens')->document($locId);
        if ($tokenRef->snapshot()->exists()) {
            $tokenRef->set(['toggle_enabled' => true], ['merge' => true]);
        }
        $fixed++;
        echo "Fixed: $locId\n";
    }
}
echo "Total fixed: $fixed\n";
```

> ⚠️ Only run if all existing OFF toggles should be reset to ON. Review with agency owner first.

---

## Logic Flow After All Fixes

```
Agency Panel: toggle → OFF
        ↓
PATCH /api/agency/toggle_subaccount.php
        ↓
  agency_subaccounts.toggle_enabled = false  ✅ (UI)
  ghl_tokens.toggle_enabled = false          ✅ (enforcement — mirrored)

Subaccount sends via GHL Chat (ghl_provider.php)
        ↓ Reads ghl_tokens → false → 403 Blocked ✅

Subaccount sends via Workflow/API (send_sms.php)
        ↓ Reads ghl_tokens → false → 403 Blocked ✅

New subaccount synced via sync_locations.php
        ↓ agency_subaccounts.toggle_enabled = true  ✅ (fixed default)

New install via ghl_oauth.php
        ↓ ghl_tokens.toggle_enabled = true  ✅ (always was correct)
```
