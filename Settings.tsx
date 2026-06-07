import { useState, useCallback, useEffect, useRef } from "react";
import { fetchCreditStatus, fetchCreditTransactions, fetchCreditPackages } from "../api/credits";
import type { CreditStatus, CreditTransaction, CreditPackage } from "../api/credits";
import {
    FiUser, FiSend, FiBell, FiCreditCard,
    FiSave, FiPlus, FiCheck,
    FiGlobe, FiMapPin, FiBriefcase, FiCheckCircle, FiAlertCircle, FiClock,
    FiRefreshCw, FiZap, FiChevronLeft, FiChevronRight, FiGift, FiChevronDown, FiDownload
} from "react-icons/fi";
import { generateMonthlyReport } from "../utils/pdfGenerator";
import {
    getAccountSettings, saveAccountSettings,
    getNotificationSettings, saveNotificationSettings,
    getStoredSenderIds, saveStoredSenderIds,
    getPreferredSender, savePreferredSender,
    type AccountSettings, type NotificationSettings, type StoredSenderId
} from "../utils/settingsStorage";
import { SenderRequestModal } from "../components/SenderRequestModal";
import { useGhlLocation, useGhlCompany, useIsInGhlIframe } from "../hooks/useGhlLocation";
import { fetchSenderRequests, fetchAccountSenderConfig, type SenderRequest, type AccountSenderConfig } from "../api/senderRequests";
import { fetchAccountProfile, fetchAgencyProfile } from "../api/account";
import { useUserProfileContext } from "../context/UserProfileContext";
import { useAuth } from "../context/AuthContext";
import { safeStorage } from "../utils/safeStorage";
import { getSession, SESSION_KEYS } from "../services/authService";



// ΓöÇΓöÇΓöÇ Types ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
type SettingsTab = "account" | "senderIds" | "notifications" | "credits";

interface SettingsProps {
    darkMode: boolean;
    toggleDarkMode: () => void;
    initialTab?: SettingsTab;
    autoOpenAddModal?: boolean;
}

// ΓöÇΓöÇΓöÇ Constants ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
const TABS: { id: SettingsTab; label: string; icon: React.ReactNode; description: string }[] = [
    { id: "account",       label: "Account",       icon: <FiUser />,         description: "Profile & organization info" },
    { id: "senderIds",    label: "Sender IDs",    icon: <FiSend />,         description: "Manage approved sender IDs" },
    { id: "notifications",label: "Notifications", icon: <FiBell />,         description: "Alert & report preferences" },
    { id: "credits",      label: "Credits",       icon: <FiCreditCard />,   description: "Balance & billing" },
];



const SENDER_ICONS = [<FiGlobe />, <FiMapPin />, <FiBriefcase />, <FiCheckCircle />];

