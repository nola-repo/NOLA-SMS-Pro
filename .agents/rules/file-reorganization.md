# File Reorganization Rules

These rules apply whenever you are asked to reorganize, move, or group PHP files
into subdirectories in this project. Follow every step — skipping any step risks
breaking live routes.

---

## 1. Understand Before Moving

Before touching any file:

- Read the file to determine its type and responsibility (see Section 3).
- Search `.htaccess` for every `RewriteRule` that references this file.
- Search the entire codebase for `require`, `require_once`, `include`, and
  `include_once` statements that reference this file by path.
- Do not move a file until all of its dependencies and dependents are mapped.

---

## 2. Classify the File First

Identify which category the file belongs to before deciding on a destination folder:

| Type | Signs | Target folder |
| --- | --- | --- |
| **HTTP endpoint** | Accepts `$_POST`/`$_GET`, returns JSON or HTTP response, included in `.htaccess` route | Appropriate subdomain folder (`admin/`, `billing/`, `ghl/`, etc.) |
| **Helper / library** | Only defines functions or classes, never called directly via HTTP | `api/services/` or a domain subfolder alongside its consumers |
| **Webhook receiver** | Entry point for third-party callbacks (UniSMS, GHL, Semaphore) | `api/webhook/` |
| **Admin endpoint** | Requires admin auth, manages users/agencies/settings | `api/admin/` |
| **Auth endpoint** | Handles login, register, token, OTP, GHL autologin | `api/auth/` |
| **Billing endpoint** | Handles credits, wallets, subscriptions, transactions | `api/billing/` |
| **GHL integration** | Reads/writes HighLevel contacts, conversations, OAuth | `api/ghl/` |
| **Agency endpoint** | Manages agency-level install, subaccounts, SSO | `api/agency/` |
| **Location endpoint** | Location bootstrap and location-scoped settings | `api/location/` |
| **Cache utility** | Cache read/write helpers | `api/cache/` |
| **Shared utility** | Used by many different modules (CORS, JWT, logger) | Keep in `api/` root or `api/services/` |

If a file clearly belongs to an existing subfolder that already exists, move it there.
If no appropriate subfolder exists and multiple files share the same new category,
create the folder. Do not create a folder for a single file.

---

## 3. .htaccess Is the Source of Truth for Routes

Every HTTP-facing PHP file has a route in `.htaccess`.

**Before moving a file, you must:**

1. Find its current `RewriteRule` entry in `.htaccess`.
2. Determine its current public URL (the left side of the rule).
3. Update the rule's target path (the right side) to reflect the new file location.
4. Preserve the public URL exactly — never change the URL the frontend or third
   parties use, only change where the file lives on disk.

**Example — moving `api/contacts.php` → `api/location/contacts.php`:**

```apache
# Before
RewriteRule ^api/contacts/?$ /api/contacts.php [NC,L,QSA]

# After — URL unchanged, file path updated
RewriteRule ^api/contacts/?$ /api/location/contacts.php [NC,L,QSA]
```

---

## 4. Check Frontend Impact Before Moving

The frontend (React app) calls PHP endpoints via clean API URLs like `/api/messages`,
`/api/contacts`, `/api/notifications`, etc. These URLs must never change.

**Before moving any HTTP-facing PHP file, you must:**

1. Identify the public URL this file is served at (from `.htaccess`).
2. Search the frontend codebase for any `fetch`, `axios`, `api/` string, or URL
   reference that matches this public URL.
   ```
   grep -r "api/messages" src/
   grep -r "api/contacts" src/
   ```
3. Confirm the public URL (left side of the `.htaccess` rule) is NOT changing —
   only the disk path on the right side changes.
4. If the public URL is staying the same, the frontend is unaffected and no
   frontend code changes are needed.
5. **Never change the public URL without coordinating with the frontend team first.**
   Changing a URL that the React app calls will silently break API requests.

**The safe principle:** Moving a PHP file on disk = safe for frontend as long as
the `.htaccess` public URL is preserved. The frontend only knows about URLs,
not about where PHP files live on disk.

---

## 5. Update All Internal References

After updating `.htaccess`, find and fix every internal reference to the moved file:

- `require_once __DIR__ . '/contacts.php'` — update path relative to the new location.
- `require __DIR__ . '/../contacts.php'` — adjust `../` depth as needed.
- Any string literal paths used in logging or documentation — update those too.

---

## 6. Do Not Move Files That Are Safe to Keep in Root

Some files belong in `api/` root and must not be moved:

- `cors.php` — required by virtually every endpoint via `require_once __DIR__ . '/cors.php'`
- `auth_helpers.php` — shared auth library required across many files
- `jwt_helper.php` — shared JWT utility
- `cache_helper.php` — shared cache utility
- `install_helpers.php` — shared install/provisioning library
- `logger.php` / `performance_logger.php` — shared logging utilities

Moving these would require updating dozens of `require` paths across the codebase.
Only move them if you are explicitly tasked with doing so and have mapped every consumer.

---

## 7. Move One File or One Logical Group at a Time

Do not reorganize the entire `api/` folder in one operation.

- When given a specific file or group of files to move, handle only those files.
- Commit each logical group separately with a clear commit message.
- Do not combine a reorganization commit with a feature or bug-fix commit.

---

## 8. Verify Before Committing

After moving a file and updating all references:

1. Confirm the `.htaccess` route still resolves correctly (right-side path exists on disk).
2. Confirm no `require`/`include` path still points to the old location.
3. Confirm the file was not removed from `.htaccess` entirely by accident.
4. **Test the public-facing URL on staging** — make a real HTTP request to the endpoint
   and confirm it returns the expected response (not a 404 or 500).
5. If the frontend calls this endpoint, confirm the frontend feature that depends on
   it still works end-to-end in the staging environment.

---

## 9. Commit Message Format

Use a focused, descriptive commit message per group moved:

```
refactor(api): move admin_* endpoint files into api/admin/ folder
refactor(api): move ghl_contacts and ghl-conversations into api/ghl/ folder
```

Do not use generic messages like `"reorganize files"` or `"cleanup"`.

---

## 10. Staged Rollout — Staging First

All file reorganizations must go through `staging` before `main`.

- Move the files and update `.htaccess` on the `staging` branch.
- Verify endpoints work end-to-end on staging.
- Only merge to `main` after staging verification passes.
