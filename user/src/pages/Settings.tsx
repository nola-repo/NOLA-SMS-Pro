import { useState, useCallback, useEffect, useRef } from "react";
import { fetchCreditBalance, fetchCreditTransactions, fetchCreditPackages } from "../api/credits";
import type { CreditTransaction, CreditPackage } from "../api/credits";
import {
    FiUser, FiSend, FiBell, FiCreditCard,
    FiSave, FiPlus, FiCheck,
    FiGlobe, FiMapPin, FiBriefcase, FiCheckCircle, FiAlertCircle, FiClock,
    FiRefreshCw, FiZap, FiChevronLeft, FiChevronRight,
} from "react-icons/fi";
import {
    getAccountSettings, saveAccountSettings,
    getNotificationSettings, saveNotificationSettings,
    getStoredSenderIds, saveStoredSenderIds,
    getPreferredSender, savePreferredSender,
    type AccountSettings, type NotificationSettings, type StoredSenderId
} from "../utils/settingsStorage";
import { SenderRequestModal } from "../components/SenderRequestModal";
import { useGhlLocation } from "../hooks/useGhlLocation";
import { fetchSenderRequests, fetchAccountSenderConfig, type SenderRequest, type AccountSenderConfig } from "../api/senderRequests";
import { fetchAccountProfile } from "../api/account";

// ─── Types ──────────────────────────────────────────────────────────────────
type SettingsTab = "account" | "senderIds" | "notifications" | "credits";

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



