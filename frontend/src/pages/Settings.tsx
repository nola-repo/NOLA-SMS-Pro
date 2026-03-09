import { useState, useCallback, useEffect } from "react";
import {
    FiUser, FiSend, FiCode, FiBell, FiCreditCard,
    FiSave, FiPlus, FiTrash2, FiCopy, FiCheck,
    FiGlobe, FiMapPin, FiBriefcase, FiCheckCircle, FiAlertCircle, FiClock,
    FiEye, FiEyeOff, FiRefreshCw, FiZap,
} from "react-icons/fi";
import {
    getAccountSettings, saveAccountSettings,
    getAPISettings, saveAPISettings,
    getNotificationSettings, saveNotificationSettings,
    getStoredSenderIds, deleteSenderId,
    TIMEZONES, CURRENCIES,
    type AccountSettings, type APISettings, type NotificationSettings, type StoredSenderId,
} from "../utils/settingsStorage";
import { SenderRequestModal } from "../components/SenderRequestModal";
import { useGhlLocation } from "../hooks/useGhlLocation";

// ─── Types ──────────────────────────────────────────────────────────────────
type SettingsTab = "account" | "senderIds" | "api" | "notifications" | "credits";

interface SettingsProps {
    darkMode: boolean;
    toggleDarkMode: () => void;
    initialTab?: SettingsTab;
    autoOpenAddModal?: boolean;
}

// ─── Constants ───────────────────────────────────────────────────────────────
const TABS: { id: SettingsTab; label: string; icon: React.ReactNode; description: string }[] = [
    { id: "account", label: "Account", icon: <FiUser />, description: "Profile & organization info" },
    { id: "senderIds", label: "Sender IDs", icon: <FiSend />, description: "Manage approved sender IDs" },
    { id: "api", label: "API & Webhooks", icon: <FiCode />, description: "Integration endpoints & keys" },
    { id: "notifications", label: "Notifications", icon: <FiBell />, description: "Alert & report preferences" },
    { id: "credits", label: "Credits", icon: <FiCreditCard />, description: "Balance & billing" },
];



const SENDER_ICONS = [<FiGlobe />, <FiMapPin />, <FiBriefcase />, <FiCheckCircle />];

const STATUS_CONFIG = {
    approved: { label: "Approved", color: "text-emerald-600 dark:text-emerald-400", bg: "bg-emerald-50 dark:bg-emerald-900/20", icon: <FiCheck className="w-3 h-3" /> },
    pending: { label: "Pending", color: "text-amber-600  dark:text-amber-400", bg: "bg-amber-50  dark:bg-amber-900/20", icon: <FiClock className="w-3 h-3" /> },
    rejected: { label: "Rejected", color: "text-red-600    dark:text-red-400", bg: "bg-red-50    dark:bg-red-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
};

// ─── Sub-components ──────────────────────────────────────────────────────────
const SectionHeader: React.FC<{ title: string; subtitle?: string }> = ({ title, subtitle }) => (
    <div className="mb-6">
        <h2 className="text-[18px] font-bold text-[#111111] dark:text-white tracking-tight">{title}</h2>
        {subtitle && <p className="text-[13px] text-[#6e6e73] dark:text-[#94959b] mt-0.5">{subtitle}</p>}
    </div>
);

const Card: React.FC<{ children: React.ReactNode; className?: string }> = ({ children, className = "" }) => (
    <div className={`bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-5 ${className}`}>
        {children}
    </div>
);

const SaveButton: React.FC<{ onClick: () => void; saved: boolean }> = ({ onClick, saved }) => (
    <button
        onClick={onClick}
        className={`flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold text-[13px] transition-all duration-300 ${saved
            ? "bg-emerald-500 text-white shadow-md shadow-emerald-500/25"
            : "bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white shadow-md shadow-blue-500/20"
            }`}
    >
        {saved ? <FiCheck className="w-4 h-4" /> : <FiSave className="w-4 h-4" />}
        {saved ? "Saved!" : "Save Changes"}
    </button>
);

const Toggle: React.FC<{ checked: boolean; onChange: (v: boolean) => void; id: string }> = ({ checked, onChange, id }) => (
    <button
        id={id}
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 ${checked ? "bg-[#2b83fa]" : "bg-gray-200 dark:bg-[#3a3b3f]"
            }`}
    >
        <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform duration-200 ${checked ? "translate-x-6" : "translate-x-1"}`} />
    </button>
);

