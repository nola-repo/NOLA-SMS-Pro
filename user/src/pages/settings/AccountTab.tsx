import React, { useState, useCallback, useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import {
    FiSave, FiCheck, FiMapPin, FiCheckCircle, FiAlertCircle,
    FiRefreshCw, FiExternalLink, FiEdit3, FiLock, FiX, FiMoreVertical, FiEye, FiEyeOff
} from "react-icons/fi";
import {
    getAccountSettings, saveAccountSettings,
    type AccountSettings
} from "../../utils/settingsStorage";
import { useGhlLocation } from "../../hooks/useGhlLocation";
import { fetchAccountProfile, getCachedAccountProfile, updateAccountProfile } from "../../api/account";
import type { AccountProfile } from "../../api/account";
import { useUserProfileContext } from "../../context/UserProfileContext";
import {
    GHL_MARKETPLACE_CONNECT_URL,
    GHL_OAUTH_RETURN_VIEW_STORAGE_KEY,
    GHL_RECONNECT_REQUIRED_STORAGE_KEY
} from "../../config";
import { safeStorage } from "../../utils/safeStorage";
import { apiFetch } from "../../utils/apiFetch";
import type { SenderDisplayStatus } from "./types";

const STATUS_CONFIG: Record<SenderDisplayStatus, { label: string; color: string; bg: string; icon: React.ReactElement }> = {
    fallback: { label: "System Default / Fallback Sender", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-50 dark:bg-blue-900/20", icon: <FiMapPin className="w-3 h-3" /> },
    active: { label: "Approved \u00b7 Active Sender", color: "text-emerald-600 dark:text-emerald-400", bg: "bg-emerald-50 dark:bg-emerald-900/20", icon: <FiCheck className="w-3 h-3" /> },
    approved: { label: "Approved", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-50 dark:bg-blue-900/20", icon: <FiCheckCircle className="w-3 h-3" /> },
    pending: { label: "Pending", color: "text-amber-600 dark:text-amber-400", bg: "bg-amber-50 dark:bg-amber-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
    rejected: { label: "Rejected", color: "text-red-600 dark:text-red-400", bg: "bg-red-50 dark:bg-red-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
    revoked: { label: "Revoked", color: "text-slate-600 dark:text-slate-400", bg: "bg-slate-100 dark:bg-white/10", icon: <FiAlertCircle className="w-3 h-3" /> },
    configuration_mismatch: { label: "Configuration mismatch", color: "text-orange-700 dark:text-orange-300", bg: "bg-orange-50 dark:bg-orange-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
};

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

const Skeleton: React.FC<{ className?: string }> = ({ className = "" }) => (
    <div className={`animate-pulse bg-[#f0f0f0] dark:bg-white/5 rounded-lg ${className}`} />
);

export const AccountTab: React.FC = () => {
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

    const applyAccountProfile = useCallback((profile: AccountProfile) => {
        setFetchedProfile(profile);

        const nextLocationName =
            profile.location_name && profile.location_name !== "Unknown"
                ? profile.location_name
                : null;

        if (nextLocationName) {
            setFetchedName(nextLocationName);
        }

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
    };

    useEffect(() => {
        const activeLocation = ghlLocationIdFromHook || liveProfile?.location_id;
        if (activeLocation && activeLocation !== lastLoadedLocationRef.current) {
            setInputLocationId(activeLocation);
            fetchAndSetLocation(activeLocation);
        }
    }, [ghlLocationIdFromHook, liveProfile?.location_id]);

    useEffect(() => {
        if (initialLocationIdRef.current) {
            fetchAndSetLocation(initialLocationIdRef.current);
        }
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

    const subaccountName = (fetchedName && fetchedName !== "Location Not Found")
        ? fetchedName
        : ((liveProfile?.location_id === inputLocationId && liveProfile?.location_name) || form.displayName || "Not Found");
    const statusCfg = STATUS_CONFIG[form.accountStatus];
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

            <div className={`flex items-center gap-2.5 px-4 py-3 rounded-xl border ${statusCfg.bg} border-transparent`}>
                <span className={statusCfg.color}>{statusCfg.icon}</span>
                <span className={`text-[13px] font-semibold ${statusCfg.color}`}>
                    Workspace Status: {statusCfg.label}
                </span>
            </div>

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
                            <FiExternalLink className="w-4 h-4" />
                            Reconnect GoHighLevel
                        </button>
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">
                            GHL Subaccount Location ID
                        </label>
                        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                            <input
                                type="text"
                                value={inputLocationId}
                                onChange={(e) => setInputLocationId(e.target.value)}
                                placeholder="Enter location_id"
                                className="flex-1 h-12 px-4 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-mono font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 focus:border-[#2b83fa]/60 transition-all"
                            />
                            <button
                                onClick={handleSaveLocation}
                                disabled={isFetchingLocation || !inputLocationId.trim() || inputLocationId === resolvedLocationId}
                                className="h-12 px-5 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[13px] font-black hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed transition-all shrink-0"
                            >
                                {isFetchingLocation ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSave className="w-4 h-4" />}
                                {isFetchingLocation ? "Fetching..." : "Fetch Profile"}
                            </button>
                        </div>
                        <p className="text-[11px] text-[#9aa0a6] mt-1.5">
                            Active location ID: <span className="font-mono text-[#6e6e73] dark:text-[#ececf1]">{resolvedLocationId || 'None'}</span>
                        </p>
                    </div>
                </div>
            </Card>

            {passwordPanelOpen && typeof document !== 'undefined' && createPortal((
                <div
                    className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/55 px-4 py-6 backdrop-blur-md"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) closePasswordModal();
                    }}
                >
                    <div className="w-full max-w-lg overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white shadow-2xl dark:border-white/10 dark:bg-[#1a1b1e]">
                        <div className="flex items-start justify-between gap-4 border-b border-[#f0f0f0] p-5 dark:border-[#ffffff08]">
                            <div className="flex items-start gap-3">
                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#2b83fa]/10 text-[#2b83fa]">
                                    <FiLock className="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 className="text-[16px] font-black text-[#111111] dark:text-white">Change Password</h3>
                                    <p className="mt-1 text-[12px] leading-relaxed text-[#6e6e73] dark:text-[#9aa0a6]">
                                        {passwordStep === "send_code" && "We will send a reset code to your email."}
                                        {passwordStep === "enter_code" && "Enter the verification code sent to your email."}
                                        {passwordStep === "change_password" && "Enter your new password below."}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={closePasswordModal}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-[#6e6e73] transition-colors hover:bg-[#f5f5f6] hover:text-red-500 dark:text-[#9aa0a6] dark:hover:bg-white/5"
                                aria-label="Close change password modal"
                            >
                                <FiX className="h-4 w-4" />
                            </button>
                        </div>

                        <div className="space-y-4 p-5">
                            {passwordStatus && (
                                <div className={`flex items-center gap-2 rounded-xl border px-3.5 py-2.5 text-[12px] font-semibold ${passwordStatus.type === "success"
                                    ? "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20"
                                    : passwordStatus.type === "error"
                                        ? "bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/20"
                                        : "bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:border-blue-500/20"
                                    }`}>
                                    {passwordStatus.type === "success" ? <FiCheck className="w-4 h-4 shrink-0" /> : <FiAlertCircle className="w-4 h-4 shrink-0" />}
                                    {passwordStatus.message}
                                </div>
                            )}

                            {passwordStep === "send_code" && (
                                <div className="space-y-4">
                                    <div className="p-4 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a]">
                                        <p className="text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1">Reset Code Recipient</p>
                                        <p className="text-[13px] font-semibold text-[#111111] dark:text-[#ececf1]">{profileForm.email || displayEmail}</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={requestPasswordOtp}
                                        disabled={passwordBusy || !profileForm.email.trim()}
                                        className="w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[13px] font-bold shadow-md hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] disabled:opacity-50 transition-all"
                                    >
                                        {passwordBusy ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : null}
                                        {passwordBusy ? "Sending Code..." : "Send Verification Code"}
                                    </button>
                                </div>
                            )}

                            {passwordStep === "enter_code" && (
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">
                                            Verification Code
                                        </label>
                                        <input
                                            type="text"
                                            value={passwordForm.otp}
                                            onChange={e => setPasswordForm(prev => ({ ...prev, otp: e.target.value }))}
                                            placeholder="Enter 6-digit code"
                                            maxLength={8}
                                            className="h-11 w-full rounded-xl border border-transparent bg-[#f5f5f6] px-4 text-[13px] font-semibold text-[#111111] placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 dark:border-[#ffffff0a] dark:bg-[#0d0e10] dark:text-[#ececf1]"
                                        />
                                    </div>
                                    <div className="flex items-center justify-between gap-3">
                                        <button
                                            type="button"
                                            onClick={requestPasswordOtp}
                                            disabled={passwordBusy}
                                            className="text-[12px] font-bold text-[#2b83fa] hover:underline disabled:opacity-50"
                                        >
                                            Resend Code
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handlePasswordCodeContinue}
                                            disabled={!passwordForm.otp.trim()}
                                            className="h-11 px-6 inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[13px] font-bold shadow-md hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] disabled:opacity-50 transition-all"
                                        >
                                            Continue
                                        </button>
                                    </div>
                                </div>
                            )}

                            {passwordStep === "change_password" && (
                                <div className="space-y-4">
                                    <div className="relative">
                                        <input
                                            type={showNewPassword ? "text" : "password"}
                                            value={passwordForm.newPassword}
                                            onChange={e => setPasswordForm(prev => ({ ...prev, newPassword: e.target.value }))}
                                            placeholder="New password (min 8 chars)"
                                            minLength={8}
                                            className="h-11 w-full rounded-xl border border-transparent bg-[#f5f5f6] px-4 pr-10 text-[13px] font-semibold text-[#111111] placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 dark:border-[#ffffff0a] dark:bg-[#0d0e10] dark:text-[#ececf1]"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowNewPassword(prev => !prev)}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#2b83fa]"
                                        >
                                            {showNewPassword ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                                        </button>
                                    </div>
                                    <div className="relative">
                                        <input
                                            type={showConfirmPassword ? "text" : "password"}
                                            value={passwordForm.confirmPassword}
                                            onChange={e => setPasswordForm(prev => ({ ...prev, confirmPassword: e.target.value }))}
                                            placeholder="Confirm new password"
                                            minLength={8}
                                            className="h-11 w-full rounded-xl border border-transparent bg-[#f5f5f6] px-4 pr-10 text-[13px] font-semibold text-[#111111] placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 dark:border-[#ffffff0a] dark:bg-[#0d0e10] dark:text-[#ececf1]"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowConfirmPassword(prev => !prev)}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#2b83fa]"
                                        >
                                            {showConfirmPassword ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                                        </button>
                                    </div>
                                    <div className="flex justify-end gap-3 border-t border-[#f0f0f0] pt-4 dark:border-[#ffffff08]">
                                        <button
                                            type="button"
                                            onClick={closePasswordModal}
                                            className="h-11 rounded-xl px-5 text-[13px] font-bold text-[#6e6e73] transition-colors hover:bg-[#f5f5f6] dark:text-[#9aa0a6] dark:hover:bg-white/5"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handlePasswordReset}
                                            disabled={passwordBusy || passwordForm.newPassword.length < 8 || passwordForm.newPassword !== passwordForm.confirmPassword}
                                            className="h-11 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] px-6 text-[13px] font-bold text-white shadow-md shadow-blue-500/20 transition-all hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            {passwordBusy ? "Updating..." : "Update Password"}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            ), document.body)}
        </div>
    );
};
