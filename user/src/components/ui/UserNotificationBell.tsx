import React, { useEffect, useMemo, useRef, useState } from "react";
import { getAccountSettings } from "../../utils/settingsStorage";
import { safeStorage } from "../../utils/safeStorage";
import { createPortal } from "react-dom";
import {
  FiAlertOctagon,
  FiAlertTriangle,
  FiBell,
  FiCheckCircle,
  FiCreditCard,
  FiSend,
  FiX,
  FiArrowRight,
} from "react-icons/fi";
import { useUserNotifications, type UserNotification } from "../../hooks/useUserNotifications";

type ViewTab = "home" | "compose" | "contacts" | "templates" | "settings" | "tickets";

interface UserNotificationBellProps {
  variant?: "default" | "light";
  onTabChange?: (tab: ViewTab) => void;
  shape?: "rounded" | "circle";
}

const timeAgo = (iso: string): string => {
  const date = new Date(iso);
  const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
  if (Number.isNaN(seconds)) return "Just now";
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
};

const formatCredits = (value?: number) =>
  Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const metadataString = (notification: UserNotification, key: string): string => {
  const value = notification.metadata?.[key];
  return typeof value === "string" && value.trim() ? value : "";
};

const notificationEmail = (notification: UserNotification): string =>
  notification.email || metadataString(notification, "registered_email") || "Account notification";

const getStoredLocationName = (): string => {
  try {
    const settings = getAccountSettings();
    if (settings.displayName && settings.displayName !== "NOLA SMS Pro") return settings.displayName;
    const authUser = JSON.parse(safeStorage.getItem("nola_auth_user") || "null");
    const name = authUser?.location_name || authUser?.company_name;
    if (typeof name === "string" && name.trim()) return name.trim();
  } catch {
    // ignore
  }
  return "";
};

const notificationLocationName = (notification: UserNotification): string =>
  getStoredLocationName() || notificationEmail(notification);

const notificationSender = (notification: UserNotification): string =>
  notification.sender_id || metadataString(notification, "sender_id") || "Sender ID";

const notificationAmount = (notification: UserNotification): string =>
  formatCredits(notification.amount || Number(metadataString(notification, "amount") || 0));

const openSettingsTab = (tab: "senderIds" | "credits" | "notifications" | "account") => {
  window.dispatchEvent(new CustomEvent("navigate-to-settings", { detail: { tab } }));
};

