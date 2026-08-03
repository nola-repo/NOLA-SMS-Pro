import React, { useState, useEffect } from "react";
import { FiBell, FiZap, FiCheck, FiAlertCircle, FiRefreshCw, FiSave } from "react-icons/fi";
import {
    getAccountSettings,
    getNotificationSettings, saveNotificationSettings as saveNotificationSettingsLocal,
    type NotificationSettings
} from "../../utils/settingsStorage";
import {
    fetchNotificationSettings,
    saveNotificationSettings as saveNotificationSettingsRemote,
} from "../../api/notificationSettings";
import { useGhlLocation } from "../../hooks/useGhlLocation";
import { fetchAccountProfile, getCachedAccountProfile } from "../../api/account";
import type { AccountProfile } from "../../api/account";
import { useUserProfileContext } from "../../context/UserProfileContext";

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

const resolveProfileEmail = (profile?: Partial<AccountProfile> | null): string =>
    profile?.email || profile?.email_address || "";

export const NotificationsTab: React.FC = () => {
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
