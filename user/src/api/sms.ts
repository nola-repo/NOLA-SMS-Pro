import type { SmsLog, BulkMessageHistoryItem, FirestoreMessage, Conversation } from "../types/Sms";

import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";
import { getAuthHeaders } from "../utils/authHeaders";
import { devLog } from "../utils/devLog";
import { apiFetch } from "../utils/apiFetch";

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
  details?: unknown;
  providerResponse?: unknown;
  status?: string;
  code?: string;
  number?: string;
  /** Message IDs returned by the backend for status polling */
  messageIds?: string[];
}

const createClientRequestId = (): string => {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `${Date.now()}_${Math.random().toString(36).slice(2)}`;
};

const createSmsIdempotencyKey = (locationId: string | null): string =>
  `sms_${locationId || "unknown"}_${createClientRequestId()}`;

const SMS_ERROR_MESSAGES: Record<string, string> = {
  invalid_phone: "A valid Philippine mobile number is required.",
  insufficient_credits: "Insufficient credits. Please top up or request more credits.",
  agency_master_lock: "SMS sending is paused by your agency wallet settings.",
  rate_limit_reached: "This subaccount has reached its sending limit.",
  sms_disabled: "SMS sending is currently disabled for this subaccount.",
  install_blocked: "The GoHighLevel app installation must be completed before sending.",
  LOCATION_CLEANUP_IN_PROGRESS: "This test workspace is temporarily unavailable while cleanup is in progress.",
  cleanup_in_progress: "This test workspace is temporarily unavailable while cleanup is in progress.",
  LOCATION_INSTALL_PENDING: "NOLA provisioning is still completing. Try again after setup finishes.",
  install_pending: "NOLA provisioning is still completing. Try again after setup finishes.",
  LOCATION_ONBOARDING_EXPIRED: "Marketplace onboarding expired. Restart the installation before sending.",
  onboarding_expired: "Marketplace onboarding expired. Restart the installation before sending.",
  LOCATION_NOT_INSTALLED: "NOLA SMS Pro is not installed for this location. Reinstall before sending.",
  LOCATION_UNINSTALLED: "NOLA SMS Pro was uninstalled for this location. Reinstall before sending.",
  app_uninstalled: "NOLA SMS Pro was uninstalled for this location. Reinstall before sending.",
  not_installed: "NOLA SMS Pro is not installed for this location. Reinstall before sending.",
  GHL_RECONNECT_REQUIRED: "Reconnect GoHighLevel before sending SMS.",
  duplicate_request: "This SMS request is already being processed.",
  idempotency_key_conflict: "This send request conflicts with a previous request. Please try again.",
  credit_deduction_failed: "Credits could not be deducted. Please try again.",
  provider_unavailable: "The SMS provider is currently unavailable. Please try again shortly.",
  rate_limit_unavailable: "Unable to verify send limit right now. Please retry shortly.",
  payload_too_large: "Message request is too large. Reduce recipients or message size and try again.",
  PAYLOAD_TOO_LARGE: "Message request is too large. Reduce recipients or message size and try again.",
};

interface SmsErrorPayload {
  error?: string;
  code?: string;
  message?: string;
  status?: string | number;
  details?: unknown;
  provider_response?: unknown;
  response?: unknown;
  output?: unknown;
  [key: string]: unknown;
}

const resolveSmsErrorMessage = (data: SmsErrorPayload | Record<string, unknown> | null | undefined, fallback = "SMS sending failed"): string => {
  const code = String(data?.error || data?.code || "").trim();
  if (data?.status === 413 || code === "413") {
    return SMS_ERROR_MESSAGES.payload_too_large;
  }
  return (typeof data?.message === "string" ? data.message : undefined) || SMS_ERROR_MESSAGES[code] || fallback;
};

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