const InputField: React.FC<{
    label: string; id: string; value: string; onChange: (v: string) => void;
    type?: string; placeholder?: string; hint?: string; disabled?: boolean;
    suffix?: React.ReactNode;
}> = ({ label, id, value, onChange, type = "text", placeholder, hint, disabled, suffix }) => (
    <div>
        <label htmlFor={id} className="block text-[12px] font-semibold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">{label}</label>
        <div className="relative">
            <input
                id={id}
                type={type}
                value={value}
                onChange={e => onChange(e.target.value)}
                placeholder={placeholder}
                disabled={disabled}
                className={`w-full px-4 py-2.5 rounded-xl text-[14px] border transition-all duration-150
          bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a]
          text-[#111111] dark:text-[#ececf1] placeholder-gray-400
          focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/25 focus:border-[#2b83fa]/40
          disabled:opacity-50 disabled:cursor-not-allowed
          ${suffix ? "pr-12" : ""}
        `}
            />
            {suffix && <div className="absolute right-3 top-1/2 -translate-y-1/2">{suffix}</div>}
        </div>
        {hint && <p className="text-[11px] text-[#9aa0a6] mt-1">{hint}</p>}
    </div>
);

const SelectField: React.FC<{
    label: string; id: string; value: string; onChange: (v: string) => void; options: string[];
}> = ({ label, id, value, onChange, options }) => (
    <div>
        <label htmlFor={id} className="block text-[12px] font-semibold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">{label}</label>
        <select
            id={id}
            value={value}
            onChange={e => onChange(e.target.value)}
            className="w-full px-4 py-2.5 rounded-xl text-[14px] border transition-all duration-150
        bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a]
        text-[#111111] dark:text-[#ececf1]
        focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/25 focus:border-[#2b83fa]/40
        appearance-none cursor-pointer"
        >
            {options.map(opt => <option key={opt} value={opt}>{opt}</option>)}
        </select>
    </div>
);

// ─── Section: Account ───────────────────────────────────────────────────────
const AccountSection: React.FC = () => {
    const [form, setForm] = useState<AccountSettings>(getAccountSettings);
    const [saved, setSaved] = useState(false);
    const ghlLocationIdFromHook = useGhlLocation();

    useEffect(() => {
        // Automatically update local form if hook detects a new GHL Location ID
        if (ghlLocationIdFromHook && ghlLocationIdFromHook !== form.ghlLocationId) {
            setForm(prev => ({ ...prev, ghlLocationId: ghlLocationIdFromHook }));
        }
    }, [ghlLocationIdFromHook, form.ghlLocationId]);

    const field = (key: keyof AccountSettings) => ({
        value: String(form[key]),
        onChange: (v: string) => setForm(prev => ({ ...prev, [key]: v })),
    });

    const handleSave = () => {
        saveAccountSettings(form);
        setSaved(true);
        setTimeout(() => setSaved(false), 2000);
    };

    const handleConnectGhl = () => {
        const clientId = "6999da2b8f278296d95f7274-mm9wv85e";
        const redirectUri = window.location.origin + window.location.pathname;
        const scopes = "contacts.readonly contacts.write conversations.readonly conversations/message.readonly conversations/message.write locations.readonly";
        const authUrl = `https://marketplace.gohighlevel.com/oauth/chooselocation?response_type=code&redirect_uri=${encodeURIComponent(redirectUri)}&client_id=${clientId}&scope=${encodeURIComponent(scopes)}`;
        window.location.href = authUrl;
    };

    const statusCfg = STATUS_CONFIG[form.accountStatus];

    return (
        <div className="space-y-5">
            <SectionHeader title="Account" subtitle="Manage your profile and organization settings." />

            {/* Status Banner */}
            <div className={`flex items-center gap-2.5 px-4 py-3 rounded-xl border ${statusCfg.bg} border-transparent`}>
                <span className={statusCfg.color}>{statusCfg.icon}</span>
                <span className={`text-[13px] font-semibold ${statusCfg.color}`}>
                    Account Status: {statusCfg.label}
                </span>
            </div>

            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] mb-4 uppercase tracking-wider">Profile</h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <InputField label="Display Name" id="displayName" placeholder="Your name or org name" {...field("displayName")} />
                    <InputField label="Email Address" id="email" type="email" placeholder="admin@example.com" {...field("email")} />
                </div>
            </Card>

            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] mb-4 uppercase tracking-wider">Localization</h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <SelectField label="Timezone" id="timezone" {...field("timezone")} options={TIMEZONES} />
                    <SelectField label="Currency" id="currency" {...field("currency")} options={CURRENCIES} />
                </div>
            </Card>

            <Card>
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider">GHL Integration & API</h3>
                    {form.ghlOAuthConnected ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 text-[11px] font-semibold rounded-full bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400">
                            <FiCheck className="w-3.5 h-3.5" /> API Connected
                        </span>
                    ) : form.ghlLocationId ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 text-[11px] font-semibold rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400">
                            <FiBriefcase className="w-3.5 h-3.5" /> App Installed
                        </span>
                    ) : null}
                </div>

                {form.ghlLocationId && form.ghlOAuthConnected ? (
                    <div className="flex items-center gap-3 p-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a]">
                        <div className="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-500">
                            <FiCheckCircle className="w-5 h-5" />
                        </div>
                        <div>
                            <p className="text-[13px] font-semibold text-[#111111] dark:text-[#ececf1]">Successfully Connected to GoHighLevel</p>
                            <p className="text-[12px] font-mono text-[#9aa0a6] mt-0.5">Location: {form.ghlLocationId}</p>
                        </div>
                    </div>
                ) : form.ghlLocationId && !form.ghlOAuthConnected ? (
                    <div className="space-y-4">
                        <div className="flex items-center gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/30">
                            <FiAlertCircle className="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0" />
                            <div>
                                <p className="text-[13px] font-semibold text-amber-800 dark:text-amber-300">API Authorization Required</p>
                                <p className="text-[12px] text-amber-700 dark:text-amber-400 mt-0.5">The app is installed (Location: {form.ghlLocationId}), but it needs API access to read contacts and conversations.</p>
                            </div>
                        </div>
                        <button
                            onClick={handleConnectGhl}
                            type="button"
                            className="w-full flex items-center justify-center gap-2 py-2.5 bg-[#2b83fa] hover:bg-[#1d6bd4] text-white rounded-xl font-semibold text-[13px] transition-colors shadow-sm"
                        >
                            <FiGlobe className="w-4 h-4" /> Connect API with GoHighLevel
                        </button>
                    </div>
                ) : (
                    <div className="flex items-center gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/30">
                        <FiAlertCircle className="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0" />
                        <div>
                            <p className="text-[13px] font-semibold text-amber-800 dark:text-amber-300">Not Installed</p>
                            <p className="text-[12px] text-amber-700 dark:text-amber-400 mt-0.5">Please open this app from within GoHighLevel initially to detect your Location ID.</p>
                        </div>
                    </div>
                )}
            </Card>

            <div className="flex justify-end">
                <SaveButton onClick={handleSave} saved={saved} />
            </div>
        </div>
    );
};

