import { safeStorage } from './safeStorage';
import type { BulkMessageHistoryItem, Message } from "../types/Sms";
import { getAccountSettings } from "../utils/settingsStorage";

const BULK_HISTORY_KEY = "nola_sms_bulk_history";
const DELETED_CONTACTS_KEY = "nola_sms_deleted_contacts";
const MESSAGES_CACHE_KEY = "nola_sms_messages_cache";
const BULK_GROUP_NAMES_KEY = "nola_sms_bulk_group_names";

// ─── Tenant Scoping ────────────────────────────────────────────────────────────
// In a multi-tenant environment (GHL locations), we must scope any persistent
// localStorage data by the current location_id to avoid cross-account bleed.
const getTenantKey = (): string => {
  try {
    return getAccountSettings().ghlLocationId || "__no_location__";
  } catch {
    return "__no_location__";
  }
};

const withTenant = (baseKey: string): string => {
  return `${baseKey}::${getTenantKey()}`;
};

interface MessagesCache {
  [phoneNumber: string]: Message[];
}

export const getDeletedContactIds = (): string[] => {
  try {
    const stored = safeStorage.getItem(withTenant(DELETED_CONTACTS_KEY));
    if (!stored) return [];
    return JSON.parse(stored);
  } catch (error) {
    return [];
  }
};

export const deleteContact = (id: string): void => {
  try {
    const existing = getDeletedContactIds();
    if (!existing.includes(id)) {
      const updated = [...existing, id];
      safeStorage.setItem(withTenant(DELETED_CONTACTS_KEY), JSON.stringify(updated));
    }
  } catch (error) {
    console.error("Failed to delete contact:", error);
  }
};

export const restoreContact = (id: string): void => {
  try {
    const existing = getDeletedContactIds();
    const updated = existing.filter(cid => cid !== id);
    safeStorage.setItem(withTenant(DELETED_CONTACTS_KEY), JSON.stringify(updated));
  } catch (error) {
    console.error("Failed to restore contact:", error);
  }
};

export const saveBulkMessage = (item: BulkMessageHistoryItem): void => {
  try {
    const existing = getBulkMessageHistory();
    const updated = [item, ...existing].slice(0, 50); // Keep max 50 items
    safeStorage.setItem(withTenant(BULK_HISTORY_KEY), JSON.stringify(updated));
  } catch (error) {
    console.error("Failed to save bulk message history:", error);
  }
};

export const getBulkMessageHistory = (): BulkMessageHistoryItem[] => {
  try {
    const stored = safeStorage.getItem(withTenant(BULK_HISTORY_KEY));
    if (!stored) return [];
    const history: BulkMessageHistoryItem[] = JSON.parse(stored);

    // Mix in custom names from groups if they don't have one
    const groupNames = getBulkGroupNames();
    return history.map(item => ({
      ...item,
      customName: item.customName || groupNames[item.recipientKey]
    }));
  } catch (error) {
    console.error("Failed to load bulk message history:", error);
    return [];
  }
};

export const getRecipientKey = (numbers: string[]): string => {
  return [...numbers].sort().join(',');
};

const getBulkGroupNames = (): Record<string, string> => {
  try {
    const stored = safeStorage.getItem(withTenant(BULK_GROUP_NAMES_KEY));
    return stored ? JSON.parse(stored) : {};
  } catch {
    return {};
  }
};

export const saveBulkGroupName = (recipientKey: string, name: string): void => {
  try {
    const groupNames = getBulkGroupNames();
    groupNames[recipientKey] = name;
    safeStorage.setItem(withTenant(BULK_GROUP_NAMES_KEY), JSON.stringify(groupNames));

    // Also update existing history items with this key
    const history = getBulkMessageHistory();
    const updated = history.map(item =>
      item.recipientKey === recipientKey ? { ...item, customName: name } : item
    );
    safeStorage.setItem(withTenant(BULK_HISTORY_KEY), JSON.stringify(updated));
  } catch (error) {
    console.error("Failed to save bulk group name:", error);
  }
};

export const getHistoryForGroup = (recipientKey: string): BulkMessageHistoryItem[] => {
  const history = getBulkMessageHistory();
  return history.filter(item => item.recipientKey === recipientKey);
};

export const clearBulkMessageHistory = (): void => {
  try {
    safeStorage.removeItem(withTenant(BULK_HISTORY_KEY));
  } catch (error) {
    console.error("Failed to clear bulk message history:", error);
  }
};

export const renameBulkMessage = (id: string, newName: string): void => {
  try {
    const existing = getBulkMessageHistory();
    const updated = existing.map(item =>
      item.id === id ? { ...item, customName: newName } : item
    );
    safeStorage.setItem(withTenant(BULK_HISTORY_KEY), JSON.stringify(updated));
  } catch (error) {
    console.error("Failed to rename bulk message:", error);
  }
};

export const deleteBulkMessage = (id: string): void => {
  try {
    const existing = getBulkMessageHistory();
    const updated = existing.filter(item => item.id !== id);
    safeStorage.setItem(withTenant(BULK_HISTORY_KEY), JSON.stringify(updated));
  } catch (error) {
    console.error("Failed to delete bulk message:", error);
  }
};

export const getRelativeTime = (timestamp: string): string => {
  const now = new Date();
  const then = new Date(timestamp);
  const diffMs = now.getTime() - then.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMins / 60);
  const diffDays = Math.floor(diffHours / 24);

  if (diffMins < 1) return "now";
  if (diffMins < 60) return `${diffMins}m`;
  if (diffHours < 24) return `${diffHours}h`;
  if (diffDays < 7) return `${diffDays}d`;
  return then.toLocaleDateString();
};

// Message caching functions
const getMessagesCache = (): MessagesCache => {
  try {
    const stored = safeStorage.getItem(MESSAGES_CACHE_KEY);
    if (!stored) return {};
    return JSON.parse(stored);
  } catch {
    return {};
  }
};

const setMessagesCache = (cache: MessagesCache): void => {
  try {
    safeStorage.setItem(MESSAGES_CACHE_KEY, JSON.stringify(cache));
  } catch {
    console.error("Failed to save messages to cache");
  }
};

export const getCachedMessages = (phoneNumber: string): Message[] | null => {
  const cache = getMessagesCache();
  return cache[phoneNumber] || null;
};

export const setCachedMessages = (phoneNumber: string, messages: Message[]): void => {
  const cache = getMessagesCache();
  cache[phoneNumber] = messages;
  setMessagesCache(cache);
};

export const updateMessageInCache = (phoneNumber: string, tempId: string, status: Message['status'], realId?: string): void => {
  const cache = getMessagesCache();
  if (!cache[phoneNumber]) return;

  cache[phoneNumber] = cache[phoneNumber].map(msg =>
    msg.id === tempId ? { ...msg, status, id: realId || msg.id } : msg
  );
  setMessagesCache(cache);
};
