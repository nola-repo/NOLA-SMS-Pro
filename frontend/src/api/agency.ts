import { API_BASE_URL, WEBHOOK_SECRET } from "./config";
import { safeStorage } from "../utils/safeStorage";
import { KEYS } from "../services/authService";

export interface AgencySubaccount {
    id: string;
    agency_id: string;
    name: string;
    email: string;
    phone: string;
    toggle_enabled: boolean;
    rate_limit: number;
    attempt_count: number;
    created_at?: any;
    updated_at?: any;
}

const getHeaders = () => {
    const agencyId = safeStorage.getItem(KEYS.companyId) || "";
    return {
        'Content-Type': 'application/json',
        'X-Webhook-Secret': WEBHOOK_SECRET,
        'X-Agency-ID': agencyId,
    };
};

export const fetchAgencySubaccounts = async (): Promise<AgencySubaccount[]> => {
    const res = await fetch(`${API_BASE_URL}/api/agency/get_subaccounts.php`, {
        headers: getHeaders(),
    });
    const data = await res.json();
    if (data.status === 'success') {
        return data.data;
    }
    throw new Error(data.message || 'Failed to fetch sub-accounts');
};

export const syncGhlLocations = async (): Promise<string> => {
    const res = await fetch(`${API_BASE_URL}/api/agency/sync_locations.php`, {
        method: 'POST',
        headers: getHeaders(),
    });
    const data = await res.json();
    if (data.status === 'success') {
        return data.message;
    }
    throw new Error(data.message || 'Failed to sync locations');
};

export const toggleSubaccountSim = async (locationId: string, enabled: boolean): Promise<void> => {
    const res = await fetch(`${API_BASE_URL}/api/agency/toggle_subaccount.php`, {
        method: 'POST',
        headers: getHeaders(),
        body: JSON.stringify({ location_id: locationId, enabled }),
    });
    const data = await res.json();
    if (data.status !== 'success') {
        throw new Error(data.message || 'Failed to toggle status');
    }
};

export const setSubaccountRateLimit = async (locationId: string, limit: number): Promise<void> => {
    const res = await fetch(`${API_BASE_URL}/api/agency/set_rate_limit.php`, {
        method: 'POST',
        headers: getHeaders(),
        body: JSON.stringify({ location_id: locationId, limit }),
    });
    const data = await res.json();
    if (data.status !== 'success') {
        throw new Error(data.message || 'Failed to set rate limit');
    }
};

export const resetSubaccountAttempts = async (locationId: string): Promise<void> => {
    const res = await fetch(`${API_BASE_URL}/api/agency/reset_attempt_count.php`, {
        method: 'POST',
        headers: getHeaders(),
        body: JSON.stringify({ location_id: locationId }),
    });
    const data = await res.json();
    if (data.status !== 'success') {
        throw new Error(data.message || 'Failed to reset attempts');
    }
};
