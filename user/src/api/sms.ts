import type { SmsLog, BulkMessageHistoryItem, FirestoreMessage, Conversation } from "../types/Sms";

import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";

const WEBHOOK_URL = API_CONFIG.messages;

export type SenderId = string;

export class ConversationMessagesError extends Error {
  status?: number;
  details?: unknown;

  constructor(message: string, status?: number, details?: unknown) {
    super(message);
    this.name = "ConversationMessagesError";
    this.status = status;
    this.details = details;
  }
}

interface SendSmsResponse {
  success?: boolean;
  message?: string;
  error?: string;
  status?: string;
  number?: string;
}

/**
 * Normalize Philippine phone numbers to 09XXXXXXXXX
 * This matches the backend's clean_numbers function
 */
export const normalizePHNumber = (input: string): string | null => {
  if (!input) return null;

  const digits = input.replace(/\D/g, "");

  // 09XXXXXXXXX → valid
  if (digits.startsWith("09") && digits.length === 11) {
    return digits;
  }

  // 9XXXXXXXXX → 09XXXXXXXXX
  if (digits.startsWith("9") && digits.length === 10) {
    return "0" + digits;
  }

  // 639XXXXXXXXX → 09XXXXXXXXX
  if (digits.startsWith("639") && digits.length === 12) {
    return "0" + digits.substring(2);
  }

  // +639XXXXXXXXX (already digits only)
  if (digits.startsWith("639") && digits.length === 12) {
    return "0" + digits.substring(2);
  }

  return null;
};

const isValidPHNumber = (number: string): boolean => {
  return /^09\d{9}$/.test(number);
};