// ─── Section: Sender IDs ────────────────────────────────────────────────────
const SenderIdsSection: React.FC<{ autoOpenAddModal?: boolean }> = ({ autoOpenAddModal }) => {
    const DEFAULT_SENDER_IDS: StoredSenderId[] = [
        { id: "NOLACRM", name: "NOLACRM", description: "Default System Sender", color: "bg-blue-500", status: "approved" },
        { id: "BRANCH1", name: "BRANCH1", description: "Standard Sender ID", color: "bg-purple-500", status: "approved" },
        { id: "BRANCH2", name: "BRANCH2", description: "Alternate Sender ID", color: "bg-orange-500", status: "approved" },
    ];

    const [customIds, setCustomIds] = useState<StoredSenderId[]>(getStoredSenderIds);
    const [isAdding, setIsAdding] = useState(false);

    // Auto-open modal when triggered from Composer
    useEffect(() => {
        if (autoOpenAddModal) {
            setIsAdding(true);
        }
    }, [autoOpenAddModal]);

    const allIds = [...DEFAULT_SENDER_IDS, ...customIds];

    const handleSuccess = (created: StoredSenderId) => {
        setCustomIds(prev => [...prev, created]);
    };

    const handleDelete = (id: string) => {
        deleteSenderId(id);
        setCustomIds(prev => prev.filter(s => s.id !== id));
    };

    return (
        <div className="space-y-5">
            <SectionHeader title="Sender IDs" subtitle="Manage and request sender IDs for your account. Only approved IDs can be used for sending." />

            <Card>
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider">Active Sender IDs</h3>
                    <button
                        onClick={() => setIsAdding(true)}
                        className="flex items-center gap-1.5 px-3 py-2 text-[12px] font-bold text-[#2b83fa] bg-gradient-to-r from-[#2b83fa]/10 to-[#2b83fa]/5 hover:from-[#2b83fa]/20 hover:to-[#2b83fa]/10 hover:shadow-[0_4px_12px_rgba(43,131,250,0.2)] rounded-xl transition-all"
                    >
                        <FiPlus className="w-3.5 h-3.5" /> Request New
                    </button>
                </div>

                <div className="space-y-2">
                    {allIds.map((sid, i) => {
                        const isCustom = DEFAULT_SENDER_IDS.every(d => d.id !== sid.id);
                        const statusCfg = STATUS_CONFIG[sid.status];
                        const icon = SENDER_ICONS[i % SENDER_ICONS.length];
                        return (
                            <div key={sid.id} className="flex items-center gap-3 p-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] group">
                                <div className={`w-9 h-9 rounded-xl flex items-center justify-center text-white flex-shrink-0 text-[14px] ${sid.color}`}>
                                    {icon}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="text-[13px] font-bold text-[#111111] dark:text-[#ececf1]">{sid.name}</span>
                                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold ${statusCfg.bg} ${statusCfg.color}`}>
                                            {statusCfg.icon} {statusCfg.label}
                                        </span>
                                    </div>
                                    <span className="text-[11px] text-[#9aa0a6]">{sid.description}</span>
                                </div>
                                {isCustom && sid.status !== "approved" && (
                                    <button
                                        onClick={() => handleDelete(sid.id)}
                                        className="opacity-0 group-hover:opacity-100 p-2 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all"
                                    >
                                        <FiTrash2 className="w-4 h-4" />
                                    </button>
                                )}
                            </div>
                        );
                    })}
                </div>
            </Card>

            {/* Add New Sender ID Shared Modal */}
            <SenderRequestModal
                isOpen={isAdding}
                onClose={() => setIsAdding(false)}
                onSuccess={handleSuccess}
            />

            <div className="flex items-start gap-3 p-4 bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/30 rounded-xl">
                <FiAlertCircle className="w-4 h-4 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                <p className="text-[12px] text-amber-700 dark:text-amber-400">
                    Newly requested Sender IDs are <strong>pending approval</strong>. Only approved IDs can be used for sending messages. Contact your administrator for approval.
                </p>
            </div>
        </div>
    );
};




// ─── Section: API & Webhooks ────────────────────────────────────────────────
const APISection: React.FC = () => {
    const [form, setForm] = useState<APISettings>(getAPISettings);
    const [saved, setSaved] = useState(false);
    const [copied, setCopied] = useState<string | null>(null);
    const [showKey, setShowKey] = useState(false);

    const field = (key: keyof APISettings) => ({
        value: form[key],
        onChange: (v: string) => setForm(prev => ({ ...prev, [key]: v })),
    });

    const handleSave = () => {
        saveAPISettings(form);
        setSaved(true);
        setTimeout(() => setSaved(false), 2000);
    };

    const handleCopy = (text: string, id: string) => {
        navigator.clipboard.writeText(text).then(() => {
            setCopied(id);
            setTimeout(() => setCopied(null), 2000);
        });
    };

    const ENDPOINT_ROWS = [
        { label: "Send SMS", method: "POST", path: "/api/v1/accounts/{id}/messages" },
        { label: "List Messages", method: "GET", path: "/api/v1/accounts/{id}/messages" },
        { label: "List Sender IDs", method: "GET", path: "/api/v1/accounts/{id}/sender-ids" },
        { label: "Credit Balance", method: "GET", path: "/api/v1/accounts/{id}/credits" },
        { label: "GHL Webhook", method: "POST", path: "/webhook/send_sms" },
        { label: "Receive SMS", method: "POST", path: "/webhook/receive_sms" },
    ];

    return (
        <div className="space-y-5">
            <SectionHeader title="API & Webhooks" subtitle="Configure your integration endpoints and manage API credentials." />

            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] mb-4 uppercase tracking-wider">Webhook Configuration</h3>
                <div className="space-y-4">
                    <InputField
                        label="Webhook Base URL"
                        id="webhookUrl"
                        placeholder="https://smspro-api.nolacrm.io"
                        hint="Base URL of your NOLA SMS Pro API server."
                        {...field("webhookUrl")}
                    />
                    <InputField
                        label="Webhook Secret"
                        id="webhookSecret"
                        type="password"
                        placeholder="Enter webhook verification secret"
                        hint="Secret token used to verify webhook payloads from GHL or partners."
                        {...field("webhookSecret")}
                    />
                </div>
            </Card>

            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] mb-4 uppercase tracking-wider">API Key</h3>
                <div>
                    <label className="block text-[12px] font-semibold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Your API Key</label>
                    <div className="relative flex gap-2">
                        <input
                            type={showKey ? "text" : "password"}
                            value={form.apiKey}
                            readOnly
                            className="flex-1 px-4 py-2.5 rounded-xl text-[13px] font-mono border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none cursor-default select-all"
                        />
                        <button onClick={() => setShowKey(v => !v)} className="p-2.5 rounded-xl border border-[#e0e0e0] dark:border-[#ffffff0a] bg-[#f7f7f7] dark:bg-[#0d0e10] text-gray-500 hover:text-[#2b83fa] transition-colors" title={showKey ? "Hide" : "Show"}>
                            {showKey ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                        </button>
                        <button onClick={() => handleCopy(form.apiKey, "apiKey")} className="p-2.5 rounded-xl border border-[#e0e0e0] dark:border-[#ffffff0a] bg-[#f7f7f7] dark:bg-[#0d0e10] text-gray-500 hover:text-[#2b83fa] transition-colors" title="Copy">
                            {copied === "apiKey" ? <FiCheck className="w-4 h-4 text-emerald-500" /> : <FiCopy className="w-4 h-4" />}
                        </button>
                    </div>
                    <p className="text-[11px] text-[#9aa0a6] mt-1.5">API keys are managed server-side. Contact your admin to regenerate.</p>
                </div>
            </Card>

            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] mb-4 uppercase tracking-wider">API Endpoints Reference</h3>
                <div className="space-y-1.5">
                    {ENDPOINT_ROWS.map(ep => (
                        <div key={`${ep.method}-${ep.path}`} className="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] group">
                            <span className={`text-[10px] font-black uppercase px-2 py-0.5 rounded-md tracking-wider flex-shrink-0 ${ep.method === "POST" ? "bg-[#2b83fa]/10 text-[#2b83fa]" : "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"}`}>
                                {ep.method}
                            </span>
                            <span className="flex-1 text-[12px] font-mono text-[#37352f] dark:text-[#ececf1] truncate">{ep.path}</span>
                            <span className="text-[11px] text-[#9aa0a6] hidden sm:block">{ep.label}</span>
                            <button
                                onClick={() => handleCopy(`${form.webhookUrl}${ep.path}`, ep.path)}
                                className="opacity-0 group-hover:opacity-100 p-1.5 rounded-lg text-gray-400 hover:text-[#2b83fa] transition-all"
                                title="Copy full URL"
                            >
                                {copied === ep.path ? <FiCheck className="w-3.5 h-3.5 text-emerald-500" /> : <FiCopy className="w-3.5 h-3.5" />}
                            </button>
                        </div>
                    ))}
                </div>
            </Card>

            <div className="flex justify-end">
                <SaveButton onClick={handleSave} saved={saved} />
            </div>
        </div>
    );
};

// ─── Section: Notifications ─────────────────────────────────────────────────
const NotificationsSection: React.FC = () => {
    const [form, setForm] = useState<NotificationSettings>(getNotificationSettings);
    const [saved, setSaved] = useState(false);

    const toggle = (key: keyof NotificationSettings) =>
        setForm(prev => ({ ...prev, [key]: !prev[key] }));

    const handleSave = () => {
        saveNotificationSettings(form);
        setSaved(true);
        setTimeout(() => setSaved(false), 2000);
    };

    const ROWS: { key: keyof NotificationSettings; label: string; desc: string; icon: React.ReactNode }[] = [
        { key: "deliveryReports", label: "SMS Delivery Reports", desc: "Get notified when messages are delivered or fail.", icon: <FiCheckCircle className="w-4 h-4" /> },
        { key: "lowBalanceAlert", label: "Low Balance Alert", desc: "Alert when credit balance drops below threshold.", icon: <FiAlertCircle className="w-4 h-4" /> },
        { key: "inboundSmsAlert", label: "Inbound SMS Alerts", desc: "Notify when an inbound SMS is received.", icon: <FiBell className="w-4 h-4" /> },
        { key: "marketingEmails", label: "Marketing & Updates", desc: "Product news and feature announcements via email.", icon: <FiGlobe className="w-4 h-4" /> },
    ];

    return (
        <div className="space-y-5">
            <SectionHeader title="Notifications" subtitle="Choose which alerts and reports you want to receive." />

            <Card>
                <div className="divide-y divide-[#f0f0f0] dark:divide-[#2a2b32]">
                    {ROWS.map(row => (
                        <div key={row.key} className="flex items-center justify-between py-4 first:pt-0 last:pb-0">
                            <div className="flex items-start gap-3">
                                <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 flex items-center justify-center text-[#2b83fa] flex-shrink-0 mt-0.5">
                                    {row.icon}
                                </div>
                                <div>
                                    <p className="text-[14px] font-semibold text-[#111111] dark:text-[#ececf1]">{row.label}</p>
                                    <p className="text-[12px] text-[#9aa0a6]">{row.desc}</p>
                                </div>
                            </div>
                            <Toggle checked={form[row.key] as boolean} onChange={() => toggle(row.key)} id={`toggle-${row.key}`} />
                        </div>
                    ))}
                </div>
            </Card>

            {form.lowBalanceAlert && (
                <Card>
                    <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] mb-4 uppercase tracking-wider">Low Balance Threshold</h3>
                    <div className="flex items-center gap-4">
                        <input
                            type="range"
                            min={10} max={500} step={10}
                            value={form.lowBalanceThreshold}
                            onChange={e => setForm(prev => ({ ...prev, lowBalanceThreshold: Number(e.target.value) }))}
                            className="flex-1 accent-[#2b83fa]"
                        />
                        <span className="text-[15px] font-bold text-[#2b83fa] min-w-[60px] text-right">{form.lowBalanceThreshold} credits</span>
                    </div>
                    <p className="text-[11px] text-[#9aa0a6] mt-2">Alert triggers when balance drops below this credit level.</p>
                </Card>
            )}

            <div className="flex justify-end">
                <SaveButton onClick={handleSave} saved={saved} />
            </div>
        </div>
    );
};

// ─── Section: Credits ───────────────────────────────────────────────────────
const CreditsSection: React.FC = () => {
    const account = getAccountSettings();
    const [topUpAmount, setTopUpAmount] = useState(500);
    const [submitted, setSubmitted] = useState(false);

    const PACKAGES = [
        { credits: 500, price: 500 },
        { credits: 1100, price: 1000 },
        { credits: 2750, price: 2500 },
        { credits: 6000, price: 5000 },
    ];

    const handleTopUp = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitted(true);
        setTimeout(() => { setSubmitted(false); }, 2500);
    };

    const usagePercent = Math.min(100, (account.creditBalance / 1000) * 100);
    const usageColor = account.creditBalance < 50 ? "bg-red-500" : account.creditBalance < 200 ? "bg-amber-400" : "bg-emerald-500";

    const LEDGER_MOCK = [
        { type: "deduction", amount: -3, note: "SMS to +63917XXXXXXX", date: "Today, 2:15 PM" },
        { type: "deduction", amount: -15, note: "Bulk send – 5 recipients", date: "Today, 9:00 AM" },
        { type: "top_up", amount: +500, note: "Manual top-up by admin", date: "Mar 2, 3:00 PM" },
        { type: "deduction", amount: -2, note: "SMS to +63918XXXXXXX", date: "Mar 2, 11:00 AM" },
        { type: "refund", amount: +2, note: "Refund – send failed", date: "Mar 1, 4:00 PM" },
    ];

    return (
        <div className="space-y-5">
            <SectionHeader title="Credits & Billing" subtitle="Monitor your SMS credit balance and request top-ups." />

            {/* Balance Card */}
            <div className="bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] rounded-2xl p-5 text-white shadow-lg shadow-blue-500/25">
                <div className="flex items-start justify-between mb-4">
                    <div>
                        <p className="text-[12px] font-semibold text-white/70 uppercase tracking-wider">Available Credits</p>
                        <p className="text-[42px] font-black leading-none mt-1">{account.creditBalance.toLocaleString()}</p>
                        <p className="text-[12px] text-white/60 mt-1">1 credit ≈ 1 SMS (160 chars)</p>
                    </div>
                    <div className="w-12 h-12 rounded-xl bg-white/10 flex items-center justify-center">
                        <FiCreditCard className="w-6 h-6 text-white" />
                    </div>
                </div>
                <div className="mb-1">
                    <div className="h-1.5 bg-white/20 rounded-full overflow-hidden">
                        <div className={`h-full ${usageColor} rounded-full transition-all`} style={{ width: `${usagePercent}%` }} />
                    </div>
                </div>
                <div className="flex items-center justify-between text-[11px] text-white/60">
                    <span>0</span>
                    <span>1,000+</span>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {[
                    { label: "Sent Today", value: "18", icon: <FiSend className="w-4 h-4" />, color: "text-[#2b83fa]", bg: "bg-[#2b83fa]/10" },
                    { label: "Credits Used", value: "18", icon: <FiZap className="w-4 h-4" />, color: "text-amber-500", bg: "bg-amber-500/10" },
                    { label: "This Month", value: "342", icon: <FiRefreshCw className="w-4 h-4" />, color: "text-purple-500", bg: "bg-purple-500/10" },
                ].map(stat => (
                    <Card key={stat.label} className="flex items-center gap-3 !py-4">
                        <div className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${stat.bg} ${stat.color}`}>{stat.icon}</div>
                        <div>
                            <p className="text-[20px] font-black text-[#111111] dark:text-[#ececf1]">{stat.value}</p>
                            <p className="text-[11px] text-[#9aa0a6]">{stat.label}</p>
                        </div>
                    </Card>
                ))}
            </div>

            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider mb-4">Top Up Credits</h3>

                <form onSubmit={handleTopUp} className="space-y-4">
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        {PACKAGES.map(pkg => (
                            <button
                                key={pkg.credits}
                                type="button"
                                onClick={() => setTopUpAmount(pkg.credits)}
                                className={`flex flex-col items-center py-3 rounded-xl border-2 transition-all ${topUpAmount === pkg.credits
                                    ? "border-[#2b83fa] bg-[#2b83fa]/5 dark:bg-[#2b83fa]/10"
                                    : "border-[#e0e0e0] dark:border-[#2a2b32] hover:border-[#2b83fa]/40"
                                    }`}
                            >
                                <span className={`text-[16px] font-black ${topUpAmount === pkg.credits ? "text-[#2b83fa]" : "text-[#111111] dark:text-[#ececf1]"}`}>{pkg.credits.toLocaleString()}</span>
                                <span className="text-[11px] text-[#9aa0a6]">credits</span>
                                <span className={`text-[12px] font-bold mt-1 ${topUpAmount === pkg.credits ? "text-[#2b83fa]" : "text-[#6e6e73] dark:text-[#94959b]"}`}>₱{pkg.price}</span>
                            </button>
                        ))}
                    </div>
                    {submitted ? (
                        <div className="flex items-center justify-center gap-2 py-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl text-emerald-600 dark:text-emerald-400 font-semibold text-[13px]">
                            <FiCheck className="w-4 h-4" /> Top-up request submitted! Admin will review shortly.
                        </div>
                    ) : (
                        <button type="submit" className="w-full flex items-center justify-center gap-2 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-semibold text-[14px] transition-all shadow-md shadow-blue-500/20">
                            <FiZap className="w-4 h-4" /> Buy {topUpAmount.toLocaleString()} Credits
                        </button>
                    )}
                </form>
            </Card>

            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] mb-4 uppercase tracking-wider">Recent Transactions</h3>
                <div className="space-y-2">
                    {LEDGER_MOCK.map((tx, i) => (
                        <div key={i} className="flex items-center gap-3 py-2 border-b border-[#f0f0f0] dark:border-[#2a2b32] last:border-0">
                            <div className={`w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-[11px] font-bold ${tx.type === "top_up" || tx.type === "refund" ? "bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400" : "bg-red-50 dark:bg-red-900/20 text-red-500"}`}>
                                {tx.type === "top_up" || tx.type === "refund" ? "+" : "−"}
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-[13px] font-medium text-[#111111] dark:text-[#ececf1] truncate">{tx.note}</p>
                                <p className="text-[11px] text-[#9aa0a6]">{tx.date}</p>
                            </div>
                            <span className={`text-[13px] font-bold flex-shrink-0 ${tx.amount > 0 ? "text-emerald-600 dark:text-emerald-400" : "text-red-500"}`}>
                                {tx.amount > 0 ? "+" : ""}{tx.amount} credits
                            </span>
                        </div>
                    ))}
                </div>
            </Card>
        </div>
    );
};

