# Backend Handoff: Agency Registration via GHL Callback + Multi-User Fix

**To:** Backend Team  
**Updated:** Covers both location AND company installs. Agency registration now unified under `ghl_callback.php` — no separate signup page needed.

---

## Overview: Unified Registration Flow

Both **subaccount users** and **agency owners** now register through the same `ghl_callback.php` form. The only difference is which ID is pre-filled (location_id vs company_id).

```
Sub-account installs app (Location-level)
  → ghl_callback.php detects userType:"Location"
  → Form shows with location_id + subaccount name pre-filled
  → register_from_install.php creates role:"user", active_location_id set

Agency installs app (Company-level)
  → ghl_callback.php detects userType:"Company"
  → Form shows with company_id + agency name pre-filled
  → register_from_install.php creates role:"agency", company_id set
```

`register_from_install.php` already handles both cases correctly ✅ — no changes needed there.

---

## What `register_from_install.php` Returns (Both Cases)

For a **location install** (role: user):
```json
{
  "status": "success",
  "token": "eyJ...",
  "role": "user",
  "location_id": "LOC_123",
  "company_id": null,
  "user": { "firstName": "Maria", "lastName": "Santos", "email": "...", "phone": "..." }
}
```

For a **company install** (role: agency):
```json
{
  "status": "success",
  "token": "eyJ...",
  "role": "agency",
  "location_id": null,
  "company_id": "CO_456", 
  "user": { "firstName": "John", "lastName": "Cruz", "email": "...", "phone": "..." }
}
```

---

## Changes Required in `ghl_callback.php`

### 1. Remove the Firestore `$userExists` block entirely

Delete this block (it causes the "Welcome Back" to show too early):

```php
// ─── Check for existing users record ──────────────────────────────────────────
$userExists = false;
try {
    $usersRef = $db->collection('users');
    // ... query ...
    foreach ($userQuery as $doc) { ... }
} catch (Exception $e) { ... }

if ($userExists) {
    // ... Welcome Back render ...
    exit;
}
```

**Replace with:** Skip directly to rendering the registration form. "Welcome Back" is handled client-side.

---

### 2. Update the JS success handler to save `nola_user` OR `nola_agency` to localStorage

In the `handleInstallRegister` JS function (inside `$formBody`), after `data.token` is received, update the localStorage write to branch on role:

```javascript
// After receiving successful response from register_from_install:
if (data.token) {
    localStorage.setItem('nola_token', data.token);
}

if (data.user) {
    const profile = {
        firstName:  data.user.firstName || '',
        lastName:   data.user.lastName  || '',
        email:      data.user.email     || '',
        phone:      data.user.phone     || '',
        location_id: data.location_id  || null,
        company_id:  data.company_id   || null,
    };

    // Always write nola_user (used for "Welcome Back" detection on next install)
    localStorage.setItem('nola_user', JSON.stringify(profile));

    // For agency installs, ALSO write nola_agency (used by agency portal pre-fill)
    if (data.role === 'agency') {
        localStorage.setItem('nola_agency', JSON.stringify(profile));
    }
}
```

---

### 3. Add client-side "Welcome Back" detection (replaces the Firestore check)

Add this IIFE at the very top of the `<script>` block in `$formBody`, **before** the `handleInstallRegister` function:

```javascript
// ── Client-side "Welcome Back" detection ─────────────────────────────────────
(function checkReturningUser() {
    try {
        const token   = localStorage.getItem('nola_token');
        const rawUser = localStorage.getItem('nola_user');
        if (!token || !rawUser) return; // No session — show the form

        const profile = JSON.parse(rawUser);

        // PHP injects these — the current install's IDs
        const currentLocationId = <?php echo $locationIdJs; ?>;  // null for company installs
        const currentCompanyId  = <?php echo $companyIdJs; ?>;   // null for location installs

        // Check if this browser already registered for this exact location/company
        const locationMatch = currentLocationId && profile.location_id === currentLocationId;
        const companyMatch  = currentCompanyId  && profile.company_id  === currentCompanyId;

        if (!locationMatch && !companyMatch) return; // Different account — show form

        // Same location/company AND we have a token → this device already registered
        const firstName = profile.firstName || 'there';
        document.getElementById('registration-view').style.display = 'none';
        document.getElementById('welcome-back-view').style.display  = 'block';
        document.getElementById('wb-name').textContent = 'Welcome back, ' + firstName + '!';

    } catch (e) {
        // Silently fail — default to showing the form
    }
})();

function showRegistrationForm() {
    document.getElementById('welcome-back-view').style.display  = 'none';
    document.getElementById('registration-view').style.display  = 'block';
}
```