export const fetchSmsLogs = async (phoneNumber: string): Promise<SmsLog[]> => {
  const formattedNumber = normalizePHNumber(phoneNumber);
  if (!formattedNumber) return [];

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {};
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    // Fetch ALL outbound messages (without number filter)
    // Then filter client-side by checking if phoneNumber is in the numbers array
    let url = `${WEBHOOK_URL}?direction=outbound&limit=500`;
    if (accountSettings.ghlLocationId) {
      url += `&location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }
    const res = await fetch(url, { headers });
    if (!res.ok) throw new Error("Failed to fetch message history");
    const data = await res.json();
    console.log('SMS Logs Response:', data);

    // Filter messages client-side - only include messages where the phone number is in the numbers array
    const allMessages: SmsLog[] = data.data || [];

    // Debug: show what we're comparing
    console.log(`🔍 Looking for contact number: ${formattedNumber}`);
    console.log(`📦 Total messages from API: ${allMessages.length}`);
    allMessages.forEach((log, i) => {
      const rawNumbers = log.numbers || [];
      const normalizedNumbers = rawNumbers.map(n => normalizePHNumber(n)).filter(Boolean);
      const match = normalizedNumbers.includes(formattedNumber);
      console.log(`  Message ${i + 1}: "${log.message?.substring(0, 30)}..." | raw numbers: [${rawNumbers.join(', ')}] → normalized: [${normalizedNumbers.join(', ')}] | match: ${match ? '✅' : '❌'}`);
    });

    const filteredMessages = allMessages.filter(log => {
      // Block messages from other sub-accounts (cross-account isolation)
      if (log.location_id && accountSettings.ghlLocationId && log.location_id !== accountSettings.ghlLocationId) {
        return false;
      }
      const normalizedNumbers = (log.numbers || []).map(n => normalizePHNumber(n)).filter(Boolean);
      return normalizedNumbers.includes(formattedNumber);
    });

    // Deduplicate by message_id to prevent duplicate entries from legacy + scoped conversation docs
    const seenMsgIds = new Set<string>();
    const deduped = filteredMessages.filter(log => {
      const key = log.message_id || `${log.message}_${log.date_created}`;
      if (seenMsgIds.has(key)) return false;
      seenMsgIds.add(key);
      return true;
    });

    console.log(`✅ Filtered messages for ${formattedNumber}: ${deduped.length} found (${filteredMessages.length} before dedup)`);
    return deduped;
  } catch (error) {
    console.error("Fetch Logs Error:", error);
    return [];
  }
};

export const sendSms = async (
  phoneNumber: string,
  message: string,
  senderName: string = "NOLASMSPro",
  batchId?: string,
  contactName?: string,
  recipientKey?: string,
  contactId?: string
): Promise<SendSmsResponse> => {
  if (!phoneNumber || !message) {
    return {
      success: false,
      message: "Phone number and message are required",
    };
  }

  const formattedNumber = normalizePHNumber(phoneNumber);

  if (!formattedNumber || !isValidPHNumber(formattedNumber)) {
    return {
      success: false,
      message: "Invalid Philippine mobile number",
    };
  }

  const payload = {
    customData: {
      number: formattedNumber,
      message: message,
      sendername: senderName,
      batch_id: batchId,
      name: contactName,
      recipient_key: recipientKey,
      contactId: contactId,
    },
  };

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {
      "Content-Type": "application/json",
    };
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    const SEND_SMS_URL = accountSettings.ghlLocationId 
      ? `${API_CONFIG.sms}?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`
      : API_CONFIG.sms;

    console.log("Sending SMS payload via proxy:", payload);
    console.log("Sending to:", SEND_SMS_URL);

    const res = await fetch(SEND_SMS_URL, {
      method: "POST",
      headers,
      body: JSON.stringify(payload),
    });

    if (!res.ok) {
      const errorText = await res.text();
      throw new Error(`HTTP ${res.status}: ${errorText}`);
    }

    const data = await res.json();
    console.log("SMS API Response:", data);

    if (data?.status === "error" || data?.status === "failed") {
      return {
        success: false,
        message: data.message || "SMS sending failed",
      };
    }

    return {
      success: true,
      message: data.message || "Message sent successfully",
    };
  } catch (error) {
    console.error("SMS Error:", error);
    return {
      success: false,
      message: error instanceof Error ? error.message : "SMS failed",
    };
  }
};

export const sendBulkSms = async (
  phoneNumbers: string[],
  message: string,
  senderName: string = "NOLASMSPro",
  _contacts: { phone: string, name: string, ghl_contact_id?: string }[] = [],
  recipientKey?: string,
  existingBatchId?: string
): Promise<{ results: SendSmsResponse[], batchId: string }> => {
  // Normalize and validate all phone numbers up front
  const normalizedNumbers: string[] = [];
  for (const phone of phoneNumbers) {
    const normalized = normalizePHNumber(phone);
    if (normalized && isValidPHNumber(normalized)) {
      if (!normalizedNumbers.includes(normalized)) {
        normalizedNumbers.push(normalized);
      }
    }
  }

  if (!message || normalizedNumbers.length === 0) {
    return {
      results: normalizedNumbers.map((n) => ({
        success: false,
        number: n,
        message: !message
          ? "Message text is required"
          : "No valid Philippine mobile numbers to send",
      })),
      batchId: existingBatchId || `batch-${Date.now()}`,
    };
  }

  // Use existing batchId if provided, otherwise create a new one (dash style to match backend docs)
  const batchId = existingBatchId || `batch-${Date.now()}`;
  const results: SendSmsResponse[] = [];

  // Sequentially send SMS so that we can pass the ghl_contact_id per recipient for full bidirectional sync
  for (const phone of normalizedNumbers) {
    // Find corresponding contact to extract ghl_contact_id
    const contact = _contacts.find(c => normalizePHNumber(c.phone) === phone) || { phone, name: undefined, ghl_contact_id: undefined };
    try {
      const res = await sendSms(phone, message, senderName, batchId, contact.name, recipientKey, contact.ghl_contact_id);
      results.push({ ...res, number: phone });
    } catch (error) {
      console.error(`[sendBulkSms] Error sending to ${phone}:`, error);
      results.push({
        success: false,
        number: phone,
        message: error instanceof Error ? error.message : "Bulk SMS piece failed",
      });
    }
  }

  return { results, batchId };
};

export const fetchBatchMessages = async (batchId: string): Promise<SmsLog[]> => {
  if (!batchId) return [];

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {};
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    let url = `${WEBHOOK_URL}?batch_id=${encodeURIComponent(batchId)}&limit=500`;
    if (accountSettings.ghlLocationId) {
      url += `&location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }
    const res = await fetch(url, { headers });
    if (!res.ok) throw new Error("Failed to fetch batch messages");
    const data = await res.json();
    return data.data || [];
  } catch (error) {
    console.error("Fetch Batch Error:", error);
    return [];
  }
};

