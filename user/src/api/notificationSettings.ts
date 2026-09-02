import { devLog } from '../utils/devLog';
import { API_CONFIG } from "../config";
import {
  getAccountSettings,
  getNotificationSettings,
  saveNotificationSettings as saveLocalNotificationSettings,
  type NotificationSettings,
} from "../utils/settingsStorage";
import { getAuthHeaders } from "../utils/authHeaders";
import { apiFetch } from "../utils/apiFetch";

const API_URL = API_CONFIG.notificationSettings;

const getHeaders = (): Record<string, string> => {
  const accountSettings = getAccountSettings();
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    ...getAuthHeaders(),
  };

  if (accountSettings.ghlLocationId) {
    headers["X-GHL-Location-ID"] = accountSettings.ghlLocationId;
  }

  return headers;
};

const normalizeSettings = (payload: any): NotificationSettings => {
  const data = payload?.data && typeof payload.data === "object" ? payload.data : payload;
  const fallback = getNotificationSettings();

  return {
    deliveryReports: Boolean(data?.deliveryReports ?? data?.delivery_reports_enabled ?? fallback.deliveryReports),
    lowBalanceAlert: Boolean(data?.lowBalanceAlert ?? data?.low_balance_alert_enabled ?? fallback.lowBalanceAlert),
    lowBalanceThreshold: Number(data?.lowBalanceThreshold ?? data?.low_balance_threshold ?? fallback.lowBalanceThreshold),
    marketingEmails: Boolean(data?.marketingEmails ?? data?.marketing_emails_enabled ?? fallback.marketingEmails),
    ghlWorkflowSyncEnabled: Boolean(data?.ghlWorkflowSyncEnabled ?? data?.ghl_workflow_sync_enabled ?? fallback.ghlWorkflowSyncEnabled),
    alertEmail: String(data?.alertEmail ?? data?.alert_email ?? fallback.alertEmail ?? ""),
    ghlAlertContactId: String(data?.ghlAlertContactId ?? data?.ghl_alert_contact_id ?? fallback.ghlAlertContactId ?? ""),
  };
};

export const fetchNotificationSettings = async (): Promise<NotificationSettings> => {
  const accountSettings = getAccountSettings();
  if (!accountSettings.ghlLocationId) {
    return getNotificationSettings();
  }

  try {
    const res = await apiFetch(API_URL, { headers: getHeaders() });

    if (res.status === 404) {
      return getNotificationSettings();
    }

    if (!res.ok) {
      throw new Error(`Failed to load notification settings: ${res.statusText}`);
    }

    const normalized = normalizeSettings(await res.json());
    saveLocalNotificationSettings(normalized);
    return normalized;
  } catch (error) {
    devLog.error("Failed to fetch notification settings:", error);
    return getNotificationSettings();
  }
};

export const saveNotificationSettings = async (data: NotificationSettings): Promise<NotificationSettings> => {
  const accountSettings = getAccountSettings();

  if (!accountSettings.ghlLocationId) {
    saveLocalNotificationSettings(data);
    return data;
  }

  try {
    const res = await apiFetch(API_URL, {
      method: "POST",
      headers: getHeaders(),
      body: JSON.stringify({
        deliveryReports: data.deliveryReports,
        lowBalanceAlert: data.lowBalanceAlert,
        lowBalanceThreshold: data.lowBalanceThreshold,
        marketingEmails: data.marketingEmails,
        ghlWorkflowSyncEnabled: data.ghlWorkflowSyncEnabled,
      }),
    });

    if (res.status === 404) {
      saveLocalNotificationSettings(data);
      return data;
    }

    if (!res.ok) {
      const error = await res.json().catch(() => ({}));
      throw new Error(error.message || "Failed to save notification settings.");
    }

    const normalized = {
      ...data,
      ...normalizeSettings(await res.json()),
      deliveryReports: data.deliveryReports,
      marketingEmails: data.marketingEmails,
      lowBalanceAlert: data.lowBalanceAlert,
      ghlWorkflowSyncEnabled: data.ghlWorkflowSyncEnabled,
    };
    saveLocalNotificationSettings(normalized);
    return normalized;
  } catch (error) {
    devLog.error("Failed to save notification settings:", error);
    throw error;
  }
};

export type { NotificationSettings };
