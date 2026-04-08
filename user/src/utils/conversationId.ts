/**
 * Conversation ID helpers.
 *
 * Backend (multi-tenant) now scopes IDs by location_id:
 *   Direct: `${locationId}_conv_${phone}`
 * Legacy direct IDs may still exist:
 *   Direct: `conv_${phone}`
 *
 * Bulk conversations are typically `group_${batchId}` but may also be scoped
 * similarly (e.g. `${locationId}_group_${batchId}`), so parsing should be tolerant.
 */
export function normalizePHNumber(input: string): string | null {
  if (!input) return null;
  const digits = input.replace(/\D/g, "");

  // 09XXXXXXXXX → valid
  if (digits.startsWith("09") && digits.length === 11) return digits;
  // 9XXXXXXXXX → 09XXXXXXXXX
  if (digits.startsWith("9") && digits.length === 10) return `0${digits}`;
  // 639XXXXXXXXX → 09XXXXXXXXX
  if (digits.startsWith("639") && digits.length === 12) return `0${digits.substring(2)}`;

  return null;
}

export function buildDirectConversationId(phone: string, locationId?: string | null): string | null {
  const normalized = normalizePHNumber(phone);
  if (!normalized) return null;
  return locationId ? `${locationId}_conv_${normalized}` : `conv_${normalized}`;
}

export function extractPhoneFromDirectConversationId(conversationId: string): string | null {
  if (!conversationId) return null;

  // Scoped: "{locationId}_conv_{phone}"
  const scopedIdx = conversationId.lastIndexOf("_conv_");
  if (scopedIdx !== -1) {
    const phone = conversationId.slice(scopedIdx + "_conv_".length);
    return phone || null;
  }

  // Legacy: "conv_{phone}"
  if (conversationId.startsWith("conv_")) {
    const phone = conversationId.slice("conv_".length);
    return phone || null;
  }

  return null;
}

export function extractBatchIdFromGroupConversationId(conversationId: string): string | null {
  if (!conversationId) return null;

  // Scoped: "{locationId}_group_{batchId}"
  const scopedIdx = conversationId.lastIndexOf("_group_");
  if (scopedIdx !== -1) {
    const batchId = conversationId.slice(scopedIdx + "_group_".length);
    return batchId || null;
  }

  // Legacy: "group_{batchId}"
  if (conversationId.startsWith("group_")) {
    const batchId = conversationId.slice("group_".length);
    return batchId || null;
  }

  return null;
}

