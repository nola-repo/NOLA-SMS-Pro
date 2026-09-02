# Backend Handoff — Forgot Password OTP & GoHighLevel Workflow Integration

This document is the full backend handoff for the OTP-based password reset flows across **all three portals** (User, Agency, Admin) and explains how to wire up GoHighLevel (GHL) workflow delivery for OTP codes.

---

## 1. Backend Code Scan Summary

Three backend files already exist to support the secure OTP forgot-password flow:

| File | Route (via .htaccess) | Purpose |
| :--- | :--- | :--- |
| `api/auth/forgot_password_otp.php` | `POST /api/auth/forgot-password-otp` | Accepts `{ email }`, generates a 6-digit OTP, saves it to Firestore with a 10-min TTL, sends email via `mail()` |
| `api/auth/reset_password_otp.php` | `POST /api/auth/reset-password-otp` | Accepts `{ email, otp, new_password }`, verifies OTP, hashes new password, clears OTP fields |
| `api/scratch_test_otp.php` | CLI only | Seeds a test user, validates the full OTP reset cycle, cleans up |

### Collections Searched

`forgot_password_otp.php` searches **all three** Firestore collections in order:

1. `admins` — Admin portal users
2. `agency_users` — Agency portal users
3. `users` — Sub-account / user portal users

So a single API endpoint covers all three portals.

---

## 2. Required Route Change — Add `/forgot-password` Page

The current `install-login.php` hides the forgot-password form in the same page (toggling a hidden `<div>`). A new **dedicated page** is needed at the route `/forgot-password`.

### 2.1 Add `.htaccess` Rule

In `/.htaccess`, inside the `<IfModule mod_rewrite.c>` block, **after** the existing `/login` rule (around line 79), add:

```apache
# ── Forgot Password standalone page ──────────────────────────────────────────
RewriteRule ^forgot-password/?$ install-forgot-password.php [NC,L,QSA]
```

Full context diff:

```diff
 RewriteRule ^register/?$ install-register.php [NC,L,QSA]
 RewriteRule ^login/?$    install-login.php    [NC,L,QSA]
+RewriteRule ^forgot-password/?$ install-forgot-password.php [NC,L,QSA]
```

### 2.2 Create `install-forgot-password.php`

Create a new file `install-forgot-password.php` in the repo root. This page handles the **2-step OTP flow** entirely in the browser via `fetch()` calls to the existing API endpoints:

**Step 1** — Enter email → calls `POST /api/auth/forgot-password-otp` → transitions to OTP entry

**Step 2** — Enter 6-digit OTP + new password → calls `POST /api/auth/reset-password-otp` → redirects to `/login?reset_success=1`

The page should:
- Match the existing design language from `install-login.php` (Poppins font, glassmorphism card, dark background, blue accent `#2b83fa`)
- Display a "Back to Sign In" link pointing to `/login`
- Show a countdown timer (10:00) for the OTP expiry
- Show resend-code functionality (60-second cooldown)

**Key API calls** the JavaScript should make:

```js
// Step 1: Request OTP
const res = await fetch('/api/auth/forgot-password-otp', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email })
});

// Step 2: Reset password
const res = await fetch('/api/auth/reset-password-otp', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email, otp, new_password })
});
```

On success, redirect to `/login?reset_success=1` so the success banner already handled in `install-login.php` displays automatically.

### 2.3 Update `install-login.php` — Change Forgot Password Link

In `install-login.php` around line 869, the "Forgot Password?" anchor currently points to `#` (JavaScript toggle). Change it to link directly to the new page:

```diff
-<a href="#" id="forgot-pw-link" style="...">Forgot Password?</a>
+<a href="/forgot-password" style="...">Forgot Password?</a>
```

You can also remove the `forgot-form-wrapper` hidden div (lines 885–903) and the JS toggle logic (lines 919–949) since they will no longer be needed.

### 2.4 Add .htaccess API route aliases

Confirm the following routes are mapped in `.htaccess` (they follow the existing catch-all pattern but should be explicitly listed for clarity):

```apache
RewriteRule ^api/auth/forgot-password-otp/?$ /api/auth/forgot_password_otp.php [NC,L,QSA]
RewriteRule ^api/auth/reset-password-otp/?$  /api/auth/reset_password_otp.php  [NC,L,QSA]
```

---

## 3. Portal-Specific Forgot Password Integration

### 3.1 User Portal (`app.nolasmspro.com`)

The User portal redirects unauthenticated `/login` requests directly to the **backend** `install-login.php` page via `RedirectToBackend` in `user/src/App.tsx`. There is no React forgot-password route — the entire login/forgot-password experience is server-rendered PHP.

**Handoff action**: Add the `install-forgot-password.php` file and `.htaccess` rule (Section 2). No React changes needed for the User portal.

---

