# Production Deployment Checklist

Use this before releasing user, agency, admin, or API changes.

## Build Verification

- [ ] `npx tsc -b` passes in `user/`.
- [ ] `npm run build` passes in `user/`.
- [ ] `npx tsc -b` passes in `agency/`.
- [ ] `npm run build` passes in `agency/`.
- [ ] `npx tsc -b` passes in `admin/`.
- [ ] `npm run build` passes in `admin/`.
- [ ] No unexpected files are included in the production build output.

## Environment

- [ ] Frontend env files contain only public Vite values.
- [ ] Backend secrets are stored in the backend runtime or secret manager, not in frontend env files.
- [ ] `JWT_SECRET` is set and shared by auth endpoints that issue or validate NOLA JWTs.
- [ ] Firebase custom token signing is configured with `FIREBASE_SERVICE_ACCOUNT_JSON` or `FIREBASE_CLIENT_EMAIL` plus `FIREBASE_PRIVATE_KEY`.
- [ ] GoHighLevel user OAuth client and agency OAuth client are configured separately where required.
- [ ] `GHL_SSO_SECRET` matches the GoHighLevel agency SSO shared secret.
- [ ] SMS provider keys and webhook secrets are present for the active provider path.
- [ ] Central notification env values are configured if low-balance/OTP/support alerts are enabled.

## Routing And Assets

- [ ] User, agency, and admin domains serve the correct built app.
- [ ] SPA fallback routes return `index.html` on browser refresh for protected pages.
- [ ] `/api` and `/webhook` proxy rules point to the intended API host.
- [ ] Favicon loads on user, agency, and admin domains.
- [ ] Logos render at expected sizes in desktop and mobile navigation.
- [ ] Page titles are correct for login, dashboard, settings, and detail views.

## Security And Privacy

- [ ] No debug endpoints are publicly exposed without an environment gate and authentication.
- [ ] Browser-visible errors do not include stack traces, secrets, provider tokens, raw headers, or decrypted GHL payloads.
- [ ] Console output is quiet in production builds except intentional browser/runtime errors.
- [ ] Expired sessions clear stored tokens and return users to the correct login or embedded recovery flow.
- [ ] Firestore rules match the custom token claims emitted by the backend.
- [ ] Sender request approval/rejection requires an authenticated admin session.
- [ ] Credit changes require the correct agency/admin authority.

## Release Steps

- [ ] Deploy backend changes first when frontend depends on new API fields.
- [ ] Deploy user app.
- [ ] Deploy agency app.
- [ ] Deploy admin app.
- [ ] Run the manual smoke test checklist after deployment.
- [ ] Monitor backend logs for auth, SMS provider, Firestore, and GHL OAuth errors.