// ─── Section: Account ───────────────────────────────────────────────────────
const AccountSection: React.FC = () => {
    const [form] = useState<AccountSettings>(getAccountSettings);
    const ghlLocationIdFromHook = useGhlLocation();

    const [fetchedName, setFetchedName] = useState<string | null>(null);
    const [isFetchingLocation, setIsFetchingLocation] = useState(false);
    
    // Manage input location ID state
    const [inputLocationId, setInputLocationId] = useState<string>(() => {
        return ghlLocationIdFromHook || getAccountSettings().ghlLocationId || "";
    });

    // Update if hook updates (e.g. from URL)
    useEffect(() => {
        if (ghlLocationIdFromHook && ghlLocationIdFromHook !== inputLocationId) {
            setInputLocationId(ghlLocationIdFromHook);
        }
    }, [ghlLocationIdFromHook]);

    // Handle debounced API fetch for location ID changes
    useEffect(() => {
        let mounted = true;
        const timer = setTimeout(async () => {
             if (inputLocationId.trim() === "") return;

             setIsFetchingLocation(true);
             const currentSettings = getAccountSettings();
             if (currentSettings.ghlLocationId !== inputLocationId) {
                 saveAccountSettings({ ...currentSettings, ghlLocationId: inputLocationId });
                 window.dispatchEvent(new Event("ghl-location-changed"));
             }

             const profile = await fetchAccountProfile();
             if (mounted) {
                 setIsFetchingLocation(false);
                 if (profile && profile.location_name && profile.location_name !== "Unknown") {
                     setFetchedName(profile.location_name);
                     const fresh = getAccountSettings();
                     if (fresh.displayName !== profile.location_name) {
                         saveAccountSettings({ ...fresh, displayName: profile.location_name });
                         window.dispatchEvent(new Event("account-settings-updated"));
                     }
                 } else {
                     setFetchedName("Location Not Found");
                 }
             }
        }, 800);
        return () => { mounted = false; clearTimeout(timer); };
    }, [inputLocationId]);

    // Derived values
    const subaccountName = fetchedName && fetchedName !== "Location Not Found" 
        ? fetchedName 
        : (fetchedName === "Location Not Found" ? "Not Found" : (form.displayName || "N/A"));
    const subaccountEmail = form.email || "N/A";
    const statusCfg = STATUS_CONFIG[form.accountStatus];

    return (
        <div className="space-y-5">
            <SectionHeader title="Account Details" subtitle="View your workspace and GoHighLevel connection information." />

            {/* Status Banner */}
            <div className={`flex items-center gap-2.5 px-4 py-3 rounded-xl border ${statusCfg.bg} border-transparent`}>
                <span className={statusCfg.color}>{statusCfg.icon}</span>
                <span className={`text-[13px] font-semibold ${statusCfg.color}`}>
                    Workspace Status: {statusCfg.label}
                </span>
            </div>

            <Card>
                <div className="flex items-center gap-4 mb-6">
                    <div className="w-12 h-12 rounded-2xl bg-[#2b83fa]/10 flex items-center justify-center text-[#2b83fa]">
                        <FiUser className="w-6 h-6" />
                    </div>
                    <div>
                        <h3 className="text-[15px] font-bold text-[#111111] dark:text-[#ececf1]">
                            {fetchedName === 'Location Not Found' ? <span className="text-red-500">Not Found</span> : subaccountName}
                        </h3>
                        <p className="text-[12px] text-[#9aa0a6]">{subaccountEmail}</p>
                    </div>
                </div>

                <div className="space-y-4 pt-4 border-t border-[#f0f0f0] dark:border-[#ffffff05]">
                    <div>
                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5 flex items-center justify-between">
                            <span>Location Name</span>
                            {isFetchingLocation && <span className="text-amber-500 normal-case tracking-normal">Fetching...</span>}
                        </label>
                        <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold">
                            {fetchedName === 'Location Not Found' ? <span className="text-red-500">Not Found</span> : subaccountName}
                        </div>
                    </div>
                    <div>
                        <label className="flex text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5 items-center justify-between gap-2">
                            <span>GHL Location ID</span>
                            {window.self === window.top && (
                                <button
                                    onClick={() => window.location.href = 'https://marketplace.gohighlevel.com/oauth/chooselocation?appId=65f8a0c2837bc281e59eef7b'}
                                    className="text-[10px] font-bold bg-[#2b83fa]/10 text-[#2b83fa] hover:bg-[#2b83fa]/20 px-2 py-1 rounded-md transition-colors"
                                >
                                    Connect GHL
                                </button>
                            )}
                        </label>
                        <div className="relative">
                            <input
                                type="text"
                                value={inputLocationId}
                                onChange={(e) => setInputLocationId(e.target.value)}
                                placeholder="Paste your Location ID..."
                                className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-mono focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/50 transition-all pr-10"
                            />
                            {isFetchingLocation && (
                                <div className="absolute right-3 top-1/2 -translate-y-1/2">
                                    <FiRefreshCw className="w-4 h-4 text-[#2b83fa] animate-spin" />
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </Card>

            <div className="p-4 rounded-xl bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/20">
                <p className="text-[12px] text-blue-700 dark:text-blue-300 leading-relaxed">
                    <strong>Note:</strong> Account information is automatically synced from your GoHighLevel workspace. Type your Location ID above to fetch your details automatically.
                </p>
            </div>
        </div>
    );
};

// ─── Section: Sender IDs ────────────────────────────────────────────────────
const SenderIdsSection: React.FC<{ autoOpenAddModal?: boolean }> = ({ autoOpenAddModal }) => {
    const [senderRequests, setSenderRequests] = useState<SenderRequest[]>([]);
    const [config, setConfig] = useState<AccountSenderConfig | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [isAdding, setIsAdding] = useState(false);
    const [preferredSender, setPreferredSender] = useState<string | null>(getPreferredSender());

    // Auto-open modal when triggered from Composer
    useEffect(() => {
        if (autoOpenAddModal) {
            setIsAdding(true);
        }
    }, [autoOpenAddModal]);

    // Fetch data from API simultaneously to avoid cascading load flashes
    useEffect(() => {
        let cancelled = false;

        setIsLoading(true);
        Promise.all([
            fetchSenderRequests().catch(err => {
                console.error("Failed to fetch sender requests:", err);
                return [];
            }),
            fetchAccountSenderConfig().catch(err => {
                console.error("Failed to fetch account sender config:", err);
                return null;
            })
        ]).then(([requests, cfg]) => {
            if (cancelled) return;
            setSenderRequests(requests);
            if (cfg) setConfig(cfg);
            setIsLoading(false);

            // Sync localStorage with API truth — remove stale entries
            // that no longer exist in the backend (e.g., after a DB clear)
            const apiIds = new Set<string>();
            if (cfg?.approved_sender_id) apiIds.add(cfg.approved_sender_id);
            for (const req of requests) apiIds.add(req.requested_id);

            const stored = getStoredSenderIds();
            if (stored.length > 0) {
                const synced = stored.filter(s => apiIds.has(s.id));
                if (synced.length !== stored.length) {
                    saveStoredSenderIds(synced);
                }
            }
        });

        return () => { cancelled = true; };
    }, []);

    const systemDefault = config?.system_default_sender || "NOLASMSPro";
    const freeUsageCount = config?.free_usage_count || 0;
    const freeLimit = 10;

    // Build display list: system default + API-fetched requests
    const displayItems: { id: string; name: string; description: string; status: "approved" | "pending" | "rejected"; color: string; isSystem: boolean }[] = [
        { id: "system-default", name: systemDefault, description: "System Default (Free Tier)", status: "approved", color: "bg-blue-500", isSystem: true },
    ];

    // Add approved sender from config (if different from system default)
    if (config?.approved_sender_id && config.approved_sender_id !== systemDefault) {
        displayItems.push({
            id: "approved-custom",
            name: config.approved_sender_id,
            description: "Your Approved Sender",
            status: "approved",
            color: "bg-emerald-500",
            isSystem: false,
        });
    }

    // Add pending/rejected requests
    for (const req of senderRequests) {
        if (req.status === "approved" && config?.approved_sender_id === req.requested_id) continue;
        displayItems.push({
            id: req.id,
            name: req.requested_id,
            description: req.purpose || "Sender ID Request",
            status: req.status,
            color: req.status === "pending" ? "bg-amber-500" : req.status === "rejected" ? "bg-red-500" : "bg-emerald-500",
            isSystem: false,
        });
    }

    const handleSuccess = (newSender?: StoredSenderId) => {
        if (newSender) {
            setSenderRequests(prev => [
                {
                    id: `temp_${Date.now()}`,
                    location_id: "",
                    requested_id: newSender.id,
                    purpose: newSender.description,
                    status: "pending",
                    created_at: new Date().toISOString()
                },
                ...prev
            ]);
        }

        // Fetch from server after index delay catch-up
        setTimeout(() => {
            fetchSenderRequests().then(setSenderRequests);
        }, 1500);
    };

    return (
        <div className="space-y-5">
            <SectionHeader title="Sender IDs" subtitle="Manage and request sender IDs for your account. Only approved IDs can be used for sending." />

            {/* Sender IDs List */}
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

                {isLoading ? (
                    <div className="space-y-2">
                        {[1, 2].map(i => (
                            <div key={i} className="flex items-center gap-3 p-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse">
                                <div className="w-9 h-9 rounded-xl bg-gray-200 dark:bg-[#2a2b32]" />
                                <div className="flex-1 space-y-1.5">
                                    <div className="h-3.5 bg-gray-200 dark:bg-[#2a2b32] rounded w-24" />
                                    <div className="h-2.5 bg-gray-100 dark:bg-[#1e1f22] rounded w-40" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="space-y-2">
                        {displayItems.map((sid, i) => {
                            const statusCfg = STATUS_CONFIG[sid.status];
                            const icon = SENDER_ICONS[i % SENDER_ICONS.length];
                            
                            const currentDefaultName = preferredSender || config?.approved_sender_id || systemDefault;
                            const isDefault = sid.name === currentDefaultName && sid.status === "approved";

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
                                            {isDefault && (
                                                <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-blue-50 dark:bg-blue-900/20 text-blue-500`}>
                                                    Default Sender
                                                </span>
                                            )}
                                            {sid.isSystem && (
                                                <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider ${
                                                    freeUsageCount >= freeLimit 
                                                    ? "bg-red-50 dark:bg-red-900/20 text-red-500" 
                                                    : "bg-gray-200 dark:bg-gray-800 text-gray-500"
                                                }`}>
                                                    System • {freeUsageCount}/{freeLimit} Free
                                                </span>
                                            )}
                                        </div>
                                        <span className="text-[11px] text-[#9aa0a6]">{sid.description}</span>
                                    </div>
                                    
                                    {/* Action Button */}
                                    {sid.status === "approved" && !isDefault && (
                                        <button 
                                            onClick={() => {
                                                savePreferredSender(sid.name);
                                                setPreferredSender(sid.name);
                                            }}
                                            className="opacity-0 group-hover:opacity-100 transition-opacity px-3 py-1.5 text-[11px] font-bold text-[#2b83fa] bg-[#2b83fa]/10 hover:bg-[#2b83fa]/20 rounded-lg whitespace-nowrap"
                                        >
                                            Set as Default
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
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

/** Format a relative date string from an ISO timestamp. */
function formatTxDate(iso: string): string {
    try {
        const d = new Date(iso);
        const now = new Date();
        const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const yesterdayStart = new Date(todayStart.getTime() - 86400000);
        const time = d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
        if (d >= todayStart) return `Today, ${time}`;
        if (d >= yesterdayStart) return `Yesterday, ${time}`;
        return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) + ', ' + time;
    } catch {
        return iso;
    }
}

/** Derive stats from a transaction array. */
function deriveStats(txs: CreditTransaction[]) {
    const now = new Date();
    const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1).getTime();

    let sentToday = 0;
    let creditsUsedToday = 0;
    let creditsUsedMonth = 0;

    for (const tx of txs) {
        if (tx.type !== 'deduction') continue;
        const t = new Date(tx.created_at).getTime();
        const abs = Math.abs(tx.amount);
        if (t >= todayStart) { sentToday += 1; creditsUsedToday += abs; }
        if (t >= monthStart) creditsUsedMonth += abs;
    }

    return { sentToday, creditsUsedToday, creditsUsedMonth };
}

const CreditsSection: React.FC = () => {
    const ghlLocationIdFromHook = useGhlLocation();
    const [balance, setBalance] = useState<number | null>(null);
    const [balanceLoading, setBalanceLoading] = useState(true);
    const [transactions, setTransactions] = useState<CreditTransaction[]>([]);
    const [txLoading, setTxLoading] = useState(true);
    const [currentPage, setCurrentPage] = useState(1);
    const itemsPerPage = 5;

    const [topUpAmount, setTopUpAmount] = useState(500);
    const [packages, setPackages] = useState<CreditPackage[]>([]);
    const [submitted, setSubmitted] = useState(false);
    const mountedRef = useRef(true);
    const popupRef = useRef<Window | null>(null);
    const popupPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // Final derived location ID
    const locationId = ghlLocationIdFromHook || getAccountSettings().ghlLocationId;

    const load = useCallback(async () => {
        setBalanceLoading(true);
        setTxLoading(true);
        const [bal, txs, pkgs] = await Promise.all([
            fetchCreditBalance(),
            fetchCreditTransactions('default', 50),
            fetchCreditPackages(),
        ]);
        if (!mountedRef.current) return;
        setBalance(bal);
        setTransactions(txs);
        setPackages(pkgs);

        // Default topUpAmount to the second package if available, else first
        if (pkgs.length > 0) {
            setTopUpAmount(pkgs[1]?.credits || pkgs[0].credits);
        }
        setBalanceLoading(false);
        setTxLoading(false);
    }, []);

    useEffect(() => {
        mountedRef.current = true;
        load();
        window.addEventListener('sms-sent', load);
        window.addEventListener('bulk-message-sent', load);

        // Listen for payment success signal from the checkout popup
        const handlePaymentMessage = (event: MessageEvent) => {
            // Accept messages from the checkout domain
            if (
                (event.origin === 'https://sms.nolawebsolutions.com' || event.origin === window.location.origin) &&
                event.data?.type === 'nola-payment-success'
            ) {
                if (popupPollRef.current) {
                    clearInterval(popupPollRef.current);
                    popupPollRef.current = null;
                }
                setSubmitted(false);
                if (popupRef.current) popupRef.current.close();
                load(); // Auto-refresh credits on success
            }
        };
        window.addEventListener('message', handlePaymentMessage);

        return () => {
            mountedRef.current = false;
            window.removeEventListener('sms-sent', load);
            window.removeEventListener('bulk-message-sent', load);
            window.removeEventListener('message', handlePaymentMessage);
            if (popupPollRef.current) clearInterval(popupPollRef.current);
            if (countdownRef.current) clearInterval(countdownRef.current);
        };
    }, [load]);


    const displayBalance = balance ?? 0;
    const usagePercent = Math.min(100, (displayBalance / 1000) * 100);
    const usageColor = displayBalance < 50 ? 'bg-red-500' : displayBalance < 200 ? 'bg-amber-400' : 'bg-emerald-500';
    const { sentToday, creditsUsedToday, creditsUsedMonth } = deriveStats(transactions);


    const handleTopUp = (e: React.FormEvent) => {
        e.preventDefault();

        const selectedPackage = packages.find(p => p.credits === topUpAmount);
        if (!selectedPackage) return;

        const baseUrl = selectedPackage.link;
        const separator = baseUrl.includes('?') ? '&' : '?';
        const checkoutUrl = locationId
            ? `${baseUrl}${separator}location_id=${encodeURIComponent(locationId)}`
            : baseUrl;

        const width = 600;
        const height = 850;
        const left = (window.screen.width / 2) - (width / 2);
        const top = (window.screen.height / 2) - (height / 2);

        const popup = window.open(
            checkoutUrl,
            "Checkout",
            `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes`
        );
        popupRef.current = popup;

        if (!popup) {
            alert("Checkout window blocked! Please allow popups for this site or use a different browser.");
            return;
        }

        setSubmitted(true);

        // Clear any existing poll
        if (popupPollRef.current) clearInterval(popupPollRef.current);

        // Start polling the window status
        popupPollRef.current = setInterval(() => {
            try {
                if (popup && popup.closed) {
                    if (popupPollRef.current) clearInterval(popupPollRef.current);
                    popupPollRef.current = null;
                    setSubmitted(false);
                    // Refresh balance in case they finished but message was missed
                    load();
                }
            } catch (e) {
                // Ignore cross-origin DOM exceptions
            }
        }, 500);
    };

    return (
        <div className="space-y-5">
            <SectionHeader title="Credits & Billing" subtitle="Monitor your SMS credit balance and request top-ups." />


            {/* Balance Card */}
            <div className="bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] rounded-2xl p-5 text-white shadow-lg shadow-blue-500/25">
                <div className="flex items-start justify-between mb-4">
                    <div>
                        <p className="text-[12px] font-semibold text-white/70 uppercase tracking-wider">Available Credits</p>
                        {balanceLoading ? (
                            <div className="mt-2 h-10 w-28 bg-white/20 rounded-lg animate-pulse" />
                        ) : (
                            <p className="text-[42px] font-black leading-none mt-1">{displayBalance.toLocaleString()}</p>
                        )}
                        <p className="text-[12px] text-white/60 mt-1">1 credit ≈ 1 SMS (160 chars)</p>
                    </div>
                    <div className="flex flex-col items-center gap-2">
                        <div className="w-12 h-12 rounded-xl bg-white/10 flex items-center justify-center">
                            <FiCreditCard className="w-6 h-6 text-white" />
                        </div>
                        <button
                            onClick={load}
                            className="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 flex items-center justify-center transition-all active:scale-90"
                            title="Refresh"
                        >
                            <FiRefreshCw className={`w-3.5 h-3.5 text-white ${balanceLoading ? 'animate-spin' : ''}`} />
                        </button>
                    </div>
                </div>
                <div className="mb-1">
                    <div className="h-1.5 bg-white/20 rounded-full overflow-hidden">
                        <div className={`h-full ${usageColor} rounded-full transition-all duration-700`} style={{ width: `${usagePercent}%` }} />
                    </div>
                </div>
                <div className="flex items-center justify-between text-[11px] text-white/60">
                    <span>0</span>
                    <span>1,000+</span>
                </div>
            </div>

            {/* Stats Row */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {[
                    { label: 'Sent Today', value: txLoading ? '—' : String(sentToday), icon: <FiSend className="w-4 h-4" />, color: 'text-[#2b83fa]', bg: 'bg-[#2b83fa]/10' },
                    { label: 'Credits Used Today', value: txLoading ? '—' : String(creditsUsedToday), icon: <FiZap className="w-4 h-4" />, color: 'text-amber-500', bg: 'bg-amber-500/10' },
                    { label: 'This Month', value: txLoading ? '—' : creditsUsedMonth.toLocaleString(), icon: <FiRefreshCw className="w-4 h-4" />, color: 'text-purple-500', bg: 'bg-purple-500/10' },
                ].map(stat => (
                    <Card key={stat.label} className="flex items-center gap-3 !py-4">
                        <div className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${stat.bg} ${stat.color}`}>{stat.icon}</div>
                        <div>
                            <p className={`text-[20px] font-black text-[#111111] dark:text-[#ececf1] ${txLoading ? 'animate-pulse' : ''}`}>{stat.value}</p>
                            <p className="text-[11px] text-[#9aa0a6]">{stat.label}</p>
                        </div>
                    </Card>
                ))}
            </div>

            {/* Top Up */}
            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider mb-4">Top Up Credits</h3>
                <form onSubmit={handleTopUp} className="space-y-4">
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        {packages.map(pkg => (
                            <button
                                key={pkg.credits}
                                type="button"
                                onClick={() => setTopUpAmount(pkg.credits)}
                                className={`flex flex-col items-center py-3 rounded-xl border-2 transition-all ${topUpAmount === pkg.credits
                                    ? 'border-[#2b83fa] bg-[#2b83fa]/5 dark:bg-[#2b83fa]/10'
                                    : 'border-[#e0e0e0] dark:border-[#2a2b32] hover:border-[#2b83fa]/40'
                                    }`}
                            >
                                <span className={`text-[16px] font-black ${topUpAmount === pkg.credits ? 'text-[#2b83fa]' : 'text-[#111111] dark:text-[#ececf1]'}`}>{pkg.credits.toLocaleString()}</span>
                                <span className="text-[11px] text-[#9aa0a6]">credits</span>
                                <span className={`text-[12px] font-bold mt-1 ${topUpAmount === pkg.credits ? 'text-[#2b83fa]' : 'text-[#6e6e73] dark:text-[#94959b]'}`}>₱{pkg.price}</span>
                            </button>
                        ))}
                    </div>
                    {submitted ? (
                        <div className="flex flex-col items-center justify-center gap-2 w-full">
                            <div className="w-full flex items-center justify-center gap-2 py-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl text-emerald-600 dark:text-emerald-400 font-semibold text-[13px]">
                                <FiCheck className="w-4 h-4" /> Checkout window opened
                            </div>
                            <button
                                type="button"
                                onClick={() => {
                                    setSubmitted(false);
                                    if (popupPollRef.current) clearInterval(popupPollRef.current);
                                }}
                                className="text-[12px] text-[#9aa0a6] hover:text-[#111111] dark:hover:text-[#ececf1] underline decoration-dashed hover:decoration-solid transition-all"
                            >
                                Window didn't open or you closed it? Click here to refresh.
                            </button>
                        </div>
                    ) : (
                        <button type="submit" className="w-full flex items-center justify-center gap-2 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-semibold text-[14px] transition-all shadow-md shadow-blue-500/20">
                            <FiZap className="w-4 h-4" /> Buy {topUpAmount.toLocaleString()} Credits
                        </button>
                    )}
                </form>
            </Card>

            {/* Transaction Ledger */}
            <Card>
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider">Recent Transactions</h3>
                    {!txLoading && transactions.length > 0 && (
                        <span className="text-[11px] text-[#9aa0a6]">{transactions.length} record{transactions.length !== 1 ? 's' : ''}</span>
                    )}
                </div>

                {txLoading ? (
                    /* Skeleton rows */
                    <div className="space-y-3">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <div key={i} className="flex items-center gap-3 py-1 animate-pulse">
                                <div className="w-7 h-7 rounded-full bg-gray-200 dark:bg-[#2a2b32] flex-shrink-0" />
                                <div className="flex-1 space-y-1.5">
                                    <div className="h-3 bg-gray-200 dark:bg-[#2a2b32] rounded w-3/4" />
                                    <div className="h-2.5 bg-gray-100 dark:bg-[#1e1f22] rounded w-1/3" />
                                </div>
                                <div className="h-3 bg-gray-200 dark:bg-[#2a2b32] rounded w-16 flex-shrink-0" />
                            </div>
                        ))}
                    </div>
                ) : transactions.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 gap-3 text-center">
                        <div className="w-12 h-12 rounded-xl bg-[#2b83fa]/10 flex items-center justify-center">
                            <FiCreditCard className="w-6 h-6 text-[#2b83fa]/60" />
                        </div>
                        <p className="text-[14px] font-semibold text-[#37352f] dark:text-[#ececf1]">No transactions yet</p>
                        <p className="text-[12px] text-[#9aa0a6] max-w-xs">Send an SMS or top up your balance to see activity here.</p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-0 min-h-[250px]">
                            {transactions.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage).map((tx) => {
                                const isCredit = tx.type === 'top_up' || tx.type === 'refund' || tx.type === 'manual_adjustment';
                                const sign = isCredit ? '+' : '−';
                                const absAmount = Math.abs(tx.amount);
                                return (
                                    <div key={tx.transaction_id} className="flex items-center gap-3 py-2.5 border-b border-[#f0f0f0] dark:border-[#2a2b32] last:border-0 hover:bg-gray-50 dark:hover:bg-white/5 px-2 rounded-xl transition-colors">
                                        <div className={`w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-[11px] font-bold ${isCredit ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400' : 'bg-red-50 dark:bg-red-900/20 text-red-500'}`}>
                                            {sign}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[13px] font-medium text-[#111111] dark:text-[#ececf1] truncate">{tx.description}</p>
                                            <p className="text-[11px] text-[#9aa0a6]">{formatTxDate(tx.created_at)}</p>
                                        </div>
                                        <div className="flex flex-col items-end flex-shrink-0">
                                            <span className={`text-[13px] font-bold ${isCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'}`}>
                                                {sign}{absAmount.toLocaleString()} cr
                                            </span>
                                            <span className="text-[10px] text-[#9aa0a6]">bal: {tx.balance_after.toLocaleString()}</span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        
                        {/* Pagination Controls */}
                        {transactions.length > itemsPerPage && (
                            <div className="flex items-center justify-between mt-6 pt-4 border-t border-[#f0f0f0] dark:border-[#2a2b32]">
                                <span className="text-[12px] font-medium text-[#9aa0a6]">
                                    Showing {(currentPage - 1) * itemsPerPage + 1} to {Math.min(currentPage * itemsPerPage, transactions.length)} of {transactions.length}
                                </span>
                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                                        disabled={currentPage === 1}
                                        className="p-1.5 rounded-lg border border-[#e5e5e5] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#94959b] hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                    >
                                        <FiChevronLeft className="w-4 h-4" />
                                    </button>
                                    <button
                                        onClick={() => setCurrentPage(p => Math.min(Math.ceil(transactions.length / itemsPerPage), p + 1))}
                                        disabled={currentPage === Math.ceil(transactions.length / itemsPerPage)}
                                        className="p-1.5 rounded-lg border border-[#e5e5e5] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#94959b] hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                    >
                                        <FiChevronRight className="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        )}
                    </>
                )}
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
