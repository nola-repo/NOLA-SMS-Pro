import { devLog } from './devLog';
import { safeStorage } from './safeStorage';
// ─── Keys ────────────────────────────────────────────────────────────────────
const KEYS = {
    account: "nola_settings_account",
    api: "nola_settings_api",
    notifications: "nola_settings_notifications",
    senderIds: "custom_sender_ids", // shared with SenderSelector component
};

// ─── Types ───────────────────────────────────────────────────────────────────
export interface AccountSettings {
    displayName: string;
    email: string;
    accountStatus: "approved" | "pending" | "rejected";
    creditBalance: number;
    ghlLocationId: string;
    ghlOAuthConnected?: boolean;
    ghlClientId?: string;
}

export interface APISettings {
    webhookUrl: string;
    apiKey: string;
    webhookSecret: string;
}

export interface NotificationSettings {
    deliveryReports: boolean;
    lowBalanceAlert: boolean;
    lowBalanceThreshold: number;
    marketingEmails: boolean;
    ghlWorkflowSyncEnabled: boolean;
    inboundSmsAlert?: boolean;
    alertEmail?: string;
    ghlAlertContactId?: string;
}

export interface StoredSenderId {
    id: string;
    name: string;
    description: string;
    color: string;
    status: "approved" | "pending" | "rejected";
    provider?: "system" | "semaphore" | "unisms";
}

// ─── Defaults ────────────────────────────────────────────────────────────────
const DEFAULT_ACCOUNT: AccountSettings = {
    displayName: "NOLA SMS Pro",
    email: "admin@nolacrm.io",
    accountStatus: "approved",
    creditBalance: 500,
    ghlLocationId: "",
    ghlOAuthConnected: false,
    // Read from env so the client ID is not hardcoded in the bundle
    ghlClientId: import.meta.env.VITE_GHL_CLIENT_ID ?? "",
};

const DEFAULT_API: APISettings = {
    webhookUrl: `${import.meta.env.VITE_API_BASE}/api/sms`,
    // Never store a real key as a default — the UI should fetch & display a masked
    // version from the backend. An empty string here prevents the key from leaking
    // into localStorage when a user hasn't configured their API settings yet.
    apiKey: "",
    webhookSecret: "",
};

const DEFAULT_NOTIFICATIONS: NotificationSettings = {
    deliveryReports: true,
    lowBalanceAlert: true,
    lowBalanceThreshold: 50,
    marketingEmails: false,
    ghlWorkflowSyncEnabled: false,
    inboundSmsAlert: true,
    alertEmail: "",
    ghlAlertContactId: "",
};

// ─── Helpers ─────────────────────────────────────────────────────────────────
function load<T>(key: string, fallback: T): T {
    try {
        const raw = safeStorage.getItem(key);
        if (!raw) return fallback;
        return { ...fallback, ...JSON.parse(raw) } as T;
    } catch {
        return fallback;
    }
}

function save<T>(key: string, data: T): void {
    try {
        safeStorage.setItem(key, JSON.stringify(data));
    } catch (e) {
        devLog.error("settingsStorage: failed to save", key, e);
    }
}

// ─── Account ─────────────────────────────────────────────────────────────────
export const getAccountSettings = (): AccountSettings => {
    const settings = load(KEYS.account, DEFAULT_ACCOUNT);

    // ─── Iframe / Multi-tenant Fallback ───
    // GHL loads the app in an iframe and can open multiple sub-accounts in the same browser.
    // We MUST always prefer the location from the current URL over any cached value
    // to avoid leaking one sub-account's location_id into another.
    if (typeof window !== "undefined") {
        const search = window.location.search;
        const hash = window.location.hash;

        const getParam = (query: string, key: string) => new URLSearchParams(query).get(key);
        const keys = ["location_id", "locationId", "ghl_location_id", "ghlLocationId", "active_location_id", "activeLocationId"];

        // Prefer location from URL if present (query or hash), regardless of stored value
        for (const k of keys) {
            const val = getParam(search, k);
            if (val) { settings.ghlLocationId = val; break; }
        }

        // Check hash params (e.g. #/dashboard?location_id=...)
        // Also prefer hash value over cached storage if present.
        if (hash.includes("?")) {
            const hashQuery = hash.split("?")[1];
            for (const k of keys) {
                const val = getParam("?" + hashQuery, k);
                if (val && /^[A-Za-z0-9_-]{12,80}$/.test(val)) { settings.ghlLocationId = val; break; }
            }
        }
    }

    return settings;
};

export const saveAccountSettings = (data: AccountSettings): void =>
    save(KEYS.account, data);

// ─── API / Webhook ────────────────────────────────────────────────────────────
export const getAPISettings = (): APISettings => load(KEYS.api, DEFAULT_API);

export const saveAPISettings = (data: APISettings): void =>
    save(KEYS.api, data);

// ─── Notifications ────────────────────────────────────────────────────────────
export const getNotificationSettings = (): NotificationSettings =>
    load(KEYS.notifications, DEFAULT_NOTIFICATIONS);

export const saveNotificationSettings = (data: NotificationSettings): void =>
    save(KEYS.notifications, data);

export const getStoredSenderIds = (): StoredSenderId[] => {
    try {
        const raw = safeStorage.getItem(KEYS.senderIds);
        if (!raw) return [];
        return JSON.parse(raw) as StoredSenderId[];
    } catch {
        return [];
    }
};

export const getPreferredSender = (): string | null => {
    return safeStorage.getItem("nola_settings_preferred_sender");
};

export const savePreferredSender = (id: string): void => {
    safeStorage.setItem("nola_settings_preferred_sender", id);
};

export const saveStoredSenderIds = (ids: StoredSenderId[]): void =>
    save(KEYS.senderIds, ids);

export const addSenderId = (
    id: string,
    description: string,
    color: string
): StoredSenderId => {
    const existing = getStoredSenderIds();
    const newEntry: StoredSenderId = {
        id: id.trim().toUpperCase(),
        name: id.trim().toUpperCase(),
        description: description.trim() || "Custom Sender ID",
        color,
        status: "pending",
    };
    saveStoredSenderIds([...existing, newEntry]);
    return newEntry;
};

export const deleteSenderId = (id: string): void => {
    const updated = getStoredSenderIds().filter((s) => s.id !== id);
    saveStoredSenderIds(updated);
};
