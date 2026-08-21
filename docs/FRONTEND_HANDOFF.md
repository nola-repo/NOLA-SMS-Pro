# Frontend Handoff — NOLA SMS Pro

> **Audience:** Frontend team (`raelyivandreyes@gmail.com`)
> **Raised by:** Backend team
> **Date:** 2026-08-21
> **Repo:** https://github.com/nola-repo/NOLA-SMS-Pro

---

## ⚡ Quick Summary — What You Need To Do

| # | Item | Priority | Action |
|---|---|---|---|
| 1 | 401 on `/api/auth/me` | 🔴 High | Fix auth retry race condition on first load |
| 2 | 2-Way SMS UI (F1–F7) | 🟡 Medium | Build frontend phases — no backend blocker |
| 3 | Backend Reorganization | 🟢 None | Read-only — no changes needed on your end |

---

## 🔐 Issue 1: 401 Unauthorized on `/api/auth/me` — Frontend Must Fix

**What was found:**

```
GET https://app.nolasmspro.com/api/auth/me → 401
{"error":"Missing auth token. Provide Authorization: Bearer <token>.","code":"AUTH_TOKEN_MISSING"}
```

**Backend verdict:** The 401 is **correct and intentional**. The backend is working as designed.

**Root cause:** The frontend is calling `/api/auth/me` **before** the GHL auto-login flow has completed and a token is saved. This is a frontend race condition on first load inside the GHL iframe.

### What the Frontend Must Fix

**Rule 1 — Treat the first 401 as "not ready yet", not a failure.**

After getting a 401 from `/api/auth/me`:
1. Call `GET /api/auth/ghl_autologin?location_id={id}`
2. Save the returned JWT token
3. Retry `GET /api/auth/me` with `Authorization: Bearer <token>`
4. Only show an error screen if the **retry** also fails

**Rule 2 — Extract `location_id` from the GHL iframe URL before making any auth calls.**

```
/v2/location/{locationId}/custom-page-link/{pageId}
                ↑ extract this
```

Do NOT send a company/account ID as `location_id`.

**Rule 3 — Do not call `/api/auth/me` before `location_id` is confirmed.**

**Correct first-load sequence:**
```
App loads in GHL iframe
  → extract location_id from URL
  → GET /api/auth/me           (expects 401)
  → GET /api/auth/ghl_autologin?location_id={id}
  → save token
  → retry GET /api/auth/me with Authorization: Bearer <token>
  → 200 ✅ user profile loaded
```

**Backend auth contract (do not change these):**

| Condition | Backend Response |
|---|---|
| No token | `401 AUTH_TOKEN_MISSING` |
| Invalid/expired token | `401` |
| Valid token, user missing | `404` |
| Valid token, user found | `200 { "user": ... }` |

**Relevant backend files (read-only, do not modify):**
- `api/auth/me.php`
- `api/auth/ghl_autologin.php`

---

## 💬 Issue 2: 2-Way SMS with UniSMS — Frontend Phases

The backend has completed a full audit of 2-way SMS via UniSMS virtual numbers.

**Full audit document:** `docs/2WAY_SMS_AUDIT_IMPLEMENTATION_HANDOFF.md`
→ See section **"Road to Implementation - Frontend Team"** (Phase F1–F7)

Backend owns: provider routing, inbound/outbound wiring, Firestore schema, virtual-number registry.

**You can start building these UI phases now — no backend blocker.**

### Phase F1 — Settings UI
Add to the account/settings screen:
- Assigned virtual number display
- Virtual number status: `pending` / `active` / `inactive` / `failed`
- 2-way SMS availability per location
- Provider mode label (NOLA app / GHL Workflow / GHL Conversation Provider / native-mirrored)

**Do NOT expose:** UniSMS API keys, webhook secrets, provider signing keys.

### Phase F2 — Reply Behavior
- Keep using `POST /api/sms` — no endpoint change
- Add `Idempotency-Key: sms_<location_id>_<uuid>` header to all sends
- When replying in a conversation thread, pass:
  ```json
  {
    "conversation_id": "...",
    "contact_id": "...",
    "reply_from_virtual_number": true,
    "template_id": "...",
    "template_name": "..."
  }
  ```
- Disable reply button when: paused, install blocked, no credits, virtual number inactive/failed

### Phase F3 — Conversation Thread Rendering
New fields to display:

| Field | Display |
|---|---|
| `direction` | `inbound` / `outbound` icon |
| `status` | `Received` / `Sending` / `Sent` / `Failed` |
| `virtual_number` | From number on outbound replies |
| `ghl_sync_failed` | Warning badge — separate from SMS delivery status |

Use `fresh=1` to bypass cache after send or inbound:
```
GET /api/conversations?fresh=1&location_id=...
```

### Phase F4 — GHL Embedded App
- Always send `X-GHL-Location-ID` header
- Do not load conversations/notifications until location context is confirmed
- Show GHL sync warnings **separately** from SMS delivery failures

### Phase F5 — Templates
- Templates = content insertion only (frontend renders the body before sending)
- Pass `template_id` and `template_name` as optional metadata on send
- Validate the final rendered body, not just the raw template

### Phase F6 — Notifications UI
Add notification preferences for:
- Inbound SMS received
- Unread inbound SMS reminder
- Failed delivery
- Low balance
- Virtual number issues

Clicking an inbound SMS notification → must open the correct conversation directly.

### Phase F7 — Admin Setup Clarity
Make 2-way SMS setup state visible to admins:
- GHL Conversation Provider installed/enabled
- Provider mode (default vs custom)
- Warning when native SMS and NOLA SMS action are both configured (double-send risk)

> **Do not call UniSMS directly from the frontend. All sends go through `/api/sms`.**

---

## 📁 Issue 3: Backend File Reorganization — No Action Needed

PHP files were reorganized into subdirectories. All `.htaccess` routes were updated so **all public API URLs remain exactly the same**.

| Moved | New location |
|---|---|
| `admin_*.php` | `api/admin/` |
| GHL files | `api/ghl/` |
| Messaging, billing, notifications, sender, health, tickets | respective subdirectories |

**No frontend code changes needed.** If you see any `404` on a previously working endpoint after this is deployed to staging — report it immediately.

---

## 📎 Related Documents

| Document | Location |
|---|---|
| Full 2-Way SMS audit | `docs/2WAY_SMS_AUDIT_IMPLEMENTATION_HANDOFF.md` |
| Backend 2-Way SMS plan | `docs/BACKEND_2WAY_SMS_IMPLEMENTATION_PLAN.md` |
| UniSMS integration notes | `docs/FRONTEND_HANDOFF_UNISMS_INTEGRATION.md` |
| Auth first-load handoff | `docs/BACKEND_FIRST_LOAD_AUTH_HANDOFF.md` |

---

*Raised by the backend team. For questions, coordinate directly via Slack or email.*