// Function to fetch SMS History (legacy full sync + client filter method)
export const fetchSmsLogs = async (phoneNumber?: string, explicitLocationId?: string): Promise<SmsLog[]> => {
  if (!phoneNumber) return [];
  const formattedNumber = normalizePHNumber(phoneNumber);
  if (!formattedNumber) return [];

  try {
    const accountSettings = getAccountSettings();
    const locationId = explicitLocationId || accountSettings.ghlLocationId || null;
    const headers: Record<string, string> = { ...getAuthHeaders() };
    if (locationId) {
      headers['X-GHL-Location-ID'] = locationId;
    }

    // Fetch ALL outbound messages (without number filter)
    // Then filter client-side by checking if phoneNumber is in the numbers array
    let url = `${WEBHOOK_URL}?direction=outbound&limit=500`;
    if (locationId) {
      url += `&location_id=${encodeURIComponent(locationId)}`;
    }
    const res = await apiFetch(url, { headers });
    if (!res.ok) throw new Error("Failed to fetch message history");
    const data = await res.json();

    // Filter messages client-side - only include messages where the phone number is in the numbers array
    const allMessages: SmsLog[] = data.data || [];


    const filteredMessages = allMessages.filter(log => {
      // Block messages from other sub-accounts (cross-account isolation)
      if (log.location_id && locationId && log.location_id !== locationId) {
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

    return deduped;
  } catch (error) {
    devLog.error("Fetch Logs Error:", error);
    return [];
  }
};

export const interpolateMessage = (text: string, contact: { name?: string, phone?: string, email?: string }) => {
  if (!text) return text;
  let result = text;
  const name = contact.name || "";
  const first_name = name.split(" ")[0] || "";
  const last_name = name.split(" ").slice(1).join(" ") || "";
  
  result = result.replace(/\{\{contact\.name\}\}/gi, name);
  result = result.replace(/\{\{contact\.first_name\}\}/gi, first_name);
  result = result.replace(/\{\{contact\.last_name\}\}/gi, last_name);
  result = result.replace(/\{\{contact\.phone\}\}/gi, contact.phone || "");
  result = result.replace(/\{\{contact\.email\}\}/gi, contact.email || "");
  result = result.replace(/\{\{company\.name\}\}/gi, "NOLA SMS Pro");
  
  return result;
};

export const sendSms = async (
  phoneNumber: string,
  message: string,
  senderName?: string,
  batchId?: string,
  contactName?: string,
  recipientKey?: string,
  contactId?: string,
  tagsToApply?: string[],
  contactEmail?: string

): Promise<SendSmsResponse> => {
  const formattedNumber = normalizePHNumber(String(phoneNumber || ""));
  const messageText = String(message || "").trim();

  if (!formattedNumber || !messageText) {
    return {
      success: false,
      message: !formattedNumber ? "A valid phone number is required" : "Message text is required",
    };
  }

  if (!formattedNumber || !isValidPHNumber(formattedNumber)) {
    return {
      success: false,
      message: "Invalid Philippine mobile number",
    };
  }

  const personalizedMessage = interpolateMessage(messageText, { name: contactName, phone: formattedNumber, email: contactEmail });

  const payload = {
    number: formattedNumber,
    message: personalizedMessage,
    ...(senderName ? { sendername: senderName } : {}),
    customData: {
      number: formattedNumber,
      message: personalizedMessage,
      ...(senderName ? { sendername: senderName } : {}),
      batch_id: batchId,
      name: contactName,
      recipient_key: recipientKey,
      contactId: contactId,
      tagsToApply: tagsToApply,
    },
  };

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {
      "Content-Type": "application/json",
      ...getAuthHeaders(),
    };
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }
    headers['Idempotency-Key'] = createSmsIdempotencyKey(accountSettings.ghlLocationId || null);

    const SEND_SMS_URL = accountSettings.ghlLocationId 
      ? `${API_CONFIG.sms}?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`
      : API_CONFIG.sms;

    const res = await apiFetch(SEND_SMS_URL, {
      method: "POST",
      headers,
      body: JSON.stringify(payload),
    });

    if (!res.ok) {
      const errorText = await res.text();
      let parsed: SmsErrorPayload | null = null;
      try {
        parsed = JSON.parse(errorText) as SmsErrorPayload;
      } catch {
        parsed = null;
      }
      return {
        success: false,
        error: typeof parsed?.error === "string" ? parsed.error : undefined,
        code: typeof parsed?.code === "string" ? parsed.code : typeof parsed?.error === "string" ? parsed.error : undefined,
        details: parsed?.details || parsed?.provider_response || parsed,
        providerResponse: parsed?.provider_response || parsed?.response || parsed?.output,
        message: parsed ? resolveSmsErrorMessage(parsed, `SMS request failed (${res.status})`) : (errorText || `SMS request failed (${res.status})`),
      };
    }

    const data = await res.json();

    if (data?.status === "error" || data?.status === "failed") {
      return {
        success: false,
        error: data.error,
        code: data.code || data.error,
        details: data.details || data.provider_response || data.response || data.output,
        providerResponse: data.provider_response || data.response || data.output,
        message: resolveSmsErrorMessage(data),
      };
    }

    if (data?.output?.success === false) {
      return {
        success: false,
        details: data.output || data.response || data,
        providerResponse: data.output || data.response,
        message: data.execution_log || data.message || "SMS gateway did not accept the message",
      };
    }

    // Extract message IDs from various possible response formats
    let extractedMessageIds: string[] = [];
    if (data.output?.message_ids) {
      extractedMessageIds = data.output.message_ids;
    } else if (Array.isArray(data.response)) {
      extractedMessageIds = data.response
        .map((r: { message_id?: unknown }) => r.message_id ? String(r.message_id) : null)
        .filter(Boolean);
    } else if (data.message_id) {
      extractedMessageIds = [String(data.message_id)];
    }

    if (extractedMessageIds.length === 0) {
      return {
        success: false,
        details: data.output || data.response || data,
        providerResponse: data.output || data.response,
        message: data.execution_log || "SMS gateway did not return a message ID",
      };
    }

    return {
      success: true,
      message: data.message || "Message sent successfully",
      messageIds: extractedMessageIds,
    };
  } catch (error) {
    devLog.error("SMS Error:", error);
    return {
      success: false,
      message: error instanceof Error ? error.message : "SMS failed",
    };
  }
};

/**
 * Poll the backend for real-time status of specific message IDs.
 * Used immediately after send to get live Semaphore delivery status.
 * Returns a map of { messageId -> 'Sent' | 'Sending' | 'Failed' }
 */
export const checkMessageStatus = async (
  messageIds: string[],
  explicitLocationId?: string
): Promise<Record<string, string>> => {
  if (!messageIds.length) return {};

  try {
    const accountSettings = getAccountSettings();
    const locationId = explicitLocationId || accountSettings.ghlLocationId || null;
    const headers: Record<string, string> = { ...getAuthHeaders() };
    if (locationId) headers['X-GHL-Location-ID'] = locationId;

    let url = `${API_CONFIG.check_message_status}?message_ids=${encodeURIComponent(messageIds.join(','))}`;
    if (locationId) url += `&location_id=${encodeURIComponent(locationId)}`;

    const res = await apiFetch(url, { headers });
    if (!res.ok) return {};
    const data = await res.json();

    const result: Record<string, string> = {};
    for (const item of (data.results || [])) {
      if (item.message_id) result[item.message_id] = item.status;
    }
    return result;
  } catch {
    return {};
  }
};

export const sendBulkSms = async (
  phoneNumbers: string[],
  message: string,
  senderName?: string,
  _contacts: { phone: string, name: string, email?: string, ghl_contact_id?: string }[] = [],
  recipientKey?: string,
  existingBatchId?: string,
  tagsToApply?: string[],
  onProgress?: (current: number, total: number, result: SendSmsResponse) => void
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
  let current = 0;
  for (const phone of normalizedNumbers) {
    // Find corresponding contact to extract ghl_contact_id
    const contact = _contacts.find(c => normalizePHNumber(c.phone) === phone) || { phone, name: undefined, email: undefined, ghl_contact_id: undefined };
    let res: SendSmsResponse;
    try {
      res = await sendSms(phone, message, senderName, batchId, contact.name, recipientKey, contact.ghl_contact_id, tagsToApply, contact.email);
      results.push({ ...res, number: phone });
    } catch (error) {
      devLog.error(`[sendBulkSms] Error sending to ${phone}:`, error);
      res = {
        success: false,
        number: phone,
        message: error instanceof Error ? error.message : "Bulk SMS piece failed",
      };
      results.push(res);
    }
    current++;
    if (onProgress) {
      onProgress(current, normalizedNumbers.length, res);
    }
  }

  return { results, batchId };
};

export const fetchBatchMessages = async (batchId: string): Promise<SmsLog[]> => {
  if (!batchId) return [];

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = { ...getAuthHeaders() };
    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    let url = `${WEBHOOK_URL}?batch_id=${encodeURIComponent(batchId)}&limit=500`;
    if (accountSettings.ghlLocationId) {
      url += `&location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }
    const res = await apiFetch(url, { headers });
    if (!res.ok) throw new Error("Failed to fetch batch messages");
    const data = await res.json();
    return data.data || [];
  } catch (error) {
    devLog.error("Fetch Batch Error:", error);
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
  recipientKey?: string,
  explicitLocationId?: string
): Promise<FirestoreMessage[]> => {
  if (!conversationId) return [];

  const accountSettings = getAccountSettings();
  const locationId = explicitLocationId || accountSettings.ghlLocationId || null;
  const headers: Record<string, string> = { ...getAuthHeaders() };
  if (locationId) {
    headers["X-GHL-Location-ID"] = locationId;
  }

  const bulkBatchId = (() => {
    const scopedIdx = conversationId.lastIndexOf("_group_");
    return scopedIdx !== -1
      ? conversationId.slice(scopedIdx + "_group_".length)
      : conversationId.startsWith("group_")
        ? conversationId.slice("group_".length)
        : "";
  })();

  const getBulkConversationCandidates = () => {
    if (!bulkBatchId) return [conversationId];

    const candidates = [
      conversationId,
      `group_${bulkBatchId}`,
      locationId ? `${locationId}_group_${bulkBatchId}` : "",
    ].filter(Boolean);

    return Array.from(new Set(candidates));
  };

  const conversationCandidates = getBulkConversationCandidates();

  const buildMessagesUrl = (candidateId: string) => {
    let url = `${API_CONFIG.messages}?conversation_id=${encodeURIComponent(
      candidateId
    )}&limit=${limit}`;
    if (locationId) {
      url += `&location_id=${encodeURIComponent(locationId)}`;
    }

    if (recipientKey) {
      url += `&recipient_key=${encodeURIComponent(recipientKey)}`;
    }

    return url;
  };

  const fetchCandidate = async (candidateId: string) => {
    let res: Response;
    try {
      res = await apiFetch(buildMessagesUrl(candidateId), { headers });
    } catch (err) {
      devLog.error("[fetchMessagesByConversationId] Network error:", err);
      throw new ConversationMessagesError(
        "Network error while fetching conversation messages",
        undefined,
        err
      );
    }

    let rawBody: string | null = null;
    let parsedBody: unknown = null;

    try {
      rawBody = await res.text();
      parsedBody = rawBody ? JSON.parse(rawBody) : null;
    } catch (err) {
      // If JSON parsing fails we still want to surface some diagnostics
      devLog.error("[fetchMessagesByConversationId] Failed to parse JSON:", err);
    }

    if (!res.ok) {
      const status = res.status;
      const parsedRecord =
        parsedBody && typeof parsedBody === "object" && !Array.isArray(parsedBody)
          ? parsedBody as Record<string, unknown>
          : null;
      const backendError = parsedRecord?.error || parsedRecord?.error_message;
      const backendDetail = parsedRecord?.message || parsedRecord?.details;
      // Prefer detailed message when "error" is generic.
      const backendMessage =
        (backendError && backendDetail && backendError === "Failed to fetch messages"
          ? backendDetail
          : (backendError || backendDetail)) || rawBody || "";
      const message = `Failed to fetch conversation messages: ${status}${
        backendMessage ? ` - ${backendMessage}` : ""
      }`;
      devLog.error("[fetchMessagesByConversationId] Error response:", {
        status,
        backendMessage,
        conversationId: candidateId,
      });
      throw new ConversationMessagesError(message, status, parsedBody ?? rawBody);
    }

    if (Array.isArray(parsedBody)) {
      return parsedBody as FirestoreMessage[];
    }

    if (parsedBody && typeof parsedBody === "object") {
      const data = (parsedBody as Record<string, unknown>).data;
      return Array.isArray(data) ? data as FirestoreMessage[] : [];
    }

    return [];
  };

  let rows: FirestoreMessage[] = [];
  let lastError: unknown = null;
  let fetchedSuccessfully = false;

  for (const candidateId of conversationCandidates) {
    try {
      rows = await fetchCandidate(candidateId);
      fetchedSuccessfully = true;
      if (rows.length > 0 || candidateId === conversationCandidates[conversationCandidates.length - 1]) {
        break;
      }
    } catch (err) {
      lastError = err;
      if (candidateId === conversationCandidates[conversationCandidates.length - 1]) {
        if (!fetchedSuccessfully) {
          throw err;
        }
        break;
      }
    }
  }

  if (!fetchedSuccessfully && lastError) {
    throw lastError;
  }

  if (rows.length === 0 && bulkBatchId) {
    const batchRows = await fetchBatchMessages(bulkBatchId);
    rows = batchRows.map((log, index) => ({
      id: log.message_id || `${bulkBatchId}-${index}`,
      conversation_id: conversationId,
      number: log.number || log.numbers?.[0] || "",
      message: log.message || "",
      direction: log.direction || 'outbound',
      sender_id: log.sender_id || "NOLASMSPro",
      sender_name: log.sender_name,
      status: log.status || "sent",
      batch_id: log.batch_id || bulkBatchId,
      created_at: log.date_created || null,
      location_id: log.location_id,
      error_reason: log.error_reason,
      error_code: log.error_code,
      provider_status: log.provider_status,
      provider_response: log.provider_response,
      provider_message_id: log.provider_message_id,
      provider_reference_id: log.provider_reference_id,
    }));
  }

  const currentLocationId = locationId || accountSettings.ghlLocationId || null;
  const acceptedConversationIds = new Set(conversationCandidates);

  // Ensure we only show messages that truly belong to this conversation_id
  // Fallback to legacy unscoped `conv_` IDs for old messages until backend updates are complete.
  const filtered = rows.filter((row) => {
    // If the message has a location_id AND it doesn't match ours — block it.
    // (This is the primary cross-account bleed prevention gate.)
    if (row.location_id && currentLocationId && row.location_id !== currentLocationId) {
      return false;
    }

    if (acceptedConversationIds.has(row.conversation_id)) return true;
    
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
export const fetchConversations = async (explicitLocationId?: string): Promise<Conversation[]> => {
  try {
    const accountSettings = getAccountSettings();
    const locationId = explicitLocationId || accountSettings.ghlLocationId || null;
    const headers: Record<string, string> = { ...getAuthHeaders() };

    if (locationId) headers['X-GHL-Location-ID'] = locationId;

    let CONVERSATIONS_URL = API_CONFIG.conversations;
    if (locationId) {
      CONVERSATIONS_URL += `?location_id=${encodeURIComponent(locationId)}`;
    }
    const res = await apiFetch(CONVERSATIONS_URL, { headers });
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
        const dedupeKey = normalizePHNumber(phone) || phone.replace(/\D/g, '').slice(-10) || phone;
        const existing = directDedup.get(dedupeKey);
        if (!existing) {
          directDedup.set(dedupeKey, conv);
        } else {
          // Both scoped — keep most recent
          const newTime = new Date(conv.last_message_at || conv.updated_at || 0).getTime();
          const existTime = new Date(existing.last_message_at || existing.updated_at || 0).getTime();
          if (newTime >= existTime) directDedup.set(dedupeKey, conv);
        }
      } else if (conv.id.startsWith('conv_')) {
        // Legacy unscoped: conv_PHONE — only add if no scoped version exists yet
        const phone = conv.id.slice(5);
        const dedupeKey = normalizePHNumber(phone) || phone.replace(/\D/g, '').slice(-10) || phone;
        if (!directDedup.has(dedupeKey)) {
          directDedup.set(dedupeKey, conv);
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
    const currentLocationPrefix = locationId
      ? `${locationId}_conv_`
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
    devLog.error('[fetchConversations] Error:', error);
    return [];
  }
};


// Fetch messages by recipient_key (conversation key)
export const fetchMessagesByRecipientKey = async (recipientKey: string, explicitLocationId?: string): Promise<SmsLog[]> => {
  if (!recipientKey) return [];

  try {
    const accountSettings = getAccountSettings();
    const locationId = explicitLocationId || accountSettings.ghlLocationId || null;
    const headers: Record<string, string> = {};
    if (locationId) {
      headers['X-GHL-Location-ID'] = locationId;
    }

    let url = `${WEBHOOK_URL}?recipient_key=${encodeURIComponent(recipientKey)}&limit=500`;
    if (locationId) {
      url += `&location_id=${encodeURIComponent(locationId)}`;
    }
    const res = await apiFetch(url, { headers });
    if (!res.ok) throw new Error("Failed to fetch messages by recipient_key");
    const data = await res.json();
    // Handle both array response and {data: [...]} response
    return Array.isArray(data) ? data : (data.data || []);
  } catch (error) {
    devLog.error("Fetch by Recipient Key Error:", error);
    return [];
  }
};

// Fetch all bulk messages from Firestore (grouped by batch)
export const fetchAllBulkMessages = async (explicitLocationId?: string): Promise<BulkMessageHistoryItem[]> => {
  try {
    const accountSettings = getAccountSettings();
    const locationId = explicitLocationId || accountSettings.ghlLocationId || null;
    const headers: Record<string, string> = { ...getAuthHeaders() };
    if (locationId) {
      headers['X-GHL-Location-ID'] = locationId;
    }

    let BULK_CAMPAIGNS_URL = API_CONFIG.bulk_campaigns;
    if (locationId) {
      BULK_CAMPAIGNS_URL += `?location_id=${encodeURIComponent(locationId)}`;
    }
    const res = await apiFetch(BULK_CAMPAIGNS_URL, { headers });
    if (!res.ok) {
      const errorText = await res.text();
      devLog.error('[fetchAllBulkMessages] Error response:', errorText);
      throw new Error(`Failed to fetch bulk messages: ${res.status}`);
    }
    const resData = await res.json();

    // Handle both array and { data: [...] } format
    type BulkCampaignResponse = {
      batch_id?: string;
      message?: string;
      recipientCount?: number;
      recipientNumbers?: string[];
      timestamp?: string;
      status?: string;
      location_id?: string;
    };

    const messages = (Array.isArray(resData) ? resData : (resData.data || [])) as BulkCampaignResponse[];

    // Convert to BulkMessageHistoryItem format
    return messages.map((item) => {
      const batchId = item.batch_id || "";
      return {
        id: `bulk-db-${batchId}`,
        message: item.message || '',
        recipientCount: item.recipientCount || 0,
        recipientNumbers: item.recipientNumbers || [],
        recipientKey: batchId,
        timestamp: item.timestamp || new Date().toISOString(),
        status: item.status || 'sent',
        batchId,
        fromDatabase: true,
        locationId: item.location_id,
      };
    });
  } catch (error) {
    devLog.error("[fetchAllBulkMessages] Error:", error);
    return [];
  }
};
/**
 * Rename a conversation (bulk or direct) in the backend.
 */
export const renameConversation = async (conversationId: string, newName: string, explicitLocationId?: string): Promise<boolean> => {
  if (!conversationId || !newName) return false;

  try {
    const accountSettings = getAccountSettings();
    const locationId = explicitLocationId || accountSettings.ghlLocationId || null;
    const headers: Record<string, string> = { 'Content-Type': 'application/json', ...getAuthHeaders() };
    if (locationId) {
      headers['X-GHL-Location-ID'] = locationId;
    }

    let url = API_CONFIG.messages;
    if (locationId) {
      url += `?location_id=${encodeURIComponent(locationId)}`;
    }

    const res = await apiFetch(url, {
      method: 'PUT',
      headers,
      body: JSON.stringify({ id: conversationId, name: newName }),
    });

    if (!res.ok) throw new Error(`Failed to rename conversation: ${res.status}`);
    return true;
  } catch (error) {
    devLog.error('[renameConversation] Error:', error);
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

    const res = await apiFetch(url, {
      method: 'DELETE',
      headers,
    });

    if (!res.ok) throw new Error(`Failed to delete conversation: ${res.status}`);
    return true;
  } catch (error) {
    devLog.error('[deleteConversation] Error:', error);
    return false;
  }
};
