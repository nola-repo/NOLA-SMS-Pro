import type { SmsLog, BulkMessageHistoryItem, FirestoreMessage, Conversation } from "../types/Sms";

const WEBHOOK_URL = "/api/messages";
const WEBHOOK_SECRET = "f7RkQ2pL9zV3tX8cB1nS4yW6";

export type SenderId = string;

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
const normalizePHNumber = (input: string): string | null => {
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
    // Fetch ALL outbound messages (without number filter)
    // Then filter client-side by checking if phoneNumber is in the numbers array
    const res = await fetch(`${WEBHOOK_URL}?direction=outbound&limit=500`, {
      headers: {
        'X-Webhook-Secret': WEBHOOK_SECRET,
      },
    });
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
      const normalizedNumbers = (log.numbers || []).map(n => normalizePHNumber(n)).filter(Boolean);
      return normalizedNumbers.includes(formattedNumber);
    });

    console.log(`✅ Filtered messages for ${formattedNumber}: ${filteredMessages.length} found`);
    return filteredMessages;
  } catch (error) {
    console.error("Fetch Logs Error:", error);
    return [];
  }
};

export const sendSms = async (
  phoneNumber: string,
  message: string,
  senderName: string = "NOLACRM",
  batchId?: string,
  contactName?: string,
  recipientKey?: string
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
    },
  };

  const SEND_SMS_URL = "https://smspro-api.nolacrm.io/webhook/send_sms.php";
  console.log("Sending SMS payload directly to production:", payload);
  console.log("Sending to:", SEND_SMS_URL);

  try {
    const res = await fetch(SEND_SMS_URL, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Webhook-Secret": WEBHOOK_SECRET,
      },
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
  senderName: string = "NOLACRM",
  contacts: { phone: string, name: string }[] = [],
  recipientKey?: string,
  existingBatchId?: string
): Promise<{ results: SendSmsResponse[], batchId: string }> => {
  const results: SendSmsResponse[] = [];
  // Use existing batchId if provided, otherwise create a new one
  const batchId = existingBatchId || `batch-${Date.now()}`;

  for (const phone of phoneNumbers) {
    const contact = contacts.find(c => normalizePHNumber(c.phone) === normalizePHNumber(phone));
    const result = await sendSms(phone, message, senderName, batchId, contact?.name, recipientKey);
    results.push(result);
  }

  return { results, batchId };
};

export const fetchBatchMessages = async (batchId: string): Promise<SmsLog[]> => {
  if (!batchId) return [];

  try {
    const res = await fetch(`${WEBHOOK_URL}?batch_id=${batchId}&limit=500`, {
      headers: {
        'X-Webhook-Secret': WEBHOOK_SECRET,
      },
    });
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
 * Direct:  conversation_id = conv_09XXXXXXXXX
 * Bulk:    conversation_id = group_batch_xxx
 */
export const fetchMessagesByConversationId = async (
  conversationId: string,
  limit = 100
): Promise<FirestoreMessage[]> => {
  if (!conversationId) return [];

  try {
    const DIRECT_MESSAGES_URL = `https://smspro-api.nolacrm.io/api/messages?conversation_id=${encodeURIComponent(conversationId)}&limit=${limit}`;
    const res = await fetch(DIRECT_MESSAGES_URL, {
      headers: { 'X-Webhook-Secret': WEBHOOK_SECRET }
    });
    if (!res.ok) throw new Error(`Failed to fetch conversation messages: ${res.status}`);
    const data = await res.json();
    return (data.data || data || []) as FirestoreMessage[];
  } catch (error) {
    console.error('[fetchMessagesByConversationId] Error:', error);
    return [];
  }
};

/**
 * Fetch all conversation metadata from the `conversations` Firestore collection.
 * Used by Sidebar to build the direct/bulk message list from server state.
 */
export const fetchConversations = async (): Promise<Conversation[]> => {
  try {
    const CONVERSATIONS_URL = "https://smspro-api.nolacrm.io/api/conversations";
    const res = await fetch(CONVERSATIONS_URL, {
      headers: { 'X-Webhook-Secret': WEBHOOK_SECRET },
    });
    if (!res.ok) throw new Error(`Failed to fetch conversations: ${res.status}`);
    const data = await res.json();
    // Data may be { data: [...] } or a plain array or { conversations: [...] }
    return (Array.isArray(data) ? data : (data.data || data.conversations || [])) as Conversation[];
  } catch (error) {
    console.error('[fetchConversations] Error:', error);
    return [];
  }
};

// Fetch messages by recipient_key (conversation key)
export const fetchMessagesByRecipientKey = async (recipientKey: string): Promise<SmsLog[]> => {
  if (!recipientKey) return [];

  try {
    const res = await fetch(`${WEBHOOK_URL}?recipient_key=${encodeURIComponent(recipientKey)}&limit=500`, {
      headers: {
        'X-Webhook-Secret': WEBHOOK_SECRET,
      },
    });
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
    const BULK_CAMPAIGNS_URL = "https://smspro-api.nolacrm.io/api/bulk-campaigns";
    const res = await fetch(BULK_CAMPAIGNS_URL, {
      headers: { 'X-Webhook-Secret': WEBHOOK_SECRET }
    });
    console.log('[fetchAllBulkMessages] Response status:', res.status);
    if (!res.ok) {
      const errorText = await res.text();
      console.error('[fetchAllBulkMessages] Error response:', errorText);
      throw new Error(`Failed to fetch bulk messages: ${res.status}`);
    }
    const data = await res.json();
    console.log('[fetchAllBulkMessages] Data received:', data);
    // Convert to BulkMessageHistoryItem format
    return data.map((item: any) => ({
      id: `bulk-db-${item.batch_id}`,
      message: item.message || '',
      recipientCount: item.recipientCount || 0,
      recipientNumbers: item.recipientNumbers || [],
      recipientKey: item.batch_id,
      timestamp: item.timestamp || new Date().toISOString(),
      status: 'sent' as const,
      batchId: item.batch_id,
      fromDatabase: true,
    }));
  } catch (error) {
    console.error("[fetchAllBulkMessages] Error:", error);
    return [];
  }
};