const CONFIGS: Record<string, {
  icon: React.ComponentType<{ className?: string }>;
  iconBg: string;
  iconText: string;
  unreadBg: string;
  dotBg: string;
  getTitle: (notification: UserNotification) => string;
  getDescription: (notification: UserNotification) => string;
  route: ViewTab;
  settingsTab?: "senderIds" | "credits" | "notifications" | "account";
  severity: number;
}> = {
  zero_balance: {
    icon: FiAlertOctagon,
    iconBg: "bg-red-100 dark:bg-red-900/30",
    iconText: "text-red-500",
    unreadBg: "bg-red-50/70 dark:bg-red-900/10 hover:bg-red-50 dark:hover:bg-red-900/15",
    dotBg: "bg-red-500",
    getTitle: () => "Zero balance alert",
    getDescription: (notification) => `${notificationLocationName(notification)} has 0 credits remaining.`,
    route: "settings",
    settingsTab: "credits",
    severity: 0,
  },
  low_balance: {
    icon: FiAlertTriangle,
    iconBg: "bg-amber-100 dark:bg-amber-900/30",
    iconText: "text-amber-500",
    unreadBg: "bg-amber-50/70 dark:bg-amber-900/10 hover:bg-amber-50 dark:hover:bg-amber-900/15",
    dotBg: "bg-amber-500",
    getTitle: () => "Low balance alert",
    getDescription: (notification) => `${notificationLocationName(notification)} is below the credit threshold.`,
    route: "settings",
    settingsTab: "credits",
    severity: 1,
  },
  location_registration: {
    icon: FiCheckCircle,
    iconBg: "bg-emerald-100 dark:bg-emerald-900/30",
    iconText: "text-emerald-500",
    unreadBg: "bg-emerald-50/70 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15",
    dotBg: "bg-emerald-500",
    getTitle: () => "Registration email sent",
    getDescription: (notification) => `${notificationLocationName(notification)} completed registration.`,
    route: "settings",
    settingsTab: "account",
    severity: 3,
  },
  support_ticket: {
    icon: FiBell,
    iconBg: "bg-blue-100 dark:bg-blue-900/30",
    iconText: "text-blue-500",
    unreadBg: "bg-blue-50/70 dark:bg-blue-900/10 hover:bg-blue-50 dark:hover:bg-blue-900/15",
    dotBg: "bg-blue-500",
    getTitle: () => "Support ticket submitted",
    getDescription: (notification) => `${notificationLocationName(notification)} submitted "${metadataString(notification, "subject") || "Support ticket"}".`,
    route: "tickets",
    severity: 3,
  },
  sender_request: {
    icon: FiSend,
    iconBg: "bg-blue-100 dark:bg-blue-900/30",
    iconText: "text-blue-500",
    unreadBg: "bg-blue-50/70 dark:bg-blue-900/10 hover:bg-blue-50 dark:hover:bg-blue-900/15",
    dotBg: "bg-blue-500",
    getTitle: () => "Sender ID request submitted",
    getDescription: (notification) => `${notificationLocationName(notification)} requested ${notificationSender(notification)}.`,
    route: "settings",
    settingsTab: "senderIds",
    severity: 4,
  },
  sender_id_approved: {
    icon: FiSend,
    iconBg: "bg-emerald-100 dark:bg-emerald-900/30",
    iconText: "text-emerald-500",
    unreadBg: "bg-emerald-50/70 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15",
    dotBg: "bg-emerald-500",
    getTitle: () => "Sender ID approved",
    getDescription: (notification) => `${notificationSender(notification)} was approved for ${notificationLocationName(notification)}.`,
    route: "settings",
    settingsTab: "senderIds",
    severity: 2,
  },
  sender_id_rejected: {
    icon: FiAlertTriangle,
    iconBg: "bg-red-100 dark:bg-red-900/30",
    iconText: "text-red-500",
    unreadBg: "bg-red-50/70 dark:bg-red-900/10 hover:bg-red-50 dark:hover:bg-red-900/15",
    dotBg: "bg-red-500",
    getTitle: () => "Sender ID rejected",
    getDescription: (notification) => `${notificationSender(notification)} was rejected for ${notificationLocationName(notification)}.`,
    route: "settings",
    settingsTab: "senderIds",
    severity: 1,
  },
  sender_approved: {
    icon: FiSend,
    iconBg: "bg-emerald-100 dark:bg-emerald-900/30",
    iconText: "text-emerald-500",
    unreadBg: "bg-emerald-50/70 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15",
    dotBg: "bg-emerald-500",
    getTitle: () => "Sender ID approved",
    getDescription: (notification) => `${notification.sender_id || "Your Sender ID"} is ready for sending.`,
    route: "settings",
    settingsTab: "senderIds",
    severity: 2,
  },
  sender_rejected: {
    icon: FiAlertTriangle,
    iconBg: "bg-red-100 dark:bg-red-900/30",
    iconText: "text-red-500",
    unreadBg: "bg-red-50/70 dark:bg-red-900/10 hover:bg-red-50 dark:hover:bg-red-900/15",
    dotBg: "bg-red-500",
    getTitle: () => "Sender ID needs attention",
    getDescription: (notification) =>
      `${notification.sender_id || "Your Sender ID"} was rejected.${notification.metadata?.admin_notes ? ` ${notification.metadata.admin_notes}` : ""}`,
    route: "settings",
    settingsTab: "senderIds",
    severity: 1,
  },
  sender_revoked: {
    icon: FiAlertOctagon,
    iconBg: "bg-red-100 dark:bg-red-900/30",
    iconText: "text-red-500",
    unreadBg: "bg-red-50/70 dark:bg-red-900/10 hover:bg-red-50 dark:hover:bg-red-900/15",
    dotBg: "bg-red-500",
    getTitle: () => "Sender ID revoked",
    getDescription: (notification) => `${notification.sender_id || "Your Sender ID"} is no longer available.`,
    route: "settings",
    settingsTab: "senderIds",
    severity: 1,
  },
  sender_pending: {
    icon: FiSend,
    iconBg: "bg-blue-100 dark:bg-blue-900/30",
    iconText: "text-blue-500",
    unreadBg: "bg-blue-50/70 dark:bg-blue-900/10 hover:bg-blue-50 dark:hover:bg-blue-900/15",
    dotBg: "bg-blue-500",
    getTitle: () => "Sender ID pending approval",
    getDescription: (notification) => `${notification.sender_id || "Your Sender ID"} is waiting for admin review.`,
    route: "settings",
    settingsTab: "senderIds",
    severity: 4,
  },
  sender_id_reminder: {
    icon: FiArrowRight,
    iconBg: "bg-indigo-100 dark:bg-indigo-900/30",
    iconText: "text-indigo-500",
    unreadBg: "bg-indigo-50/70 dark:bg-indigo-900/10 hover:bg-indigo-50 dark:hover:bg-indigo-900/15",
    dotBg: "bg-indigo-400",
    getTitle: () => "Set up your Sender ID",
    getDescription: () => "You haven't requested a custom Sender ID yet. Tap to set one up now.",
    route: "settings",
    settingsTab: "senderIds",
    severity: 5,
  },
  top_up_success: {
    icon: FiCreditCard,
    iconBg: "bg-emerald-100 dark:bg-emerald-900/30",
    iconText: "text-emerald-500",
    unreadBg: "bg-emerald-50/70 dark:bg-emerald-950/10 hover:bg-emerald-50 dark:hover:bg-emerald-950/15",
    dotBg: "bg-emerald-500",
    getTitle: () => "Credits added",
    getDescription: (notification) => `${notificationAmount(notification)} credits added for ${notificationLocationName(notification)}.`,
    route: "settings",
    settingsTab: "credits",
    severity: 3,
  },
};

