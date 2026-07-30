import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { fetchCreditStatus, fetchCreditTransactions, type CreditTransaction } from "../api/credits";
import { fetchSenderRequests } from "../api/senderRequests";
import { API_CONFIG } from "../config";
import { useLocationId } from "../context/LocationContext";
import { getSession } from "../services/authService";
import { safeStorage } from "../utils/safeStorage";
import { getNotificationSettings } from "../utils/settingsStorage";
import { apiFetch } from "../utils/apiFetch";

const POLL_INTERVAL = 60_000;
const NOTIFICATIONS_API = `${API_CONFIG.base}/api/notifications`;
const READ_STORAGE_PREFIX = "nola_user_notification_reads_";
const VIEW_TABS = ["home", "compose", "contacts", "templates", "settings", "tickets"] as const;
const SETTINGS_TABS = ["account", "senderIds", "notifications", "credits"] as const;

export type UserNotificationType =
  | "zero_balance"
  | "low_balance"
  | "top_up_success"
  | "location_registration"
  | "support_ticket"
  | "sender_request"
  | "sender_id_approved"
  | "sender_id_rejected"
  | "sender_approved"
  | "sender_rejected"
  | "sender_revoked"
  | "sender_pending"
  | "sender_id_reminder"
  | "account";

export interface UserNotification {
  id: string;
  type: UserNotificationType | string;
  title?: string;
  description?: string;
  created_at: string;
  read: boolean;
  email?: string;
  amount?: number;
  balance?: number;
  threshold?: number;
  sender_id?: string;
  route?: "home" | "compose" | "contacts" | "templates" | "settings" | "tickets";
  settingsTab?: "account" | "senderIds" | "notifications" | "credits";
  metadata?: Record<string, unknown>;
}

type JsonRecord = Record<string, unknown>;
type ViewTab = NonNullable<UserNotification["route"]>;
type SettingsTab = NonNullable<UserNotification["settingsTab"]>;
type NotificationTransaction = CreditTransaction & {
  id?: string;
  type: string;
};

const isRecord = (value: unknown): value is JsonRecord =>
  typeof value === "object" && value !== null;

const asString = (value: unknown): string | undefined =>
  typeof value === "string" && value ? value : undefined;

const asNumber = (value: unknown): number | undefined =>
  typeof value === "number" ? value : typeof value === "string" && value.trim() && !Number.isNaN(Number(value)) ? Number(value) : undefined;

const asViewTab = (value: unknown): ViewTab | undefined =>
  VIEW_TABS.includes(value as ViewTab) ? value as ViewTab : undefined;

const asSettingsTab = (value: unknown): SettingsTab | undefined =>
  SETTINGS_TABS.includes(value as SettingsTab) ? value as SettingsTab : undefined;

const readStorageKey = (locationId: string) => `${READ_STORAGE_PREFIX}${locationId || "default"}`;

const readReadIds = (locationId: string): Set<string> => {
  try {
    const raw = safeStorage.getItem(readStorageKey(locationId));
    const ids = raw ? JSON.parse(raw) : [];
    return new Set(Array.isArray(ids) ? ids.filter((id) => typeof id === "string") : []);
  } catch {
    return new Set();
  }
};

const writeReadIds = (locationId: string, ids: Set<string>) => {
  try {
    safeStorage.setItem(readStorageKey(locationId), JSON.stringify(Array.from(ids).slice(-300)));
  } catch {
    // Local read state is best-effort.
  }
};

const decodeJwtPayload = (token: string): JsonRecord | null => {
  try {
    const payload = token.split(".")[1];
    if (!payload) return null;
    const normalized = payload.replace(/-/g, "+").replace(/_/g, "/");
    const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), "=");
    return JSON.parse(atob(padded)) as JsonRecord;
  } catch {
    return null;
  }
};

const isJwtExpired = (token: string): boolean => {
  const payload = decodeJwtPayload(token);
  const exp = typeof payload?.exp === "number" ? payload.exp : null;
  return exp !== null && exp * 1000 <= Date.now();
};

function getNotificationAuthHeaders(locationId?: string): Record<string, string> | null {
  const session = getSession();
  const token = session?.token || safeStorage.getItem("nola_auth_token");
  const effectiveLocationId = locationId || session?.locationId || safeStorage.getItem("nola_location_id") || "";

  if (!token || isJwtExpired(token)) return null;

  const headers: Record<string, string> = {
    Accept: "application/json",
    Authorization: `Bearer ${token}`,
  };

  if (effectiveLocationId) {
    headers["X-GHL-Location-ID"] = effectiveLocationId;
  }

  return headers;
}

