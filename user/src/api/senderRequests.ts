import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";

// ─── Types ───────────────────────────────────────────────────────────────────
export interface SenderRequest {
    id: string;
    location_id: string;
    requested_id: string;
    status: "pending" | "approved" | "rejected";
    purpose?: string;
    sample_message?: string;
    created_at: string;
}

export interface AccountSenderConfig {
    approved_sender_id: string | null;
    nola_pro_api_key: string | null;     // Preferred field (new)
    semaphore_api_key: string | null;    // Legacy fallback
    free_usage_count: number;
    system_default_sender: string;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function getLocationHeaders(): { headers: Record<string, string>; locationId: string } {
    const { ghlLocationId } = getAccountSettings();
    const headers: Record<string, string> = { "Content-Type": "application/json" };
    if (ghlLocationId) {
        headers["X-GHL-Location-ID"] = ghlLocationId;
    }
    return { headers, locationId: ghlLocationId };
}

// ─── API Functions ───────────────────────────────────────────────────────────

/**
 * Submit a new Sender ID request to Firestore via the backend.
 */
export const submitSenderRequest = async (
    requestedId: string,
    purpose: string,
    sampleMessage: string
): Promise<SenderRequest> => {
    const { headers, locationId } = getLocationHeaders();

    const res = await fetch(API_CONFIG.sender_requests, {
        method: "POST",
        headers,
        body: JSON.stringify({
            location_id: locationId,
            requested_id: requestedId.trim().toUpperCase(),
            purpose,
            sample_message: sampleMessage,
        }),
    });

    if (!res.ok) {
        const errorText = await res.text();
        throw new Error(`Failed to submit sender request: ${res.status} - ${errorText}`);
    }

    return res.json();
};

/**
 * Fetch all sender ID requests for the current location.
 */
export const fetchSenderRequests = async (): Promise<SenderRequest[]> => {
    const { headers, locationId } = getLocationHeaders();

    let url = API_CONFIG.sender_requests;
    if (locationId) {
        url += `?location_id=${encodeURIComponent(locationId)}`;
    }

    const res = await fetch(url, { headers });
    if (!res.ok) {
        console.error("[fetchSenderRequests] Error:", res.status);
        return [];
    }

    const data = await res.json();
    return Array.isArray(data) ? data : (data.data || []);
};

/**
 * Fetch the account's sender configuration (approved sender, API key, free usage).
 */
export const fetchAccountSenderConfig = async (): Promise<AccountSenderConfig> => {
    const { headers, locationId } = getLocationHeaders();

    const DEFAULT_CONFIG: AccountSenderConfig = {
        approved_sender_id: null,
        nola_pro_api_key: null,
        semaphore_api_key: null,
        free_usage_count: 0,
        system_default_sender: "NOLASMSPro",
    };

    let url = API_CONFIG.account_sender;
    if (locationId) {
        url += `?location_id=${encodeURIComponent(locationId)}`;
    }

    try {
        const res = await fetch(url, { headers });
        if (!res.ok) {
            console.error("[fetchAccountSenderConfig] Error:", res.status);
            return DEFAULT_CONFIG;
        }
        const raw = await res.json();
        const inner = raw.data || raw;
        return { ...DEFAULT_CONFIG, ...inner };
    } catch (error) {
        console.error("[fetchAccountSenderConfig] Network error:", error);
        return DEFAULT_CONFIG;
    }
};

/**
 * Save/update the user's Semaphore API key.
 */
export const saveAccountApiKey = async (apiKey: string): Promise<boolean> => {
    const { headers, locationId } = getLocationHeaders();

    try {
        const res = await fetch(API_CONFIG.account_sender, {
            method: "POST",
            headers,
            body: JSON.stringify({
                location_id: locationId,
                semaphore_api_key: apiKey,  // Current backend field
                nola_pro_api_key: apiKey,   // Future standardized field
            }),
        });

        if (!res.ok) {
            const errorText = await res.text();
            throw new Error(`Failed to save API key: ${res.status} - ${errorText}`);
        }
        return true;
    } catch (error) {
        console.error("[saveAccountApiKey] Error:", error);
        return false;
    }
};