const DEFAULT_CONFIG = {
  icon: FiBell,
  iconBg: "bg-gray-100 dark:bg-gray-800",
  iconText: "text-gray-500",
  unreadBg: "bg-gray-50/70 dark:bg-gray-800/10 hover:bg-gray-50 dark:hover:bg-gray-800/15",
  dotBg: "bg-gray-500",
  getTitle: (notification: UserNotification) => notification.title || "New notification",
  getDescription: (notification: UserNotification) => notification.description || "Account update available.",
  route: "home" as ViewTab,
  severity: 9,
};

const NotificationRow: React.FC<{
  notification: UserNotification;
  onRead: (id: string) => void;
  onClose: () => void;
  onTabChange?: (tab: ViewTab) => void;
}> = ({ notification, onRead, onClose, onTabChange }) => {
  const config = CONFIGS[notification.type] || DEFAULT_CONFIG;
  const Icon = config.icon;

  const handleClick = () => {
    if (!notification.read) onRead(notification.id);

    // Sender ID reminder: navigate to settings sender id and open modal
    if (notification.type === "sender_id_reminder") {
      openSettingsTab("senderIds");
      window.dispatchEvent(new CustomEvent("open-sender-id-modal"));
      onClose();
      return;
    }

    const route = notification.route || config.route;
    const settingsTab = notification.settingsTab || config.settingsTab;
    if (settingsTab) {
      openSettingsTab(settingsTab);
    } else if (route && onTabChange) {
      onTabChange(route);
    }

    onClose();
  };

  return (
    <button
      type="button"
      onClick={handleClick}
      className={`
        relative flex w-full items-start gap-3 px-4 py-3.5 text-left
        transition-colors duration-150
        ${notification.read
          ? "opacity-60 hover:opacity-100 hover:bg-[#f7f7f7] dark:hover:bg-white/[0.03]"
          : config.unreadBg
        }
      `}
    >
      <span className={`mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl ${config.iconBg} ${config.iconText}`}>
        <Icon className="h-4 w-4" />
      </span>

      <span className="min-w-0 flex-1">
        <span className="block text-[12.5px] font-bold leading-snug text-[#111111] dark:text-white">
          {config.getTitle(notification)}
        </span>
        <span className="mt-0.5 block text-[11.5px] font-medium leading-snug text-[#6e6e73] dark:text-[#9aa0a6]">
          {config.getDescription(notification)}
        </span>
        <span className="mt-1 block text-[10px] font-bold uppercase tracking-wide text-[#9aa0a6]">
          {timeAgo(notification.created_at)}
        </span>
      </span>

      {!notification.read && (
        <span className={`mt-1.5 h-2 w-2 flex-shrink-0 rounded-full ${config.dotBg} animate-pulse`} />
      )}
    </button>
  );
};

