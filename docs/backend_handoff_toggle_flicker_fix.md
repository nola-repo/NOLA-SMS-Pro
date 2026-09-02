# Frontend Handoff — Advanced Polling & Toggle Flicker Fix

**Date**: April 15, 2026  
**Priority**: High  
**Affected Repo**: `nola-repo/NOLA-SMS-Pro` — Agency Frontend  
**Files Changed**: `agency/src/pages/Subaccounts.tsx`

---

## Status Summary

| Layer | Status | Note |
|---|---|---|
| `api/agency/update_subaccount.php` | ✅ Correct — No changes needed | POSTs to correct endpoint, correct payload |
| `agency/src/services/api.ts` | ✅ Already correct | Calls `update_subaccount.php` with `toggle_enabled` |
| `agency/src/pages/Subaccounts.tsx` | ✅ Fixed in this handoff | 5-Second Per-Location Toggle Lock applied |

---

## The Root Cause (Firestore Propagation Delay)

Even after fixing the endpoint, the toggle was still flickering (turning OFF, snapping ON, then settling OFF later). 

**The Sequence That Caused the Flicker:**
```
1. User clicks toggle OFF
2. Optimistic UI: toggle flips OFF immediately
3. API call fires & succeeds → writes `toggle_enabled: false` to Firestore
4. Poll interval fires immediately after 
5. fetchSubaccounts() reads from Firestore BEFORE the write propagates across all Google Cloud read replicas (especially on Cloud Run cold starts)
6. Poll returns `toggle_enabled: true` (stale)
7. React state overwrites optimistic UI → UI snaps BACK to ON
8. Next poll (10s later) finally gets the right true value.
```

The initial `600ms` global block wasn't enough because Cloud Run network latency/Firestore write synchronization sometimes takes 1-3 seconds.

---

## The Advanced Polling Architecture (The 5-Second Lock)

Instead of a brute-force block on all polling, we implemented a **Per-Location 5-Second Lock** mechanism.

**File:** `agency/src/pages/Subaccounts.tsx`

### 1. The Lock Reference Map
```typescript
// Per-location toggle lock: after a successful write, ignore server toggle_enabled
// for 5 seconds so Firestore propagation doesn't snap the UI back.
const toggleLocksRef = useRef<Map<string, { enabled: boolean; until: number }>>(new Map());
```
This map holds a 5-second timestamp for *only* the specific Subaccount that was toggled.

### 2. Upgraded Polling Merge Logic (The Core Fix)
The 10-second background poll still runs uninterrupted. However, when it receives the data payload from the backend, it overrides the server's `toggle_enabled` value with our local lock (if the lock hasn't expired).

```typescript
const fetchSubaccounts = useCallback(async ({ silent = false } = {}) => {
  ...
  const data = await getSubaccounts(agencyId);
  const now = Date.now();
  
  // Merge server data but keep locally-locked toggle states
  const merged = (data.subaccounts || []).map((s: any) => {
    const lock = toggleLocksRef.current.get(s.location_id);
    if (lock && lock.until > now) {
      return { ...s, toggle_enabled: lock.enabled }; // Force UI to keep optimistic value
    }
    return s;
  });
  setSubaccounts(merged);
  ...
```

### 3. Applying the Lock on Toggle Success
When the toggle API POST completes successfully, we set the locker.

```typescript
const handleToggle = async (locationId, enabled) => {
  ...
  try {
    await toggleSubaccount(agencyId, { subaccount_id: locationId, enabled });
    
    // Confirmed success — set lock for 5 seconds
    toggleLocksRef.current.set(locationId, { enabled, until: Date.now() + 5000 });
    ...
  } catch (e: any) {
    // Failure — delete lock immediately so the next poll restores the actual server value
    toggleLocksRef.current.delete(locationId);
    ...
  }
};
```

---

## Why this is superior to Realtime Streams (Firebase)
- **Zero Backend Changes**: This required no changes to the PHP backend or Firestore setup.
- **Maximized Security**: The agency frontend continues to proxy through `get_subaccounts.php`, which runs secure server-side logic.
- **Fail-Safe**: If an API call fails, the cache is instantly deleted, meaning the user won't get stuck staring at an incorrect state; the server restores the truth immediately.
- **No Global UI Freezing**: If a user is rapidly toggling Location A, it has zero collision with polls fetching data updates for Location B and C.

**Testing Confirmed**: Toggling multiple subaccounts rapidly no longer triggers the race condition snapback. Polling operates completely behind-the-scenes.
