import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";

interface CreateGhlConversationResponse {
  success: boolean;
  ghl_conversation_id?: string;
  local_conversation_id?: string;
  message?: string;
  error?: string;
  ghl_status?: number;
  ghl_error?: string;
}

/**
 * Create a conversation on the GHL Dashboard for a given contact.
 * This makes the conversation visible in GHL → Conversations tab
 * and syncs it into the local Firestore sidebar.
 */
export const createGhlConversation = async (
  contactId: string,
  contactName?: string
): Promise<CreateGhlConversationResponse> => {
  if (!contactId) {
    return { success: false, error: "Contact ID is required" };
  }

  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {
      "Content-Type": "application/json",
    };

    if (accountSettings.ghlLocationId) {
      headers["X-GHL-Location-ID"] = accountSettings.ghlLocationId;
    }

    const url = accountSettings.ghlLocationId
      ? `${API_CONFIG.ghl_conversations}?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`
      : API_CONFIG.ghl_conversations;

    const res = await fetch(url, {
      method: "POST",
      headers,
      body: JSON.stringify({
        contactId,
        contactName: contactName || undefined,
      }),
    });

    const data = await res.json();

    if (!res.ok) {
      return {
        success: false,
        error: data.error || data.message || `HTTP ${res.status}`,
        ghl_status: data.ghl_status,
        ghl_error: data.ghl_error,
      };
    }

    return data as CreateGhlConversationResponse;
  } catch (error) {
    console.error("[createGhlConversation] Error:", error);
    return {
      success: false,
      error: error instanceof Error ? error.message : "Failed to create GHL conversation",
    };
  }
};