export const UserNotificationBell: React.FC<UserNotificationBellProps> = ({
  variant = "default",
  onTabChange,
  shape = "rounded",
}) => {
  const { notifications, loading, unreadCount, markRead, markAllRead, refetch } = useUserNotifications();
  const [open, setOpen] = useState(false);
  const [panelPosition, setPanelPosition] = useState({ top: 0, right: 12 });
  const panelRef = useRef<HTMLDivElement>(null);
  const btnRef = useRef<HTMLButtonElement>(null);

  const sortedNotifications = useMemo(() => {
    return [...notifications].sort((a, b) =>
      new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
    );
  }, [notifications]);

  const hasCritical = notifications.some((notification) =>
    !notification.read && ["zero_balance", "sender_rejected", "sender_id_rejected", "sender_revoked"].includes(notification.type)
  );

  const handleBellClick = () => {
    setOpen((value) => {
      const nextOpen = !value;
      if (nextOpen) void refetch(false);
      return nextOpen;
    });
  };

  useEffect(() => {
    if (!open) return;

    const updatePosition = () => {
      const rect = btnRef.current?.getBoundingClientRect();
      if (!rect) return;
      setPanelPosition({
        top: rect.bottom + 8,
        right: Math.max(12, window.innerWidth - rect.right),
      });
    };

    const handleOutside = (event: MouseEvent) => {
      if (
        panelRef.current && !panelRef.current.contains(event.target as Node) &&
        btnRef.current && !btnRef.current.contains(event.target as Node)
      ) {
        setOpen(false);
      }
    };

    updatePosition();
    window.addEventListener("resize", updatePosition);
    window.addEventListener("scroll", updatePosition, true);
    document.addEventListener("mousedown", handleOutside);

    return () => {
      window.removeEventListener("resize", updatePosition);
      window.removeEventListener("scroll", updatePosition, true);
      document.removeEventListener("mousedown", handleOutside);
    };
  }, [open]);

  const panel = open ? createPortal(
    <div
      ref={panelRef}
      className="
        fixed z-[99999]
        w-[360px] max-w-[calc(100vw-24px)]
        overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white shadow-2xl
        dark:border-white/10 dark:bg-[#1a1b1e]
      "
      style={{ top: panelPosition.top, right: panelPosition.right }}
    >
      <div className="flex items-center justify-between border-b border-[#e5e5e5] px-4 py-3.5 dark:border-white/5">
        <div className="flex items-center gap-2">
          <FiBell className="h-4 w-4 text-[#2b83fa]" />
          <span className="text-[13.5px] font-bold text-[#111111] dark:text-white">Notifications</span>
          {unreadCount > 0 && (
            <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-red-600 dark:bg-red-900/30 dark:text-red-400">
              {unreadCount} new
            </span>
          )}
        </div>
        <div className="flex items-center gap-1.5">
          {unreadCount > 0 && (
            <button
              type="button"
              onClick={markAllRead}
              className="flex items-center gap-1 rounded-lg px-2.5 py-1 text-[10.5px] font-bold text-[#2b83fa] transition-colors hover:bg-[#2b83fa]/10"
              title="Mark all as read"
            >
              <FiCheckCircle className="h-3 w-3" />
              All read
            </button>
          )}
          <button
            type="button"
            onClick={() => setOpen(false)}
            className="rounded-lg p-1 text-[#6e6e73] transition-colors hover:bg-[#f7f7f7] hover:text-[#111111] dark:hover:bg-white/5 dark:hover:text-white"
            aria-label="Close notifications"
          >
            <FiX className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>

      <div className="max-h-[420px] overflow-y-auto divide-y divide-[#f0f0f0] dark:divide-white/[0.04]">
        {loading ? (
          <div className="space-y-0.5 p-3">
            {[1, 2, 3].map((item) => (
              <div key={item} className="flex items-start gap-3 p-3">
                <div className="h-8 w-8 flex-shrink-0 rounded-xl bg-[#f0f0f0] animate-pulse dark:bg-[#2a2b2e]" />
                <div className="flex-1 space-y-2">
                  <div className="h-3 w-3/4 rounded bg-[#f0f0f0] animate-pulse dark:bg-[#2a2b2e]" />
                  <div className="h-2.5 w-full rounded bg-[#f0f0f0] opacity-60 animate-pulse dark:bg-[#2a2b2e]" />
                </div>
              </div>
            ))}
          </div>
        ) : sortedNotifications.length === 0 ? (
          <div className="py-14 text-center">
            <FiBell className="mx-auto mb-3 h-8 w-8 text-[#d0d0d5] dark:text-[#3a3b3f]" />
            <p className="text-[13px] font-semibold text-[#9aa0a6]">No notifications yet</p>
            <p className="mt-1 text-[11px] font-medium text-[#9aa0a6]">
              Sender ID, credit, and account updates will appear here
            </p>
          </div>
        ) : (
          sortedNotifications.map((notification) => (
            <NotificationRow
              key={notification.id}
              notification={notification}
              onRead={markRead}
              onClose={() => setOpen(false)}
              onTabChange={onTabChange}
            />
          ))
        )}
      </div>

      {notifications.length > 0 && (
        <div className="border-t border-[#f0f0f0] bg-[#fafafa] px-4 py-2.5 dark:border-white/5 dark:bg-black/20">
          <p className="text-center text-[10.5px] font-medium text-[#9aa0a6]">
            Showing last {notifications.length} notification{notifications.length !== 1 ? "s" : ""} - Auto-refreshes every 60s
          </p>
        </div>
      )}
    </div>,
    document.body
  ) : null;

  return (
    <div className="relative">
      <button
        ref={btnRef}
        type="button"
        onClick={handleBellClick}
        className={`
          relative inline-flex items-center justify-center border p-2 leading-none shadow-sm transition-all
          ${shape === "circle" ? "h-10 w-10 rounded-full" : "rounded-xl"}
          ${variant === "light"
            ? open
              ? "border-white/30 bg-white/25 text-white"
              : "border-white/20 bg-white/10 text-white hover:bg-white/20"
            : open
              ? "border-[#2b83fa]/30 bg-[#2b83fa]/10 text-[#2b83fa]"
              : "border-[#e5e5e5] bg-[#f7f7f7] text-[#6e6e73] hover:bg-[#efefef] hover:text-[#111111] dark:border-white/5 dark:bg-[#1e2023] dark:text-[#9aa0a6] dark:hover:bg-white/5 dark:hover:text-white"
          }
        `}
        aria-label={`Notifications${unreadCount > 0 ? `, ${unreadCount} unread` : ""}`}
        title="Notifications"
      >
        <FiBell className={`h-4 w-4 ${unreadCount > 0 ? "animate-[notification-ring_1.5s_ease-in-out_infinite]" : ""}`} />
        {unreadCount > 0 && (
          <span className={`
            absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full
            border-2 border-white px-1 text-[9px] font-black text-white shadow-md dark:border-[#111111]
            ${hasCritical ? "bg-red-500 animate-pulse" : "bg-amber-500"}
          `}>
            {unreadCount > 9 ? "9+" : unreadCount}
          </span>
        )}
      </button>
      {panel}
    </div>
  );
};
