# Frontend Handoff: Fix Settings N/A Inside GHL Iframe

**Status**: Ready to implement  
**Priority**: High  
**Affects**: `user/src/hooks/useUserProfile.ts`, `user/src/App.tsx`, `user/src/pages/Settings.tsx`

---

## Why It Is N/A Inside GHL

The full chain of failure:

1. `auth-handoff.html` appends `?token=JWT` to the redirect URL → **correctly lands in the React app**
2. `AuthContext.tsx` reads the token from `?token=` and writes it to `safeStorage` (in-memory) → **works**
3. Inside the GHL iframe, `localStorage` is blocked, so `safeStorage` stores the token **only in its in-memory `memoryStore`** — it never reaches real `localStorage`
4. `useUserProfile.ts` is called in `AppLayout` but **its return value is thrown away** — `App.tsx` line 30:
   ```ts
   useUserProfile(); // ← return value discarded, nobody re-renders from it
   ```
5. `Settings.tsx` reads profile fields from `localStorage` directly (stale or empty keys), not from a live API call → **N/A**

---

## Fix 1 — `useUserProfile.ts`: read token from `safeStorage`, not raw `localStorage`

The `getToken()` fallback uses `localStorage.getItem()` directly, which is blocked inside GHL.
Replace the fallback with `safeStorage.getItem()` so it reads from the in-memory store that `AuthContext` already populated.

**File:** `user/src/hooks/useUserProfile.ts`

```diff
- import { getSession } from '../services/authService';
+ import { getSession, SESSION_KEYS } from '../services/authService';
+ import { safeStorage } from '../utils/safeStorage';

  function getToken(): string | null {
    try {
      const sessionToken = getSession()?.token;
      if (sessionToken) {
        console.log("[useUserProfile] Token retrieved via getSession()");
        return sessionToken;
      }
-     // Fallback: raw localStorage (handles GHL iframe / auth-handoff users)
-     const rawToken = localStorage.getItem('nola_auth_token');
+     // Fallback: safeStorage (works even when localStorage is blocked in GHL iframe)
+     const rawToken = safeStorage.getItem(SESSION_KEYS.token);
      if (rawToken) {
        console.log("[useUserProfile] Token retrieved via safeStorage fallback");
        return rawToken;
      }
      console.log("[useUserProfile] No token found in any storage.");
      return null;
    } catch (e) {
      console.error("[useUserProfile] Error getting token:", e);
      return null;
    }
  }
```

---

## Fix 2 — `App.tsx`: capture the `useUserProfile` return value

Right now the hook runs but nobody listens to it. Pass the result into a shared context or at minimum expose it to child routes.

**File:** `user/src/App.tsx`

```diff
- import { useUserProfile } from "./hooks/useUserProfile";

+ import { useUserProfile, type UserProfile } from "./hooks/useUserProfile";
+ import React, { useState, useEffect, createContext, useContext } from "react";

+ // Create a simple context so Settings and other pages can consume the live profile
+ export const UserProfileContext = createContext<UserProfile | null>(null);
+ export const useUserProfileContext = () => useContext(UserProfileContext);

  const AppLayout: React.FC = () => {
-   // Dynamically fetch and sync profile immediately on app boot
-   useUserProfile();
+   const userProfile = useUserProfile();

    return (
+     <UserProfileContext.Provider value={userProfile}>
        <div className="h-screen bg-[#ffffff] dark:bg-[#1a1b1e]">
          {/* ... existing JSX ... */}
        </div>
+     </UserProfileContext.Provider>
    );
  };
```

---

## Fix 3 — `Settings.tsx`: fetch profile from `/api/auth/me` on mount

Stop reading from `localStorage` keys directly. Instead consume the live `UserProfileContext` (populated by the hook), with `/api/auth/me` as the authoritative source.

**File:** `user/src/pages/Settings.tsx`

At the top of the component that renders the Account/Profile section, add:

```tsx
import { useUserProfileContext } from '../App';

// Inside the Settings component:
const liveProfile = useUserProfileContext();

// Then display fields from liveProfile instead of localStorage:
const displayName  = liveProfile?.name        ?? liveProfile?.firstName ?? 'N/A';
const displayEmail = liveProfile?.email        ?? 'N/A';
const displayPhone = liveProfile?.phone        ?? 'N/A';
const displayLoc   = liveProfile?.location_name ?? 'N/A';
```

If `Settings.tsx` currently reads like this (which causes N/A):
```ts
// BAD — reads stale / blocked localStorage
const user = JSON.parse(localStorage.getItem('nola_auth_user') ?? 'null');
const name  = user?.name  ?? 'N/A';
const email = user?.email ?? 'N/A';
```

Replace it with the `useUserProfileContext()` pattern above.

---

## How the Full Fixed Flow Works

```
GHL installs app
      │
      ▼
auth-handoff.html appends ?token=JWT to redirect URL
      │
      ▼
AuthContext reads ?token= → safeStorage.setItem('nola_auth_token', token)
(works even when localStorage is blocked)
      │
      ▼
useUserProfile() → getToken() → safeStorage.getItem('nola_auth_token') ✅
      │
      ▼
fetch GET /api/auth/me  (Authorization: Bearer <token>)
      │
      ▼
Backend reads JWT → looks up users/{uid} in Firestore → returns live profile
      │
      ▼
useUserProfile returns { name, email, phone, location_name, ... }
      │
      ▼
UserProfileContext.Provider distributes to all children
      │
      ▼
Settings.tsx reads from context → displays real data ✅ (no N/A)
```

---

## Backend API Reference

**Endpoint:** `GET /api/auth/me`  
**Host:** `https://smspro-api.nolacrm.io`  
**Auth:** `Authorization: Bearer <token>`

**Response (200):**
```json
{
  "user": {
    "name": "Jane Smith",
    "firstName": "Jane",
    "lastName": "Smith",
    "email": "jane@company.com",
    "phone": "+15550001234",
    "location_id": "abc123",
    "company_id": "xyz789",
    "location_name": "Jane's Business",
    "company_name": null,
    "location_memberships": ["abc123"]
  }
}
```

**Error (401):** Token missing or expired → redirect to `/login`  
**Error (404):** User document not found in Firestore

---

## Summary of Files to Change

| File | Change |
|---|---|
| `user/src/hooks/useUserProfile.ts` | Replace `localStorage.getItem` fallback with `safeStorage.getItem` |
| `user/src/App.tsx` | Capture hook return, add `UserProfileContext.Provider` |
| `user/src/pages/Settings.tsx` | Consume `useUserProfileContext()` instead of reading raw `localStorage` |

These three changes together close the entire N/A gap, both inside and outside GHL.
