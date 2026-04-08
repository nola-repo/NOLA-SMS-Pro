import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";

export interface AccountProfile {
    location_id: string;
    location_name: string | null;
}

function getLocationHeaders(): { headers: Record<string, string>; locationId: string } {
    const { ghlLocationId } = getAccountSettings();
    const headers: Record<string, string> = { "Content-Type": "application/json" };
    if (ghlLocationId) {
        headers["X-GHL-Location-ID"] = ghlLocationId;
    }
    return { headers, locationId: ghlLocationId };
}

/**
 * Fetch the basic account profile (including location_name).
 */
export const fetchAccountProfile = async (): Promise<AccountProfile | null> => {
    const { headers, locationId } = getLocationHeaders();

    if (!locationId) return null;

    let url = API_CONFIG.account;
    url += `?location_id=${encodeURIComponent(locationId)}`;

    try {
        const res = await fetch(url, { headers });
        if (!res.ok) {
            console.error("[fetchAccountProfile] Error:", res.status);
            return null;
        }
        const data = await res.json();
        return data.data || null;
    } catch (error) {
        console.error("[fetchAccountProfile] Network error:", error);
        return null;
    }
};