const toIso = (value: unknown): string => {
  if (typeof value === "string" && value) return value;
  return new Date().toISOString();
};

const normalizeRemoteNotification = (raw: unknown, readIds: Set<string>): UserNotification | null => {
  if (!isRecord(raw)) return null;
  const id = String(raw.id || raw.notification_id || raw.event_id || "");
  if (!id) return null;
  const metadata = isRecord(raw.metadata) ? raw.metadata : {};

  return {
    id,
    type: asString(raw.type) || "account",
    title: asString(raw.title),
    description: asString(raw.description) || asString(raw.message),
    created_at: toIso(raw.created_at || raw.createdAt || raw.timestamp),
    read: Boolean(raw.read) || readIds.has(id),
    email: asString(raw.email) || asString(metadata.registered_email),
    amount: asNumber(raw.amount) || asNumber(metadata.amount),
    balance: asNumber(raw.balance),
    threshold: asNumber(raw.threshold),
    sender_id: asString(raw.sender_id) || asString(raw.senderId) || asString(raw.requested_id) || asString(metadata.sender_id),
    route: asViewTab(raw.route),
    settingsTab: asSettingsTab(raw.settingsTab) || asSettingsTab(raw.settings_tab),
    metadata,
  };
};

async function fetchRemoteNotifications(locationId: string, readIds: Set<string>): Promise<UserNotification[] | null> {
  const headers = getNotificationAuthHeaders(locationId);
  if (!headers) return null;

  const params = new URLSearchParams({
    limit: "30",
    location_id: locationId,
  });
  const url = `${NOTIFICATIONS_API}?${params.toString()}`;
  try {
    const res = await apiFetch(url, { headers });
    if (!res.ok) return null;

    const json = await res.json();
    const rows = Array.isArray(json)
      ? json
      : isRecord(json)
        ? json.data || json.notifications || []
        : [];
    if (!Array.isArray(rows)) return null;
    return rows
      .map((row) => normalizeRemoteNotification(row, readIds))
      .filter(Boolean) as UserNotification[];
  } catch {
    return null;
  }
}

async function postNotificationAction(
  locationId: string,
  payload: { action: "mark_read"; notification_id: string } | { action: "mark_all_read" }
): Promise<void> {
  const headers = getNotificationAuthHeaders(locationId);
  if (!headers) return;

  try {
    await apiFetch(NOTIFICATIONS_API, {
      method: "POST",
      headers: {
        ...headers,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        ...payload,
        location_id: locationId,
      }),
    });
  } catch {
    // Local read state remains the fallback if remote persistence fails.
  }
}