const STATUS_CONFIG = {
    approved: { label: "Approved", color: "text-emerald-600 dark:text-emerald-400", bg: "bg-emerald-50 dark:bg-emerald-900/20", icon: <FiCheck className="w-3 h-3" /> },
    pending: { label: "Pending", color: "text-amber-600  dark:text-amber-400", bg: "bg-amber-50  dark:bg-amber-900/20", icon: <FiClock className="w-3 h-3" /> },
    rejected: { label: "Rejected", color: "text-red-600    dark:text-red-400", bg: "bg-red-50    dark:bg-red-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
};

// ΓöÇΓöÇΓöÇ Sub-components ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
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



function readCachedProfile(): Record<string, unknown> {
    try {
        const raw =
            safeStorage.getItem(SESSION_KEYS.user) ||
            safeStorage.getItem('nola_auth_user') ||
            safeStorage.getItem('nola_user') ||
            '{}';
        return JSON.parse(raw);
    } catch {
        return {};
    }
}

// ΓöÇΓöÇΓöÇ Section: Account ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
const AccountSection: React.FC = () => {
    const [form] = useState<AccountSettings>(getAccountSettings);
    const { isAgency } = useAuth();
    const ghlLocationIdFromHook = useGhlLocation();
    const ghlCompanyIdFromHook = useGhlCompany();
    const isInGhlIframe = useIsInGhlIframe();
    const liveProfile = useUserProfileContext();
    const cachedProfile = readCachedProfile();
    const userProfile = liveProfile || cachedProfile;

    const initialWorkspaceName = isAgency
        ? ((userProfile.company_name as string | undefined) || null)
        : ((userProfile.location_name as string | undefined) || null);

    const [fetchedName, setFetchedName] = useState<string | null>(initialWorkspaceName);
    const [isFetchingLocation, setIsFetchingLocation] = useState(false);

    useEffect(() => {
        if (isAgency) {
            if (userProfile.company_name) setFetchedName(String(userProfile.company_name));
            return;
        }
        if (userProfile.location_name) setFetchedName(String(userProfile.location_name));
    }, [isAgency, userProfile.company_name, userProfile.location_name]);

    const [inputLocationId, setInputLocationId] = useState<string>(() => {
        return ghlLocationIdFromHook || String(userProfile.location_id || getAccountSettings().ghlLocationId || "");
    });

    useEffect(() => {
        if (!isAgency && userProfile.location_id && !inputLocationId) {
            setInputLocationId(String(userProfile.location_id));
        }
    }, [isAgency, userProfile.location_id, inputLocationId]);

    useEffect(() => {
        if (!isAgency && ghlLocationIdFromHook && ghlLocationIdFromHook !== inputLocationId) {
            setInputLocationId(ghlLocationIdFromHook);
            fetchAndSetLocation(ghlLocationIdFromHook);
        }
    }, [ghlLocationIdFromHook, inputLocationId, isAgency]);

    const fetchAndSetAgencyProfile = async () => {
        setIsFetchingLocation(true);
        const profile = await fetchAgencyProfile();
        setIsFetchingLocation(false);
        if (!profile) return;

        if (profile.company_name && profile.company_name !== "Unknown") {
            setFetchedName(profile.company_name);
        }

        try {
            const cached = readCachedProfile();
            const merged = {
                ...cached,
                ...profile,
                company_name: profile.company_name ?? cached.company_name,
                company_id: profile.company_id ?? cached.company_id,
                name: profile.name ?? profile.full_name ?? cached.name,
                email: profile.email ?? cached.email,
                phone: profile.phone ?? cached.phone,
            };
            const serialized = JSON.stringify(merged);
            safeStorage.setItem(SESSION_KEYS.user, serialized);
            safeStorage.setItem('nola_user', serialized);
            safeStorage.setItem('nola_auth_user', serialized);
        } catch {}
    };

    const fetchAndSetLocation = async (locId: string) => {
         if (!locId || locId.trim() === "") return;

         setIsFetchingLocation(true);
         const currentSettings = getAccountSettings();
         if (currentSettings.ghlLocationId !== locId) {
             saveAccountSettings({ ...currentSettings, ghlLocationId: locId });
             window.dispatchEvent(
                 new CustomEvent('ghl-location-set', { detail: { locationId: locId } })
             );
         }

         const profile = await fetchAccountProfile(locId);
         setIsFetchingLocation(false);
         if (profile && profile.location_name && profile.location_name !== "Unknown") {
             setFetchedName(profile.location_name);
             try {
                 const cached = readCachedProfile();
                 cached.location_name = profile.location_name;
                 const serialized = JSON.stringify(cached);
                 safeStorage.setItem('nola_user', serialized);
                 safeStorage.setItem('nola_auth_user', serialized);
             } catch {}
             const fresh = getAccountSettings();
             if (fresh.displayName !== profile.location_name) {
                 saveAccountSettings({ ...fresh, displayName: profile.location_name });
                 window.dispatchEvent(new Event("account-settings-updated"));
             }
         }
    };

    useEffect(() => {
        if (isAgency) {
            fetchAndSetAgencyProfile();
            return;
        }
        if (inputLocationId) {
            fetchAndSetLocation(inputLocationId);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAgency]);

    const handleSaveLocation = () => {
        fetchAndSetLocation(inputLocationId);
    };

    const handleResetToDefault = () => {
        // Try to get the dynamic location from the URL
        const keys = ['location_id', 'locationId', 'location', 'id'];
        const search = window.location.search;
        const hash = window.location.hash;
        
        let dynamicId = null;
        for (const k of keys) {
            const val = new URLSearchParams(search).get(k);
            if (val && val.length > 4) { dynamicId = val; break; }
        }
        if (!dynamicId && hash.includes('?')) {
            const hashQuery = hash.split('?')[1];
            for (const k of keys) {
                const val = new URLSearchParams('?' + hashQuery).get(k);
                if (val && val.length > 4) { dynamicId = val; break; }
            }
        }
        
        if (dynamicId) {
            setInputLocationId(dynamicId);
            fetchAndSetLocation(dynamicId);
        } else if (ghlLocationIdFromHook) {
            // Fallback to whatever the hook thinks is right
            setInputLocationId(ghlLocationIdFromHook);
            fetchAndSetLocation(ghlLocationIdFromHook);
        }
    };

    // Derived values
    const statusCfg = STATUS_CONFIG[form.accountStatus];
    // fullName: use `name` field; fall back to legacy firstName+lastName for old sessions
    const fullName = userProfile.name
        || userProfile.full_name
        || (`${userProfile.firstName ?? ''} ${userProfile.lastName ?? ''}`.trim())
        || 'N/A';
    const resolvedCompanyId =
        ghlCompanyIdFromHook ||
        userProfile.company_id ||
        getSession()?.companyId ||
        safeStorage.getItem(SESSION_KEYS.companyId) ||
        '';
    const resolvedLocationId = ghlLocationIdFromHook || userProfile.location_id || inputLocationId || '';
    const workspaceTitle = isAgency
        ? (fetchedName || userProfile.company_name || form.displayName || "Not Found")
        : (fetchedName && fetchedName !== "Location Not Found"
            ? fetchedName
            : (userProfile.location_name || form.displayName || "Not Found"));

    return (
        <div className="space-y-5">
            <SectionHeader
                title="Account Details"
                subtitle={isAgency
                    ? "View your agency profile and GoHighLevel company information."
                    : "View your profile and GoHighLevel workspace information."}
            />

            {/* Status Banner */}
            <div className={`flex items-center gap-2.5 px-4 py-3 rounded-xl border ${statusCfg.bg} border-transparent`}>
                <span className={statusCfg.color}>{statusCfg.icon}</span>
                <span className={`text-[13px] font-semibold ${statusCfg.color}`}>
                    Workspace Status: {statusCfg.label}
                </span>
            </div>

            {/* Personal Details */}
            <Card>
                <div className="flex items-center gap-4 mb-5">
                    <div className="w-12 h-12 rounded-2xl bg-[#2b83fa]/10 flex items-center justify-center text-[#2b83fa]">
                        <FiUser className="w-6 h-6" />
                    </div>
                    <div>
                        <h3 className="text-[15px] font-bold text-[#111111] dark:text-[#ececf1]">{fullName}</h3>
                        <p className="text-[12px] text-[#9aa0a6]">{userProfile.email || 'N/A'}</p>
                    </div>
                </div>

                <div className="space-y-3.5 pt-4 border-t border-[#f0f0f0] dark:border-[#ffffff05]">
                    <div>
                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Full Name</label>
                        <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold">
                            {fullName}
                        </div>
                    </div>
                    <div>
                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Email Address</label>
                        <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold">
                            {userProfile.email || 'N/A'}
                        </div>
                    </div>
                    <div>
                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Phone Number</label>
                        <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold">
                            {userProfile.phone || 'N/A'}
                        </div>
                    </div>
                </div>
            </Card>

            {/* Workspace / GHL Info */}
            <Card>
                <div className="flex items-center gap-4 mb-5">
                    <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500">
                        {isAgency ? <FiBriefcase className="w-6 h-6" /> : <FiMapPin className="w-6 h-6" />}
                    </div>
                    <div>
                        <h3 className="text-[15px] font-bold text-[#111111] dark:text-[#ececf1]">
                            {workspaceTitle === 'Location Not Found'
                                ? <span className="text-red-500">Not Found</span>
                                : workspaceTitle}
                        </h3>
                        <p className="text-[12px] text-[#9aa0a6]">
                            {isAgency ? 'GHL Agency Company' : (userProfile.company_name ? `Agency: ${userProfile.company_name}` : 'GHL Workspace')}
                        </p>
                    </div>
                </div>

                <div className="space-y-4 pt-4 border-t border-[#f0f0f0] dark:border-[#ffffff05]">
                    <div>
                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5 flex items-center justify-between">
                            <span>{isAgency ? 'Company Name' : 'Location Name'}</span>
                            {isFetchingLocation && <span className="text-amber-500 normal-case tracking-normal">Fetching...</span>}
                        </label>
                        <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold">
                            {workspaceTitle === 'Location Not Found'
                                ? <span className="text-red-500">Not Found</span>
                                : workspaceTitle}
                        </div>
                    </div>
                    {!isAgency && userProfile.company_name && (
                        <div>
                            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Agency / Company</label>
                            <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold">
                                {userProfile.company_name}
                            </div>
                        </div>
                    )}
                    <div>
                        <label className="flex text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5 items-center justify-between gap-2">
                            <span>{isAgency ? 'GHL Company ID' : 'GHL Location ID'}</span>
                            {!isInGhlIframe && !isAgency && (
                                <button
                                    onClick={() => window.location.href = 'https://marketplace.gohighlevel.com/oauth/chooselocation?appId=65f8a0c2837bc281e59eef7b'}
                                    className="text-[10px] font-bold bg-[#2b83fa]/10 text-[#2b83fa] hover:bg-[#2b83fa]/20 px-2 py-1 rounded-md transition-colors"
                                >
                                    Connect GHL
                                </button>
                            )}
                        </label>
                        {!isInGhlIframe && !isAgency ? (
                            <div className="flex gap-2 relative">
                                <div className="relative flex-1">
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
                                <button
                                    onClick={handleSaveLocation}
                                    disabled={isFetchingLocation || inputLocationId === ghlLocationIdFromHook}
                                    className="px-4 py-2.5 rounded-xl bg-[#2b83fa] text-white text-[13px] font-bold shadow-md hover:bg-[#1a65d1] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                >
                                    Save
                                </button>
                                <button
                                    onClick={handleResetToDefault}
                                    disabled={isFetchingLocation || inputLocationId === ghlLocationIdFromHook}
                                    className="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 text-[13px] font-bold shadow-sm hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                >
                                    Reset To Default
                                </button>
                            </div>
                        ) : (
                            <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-mono flex items-center justify-between">
                                <span>{isAgency ? (resolvedCompanyId || "Not Found") : (resolvedLocationId || "Not Found")}</span>
                                {isFetchingLocation && <FiRefreshCw className="w-4 h-4 text-[#2b83fa] animate-spin" />}
                            </div>
                        )}
                    </div>
                </div>
            </Card>

            {!isInGhlIframe && !isAgency && (
                <div className="p-4 rounded-xl bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/20">
                    <p className="text-[12px] text-blue-700 dark:text-blue-300 leading-relaxed">
                        <strong>Note:</strong> Account information is automatically synced from your GoHighLevel workspace. Type your Location ID above to fetch your details automatically.
                    </p>
                </div>
            )}
        </div>
    );
};



// ΓöÇΓöÇΓöÇ Section: Sender IDs ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
const SenderIdsSection: React.FC<{ autoOpenAddModal?: boolean }> = ({ autoOpenAddModal }) => {
    const locationId = useGhlLocation() || getAccountSettings().ghlLocationId || undefined;
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
            fetchSenderRequests(locationId).catch(err => {
                console.error("Failed to fetch sender requests:", err);
                return [];
            }),
            fetchAccountSenderConfig(locationId).catch(err => {
                console.error("Failed to fetch account sender config:", err);
                return null;
            })
        ]).then(([requests, cfg]) => {
            if (cancelled) return;
            setSenderRequests(requests);
            if (cfg) setConfig(cfg);
            setIsLoading(false);

            // Sync localStorage with API truth ΓÇö remove stale entries
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
    }, [locationId]);

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
            fetchSenderRequests(locationId).then(setSenderRequests);
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
                                                    System ΓÇó {freeUsageCount}/{freeLimit} Free
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




// ΓöÇΓöÇΓöÇ Section: Notifications ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
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

// ΓöÇΓöÇΓöÇ Section: Credits ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ

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

const AR_AMOUNTS = [100, 250, 500, 1000, 2000];
const AR_THRESHOLDS = [25, 50, 100, 200];
const API_BASE_URL = (import.meta as any).env?.VITE_API_BASE || 'https://smspro-api.nolacrm.io';

const CreditsSection: React.FC = () => {
    const ghlLocationIdFromHook = useGhlLocation();
    const [creditStatus, setCreditStatus] = useState<CreditStatus | null>(null);
    const [balanceLoading, setBalanceLoading] = useState(true);
    const [transactions, setTransactions] = useState<CreditTransaction[]>([]);
    const [txLoading, setTxLoading] = useState(true);
    const [txMonth, setTxMonth] = useState(() => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    });
    const [availableMonths, setAvailableMonths] = useState<{ val: string, label: string }[]>([]);
    const [currentPage, setCurrentPage] = useState(1);
    const itemsPerPage = 5;

    const [topUpAmount, setTopUpAmount] = useState(500);
    const [packages, setPackages] = useState<CreditPackage[]>([]);
    const [submitted, setSubmitted] = useState(false);
    const mountedRef = useRef(true);
    const popupRef = useRef<Window | null>(null);
    const popupPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // Auto-recharge state
    const [arEnabled, setArEnabled] = useState(false);
    const [arAmount, setArAmount] = useState(500);
    const [arThreshold, setArThreshold] = useState(50);
    const [arSaving, setArSaving] = useState(false);
    const [arSaved, setArSaved] = useState(false);

    // Request credits state
    const [reqModalOpen, setReqModalOpen] = useState(false);
    const [reqAmount, setReqAmount] = useState(100);
    const [reqNote, setReqNote] = useState('');
    const [reqLoading, setReqLoading] = useState(false);
    const [reqSuccess, setReqSuccess] = useState(false);

    // Final derived location ID
    const locationId = ghlLocationIdFromHook || getAccountSettings().ghlLocationId;

    const load = useCallback(async () => {
        setBalanceLoading(true);
        setTxLoading(true);
        const [status, txs, pkgs] = await Promise.all([
            fetchCreditStatus(locationId || undefined),
            fetchCreditTransactions('default', 500, locationId || undefined, txMonth === 'All' ? undefined : txMonth),
            fetchCreditPackages(),
        ]);
        if (!mountedRef.current) return;
        setCreditStatus(status);
        setTransactions(txs);
        setPackages(pkgs);

        // Default topUpAmount to the second package if available, else first
        if (pkgs.length > 0) {
            setTopUpAmount(pkgs[1]?.credits || pkgs[0].credits);
        }
        setBalanceLoading(false);
        setTxLoading(false);
    }, [locationId, txMonth]);

    useEffect(() => {
        const fetchAvailableMonths = async () => {
            const txs = await fetchCreditTransactions('default', 1000, locationId || undefined);
            const months = new Set<string>();
            // Use current month as a baseline
            months.add(new Date().toISOString().slice(0, 7));
            
            txs.forEach(tx => {
                const dt = tx.created_at || (tx as any).timestamp;
                if (dt) months.add(dt.slice(0, 7));
            });

            const sorted = Array.from(months)
                .sort((a, b) => b.localeCompare(a))
                .map(m => {
                    const [y, mm] = m.split('-');
                    const d = new Date(parseInt(y), parseInt(mm) - 1);
                    return {
                        val: m,
                        label: d.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
                    };
                });
            setAvailableMonths([{ val: 'All', label: 'All Transactions' }, ...sorted]);
        };
        fetchAvailableMonths();
    }, [locationId]);

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


    const displayBalance  = creditStatus?.credit_balance ?? 0;
    const trialUsed       = creditStatus?.free_usage_count ?? 0;
    const trialTotal      = creditStatus?.free_credits_total ?? 0;
    const isTrialActive   = trialTotal > 0 && trialUsed < trialTotal;
    const trialLeft       = trialTotal - trialUsed;
    const usagePercent    = Math.min(100, (displayBalance / 1000) * 100);
    const usageColor      = displayBalance < 50 ? 'bg-red-500' : displayBalance < 200 ? 'bg-amber-400' : 'bg-emerald-500';

    const sentToday = creditStatus?.stats?.sent_today ?? 0;
    const creditsUsedToday = creditStatus?.stats?.credits_used_today ?? 0;
    const creditsUsedMonth = creditStatus?.stats?.credits_used_month ?? 0;

    // ΓöÇΓöÇ Auto-recharge save ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
    const saveAutoRecharge = async () => {
        setArSaving(true);
        try {
            await fetch(`${API_BASE_URL}/api/billing/subaccount_wallet.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'set_auto_recharge', location_id: locationId, enabled: arEnabled, amount: arAmount, threshold: arThreshold }),
            });
            setArSaved(true); setTimeout(() => setArSaved(false), 2000);
        } catch { /* silently handled */ } finally { setArSaving(false); }
    };

    // ΓöÇΓöÇ Submit credit request ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
    const submitCreditRequest = async () => {
        if (reqAmount <= 0) return;
        setReqLoading(true);
        try {
            const res = await fetch(`${API_BASE_URL}/api/billing/subaccount_wallet.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'request_credits', location_id: locationId, amount: reqAmount, note: reqNote }),
            });
            const data = await res.json();
            if (data.success || res.ok) {
                setReqSuccess(true);
                setTimeout(() => { setReqSuccess(false); setReqModalOpen(false); setReqNote(''); }, 2500);
            }
        } catch { /* silently handled */ } finally { setReqLoading(false); }
    };


    const handleTopUp = (e: React.FormEvent) => {
        e.preventDefault();

        const selectedPackage = packages.find(p => p.credits === topUpAmount);
        if (!selectedPackage) return;

        const baseUrl = selectedPackage.link;
        const separator = baseUrl.includes('?') ? '&' : '?';

        // Resolve location_id: prefer GHL hook/settings, fall back to nola_user localStorage
        let resolvedLocationId = locationId;
        try {
            const storedUser = JSON.parse(localStorage.getItem('nola_user') || 'null');
            if (!resolvedLocationId && storedUser?.location_id) {
                resolvedLocationId = storedUser.location_id;
            }
        } catch { /* ignore */ }

        // Build base URL ΓÇö location_id goes into ?location_id= so the GHL funnel
        // script can read it and fill input[name="companyname"] (its tracking field)
        let checkoutUrl = resolvedLocationId
            ? `${baseUrl}${separator}location_id=${encodeURIComponent(resolvedLocationId)}`
            : baseUrl;

        // Append contact pre-fill params read by the GHL funnel's custom script
        try {
            const stored = localStorage.getItem('nola_user');
            if (stored) {
                const profile = JSON.parse(stored) as {
                    firstName?: string; lastName?: string;
                    email?: string; phone?: string;
                    location_name?: string;
                };
                const p = new URLSearchParams();
                const fullName = [profile.firstName, profile.lastName].filter(Boolean).join(' ');
                if (fullName) {
                    p.set('name', fullName);
                    p.set('full_name', fullName);
                }
                if (profile.firstName) p.set('first_name', profile.firstName);
                if (profile.lastName)  p.set('last_name',  profile.lastName);
                if (profile.email)     p.set('email',      profile.email);
                // Phone is already stored clean (spaces stripped at login/register)
                if (profile.phone) p.set('phone', profile.phone);

                const qs = p.toString();
                if (qs) checkoutUrl += (checkoutUrl.includes('?') ? '&' : '?') + qs;
            }
        } catch {
            // Non-fatal: checkout still opens without pre-fill
        }

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
            <SectionHeader title="Credits & Billing" subtitle="Monitor your SMS credit balance, configure auto-recharge, and request credits from your agency." />

            {/* ΓöÇΓöÇ Request Credits Modal ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ */}
            {reqModalOpen && (
                <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-[1000]" onClick={() => setReqModalOpen(false)}>
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl shadow-2xl p-6 w-full max-w-[400px] mx-4" onClick={e => e.stopPropagation()}>
                        <div className="flex items-center gap-3 mb-5">
                            <div className="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-500">
                                <FiGift className="w-5 h-5" />
                            </div>
                            <div>
                                <div className="text-[15px] font-bold text-[#111111] dark:text-white">Request Credits from Agency</div>
                                <div className="text-[12px] text-[#9aa0a6]">Your agency will review and approve your request</div>
                            </div>
                        </div>
                        {reqSuccess ? (
                            <div className="flex flex-col items-center py-6 gap-2">
                                <div className="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-500"><FiCheck className="w-6 h-6" /></div>
                                <div className="text-[14px] font-bold text-[#111111] dark:text-white">Request Sent!</div>
                                <div className="text-[12px] text-[#9aa0a6] text-center">Your agency will review and fulfill your request shortly.</div>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div>
                                    <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Credits Requested</label>
                                    <div className="grid grid-cols-4 gap-2 mb-2">
                                        {[50, 100, 250, 500].map(n => (
                                            <button key={n} type="button" onClick={() => setReqAmount(n)}
                                                className={`py-2 rounded-xl text-[12px] font-bold border-2 transition-all ${reqAmount === n ? 'border-purple-500 bg-purple-500/5 text-purple-600' : 'border-[#e0e0e0] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#9aa0a9] hover:border-purple-400'}`}>
                                                {n}
                                            </button>
                                        ))}
                                    </div>
                                    <input type="number" min={1} value={reqAmount} onChange={e => setReqAmount(Math.max(1, parseInt(e.target.value) || 1))}
                                        className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-purple-500/30"
                                        placeholder="Or type a custom amountΓÇª" />
                                </div>
                                <div>
                                    <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Note (optional)</label>
                                    <input type="text" value={reqNote} onChange={e => setReqNote(e.target.value)}
                                        placeholder="E.g. Need credits for weekend campaignΓÇª"
                                        className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-purple-500/30" />
                                </div>
                                <div className="flex gap-2.5 pt-1">
                                    <button onClick={() => setReqModalOpen(false)} disabled={reqLoading}
                                        className="flex-1 px-4 py-2.5 rounded-xl text-[13px] font-semibold bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#6b7280] border border-[#e0e0e0] dark:border-[#ffffff0a] hover:bg-black/5 transition-colors">
                                        Cancel
                                    </button>
                                    <button onClick={submitCreditRequest} disabled={reqLoading || reqAmount <= 0}
                                        className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-[13px] font-semibold bg-purple-600 text-white hover:bg-purple-700 transition-colors shadow-md shadow-purple-500/20 disabled:opacity-50">
                                        {reqLoading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiGift className="w-4 h-4" />}
                                        {reqLoading ? 'SendingΓÇª' : 'Send Request'}
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}


            {/* Balance Card */}
            <div className="rounded-2xl p-5 text-white shadow-lg transition-colors duration-500 bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] shadow-blue-500/25">
                <div className="flex items-start justify-between mb-4">
                    <div className="flex-1 min-w-0">
                        <p className="text-[12px] font-semibold text-white/70 uppercase tracking-wider mb-2">Available Credits</p>
                        {balanceLoading ? (
                            <div className="h-10 w-28 bg-white/20 rounded-lg animate-pulse" />
                        ) : (
                            <div className="flex items-center gap-3">
                                <p className="text-[42px] font-black leading-none">{displayBalance.toLocaleString()}</p>
                                {isTrialActive && (
                                    <span className="text-[11px] font-bold bg-[#ffffff30] text-white px-3 py-1 rounded-full shadow-sm whitespace-nowrap">
                                        {trialLeft} Free Trial
                                    </span>
                                )}
                            </div>
                        )}
                        <p className="text-[12px] text-white/60 mt-1">1 credit Γëê 1 SMS (160 chars)</p>
                    </div>
                    <div className="flex flex-col items-center gap-2 flex-shrink-0">
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

                {/* Trial progress bar */}
                {isTrialActive ? (
                    <>
                        <div className="mb-1">
                            <div className="h-1.5 bg-white/20 rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-white/70 rounded-full transition-all duration-700"
                                    style={{ width: `${Math.round((trialUsed / trialTotal) * 100)}%` }}
                                />
                            </div>
                        </div>
                        <div className="flex items-center justify-between text-[11px] text-white/60">
                            <span>{trialUsed} used</span>
                            <span>{trialTotal} total free</span>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="mb-1">
                            <div className="h-1.5 bg-white/20 rounded-full overflow-hidden">
                                <div className={`h-full ${usageColor} rounded-full transition-all duration-700`} style={{ width: `${usagePercent}%` }} />
                            </div>
                        </div>
                        <div className="flex items-center justify-between text-[11px] text-white/60">
                            <span>0</span>
                            <span>1,000+</span>
                        </div>
                    </>
                )}
            </div>

            {/* Auto-recharge config row */}
            <Card>
                <div className="flex flex-wrap items-center gap-3">
                    <button
                        onClick={() => setArEnabled(v => !v)}
                        className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0 focus:outline-none ${arEnabled ? 'bg-[#2b83fa]' : 'bg-gray-200 dark:bg-[#3a3b3f]'}`}
                    >
                        <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform ${arEnabled ? 'translate-x-[18px]' : 'translate-x-1'}`} />
                    </button>
                    <span className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a9] font-medium">Auto recharge with</span>
                    <div className="relative">
                        <select value={arAmount} onChange={e => setArAmount(Number(e.target.value))} disabled={!arEnabled}
                            className="pl-3 pr-7 py-1 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[12.5px] font-bold text-[#111111] dark:text-white appearance-none focus:outline-none disabled:opacity-50">
                            {AR_AMOUNTS.map(v => <option key={v} value={v}>{v.toLocaleString()} credits</option>)}
                        </select>
                        <FiChevronDown className="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
                    </div>
                    <span className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a9] font-medium">when below</span>
                    <div className="relative">
                        <select value={arThreshold} onChange={e => setArThreshold(Number(e.target.value))} disabled={!arEnabled}
                            className="pl-3 pr-7 py-1 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[12.5px] font-bold text-[#111111] dark:text-white appearance-none focus:outline-none disabled:opacity-50">
                            {AR_THRESHOLDS.map(v => <option key={v} value={v}>{v.toLocaleString()} credits</option>)}
                        </select>
                        <FiChevronDown className="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
                    </div>
                    <button onClick={saveAutoRecharge} disabled={arSaving}
                        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold transition-all disabled:opacity-50 ${
                            arSaved ? 'bg-emerald-500/10 text-emerald-600' : 'bg-[#f0f2f8] dark:bg-[#1c1e21] text-[#6e6e73] dark:text-[#9aa0a9] hover:text-[#111111] dark:hover:text-white border border-[#e0e0e0] dark:border-[#ffffff0a]'
                        }`}>
                        {arSaving ? <FiRefreshCw className="w-3.5 h-3.5 animate-spin" /> : <FiCheck className="w-3.5 h-3.5" />}
                        {arSaved ? 'Saved!' : 'Save'}
                    </button>
                </div>
            </Card>

            {/* Request Credits from Agency */}
            <Card>
                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-500 flex-shrink-0">
                            <FiGift className="w-4.5 h-4.5" />
                        </div>
                        <div>
                            <p className="text-[13.5px] font-bold text-[#111111] dark:text-[#ececf1]">Request Credits from Agency</p>
                            <p className="text-[11.5px] text-[#9aa0a6]">Ask your agency to top up your credit balance.</p>
                        </div>
                    </div>
                    <button onClick={() => setReqModalOpen(true)}
                        className="flex-shrink-0 flex items-center gap-2 px-4 py-2 rounded-xl text-[12.5px] font-bold text-purple-600 bg-purple-500/10 hover:bg-purple-500/20 hover:shadow-[0_4px_12px_rgba(168,85,247,0.2)] transition-all">
                        <FiGift className="w-3.5 h-3.5" /> Request Credits
                    </button>
                </div>
            </Card>

            {/* Stats Row */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {[
                    { label: 'Sent Today', value: txLoading ? 'ΓÇö' : String(sentToday), icon: <FiSend className="w-4 h-4" />, color: 'text-[#2b83fa]', bg: 'bg-[#2b83fa]/10' },
                    { label: 'Credits Used Today', value: txLoading ? 'ΓÇö' : String(creditsUsedToday), icon: <FiZap className="w-4 h-4" />, color: 'text-amber-500', bg: 'bg-amber-500/10' },
                    { label: 'This Month', value: txLoading ? 'ΓÇö' : creditsUsedMonth.toLocaleString(), icon: <FiRefreshCw className="w-4 h-4" />, color: 'text-purple-500', bg: 'bg-purple-500/10' },
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
                                <span className={`text-[16px] font-black ${topUpAmount === pkg.credits ? 'text-[#2b83fa]' : 'text-[#111111] dark:text-[#ececf1]'}`}>{pkg.credits?.toLocaleString() || pkg.credits}</span>
                                <span className="text-[11px] text-[#9aa0a6]">credits</span>
                                <span className={`text-[12px] font-bold mt-1 ${topUpAmount === pkg.credits ? 'text-[#2b83fa]' : 'text-[#6e6e73] dark:text-[#94959b]'}`}>Γé▒{pkg.price}</span>
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
                    <div className="flex items-center gap-3">
                        <div className="relative">
                            <select
                                value={txMonth}
                                onChange={(e) => setTxMonth(e.target.value)}
                                className="pl-3 pr-8 py-1.5 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[12.5px] font-semibold text-[#111111] dark:text-white appearance-none focus:outline-none"
                            >
                                {availableMonths.map((m) => (
                                    <option key={m.val} value={m.val}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                            <FiChevronDown className="absolute right-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
                        </div>
                        <button
                            onClick={() => generateMonthlyReport(txMonth, transactions, 'subaccount', 'My Account')}
                            disabled={txLoading || transactions.length === 0}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-[#ffffff] border border-transparent hover:bg-[#f3f4f6] dark:hover:bg-[#1f2023] disabled:opacity-50 transition-all font-inter"
                        >
                            <FiDownload className="w-3.5 h-3.5" /> Download Report
                        </button>
                    </div>
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
                                const sign = isCredit ? '+' : 'ΓêÆ';
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
                                                {absAmount === 0 && !isCredit ? 'ΓêÆ1 free trial' : `${sign}${absAmount?.toLocaleString() || absAmount} credits`}
                                            </span>
                                            <span className="text-[10px] text-[#9aa0a6]">balance: {tx.balance_after?.toLocaleString() || tx.balance_after || 0}</span>
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

// ΓöÇΓöÇΓöÇ Main Export ΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇΓöÇ
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
                                <div key={tab.id} className="flex flex-col md:w-full flex-shrink-0 md:flex-shrink">
                                    <button
                                        onClick={() => setActiveTab(tab.id)}
                                        className={`relative flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 text-left whitespace-nowrap md:whitespace-normal w-full group
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
                                </div>
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
