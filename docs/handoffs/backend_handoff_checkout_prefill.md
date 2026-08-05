# Backend Handoff: Registration & Checkout Pre-fill — Final Verification & Testing

**Status:** Backend implementation confirmed complete ✅  
**Remaining Action:** GHL Funnel Custom Code update (GHL-side only, no backend code changes)

---

## Alignment Verification

| Plan Item | Backend Status | Notes |
|-----------|---------------|-------|
| `ghl_callback.php` — first-run form + re-install detection | ✅ Done | Queries `users` by `active_location_id` |
| `register_from_install.php` — new endpoint | ✅ Done | Creates/links user, updates `integrations` |
| `login.php` — `phone` in response | ✅ Done | `nola_user` localStorage populated on login |
| `.htaccess` — rewrite rule | ✅ Done | `api/auth/register-from-install` route live |
| `_ghl_get_or_create_custom_field()` | ✅ Done | Auto-creates `owner_name/email/phone` fields |
| `_sync_owner_to_ghl()` | ✅ Done | Writes Custom Values after registration |
| `Settings.tsx` — checkout URL params | ✅ Done | Appends `name`, `first_name`, `last_name`, `email`, `phone` |

**Everything is implemented. No backend code changes needed.**

---

## The One Remaining Action: Update GHL Funnel Custom Code

### What the funnel currently does

The checkout funnel has a Custom Code block that reads `location_id` from the URL and fills the hidden `companyname` field:

```javascript
// CURRENT (partial — only handles location_id)
function setCompanyNameFromLocationId() {
  const params = new URLSearchParams(window.location.search);
  const locationId = params.get("location_id");
  if (!locationId) return;

  const input = document.querySelector('input[name="companyname"]');
  if (!input) return;

  input.value = locationId;
  input.setAttribute("value", locationId);
}
```

### What it needs to also do

`Settings.tsx` now sends these URL params when opening checkout:
```
?location_id=MJoecBYPutNZwRw7N7Ud
&name=Maria+Santos
&first_name=Maria
&last_name=Santos
&email=maria%40example.com
&phone=09171234567
```

The funnel custom code needs to also read `name`, `first_name`, `last_name`, `email`, `phone` and fill the order form fields.

### ✅ Updated GHL Funnel Custom Code (replace the existing script)

```javascript
(function () {
  "use strict";

  /* ── URL param reader ───────────────────────────────────────────── */
  const params = new URLSearchParams(window.location.search);

  /* ── Field fill helper ──────────────────────────────────────────── */
  function fillField(selector, value) {
    if (!value) return;
    const el = document.querySelector(selector);
    if (!el) return;
    el.value = value;
    el.setAttribute("value", value);
    // Fire change/input events so GHL's form state picks up the value
    ["input", "change"].forEach(function (evt) {
      el.dispatchEvent(new Event(evt, { bubbles: true }));
    });
  }

  function prefillForm() {
    /* ── location_id → companyname (existing behaviour, preserved) ── */
    const locationId = params.get("location_id");
    fillField('input[name="companyname"]', locationId);

    /* ── Contact info → order form fields ──────────────────────────  */
    // Full Name: GHL order forms typically use input[name="name"]
    // Try both combined and split name fields
    const fullName =
      params.get("name") ||
      [params.get("first_name"), params.get("last_name")]
        .filter(Boolean)
        .join(" ");
    fillField('input[name="name"]',       fullName);
    fillField('input[name="full_name"]',  fullName);
    fillField('input[name="first_name"]', params.get("first_name") || "");
    fillField('input[name="last_name"]',  params.get("last_name")  || "");

    /* ── Email ───────────────────────────────────────────────────── */
    fillField('input[name="email"]', params.get("email") || "");

    /* ── Phone ───────────────────────────────────────────────────── */
    fillField('input[name="phone"]',       params.get("phone") || "");
    fillField('input[name="phone_number"]',params.get("phone") || "");
  }

  /* ── Run on DOM ready + retry loop (GHL renders forms async) ───── */
  document.addEventListener("DOMContentLoaded", function () {
    prefillForm();

    var tries = 0;
    var timer = setInterval(function () {
      prefillForm();
      tries++;
      if (tries > 30) clearInterval(timer); // retry for ~9 seconds
    }, 300);
  });
})();
```