/**
 * Fetch messages for a single conversation (direct or bulk) by conversation_id.
 * This is the primary way to load chat history – avoids bulk mixing.
 * Direct:  conversation_id = {locationId}_conv_09XXXXXXXXX (or legacy conv_09XXXXXXXXX)
 * Bulk:    conversation_id = group_batch_xxx
 */
export const fetchMessagesByConversationId = async (
  conversationId: string,
  limit = 100,
  recipientKey?: string
): Promise<FirestoreMessage[]> => {
  if (!conversationId) return [];

  const accountSettings = getAccountSettings();
  const headers: Record<string, string> = {};
  if (accountSettings.ghlLocationId) {
    headers["X-GHL-Location-ID"] = accountSettings.ghlLocationId;
  }

  let url = `${API_CONFIG.messages}?conversation_id=${encodeURIComponent(
    conversationId
  )}&limit=${limit}`;
  if (accountSettings.ghlLocationId) {
    url += `&location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
  }

  // If recipientKey is provided, we're isolating a single user's history within a bulk campaign
  if (recipientKey) {
    url += `&recipient_key=${encodeURIComponent(recipientKey)}`;
  }

  let res: Response;
  try {
    res = await fetch(url, { headers });
  } catch (err) {
    console.error("[fetchMessagesByConversationId] Network error:", err);
    throw new ConversationMessagesError(
      "Network error while fetching conversation messages",
      undefined,
      err
    );
  }

  let rawBody: string | null = null;
  let parsedBody: any = null;

  try {
    rawBody = await res.text();
    parsedBody = rawBody ? JSON.parse(rawBody) : null;
  } catch (err) {
    // If JSON parsing fails we still want to surface some diagnostics
    console.error("[fetchMessagesByConversationId] Failed to parse JSON:", err);
  }

  if (!res.ok) {
    const status = res.status;
    const backendError = parsedBody && (parsedBody.error || parsedBody.error_message);
    const backendDetail = parsedBody && (parsedBody.message || parsedBody.details);
    // Prefer detailed message when "error" is generic.
    const backendMessage =
      (backendError && backendDetail && backendError === "Failed to fetch messages"
        ? backendDetail
        : (backendError || backendDetail)) || rawBody || "";
    const message = `Failed to fetch conversation messages: ${status}${
      backendMessage ? ` - ${backendMessage}` : ""
    }`;
    console.error("[fetchMessagesByConversationId] Error response:", {
      status,
      backendMessage,
    });
    throw new ConversationMessagesError(message, status, parsedBody ?? rawBody);
  }

  const data = parsedBody ?? {};
  const rows = (data.data || data || []) as FirestoreMessage[];
  const currentLocationId = accountSettings.ghlLocationId || null;

  // Ensure we only show messages that truly belong to this conversation_id
  // Fallback to legacy unscoped `conv_` IDs for old messages until backend updates are complete.
  const filtered = rows.filter((row) => {
    // If the message has a location_id AND it doesn't match ours — block it.
    // (This is the primary cross-account bleed prevention gate.)
    if (row.location_id && currentLocationId && row.location_id !== currentLocationId) {
      return false;
    }

    if (row.conversation_id === conversationId) return true;
    
    // Extract phone from scoped ID to match legacy unscoped ID
    let legacyDirectId = null;
    if (conversationId && conversationId.includes('_conv_')) {
      legacyDirectId = `conv_${conversationId.split('_conv_')[1]}`;
    }
    
    if (legacyDirectId && row.conversation_id === legacyDirectId) {
      // Only allow legacy messages if they have no location_id (truly legacy pre-multi-tenancy)
      // or their location_id matches ours.
      const hasWrongLocation = row.location_id && currentLocationId && row.location_id !== currentLocationId;
      return !hasWrongLocation;
    }
    return false;
  });

  // Deduplicate by message ID (prevents showing same message twice when legacy + scoped
  // conversation docs exist in the backend for the same contact)
  const seenIds = new Set<string>();
  return filtered.filter(row => {
    const key = row.id || `${row.message}_${row.created_at}`;
    if (seenIds.has(key)) return false;
    seenIds.add(key);
    return true;
  });
};

/**
 * Fetch all conversation metadata from the `conversations` Firestore collection.
 * Used by Sidebar to build the direct/bulk message list from server state.
 * Deduplicates conversations by phone number, preferring scoped IDs.
 */
export const fetchConversations = async (): Promise<Conversation[]> => {
  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {};
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    let CONVERSATIONS_URL = API_CONFIG.conversations;
    if (accountSettings.ghlLocationId) {
      CONVERSATIONS_URL += `?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }
    const res = await fetch(CONVERSATIONS_URL, { headers });
    if (!res.ok) throw new Error(`Failed to fetch conversations: ${res.status}`);
    const data = await res.json();
    const raw = (Array.isArray(data) ? data : (data.data || data.conversations || [])) as Conversation[];

    // Deduplicate direct conversations by phone number.
    // When legacy (conv_PHONE) and scoped (LOC_conv_PHONE) exist for the same contact,
    // prefer the scoped one so all new messages are correctly isolated to this sub-account.
    const directDedup = new Map<string, Conversation>();
    const others: Conversation[] = [];

    for (const conv of raw) {
      const scopedIdx = conv.id.lastIndexOf('_conv_');
      if (scopedIdx !== -1) {
        // Scoped direct conversation: LOC_conv_PHONE
        const phone = conv.id.slice(scopedIdx + 6);
        const existing = directDedup.get(phone);
        if (!existing) {
          directDedup.set(phone, conv);
        } else {
          // Both scoped — keep most recent
          const newTime = new Date(conv.last_message_at || conv.updated_at || 0).getTime();
          const existTime = new Date(existing.last_message_at || existing.updated_at || 0).getTime();
          if (newTime >= existTime) directDedup.set(phone, conv);
        }
      } else if (conv.id.startsWith('conv_')) {
        // Legacy unscoped: conv_PHONE — only add if no scoped version exists yet
        const phone = conv.id.slice(5);
        if (!directDedup.has(phone)) {
          directDedup.set(phone, conv);
        }
      } else {
        // Bulk or unknown — keep as-is
        others.push(conv);
      }
    }

    // Only surface scoped direct conversations (LOC_conv_PHONE).
    // Legacy unscoped (conv_PHONE) entries are silently hidden from the sidebar and
    // recent activity — they had no location fingerprint so we can't trust which
    // sub-account they belong to.
    const currentLocationPrefix = accountSettings.ghlLocationId
      ? `${accountSettings.ghlLocationId}_conv_`
      : null;

    const scopedDirectConvs = Array.from(directDedup.values()).filter(conv => {
      // Keep if it is a scoped conversation matching this location
      if (currentLocationPrefix && conv.id.startsWith(currentLocationPrefix)) return true;
      // No location prefix set — fall back to showing any _conv_ entry
      if (!currentLocationPrefix && conv.id.includes('_conv_')) return true;
      // Drop all plain conv_PHONE (legacy unscoped) entries
      return false;
    });

    return [...scopedDirectConvs, ...others];
  } catch (error) {
    console.error('[fetchConversations] Error:', error);
    return [];
  }
};


