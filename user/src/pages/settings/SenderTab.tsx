import React, { useState, useCallback, useEffect } from "react";
import {
    FiPlus, FiGlobe, FiMapPin, FiBriefcase, FiCheckCircle, FiAlertCircle, FiClock,
    FiCheck, FiShieldOff, FiPhoneCall, FiRepeat
} from "react-icons/fi";
import { devLog } from "../../utils/devLog";
import { getAccountSettings, type StoredSenderId } from "../../utils/settingsStorage";
import { SenderRequestModal } from "../../components/SenderRequestModal";
import { SenderIdReminderBanner } from "../../components/ui/SenderIdReminderBanner";
import { useGhlLocation } from "../../hooks/useGhlLocation";
import { fetchSenderRequests, fetchAccountSenderConfig, cancelSenderRequest, type SenderRequest, type AccountSenderConfig } from "../../api/senderRequests";
import type { SenderDisplayStatus } from "./types";

const STATUS_CONFIG: Record<SenderDisplayStatus, { label: string; color: string; bg: string; icon: React.ReactElement }> = {
    fallback: { label: "System Default / Fallback Sender", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-50 dark:bg-blue-900/20", icon: <FiGlobe className="w-3 h-3" /> },
    active: { label: "Approved \u00b7 Active Sender", color: "text-emerald-600 dark:text-emerald-400", bg: "bg-emerald-50 dark:bg-emerald-900/20", icon: <FiCheck className="w-3 h-3" /> },
    approved: { label: "Approved", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-50 dark:bg-blue-900/20", icon: <FiCheckCircle className="w-3 h-3" /> },
    pending: { label: "Pending", color: "text-amber-600 dark:text-amber-400", bg: "bg-amber-50 dark:bg-amber-900/20", icon: <FiClock className="w-3 h-3" /> },
    rejected: { label: "Rejected", color: "text-red-600 dark:text-red-400", bg: "bg-red-50 dark:bg-red-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
    revoked: { label: "Revoked", color: "text-slate-600 dark:text-slate-400", bg: "bg-slate-100 dark:bg-white/10", icon: <FiShieldOff className="w-3 h-3" /> },
    configuration_mismatch: { label: "Configuration mismatch", color: "text-orange-700 dark:text-orange-300", bg: "bg-orange-50 dark:bg-orange-900/20", icon: <FiAlertCircle className="w-3 h-3" /> },
};

const SENDER_ICONS = [<FiGlobe key="0" />, <FiMapPin key="1" />, <FiBriefcase key="2" />, <FiCheckCircle key="3" />];

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

export const SenderTab: React.FC<{ autoOpenAddModal?: boolean }> = ({ autoOpenAddModal }) => {
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

    const displayItems: { id: string; name: string; description: string; status: SenderDisplayStatus; color: string; isSystem: boolean; submittedAt?: string; adminNotes?: string; sampleMessage?: string; provider?: string }[] = [
        { id: "system-default", name: systemDefault, description: "Fallback sender used only when no approved sender is active.", status: "fallback", color: "bg-blue-500", isSystem: true },
    ];

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

            {/* 2-Way SMS Virtual Number Status Card */}
            <Card className="bg-gradient-to-r from-blue-50/50 via-white to-blue-50/30 dark:from-blue-950/20 dark:via-[#1a1b1e] dark:to-blue-900/10">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="flex items-start gap-3.5">
                        <div className="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 mt-0.5">
                            <FiPhoneCall className="w-5 h-5" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h3 className="text-[14px] font-bold text-[#111111] dark:text-white">Assigned Virtual Number</h3>
                                {config?.two_way_capable !== false && (
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/40">
                                        <FiRepeat className="w-3 h-3" /> 2-Way Ready
                                    </span>
                                )}
                            </div>
                            <p className="text-[12px] text-[#6e6e73] dark:text-[#94959b] mt-0.5">
                                Virtual numbers enable customers to send inbound SMS replies directly to your inbox.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 self-end sm:self-center">
                        <div className="text-right">
                            <p className="text-[13px] font-extrabold text-[#111111] dark:text-white font-mono">
                                {config?.virtual_number || config?.unisms_virtual_number || "No Virtual Number Assigned"}
                            </p>
                            <div className="flex items-center justify-end gap-1.5 mt-0.5">
                                <span className={`w-2 h-2 rounded-full ${
                                    (config?.virtual_number_status === 'active' || (!config?.virtual_number_status && (config?.virtual_number || config?.unisms_virtual_number)))
                                        ? "bg-emerald-500"
                                        : config?.virtual_number_status === 'pending'
                                        ? "bg-amber-500 animate-pulse"
                                        : "bg-gray-400"
                                }`} />
                                <span className="text-[11px] font-semibold capitalize text-[#6e6e73] dark:text-[#94959b]">
                                    {config?.virtual_number_status || ((config?.virtual_number || config?.unisms_virtual_number) ? "active" : "inactive")}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>

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
