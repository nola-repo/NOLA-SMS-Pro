/**
 * Centralized API configuration for NOLA SMS Pro.
 * All API calls use same-origin relative paths.
 *   - Dev:  Vite dev server middleware proxies /api/* and /webhook/* to the backend.
 *   - Prod: nginx (in Docker/Cloud Run) proxies /api/* and /webhook/* to the backend.
 * This eliminates CORS entirely — the browser never makes a cross-origin request.
 */

// Use relative paths — nginx proxy at app.nolacrm.io injects X-Webhook-Secret header.
// Direct absolute URLs bypass nginx and will cause 401 Unauthorized on all endpoints.
const API_BASE = "";

export const GHL_MARKETPLACE_CONNECT_URL =
    "https://marketplace.gohighlevel.com/oauth/chooselocation?appId=65f8a0c2837bc281e59eef7b";

export const GHL_BACKEND_ONBOARDING_URL =
    "https://marketplace.leadconnectorhq.com/v2/oauth/chooselocation?response_type=code" +
    "&redirect_uri=https%3A%2F%2Fsmspro-api.nolacrm.io%2Foauth%2Fcallback" +
    "&client_id=6999da2b8f278296d95f7274-mmn30t4f" +
    "&scope=workflows.readonly+conversations%2Fmessage.readonly+conversations.readonly+conversations.write+contacts.readonly+contacts.write+conversations%2Fmessage.write+saas%2Flocation.read+locations.readonly+locations%2Ftags.readonly+locations%2Ftags.write+locations%2FcustomFields.readonly+oauth.write+oauth.readonly" +
    "&version_id=6999da2b8f278296d95f7274";

export const GHL_RECONNECT_REQUIRED_STORAGE_KEY = "nola_ghl_reconnect_required";
export const GHL_OAUTH_RETURN_VIEW_STORAGE_KEY = "nola_ghl_oauth_return_view";

/**
 * The GoHighLevel Custom Menu Link ID configured in the Marketplace App settings.
 * This ID appears in the dynamic deep-link URL:
 *   /v2/location/{locationId}/custom-page-link/{GHL_CUSTOM_MENU_LINK_ID}
 *
 * ⚠️ If GHL updates this ID in the Marketplace App settings, update this constant to match.
 * The current configured ID: 69a642aae76974824fd39bb6A
 */
export const GHL_CUSTOM_MENU_LINK_ID = "69a642aae76974824fd39bb6A";

export const API_CONFIG = {
    // Primary API Base
    base: API_BASE,

    // Specific Endpoints
    sms: `${API_BASE}/api/sms`,
    credits: `${API_BASE}/api/credits`,
    contacts: `${API_BASE}/api/contacts`,         // local Firestore contacts (add/edit/delete)
    ghl_contacts: `${API_BASE}/api/ghl-contacts`,  // GHL live contacts proxy
    ghl_conversations: `${API_BASE}/api/ghl-conversations`, // GHL conversation creation
    messages: `${API_BASE}/api/messages`,
    conversations: `${API_BASE}/api/conversations`,
    bulk_campaigns: `${API_BASE}/api/bulk-campaigns`,
    sender_requests: `${API_BASE}/api/sender-requests`,
    account_sender: `${API_BASE}/api/account-sender`,
    account: `${API_BASE}/api/account`,
    templates: `${API_BASE}/api/templates`,
    tickets: `${API_BASE}/api/tickets`,
    notificationSettings: `${API_BASE}/api/notification-settings`,
    check_message_status: `${API_BASE}/api/check_message_status.php`,
};

export default API_CONFIG;
