import { devLog } from '../utils/devLog';
import { useState, useCallback, useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import { useLocation, useNavigate } from "react-router-dom";
import { fetchCreditTransactions, fetchCreditPackages } from "../api/credits";
import type { CreditTransaction, CreditPackage } from "../api/credits";
import {
    FiUser, FiSend, FiBell, FiCreditCard,
    FiSave, FiPlus, FiCheck,
    FiGlobe, FiMapPin, FiBriefcase, FiCheckCircle, FiAlertCircle, FiClock,
    FiRefreshCw, FiZap, FiChevronLeft, FiChevronRight, FiGift, FiChevronDown, FiDownload,
    FiCopy, FiExternalLink, FiSettings, FiShieldOff, FiX, FiEdit3, FiLock, FiEye, FiEyeOff, FiMoreVertical
} from "react-icons/fi";
import { generateMonthlyReport } from "../utils/pdfGenerator";
import {
    getAccountSettings, saveAccountSettings,
    getNotificationSettings, saveNotificationSettings as saveNotificationSettingsLocal,
    type AccountSettings, type NotificationSettings, type StoredSenderId
} from "../utils/settingsStorage";
import {
    fetchNotificationSettings,
    saveNotificationSettings as saveNotificationSettingsRemote,
} from "../api/notificationSettings";
import { SenderRequestModal } from "../components/SenderRequestModal";
import { SenderIdReminderBanner } from "../components/ui/SenderIdReminderBanner";
import { useGhlLocation } from "../hooks/useGhlLocation";
import { useRealtimeCreditStatus } from "../hooks/useRealtimeCreditStatus";
import { fetchSenderRequests, fetchAccountSenderConfig, cancelSenderRequest, type SenderRequest, type AccountSenderConfig } from "../api/senderRequests";
import { fetchAccountProfile, getCachedAccountProfile, updateAccountProfile } from "../api/account";
import type { AccountProfile } from "../api/account";
import { useUserProfileContext } from "../context/UserProfileContext";
import { getSession } from "../services/authService";
import {
    GHL_MARKETPLACE_CONNECT_URL,
    GHL_OAUTH_RETURN_VIEW_STORAGE_KEY,
    GHL_RECONNECT_REQUIRED_STORAGE_KEY
} from "../config";
import { safeStorage } from "../utils/safeStorage";
import { sessionSafeStorage } from "../utils/sessionSafeStorage";
import { apiFetch } from "../utils/apiFetch";


// ─── Types ──────────────────────────────────────────────────────────────────
export type SettingsTab = "account" | "senderIds" | "notifications" | "credits";
type SenderDisplayStatus = "fallback" | "active" | "approved" | "pending" | "rejected" | "revoked" | "configuration_mismatch";

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

const SETTINGS_TAB_ROUTES: Record<SettingsTab, string> = {
    account: "/settings/account",
    senderIds: "/settings/sender-id",
    notifications: "/settings/notifications",
    credits: "/settings/credits",
};



const SENDER_ICONS = [<FiGlobe />, <FiMapPin />, <FiBriefcase />, <FiCheckCircle />];

const STATUS_CONFIG: Record<SenderDisplayStatus, { label: string; color: string; bg: string; icon: React.ReactElement }> = {
    fallback: { label: "System Default / Fallback Sender", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-50 dark:bg-blue-900/20", icon: <FiGlobe className="w-3 h-3" /> },
    active: { label: "Approved \u00b7 Active Sender", color: "text-emerald-600 dark:text-emerald-400", bg: "bg-emerald-50 dark:bg-emerald-900/20", icon: <FiCheck className="w-3 h-3" /> },
    approved: { label: "Approved", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-50 dark:bg-blue-900/20", icon: <FiCheckCircle className="w-3 h-3" /> },
    pending: { label: "Pending", color: "text-amber-600 dark:text-amber-400", bg: "bg-amber-50 dark:bg-amber-900/20", icon: <FiClock className="w-3 h-3" /> },
    rejected: { label: "Rejected", color: "text-red-600 dark:text-red-400", bg: "bg-red-50 dark:bg-red-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
    revoked: { label: "Revoked", color: "text-slate-600 dark:text-slate-400", bg: "bg-slate-100 dark:bg-white/10", icon: <FiShieldOff className="w-3 h-3" /> },
    configuration_mismatch: { label: "Configuration mismatch", color: "text-orange-700 dark:text-orange-300", bg: "bg-orange-50 dark:bg-orange-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
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

const SaveButton: React.FC<{ onClick: () => void; saved: boolean; disabled?: boolean; saving?: boolean }> = ({ onClick, saved, disabled = false, saving = false }) => (
    <button
        onClick={onClick}
        disabled={disabled || saving}
        className={`flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold text-[13px] transition-all duration-300 ${saved
            ? "bg-emerald-500 text-white shadow-md shadow-emerald-500/25"
            : disabled || saving
                ? "bg-gray-200 dark:bg-white/10 text-gray-400 dark:text-gray-500 cursor-not-allowed"
                : "bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white shadow-md shadow-blue-500/20"
            }`}
    >
        {saved ? <FiCheck className="w-4 h-4" /> : saving ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSave className="w-4 h-4" />}
        {saved ? "Saved!" : saving ? "Saving..." : "Save Changes"}
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

const Skeleton: React.FC<{ className?: string }> = ({ className = "" }) => (
    <div className={`animate-pulse bg-[#f0f0f0] dark:bg-white/5 rounded-lg ${className}`} />
);



// ─── Section: Account ───────────────────────────────────────────────────────
const AccountSection: React.FC = () => {
    const [form] = useState<AccountSettings>(getAccountSettings);
    const ghlLocationIdFromHook = useGhlLocation();

    // Consume live profile from context (populated by App.tsx -> useUserProfile)
    const liveProfile = useUserProfileContext();
    const initialLocationIdRef = useRef<string>(
        ghlLocationIdFromHook || liveProfile?.location_id || getAccountSettings().ghlLocationId || ""
    );
    const initialCachedProfileRef = useRef<AccountProfile | null | undefined>(undefined);
    if (initialCachedProfileRef.current === undefined) {
        initialCachedProfileRef.current = initialLocationIdRef.current
            ? getCachedAccountProfile(initialLocationIdRef.current)
            : null;
    }

    // Initialize fetchedName from context or cached location_name
    const [fetchedName, setFetchedName] = useState<string | null>(() => {
        if (initialCachedProfileRef.current?.location_name) {
            return initialCachedProfileRef.current.location_name;
        }
        if (liveProfile?.location_id === initialLocationIdRef.current && liveProfile?.location_name) return liveProfile.location_name;
        try {
            const authUser = JSON.parse(safeStorage.getItem('nola_auth_user') || '{}');
            if (authUser?.location_id === initialLocationIdRef.current && authUser?.location_name) return authUser.location_name;
            const nolaUser = JSON.parse(safeStorage.getItem('nola_user') || '{}');
            if (nolaUser?.location_id === initialLocationIdRef.current && nolaUser?.location_name) return nolaUser.location_name;
        }
        catch { return null; }
        return null;
    });
    const [fetchedProfile, setFetchedProfile] = useState<AccountProfile | null>(
        () => initialCachedProfileRef.current ?? null
    );
    const [isFetchingLocation, setIsFetchingLocation] = useState(false);
    const [showReconnectNotice, setShowReconnectNotice] = useState(
        () => safeStorage.getItem(GHL_RECONNECT_REQUIRED_STORAGE_KEY) === 'true'
    );
    const activeFetchRef = useRef(0);
    const lastLoadedLocationRef = useRef<string | null>(
        initialCachedProfileRef.current?.location_id || null
    );

    // Manage input location ID state
    const [inputLocationId, setInputLocationId] = useState<string>(() => {
        return initialLocationIdRef.current;
    });
    const [profileForm, setProfileForm] = useState({ name: "", email: "", phone: "" });
    const [isSavingProfile, setIsSavingProfile] = useState(false);
    const [profileSaveStatus, setProfileSaveStatus] = useState<{ type: "success" | "error"; message: string } | null>(null);
    const [profileMenuOpen, setProfileMenuOpen] = useState(false);
    const [passwordPanelOpen, setPasswordPanelOpen] = useState(false);
    const [passwordStep, setPasswordStep] = useState<"send_code" | "enter_code" | "change_password">("send_code");
    const [passwordForm, setPasswordForm] = useState({ otp: "", newPassword: "", confirmPassword: "" });
    const [passwordBusy, setPasswordBusy] = useState(false);
    const [passwordStatus, setPasswordStatus] = useState<{ type: "success" | "error" | "info"; message: string } | null>(null);
    const [showNewPassword, setShowNewPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const profileMenuRef = useRef<HTMLDivElement | null>(null);

    const getEditableProfileValues = useCallback(() => {
        const safeValue = (value?: string | null) => (value && value !== "N/A" ? value : "");
        const liveName = liveProfile?.location_id === inputLocationId
            ? (liveProfile?.name || `${liveProfile?.firstName ?? ""} ${liveProfile?.lastName ?? ""}`.trim())
            : "";

        return {
            name: safeValue(fetchedProfile?.full_name || fetchedProfile?.name || liveName),
            email: safeValue(
                fetchedProfile?.email ||
                fetchedProfile?.email_address ||
                (liveProfile?.location_id === inputLocationId ? liveProfile?.email : "")
            ),
            phone: safeValue(
                fetchedProfile?.phone ||
                fetchedProfile?.phone_number ||
                (liveProfile?.location_id === inputLocationId ? liveProfile?.phone : "")
            ),
        };
    }, [fetchedProfile, inputLocationId, liveProfile]);

    useEffect(() => {
        setProfileForm(getEditableProfileValues());
    }, [getEditableProfileValues]);

    useEffect(() => {
        if (!profileMenuOpen) return;
        const closeOnOutsideClick = (event: MouseEvent) => {
            if (profileMenuRef.current && !profileMenuRef.current.contains(event.target as Node)) {
                setProfileMenuOpen(false);
            }
        };
        document.addEventListener("mousedown", closeOnOutsideClick);
        return () => document.removeEventListener("mousedown", closeOnOutsideClick);
    }, [profileMenuOpen]);

    // Synchronize context state with local variables if needed
    useEffect(() => {
        if (liveProfile?.location_id === inputLocationId && liveProfile?.location_name) {
            setFetchedName(liveProfile.location_name);
        }
    }, [liveProfile, inputLocationId]);

    // Keep active location in sync with hook or context profile
    useEffect(() => {
        const activeLocation = ghlLocationIdFromHook || liveProfile?.location_id;
        if (activeLocation && activeLocation !== lastLoadedLocationRef.current) {
            setInputLocationId(activeLocation);
            fetchAndSetLocation(activeLocation);
        }
    }, [ghlLocationIdFromHook, liveProfile?.location_id]);

    const applyAccountProfile = useCallback((profile: AccountProfile) => {
        setFetchedProfile(profile);

        const nextLocationName =
            profile.location_name && profile.location_name !== "Unknown"
                ? profile.location_name
                : null;

        if (nextLocationName) {
            setFetchedName(nextLocationName);
        }

        // Only patch cached session profile if this is the active session location
        const sessionLocationId = safeStorage.getItem('nola_location_id') || liveProfile?.location_id;
        const isSessionLocation = sessionLocationId === profile.location_id;

        if (isSessionLocation) {
            const patchCachedUser = (key: string) => {
                try {
                    const cached = JSON.parse(safeStorage.getItem(key) || '{}');
                    safeStorage.setItem(key, JSON.stringify({
                        ...cached,
                        location_id: profile.location_id || cached.location_id,
                        location_name: nextLocationName || cached.location_name,
                        name: profile.full_name || profile.name || cached.name,
                        email: profile.email || profile.email_address || cached.email,
                        phone: profile.phone || profile.phone_number || cached.phone,
                    }));
                } catch {
                    // Cache sync is best-effort only.
                }
            };

            patchCachedUser('nola_user');
            patchCachedUser('nola_auth_user');

            if (nextLocationName) {
                const fresh = getAccountSettings();
                if (fresh.displayName !== nextLocationName) {
                    saveAccountSettings({ ...fresh, displayName: nextLocationName });
                    window.dispatchEvent(new Event("account-settings-updated"));
                }
            }
        }
    }, [liveProfile]);

    const fetchAndSetLocation = async (locId: string, options: { forceRefresh?: boolean } = {}) => {
        const normalizedLocationId = locId.trim();
        if (!normalizedLocationId) return;

        const cachedProfile = getCachedAccountProfile(normalizedLocationId);
        if (cachedProfile && !options.forceRefresh) {
            applyAccountProfile(cachedProfile);
            lastLoadedLocationRef.current = normalizedLocationId;
            return;
        }

        const requestId = activeFetchRef.current + 1;
        activeFetchRef.current = requestId;
        setIsFetchingLocation(true);
        const currentSettings = getAccountSettings();
        if (currentSettings.ghlLocationId !== normalizedLocationId) {
            saveAccountSettings({ ...currentSettings, ghlLocationId: normalizedLocationId });
            // Notify LocationContext so all subscribers get the new location reactively
            window.dispatchEvent(
                new CustomEvent('ghl-location-set', { detail: { locationId: normalizedLocationId } })
            );
        }

        const profile = await fetchAccountProfile(normalizedLocationId, {
            forceRefresh: options.forceRefresh,
            allowStaleOnError: true,
        });
        if (activeFetchRef.current !== requestId) return;
        setIsFetchingLocation(false);

        if (profile) {
            applyAccountProfile(profile);
            lastLoadedLocationRef.current = normalizedLocationId;
        }
        // Note: do NOT set fetchedName to "Location Not Found" on failure —
        // we already have the correct value from nola_user cache (set at login/register)
    };

    // Initial fetch on mount
    useEffect(() => {
        if (initialLocationIdRef.current) {
            fetchAndSetLocation(initialLocationIdRef.current);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const handleSaveLocation = () => {
        fetchAndSetLocation(inputLocationId, { forceRefresh: true });
    };

    const handleReconnectGhl = () => {
        safeStorage.setItem(GHL_OAUTH_RETURN_VIEW_STORAGE_KEY, 'contacts');
        window.location.href = GHL_MARKETPLACE_CONNECT_URL;
    };

    const handleSaveProfile = async () => {
        if (!profileForm.name.trim() || !profileForm.email.trim()) {
            setProfileSaveStatus({ type: "error", message: "Name and email are required." });
            return;
        }

        setIsSavingProfile(true);
        setProfileSaveStatus(null);
        try {
            const updatedProfile = await updateAccountProfile(
                {
                    name: profileForm.name,
                    email: profileForm.email,
                    phone: profileForm.phone,
                },
                inputLocationId
            );
            applyAccountProfile({
                ...(fetchedProfile || {}),
                ...updatedProfile,
                location_id: updatedProfile.location_id || inputLocationId,
                location_name: updatedProfile.location_name ?? fetchedProfile?.location_name ?? null,
            });
            setProfileForm({
                name: updatedProfile.full_name || updatedProfile.name || profileForm.name.trim(),
                email: updatedProfile.email || updatedProfile.email_address || profileForm.email.trim(),
                phone: updatedProfile.phone || updatedProfile.phone_number || profileForm.phone.trim(),
            });
            setProfileSaveStatus({ type: "success", message: "Profile updated successfully." });
            window.dispatchEvent(new Event("nola-profile-updated"));
        } catch (err) {
            setProfileSaveStatus({
                type: "error",
                message: err instanceof Error ? err.message : "Failed to update profile.",
            });
        } finally {
            setIsSavingProfile(false);
        }
    };

    const requestPasswordOtp = async () => {
        const email = profileForm.email.trim();
        if (!email) {
            setPasswordStatus({ type: "error", message: "Save an email address before requesting a reset code." });
            return;
        }

        setPasswordBusy(true);
        setPasswordStatus(null);
        try {
            const res = await apiFetch('/api/auth/forgot_password_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.error || json.message || 'Could not send reset code.');
            setPasswordStep("enter_code");
            setPasswordStatus({ type: "success", message: `Reset code sent to ${email}.` });
        } catch (err) {
            setPasswordStatus({
                type: "error",
                message: err instanceof Error ? err.message : "Could not send reset code.",
            });
        } finally {
            setPasswordBusy(false);
        }
    };

    const handlePasswordCodeContinue = () => {
        const otp = passwordForm.otp.trim();
        if (!otp) {
            setPasswordStatus({ type: "error", message: "Enter the verification code." });
            return;
        }
        if (otp.length < 4) {
            setPasswordStatus({ type: "error", message: "Enter the full verification code." });
            return;
        }
        setPasswordStatus(null);
        setPasswordStep("change_password");
    };

    const handlePasswordReset = async () => {
        const email = profileForm.email.trim();
        const otp = passwordForm.otp.trim();
        const newPassword = passwordForm.newPassword;

        if (!email || !otp || !newPassword || !passwordForm.confirmPassword) {
            setPasswordStatus({ type: "error", message: "Enter the code and your new password." });
            return;
        }
        if (newPassword.length < 8) {
            setPasswordStatus({ type: "error", message: "Use at least 8 characters for the new password." });
            return;
        }
        if (newPassword !== passwordForm.confirmPassword) {
            setPasswordStatus({ type: "error", message: "Passwords do not match." });
            return;
        }

        setPasswordBusy(true);
        setPasswordStatus(null);
        try {
            const res = await apiFetch('/api/auth/reset_password_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, otp, new_password: newPassword }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.error || json.message || 'Could not update password.');
            setPasswordForm({ otp: "", newPassword: "", confirmPassword: "" });
            setPasswordStep("send_code");
            setPasswordPanelOpen(false);
            setPasswordStatus({ type: "success", message: "Password updated successfully." });
            setProfileSaveStatus({ type: "success", message: "Password updated successfully." });
        } catch (err) {
            setPasswordStatus({
                type: "error",
                message: err instanceof Error ? err.message : "Could not update password.",
            });
        } finally {
            setPasswordBusy(false);
        }
    };

    const openPasswordModal = () => {
        setPasswordPanelOpen(true);
        setPasswordStep("send_code");
        setPasswordStatus(null);
        setPasswordForm({ otp: "", newPassword: "", confirmPassword: "" });
        setShowNewPassword(false);
        setShowConfirmPassword(false);
    };

    const closePasswordModal = () => {
        setPasswordPanelOpen(false);
        setPasswordStep("send_code");
        setPasswordStatus(null);
        setPasswordForm({ otp: "", newPassword: "", confirmPassword: "" });
        setShowNewPassword(false);
        setShowConfirmPassword(false);
    };


    // Derived values
    // subaccountName: use the fetchedName if it's a real value, otherwise fallback to profile cache
    const subaccountName = (fetchedName && fetchedName !== "Location Not Found")
        ? fetchedName
        : ((liveProfile?.location_id === inputLocationId && liveProfile?.location_name) || form.displayName || "Not Found");
    const statusCfg = STATUS_CONFIG[form.accountStatus];
    // fullName: use fetchedProfile, then `name` field; fall back to legacy firstName+lastName for old sessions
    const fullName = fetchedProfile?.full_name
        || fetchedProfile?.name
        || (liveProfile?.location_id === inputLocationId ? liveProfile?.name : null)
        || (liveProfile?.location_id === inputLocationId ? `${liveProfile?.firstName ?? ''} ${liveProfile?.lastName ?? ''}`.trim() : '')
        || 'N/A';
    const displayEmail = fetchedProfile?.email || fetchedProfile?.email_address || (liveProfile?.location_id === inputLocationId ? liveProfile?.email : null) || 'N/A';
    const resolvedLocationId = ghlLocationIdFromHook || liveProfile?.location_id || inputLocationId || '';
    const showPersonalSkeleton = isFetchingLocation && (fullName === 'N/A' || displayEmail === 'N/A');
    const showWorkspaceSkeleton = isFetchingLocation && (subaccountName === 'Not Found' || subaccountName === 'N/A');
    const profileDisplayName = profileForm.name || fullName || "User";
    const profileInitial = (profileDisplayName || "U").trim().charAt(0).toUpperCase();
    const profileBaseline = getEditableProfileValues();
    const hasProfileChanges =
        profileForm.name.trim() !== profileBaseline.name.trim() ||
        profileForm.email.trim() !== profileBaseline.email.trim() ||
        profileForm.phone.trim() !== profileBaseline.phone.trim();

    return (
        <div className="space-y-5">
            <SectionHeader title="Account Details" subtitle="View your profile and GoHighLevel workspace information." />

            {showReconnectNotice && (
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 py-3 rounded-xl border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10">
                    <div className="flex items-start gap-2.5">
                        <FiAlertCircle className="w-4 h-4 mt-0.5 text-amber-700 dark:text-amber-300 flex-shrink-0" />
                        <p className="text-[12.5px] text-amber-800 dark:text-amber-200 leading-relaxed">
                            Your CRM link expired. Use Reconnect GoHighLevel below to refresh the connection, then return to Contacts.
                        </p>
                    </div>
                    <button
                        onClick={() => {
                            safeStorage.removeItem(GHL_RECONNECT_REQUIRED_STORAGE_KEY);
                            setShowReconnectNotice(false);
                        }}
                        className="shrink-0 text-[12px] font-bold text-amber-800 dark:text-amber-200 hover:text-amber-950 dark:hover:text-amber-100"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {/* Status Banner */}
            <div className={`flex items-center gap-2.5 px-4 py-3 rounded-xl border ${statusCfg.bg} border-transparent`}>
                <span className={statusCfg.color}>{statusCfg.icon}</span>
                <span className={`text-[13px] font-semibold ${statusCfg.color}`}>
                    Workspace Status: {statusCfg.label}
                </span>
            </div>

            {/* Personal Details */}
            <Card className="p-5 sm:p-6">
                <div className="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-5 mb-7">
                    <div className="w-20 h-20 rounded-full overflow-hidden ring-4 ring-white dark:ring-[#25282c] shadow-lg bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] flex items-center justify-center text-white text-3xl font-black">
                        {profileInitial}
                    </div>

                    <div className="flex-1 min-w-0">
                        {showPersonalSkeleton ? (
                            <div className="space-y-2">
                                <Skeleton className="h-6 w-44" />
                                <Skeleton className="h-3 w-56" />
                            </div>
                        ) : (
                            <>
                                <h3 className="text-[22px] sm:text-[24px] font-black tracking-tight text-[#111111] dark:text-white truncate">{profileDisplayName}</h3>
                                <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5 truncate">{profileForm.email || displayEmail}</p>
                            </>
                        )}
                    </div>

                    <div className="relative self-start sm:self-center" ref={profileMenuRef}>
                        <button
                            type="button"
                            onClick={() => setProfileMenuOpen(prev => !prev)}
                            className="h-10 w-10 inline-flex items-center justify-center rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-white/10 text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#2b83fa] hover:border-[#2b83fa]/30 transition-all"
                            aria-label="More profile options"
                            title="More options"
                        >
                            <FiMoreVertical className="w-4 h-4" />
                        </button>
                        {profileMenuOpen && (
                            <div className="absolute right-0 top-full mt-2 w-56 rounded-2xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1a1b1e] shadow-2xl z-30 p-1.5">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setProfileMenuOpen(false);
                                        openPasswordModal();
                                    }}
                                    className="w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-left text-[13px] font-bold text-[#111111] dark:text-white hover:bg-[#f5f5f6] dark:hover:bg-white/5 transition-colors"
                                >
                                    <FiLock className="w-4 h-4 text-[#2b83fa]" />
                                    Change Password
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                <div className="space-y-4">
                    {[
                        {
                            label: "Full Name",
                            type: "text",
                            value: profileForm.name,
                            placeholder: "Full name",
                            onChange: (value: string) => setProfileForm(prev => ({ ...prev, name: value })),
                        },
                        {
                            label: "Email Address",
                            type: "email",
                            value: profileForm.email,
                            placeholder: "Email address",
                            onChange: (value: string) => setProfileForm(prev => ({ ...prev, email: value })),
                        },
                        {
                            label: "Phone Number",
                            type: "tel",
                            value: profileForm.phone,
                            placeholder: "Phone number",
                            onChange: (value: string) => setProfileForm(prev => ({ ...prev, phone: value })),
                        },
                    ].map(field => (
                        <div key={field.label}>
                            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">
                                {field.label}
                            </label>
                            {showPersonalSkeleton ? (
                                <Skeleton className="h-12 w-full rounded-xl" />
                            ) : (
                                <div className="relative">
                                    <input
                                        type={field.type}
                                        value={field.value}
                                        onChange={(e) => {
                                            field.onChange(e.target.value);
                                            setProfileSaveStatus(null);
                                        }}
                                        placeholder={field.placeholder}
                                        className="w-full h-12 pl-4 pr-12 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 focus:border-[#2b83fa]/60 transition-all"
                                    />
                                    <FiEdit3 className="absolute right-4 top-1/2 -translate-y-1/2 w-4 h-4 text-[#6e6e73] dark:text-[#9aa0a6]" />
                                </div>
                            )}
                        </div>
                    ))}

                    {profileSaveStatus && (
                        <div className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-[12px] font-semibold ${profileSaveStatus.type === "success"
                            ? "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20"
                            : "bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/20"
                            }`}>
                            {profileSaveStatus.type === "success" ? <FiCheck className="w-4 h-4" /> : <FiAlertCircle className="w-4 h-4" />}
                            {profileSaveStatus.message}
                        </div>
                    )}

                    {hasProfileChanges && (
                        <button
                            onClick={handleSaveProfile}
                            disabled={showPersonalSkeleton || isSavingProfile || !profileForm.name.trim() || !profileForm.email.trim()}
                            className="w-full h-12 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[13px] font-black hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                        >
                            {isSavingProfile ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSave className="w-4 h-4" />}
                            {isSavingProfile ? "Saving..." : "Save Changes"}
                        </button>
                    )}
                </div>

                <div className="mt-7 space-y-4 pt-6 border-t border-[#f0f0f0] dark:border-[#ffffff05]">
                    <div className="flex items-center gap-4">
                        <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500">
                            <FiMapPin className="w-6 h-6" />
                        </div>
                        <div className="flex-1">
                            {showWorkspaceSkeleton ? (
                                <div className="space-y-2">
                                    <Skeleton className="h-5 w-40" />
                                    <Skeleton className="h-3 w-32" />
                                </div>
                            ) : (
                                <>
                                    <h3 className="text-[15px] font-bold text-[#111111] dark:text-[#ececf1]">
                                        {fetchedName === 'Location Not Found' ? <span className="text-red-500">Not Found</span> : subaccountName}
                                    </h3>
                                    <p className="text-[12px] text-[#9aa0a6]">
                                        {liveProfile?.company_name ? `Agency: ${liveProfile.company_name}` : 'GHL Workspace'}
                                    </p>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="p-4 rounded-xl bg-amber-50/70 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/20 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h4 className="text-[13px] font-bold text-amber-900 dark:text-amber-200">GoHighLevel connection</h4>
                            <p className="text-[12px] text-amber-800/90 dark:text-amber-200/80 leading-relaxed mt-1">
                                Reconnect your CRM if contacts stop loading or the connection expires.
                            </p>
                        </div>
                        <button
                            onClick={handleReconnectGhl}
                            className="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-[#1d6bd4] to-[#2b83fa] text-white text-[13px] font-bold shadow-md hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] active:scale-95 transition-all"
                        >
                            <FiRefreshCw className="w-4 h-4" />
                            Reconnect GoHighLevel
                            <FiExternalLink className="w-3.5 h-3.5 opacity-80" />
                        </button>
                    </div>
                    <div>
                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Location Name</label>
                        {showWorkspaceSkeleton ? (
                            <Skeleton className="h-9 w-full rounded-xl" />
                        ) : (
                            <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold">
                                {fetchedName === 'Location Not Found' ? <span className="text-red-500">Not Found</span> : subaccountName}
                            </div>
                        )}
                    </div>
                    {liveProfile?.company_name && (
                        <div>
                            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Agency / Company</label>
                            <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold">
                                {liveProfile.company_name}
                            </div>
                        </div>
                    )}
                    <div>
                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">
                            GHL Location ID
                        </label>
                        {window.self === window.top ? (
                            <div className="flex items-center gap-2">
                                <input
                                    type="text"
                                    value={inputLocationId}
                                    onChange={(e) => setInputLocationId(e.target.value)}
                                    placeholder="Enter GHL Location ID"
                                    className={`flex-1 px-4 py-2.5 rounded-xl border text-[13px] font-mono placeholder:font-sans focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/50 transition-all ${isFetchingLocation
                                        ? 'border-transparent bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#6e6e73] dark:text-[#9aa0a6]'
                                        : 'border-[#e0e0e0] dark:border-[#ffffff0a] bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#111111] dark:text-[#ececf1] shadow-inner hover:border-[#2b83fa]/50'
                                        }`}
                                    disabled={isFetchingLocation}
                                />
                                {inputLocationId !== ghlLocationIdFromHook && (
                                    <button
                                        onClick={handleSaveLocation}
                                        disabled={isFetchingLocation || !inputLocationId.trim()}
                                        className="shrink-0 px-4 py-2.5 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] transition-all shadow-md flex items-center gap-2 font-semibold disabled:opacity-50 text-[13px]"
                                        title="Save Location ID"
                                    >
                                        {isFetchingLocation ? 'Saving...' : <><FiSave className="w-4 h-4" /> Save</>}
                                    </button>
                                )}
                                {resolvedLocationId && inputLocationId === ghlLocationIdFromHook && (
                                    <button
                                        onClick={() => navigator.clipboard.writeText(resolvedLocationId)}
                                        className="shrink-0 p-3 rounded-xl border border-[#e0e0e0] dark:border-[#ffffff0a] bg-white dark:bg-[#25282c] text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#2b83fa] transition-all shadow-sm"
                                        title="Copy Location ID"
                                    >
                                        <FiCopy className="w-4 h-4" />
                                    </button>
                                )}
                            </div>
                        ) : (
                            <div className="px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] font-mono flex items-center justify-between">
                                <span>{resolvedLocationId || "Not Found"}</span>
                                {isFetchingLocation && <FiRefreshCw className="w-4 h-4 text-[#2b83fa] animate-spin" />}
                            </div>
                        )}
                    </div>
                </div>
            </Card>

            {passwordPanelOpen && typeof document !== "undefined" && createPortal((
                <div
                    className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/55 backdrop-blur-md px-4 py-6"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) closePasswordModal();
                    }}
                >
                    <div className="w-full max-w-lg rounded-2xl bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 shadow-2xl overflow-hidden">
                        <div className="flex items-start justify-between gap-4 p-5 border-b border-[#f0f0f0] dark:border-[#ffffff08]">
                            <div className="flex items-start gap-3">
                                <div className="w-11 h-11 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center shrink-0">
                                    <FiLock className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-[16px] font-black text-[#111111] dark:text-white">
                                        {passwordStep === "send_code" && "Send Verification Code"}
                                        {passwordStep === "enter_code" && "Enter Verification Code"}
                                        {passwordStep === "change_password" && "Change Password"}
                                    </h3>
                                    <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1 leading-relaxed">
                                        {passwordStep === "send_code" && "We'll email a verification code to your profile email."}
                                        {passwordStep === "enter_code" && "Enter the code you received before setting a new password."}
                                        {passwordStep === "change_password" && "Create and confirm your new password."}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={closePasswordModal}
                                className="h-9 w-9 rounded-xl inline-flex items-center justify-center text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f5f5f6] dark:hover:bg-white/5 hover:text-red-500 transition-colors"
                                aria-label="Close account management modal"
                            >
                                <FiX className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="p-5 space-y-4">
                            {passwordStep === "send_code" && (
                                <div className="space-y-3">
                                    <div className="px-4 py-3 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] text-[12.5px] text-[#6e6e73] dark:text-[#9aa0a6] font-semibold">
                                        {profileForm.email || "Add an email address first"}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={requestPasswordOtp}
                                        disabled={passwordBusy || !profileForm.email.trim()}
                                        className="w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[12.5px] font-bold shadow-md shadow-blue-500/20 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                    >
                                        {passwordBusy ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSend className="w-4 h-4" />}
                                        Send Verification Code
                                    </button>
                                </div>
                            )}

                            {passwordStep === "enter_code" && (
                                <div className="space-y-3">
                                    <input
                                        type="text"
                                        inputMode="numeric"
                                        value={passwordForm.otp}
                                        onChange={(e) => setPasswordForm(prev => ({ ...prev, otp: e.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                        placeholder="Verification code"
                                        className="h-11 w-full px-4 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                    />
                                    <div className="grid grid-cols-2 gap-3">
                                        <button
                                            type="button"
                                            onClick={requestPasswordOtp}
                                            disabled={passwordBusy || !profileForm.email.trim()}
                                            className="h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] text-[#111111] dark:text-white text-[12.5px] font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                        >
                                            {passwordBusy ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSend className="w-4 h-4" />}
                                            Resend Code
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handlePasswordCodeContinue}
                                            disabled={!passwordForm.otp.trim()}
                                            className="h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[12.5px] font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                        >
                                            Continue
                                        </button>
                                    </div>
                                </div>
                            )}

                            {passwordStep === "change_password" && (
                                <div className="space-y-3">
                                    <div className="relative">
                                        <input
                                            type={showNewPassword ? "text" : "password"}
                                            value={passwordForm.newPassword}
                                            onChange={(e) => setPasswordForm(prev => ({ ...prev, newPassword: e.target.value }))}
                                            placeholder="New password"
                                            autoComplete="new-password"
                                            className="w-full h-11 pl-4 pr-10 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowNewPassword(prev => !prev)}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#2b83fa]"
                                            aria-label="Toggle new password visibility"
                                        >
                                            {showNewPassword ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                                        </button>
                                    </div>
                                    <div className="relative">
                                        <input
                                            type={showConfirmPassword ? "text" : "password"}
                                            value={passwordForm.confirmPassword}
                                            onChange={(e) => setPasswordForm(prev => ({ ...prev, confirmPassword: e.target.value }))}
                                            placeholder="Confirm password"
                                            autoComplete="new-password"
                                            className="w-full h-11 pl-4 pr-10 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowConfirmPassword(prev => !prev)}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#2b83fa]"
                                            aria-label="Toggle confirm password visibility"
                                        >
                                            {showConfirmPassword ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={handlePasswordReset}
                                        disabled={passwordBusy || !passwordForm.newPassword || !passwordForm.confirmPassword}
                                        className="w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-[#111111] dark:bg-white text-white dark:text-[#111111] text-[12.5px] font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                    >
                                        {passwordBusy ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiCheck className="w-4 h-4" />}
                                        Change Password
                                    </button>
                                </div>
                            )}

                            {passwordStatus && (
                                <div className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-[12px] font-semibold ${passwordStatus.type === "success"
                                    ? "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20"
                                    : passwordStatus.type === "info"
                                        ? "bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:border-blue-500/20"
                                        : "bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/20"
                                    }`}>
                                    {passwordStatus.type === "success" ? <FiCheck className="w-4 h-4" /> : <FiAlertCircle className="w-4 h-4" />}
                                    {passwordStatus.message}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            ), document.body)}

            {window.self === window.top && (
                <div className="p-4 rounded-xl bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/20">
                    <p className="text-[12px] text-blue-700 dark:text-blue-300 leading-relaxed">
                        <strong>Note:</strong> Account information is automatically synced from your GoHighLevel workspace. Type your Location ID above to fetch your details automatically.
                    </p>
                </div>
            )}
        </div>
    );
};



// ─── Section: Sender IDs ────────────────────────────────────────────────────
const SenderIdsSection: React.FC<{ autoOpenAddModal?: boolean }> = ({ autoOpenAddModal }) => {
    const locationId = useGhlLocation() || getAccountSettings().ghlLocationId || undefined;
    const [senderRequests, setSenderRequests] = useState<SenderRequest[]>([]);
    const [config, setConfig] = useState<AccountSenderConfig | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [isAdding, setIsAdding] = useState(() => Boolean(autoOpenAddModal));
    const [cancellingId, setCancellingId] = useState<string | null>(null);

    const applySenderData = useCallback((requests: SenderRequest[], cfg: AccountSenderConfig | null) => {
        setSenderRequests(requests);
        if (cfg) setConfig(cfg);
    }, []);

    const loadSenderData = useCallback(async (showLoading = false) => {
        if (showLoading) setIsLoading(true);
        const [requests, cfg] = await Promise.all([
            fetchSenderRequests(locationId).catch(err => {
                devLog.error("Failed to fetch sender requests:", err);
                return [];
            }),
            fetchAccountSenderConfig(locationId).catch(err => {
                devLog.error("Failed to fetch account sender config:", err);
                return null;
            })
        ]);
        applySenderData(requests, cfg);
        setIsLoading(false);
        return { requests, config: cfg };
    }, [applySenderData, locationId]);

    // Fetch requests and account config together so display state reflects the real backend pairing.
    useEffect(() => {
        void loadSenderData(true);
    }, [loadSenderData]);

    const systemDefault = config?.system_default_sender || "NOLASMSPro";
    const freeUsageCount = config?.free_usage_count || 0;
    const freeLimit = config?.free_credits_total || 10;
    const providerLabel = (value?: string | null) => {
        const normalized = value?.toLowerCase().trim() || "";
        if (normalized.startsWith("unisms")) return "UniSMS";
        if (normalized.startsWith("semaphore")) return "Semaphore";
        return undefined;
    };

    const configuredSenderId = config?.approved_sender_id?.trim() || "";
    const configuredSenderKey = configuredSenderId.toLowerCase();
    const activeRequest = configuredSenderKey
        ? senderRequests.find(req => req.status === "approved" && req.requested_id.trim().toLowerCase() === configuredSenderKey)
        : undefined;
    const activeProvider = providerLabel(activeRequest?.provider || activeRequest?.approved_provider || config?.approved_provider || activeRequest?.provider_preference || config?.provider_preference);

    const colorForStatus = (status: SenderDisplayStatus) => {
        switch (status) {
            case "fallback": return "bg-blue-500";
            case "active": return "bg-emerald-500";
            case "approved": return "bg-blue-500";
            case "pending": return "bg-amber-500";
            case "rejected": return "bg-red-500";
            case "configuration_mismatch": return "bg-orange-500";
            case "revoked": return "bg-slate-500";
        }
    };

    const descriptionForRequest = (req: SenderRequest, status: SenderDisplayStatus) => {
        if (status === "configuration_mismatch") {
            return "Configuration Issue - Contact Support.";
        }
        if (status === "approved") {
            return "Approved, but not currently active.";
        }
        if (status === "revoked") {
            return "Explicitly revoked by an administrator.";
        }
        return req.purpose || "Sender ID Request";
    };

    // Build display list: active system/default sender + API-fetched request history.
    const displayItems: { id: string; name: string; description: string; status: SenderDisplayStatus; color: string; isSystem: boolean; submittedAt?: string; adminNotes?: string; sampleMessage?: string; provider?: string }[] = [
        { id: "system-default", name: systemDefault, description: "Fallback sender used only when no approved sender is active.", status: "fallback", color: "bg-blue-500", isSystem: true },
    ];

    // Add active custom sender from config (if different from system default).
    if (configuredSenderId && configuredSenderId !== systemDefault) {
        displayItems.push({
            id: "approved-custom",
            name: configuredSenderId,
            description: "Active sender configured for this account.",
            status: "active",
            color: "bg-emerald-500",
            isSystem: false,
            provider: activeProvider,
        });
    }

    for (const req of senderRequests) {
        const requestSenderKey = req.requested_id.trim().toLowerCase();
        if (req.status === "approved" && configuredSenderKey && configuredSenderKey === requestSenderKey) continue;

        const displayStatus: SenderDisplayStatus = req.status === "approved"
            ? "configuration_mismatch"
            : req.status;

        displayItems.push({
            id: req.id,
            name: req.requested_id,
            description: descriptionForRequest(req, displayStatus),
            status: displayStatus,
            color: colorForStatus(displayStatus),
            isSystem: false,
            submittedAt: req.created_at,
            adminNotes: req.admin_notes,
            sampleMessage: req.sample_message,
            provider: displayStatus === "pending" ? undefined : providerLabel(req.provider || req.approved_provider || req.provider_preference),
        });
    }

    const activeCount = displayItems.filter(item => item.status === "active").length;
    const pendingCount = senderRequests.filter(req => req.status === "pending").length;
    const rejectedCount = senderRequests.filter(req => req.status === "rejected").length;
    const revokedCount = senderRequests.filter(req => req.status === "revoked").length;
    const mismatchCount = displayItems.filter(item => item.status === "configuration_mismatch").length;

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

        // Refetch requests and account config after index delay catch-up.
        setTimeout(() => {
            void loadSenderData();
        }, 1500);
    };

    const handleCancelRequest = async (requestId: string) => {
        if (!window.confirm("Cancel this pending sender request?")) return;
        setCancellingId(requestId);
        try {
            await cancelSenderRequest(requestId, locationId);
            await loadSenderData();
        } catch (error) {
            devLog.error("Failed to cancel sender request:", error);
            alert(error instanceof Error ? error.message : "Failed to cancel sender request.");
        } finally {
            setCancellingId(null);
        }
    };

    return (
        <div className="space-y-5">
            <SectionHeader title="Sender IDs" subtitle="Manage and request sender IDs for your account. Only approved IDs can be used for sending." />

            {!isLoading && (
                <SenderIdReminderBanner
                    onOpenRequestModal={() => setIsAdding(true)}
                    ignoreDismiss={true}
                />
            )}

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-800/30">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">Active</p>
                    <p className="text-[20px] font-black text-[#111111] dark:text-white mt-1">{activeCount}</p>
                </div>
                <div className="p-4 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-800/30">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">Pending Review</p>
                    <p className="text-[20px] font-black text-[#111111] dark:text-white mt-1">{pendingCount}</p>
                </div>
                <div className="p-4 rounded-xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-800/30">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-red-700 dark:text-red-400">Needs Changes</p>
                    <p className="text-[20px] font-black text-[#111111] dark:text-white mt-1">{rejectedCount}</p>
                </div>
                <div className="p-4 rounded-xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-700 dark:text-slate-400">Revoked</p>
                    <p className="text-[20px] font-black text-[#111111] dark:text-white mt-1">{revokedCount}</p>
                </div>
                <div className="p-4 rounded-xl bg-orange-50 dark:bg-orange-900/10 border border-orange-100 dark:border-orange-800/30">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-orange-700 dark:text-orange-400">Config Issue</p>
                    <p className="text-[20px] font-black text-[#111111] dark:text-white mt-1">{mismatchCount}</p>
                </div>
            </div>

            {/* Sender IDs List */}
            <Card>
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider">Sender ID Status</h3>
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
                            <div key={i} className="flex items-center gap-3 p-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10]">
                                <Skeleton className="w-9 h-9 rounded-xl" />
                                <div className="flex-1 space-y-1.5">
                                    <Skeleton className="h-3.5 w-24" />
                                    <Skeleton className="h-2.5 w-40" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="space-y-2">
                        {displayItems.map((sid, i) => {
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

                                            {sid.isSystem && (
                                                <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider ${freeUsageCount >= freeLimit
                                                    ? "bg-red-50 dark:bg-red-900/20 text-red-500"
                                                    : "bg-gray-200 dark:bg-gray-800 text-gray-500"
                                                    }`}>
                                                    System • {freeUsageCount}/{freeLimit} Free
                                                </span>
                                            )}
                                        </div>
                                        <div className="space-y-1">
                                            <p className="text-[11px] text-[#9aa0a6] leading-snug">{sid.description}</p>
                                            {sid.submittedAt && (
                                                <p className="text-[10px] text-[#9aa0a6] font-medium">Submitted {sid.submittedAt}</p>
                                            )}
                                            {sid.status === "rejected" && sid.adminNotes && (
                                                <p className="text-[11px] text-red-600 dark:text-red-400 font-medium leading-snug">Admin note: {sid.adminNotes}</p>
                                            )}
                                            {sid.status === "configuration_mismatch" && (
                                                <p className="text-[11px] text-orange-700 dark:text-orange-300 font-semibold leading-snug">Approved request exists, but this account has no active sender mapping. Contact support before sending.</p>
                                            )}
                                            {sid.status === "revoked" && (
                                                <p className="text-[11px] text-slate-600 dark:text-slate-400 font-medium leading-snug">This sender was explicitly revoked and messages now use the system fallback.</p>
                                            )}
                                        </div>
                                    </div>

                                    {/* Action Button */}
                                    {sid.status === "pending" && !sid.isSystem && (
                                        <button
                                            onClick={() => handleCancelRequest(sid.id)}
                                            disabled={cancellingId === sid.id}
                                            className="opacity-0 group-hover:opacity-100 transition-opacity px-3 py-1.5 text-[11px] font-bold text-red-600 bg-red-50 hover:bg-red-100 dark:text-red-400 dark:bg-red-900/10 dark:hover:bg-red-900/20 rounded-lg whitespace-nowrap disabled:opacity-50"
                                        >
                                            {cancellingId === sid.id ? "Cancelling..." : "Cancel Request"}
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
const resolveProfileEmail = (profile?: Partial<AccountProfile> | null): string =>
    profile?.email || profile?.email_address || "";

const pickText = (...values: Array<string | null | undefined>): string =>
    values.find(value => typeof value === "string" && value.trim() !== "")?.trim() || "";

const joinProfileName = (profile?: Partial<AccountProfile> | null): string =>
    pickText(
        profile?.full_name,
        profile?.name,
        [profile?.firstName, profile?.lastName].filter(Boolean).join(" ")
    );

const NotificationsSection: React.FC = () => {
    const [form, setForm] = useState<NotificationSettings>(getNotificationSettings);
    const [registeredEmail, setRegisteredEmail] = useState("");
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const ghlLocationIdFromHook = useGhlLocation();
    const liveProfile = useUserProfileContext();

    const toggleLowBalanceEmail = () =>
        setForm(prev => {
            const enabled = !prev.lowBalanceAlert;
            return {
                ...prev,
                lowBalanceAlert: enabled,
                ghlWorkflowSyncEnabled: enabled,
                deliveryReports: false,
                marketingEmails: false,
            };
        });

    useEffect(() => {
        let cancelled = false;
        const locationId = ghlLocationIdFromHook || liveProfile?.location_id || getAccountSettings().ghlLocationId || "";
        const cachedEmail = resolveProfileEmail(getCachedAccountProfile(locationId));
        const liveEmail = liveProfile?.location_id === locationId ? resolveProfileEmail(liveProfile) : "";

        if (liveEmail || cachedEmail) {
            setRegisteredEmail(liveEmail || cachedEmail);
        }

        const load = async () => {
            setLoading(true);
            setError(null);
            try {
                const [settings, profile] = await Promise.all([
                    fetchNotificationSettings(),
                    locationId
                        ? fetchAccountProfile(locationId, { allowStaleOnError: true })
                        : Promise.resolve(null),
                ]);

                if (cancelled) return;

                setForm(settings);
                setRegisteredEmail(
                    settings.alertEmail ||
                    resolveProfileEmail(profile) ||
                    liveEmail ||
                    cachedEmail
                );
            } catch (err) {
                if (!cancelled) {
                    setError(err instanceof Error ? err.message : "Failed to load notification settings.");
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        };

        load();
        return () => { cancelled = true; };
    }, [ghlLocationIdFromHook, liveProfile]);

    const lowBalanceEmailEnabled = form.lowBalanceAlert || form.ghlWorkflowSyncEnabled;
    const missingWorkflowEmail = lowBalanceEmailEnabled && !registeredEmail.trim();

    const handleSave = async () => {
        if (missingWorkflowEmail) {
            setError("Add a registered email in Account Details before enabling low balance email alerts.");
            return;
        }

        setSaving(true);
        setError(null);
        try {
            const payload: NotificationSettings = {
                ...form,
                deliveryReports: false,
                marketingEmails: false,
                lowBalanceAlert: lowBalanceEmailEnabled,
                ghlWorkflowSyncEnabled: lowBalanceEmailEnabled,
                alertEmail: registeredEmail,
            };
            const next = await saveNotificationSettingsRemote(payload);
            const merged = {
                ...next,
                deliveryReports: false,
                marketingEmails: false,
                lowBalanceAlert: payload.lowBalanceAlert,
                ghlWorkflowSyncEnabled: payload.ghlWorkflowSyncEnabled,
                alertEmail: next.alertEmail || registeredEmail,
            };
            saveNotificationSettingsLocal(merged);
            setForm(merged);
            setSaved(true);
            setTimeout(() => setSaved(false), 2000);
        } catch (err) {
            setError(err instanceof Error ? err.message : "Failed to save notification settings.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-5">
            <SectionHeader title="Notifications" subtitle="Choose which alerts and reports you want to receive." />

            {error && (
                <div className="flex items-start gap-3 p-4 bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/30 rounded-xl">
                    <FiAlertCircle className="w-4 h-4 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                    <p className="text-[12px] text-red-700 dark:text-red-400">{error}</p>
                </div>
            )}

            <Card>
                <div className="divide-y divide-[#f0f0f0] dark:divide-[#2a2b32]">
                    <div className="flex items-center justify-between gap-4 py-4 first:pt-0 last:pb-0">
                        <div className="flex items-start gap-3">
                            <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 flex items-center justify-center text-[#2b83fa] flex-shrink-0 mt-0.5">
                                <FiZap className="w-4 h-4" />
                            </div>
                            <div>
                                <p className="text-[14px] font-semibold text-[#111111] dark:text-[#ececf1]">Low Balance Email Alert</p>
                                <p className="text-[12px] text-[#9aa0a6]">Email the registered account owner when credits drop below the threshold.</p>
                            </div>
                        </div>
                        <Toggle checked={lowBalanceEmailEnabled} onChange={toggleLowBalanceEmail} id="toggle-low-balance-email" />
                    </div>
                </div>
            </Card>

            <Card>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-3">
                        <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 flex items-center justify-center text-[#2b83fa] flex-shrink-0 mt-0.5">
                            <FiBell className="w-4 h-4" />
                        </div>
                        <div>
                            <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider">Email Recipient</h3>
                            <p className="text-[12px] text-[#9aa0a6] mt-1">Low balance emails are sent to the registered email in Account Details.</p>
                        </div>
                    </div>
                    <div className={`px-3 py-2 rounded-xl border text-[13px] font-semibold ${registeredEmail ? "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300" : "border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300"}`}>
                        {loading ? "Loading..." : registeredEmail || "No registered email found"}
                    </div>
                </div>
                {missingWorkflowEmail && (
                    <div className="mt-4 flex items-start gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/30">
                        <FiAlertCircle className="w-4 h-4 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                        <p className="text-[12px] text-amber-700 dark:text-amber-400">Add and save an email in Account Details before enabling low balance email alerts.</p>
                    </div>
                )}
            </Card>

            {lowBalanceEmailEnabled && (
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
                <SaveButton onClick={handleSave} saved={saved} saving={saving} disabled={loading || missingWorkflowEmail} />
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

// const AR_AMOUNTS = [100, 250, 500, 1000, 2000];
// const AR_THRESHOLDS = [25, 50, 100, 200];
const API_BASE_URL = import.meta.env.VITE_API_BASE || '';
const CHECKOUT_ALLOWED_ORIGINS = new Set(['https://sms.nolawebsolutions.com', 'https://nolasmspro.com']);
const CHECKOUT_SESSION_STORAGE_KEY = 'nola_pending_checkout';
const VALID_LOCATION_ID = /^[A-Za-z0-9_-]{8,80}$/;
type CreditTransactionWithFallbacks = CreditTransaction & { timestamp?: string };

type PendingCheckoutSession = {
    state: string;
    locationId: string;
    packageCredits: number;
    createdAt: number;
};

const createCheckoutState = (): string => {
    const webCrypto: Crypto | undefined = globalThis.crypto;
    if (webCrypto?.randomUUID) {
        return webCrypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    if (webCrypto?.getRandomValues) {
        webCrypto.getRandomValues(bytes);
    } else {
        bytes.forEach((_, index) => {
            bytes[index] = Math.floor(Math.random() * 256);
        });
    }
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
};

const readPendingCheckoutSession = (): PendingCheckoutSession | null => {
    try {
        const raw = sessionSafeStorage.getItem(CHECKOUT_SESSION_STORAGE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
};

const buildSafeCheckoutUrl = (rawUrl: string): URL | null => {
    try {
        const checkoutUrl = new URL(rawUrl);
        if (checkoutUrl.protocol !== 'https:') return null;
        if (!CHECKOUT_ALLOWED_ORIGINS.has(checkoutUrl.origin)) return null;
        return checkoutUrl;
    } catch {
        return null;
    }
};

const CreditsSection: React.FC = () => {
    const ghlLocationIdFromHook = useGhlLocation();
    const liveProfile = useUserProfileContext();
    const locationId = ghlLocationIdFromHook || getAccountSettings().ghlLocationId;
    const { status: creditStatus, loading: balanceLoading, refresh: refreshCreditStatus } = useRealtimeCreditStatus(locationId);
    const [transactions, setTransactions] = useState<CreditTransaction[]>([]);
    const [txLoading, setTxLoading] = useState(true);
    const [txMonth, setTxMonth] = useState(() => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    });
    const [availableMonths, setAvailableMonths] = useState<{ val: string, label: string, count: number }[]>([]);
    const [currentPage, setCurrentPage] = useState(1);
    const [reportModalOpen, setReportModalOpen] = useState(false);
    const [reportModalMonth, setReportModalMonth] = useState(txMonth);
    const [reportDownloadLoading, setReportDownloadLoading] = useState(false);
    const [reportDownloadError, setReportDownloadError] = useState<string | null>(null);
    const itemsPerPage = 5;

    const [topUpAmount, setTopUpAmount] = useState<number | null>(null);
    const [packages, setPackages] = useState<CreditPackage[]>([]);
    const [submitted, setSubmitted] = useState(false);
    const [checkoutError, setCheckoutError] = useState<string | null>(null);
    const mountedRef = useRef(true);
    const popupRef = useRef<Window | null>(null);
    const popupPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const lastLiveBalanceRef = useRef<number | null>(null);
    const accountSettings = getAccountSettings();
    const cachedReportProfile = locationId
        ? (
            getCachedAccountProfile(locationId, { includeAuth: false, allowExpired: true }) ||
            getCachedAccountProfile(locationId, { allowExpired: true })
        )
        : null;
    const activeLiveProfile = liveProfile?.location_id === locationId ? liveProfile : null;
    const liveProfileFields = (activeLiveProfile || {}) as Partial<AccountProfile> & Record<string, string | null | undefined>;
    const cachedProfileFields = (cachedReportProfile || {}) as Partial<AccountProfile> & Record<string, string | null | undefined>;
    const creditStatusFields = (creditStatus || {}) as Record<string, string | number | null | undefined>;
    const reportAccountName = pickText(
        typeof creditStatusFields.location_name === "string" ? creditStatusFields.location_name : "",
        liveProfileFields.location_name,
        cachedProfileFields.location_name,
        accountSettings.displayName,
        locationId,
        "My Account"
    );
    const reportProfile = {
        accountName: reportAccountName,
        ownerName: pickText(joinProfileName(activeLiveProfile), joinProfileName(cachedReportProfile), accountSettings.displayName),
        email: pickText(liveProfileFields.email, liveProfileFields.email_address, cachedProfileFields.email, cachedProfileFields.email_address, accountSettings.email),
        phone: pickText(liveProfileFields.phone, liveProfileFields.phone_number, cachedProfileFields.phone, cachedProfileFields.phone_number),
        locationName: pickText(liveProfileFields.location_name, cachedProfileFields.location_name, reportAccountName),
        locationId,
        agencyName: pickText(liveProfileFields.company_name, liveProfileFields.agency_name, cachedProfileFields.company_name, cachedProfileFields.agency_name),
        companyName: pickText(liveProfileFields.company_name, liveProfileFields.agency_name, cachedProfileFields.company_name, cachedProfileFields.agency_name),
        companyId: pickText(liveProfileFields.company_id, cachedProfileFields.company_id),
        reportTitle: 'SUBACCOUNT CREDIT REPORT',
        currentBalance: creditStatus?.credit_balance ?? 0,
    };

    // AUTO_RECHARGE_DISABLED
    // const [arEnabled, setArEnabled] = useState(false);
    // const [arAmount, setArAmount] = useState(500);
    // const [arThreshold, setArThreshold] = useState(50);
    // const [arSaving, setArSaving] = useState(false);
    // const [arSaved, setArSaved] = useState(false);

    // Request credits state
    const [reqModalOpen, setReqModalOpen] = useState(false);
    const [reqAmount, setReqAmount] = useState(100);
    const [reqNote, setReqNote] = useState('');
    const [reqLoading, setReqLoading] = useState(false);
    const [reqSuccess, setReqSuccess] = useState(false);

    const clearCheckoutTimers = useCallback(() => {
        if (popupPollRef.current) {
            clearInterval(popupPollRef.current);
            popupPollRef.current = null;
        }
        if (countdownRef.current) {
            clearInterval(countdownRef.current);
            countdownRef.current = null;
        }
    }, []);

    const resetCheckoutState = useCallback((closePopup = false) => {
        clearCheckoutTimers();
        setSubmitted(false);
        sessionSafeStorage.removeItem(CHECKOUT_SESSION_STORAGE_KEY);
        if (closePopup && popupRef.current && !popupRef.current.closed) {
            try {
                popupRef.current.close();
            } catch {
                // Cross-origin popups can reject close attempts; the poller handles this.
            }
        }
        popupRef.current = null;
    }, [clearCheckoutTimers]);

    const load = useCallback(async () => {
        setTxLoading(true);
        try {
            const [txs, pkgs] = await Promise.all([
                fetchCreditTransactions('default', 500, locationId || undefined, txMonth === 'All' ? undefined : txMonth),
                fetchCreditPackages(),
            ]);
            if (!mountedRef.current) return;
            setTransactions(txs);
            setPackages(pkgs);

            // Default topUpAmount to the second package if available, else first
            if (pkgs.length > 0) {
                setTopUpAmount(pkgs[1]?.credits || pkgs[0].credits);
            }
        } catch (error) {
            devLog.error("Failed to load credit billing data", error);
        } finally {
            if (mountedRef.current) {
                setTxLoading(false);
            }
        }
    }, [locationId, txMonth]);

    const refreshCreditsView = useCallback(() => {
        void refreshCreditStatus();
        void load();
    }, [load, refreshCreditStatus]);

    useEffect(() => {
        const fetchAvailableMonths = async () => {
            const txs = await fetchCreditTransactions('default', 1000, locationId || undefined);
            const months = new Set<string>();
            const counts = new Map<string, number>();
            // Use current month as a baseline
            months.add(new Date().toISOString().slice(0, 7));

            txs.forEach(tx => {
                const rawDate = tx.created_at || (tx as CreditTransactionWithFallbacks).timestamp;
                let monthKey = '';
                if (rawDate && typeof rawDate === 'object' && 'seconds' in rawDate) {
                    const date = new Date(Number((rawDate as { seconds?: unknown }).seconds) * 1000);
                    if (!Number.isNaN(date.getTime())) {
                        monthKey = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
                    }
                } else if (rawDate) {
                    const text = String(rawDate);
                    const parsed = new Date(text);
                    monthKey = Number.isNaN(parsed.getTime())
                        ? text.slice(0, 7)
                        : `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}`;
                }

                if (monthKey) {
                    months.add(monthKey);
                    counts.set(monthKey, (counts.get(monthKey) || 0) + 1);
                }
            });

            const sorted = Array.from(months)
                .sort((a, b) => b.localeCompare(a))
                .map(m => {
                    const [y, mm] = m.split('-');
                    const d = new Date(parseInt(y), parseInt(mm) - 1);
                    return {
                        val: m,
                        label: d.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }),
                        count: counts.get(m) || 0,
                    };
                });
            setAvailableMonths([{ val: 'All', label: 'All Transactions', count: txs.length }, ...sorted]);
        };
        fetchAvailableMonths();
    }, [locationId]);

    useEffect(() => {
        if (!locationId || !creditStatus) return;

        const nextBalance = Number(creditStatus.credit_balance ?? 0);
        if (lastLiveBalanceRef.current === null) {
            lastLiveBalanceRef.current = nextBalance;
            return;
        }
        if (lastLiveBalanceRef.current === nextBalance) return;

        lastLiveBalanceRef.current = nextBalance;
        const timer = window.setTimeout(async () => {
            try {
                const txs = await fetchCreditTransactions('default', 500, locationId || undefined, txMonth === 'All' ? undefined : txMonth);
                if (mountedRef.current) {
                    setTransactions(txs);
                }
            } catch (error) {
                devLog.error("Failed to refresh credit transactions", error);
            }
        }, 250);

        return () => window.clearTimeout(timer);
    }, [creditStatus, locationId, txMonth]);

    useEffect(() => {
        mountedRef.current = true;
        load();
        window.addEventListener('sms-sent', load);
        window.addEventListener('bulk-message-sent', load);

        // Listen for payment success signal from the checkout popup
        const handlePaymentMessage = (event: MessageEvent) => {
            const data = event.data || {};
            if (data?.type !== 'nola-payment-success') return;
            if (!CHECKOUT_ALLOWED_ORIGINS.has(event.origin) && event.origin !== window.location.origin) return;
            if (!popupRef.current || event.source !== popupRef.current) return;

            const pending = readPendingCheckoutSession();
            if (!pending) return;

            const incomingState =
                typeof data.checkout_state === 'string' ? data.checkout_state :
                    typeof data.state === 'string' ? data.state :
                        '';
            const incomingLocationId =
                typeof data.location_id === 'string' ? data.location_id :
                    typeof data.locationId === 'string' ? data.locationId :
                        '';

            if (incomingState && incomingState !== pending.state) return;
            if (incomingLocationId && incomingLocationId !== pending.locationId) return;

            setCheckoutError(null);
            resetCheckoutState(true);
            refreshCreditsView();
            window.dispatchEvent(new Event("nola-notifications-refresh"));
        };
        window.addEventListener('message', handlePaymentMessage);

        return () => {
            mountedRef.current = false;
            window.removeEventListener('sms-sent', load);
            window.removeEventListener('bulk-message-sent', load);
            window.removeEventListener('message', handlePaymentMessage);
            clearCheckoutTimers();
        };
    }, [clearCheckoutTimers, load, refreshCreditsView, resetCheckoutState]);

    const totalReportEventCount = availableMonths.find(month => month.val === 'All')?.count ?? transactions.length;
    const reportModalOption = availableMonths.find(month => month.val === reportModalMonth);
    const reportModalEventCount = reportModalOption?.count ?? (reportModalMonth === txMonth ? transactions.length : 0);
    const reportModalLabel = reportModalOption?.label ?? (reportModalMonth === 'All' ? 'All Transactions' : reportModalMonth);

    const openReportDownloadModal = () => {
        setReportModalMonth(txMonth);
        setReportDownloadError(null);
        setReportModalOpen(true);
    };

    const confirmReportDownload = async () => {
        if (reportDownloadLoading || reportModalEventCount <= 0) return;

        setReportDownloadLoading(true);
        setReportDownloadError(null);
        try {
            const reportTransactions = await fetchCreditTransactions(
                'default',
                5000,
                locationId || undefined,
                reportModalMonth === 'All' ? undefined : reportModalMonth
            );

            if (reportTransactions.length === 0) {
                setReportDownloadError('No reportable events were found for the selected period. Choose another month or generate account activity first.');
                return;
            }

            generateMonthlyReport(reportModalMonth, reportTransactions, 'subaccount', reportAccountName, reportProfile);
            setReportModalOpen(false);
        } catch {
            setReportDownloadError('Failed to prepare the report. Please try again.');
        } finally {
            setReportDownloadLoading(false);
        }
    };


    const displayBalance = creditStatus?.credit_balance ?? 0;
    const trialUsed = creditStatus?.free_usage_count ?? 0;
    const trialTotal = creditStatus?.free_credits_total ?? 0;
    const isTrialActive = trialTotal > 0 && trialUsed < trialTotal;
    const trialLeft = trialTotal - trialUsed;
    const usagePercent = Math.min(100, (displayBalance / 1000) * 100);
    const usageColor = displayBalance < 50 ? 'bg-red-500' : displayBalance < 200 ? 'bg-amber-400' : 'bg-emerald-500';

    const sentToday = creditStatus?.stats?.sent_today ?? 0;
    const creditsUsedToday = creditStatus?.stats?.credits_used_today ?? 0;
    const creditsUsedMonth = creditStatus?.stats?.credits_used_month ?? 0;

    // AUTO_RECHARGE_DISABLED
    // const saveAutoRecharge = async () => {
    //     setArSaving(true);
    //     try {
    //         await apiFetch(`${API_BASE_URL}/api/billing/subaccount_wallet.php`, {
    //             method: 'POST',
    //             headers: { 'Content-Type': 'application/json' },
    //             credentials: 'include',
    //             body: JSON.stringify({ action: 'set_auto_recharge', location_id: locationId, enabled: arEnabled, amount: arAmount, threshold: arThreshold }),
    //         });
    //         setArSaved(true); setTimeout(() => setArSaved(false), 2000);
    //     } catch { /* silently handled */ } finally { setArSaving(false); }
    // };

    // ── Submit credit request ─────────────────────────────────────────────────
    const submitCreditRequest = async () => {
        if (reqAmount <= 0) return;
        setReqLoading(true);
        try {
            const res = await apiFetch(`${API_BASE_URL}/api/billing/subaccount_wallet.php`, {
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


    const handleTopUp = async (e: React.FormEvent) => {
        e.preventDefault();
        setCheckoutError(null);

        const selectedPackage = packages.find(p => p.credits === topUpAmount);
        if (!selectedPackage) return;

        const checkoutUrl = buildSafeCheckoutUrl(selectedPackage.link);
        if (!checkoutUrl) {
            setCheckoutError('This checkout link is not trusted. Please contact support before paying.');
            return;
        }

        const pick = (...vals: (string | undefined | null)[]) =>
            vals.find(v => typeof v === 'string' && v.trim() !== '')?.trim() || '';

        const session = getSession();
        if (session?.role && session.role !== 'user') {
            setCheckoutError('This payment window is only available for user accounts.');
            return;
        }

        // ── Resolve location_id ───────────────────────────────────────────────
        const resolvedLocationId = pick(
            locationId,
            liveProfile?.location_id,
            session?.locationId
        );

        if (!resolvedLocationId || !VALID_LOCATION_ID.test(resolvedLocationId)) {
            setCheckoutError('Open this app from a valid subaccount before buying credits.');
            return;
        }

        if (session?.locationId && session.locationId !== resolvedLocationId) {
            setCheckoutError('Your checkout session does not match the active subaccount. Refresh and try again.');
            return;
        }

        const width = Math.max(320, Math.min(620, window.screen.availWidth - 32));
        const height = Math.max(520, Math.min(860, window.screen.availHeight - 32));
        const left = Math.max(16, (window.screen.availWidth / 2) - (width / 2));
        const top = Math.max(16, (window.screen.availHeight / 2) - (height / 2));

        const popup = window.open(
            '',
            'NolaSecureCheckout',
            `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes,popup=yes`
        );

        if (!popup) {
            setCheckoutError('Checkout window was blocked. Please allow popups for this site and try again.');
            return;
        }

        popupRef.current = popup;
        setSubmitted(true);

        try {
            popup.document.write(`
                <!doctype html>
                <title>Preparing checkout</title>
                <style>
                    body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Inter,Arial,sans-serif;background:#f7f8fc;color:#111}
                    .box{display:flex;align-items:center;gap:12px;padding:18px 20px;border:1px solid #e5e7eb;border-radius:16px;background:white;box-shadow:0 18px 45px rgba(15,23,42,.12)}
                    .spin{width:18px;height:18px;border:3px solid #dbeafe;border-top-color:#2b83fa;border-radius:999px;animation:s 1s linear infinite}
                    @keyframes s{to{transform:rotate(360deg)}}
                </style>
                <div class="box"><div class="spin"></div><strong>Preparing secure checkout...</strong></div>
            `);
        } catch {
            // Some browsers block writing to the placeholder window; navigation still works.
        }

        // ── Resolve profile fields ────────────────────────────────────────────
        // Prefer /api/account so checkout matches the Account Details panel.
        let accountProfile: Awaited<ReturnType<typeof fetchAccountProfile>> = null;
        try {
            accountProfile = await fetchAccountProfile(resolvedLocationId);
        } catch {
            accountProfile = null;
        }

        if (!accountProfile?.location_id || accountProfile.location_id !== resolvedLocationId) {
            resetCheckoutState(true);
            setCheckoutError('We could not verify this subaccount for checkout. Please refresh and try again.');
            return;
        }

        // ── Build query string ────────────────────────────────────────────────
        const readCachedProfile = (): Record<string, string | null | undefined> => {
            try {
                const authUser = JSON.parse(safeStorage.getItem('nola_auth_user') || 'null') || {};
                const nolaUser = JSON.parse(safeStorage.getItem('nola_user') || 'null') || {};
                return { ...authUser, ...nolaUser };
            } catch {
                return {};
            }
        };
        const profileFields = accountProfile as unknown as Record<string, string | null | undefined>;
        const liveProfileFields = (liveProfile || {}) as Record<string, string | null | undefined>;
        const sessionUserFields = (session?.user || {}) as Record<string, string | null | undefined>;
        const cachedFields = readCachedProfile();
        const joinedLiveName = [liveProfile?.firstName, liveProfile?.lastName].filter(Boolean).join(' ');
        const fullName = pick(
            profileFields.full_name,
            profileFields.name,
            liveProfileFields.full_name,
            liveProfileFields.name,
            joinedLiveName,
            sessionUserFields.full_name,
            sessionUserFields.name,
            cachedFields.full_name,
            cachedFields.name
        );
        const nameParts = fullName.split(/\s+/).filter(Boolean);
        const firstName = pick(
            profileFields.firstName,
            liveProfileFields.firstName,
            sessionUserFields.firstName,
            cachedFields.firstName,
            nameParts[0]
        );
        const lastName = pick(
            profileFields.lastName,
            liveProfileFields.lastName,
            sessionUserFields.lastName,
            cachedFields.lastName,
            nameParts.length > 1 ? nameParts.slice(1).join(' ') : ''
        );
        const email = pick(
            profileFields.email,
            profileFields.email_address,
            liveProfileFields.email,
            sessionUserFields.email,
            cachedFields.email,
            cachedFields.email_address
        );
        const phone = pick(
            profileFields.phone,
            profileFields.phone_number,
            liveProfileFields.phone,
            sessionUserFields.phone,
            cachedFields.phone,
            cachedFields.phone_number
        );

        const checkoutState = createCheckoutState();
        const pending: PendingCheckoutSession = {
            state: checkoutState,
            locationId: resolvedLocationId,
            packageCredits: selectedPackage.credits,
            createdAt: Date.now(),
        };
        sessionSafeStorage.setItem(CHECKOUT_SESSION_STORAGE_KEY, JSON.stringify(pending));

        checkoutUrl.searchParams.set('location_id', resolvedLocationId);
        [
            ['name', fullName],
            ['full_name', fullName],
            ['first_name', firstName],
            ['last_name', lastName],
            ['email', email],
            ['phone', phone],
        ].forEach(([key, value]) => {
            if (value) checkoutUrl.searchParams.set(key, value);
        });
        checkoutUrl.searchParams.set('checkout_state', checkoutState);
        popup.location.href = checkoutUrl.toString();

        clearCheckoutTimers();
        popupPollRef.current = setInterval(() => {
            try {
                if (popup.closed) {
                    resetCheckoutState(false);
                    load();
                }
            } catch {
                // Ignore cross-origin DOM exceptions.
            }
        }, 750);
    };

    return (
        <div className="space-y-5">
            <SectionHeader title="Credits & Billing" subtitle="Monitor your SMS credit balance and request credits from your agency." />
            {reportModalOpen && (
                <div className="fixed inset-0 z-[1000] flex items-center justify-center overflow-y-auto p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl w-full max-w-lg max-h-[92vh] shadow-2xl animate-in zoom-in-95 duration-200 overflow-hidden flex flex-col">
                        <div className="px-6 py-5 border-b border-[#e5e5e5] dark:border-white/10 flex items-start justify-between gap-4">
                            <div className="flex items-center gap-2.5 min-w-0">
                                <div className="w-9 h-9 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                                    <FiDownload className="w-4 h-4" />
                                </div>
                                <div className="min-w-0">
                                    <h3 className="text-[17px] font-bold text-[#111111] dark:text-[#ececf1] leading-tight">Download Report</h3>
                                    <p className="text-[12px] font-medium text-[#9aa0a6] mt-0.5 truncate">{reportAccountName}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReportModalOpen(false)}
                                className="p-2 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors"
                                aria-label="Close report download modal"
                            >
                                <FiX className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto p-4 space-y-5 sm:p-6 custom-scrollbar">
                            {totalReportEventCount === 0 ? (
                                <div className="py-8 flex flex-col items-center justify-center gap-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5 text-center">
                                    <div className="w-11 h-11 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                                        <FiDownload className="w-5 h-5" />
                                    </div>
                                    <div>
                                        <p className="text-[14px] font-bold text-[#111111] dark:text-[#ececf1]">No reportable events yet</p>
                                        <p className="text-[12px] text-[#9aa0a6] mt-1">Send SMS, top up credits, or choose another period before downloading a PDF report.</p>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                                            <p className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Total Events</p>
                                            <p className="text-[22px] font-black text-[#111111] dark:text-[#ececf1] mt-1">{totalReportEventCount.toLocaleString()}</p>
                                        </div>
                                        <div className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                                            <p className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Selected Period</p>
                                            <p className="text-[13px] font-black text-[#111111] dark:text-[#ececf1] mt-1 truncate">{reportModalLabel}</p>
                                            <p className="text-[11px] font-semibold text-[#9aa0a6] mt-1">{reportModalEventCount.toLocaleString()} events</p>
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <label className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Report Period</label>
                                        <div className="relative">
                                            <select
                                                value={reportModalMonth}
                                                onChange={(event) => {
                                                    setReportModalMonth(event.target.value);
                                                    setReportDownloadError(null);
                                                }}
                                                className="w-full appearance-none pl-3.5 pr-10 py-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#d8dce3] dark:border-white/10 text-[13px] font-bold text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all cursor-pointer"
                                            >
                                                {availableMonths.map(month => (
                                                    <option key={month.val} value={month.val}>
                                                        {month.label} ({month.count} events)
                                                    </option>
                                                ))}
                                            </select>
                                            <FiChevronDown className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[#9aa0a6] pointer-events-none" />
                                        </div>
                                    </div>

                                    {reportDownloadError && (
                                        <div className="flex items-start gap-2 rounded-xl border border-red-200/70 bg-red-50 px-3 py-2 text-[12px] font-semibold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                                            <FiAlertCircle className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                                            {reportDownloadError}
                                        </div>
                                    )}

                                    <button
                                        type="button"
                                        onClick={confirmReportDownload}
                                        disabled={reportDownloadLoading || reportModalEventCount <= 0}
                                        className="w-full flex items-center justify-center gap-2 px-5 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] text-white rounded-xl text-[13px] font-bold transition-all shadow-md shadow-blue-500/20 active:scale-[0.98] disabled:opacity-45 disabled:cursor-not-allowed disabled:hover:shadow-none"
                                    >
                                        {reportDownloadLoading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiDownload className="w-4 h-4" />}
                                        {reportDownloadLoading ? 'Preparing PDF...' : reportModalEventCount > 0 ? 'Download PDF' : 'No events to download'}
                                    </button>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}
            {/* ── Request Credits Modal ──────────────────────────────────────── */}
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
                                        placeholder="Or type a custom amount…" />
                                </div>
                                <div>
                                    <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Note (optional)</label>
                                    <input type="text" value={reqNote} onChange={e => setReqNote(e.target.value)}
                                        placeholder="E.g. Need credits for weekend campaign…"
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
                                        {reqLoading ? 'Sending…' : 'Send Request'}
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
                        <p className="text-[12px] text-white/60 mt-1">1 credit ≈ 1 SMS (160 chars)</p>
                    </div>
                    <div className="flex flex-col items-center gap-2 flex-shrink-0">
                        <div className="w-12 h-12 rounded-xl bg-white/10 flex items-center justify-center">
                            <FiCreditCard className="w-6 h-6 text-white" />
                        </div>
                        <button
                            onClick={refreshCreditsView}
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
            {/* AUTO_RECHARGE_DISABLED
            <Card>
                <div className="flex flex-wrap items-center gap-3">
                    <button
                        onClick={() => {}}
                        className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0 focus:outline-none bg-gray-200 dark:bg-[#3a3b3f]`}
                    >
                        <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform translate-x-1`} />
                    </button>
                    <span className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a9] font-medium">Auto recharge with</span>
                    <div className="relative">
                        <select value={500} onChange={() => {}} disabled={true}
                            className="pl-3 pr-7 py-1 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[12.5px] font-bold text-[#111111] dark:text-white appearance-none focus:outline-none disabled:opacity-50">
                            <option value={500}>500 credits</option>
                        </select>
                        <FiChevronDown className="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
                    </div>
                    <span className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a9] font-medium">when below</span>
                    <div className="relative">
                        <select value={50} onChange={() => {}} disabled={true}
                            className="pl-3 pr-7 py-1 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[12.5px] font-bold text-[#111111] dark:text-white appearance-none focus:outline-none disabled:opacity-50">
                            <option value={50}>50 credits</option>
                        </select>
                        <FiChevronDown className="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
                    </div>
                    <button onClick={() => {}} disabled={true}
                        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold transition-all disabled:opacity-50 bg-[#f0f2f8] dark:bg-[#1c1e21] text-[#6e6e73] dark:text-[#9aa0a9] border border-[#e0e0e0] dark:border-[#ffffff0a]`}>
                        <FiCheck className="w-3.5 h-3.5" />
                        Save
                    </button>
                </div>
            </Card>
            */}
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
                    {balanceLoading || packages.length === 0 || topUpAmount === null ? (
                        <div className="grid grid-cols-6 gap-3">
                            {Array.from({ length: 5 }).map((_, idx) => {
                                const colSpan = idx < 3 ? 'col-span-6 sm:col-span-2' : 'col-span-6 sm:col-span-3';
                                return (
                                    <div
                                        key={idx}
                                        className={`flex flex-col items-center py-4 px-3 rounded-xl border border-[#e5e5e5] dark:border-[#2a2b32] animate-pulse space-y-2.5 ${colSpan} bg-gray-50/50 dark:bg-[#1a1b1e]/30`}
                                    >
                                        <div className="h-4 bg-gray-200 dark:bg-[#2a2b32] rounded w-16" />
                                        <div className="h-3 bg-gray-100 dark:bg-[#1e1f22] rounded w-10" />
                                        <div className="h-4 bg-gray-200 dark:bg-[#2a2b32] rounded w-12 mt-1" />
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="grid grid-cols-6 gap-3">
                            {packages.map((pkg, idx) => {
                                const colSpan = idx < 3 ? 'col-span-6 sm:col-span-2' : 'col-span-6 sm:col-span-3';
                                const isSelected = topUpAmount === pkg.credits;
                                const bonusCredits = pkg.credits - pkg.price;
                                const bonusPercent = pkg.price > 0 ? Math.round((bonusCredits / pkg.price) * 100) : 0;

                                return (
                                    <button
                                        key={pkg.credits}
                                        type="button"
                                        onClick={() => setTopUpAmount(pkg.credits)}
                                        className={`relative flex flex-col items-center py-4 px-3 rounded-xl border-2 transition-all duration-300 ease-out transform hover:scale-[1.02] active:scale-[0.98] ${isSelected
                                            ? 'border-[#2b83fa] bg-gradient-to-br from-[#2b83fa]/10 to-[#2b83fa]/5 dark:from-[#2b83fa]/20 dark:to-[#2b83fa]/5 shadow-[0_0_15px_rgba(43,131,250,0.15)] dark:shadow-[0_0_20px_rgba(43,131,250,0.25)]'
                                            : 'border-[#e0e0e0] dark:border-[#2a2b32] bg-white dark:bg-[#1a1b1e]/50 hover:border-[#2b83fa]/50 hover:bg-gray-50/50 dark:hover:bg-[#2b832]/30'
                                            } ${colSpan}`}
                                    >
                                        {/* Dynamic premium badge for bonus percentage */}
                                        {bonusPercent > 0 && (
                                            <span className="absolute -top-2.5 -right-1.5 bg-gradient-to-r from-amber-500 to-orange-500 text-white text-[9.5px] font-black px-2 py-0.5 rounded-full shadow-md uppercase tracking-wider">
                                                +{bonusPercent}% Bonus
                                            </span>
                                        )}
                                        <span className={`text-[17px] font-black tracking-tight ${isSelected ? 'text-[#2b83fa]' : 'text-[#111111] dark:text-[#ececf1]'}`}>
                                            {pkg.credits?.toLocaleString() || pkg.credits}
                                        </span>
                                        <span className="text-[10px] text-[#9aa0a6] font-semibold mt-0.5">credits</span>
                                        <span className={`text-[13px] font-bold mt-1.5 px-3 py-0.5 rounded-lg ${isSelected
                                            ? 'bg-[#2b83fa] text-white'
                                            : 'bg-gray-100 dark:bg-[#2a2b32] text-[#6e6e73] dark:text-[#94959b]'
                                            }`}>
                                            ₱{pkg.price?.toLocaleString() || pkg.price}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    {checkoutError && (
                        <div className="rounded-xl border border-red-200/70 bg-red-50 px-3 py-2.5 text-[12.5px] font-semibold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                            {checkoutError}
                        </div>
                    )}
                    {balanceLoading || packages.length === 0 || topUpAmount === null ? (
                        <div className="w-full h-12 bg-gray-200 dark:bg-[#2a2b32] rounded-xl animate-pulse flex items-center justify-center">
                            <div className="h-4 bg-gray-300 dark:bg-[#3a3b3f] rounded w-40" />
                        </div>
                    ) : submitted ? (
                        <div className="flex flex-col items-center justify-center gap-2 w-full">
                            <div className="w-full flex items-center justify-center gap-2 py-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl text-emerald-600 dark:text-emerald-400 font-semibold text-[13px]">
                                <FiCheck className="w-4 h-4" /> Checkout window opened
                            </div>
                            <button
                                type="button"
                                onClick={() => {
                                    resetCheckoutState(false);
                                    load();
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
                            onClick={openReportDownloadModal}
                            disabled={txLoading || totalReportEventCount === 0}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-[#ffffff] border border-transparent hover:bg-[#f3f4f6] dark:hover:bg-[#1f2023] disabled:opacity-50 transition-all font-inter"
                        >
                            <FiDownload className="w-3.5 h-3.5" /> Download Report
                        </button>
                    </div>
                </div>

                {txLoading ? (
                    /* Skeleton rows */
                    (<div className="space-y-3">
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
                    </div>)
                ) : transactions.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 gap-3 text-center">
                        <div className="w-12 h-12 rounded-xl bg-[#2b83fa]/10 flex items-center justify-center">
                            <FiCreditCard className="w-6 h-6 text-[#2b83fa]/60" />
                        </div>
                        <p className="text-[14px] font-semibold text-[#37352f] dark:text-[#ececf1]">No credit activity yet</p>
                        <p className="text-[12px] text-[#9aa0a6] max-w-xs">Send SMS, top up your balance, or receive an adjustment to start building this ledger.</p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-0 min-h-[250px]">
                            {transactions.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage).map((tx) => {
                                const isAdjustment = tx.type === 'manual_adjustment' || tx.type === 'admin_adjustment' || tx.type === 'agency_adjustment';
                                const isCredit = tx.type === 'top_up' || tx.type === 'refund' || tx.type === 'credit_purchase' || (isAdjustment && tx.amount >= 0);
                                const sign = isCredit ? '+' : '−';
                                const absAmount = Math.abs(tx.amount);

                                let displayDescription = tx.description;
                                if (isAdjustment) {
                                    displayDescription = `Manual credit adjustment (Applied ${tx.amount >= 0 ? '+' : '-'}${absAmount} credits)`;
                                }

                                return (
                                    <div key={tx.transaction_id} className="flex items-center gap-3 py-2.5 border-b border-[#f0f0f0] dark:border-[#2a2b32] last:border-0 hover:bg-gray-50 dark:hover:bg-white/5 px-2 rounded-xl transition-colors">
                                        <div className={`w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-[11px] font-bold ${isCredit ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400' : 'bg-red-50 dark:bg-red-900/20 text-red-500'}`}>
                                            {sign}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[13px] font-medium text-[#111111] dark:text-[#ececf1] truncate">{displayDescription}</p>
                                            <p className="text-[11px] text-[#9aa0a6]">{formatTxDate(tx.created_at)}</p>
                                            {(tx.reference_id || tx.transaction_reference_id) && (
                                                <p className="text-[10px] font-mono text-[#9aa0a6] truncate">Ref: {tx.reference_id || tx.transaction_reference_id}</p>
                                            )}
                                        </div>
                                        <div className="flex flex-col items-end flex-shrink-0">
                                            <span className={`text-[13px] font-bold ${isCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'}`}>
                                                {absAmount === 0 && !isCredit ? '−1 free trial' : `${sign}${absAmount?.toLocaleString() || absAmount} credits`}
                                            </span>
                                            <span className="text-[10px] text-[#9aa0a6]">balance: {tx.balance_after?.toLocaleString() || tx.balance_after || 0}</span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Pagination Controls */}
                        {transactions.length > itemsPerPage && (() => {
                            const totalPages = Math.ceil(transactions.length / itemsPerPage);

                            const getPageNumbers = () => {
                                const pages = [];
                                const maxVisiblePages = 5;

                                if (totalPages <= maxVisiblePages) {
                                    for (let i = 1; i <= totalPages; i++) pages.push(i);
                                } else {
                                    if (currentPage <= 3) {
                                        for (let i = 1; i <= 4; i++) pages.push(i);
                                        pages.push('...');
                                        pages.push(totalPages);
                                    } else if (currentPage >= totalPages - 2) {
                                        pages.push(1);
                                        pages.push('...');
                                        for (let i = totalPages - 3; i <= totalPages; i++) pages.push(i);
                                    } else {
                                        pages.push(1);
                                        pages.push('...');
                                        pages.push(currentPage - 1);
                                        pages.push(currentPage);
                                        pages.push(currentPage + 1);
                                        pages.push('...');
                                        pages.push(totalPages);
                                    }
                                }
                                return pages;
                            };

                            return (
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between mt-6 pt-4 border-t border-[#f0f0f0] dark:border-[#2a2b32] gap-4">
                                    <span className="text-[12px] font-medium text-[#9aa0a6] text-center sm:text-left">
                                        Showing {(currentPage - 1) * itemsPerPage + 1} to {Math.min(currentPage * itemsPerPage, transactions.length)} of {transactions.length}
                                    </span>
                                    <div className="flex items-center justify-center gap-1.5">
                                        <button
                                            onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                                            disabled={currentPage === 1}
                                            className="p-1.5 rounded-lg border border-[#e5e5e5] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#94959b] hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                        >
                                            <FiChevronLeft className="w-4 h-4" />
                                        </button>

                                        {getPageNumbers().map((page, idx) => (
                                            page === '...' ? (
                                                <span key={`ellipsis-${idx}`} className="px-1 text-[12px] font-medium text-[#9aa0a6]">...</span>
                                            ) : (
                                                <button
                                                    key={`page-${page}`}
                                                    onClick={() => setCurrentPage(page as number)}
                                                    className={`min-w-[28px] h-7 rounded-lg text-[12px] font-bold transition-all ${currentPage === page
                                                        ? 'bg-[#2b83fa] text-white border border-[#2b83fa]'
                                                        : 'border border-[#e5e5e5] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#94959b] hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32]'
                                                        }`}
                                                >
                                                    {page}
                                                </button>
                                            )
                                        ))}

                                        <button
                                            onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                                            disabled={currentPage === totalPages}
                                            className="p-1.5 rounded-lg border border-[#e5e5e5] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#94959b] hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                        >
                                            <FiChevronRight className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            );
                        })()}
                    </>
                )}
            </Card>
        </div>
    );
};

// ─── Main Export ─────────────────────────────────────────────────────────────
export const Settings: React.FC<SettingsProps> = ({ initialTab, autoOpenAddModal }) => {
    const navigate = useNavigate();
    const location = useLocation();
    const activeTab: SettingsTab = initialTab || "account";

    const handleTabSelect = (tab: SettingsTab) => {
        navigate({ pathname: SETTINGS_TAB_ROUTES[tab], search: location.search }, { replace: false });
    };

    const renderContent = useCallback(() => {
        switch (activeTab) {
            case "account": return <AccountSection />;
            case "senderIds": return <SenderIdsSection autoOpenAddModal={autoOpenAddModal && activeTab === "senderIds"} />;
            case "notifications": return <NotificationsSection />;
            case "credits": return <CreditsSection />;
        }
    }, [activeTab, autoOpenAddModal]);

    const activeTabInfo = TABS.find(t => t.id === activeTab)!;

    return (
        <div className="h-full flex flex-col overflow-hidden bg-[#f3f4f6] dark:bg-[#09090b]">
            {/* Page Header */}
            <div className="flex-shrink-0 bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] rounded-b-[40px] shadow-[0_18px_45px_rgba(29,107,212,0.24)]">
                <div className="max-w-5xl mx-auto px-3 md:px-6 pt-5 pb-5">
                    <div className="flex items-center gap-3 mb-5">
                        <div className="w-10 h-10 rounded-full bg-white/20 border border-white/20 flex items-center justify-center text-white shadow-md shadow-blue-950/10">
                            <FiSettings className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-[22px] font-extrabold text-white tracking-tight">Settings</h1>
                            <p className="text-[12px] text-white/75">{activeTabInfo.description}</p>
                        </div>
                    </div>

                    <nav className="overflow-x-auto custom-scrollbar pb-1">
                        <div className="flex gap-2 min-w-max">
                            {TABS.map(tab => {
                                const isActive = activeTab === tab.id;
                                return (
                                    <button
                                        key={tab.id}
                                        onClick={() => handleTabSelect(tab.id)}
                                        className={`flex items-center gap-2 px-3 sm:px-4 py-2.5 rounded-xl border transition-all duration-200 text-left whitespace-nowrap ${isActive
                                            ? "bg-white text-[#1d6bd4] border-white shadow-sm"
                                            : "bg-white/10 text-white border-white/20 hover:bg-white/20"
                                            }`}
                                    >
                                        <span className="text-[15px] flex-shrink-0">{tab.icon}</span>
                                        <span className="text-[13px] font-bold">{tab.label}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </nav>
                </div>
            </div>

            {/* Content Panel */}
            <main className="flex-1 min-h-0 overflow-y-auto px-4 pb-4 pt-5 md:px-6 md:pb-6 md:pt-6 lg:px-8 lg:pb-8 lg:pt-6 custom-scrollbar">
                <div className="max-w-5xl mx-auto">
                    {renderContent()}
                </div>
            </main>
        </div>
    );
};
