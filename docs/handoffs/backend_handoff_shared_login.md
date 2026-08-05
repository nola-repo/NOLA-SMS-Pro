# Backend Handoff: Shared Login API

The frontend has been updated to include a single shared login page for **Agencies** and **Users (Subaccounts)**. The frontend expects two new endpoints to support this feature.

## Requirements Overview
This login flow requires an unauthenticated `whitelabel` endpoint to fetch the agency branding based on the Custom Domain (e.g. `app.agencydomain.com`) *before* the user has logged in. It also requires an authentication endpoint that can identify whether the user is an `agency` or a `user` and perform the correct login verification.

---

## 1. Public Whitelabel API
Used to retrieve custom domain branding on the login page.

**Endpoint:** `GET /api/public/whitelabel.php`
**Query Parameters:**
- `domain` (string): The host requesting the page (e.g., `window.location.hostname` like `app.testagency.com`)

**Expected Behavior:**
The backend should query the Firestore `agencies` collection (or `settings`) to find the agency linked to the requested `domain`. 

**Response (200 OK):**
```json
{
  "logo_url": "https://url-to-firebase-storage-logo.png",
  "company_name": "Acme Marketing",
  "primary_color": "#4F46E5"
}
```
*Note: If no custom domain matches, or if it is the parent NOLA SMS Pro domain, return the default NOLA SMS Pro branding variables.*

**Response (404 Not Found) or Fallback:**
If the domain fails, the frontend will gracefully degrade to standard styling, but you can also just return a 200 with default NOLA branding.

---

## 2. Shared Authentication API
Used to submit Email & Password and return the user's role and authorization token.

**Endpoint:** `POST /api/auth/login.php`
**Headers:**
- `Content-Type: application/json`

**Payload:**
```json
{
  "email": "user@example.com",
  "password": "secure_password"
}
```

**Expected Behavior:**
1. Verify the credentials against your Firestore Database (`agencies` and `users` collections, or however authentication is modeled).
2. Generate an authorization token (JWT or secure Firebase Custom Token).
3. Determine the user's role (`agency` or `user`). This is critical because the frontend uses this role to decide which framework to load next.

**Response (200 OK):**
```json
{
  "token": "eyJhbG... (JWT or Auth Session String)",
  "role": "agency" // or "user"
}
```

**Response (401 Unauthorized):**
```json
{
  "error": "Invalid email or password."
}
```

---

## Implementation Notes
- **Redirection Logic**: The frontend relies on the exact string value `agency` in the `role` field. If `role === 'agency'`, the frontend performs a hard navigation to `window.location.href = '/agency/'` to load the Agency Vite Application container. If it is `user`, it uses React Router to navigate to the User Dashboard (`/`) within the current Vite App.
- **CORS Requirements**: If you decide to host these PHP files on the `smspro-api.nolacrm.io` domain instead of relative paths, make sure CORS is enabled for the `whitelabel.php` endpoint so custom domains don't get blocked before login.
