export type SettingsTab = "account" | "senderIds" | "notifications" | "credits";
export type SenderDisplayStatus = "fallback" | "active" | "approved" | "pending" | "rejected" | "revoked" | "configuration_mismatch";

export interface SettingsProps {
    darkMode: boolean;
    toggleDarkMode: () => void;
    initialTab?: SettingsTab;
    autoOpenAddModal?: boolean;
}
