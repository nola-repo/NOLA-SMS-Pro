# Codebase Organization & File Placement Rules

All new and existing files must strictly adhere to the following directory structure to keep the repository root clean, organized, and production-safe:

## 1. Directory Structure Standards

- **`docs/`**: All markdown documentation, handoffs, architecture guides, specifications, and design notes. Never place `.md` files in the root (except `README.md`).
- **`api/`**: All backend REST API endpoints, services, webhooks, and controllers (categorized under `api/auth/`, `api/billing/`, `api/agency/`, `api/webhook/`, `api/services/`, etc.).
- **`pages/`**: All public UI views, registration/login pages, OAuth entrypoints, and HTML handoff pages (`pages/install-login.php`, `pages/install-register.php`, `pages/ghl_callback.php`, `pages/auth-handoff.html`, etc.).
- **`scripts/`**: All CLI scripts, migrations, database seeders, token generators, and maintenance utilities.
- **`scratch/`**: All temporary debug scripts, diagnostic tools, and test harnesses. (These are excluded from Docker production images).
- **`assets/`**: All static images, logos, banners, and icons (e.g., `assets/PNG - NOLA SMS PRO Standard.png`, `assets/favicon.png`).
- **`docker/`**: Apache and container-specific configuration files.

## 2. Root Directory Policy

Only core build, version control, and infrastructure configuration files are permitted in the root directory:
- `Dockerfile` & `docker-entrypoint.sh`
- `cloudbuild.yaml` & `cloudbuild.staging.yaml`
- `composer.json` & `composer.lock`
- `.htaccess`
- `.dockerignore`, `.gcloudignore`, `.gitignore`, `.gitattributes`
- `firebase.json`, `firestore.rules`, `firestore.indexes.json`, `.firebaserc`
- `README.md`

## 3. Routing & Path Rules
- Any new web route must be added to `.htaccess` mapping clean URLs to `pages/` or `api/`.
- Relative includes in `pages/` and `scripts/` must use `dirname(__DIR__) . '/api/...'` rather than `__DIR__`.
