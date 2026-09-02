# Console Error Cleanup QA

Date: 2026-06-25

## Frontend-owned checks

- Credit status responses with `success: true`, `cached: false`, or `meta.cache.status: "MISS"` are valid and should render normally.
- Treat only HTTP failures, JSON parse failures, or explicit `success: false` payloads as credit-status errors.
- Do not call the credit-status endpoint when no GHL location ID is resolved yet; let the missing-location UI handle that state.
- Third-party console noise from LeadConnector, Pendo, Sentry, or browser extensions should be separated from app-owned errors during QA.

## Deployment/config checks

- Add Firebase authorized domains for `app.nolasmspro.com` and `app.nolacrm.io` in Firebase Console.
- Fix the GoHighLevel custom page/sidebar URL in the GHL app/menu configuration if it still points at a 404 route.