### Where to paste this in GHL

1. Open `sms.nolawebsolutions.com` in GHL → Sites/Funnels
2. Open the funnel page (e.g. `nola-sms-pro---500-credits-page-8465`)
3. Click the **Custom Code** element (visible in the screenshot)
4. Click **Open Code Editor**
5. **Replace** the existing script entirely with the updated code above
6. Repeat for **all 5 credit package pages**:
   - `nola-sms-pro---500-credits-page-8465-657955` (10 credits)
   - `nola-sms-pro---500-credits-page-8465` (500 credits)
   - `nola-sms-pro---1000-credits` (1100 credits)
   - `nola-sms-pro-2750-credits` (2750 credits)
   - `nola-sms-pro-6000-credits` (6000 credits)
7. **Publish** each page

> [!NOTE]
> The funnel also needs the GHL Custom Values fallback:
> In the funnel form field settings, set each field's **Default Value**:
> - Full Name → `{{location.custom_values.owner_name}}`
> - Email → `{{location.custom_values.owner_email}}`
> - Phone → `{{location.custom_values.owner_phone}}`
>
> This fills the form even when the user opens checkout directly (without URL params).

---

## How the Full Flow Works Now

```
① User installs from GHL Marketplace
   └─ ghl_callback.php:
       • Exchanges OAuth code → access_token saved
       • Provisions 10 free credits
       • Checks if location already in users collection
         ├─ Re-install → "Welcome Back" screen ✅
         └─ New install → "Complete Your Account" form

② User fills registration form (name, phone, email, password)
   └─ POST /api/auth/register-from-install:
       • Creates users doc (role: "user", active_location_id: ...)
       • Updates integrations doc (owner_name, owner_email, owner_phone)
       • Calls _sync_owner_to_ghl():
           GET /locations/{id}/customFields → find or create owner_name/email/phone
           PUT /locations/{id}/customValues/{id} → write the values
       • Returns JWT → saved to localStorage as nola_token + nola_user

③ User clicks "Buy Credits" in NOLA SMS Pro
   └─ Settings.tsx handleTopUp():
       • Reads nola_user from localStorage
       • Builds checkout URL:
           ?location_id=XXX
           &name=Maria+Santos&first_name=Maria&last_name=Santos
           &email=maria%40example.com&phone=09171234567
       • Opens popup

④ GHL Funnel Checkout loads
   └─ Custom Code script runs:
       • Reads URL params → fills Full Name, Email, Phone instantly
       • Retries every 300ms for 9s (handles GHL async rendering)
   └─ GHL Custom Values fallback:
       • {{location.custom_values.owner_name}} fills if URL params missing

⑤ User clicks "Complete Order"
   └─ GHL Workflow fires:
       POST /api/credits
       X-GHL-Location: {{businessName}}
       { action: "add", amount: 500 }
       → Credits added to NOLA account ✅
```

---

## Testing Guide

### Test 1: First-Time Install Registration

**What to do:**
1. Use a test GHL subaccount that has NOT installed NOLA SMS Pro before
2. Go to GHL Marketplace → install NOLA SMS Pro
3. You will be redirected to `smspro-api.nolacrm.io/oauth/callback`

**Expected result:**
- ✅ Green "Connected to NOLA SMS Pro" badge with the location name
- ✅ "Complete Your Account" form with Location ID and Subaccount Name pre-filled (read-only)
- ✅ Empty Full Name, Phone, Email, Password fields

**Fill and submit:**
1. Enter: Name = `Test User`, Phone = `09170000001`, Email = `test@example.com`, Password = `Test1234!`
2. Click "Complete Setup →"

**Expected result:**
- ✅ Form hides, success card appears: "Welcome, Test! Your account has been created."
- ✅ `localStorage.nola_token` is set (JWT)
- ✅ `localStorage.nola_user` is set: `{ firstName, lastName, email, phone, location_id }`

**Verify in Firestore:**
```
Collection: users
→ Find doc where email = "test@example.com"
→ Should have: role: "user", active_location_id: <locationId>, source: "marketplace_install"

Collection: integrations
→ Find doc "ghl_<locationId>"
→ Should have: owner_email, owner_name, owner_phone
```

