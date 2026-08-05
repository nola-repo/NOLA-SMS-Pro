# Backend Handoff: GHL Agency OAuth

**To:** Backend Team  
**Topic:** New Agency-level GHL OAuth app — `oauth_exchange.php` must handle the new `client_id`

---

## What Changed

The Agency panel now uses a **dedicated Agency-level GHL Marketplace app** to perform OAuth and automatically retrieve the `company_id`. This replaces the previous manual text input.

**New Agency App Credentials:**
| Key | Value |
|-----|-------|
| Client ID | `69d31f33b3071b25dbcc5656-mnmlq8zj` |
| Client Secret | `5b3f989d-2ad2-4213-96d4-08698a218e8c` |
| Redirect URI | `https://agency.nolasmspro.com/oauth/callback` |
| OAuth Scopes | `companies.readonly locations.readonly oauth.readonly` |

> [!CAUTION]
> Update the server environment variables immediately. The current `GHL_CLIENT_ID` and `GHL_CLIENT_SECRET` env vars in Cloud Run are for the old Sub-Account app. The `oauth_exchange.php` endpoint used by the Agency panel must use the new credentials.

---

## Server Environment Variables to Update

On your **Cloud Run service** (or wherever `api/ghl/oauth_exchange.php` is deployed), update:

```bash
GHL_CLIENT_ID=69d31f33b3071b25dbcc5656-mnmlq8zj
GHL_CLIENT_SECRET=5b3f989d-2ad2-4213-96d4-08698a218e8c
```

If you need separate credentials for the sub-account OAuth (old app) and this agency OAuth (new app), consider adding a second pair of env vars and routing based on the `client_id` in the request payload.

---

## Endpoint: `POST /api/ghl/oauth_exchange.php`

This endpoint already exists and the backend team already updated it. **No code changes are needed** — only the env vars above.

### What the frontend sends:
```json
{
  "code": "abc123...",
  "redirect_uri": "https://agency.nolasmspro.com/oauth/callback"
}
```

### What `oauth_exchange.php` must return on success:
```json
{
  "success": true,
  "company_id": "GHL_COMPANY_123",
  "company_name": "Acme Agency"
}
```

### Error response:
```json
{
  "success": false,
  "error": "No companyId found in oauth response"
}
```

---

## GHL OAuth Token Response (Agency App)

When an Agency-level app exchanges the code, GHL's token endpoint returns:
```json
{
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 86399,
  "companyId": "GHL_COMPANY_ABC123",
  "scope": "companies.readonly locations.readonly oauth.readonly"
}
```

The `companyId` field is at the **top level** of the response (not nested). The existing `oauth_exchange.php` code already reads this correctly:
```php
$companyId = $result['companyId'] ?? $result['company_id'] ?? '';
```

---

## Firestore Write (Already Implemented)

After exchange, `oauth_exchange.php` already:
1. Saves tokens to `ghl_tokens/{companyId}`
2. Queries `users` by `agency_id` and patches `company_id` onto those documents

This means after OAuth, future logins will return `company_id` automatically and the user won't need to go through the OAuth flow again.

---

## GHL Marketplace App Settings Checklist

| Setting | Required Value |
|---------|---------------|
| App Type | Private |
| Target User | **Agency** |
| Who can install | Agency Only |
| Redirect URI (whitelisted) | `https://agency.nolasmspro.com/oauth/callback` |
| Scopes | `companies.readonly`, `locations.readonly`, `oauth.readonly` |

---

## Verification Steps

1. Deploy updated env vars to Cloud Run
2. Log in with an agency account that **has no `company_id`** in Firestore
3. Click **"Connect with GoHighLevel"** → authorize on GHL
4. Should redirect to `https://agency.nolasmspro.com/oauth/callback?code=...`
5. Spinner shows → success screen → redirect to Dashboard
6. Verify `ghl_tokens/{companyId}` document created in Firestore
7. Verify `users/{uid}` now has `company_id` field
8. Log out and log back in → should go straight to Dashboard (no OAuth prompt)