### 3.2 Agency Portal (`agency.nolasmspro.com`)

The Agency portal has a **fully implemented** React OTP forgot-password flow in `agency/src/pages/AgencyLogin.tsx`. It uses four phases:

| Phase key | Description |
| :--- | :--- |
| `credentials` | Email + password sign-in form |
| `forgot_request` | Enter email to request OTP |
| `forgot_verify` | Enter 6-digit OTP + new password |
| `connect_ghl` | GHL Company ID link (post-login) |

The OTP functions are in `agency/src/services/agencyAuthHelper.ts`:

- `requestPasswordOtp(email)` → `POST /api/auth/forgot-password-otp`
- `resetPasswordWithOtp(email, otp, newPassword)` → `POST /api/auth/reset-password-otp`

**The Agency OTP flow is complete and production-ready. No backend changes are needed for this portal.**

#### Agency Portal — `/forgot-password` Route (Implemented)

The Agency portal has a dedicated `/forgot-password` route handled by the new `AgencyForgotPassword.tsx` component.
- Clicking "Forgot password?" in `AgencyLogin.tsx` navigates to `/forgot-password`.
- The route is registered in `routes.tsx` mapping to `AgencyForgotPassword.tsx`.
- On success, it redirects to `/login` passing the reset success state.

---

### 3.3 Admin Portal (`smspro-api.nolacrm.io/admin`)

The Admin portal has a dedicated `/forgot-password` route handled by the new `AdminForgotPassword.tsx` component.
- In `AdminLayout.tsx`, if the user is unauthenticated, any request to `/forgot-password` is routed to `AdminForgotPassword`, and all other routes match to `AdminLogin`.
- Clicking "Forgot password?" in `AdminLogin.tsx` navigates to `/forgot-password`.
- On success, it redirects to `/login` passing the reset success state.

---

## 4. GHL Workflow Setup — Send OTP via GoHighLevel

To route OTP delivery through a GHL workflow (recommended over plain `mail()` for reliability, branding, and SMS support):

### Step 1: Create GHL Custom Fields

In your **NOLA Central GHL Location** (`NOLA_ALERT_GHL_LOCATION_ID`):

1. Go to **Settings → Custom Fields**
2. Add the following **Contact** custom fields:

| Field Label | Suggested Key | Field Type |
| :--- | :--- | :--- |
| **NOLA SMS Alert Type** | `nola_sms_alert_type` | Single Line Text |
| **NOLA SMS Alert ID** | `nola_sms_alert_id` | Single Line Text |
| **NOLA SMS OTP Code** | `nola_sms_otp_code` | Single Line Text |
| **NOLA SMS Alerted At** | `nola_sms_alerted_at` | Single Line Text |

### Step 2: Create the Workflow

1. Go to **Automation → Workflows → Create Workflow → Start from Scratch**
2. Name: `NOLA SMS Pro - Password Reset OTP Delivery`

### Step 3: Trigger

- **Trigger**: Contact Changed
- **Filter**: `NOLA SMS Alert ID` → has changed

### Step 4: If/Else Condition

Add an **If/Else** to guard this workflow against other NOLA alerts:

- **Condition**: `Custom Fields > NOLA SMS Alert Type` `is` `forgot_password_otp`
- Rename the branch to **OTP Request**

### Step 5: Delivery Action

Under the **OTP Request** branch, add a **Send Email** or **Send SMS**:

```
Subject: Your NOLA SMS Pro verification code

Hi {{contact.first_name}},

Your password reset code is:

{{contact.nola_sms_otp_code}}

This code expires in 10 minutes. If you didn't request a reset, ignore this message.
```

Publish the workflow when ready.

---

## 5. Backend Code Changes for GHL Delivery (Handoff for Dev Team)

> **No backend changes have been committed.** The following is a reference for the backend developer to wire GHL delivery into the existing OTP flow.

### 5.1 `api/services/NotificationService.php` — Add `nola_sms_otp_code` to Custom Field Resolution

In `resolveCentralGhlCustomFieldIds`, extend `$requiredKeys`:

```php
$requiredKeys = [
    'nola_sms_alert_type',
    'nola_sms_alert_id',
    'nola_sms_balance',
    'nola_sms_low_balance_threshold',
    'nola_sms_alerted_at',
    'nola_sms_registered_email',
    'nola_sms_source_location_id',
    'nola_sms_source_location_name',
    'nola_sms_requested_sender_id',
    'nola_sms_admin_notes',
    'nola_sms_otp_code', // ← Add this
];
```

### 5.2 Add `notifyForgotPasswordOtp()` Method