**Verify in GHL:**
```
GHL → Location Settings → Custom Fields
→ Should see: Owner Name = "Test User", Owner Email = "test@...", Owner Phone = "09170000001"
```

---

### Test 2: Re-Install (Same Location)

**What to do:**
1. Use the SAME location from Test 1
2. Re-run the install flow (go to Marketplace → Install again)

**Expected result:**
- ✅ "Welcome Back!" screen with the location name and owner name
- ✅ No registration form
- ✅ "Open Dashboard" button works

---

### Test 3: External Login + localStorage

**What to do:**
1. Go to `app.nolacrm.io/login`
2. Log in with `test@example.com` / `Test1234!`

**Expected result:**
- ✅ Logged in successfully
- ✅ Check `localStorage.nola_user` in browser devtools:
  ```json
  {
    "firstName": "Test",
    "lastName": "User",
    "email": "test@example.com",
    "phone": "09170000001",
    "location_id": "...",
    "company_id": null
  }
  ```

---

### Test 4: Checkout Pre-fill (URL Params)

**What to do:**
1. Log in as the test user (so `nola_user` is in localStorage)
2. Go to Settings → Credits
3. Select any credit package and click "Buy Credits"
4. The checkout popup opens

**Expected result:**
- ✅ Checkout URL contains: `?location_id=...&name=Test+User&first_name=Test&last_name=User&email=test%40example.com&phone=09170000001`
- ✅ Full Name field = "Test User"
- ✅ Email field = "test@example.com"
- ✅ Phone field = "09170000001"
- ✅ `companyname` hidden field = location ID (existing behaviour)

**To check URL:** In browser devtools → Network tab → watch for the popup URL when you click "Buy Credits"

---

### Test 5: GHL Custom Values Fallback

**What to do:**
1. Open the checkout URL directly WITHOUT the contact params:
   ```
   https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465?location_id=<locationId>
   ```
2. The URL params for name/email/phone are missing

**Expected result (after GHL Funnel Builder config):**
- ✅ Fields pre-filled from `{{location.custom_values.owner_name}}` etc.
- ✅ This confirms the GHL Custom Values write was successful

---

### Test 6: Checkout → Payment → Credits Added

**What to do:**
1. Complete an actual test purchase (use the ₱10 test package)
2. Fill the pre-filled form → click "Complete Order"

**Expected result:**
- ✅ GHL Workflow triggers
- ✅ Webhook fires to `POST /api/credits` with `action: "add"`, `amount: 10`
- ✅ Credit balance increases in NOLA SMS Pro
- ✅ Transaction appears in the ledger

---

## Quick Verification Curl Commands

```bash
# Test register-from-install endpoint
curl -X POST https://smspro-api.nolacrm.io/api/auth/register-from-install \
  -H "Content-Type: application/json" \
  -d '{
    "full_name": "Test User",
    "phone": "09170000001",
    "email": "test-verify@example.com",
    "password": "Test1234!",
    "location_id": "YOUR_TEST_LOCATION_ID",
    "company_id": ""
  }'
# Expected: 201 { "status": "success", "token": "eyJ...", "role": "user" }

# Test login returns phone
curl -X POST https://smspro-api.nolacrm.io/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "test-verify@example.com", "password": "Test1234!"}'
# Expected: 200 { "token": "...", "user": { "phone": "09170000001", ... } }

# Test re-install (same location — should return 200 "linked")
curl -X POST https://smspro-api.nolacrm.io/api/auth/register-from-install \
  -H "Content-Type: application/json" \
  -d '{
    "full_name": "Test User",
    "phone": "09170000001",
    "email": "test-verify@example.com",
    "password": "Test1234!",
    "location_id": "YOUR_TEST_LOCATION_ID",
    "company_id": ""
  }'
# Expected: 200 { "status": "linked" }
```

---

## Summary: What Still Needs to be Done (GHL-side only)

| Action | Where | Who |
|--------|-------|-----|
| Replace Custom Code script in each funnel page | GHL Funnel Builder | You / GHL Admin |
| Set Default Values in form fields to `{{location.custom_values.owner_name}}` etc. | GHL Funnel Builder | You / GHL Admin |
| Publish all 5 funnel pages after changes | GHL Funnel Builder | You / GHL Admin |

**No backend or frontend code changes are needed.** Everything is deployed.
