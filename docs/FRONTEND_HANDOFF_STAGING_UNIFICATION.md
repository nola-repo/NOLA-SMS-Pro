# Frontend Handoff: Staging Repository Unification & GHL Marketplace Auth Bridge

**Date:** September 2, 2026  
**From:** Backend Team  
**To:** Frontend Team  
**Branch:** staging  
**Status:** Unified & Verified (92/92 PHPUnit Tests Passing)

---

## Executive Summary

The PHP Backend API and React Frontend applications (user, dmin, gency) are now unified into a single repository structure on the staging branch. 

All backend fixes, including Phase 1 CRM host detection, OAuth marketplace redirects, session handoff, and support ticket notification fixes, have been committed and pushed to staging.

---

## 1. Unified Repository Structure

The root directory of staging now contains both the PHP Backend API and the React Frontends:

`	ext
NOLA-SMS-Pro/
├── api/                   # PHP API Endpoints
├── pages/                 # PHP Marketplace & OAuth Pages
├── laravel/               # PHPUnit Test Suite & Contracts
├── user/                  # React Frontend (User Subaccount Dashboard)
├── agency/                # React Frontend (Agency Dashboard)
├── admin/                 # React Frontend (Admin Dashboard)
├── cloudbuild.staging.yaml # Multi-Service Cloud Build Configuration
└── cloudbuild.yaml        # Production Cloud Build Configuration
`

### Action for Frontend Developers:
- When pulling staging and making changes inside user/, dmin/, or gency/, ensure root backend directories (pi/, pages/, laravel/, Dockerfile) remain intact.

---

## 2. GHL Marketplace Auth Handoff & CRM Domain Fixes

We have implemented the recommended bridge using install-login.php, install-register.php, and uth-handoff.html:

1. **Marketplace Redirects:**  
   GHL Marketplace OAuth installs now route directly to https://smspro-api.nolacrm.io/install-register.php (for new installs) or https://smspro-api.nolacrm.io/install-login.php (for re-installs).

2. **Session Handoff:**  
   Upon successful registration or login, uth-handoff.html populates localStorage with:
   - 
ola_auth_token -> JWT access token
   - 
ola_user -> Base64-decoded user profile JSON

3. **Dynamic CRM Host Detection (Phase 1 Fix):**  
   - If the install originates from LeadConnector (client_id starting with 6999da2b), the deep link redirects back to pp.gohighlevel.com.
   - If the install originates from NOLA CRM, it redirects back to pp.nolacrm.io.
   - crm_domain is now stored in Firestore ghl_tokens/{locationId} at install time. On re-logins where the HTTP_REFERER header is missing, install-login.php falls back to the stored crm_domain value.

---

## 3. Shared Cloud Build Pipeline (cloudbuild.staging.yaml)

cloudbuild.staging.yaml has been configured to build and deploy both services automatically on push to staging:

1. **Step 1:** Builds and deploys sms-api-staging (PHP Backend API).
2. **Step 2:** Builds and deploys 
olasmspro-frontend-staging (React User App).

### Action for Frontend Developers:
- If modifying cloudbuild.staging.yaml, preserve Step 1 so backend deployments remain automated.

---

## 4. Staging QA Guidelines

- Use internal test location IDs for GHL Marketplace installation QA.
- Verify that uthService.ts and Settings.tsx successfully consume the 
ola_auth_token and 
ola_user keys from localStorage.