async function buildLocalNotifications(locationId: string, readIds: Set<string>): Promise<UserNotification[]> {
  const settings = getNotificationSettings();
  const lowBalanceThreshold = Number(settings.lowBalanceThreshold || 50);

  const [creditStatus, senderRequests, transactions] = await Promise.all([
    fetchCreditStatus(locationId),
    fetchSenderRequests(locationId),
    fetchCreditTransactions("default", 25, locationId),
  ]);

  const now = new Date().toISOString();
  const notifications: UserNotification[] = [];
  const balance = Number(creditStatus?.credit_balance ?? 0);

  if (creditStatus && balance <= 0) {
    const id = `balance-zero-${locationId}`;
    notifications.push({
      id,
      type: "zero_balance",
      created_at: now,
      read: readIds.has(id),
      balance,
      threshold: lowBalanceThreshold,
      route: "settings",
      settingsTab: "credits",
    });
  } else if (creditStatus && balance <= lowBalanceThreshold) {
    const id = `balance-low-${locationId}-${lowBalanceThreshold}`;
    notifications.push({
      id,
      type: "low_balance",
      created_at: now,
      read: readIds.has(id),
      balance,
      threshold: lowBalanceThreshold,
      route: "settings",
      settingsTab: "credits",
    });
  }

  senderRequests.forEach((request) => {
    if (!request.status || request.status === "pending") {
      const id = `sender-${request.id || request.requested_id}-pending`;
      notifications.push({
        id,
        type: "sender_pending",
        created_at: toIso(request.created_at),
        read: readIds.has(id),
        sender_id: request.requested_id,
        route: "settings",
        settingsTab: "senderIds",
        metadata: { status: request.status, admin_notes: request.admin_notes },
      });
      return;
    }

    const typeMap: Record<string, UserNotificationType> = {
      approved: "sender_approved",
      rejected: "sender_rejected",
      revoked: "sender_revoked",
    };
    const type = typeMap[request.status];
    if (!type) return;

    const id = `sender-${request.id || request.requested_id}-${request.status}`;
    notifications.push({
      id,
      type,
      created_at: toIso(request.created_at),
      read: readIds.has(id),
      sender_id: request.requested_id,
      route: "settings",
      settingsTab: "senderIds",
      metadata: { status: request.status, admin_notes: request.admin_notes },
    });
  });

  transactions
    .filter((tx): tx is NotificationTransaction =>
      ["top_up", "credit_purchase", "refund", "manual_adjustment"].includes(tx.type) && Number(tx.amount || 0) > 0
    )
    .slice(0, 10)
    .forEach((tx) => {
      const id = `topup-${tx.transaction_id || tx.id || tx.reference_id || tx.created_at}`;
      notifications.push({
        id,
        type: "top_up_success",
        created_at: toIso(tx.created_at),
        read: readIds.has(id),
        amount: Number(tx.amount || 0),
        balance: Number(tx.balance_after ?? 0),
        description: tx.description,
        route: "settings",
        settingsTab: "credits",
        metadata: { reference_id: tx.reference_id, transaction_id: tx.transaction_id },
      });
    });

  // Sender ID reminder: shown whenever no Sender ID requests exist for this subaccount
  if (locationId && senderRequests.length === 0) {
    const id = `sender-reminder-${locationId}`;
    notifications.push({
      id,
      type: "sender_id_reminder",
      created_at: now,
      read: readIds.has(id),
      route: "settings",
      settingsTab: "senderIds",
    });
  }

  return notifications;
}

export function useUserNotifications() {
  const { locationId } = useLocationId();
  const [notifications, setNotifications] = useState<UserNotification[]>([]);
  const [loading, setLoading] = useState(true);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const fetchNotifications = useCallback(async (isInitial = false) => {
    if (!locationId) {
      setNotifications([]);
      setLoading(false);
      return;
    }

    if (isInitial) setLoading(true);
    const readIds = readReadIds(locationId);

    try {
      const remote = await fetchRemoteNotifications(locationId, readIds);
      const next = remote || await buildLocalNotifications(locationId, readIds);
      setNotifications(next);
    } catch {
      // Keep stale notifications visible if the refresh fails.
    } finally {
      if (isInitial) setLoading(false);
    }
  }, [locationId]);

  useEffect(() => {
    fetchNotifications(true);
    timerRef.current = setInterval(() => fetchNotifications(false), POLL_INTERVAL);

    const handleExternalRefresh = () => {
      void fetchNotifications(false);
    };

    window.addEventListener("nola-notifications-refresh", handleExternalRefresh);
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
      window.removeEventListener("nola-notifications-refresh", handleExternalRefresh);
    };
  }, [fetchNotifications]);

  const persistRead = useCallback((ids: string[]) => {
    if (!locationId) return;
    const readIds = readReadIds(locationId);
    ids.forEach((id) => readIds.add(id));
    writeReadIds(locationId, readIds);
  }, [locationId]);

  const markRead = useCallback((notificationId: string) => {
    setNotifications((prev) =>
      prev.map((notification) =>
        notification.id === notificationId ? { ...notification, read: true } : notification
      )
    );
    persistRead([notificationId]);
    if (locationId) {
      void postNotificationAction(locationId, { action: "mark_read", notification_id: notificationId });
    }
  }, [locationId, persistRead]);

  const markAllRead = useCallback(() => {
    const ids = notifications.map((notification) => notification.id);
    setNotifications((prev) => prev.map((notification) => ({ ...notification, read: true })));
    persistRead(ids);
    if (locationId) {
      void postNotificationAction(locationId, { action: "mark_all_read" });
    }
  }, [locationId, notifications, persistRead]);

  const unreadCount = useMemo(
    () => notifications.filter((notification) => !notification.read).length,
    [notifications]
  );

  return { notifications, loading, unreadCount, markRead, markAllRead, refetch: fetchNotifications };
}