// Fetch messages by recipient_key (conversation key)
export const fetchMessagesByRecipientKey = async (recipientKey: string): Promise<SmsLog[]> => {
  if (!recipientKey) return [];

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {};
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    let url = `${WEBHOOK_URL}?recipient_key=${encodeURIComponent(recipientKey)}&limit=500`;
    if (accountSettings.ghlLocationId) {
      url += `&location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }
    const res = await fetch(url, { headers });
    if (!res.ok) throw new Error("Failed to fetch messages by recipient_key");
    const data = await res.json();
    // Handle both array response and {data: [...]} response
    return Array.isArray(data) ? data : (data.data || []);
  } catch (error) {
    console.error("Fetch by Recipient Key Error:", error);
    return [];
  }
};

// Fetch all bulk messages from Firestore (grouped by batch)
export const fetchAllBulkMessages = async (): Promise<BulkMessageHistoryItem[]> => {
  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {};
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    let BULK_CAMPAIGNS_URL = API_CONFIG.bulk_campaigns;
    if (accountSettings.ghlLocationId) {
      BULK_CAMPAIGNS_URL += `?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }
    const res = await fetch(BULK_CAMPAIGNS_URL, { headers });
    console.log('[fetchAllBulkMessages] Response status:', res.status);
    if (!res.ok) {
      const errorText = await res.text();
      console.error('[fetchAllBulkMessages] Error response:', errorText);
      throw new Error(`Failed to fetch bulk messages: ${res.status}`);
    }
    const resData = await res.json();
    console.log('[fetchAllBulkMessages] Data received:', resData);

    // Handle both array and { data: [...] } format
    const messages = Array.isArray(resData) ? resData : (resData.data || []);

    // Convert to BulkMessageHistoryItem format
    return messages.map((item: any) => ({
      id: `bulk-db-${item.batch_id}`,
      message: item.message || '',
      recipientCount: item.recipientCount || 0,
      recipientNumbers: item.recipientNumbers || [],
      recipientKey: item.batch_id,
      timestamp: item.timestamp || new Date().toISOString(),
      status: item.status || 'sent',
      batchId: item.batch_id,
      fromDatabase: true,
      locationId: item.location_id,
    }));
  } catch (error) {
    console.error("[fetchAllBulkMessages] Error:", error);
    return [];
  }
};
/**
 * Rename a conversation (bulk or direct) in the backend.
 */
export const renameConversation = async (conversationId: string, newName: string): Promise<boolean> => {
  if (!conversationId || !newName) return false;

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    let url = API_CONFIG.messages;
    if (accountSettings.ghlLocationId) {
      url += `?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }

    const res = await fetch(url, {
      method: 'PUT',
      headers,
      body: JSON.stringify({ id: conversationId, name: newName }),
    });

    if (!res.ok) throw new Error(`Failed to rename conversation: ${res.status}`);
    return true;
  } catch (error) {
    console.error('[renameConversation] Error:', error);
    return false;
  }
};

/**
 * Delete a conversation (bulk or direct) in the backend.
 */
export const deleteConversation = async (conversationId: string): Promise<boolean> => {
  if (!conversationId) return false;

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {};
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    let url = `${API_CONFIG.conversations}?id=${encodeURIComponent(conversationId)}`;
    if (accountSettings.ghlLocationId) {
      url += `&location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }

    const res = await fetch(url, {
      method: 'DELETE',
      headers,
    });

    if (!res.ok) throw new Error(`Failed to delete conversation: ${res.status}`);
    return true;
  } catch (error) {
    console.error('[deleteConversation] Error:', error);
    return false;
  }
};
