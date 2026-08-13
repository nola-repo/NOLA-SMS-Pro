import React, { useState, useRef, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { FiBell, FiAlertTriangle, FiAlertOctagon, FiCheckCircle, FiX, FiSend, FiUserPlus, FiBriefcase } from 'react-icons/fi';
import { useAdminNotifications, AdminNotification } from '../../hooks/useAdminNotifications';

function timeAgo(iso: string): string {
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

const NOTIF_CONFIGS: Record<string, {
    icon: React.ComponentType<{ className?: string }>;
    iconBg: string;
    iconText: string;
    unreadBg: string;
    dotBg: string;
    getTitle: (n: AdminNotification) => string;
    getDescription: (n: AdminNotification) => string;
    route: string;
}> = {
    zero_balance: {
        icon: FiAlertOctagon,
        iconBg: 'bg-red-100 dark:bg-red-900/30',
        iconText: 'text-red-500',
        unreadBg: 'bg-red-50/60 dark:bg-red-900/10 hover:bg-red-50 dark:hover:bg-red-900/15',
        dotBg: 'bg-red-500',
        getTitle: (n) => `⚠️ Zero Balance — ${n.location_name || n.location_id || 'Unknown Subaccount'}`,
        getDescription: () => `Sending suspended. Balance: 0 credits.`,
        route: '/accounts',
    },
    low_balance: {
        icon: FiAlertTriangle,
        iconBg: 'bg-amber-100 dark:bg-amber-900/30',
        iconText: 'text-amber-500',
        unreadBg: 'bg-amber-50/60 dark:bg-amber-900/10 hover:bg-amber-50 dark:hover:bg-amber-900/15',
        dotBg: 'bg-amber-500',
        getTitle: (n) => `Low Balance — ${n.location_name || n.location_id || 'Unknown Subaccount'}`,
        getDescription: (n) => `Balance dropped to ${n.balance ?? 0} credits (threshold: ${n.threshold ?? 0}).`,
        route: '/accounts',
    },
    sender_request: {
        icon: FiSend,
        iconBg: 'bg-blue-100 dark:bg-blue-900/30',
        iconText: 'text-blue-500',
        unreadBg: 'bg-blue-50/60 dark:bg-blue-900/10 hover:bg-blue-50 dark:hover:bg-blue-900/15',
        dotBg: 'bg-blue-500',
        getTitle: () => `New Sender ID Request`,
        getDescription: (n) => `Subaccount "${n.location_name || n.location_id || 'Unknown'}" requested Sender ID: "${n.metadata?.sender_id || 'Pending'}"`,
        route: '/requests',
    },
    sender_id_approved: {
        icon: FiCheckCircle,
        iconBg: 'bg-emerald-100 dark:bg-emerald-900/30',
        iconText: 'text-emerald-500',
        unreadBg: 'bg-emerald-50/60 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15',
        dotBg: 'bg-emerald-500',
        getTitle: () => `Sender ID Approved`,
        getDescription: (n) => `Sender ID "${n.metadata?.sender_id || ''}" approved for ${n.location_name || 'Subaccount'}.`,
        route: '/requests',
    },
    sender_id_rejected: {
        icon: FiX,
        iconBg: 'bg-red-100 dark:bg-red-900/30',
        iconText: 'text-red-500',
        unreadBg: 'bg-red-50/60 dark:bg-red-900/10 hover:bg-red-50 dark:hover:bg-red-900/15',
        dotBg: 'bg-red-500',
        getTitle: () => `Sender ID Rejected`,
        getDescription: (n) => `Sender ID "${n.metadata?.sender_id || ''}" rejected for ${n.location_name || 'Subaccount'}.`,
        route: '/requests',
    },
    new_subaccount: {
        icon: FiUserPlus,
        iconBg: 'bg-emerald-100 dark:bg-emerald-900/30',
        iconText: 'text-emerald-500',
        unreadBg: 'bg-emerald-50/60 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15',
        dotBg: 'bg-emerald-500',
        getTitle: () => `New Subaccount Connected`,
        getDescription: (n) => `Subaccount "${n.location_name || n.location_id || 'Unknown'}" is now connected.`,
        route: '/accounts',
    },
    location_registration: {
        icon: FiUserPlus,
        iconBg: 'bg-emerald-100 dark:bg-emerald-900/30',
        iconText: 'text-emerald-500',
        unreadBg: 'bg-emerald-50/60 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15',
        dotBg: 'bg-emerald-500',
        getTitle: () => `New User Registered`,
        getDescription: (n) => `Subaccount "${n.location_name || n.metadata?.location_name || n.location_id || 'New User'}" completed registration.`,
        route: '/accounts',
    },
    welcome: {
        icon: FiUserPlus,
        iconBg: 'bg-emerald-100 dark:bg-emerald-900/30',
        iconText: 'text-emerald-500',
        unreadBg: 'bg-emerald-50/60 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15',
        dotBg: 'bg-emerald-500',
        getTitle: () => `New Business Registered`,
        getDescription: (n) => `Subaccount "${n.location_name || n.metadata?.location_name || n.location_id || 'New Business'}" completed registration.`,
        route: '/accounts',
    },
    top_up_success: {
        icon: FiCheckCircle,
        iconBg: 'bg-emerald-100 dark:bg-emerald-900/30',
        iconText: 'text-emerald-500',
        unreadBg: 'bg-emerald-50/60 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15',
        dotBg: 'bg-emerald-500',
        getTitle: () => `Credits Top-Up`,
        getDescription: (n) => `${n.location_name || 'Subaccount'} topped up ${n.metadata?.amount ? `${n.metadata.amount} credits` : 'credits'}.`,
        route: '/accounts',
    },
    support_ticket: {
        icon: FiAlertTriangle,
        iconBg: 'bg-orange-100 dark:bg-orange-900/30',
        iconText: 'text-orange-500',
        unreadBg: 'bg-orange-50/60 dark:bg-orange-900/10 hover:bg-orange-50 dark:hover:bg-orange-900/15',
        dotBg: 'bg-orange-500',
        getTitle: () => `New Support Ticket`,
        getDescription: (n) => `${n.location_name || 'Subaccount'} submitted "${n.metadata?.subject || 'Support Ticket'}".`,
        route: '/activity',
    },
    new_agency: {
        icon: FiBriefcase,
        iconBg: 'bg-purple-100 dark:bg-purple-900/30',
        iconText: 'text-purple-500',
        unreadBg: 'bg-purple-50/60 dark:bg-purple-900/10 hover:bg-purple-50 dark:hover:bg-purple-900/15',
        dotBg: 'bg-purple-500',
        getTitle: () => `New Agency Registered`,
        getDescription: (n) => `Agency "${n.metadata?.agency_name || n.location_name || 'New Agency'}" has registered.`,
        route: '/agencies',
    },
};

const DEFAULT_CONFIG = {
    icon: FiBell,
    iconBg: 'bg-gray-100 dark:bg-gray-800',
    iconText: 'text-gray-500',
    unreadBg: 'bg-gray-50/60 dark:bg-gray-800/10 hover:bg-gray-50 dark:hover:bg-gray-800/15',
    dotBg: 'bg-gray-500',
    getTitle: (n: AdminNotification) => n.metadata?.title || (n.type ? n.type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : `System Notification`),
    getDescription: (n: AdminNotification) => n.metadata?.description || n.metadata?.message || (n.location_name ? `Update for ${n.location_name}` : `You have a new activity update.`),
    route: '/accounts',
};

const NotifItem: React.FC<{
    notif: AdminNotification;
    onRead: (id: string) => void;
    onClose: () => void;
}> = ({ notif, onRead, onClose }) => {
    const navigate = useNavigate();
    const config = NOTIF_CONFIGS[notif.type] || DEFAULT_CONFIG;
    const Icon = config.icon;

    return (
        <div
            onClick={() => {
                if (!notif.read) {
                    onRead(notif.id);
                }
                navigate(config.route);
                onClose();
            }}
            className={`
                relative flex items-start gap-3 px-4 py-3.5 cursor-pointer
                transition-colors duration-150
                ${notif.read
                    ? 'opacity-60 hover:opacity-100 hover:bg-[#f7f7f7] dark:hover:bg-white/[0.03]'
                    : config.unreadBg
                }
            `}
        >
            {/* Icon */}
            <div className={`
                mt-0.5 w-8 h-8 rounded-xl flex-shrink-0 flex items-center justify-center
                ${config.iconBg} ${config.iconText}
            `}>
                <Icon className="w-4 h-4" />
            </div>

            {/* Content */}
            <div className="flex-1 min-w-0">
                <p className="text-[12.5px] font-bold text-[#111111] dark:text-white leading-snug">
                    {config.getTitle(notif)}
                </p>
                <p className="text-[11.5px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5 font-medium">
                    {config.getDescription(notif)}
                </p>
                {notif.email && <p className="text-[10.5px] text-[#9aa0a6] mt-1 font-medium">{notif.email}</p>}
                <p className="text-[10px] text-[#9aa0a6] mt-0.5 font-medium uppercase tracking-wide">
                    {timeAgo(notif.created_at || '')}
                </p>
            </div>

            {/* Unread dot */}
            {!notif.read && (
                <span className={`
                    mt-1.5 w-2 h-2 rounded-full flex-shrink-0
                    ${config.dotBg}
                    animate-pulse
                `} />
            )}
        </div>
    );
};

export const NotificationBell: React.FC<{ variant?: 'default' | 'light' }> = ({ variant = 'default' }) => {
    const { notifications, loading, unreadCount, markRead, markAllRead } = useAdminNotifications();
    const [open, setOpen] = useState(false);
    const panelRef = useRef<HTMLDivElement>(null);
    const btnRef = useRef<HTMLButtonElement>(null);

    // Close on outside click
    useEffect(() => {
        if (!open) return;
        const handler = (e: MouseEvent) => {
            if (
                panelRef.current && !panelRef.current.contains(e.target as Node) &&
                btnRef.current && !btnRef.current.contains(e.target as Node)
            ) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [open]);

    const hasZero = notifications.some(n => !n.read && n.type === 'zero_balance');

    // Sort notifications strictly newest to oldest
    const sortedNotifications = useMemo(() => {
        return [...notifications].sort((a, b) => {
            const timeA = a.created_at ? new Date(a.created_at).getTime() : 0;
            const timeB = b.created_at ? new Date(b.created_at).getTime() : 0;
            return timeB - timeA;
        });
    }, [notifications]);

    return (
        <div className="relative">
            {/* Bell Button */}
            <button
                ref={btnRef}
                id="admin-notification-bell"
                onClick={() => setOpen(o => !o)}
                className={`
                    relative flex h-10 w-10 items-center justify-center rounded-xl border transition-all shadow-sm
                    ${variant === 'light'
                        ? open
                            ? 'bg-white/25 border-white/30 text-white'
                            : 'bg-white/10 border-white/20 text-white hover:bg-white/20'
                        : open
                            ? 'bg-[#2b83fa]/10 border-[#2b83fa]/30 text-[#2b83fa]'
                            : 'bg-[#f7f7f7] dark:bg-[#1e2023] border-[#e5e5e5] dark:border-white/5 text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white hover:bg-[#efefef] dark:hover:bg-white/5'
                    }
                `}
                aria-label={`Notifications${unreadCount > 0 ? `, ${unreadCount} unread` : ''}`}
                title="Notifications"
            >
                <FiBell className={`w-4 h-4 ${unreadCount > 0 ? 'animate-[ring_1.5s_ease-in-out_infinite]' : ''}`} />

                {/* Badge */}
                {unreadCount > 0 && (
                    <span className={`
                        absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full
                        text-white text-[9px] font-black flex items-center justify-center
                        shadow-md border-2 border-white dark:border-[#111111]
                        ${hasZero ? 'bg-red-500 animate-pulse' : 'bg-amber-500'}
                    `}>
                        {unreadCount > 9 ? '9+' : unreadCount}
                    </span>
                )}
            </button>

            {/* Dropdown Panel */}
            {open && (
                <div
                    ref={panelRef}
                    className="
                        absolute right-0 top-[calc(100%+8px)] z-[150]
                        w-[360px] max-w-[calc(100vw-24px)]
                        bg-white dark:bg-[#1a1b1e]
                        border border-[#e5e5e5] dark:border-white/10
                        rounded-2xl shadow-2xl overflow-hidden
                        animate-in fade-in zoom-in-95 slide-in-from-top-2 duration-200
                    "
                >
                    {/* Header */}
                    <div className="flex items-center justify-between px-4 py-3.5 border-b border-[#e5e5e5] dark:border-white/5">
                        <div className="flex items-center gap-2">
                            <FiBell className="w-4 h-4 text-[#2b83fa]" />
                            <span className="text-[13.5px] font-bold text-[#111111] dark:text-white">
                                Notifications
                            </span>
                            {unreadCount > 0 && (
                                <span className="px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 text-[10px] font-black uppercase tracking-wide">
                                    {unreadCount} new
                                </span>
                            )}
                        </div>
                        <div className="flex items-center gap-1.5">
                            {unreadCount > 0 && (
                                <button
                                    onClick={markAllRead}
                                    className="flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10.5px] font-bold text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-colors"
                                    title="Mark all as read"
                                >
                                    <FiCheckCircle className="w-3 h-3" />
                                    All read
                                </button>
                            )}
                            <button
                                onClick={() => setOpen(false)}
                                className="p-1 rounded-lg text-[#6e6e73] hover:text-[#111111] dark:hover:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors"
                            >
                                <FiX className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>

                    {/* Body */}
                    <div className="max-h-[420px] overflow-y-auto divide-y divide-[#f0f0f0] dark:divide-white/[0.04]">
                        {loading ? (
                            <div className="space-y-0.5 p-3">
                                {[1, 2, 3].map(i => (
                                    <div key={i} className="flex items-start gap-3 p-3">
                                        <div className="w-8 h-8 rounded-xl bg-[#f0f0f0] dark:bg-[#2a2b2e] animate-pulse flex-shrink-0" />
                                        <div className="flex-1 space-y-2">
                                            <div className="h-3 w-3/4 rounded bg-[#f0f0f0] dark:bg-[#2a2b2e] animate-pulse" />
                                            <div className="h-2.5 w-full rounded bg-[#f0f0f0] dark:bg-[#2a2b2e] animate-pulse opacity-60" />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : sortedNotifications.length === 0 ? (
                            <div className="py-14 text-center">
                                <FiBell className="w-8 h-8 mx-auto mb-3 text-[#d0d0d5] dark:text-[#3a3b3f]" />
                                <p className="text-[13px] font-semibold text-[#9aa0a6]">No notifications yet</p>
                                <p className="text-[11px] text-[#9aa0a6] mt-1 font-medium">
                                    Alerts and platform updates will appear here
                                </p>
                            </div>
                        ) : (
                            sortedNotifications.map(n => (
                                <NotifItem key={n.id} notif={n} onRead={markRead} onClose={() => setOpen(false)} />
                            ))
                        )}
                    </div>

                    {/* Footer */}
                    {notifications.length > 0 && (
                        <div className="px-4 py-2.5 border-t border-[#f0f0f0] dark:border-white/5 bg-[#fafafa] dark:bg-black/20">
                            <p className="text-[10.5px] text-center text-[#9aa0a6] font-medium">
                                Showing last {notifications.length} notification{notifications.length !== 1 ? 's' : ''} · Auto-refreshes every 60s
                            </p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};
