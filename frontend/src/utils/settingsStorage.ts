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
    timezone: string;
    currency: string;
    accountStatus: "approved" | "pending" | "rejected";
    creditBalance: number;
    ghlLocationId: string;
    ghlOAuthConnected?: boolean;
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
    inboundSmsAlert: boolean;
    marketingEmails: boolean;
}

export interface StoredSenderId {
    id: string;
    name: string;
    description: string;
    color: string;
    status: "approved" | "pending" | "rejected";
}

// ─── Defaults ────────────────────────────────────────────────────────────────
const DEFAULT_ACCOUNT: AccountSettings = {
    displayName: "NOLA Admin",
    email: "admin@nolacrm.io",
    timezone: "Asia/Manila",
    currency: "PHP",
    accountStatus: "approved",
    creditBalance: 500,
    ghlLocationId: "",
    ghlOAuthConnected: false,
};

const DEFAULT_API: APISettings = {
    webhookUrl: "https://smspro-api.nolacrm.io/webhook/send_sms",
    apiKey: "nola_sk_••••••••••••••••••••",
    webhookSecret: "",
};

const DEFAULT_NOTIFICATIONS: NotificationSettings = {
    deliveryReports: true,
    lowBalanceAlert: true,
    lowBalanceThreshold: 50,
    inboundSmsAlert: false,
    marketingEmails: false,
};

// ─── Helpers ─────────────────────────────────────────────────────────────────
function load<T>(key: string, fallback: T): T {
    try {
        const raw = localStorage.getItem(key);
        if (!raw) return fallback;
        return { ...fallback, ...JSON.parse(raw) } as T;
    } catch {
        return fallback;
    }
}

function save<T>(key: string, data: T): void {
    try {
        localStorage.setItem(key, JSON.stringify(data));
    } catch (e) {
        console.error("settingsStorage: failed to save", key, e);
    }
}

// ─── Account ─────────────────────────────────────────────────────────────────
export const getAccountSettings = (): AccountSettings =>
    load(KEYS.account, DEFAULT_ACCOUNT);

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

// ─── Sender IDs ───────────────────────────────────────────────────────────────
export const getStoredSenderIds = (): StoredSenderId[] => {
    try {
        const raw = localStorage.getItem(KEYS.senderIds);
        if (!raw) return [];
        return JSON.parse(raw) as StoredSenderId[];
    } catch {
        return [];
    }
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

// ─── Constants ───────────────────────────────────────────────────────────────
export const TIMEZONES = [
    "Asia/Manila",
    "Asia/Singapore",
    "Asia/Tokyo",
    "Asia/Hong_Kong",
    "Asia/Kolkata",
    "Asia/Dubai",
    "Europe/London",
    "Europe/Paris",
    "Europe/Berlin",
    "America/New_York",
    "America/Chicago",
    "America/Denver",
    "America/Los_Angeles",
    "America/Sao_Paulo",
    "Australia/Sydney",
    "Pacific/Auckland",
];

export const CURRENCIES = ["PHP", "USD", "EUR", "SGD", "AUD", "GBP", "JPY"];
