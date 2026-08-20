# Frontend File Reorganization Rules

These rules apply whenever you are asked to reorganize, move, or group files
in `pages/` or `assets/`. Follow every step — skipping any step risks breaking
public-facing URLs and the install/OAuth flows.

---

## 1. Understand the Frontend Structure First

This project's frontend consists of:

| Folder | Contents | Served via |
| --- | --- | --- |
| `pages/` | PHP page files and HTML files rendered directly to the browser | `.htaccess` rewrite rules |
| `assets/` | Static images, logos, favicons | `.htaccess` fallback or direct URL |

The `pages/` files are **not** a JavaScript SPA. They are PHP/HTML pages that:
- Render full HTML (including inline CSS and JS) directly from PHP
- Use `require_once dirname(__DIR__) . '/api/...'` to pull in backend helpers
- Are served via clean URL rewrites in `.htaccess` (e.g. `/login` → `pages/install-login.php`)

---

## 2. Classify the File Before Moving

| Type | Signs | Correct location |
| --- | --- | --- |
| **Auth page** | Login, register, forgot-password UI rendered by PHP | `pages/` root (already correct) |
| **OAuth callback page** | GHL subaccount or agency OAuth flow, uses `install_helpers.php` | `pages/` root (already correct) |
| **Auth handoff** | Pure HTML bridge page that reads a JWT from URL and redirects into the React app | `pages/` root (already correct) |
| **Static image / logo / favicon** | `.png`, `.svg`, `.ico` files used in UI or `<link rel="icon">` | `assets/` |
| **Shared CSS file** | Stylesheet used by multiple pages | `assets/css/` (create if needed) |
| **Shared JS file** | Script used by multiple pages | `assets/js/` (create if needed) |
| **Scratch / debug page** | Temporary diagnostic page not intended for production | `scratch/` |

---

## 3. .htaccess Is the Source of Truth for All Page Routes

Every `pages/` file that is user-facing has a clean URL route in `.htaccess`.

**Before moving any page file:**

1. Find its `RewriteRule` in `.htaccess`.
2. Note the current public URL (left side of the rule — this is what users and GHL use).
3. Update the target path (right side) to match the new file location on disk.
4. **Never change the public URL.** Changing `/login`, `/register`, `/oauth/callback`,
   or `/oauth/agency-callback` will break the GHL marketplace install flow and
   any bookmarked user links.

**Current page routes — do not alter the left side of these:**

| Public URL | File |
| --- | --- |
| `/login` | `pages/install-login.php` |
| `/register` | `pages/install-register.php` |
| `/forgot-password` | `pages/install-forgot-password.php` |
| `/auth-handoff` | `pages/auth-handoff.html` |
| `/oauth/callback` | `pages/ghl_callback.php` |
| `/oauth/agency-callback` | `pages/ghl_agency_callback.php` |

---

## 4. `require` Paths Use `dirname(__DIR__)`

All `pages/` PHP files reference backend helpers using:

```php
require_once dirname(__DIR__) . '/api/jwt_helper.php';
require_once dirname(__DIR__) . '/api/install_helpers.php';
require dirname(__DIR__)      . '/api/webhook/firestore_client.php';
```

If you move a page file into a subfolder inside `pages/` (e.g. `pages/auth/`), the
`dirname(__DIR__)` depth changes. You must update every `require` path accordingly:

```php
// File moved to pages/auth/install-login.php
// dirname(__DIR__) now resolves to pages/, not the project root
// Use dirname(__DIR__, 2) to reach the project root from two levels deep
require_once dirname(__DIR__, 2) . '/api/jwt_helper.php';
```

Always verify the resolved path is correct after any move.

---

## 5. Assets — Update Every Reference When Moving

Before moving any file in `assets/`:

- Search all `pages/*.php` and `pages/*.html` files for the asset filename.
- Search `.htaccess` for any explicit rewrite rules pointing to the asset.
- Update every `<img src>`, `<link href>`, `<script src>`, and `.htaccess` entry
  to reflect the new path.

**Current `.htaccess` asset rules — update if paths change:**

```apache
RewriteRule ^PNG[\s%20\-_]+NOLA[\s%20\-_]+SMS[\s%20\-_]+PRO[\s%20\-_]+Standard\.png$ assets/PNG\ -\ NOLA\ SMS\ PRO\ Standard.png [NC,L]
RewriteRule ^favicon\.png$ assets/favicon.png [NC,L]
```

---

## 6. Inline CSS and JS in PHP Pages

The `pages/` PHP files contain all their CSS and JavaScript inline (no external
stylesheet or script files). This is intentional — these are self-contained install
pages that must work without any build step.

- Do not extract inline CSS/JS into separate files unless you are explicitly tasked
  with doing so **and** you update every `<link>`/`<script>` reference.
- If you do extract shared styles or scripts, place them in `assets/css/` or
  `assets/js/` and add Apache rules if those paths need clean URLs.

---

## 7. Do Not Move These Files Without an Explicit Task

The following files are referenced by GHL marketplace configuration and external
OAuth redirect URIs. Their public URLs are registered externally and cannot be
changed without updating the GHL app settings first:

- `pages/ghl_callback.php` → public URL `/oauth/callback`
- `pages/ghl_agency_callback.php` → public URL `/oauth/agency-callback`

**Never rename or move these unless the GHL OAuth redirect URIs are updated first.**

---

## 8. Move One File or Group at a Time

- Do not reorganize all of `pages/` in a single operation.
- Handle the specific file or group you were asked about, nothing else.
- Commit each move separately with a clear message.

---

## 9. Commit Message Format

```
refactor(pages): move shared CSS into assets/css/ and update page references
refactor(assets): rename logo files and update all page img src references
```

---

## 10. Staging First

All frontend file moves must be deployed to `staging` and verified before `main`:

- Confirm the public-facing URLs still load correctly on staging.
- Confirm the GHL OAuth callback flow still completes on staging.
- Confirm assets (logo, favicon) still render correctly on staging.
- Only merge to `main` after staging verification passes.