---

### 4. Add the hidden `#welcome-back-view` div to `$formBody`

Add this **after** `<div id="success-view">` in the PHP `$formBody` string:

```html
<!-- Welcome Back: shown by JS if localStorage already has a matching session -->
<div id="welcome-back-view" style="display:none; text-align:center;">
    <div class="success-ring">
        <div class="success-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
    </div>
    <h1 id="wb-name">Welcome back!</h1>
    <p class="subtitle"><b><?= htmlspecialchars($displayNameSafe) ?></b> is already connected to your account.</p>

    <div style="display:flex; flex-direction:column; gap:14px; margin-bottom:28px;">
        <a href="<?= $dashboardUrl ?>" class="btn-primary">Open Dashboard &rarr;</a>
    </div>

    <p style="font-size:12px; color:#a1a1aa; margin-top:8px;">
        Not you? 
        <span onclick="showRegistrationForm()" 
              style="color:#2b83fa; font-weight:700; cursor:pointer;">
            Register a different account
        </span>
    </p>
</div>
```

---

### 5. Add owner guard in `_update_integration_owner()`

Prevent subsequent registrants from overwriting the first registrant's owner info in the `integrations` doc:

```php
function _update_integration_owner($db, string $locationId, string $email, string $fullName, string $phone, $now): void {
    if (!$locationId) return;

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    try {
        // ── NEW: Only set owner if not already recorded ──
        $snap = $db->collection('integrations')->document($intDocId)->snapshot();
        if ($snap->exists() && !empty($snap->data()['owner_email'])) {
            return; // First registrant is the permanent owner — don't overwrite
        }
        // ─────────────────────────────────────────────────

        $db->collection('integrations')->document($intDocId)->set([
            'owner_email' => $email,
            'owner_name'  => $fullName,
            'owner_phone' => $phone,
            'updatedAt'   => new \Google\Cloud\Core\Timestamp($now),
        ], ['merge' => true]);
    } catch (Exception $e) {
        error_log("register_from_install: failed to update integration owner for $locationId: " . $e->getMessage());
    }
}
```

---

## `Register.tsx` Status

`Register.tsx` is now a **fallback only** — it is no longer the primary agency signup path.

| Path | Status |
|------|--------|
| Agency installs via GHL Marketplace (Company-level) → `ghl_callback.php` | ✅ Primary path |
| Manual signup at `app.nolacrm.io/register` → `Register.tsx` | 🔁 Fallback (keep for now) |

The `Register.tsx` page can stay in the codebase as a backup for agencies who aren't using the GHL Marketplace. It is not broken and no changes are required to it.

---

## Full Multi-User Scenario Summary

| Scenario | What Happens |
|----------|-------------|
| First install, new user/agency | No localStorage → form shown → account created → localStorage set |
| Same person, same device, re-installs | localStorage matches location/company → "Welcome Back" shown |
| Same person, different device | No localStorage → form shown → same email → `status: "linked"`, fresh token returned, localStorage populated |
| Different person, same subaccount | No localStorage match → form shown → NEW users doc created → both share same `active_location_id` |
| Agency owner, company install | Same flow, `company_id` pre-filled → `role:"agency"` created → `nola_agency` also saved to localStorage |

---

## localStorage Keys Written by `ghl_callback.php` After Registration

| Key | Who gets it | Value |
|-----|-------------|-------|
| `nola_token` | All | JWT string |
| `nola_user` | All | `{ firstName, lastName, email, phone, location_id, company_id }` |
| `nola_agency` | Agency only | Same as `nola_user` (agency portal pre-fill) |