```php
/**
 * Dispatch OTP code via central GHL workflow.
 */
public static function notifyForgotPasswordOtp($db, string $email, string $otp): void
{
    $centralLocationId = getenv('NOLA_ALERT_GHL_LOCATION_ID') ?: '';
    if ($centralLocationId === '') {
        error_log('[forgot_password_otp] NOLA_ALERT_GHL_LOCATION_ID not set. Skipping GHL delivery.');
        return;
    }

    require_once __DIR__ . '/GhlClient.php';
    $centralTokenRegistryId = getenv('NOLA_ALERT_GHL_TOKEN_REGISTRY_ID') ?: $centralLocationId;
    $alertTag = getenv('NOLA_ALERT_OTP_TAG') ?: 'nola-otp-alert';

    try {
        $ghlClient = new \GhlClient($db, $centralLocationId, $centralTokenRegistryId);

        // 1. Find contact by email in central location
        $contactId = null;
        $searchResp = $ghlClient->request('GET', '/contacts/?locationId=' . urlencode($centralLocationId) . '&query=' . urlencode($email));
        if ($searchResp['status'] === 200) {
            $searchData = json_decode($searchResp['body'], true);
            foreach ($searchData['contacts'] ?? [] as $c) {
                if (strtolower(trim($c['email'] ?? '')) === strtolower(trim($email))) {
                    $contactId = $c['id'];
                    break;
                }
            }
        }

        // 2. Resolve custom field IDs
        $fieldIdMap = self::resolveCentralGhlCustomFieldIds($db, $ghlClient, $centralLocationId);

        $now      = new \DateTimeImmutable();
        $alertId  = 'otp_' . $email . '_' . $now->format('YmdHis');
        $alertFields = [
            'nola_sms_alert_type' => 'forgot_password_otp',
            'nola_sms_alert_id'   => $alertId,
            'nola_sms_otp_code'   => $otp,
            'nola_sms_alerted_at' => $now->format('c'),
        ];

        $ghlCustomFields = [];
        foreach ($alertFields as $k => $v) {
            $fieldId = $fieldIdMap[$k] ?? null;
            if ($fieldId) {
                $ghlCustomFields[] = ['id' => $fieldId, 'value' => (string) $v];
            }
        }

        $contactPayload = [
            'locationId'   => $centralLocationId,
            'email'        => $email,
            'firstName'    => 'NOLA SMS',
            'lastName'     => 'User',
            'customFields' => $ghlCustomFields,
        ];

        // 3. Upsert contact
        if ($contactId) {
            unset($contactPayload['locationId']);
            $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($contactPayload));
        } else {
            $createResp = $ghlClient->request('POST', '/contacts/', json_encode($contactPayload));
            if (in_array($createResp['status'], [200, 201])) {
                $createData = json_decode($createResp['body'], true);
                $contactId  = $createData['contact']['id'] ?? $createData['id'] ?? null;
            }
        }

        // 4. Cycle tag to fire GHL workflow
        if ($contactId) {
            $ghlClient->request('DELETE', "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
            $ghlClient->request('POST',   "/contacts/{$contactId}/tags", json_encode(['tags' => [$alertTag]]));
            error_log("[forgot_password_otp] GHL OTP sync succeeded for contact: {$contactId}");
        }
    } catch (\Throwable $e) {
        error_log('[forgot_password_otp] GHL sync failed: ' . $e->getMessage());
    }
}
```

### 5.3 Call from `api/auth/forgot_password_otp.php`

After saving the OTP to Firestore (around line 91), call the notification:

```php
// After $userRef->set([...], ['merge' => true]);

try {
    require_once __DIR__ . '/../services/NotificationService.php';
    \NotificationService::notifyForgotPasswordOtp($db, $email, $otp);
} catch (\Throwable $e) {
    error_log('[forgot_password_otp] GHL notification failed: ' . $e->getMessage());
}

// The existing @mail() call below acts as a fallback
@mail($email, $subject, $message, $headers);
```

---

## 6. Summary of Backend Dev Tasks

| Task | File(s) | Status |
| :--- | :--- | :--- |
| Add `/forgot-password` route | `.htaccess` | ⏳ Handoff |
| Create `install-forgot-password.php` | `install-forgot-password.php` | ⏳ Handoff |
| Update "Forgot Password?" link in login page | `install-login.php` line 869 | ⏳ Handoff |
| Remove legacy hidden forgot form from login page | `install-login.php` lines 885–949 | ⏳ Handoff |
| Add `nola_sms_otp_code` to NotificationService | `api/services/NotificationService.php` | ⏳ Handoff |
| Add `notifyForgotPasswordOtp()` method | `api/services/NotificationService.php` | ⏳ Handoff |
| Call GHL notifier from OTP endpoint | `api/auth/forgot_password_otp.php` | ⏳ Handoff |
| Create GHL Custom Fields + Workflow in GHL | GHL Admin Panel | ⏳ Handoff |
| Add `/forgot-password` to agency and admin routes | `agency/` + `admin/` frontend code | Done (Frontend Implemented) |
