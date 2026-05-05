# Blank page after bulk installation
_Exported on 5/5/2026 at 11:37:56 GMT+8 from Cursor (3.2.21)_

---

**User**

after reinstallation it still white blank page when it should redirect to registrer form from RegisterFromInstal.tsx but the app is installed its just the register form didnt appear causing right after the app is installed to subaccount the the parameters inside the app settings remains N/A
https://github.com/nola-repo/nola-sms-pro-frontend 

---

**Cursor**

Tracing the bulk-install → registration flow: exploring the workspace and the frontend reference you provided.



Workspace contains the PHP redirect to `/login?bulk_install=...`. Fetching the frontend's login flow and `RegisterFromInstal` from GitHub.



Fetching uncorrupted `App.tsx` and checking `ghl_callback.php` for the redirect URL and whether it includes `install_token`.



Tracing Case B vs single-location logic in `ghl_callback.php` to determine why we get two provisioned locations.



Identifying the issue: `RegisterFromInstall.tsx` requires `install_token` on `/register-from-install`. When Case B provisions multiple locations, `ghl_callback.php` redirects to `/login?bulk_install=1&count=N` — no registration token — so users never complete onboarding (settings stay N/A). A blank white page can occur if the login branding fetch to `/api/public/whitelabel` never resolves (SharedLogin stays in the loading state). Implementing backend fixes: narrow bulk provisioning when OAuth state pins a single location, and extend state parsing for common GHL payload shapes.



Implementing improved OAuth state parsing and narrowing Case B when state pins one location among several candidates so we redirect to `/register-from-install?install_token=...` instead of `/login?bulk_install=...`.



Here is what was going wrong and what we changed in your backend.

## What was happening

1. **`RegisterFromInstall.tsx`** (note the spelling in the repo: [`RegisterFromInstall.tsx`](https://github.com/nola-repo/nola-sms-pro-frontend/blob/main/user/src/pages/RegisterFromInstall.tsx)) only runs on **`/register-from-install`**. It **requires** `install_token` in the query string. Without it, the UI ends up in the “invalid link” path — not the full onboarding you expect.

2. In **`ghl_callback.php`**, when GoHighLevel’s bulk-company OAuth flow finishes and **more than one location** is provisioned, the callback deliberately sends users to **login**, not registration:

```919:924:c:\Users\niceo\public_html\ghl_callback.php
    } else {
        // True multi-location bulk install — redirect to login
        // Each sub-account admin registers independently from within their location.
        error_log("[GHL_CALLBACK] Case B: true bulk install (" . count($successfulLocIds) . " locations) — redirect to login.");
        header('Location: ' . $reactAppUrlB . '/login?bulk_install=1&count=' . count($successfulLocIds), true, 302);
    }
```

So your screenshot URL **`/login?bulk_install=1&count=2`** means the backend treated the install as **two-location bulk**, skipped **`install_token`**, and never sent anyone to **`RegisterFromInstall`**. That matches settings staying **N/A** (no completed **`register-from-install`** flow).

3. **Why it looked “single sub-account” to you:** Case A only treats **`locations[]` as single-location when there is exactly one row**. If GHL sends **two** entries (or company search returns two IDs) while the **real** sub-account is still identifiable from OAuth **`state`**, the old logic fell through to Case B → **`count=2`** → login.

## What we fixed (in `ghl_callback.php`)

- **`location_id_from_assoc_json()`** — reads **`selectedLocationId`**, a **single-element `location_ids`**, or a **single-element nested `locations`** from decoded state JSON (common GHL-shaped payloads).
- **`extract_location_id_from_state()`** — uses that helper and **`selectedLocationId`** in query-string state.
- **Case A** — if **`locations[]` has multiple rows**, we still pick the row whose id matches the location inferred from **`state`** so you stay on the **single-location** path (registration / welcome-back) instead of bulk.
- **Case B** — after building the candidate location list, if **`state` pins one id that appears in that list**, we **narrow to that one location** so provisioning + redirect follow the **single-location** rules (including **`/register-from-install?install_token=...`** when there are no members yet).

Syntax check: `php -l ghl_callback.php` passes.

After deploy, reinstall from the problematic sub-account and confirm logs for lines like **`Case A: OAuth state matched locations[] entry`** or **`Case B: state pins ... narrowing to single-location flow`**, then a redirect to **`/register-from-install?...`** instead of **`/login?bulk_install=...`**.

## About the **white** screen on `/login`

In [`SharedLogin.tsx`](https://github.com/nola-repo/nola-sms-pro-frontend/blob/main/user/src/pages/SharedLogin.tsx), the logo area stays in a **loading pulse** until **`/api/public/whitelabel`** completes. If that request **never finishes** on **`app.nolacrm.io`**, the page can look almost blank. Worth checking DevTools **Network** for that call and adding a **timeout / `finally`** guard in the frontend if needed — that part lives in **nola-sms-pro-frontend**, not in this PHP repo.

If you still get **`bulk_install` with `count=2`** after deploy, GHL may be doing a **real** two-location install with **no usable location in `state`**; in that case the next step is either a **frontend banner** on `/login?bulk_install=1` explaining “open each sub-account and complete registration,” or a **product decision** to mint tokens per location differently — say the word if you want to sketch that flow.

---

**User**

yes

---

**Cursor**

Implementing the bulk-install login banner and a whitelabel-fetch timeout. Checking whether the frontend repo exists in the workspace.



Cloning the frontend repo shallowly and implementing the bulk-install banner plus a whitelabel fetch timeout.



Implementing branding timeout, showing the default logo during load (avoids a blank-looking screen), and adding a bulk-install banner.



---

**User**

dont clone the repo and delete that folder instead make a handoff for the frontend team 

