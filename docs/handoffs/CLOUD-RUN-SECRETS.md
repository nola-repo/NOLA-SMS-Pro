# Cloud Run Secrets Management

To ensure the GHL OAuth flow works correctly across different environments, you must synchronize the credentials between the Frontend, Backend, and Cloud Run.

## GHL Credential Synchronization

> [!IMPORTANT]
> The **GHL Client ID** configured in the Frontend App Settings **MUST** match the `GHL_CLIENT_ID` environment variable / secret in your Google Cloud Run instance.

### Required Secrets
| Secret Name | Source | Use Case |
|-------------|--------|----------|
| `GHL_CLIENT_ID` | GHL Marketplace App | OAuth Initiation & Token Exchange |
| `GHL_CLIENT_SECRET` | GHL Marketplace App | Backend Token Refresh Loop |

### Synchronization Steps
1.  **Frontend**: Go to **Settings > Account** and enter the GHL Client ID from your GHL Marketplace app.
2.  **Backend (Cloud Run)**: Ensure the `GHL_CLIENT_ID` and `GHL_CLIENT_SECRET` secrets are updated to match the same app credentials.
3.  **Deployment**: Redeploy the container if secrets are updated.

If these do not match, you will see a "No integration found" or "Client ID mismatch" error during the OAuth redirect.