// ─── Main Export ─────────────────────────────────────────────────────────────
export const Settings: React.FC<SettingsProps> = ({ darkMode, toggleDarkMode, initialTab, autoOpenAddModal }) => {
    const [activeTab, setActiveTab] = useState<SettingsTab>(initialTab || "account");

    const renderContent = useCallback(() => {
        switch (activeTab) {
            case "account": return <AccountSection />;
            case "senderIds": return <SenderIdsSection autoOpenAddModal={autoOpenAddModal && activeTab === "senderIds"} />;
            case "api": return <APISection />;
            case "notifications": return <NotificationsSection />;
            case "credits": return <CreditsSection />;
        }
    }, [activeTab, darkMode, toggleDarkMode, autoOpenAddModal]);

    const activeTabInfo = TABS.find(t => t.id === activeTab)!;

    return (
        <div className="h-full flex flex-col overflow-hidden bg-[#f7f7f7] dark:bg-[#111111]">
            {/* Page Header */}
            <div className="px-6 py-4 border-b border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#1a1b1e]/80 backdrop-blur-xl flex-shrink-0">
                <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 flex items-center justify-center text-[#2b83fa]">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div>
                        <h1 className="text-[16px] font-bold text-[#111111] dark:text-white">Settings</h1>
                        <p className="text-[11px] text-[#9aa0a6]">{activeTabInfo.description}</p>
                    </div>
                </div>
            </div>

            {/* Body: two-column layout on md+ */}
            <div className="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

                {/* Sidebar Nav */}
                <nav className="md:w-56 flex-shrink-0 border-b md:border-b-0 md:border-r border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#1a1b1e]/80 backdrop-blur-xl overflow-x-auto md:overflow-x-visible overflow-y-auto">
                    <div className="flex md:flex-col gap-1 p-2 md:p-3">
                        {TABS.map(tab => {
                            const isActive = activeTab === tab.id;
                            return (
                                <button
                                    key={tab.id}
                                    onClick={() => setActiveTab(tab.id)}
                                    className={`relative flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 text-left whitespace-nowrap md:whitespace-normal flex-shrink-0 md:flex-shrink md:w-full group
                    ${isActive
                                            ? "bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 text-[#2b83fa]"
                                            : "text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03] hover:text-[#111111] dark:hover:text-[#ececf1]"}
                  `}
                                >
                                    {/* Left accent bar - desktop only */}
                                    {isActive && (
                                        <div className="hidden md:block absolute left-0 top-1/2 -translate-y-1/2 w-1 h-5 bg-[#2b83fa] rounded-r-full shadow-[0_0_8px_rgba(43,131,250,0.5)]" />
                                    )}
                                    <span className={`text-[15px] flex-shrink-0 transition-all duration-300 ${isActive
                                        ? "scale-110 text-[#2b83fa] drop-shadow-[0_0_8px_rgba(43,131,250,0.4)]"
                                        : "group-hover:scale-105 group-hover:text-[#2b83fa]"
                                        }`}>{tab.icon}</span>
                                    <span className={`text-[13.5px] ${isActive ? "font-bold tracking-tight" : "font-medium"}`}>{tab.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </nav>

                {/* Content Panel */}
                <main className="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 bg-[#f7f7f7] dark:bg-[#111111]">
                    <div className="max-w-2xl mx-auto">
                        {renderContent()}
                    </div>
                </main>
            </div>
        </div>
    );
};
