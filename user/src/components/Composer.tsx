import { useState, useRef, useEffect, useMemo } from "react";
import { Snackbar, Alert, Slide } from "@mui/material";
import { fetchContacts } from "../api/contacts";
import { sendSms, sendBulkSms, type SenderId } from "../api/sms";
import { getRecipientKey } from "../utils/storage";
import type { BulkMessageHistoryItem, Message } from "../types/Sms";
import type { Contact } from "../types/Contact";
import { FiUser, FiUsers, FiMenu } from "react-icons/fi";
import ShinyText from "./ShinyText";
import { DotLottieReact } from "@lottiefiles/dotlottie-react";
import { useConversationMessages } from "../hooks/useConversationMessages";
import { useMessages as usePhoneMessages } from "../hooks/useMessages";
import { SenderSelector } from "./SenderSelector";
import { CreditBadge } from "./CreditBadge";
import { FiCheck, FiAlertCircle, FiLoader } from "react-icons/fi";
import { getAccountSettings, getPreferredSender, savePreferredSender } from "../utils/settingsStorage";
import { fetchAccountSenderConfig } from "../api/senderRequests";
import { buildDirectConversationId } from "../utils/conversationId";
import { estimateSmsSegments } from "../utils/smsSegments";

interface ComposerProps {
  selectedContacts: Contact[];
  isNewMessage?: boolean;
  activeContact?: Contact | null;
  activeBulkMessage?: BulkMessageHistoryItem | null;
  onSelectContact?: (contact: Contact) => void;
  onSelectBulkMessage?: (item: BulkMessageHistoryItem) => void;
  onRequestSettings?: () => void;
  onToggleMobileMenu?: () => void;
}

const StatusBadgeSummary: React.FC<{ stats: { sent: number, pending: number, failed: number, total: number }, minimal?: boolean }> = ({ stats, minimal }) => {
  if (minimal) {
    const isFullySent = stats.sent === stats.total && stats.total > 0;
    const isFailed = stats.failed === stats.total && stats.total > 0;

    return (
      <div className="flex items-center gap-1.5 text-[10px] font-bold">
        {stats.failed > 0 ? (
          <span className="text-red-500 uppercase tracking-wider flex items-center gap-1">
            <FiAlertCircle size={11} className="animate-pulse" /> {isFailed ? 'Failed' : `${stats.failed} Failed`}
          </span>
        ) : isFullySent ? (
          <span className="text-green-500 flex items-center gap-0.5">
            <FiCheck size={11} className="text-blue-400" />
            <FiCheck size={11} className="-ml-1.5 text-blue-400" />
          </span>
        ) : (
          <span className="text-gray-400 dark:text-gray-500 uppercase flex items-center gap-1">
            <FiCheck size={10} /> {stats.sent}/{stats.total}
          </span>
        )}
      </div>
    );
  }

  return (
    <div className="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider">
      {stats.failed > 0 && (
        <span className="text-red-500 flex items-center gap-1">
          <FiAlertCircle size={10} /> {stats.failed} Failed
        </span>
      )}
      {stats.pending > 0 && (
        <span className="text-blue-500 flex items-center gap-1">
          <FiLoader className="animate-spin" size={10} /> {stats.pending} Pending
        </span>
      )}
      <span className="text-gray-400 dark:text-gray-500 flex items-center gap-1">
        <FiCheck size={10} className={stats.sent === stats.total && stats.total > 0 ? "text-green-500" : ""} />
        {stats.sent}/{stats.total} Sent
      </span>
    </div>
  );
};

export const Composer: React.FC<ComposerProps> = ({
  selectedContacts,
  isNewMessage = true,
  activeContact,
  activeBulkMessage,
  onSelectContact,
  onSelectBulkMessage,
  onRequestSettings,
  onToggleMobileMenu
}) => {
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(false);
  const [senderName, setSenderName] = useState<SenderId>("NOLASMSPro");
  const [approvedSenderId, setApprovedSenderId] = useState<string | undefined>(undefined);

  const handleSenderChange = (val: SenderId) => {
    setSenderName(val);
    savePreferredSender(val);
  };

  // Dynamic sender default: prioritize user's preferred sender, else approved sender, else system default
  useEffect(() => {
    let cancelled = false;
    fetchAccountSenderConfig().then(cfg => {
      if (cancelled) return;
      
      const preferred = getPreferredSender();
      
      if (cfg.approved_sender_id) {
        setApprovedSenderId(cfg.approved_sender_id);
      }
      
      if (preferred) {
        setSenderName(preferred);
      } else if (cfg.approved_sender_id) {
        setSenderName(cfg.approved_sender_id);
      } else if (cfg.system_default_sender) {
        setSenderName(cfg.system_default_sender);
      }
    });
    return () => { cancelled = true; };
  }, []);
  const [expandedMessageId, setExpandedMessageId] = useState<string | null>(null);
  const [lottieError, setLottieError] = useState(false);

  // Filtering focus for bulk conversations
  const [recipientKeyFocus, setRecipientKeyFocus] = useState<string | undefined>(undefined);

  // Bulk SMS state
  const [composeMode, setComposeMode] = useState<"single" | "bulk">("single");
  const [allContacts, setAllContacts] = useState<Contact[]>([]);
  const [searchQuery, setSearchQuery] = useState("");
  const [bulkSelectedContacts, setBulkSelectedContacts] = useState<Contact[]>([]);
  const [isPickerOpen, setIsPickerOpen] = useState(false);

  // Convert name to proper case (title case)
  const toProperCase = (name: string): string => {
    return name.replace(/\b\w/g, (char) => char.toUpperCase());
  };

  const activePhoneNumber = useMemo(() => {
    if (activeContact) return activeContact.phone;
    if (selectedContacts.length === 1) return selectedContacts[0].phone;
    return undefined;
  }, [activeContact, selectedContacts]);

  /**
   * Stable conversation_id for message fetching:
   *  - Direct chat:             {locationId}_conv_{phone} (or legacy conv_{phone})
   *  - Existing bulk from sidebar: group_{batchId}  (batchId already contains "batch_" prefix from server)
   *  - New bulk in progress:    undefined (messages will appear after navigation to activeBulkMessage)
   */
  const conversationId = useMemo(() => {
    const { ghlLocationId } = getAccountSettings();
    if (activePhoneNumber) {
      return buildDirectConversationId(activePhoneNumber, ghlLocationId) || undefined;
    }
    if (activeBulkMessage) {
      // Backend expects scoped IDs: {locationId}_group_{batchId}
      const prefix = activeBulkMessage.locationId || ghlLocationId;
      return prefix ? `${prefix}_group_${activeBulkMessage.batchId}` : `group_${activeBulkMessage.batchId}`;
    }
    return undefined;
  }, [activePhoneNumber, activeBulkMessage]);

  const {
    messages: conversationMessages,
    loading: historyLoading,
    error: historyError,
    errorStatus: historyErrorStatus,
    addOptimisticMessage,
    updateMessageStatus,
    refresh,
  } = useConversationMessages(conversationId, recipientKeyFocus);

  // Optional per-contact fallback: raw outbound log view by phone number
  const {
    messages: phoneLogMessages,
    loading: phoneLogLoading,
    error: phoneLogError,
  } = usePhoneMessages(activePhoneNumber);

  const [useRawLogView, setUseRawLogView] = useState(false);

  const dropdownRef = useRef<HTMLDivElement>(null);

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const emojiPickerRef = useRef<HTMLDivElement>(null);
  const msgAreaRef = useRef<HTMLDivElement>(null);
  const touchStartYMsg = useRef<number>(0);
  const [isPullRefreshing, setIsPullRefreshing] = useState(false);

  // Interactive features state
  const [isEmojiPickerOpen, setIsEmojiPickerOpen] = useState(false);
  const [attachedFiles, setAttachedFiles] = useState<File[]>([]);

  // Toast states
  const [toastOpen, setToastOpen] = useState(false);
  const [toastSeverity, setToastSeverity] = useState<"success" | "error">("success");
  const [toastMessage, setToastMessage] = useState("");
  // Guard: prevents multiple toasts firing from same send action
  const toastTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const showToast = (severity: "success" | "error", msg: string) => {
    // Cancel any pending open so rapid-fire calls collapse into one
    if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
    setToastOpen(false);
    setToastSeverity(severity);
    setToastMessage(msg);
    toastTimerRef.current = setTimeout(() => { setToastOpen(true); }, 50);
  };
  const [showDisabledReason, setShowDisabledReason] = useState(false);
  const [showScrollButton, setShowScrollButton] = useState(false);



  const handleScroll = () => {
    if (!msgAreaRef.current) return;
    const { scrollTop, scrollHeight, clientHeight } = msgAreaRef.current;
    // Show the button if scrolled up more than 150px from the bottom
    setShowScrollButton(scrollHeight - scrollTop - clientHeight > 150);
  };

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  const prevConversationId = useRef<string | undefined>(undefined);
  const prevMessagesLength = useRef<number>(0);
  const prevRawLogView = useRef<boolean>(false);

  useEffect(() => {
    const currentLength = useRawLogView ? phoneLogMessages.length : conversationMessages.length;
    
    // Only auto-scroll on initial load, when conversation changes, 
    // when toggling raw log view, or when new messages arrive.
    // This prevents flickering/auto-scrolling on background status polls.
    if (
      conversationId !== prevConversationId.current ||
      useRawLogView !== prevRawLogView.current ||
      currentLength > prevMessagesLength.current ||
      prevMessagesLength.current === 0
    ) {
      setTimeout(scrollToBottom, 50);
    }
    
    prevConversationId.current = conversationId;
    prevRawLogView.current = useRawLogView;
    prevMessagesLength.current = currentLength;
  }, [conversationMessages, phoneLogMessages, useRawLogView, conversationId]);

  // Whenever the conversationId changes, reset the raw log view toggle
  useEffect(() => {
    setUseRawLogView(false);
  }, [conversationId]);

  // Consolidated effect to sync internal state with props and handle "New Message" (reset)
  useEffect(() => {
    if (activeContact) {
      setBulkSelectedContacts([activeContact]);
      setComposeMode("single");
    } else if (activeBulkMessage) {
      setComposeMode("bulk");
      const derivedContacts: Contact[] = (activeBulkMessage.recipientNumbers ?? []).map((num, i) => ({
        id: `bulk-derive-${num}`,
        name: activeBulkMessage.recipientNames?.[i] || num,
        phone: num
      }));
      setBulkSelectedContacts(derivedContacts);
    } else if (selectedContacts.length > 0) {
      setBulkSelectedContacts(selectedContacts);
      setComposeMode(selectedContacts.length > 1 ? "bulk" : "single");
    } else {
      // Clear state for "New Message" or when selection is removed in Dashboard
      setBulkSelectedContacts([]);
      setComposeMode("single");
      setMessage("");
    }
  }, [activeContact, selectedContacts, activeBulkMessage]);

  // Reset bulkSelectedContacts when switching from bulk to single mode
  useEffect(() => {
    if (composeMode === "single" && bulkSelectedContacts.length > 1) {
      // Keep only the first contact when switching to single mode
      setBulkSelectedContacts(bulkSelectedContacts.slice(0, 1));
    }
    // Clear recipient filter when switching modes or conversations
    setRecipientKeyFocus(undefined);
  }, [composeMode, conversationId]);

  useEffect(() => {
    if (isNewMessage) {
      fetchContacts().then(setAllContacts).catch(console.error);
    }
  }, [isNewMessage]);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsPickerOpen(false);
      }
      if (emojiPickerRef.current && !emojiPickerRef.current.contains(event.target as Node)) {
        setIsEmojiPickerOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, []);

  const filteredContacts = useMemo(() => {
    if (!searchQuery) return allContacts;
    const lowerQ = searchQuery.toLowerCase();
    return allContacts.filter(c =>
      c.name.toLowerCase().includes(lowerQ) ||
      c.phone.includes(lowerQ)
    );
  }, [searchQuery, allContacts]);

  const handleSelectBulkContact = (contact: Contact) => {
    if (composeMode === "single") {
      setBulkSelectedContacts([contact]);
    } else {
      if (!bulkSelectedContacts.find(c => c.id === contact.id)) {
        setBulkSelectedContacts(prev => [...prev, contact]);
      }
    }
    setSearchQuery("");
  };

  const handleManualAdd = () => {
    if (!searchQuery.trim()) return;
    const digits = searchQuery.replace(/\D/g, "");
    if (digits.length >= 7) {
      const newManualContact: Contact = {
        id: `manual-${Date.now()}`,
        name: searchQuery.trim(),
        phone: searchQuery.trim(),
      };
      if (composeMode === "single") {
        setBulkSelectedContacts([newManualContact]);
      } else {
        if (!bulkSelectedContacts.find(c => c.phone === newManualContact.phone)) {
          setBulkSelectedContacts(prev => [...prev, newManualContact]);
        }
      }
      setSearchQuery("");
      setIsPickerOpen(false);
    }
  };

  const handleRemoveBulkContact = (id: string, e?: React.MouseEvent) => {
    if (e) e.stopPropagation();
    setBulkSelectedContacts(prev => prev.filter(c => c.id !== id));
  };

  // Interactive Features Handlers
  const commonEmojis = ["😊", "👍", "👋", "🙌", "🔥", "✨", "📱", "💬", "✅", "⚠️", "⏳", "📅"];

  const handleEmojiSelect = (emoji: string) => {
    setMessage(prev => prev + emoji);
    setIsEmojiPickerOpen(false);
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      const newFiles = Array.from(e.target.files);
      setAttachedFiles(prev => [...prev, ...newFiles]);
    }
  };

  const handleRemoveFile = (index: number) => {
    setAttachedFiles(prev => prev.filter((_, i) => i !== index));
  };

  // SMS length calculation
  const smsSegments = estimateSmsSegments(message).segments;

  // Get active recipients based on context
  const getActiveRecipients = (): Contact[] => {
    // If we have an active bulk message conversation, use its recipients
    if (activeBulkMessage) {
      return (activeBulkMessage.recipientNumbers ?? []).map((num, i) => ({
        id: `bulk-${num}`,
        name: activeBulkMessage.recipientNames?.[i] || num,
        phone: num
      }));
    }
    // If we have an active contact (direct message conversation)
    if (activeContact) {
      return [activeContact];
    }
    // For new message flow
    if (composeMode === 'bulk' || composeMode === 'single') {
      return bulkSelectedContacts;
    }
    return [];
  };

  const currentRecipients = getActiveRecipients();
  const totalEstimatedSms = composeMode === "bulk" && isNewMessage
    ? smsSegments * bulkSelectedContacts.length
    : smsSegments;

  const handleSend = async () => {
    if (loading) return;
    // Guard to ensure only ONE toast fires per send action
    let toastShown = false;
    const guardedToast = (severity: "success" | "error", msg: string) => {
      toastShown = true;
      showToast(severity, msg);
    };
    const recipients = getActiveRecipients();

    if (!message) {
      setShowDisabledReason(true);
      setTimeout(() => setShowDisabledReason(false), 3000);
      return;
    }
    if (recipients.length === 0) {
      setShowDisabledReason(true);
      setTimeout(() => setShowDisabledReason(false), 3000);
      return;
    }

    setLoading(true);
    const messageText = message;
    setMessage("");
    setAttachedFiles([]);

    try {
      // Check if we're viewing an existing bulk message conversation
      const isExistingBulkConversation = activeBulkMessage && recipients.length > 1;

      if (recipients.length === 1 || isExistingBulkConversation) {
        // Single message or appending to existing bulk conversation
        if (recipients.length === 1) {
          // Optimistic update for single message
          const tempId = addOptimisticMessage(messageText, senderName);
          const smsResult = await sendSms(recipients[0].phone, messageText, senderName, undefined, recipients[0].name, undefined, recipients[0].ghl_contact_id);

          if (smsResult.success) {
            updateMessageStatus(tempId, 'sent');
            guardedToast("success", smsResult.message || "Message sent successfully!");

            // Dispatch event to refresh credit balance
            window.dispatchEvent(new Event('sms-sent'));

            // Re-fetch from database after a short delay to get the stored message
            setTimeout(() => refresh(), 2000);

            // Navigate to contact view if not already there
            if (activeContact) {
              // Already viewing contact, just refresh
            } else if (onSelectContact && recipients[0]) {
              setTimeout(() => onSelectContact(recipients[0]), 500);
            }
          } else {
            updateMessageStatus(tempId, 'failed', undefined, smsResult.message || "Failed to send message");
            guardedToast("error", smsResult.message || "Failed to send message");
          }
        } else {
          // Sending to existing bulk conversation - use existing recipientKey
          const phones = recipients.map(c => c.phone);
          const recipientKey = activeBulkMessage?.recipientKey || getRecipientKey(phones);
          const batchId = activeBulkMessage?.batchId; // Use existing batchId to keep in same conversation

          // Send bulk SMS - if we have an existing batchId, it will add to that conversation
          const { results } = await sendBulkSms(phones, messageText, senderName, recipients, recipientKey, batchId);
          const successCount = results.filter(r => r.success).length;

          if (successCount > 0) {
            guardedToast("success", `Sent ${successCount} of ${recipients.length} messages`);

            // Refresh to show new messages in the conversation
            setTimeout(() => refresh(), 2000);
          } else {
            guardedToast("error", "Failed to send bulk messages");
          }
        }
      } else {
        // NEW bulk SMS sending (creating new conversation)
        const phones = recipients.map(c => c.phone);
        const recipientKey = getRecipientKey(phones);
        const { results, batchId } = await sendBulkSms(phones, messageText, senderName, recipients, recipientKey);
        const successCount = results.filter(r => r.success).length;

        if (successCount > 0) {
          guardedToast("success", `Sent ${successCount} of ${recipients.length} messages`);

          // Define item for navigation, but don't save to localStorage
          const bulkItemForNav: BulkMessageHistoryItem = {
            id: `bulk-db-${batchId}`,
            message: messageText,
            recipientCount: recipients.length,
            recipientNames: recipients.map(r => r.name),
            recipientNumbers: recipients.map(r => r.phone),
            recipientKey: recipientKey,
            timestamp: new Date().toISOString(),
            status: 'sent',
            batchId: batchId,
            fromDatabase: true
          };

          // First, navigate to bulk message view (this sets activeBulkMessage in Dashboard)
          if (onSelectBulkMessage) {
            onSelectBulkMessage(bulkItemForNav);
          }

          // Refresh after navigation to fetch from Firestore
          setTimeout(() => refresh(), 2000);
        } else {
          guardedToast("error", "Failed to send bulk messages");
        }
      }
      // toast already shown via guardedToast() in each branch above
    } catch (error) {
      if (!toastShown) {
        showToast("error", error instanceof Error ? error.message : "Failed to send message");
      }
    } finally {
      setLoading(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (!isSendDisabled()) {
        handleSend();
      }
    }
  };

  const getSendButtonText = () => {
    if (loading) return "";
    // When viewing an existing bulk message conversation, allow sending
    if (activeBulkMessage) {
      return "Send";
    }
    // When viewing an existing direct message conversation
    if (activeContact) {
      return "Send";
    }
    // For new message flow
    if (composeMode === "bulk") {
      return bulkSelectedContacts.length > 0 ? `Send to ${bulkSelectedContacts.length}` : "Send";
    }
    return "Send";
  };

  const isSendDisabled = () => {
    if (loading || !message) return true;
    // Allow sending when viewing an existing conversation
    if (activeBulkMessage || activeContact) {
      return false;
    }
    // For new message flow, need recipients
    if (bulkSelectedContacts.length === 0) return true;
    return false;
  };

  const getSendDisabledReason = (): string => {
    if (loading) return "Sending in progress...";
    if (!message) return "Enter a message to send";
    // Allow sending when viewing an existing conversation
    if (activeBulkMessage || activeContact) {
      return "";
    }
    // For new message flow, need recipients
    if (bulkSelectedContacts.length === 0) return "Select at least one recipient";
    return "";
  };

  // History state logic...

  return (
    <div className="flex flex-col h-full bg-[#f9fafb] dark:bg-[#111111] relative overflow-hidden transition-colors duration-300">
      {/* 1. Header & Recipient Area (Sticky) */}
      <div className="flex-shrink-0 z-30 bg-white/80 dark:bg-[#1a1b1e]/80 backdrop-blur-xl border-b border-gray-200/60 dark:border-white/5 shadow-sm">
        {activePhoneNumber ? (
          /* Chat Header for specific contact - Direct Messages */
          <div className="max-w-5xl mx-auto px-4 sm:px-6 py-3 sm:py-4 flex flex-row items-center justify-between gap-3">
            <div className="flex items-center gap-2 sm:gap-4 min-w-0 flex-1">
              <button
                onClick={onToggleMobileMenu}
                className="md:hidden p-2 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-[#ececf1] transition-colors"
                aria-label="Toggle sidebar"
              >
                <FiMenu className="h-5 w-5" />
              </button>
              <div className="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex-shrink-0 flex items-center justify-center text-white font-bold text-base sm:text-lg shadow-md shadow-blue-500/10">
                {(activeContact?.name || selectedContacts[0]?.name || "?").charAt(0).toUpperCase()}
              </div>
              <div className="flex flex-col min-w-0">
                <h2 className="text-[15px] sm:text-[17px] font-bold text-[#111111] dark:text-[#ececf1] leading-tight tracking-tight truncate">
                  {toProperCase(activeContact?.name || selectedContacts[0]?.name || '')}
                </h2>
                <span className="text-[12px] sm:text-[13px] text-gray-500 dark:text-gray-400 font-medium truncate">
                  {activePhoneNumber}
                </span>
              </div>
            </div>

            {/* Sender + Credits */}
            <div className="flex items-center gap-2 group">
              <div className="flex-shrink-0 order-2 sm:order-1">
                <CreditBadge />
              </div>
              <div className="flex-shrink-0 order-1 sm:order-2">
                <SenderSelector
                  value={senderName}
                  onChange={handleSenderChange}
                  onRequestSettings={onRequestSettings}
                  approvedSenderId={approvedSenderId}
                />
              </div>
            </div>
          </div>
        ) : activeBulkMessage ? (
          /* Bulk Message Conversation Header - Clean view like direct messages */
          <div className="max-w-5xl mx-auto px-4 sm:px-6 py-3 sm:py-4 flex flex-row items-center justify-between gap-3">
            <div className="flex items-center gap-2 sm:gap-4 min-w-0 flex-1">
              <button
                onClick={onToggleMobileMenu}
                className="md:hidden p-2 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-[#ececf1] transition-colors"
                aria-label="Toggle sidebar"
              >
                <FiMenu className="h-5 w-5" />
              </button>
              <div className="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-gradient-to-br from-[#7c3aed] to-[#a78bfa] flex-shrink-0 flex items-center justify-center text-white font-bold text-base sm:text-lg shadow-md shadow-purple-500/20">
                <FiUsers className="h-5 w-5 sm:h-6 sm:w-6" />
              </div>
              <div className="flex flex-col min-w-0">
                <h2 className="text-[15px] sm:text-[17px] font-bold text-[#111111] dark:text-[#ececf1] leading-tight tracking-tight truncate">
                  {(() => {
                    const custom = activeBulkMessage.customName;
                    const looksLikeBatchId = !!custom && (/^batch[-_]\d+$/i.test(custom) || /^batch[-_]/i.test(custom));
                    const usableCustom = custom && !looksLikeBatchId ? custom : undefined;

                    if (usableCustom) return usableCustom;

                    const names = (activeBulkMessage.recipientNames || []).filter(Boolean);
                    if (names.length > 0) {
                      return (
                        names.slice(0, 3).join(", ") +
                        (names.length > 3 ? ` +${names.length - 3}` : "")
                      );
                    }

                    return `${activeBulkMessage.recipientCount} recipients`;
                  })()}
                </h2>
                <span className="text-[12px] sm:text-[13px] text-gray-500 dark:text-gray-400 font-medium truncate">
                  {activeBulkMessage.recipientCount} recipient{activeBulkMessage.recipientCount !== 1 ? 's' : ''}
                </span>
              </div>
            </div>

            {/* Sender and Credits */}
            <div className="flex items-center gap-2 group">
              <div className="flex-shrink-0 order-2 sm:order-1">
                <CreditBadge />
              </div>
              <div className="flex-shrink-0 order-1 sm:order-2">
                <SenderSelector
                  value={senderName}
                  onChange={handleSenderChange}
                  onRequestSettings={onRequestSettings}
                  approvedSenderId={approvedSenderId}
                />
              </div>
            </div>
          </div>
        ) : (
          /* New Message / Bulk Header - Styled like individual contact header */
          <div className="max-w-5xl mx-auto px-4 sm:px-6 pt-3 pb-2">
            <div className="flex flex-row items-center justify-between mb-3 gap-3">
              <div className="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
                <button
                  onClick={onToggleMobileMenu}
                  className="md:hidden p-2 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-[#ececf1] transition-colors"
                  aria-label="Toggle sidebar"
                >
                  <FiMenu className="h-5 w-5" />
                </button>

                {/* Circular Avatar - Blue for new message, Purple for bulk */}
                <div className={`w-10 h-10 sm:w-11 sm:h-11 rounded-full flex-shrink-0 flex items-center justify-center text-white font-bold text-base shadow-md 
                  ${activeBulkMessage
                    ? 'bg-gradient-to-br from-[#7c3aed] to-[#a78bfa] shadow-purple-500/20'
                    : 'bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] shadow-blue-500/20'}`}>
                  {activeBulkMessage ? (
                    <FiUsers className="h-5 w-5" />
                  ) : (
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.2}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                    </svg>
                  )}
                </div>

                <div className="flex flex-col min-w-0">
                  <h2 className="text-[15px] sm:text-[16px] font-bold text-[#111111] dark:text-[#ececf1] leading-tight tracking-tight truncate">
                    {composeMode === 'bulk' && bulkSelectedContacts.length > 0 ? (
                      (() => {
                        const count = bulkSelectedContacts.length;
                        if (count === 1) return `To: ${toProperCase(bulkSelectedContacts[0].name)}`;
                        if (count === 2) return `To: ${toProperCase(bulkSelectedContacts[0].name)}, ${toProperCase(bulkSelectedContacts[1].name)}`;
                        return `To: ${toProperCase(bulkSelectedContacts[0].name)}, ${toProperCase(bulkSelectedContacts[1].name)} +${count - 2} more`;
                      })()
                    ) : (
                      "New Message"
                    )}
                  </h2>
                  <span className="text-[12px] sm:text-[13px] text-gray-500 dark:text-gray-400 font-medium truncate">
                    {bulkSelectedContacts.length > 0 ? `${bulkSelectedContacts.length} selected` : 'Select recipients'}
                  </span>
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 sm:gap-3 flex-wrap">
                <div className="order-1 hidden sm:block">
                  <CreditBadge />
                </div>

                {/* Compact Toggle - restored for new message flow */}
                <div className="flex p-0.5 bg-gray-100 dark:bg-white/5 rounded-xl border border-gray-200/50 dark:border-white/5 order-2">
                  <button
                    onClick={() => setComposeMode("single")}
                    className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-1.5 sm:py-1 text-[10px] sm:text-[11px] font-bold uppercase tracking-wider rounded-lg transition-all duration-200 ${composeMode === "single"
                      ? "bg-white dark:bg-[#2a2b32] text-[#2b83fa] shadow-sm shadow-blue-500/10"
                      : "text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                      }`}
                  >
                    <FiUser className="h-3 w-3 sm:h-3.5 sm:w-3.5" />
                    Single
                  </button>
                  <button
                    onClick={() => setComposeMode("bulk")}
                    className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-1.5 sm:py-1 text-[10px] sm:text-[11px] font-bold uppercase tracking-wider rounded-lg transition-all duration-200 ${composeMode === "bulk"
                      ? "bg-white dark:bg-[#2a2b32] text-[#2b83fa] shadow-sm shadow-blue-500/10"
                      : "text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                      }`}
                  >
                    <FiUsers className="h-3 w-3 sm:h-3.5 sm:w-3.5" />
                    Bulk
                  </button>
                </div>
              </div>
            </div>

            {/* Sender Selector - Full Width Above To: on Mobile */}
            <div className="mb-3 w-full block sm:hidden">
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <span className="text-[14px] font-semibold text-gray-400 dark:text-gray-500 whitespace-nowrap">From:</span>
                  <div className="flex-1">
                    <SenderSelector
                      value={senderName}
                      onChange={handleSenderChange}
                      align="left"
                      onRequestSettings={onRequestSettings}
                      approvedSenderId={approvedSenderId}
                    />
                  </div>
                </div>
                <div className="flex-shrink-0 scale-90 origin-right">
                  <CreditBadge />
                </div>
              </div>
            </div>

            {/* Recipient Line */}
            <div className="flex items-start gap-3 pb-2 border-t border-gray-100 dark:border-white/5 pt-3">
              <span className="text-[14px] font-semibold text-gray-400 dark:text-gray-500 mt-2.5 whitespace-nowrap">To:</span>

              <div className="flex-1 min-h-[44px]">
                <div className="relative" ref={dropdownRef}>
                  <div
                    className="flex flex-wrap gap-2 py-1.5 cursor-text"
                    onClick={() => setIsPickerOpen(true)}
                  >
                    {bulkSelectedContacts.map(contact => {
                      const isFocused = recipientKeyFocus === contact.phone;
                      const isBulkActive = !!activeBulkMessage;

                      return (
                        <span
                          key={contact.id}
                          className={`flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[13px] font-semibold transition-all
                            ${isFocused
                              ? 'bg-[#2b83fa] text-white shadow-md scale-105'
                              : isBulkActive
                                ? 'bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 border border-[#2b83fa]/20 text-[#2b83fa] hover:bg-[#2b83fa]/20 cursor-pointer'
                                : 'bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 border border-[#2b83fa]/20 text-[#2b83fa]'
                            }
                          `}
                          onClick={(e) => {
                            if (isBulkActive) {
                              e.stopPropagation();
                              setRecipientKeyFocus(isFocused ? undefined : contact.phone);
                            }
                          }}
                          title={isBulkActive ? (isFocused ? "Clear Filter" : "Filter history for this contact") : ""}
                        >
                          {toProperCase(contact.name)}
                          {!isBulkActive && (
                            <button
                              onClick={(e) => handleRemoveBulkContact(contact.id, e)}
                              className="hover:text-red-500 transition-colors"
                            >
                              <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                              </svg>
                            </button>
                          )}
                          {isFocused && (
                            <div className="ml-1 hover:text-white/80" onClick={(e) => { e.stopPropagation(); setRecipientKeyFocus(undefined); }}>
                              <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                              </svg>
                            </div>
                          )}
                        </span>
                      );
                    })}
                    <input
                      type="text"
                      value={searchQuery}
                      onChange={(e) => {
                        setSearchQuery(e.target.value);
                        setIsPickerOpen(true);
                      }}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter' && searchQuery) {
                          e.preventDefault();
                          handleManualAdd();
                        }
                      }}
                      onFocus={() => setIsPickerOpen(true)}
                      placeholder={bulkSelectedContacts.length === 0 ? (composeMode === "single" ? "Search or enter number..." : "Search or enter multiple...") : ""}
                      className="flex-1 bg-transparent border-none min-w-[120px] text-[15px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none py-1"
                    />
                  </div>

                  {/* Dropdown */}
                  {isPickerOpen && (
                    <div className="absolute top-full left-0 right-0 z-40 max-h-64 overflow-y-auto rounded-2xl border border-gray-200/80 dark:border-white/10 bg-white/95 dark:bg-[#1a1b1e]/95 backdrop-blur-2xl shadow-2xl mt-1 py-2 custom-scrollbar transition-all scale-up-center">
                      {/* Manual Add Option */}
                      {searchQuery.replace(/\D/g, "").length >= 7 && (
                        <div
                          onClick={handleManualAdd}
                          className="mx-2 mb-2 p-3 rounded-xl bg-[#2b83fa]/5 border border-[#2b83fa]/20 flex items-center gap-3 cursor-pointer hover:bg-[#2b83fa]/10 transition-all group"
                        >
                          <div className="w-8 h-8 rounded-full bg-[#2b83fa] flex items-center justify-center text-white shadow-sm group-hover:scale-110 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                              <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
                            </svg>
                          </div>
                          <div className="flex-1">
                            <p className="text-[13px] font-bold text-[#2b83fa]">Add manual number</p>
                            <p className="text-[11px] text-[#2b83fa]/70 font-medium">"{searchQuery}"</p>
                          </div>
                          <span className="text-[10px] font-black text-[#2b83fa]/40 tracking-widest uppercase">Enter</span>
                        </div>
                      )}

                      {filteredContacts.length === 0 && searchQuery.replace(/\D/g, "").length < 7 ? (
                        <div className="px-4 py-8 text-center">
                          <p className="text-[13px] text-gray-500 font-medium">No results found</p>
                        </div>
                      ) : (
                        <div className="flex flex-col gap-0.5 px-2">
                          {filteredContacts.map(contact => {
                            const isSelected = bulkSelectedContacts.some(c => c.id === contact.id);
                            return (
                              <div
                                key={contact.id}
                                onClick={() => isSelected ? handleRemoveBulkContact(contact.id) : handleSelectBulkContact(contact)}
                                className={`px-3 py-2.5 rounded-xl flex items-center justify-between cursor-pointer transition-all duration-150 ${isSelected
                                  ? "bg-[#2b83fa]/5 dark:bg-[#2b83fa]/10"
                                  : "hover:bg-gray-100/50 dark:hover:bg-white/5"
                                  }`}
                              >
                                <div className="flex items-center gap-3 min-w-0">
                                  <div className={`w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold ${isSelected ? "bg-[#2b83fa] text-white" : "bg-gray-200 dark:bg-white/10 text-gray-600 dark:text-gray-400"}`}>
                                    {(() => {
                                      const parts = contact.name.split(' ').filter(p => p.length > 0);
                                      const first = parts[0]?.charAt(0) || '';
                                      const last = parts.length > 1 ? parts[parts.length - 1]?.charAt(0) || '' : '';
                                      return (first + last).toUpperCase() || '?';
                                    })()}
                                  </div>
                                  <div className="min-w-0">
                                    <p className="font-semibold text-[13px] text-[#111111] dark:text-[#ececf1] truncate">{toProperCase(contact.name)}</p>
                                    <p className="text-[11px] text-gray-500 truncate">{contact.phone}</p>
                                  </div>
                                </div>
                                {isSelected && (
                                  <div className="w-5 h-5 rounded-full bg-[#2b83fa] flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 text-white" viewBox="0 0 20 20" fill="currentColor">
                                      <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                    </svg>
                                  </div>
                                )}
                              </div>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>

              {/* Sender Selector — right of To: row (Desktop only) */}
              <div className="flex-shrink-0 mt-1 hidden sm:block">
                <SenderSelector
                  value={senderName}
                  onChange={setSenderName}
                  size="sm"
                  onRequestSettings={onRequestSettings}
                  approvedSenderId={approvedSenderId}
                />
              </div>
            </div>
          </div>
        )}
      </div>

      {/* 2. Message History Area */}
      <div
        ref={msgAreaRef}
        className="flex-1 overflow-y-auto px-6 py-4 space-y-1 flex flex-col custom-scrollbar"
        onScroll={handleScroll}
        onTouchStart={(e) => { touchStartYMsg.current = e.touches[0].clientY; }}
        onTouchEnd={async (e) => {
          const delta = e.changedTouches[0].clientY - touchStartYMsg.current;
          const atTop = (msgAreaRef.current?.scrollTop ?? 0) === 0;
          if (delta > 60 && atTop && !isPullRefreshing) {
            setIsPullRefreshing(true);
            await refresh();
            setIsPullRefreshing(false);
          }
        }}
      >
        <div className="max-w-5xl mx-auto w-full h-full flex flex-col">
          {/* Pull-to-refresh spinner */}
          {isPullRefreshing && (
            <div className="flex justify-center items-center py-2">
              <svg className="animate-spin h-5 w-5 text-[#2b83fa]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
              </svg>
            </div>
          )}
          {/* Error vs empty vs normal states */}
          {historyError && !useRawLogView && conversationMessages.length === 0 ? (
            <div className="flex-1 flex flex-col items-center justify-center">
              <div className="w-24 h-24 mb-4 rounded-[2.5rem] bg-red-50 dark:bg-red-900/20 flex items-center justify-center border border-red-200/60 dark:border-red-500/40 shadow-inner">
                <FiAlertCircle className="h-10 w-10 text-red-500" />
              </div>
              <h3 className="text-[18px] font-bold text-[#111111] dark:text-[#ececf1] mb-2 tracking-tight">
                Unable to load message history
              </h3>
              <p className="text-[14px] text-gray-500 dark:text-gray-400 text-center max-w-sm leading-relaxed mb-4">
                {historyErrorStatus
                  ? `The server returned an error (${historyErrorStatus}). This is likely a temporary issue with the backend, not your browser.`
                  : historyError}
              </p>
              <div className="flex flex-col sm:flex-row gap-2">
                <button
                  onClick={() => refresh(true)}
                  className="px-4 py-2 rounded-xl bg-[#2b83fa] text-white text-[13px] font-bold hover:bg-[#1d6bd4] transition-colors"
                >
                  Retry
                </button>
                {activePhoneNumber && (
                  <button
                    onClick={() => setUseRawLogView(true)}
                    className="px-4 py-2 rounded-xl bg-gray-100 dark:bg-white/10 text-[13px] font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-white/20 transition-colors"
                  >
                    View raw outbound log for this contact
                  </button>
                )}
              </div>
            </div>
          ) : historyLoading && !useRawLogView && conversationMessages.length === 0 ? (
            <div className="flex-1 flex flex-col items-end justify-end gap-2 px-2 pb-6 animate-pulse overflow-hidden">
              {/* Skeleton Date Separator */}
              <div className="w-full flex justify-center mb-6 mt-4 opacity-40">
                <div className="h-5 w-24 bg-gray-200 dark:bg-white/10 rounded-full"></div>
              </div>
              {/* Skeleton Message Bubbles - Top group */}
              <div className="w-[55%] h-12 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-br-[4px] opacity-20"></div>
              <div className="w-[85%] h-24 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-tr-[4px] rounded-br-[4px] opacity-20"></div>
              <div className="w-[45%] h-10 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-tr-[4px] mb-6 opacity-20"></div>

              {/* Skeleton Date Separator */}
              <div className="w-full flex justify-center mb-6 mt-4 opacity-60">
                <div className="h-5 w-32 bg-gray-200 dark:bg-white/10 rounded-full"></div>
              </div>
              {/* Skeleton Message Bubbles - Middle group */}
              <div className="w-[75%] h-16 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-br-[4px] opacity-40"></div>
              <div className="w-[30%] h-10 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-tr-[4px] mb-6 opacity-40"></div>
              
              {/* Skeleton Date Separator */}
              <div className="w-full flex justify-center mb-6 mt-4">
                <div className="h-6 w-28 bg-gray-200 dark:bg-white/10 rounded-full"></div>
              </div>
              {/* Skeleton Message Bubbles - Bottom active group */}
              <div className="w-[65%] h-14 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-br-[4px]"></div>
              <div className="w-[40%] h-10 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-tr-[4px] rounded-br-[4px]"></div>
              <div className="w-[80%] h-20 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-tr-[4px] mb-4"></div>
              <div className="w-[50%] h-12 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-br-[4px]"></div>
              <div className="w-[30%] h-10 bg-gray-200 dark:bg-white/10 rounded-[20px] rounded-tr-[4px]"></div>
            </div>
          ) : !useRawLogView && conversationMessages.length === 0 ? (
            <div className="flex-1 flex flex-col items-center justify-center">
              {isNewMessage && !lottieError ? (
                <DotLottieReact
                  src="https://lottie.host/8bff6661-62db-4473-adb8-7eced34f3649/mii3gOOlir.lottie"
                  loop
                  autoplay
                  className="w-40 h-40 md:w-56 md:h-56 mb-1"
                  onError={() => setLottieError(true)}
                />
              ) : (
                <div className="w-24 h-24 mb-6 rounded-[2.5rem] bg-gradient-to-br from-[#2b83fa]/10 to-[#60a5fa]/5 dark:from-[#2b83fa]/20 dark:to-[#60a5fa]/5 flex items-center justify-center border border-[#2b83fa]/10 dark:border-[#2b83fa]/20 shadow-inner">
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-10 w-10 text-[#2b83fa]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                  </svg>
                </div>
              )}
              <h3 className="text-[19px] font-bold text-[#111111] dark:text-[#ececf1] mb-2 tracking-tight">
                {activePhoneNumber ? "No history yet" : (composeMode === "bulk" ? "New Broadcast" : "New Message")}
              </h3>
              <p className="text-[14px] text-gray-500 dark:text-gray-400 text-center max-w-xs leading-relaxed">
                {activePhoneNumber
                  ? "Start a professional conversation by typing your first message below."
                  : (composeMode === "bulk" ? "Select contacts to send a synchronized update across your network." : "Type a message below to start a new professional conversation.")}
              </p>
            </div>
          ) : useRawLogView ? (
            <div className="space-y-1 max-w-4xl mx-auto w-full">
              <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className="px-2 py-0.5 rounded-full bg-gray-100 dark:bg-white/10 text-[11px] font-semibold text-gray-600 dark:text-gray-300">
                    Raw outbound log view
                  </span>
                  {phoneLogLoading && (
                    <span className="text-[11px] text-gray-400 flex items-center gap-1">
                      <FiLoader className="h-3 w-3 animate-spin" /> Loading…
                    </span>
                  )}
                </div>
                <button
                  onClick={() => {
                    setUseRawLogView(false);
                  }}
                  className="text-[11px] font-semibold text-[#2b83fa] hover:text-[#1d6bd4]"
                >
                  Back to conversation view
                </button>
              </div>
              {phoneLogError && (
                <div className="mb-2 px-3 py-2 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-200/60 dark:border-amber-700/40 text-[12px] text-amber-800 dark:text-amber-300">
                  {phoneLogError}
                </div>
              )}
              {phoneLogMessages.length === 0 ? (
                <div className="flex-1 flex flex-col items-center justify-center py-8">
                  <p className="text-[14px] text-gray-500 dark:text-gray-400">
                    No outbound messages found for this number.
                  </p>
                </div>
              ) : (
                (phoneLogMessages as any[]).map((msg, index) => {
                  const isExpanded = expandedMessageId === msg.id;
                  const prevMsg = phoneLogMessages[index - 1];
                  const nextMsg = phoneLogMessages[index + 1];

                  const msgDateStr = new Date(msg.timestamp).toDateString();
                  const showDateSeparator =
                    !prevMsg || new Date(prevMsg.timestamp).toDateString() !== msgDateStr;

                  const isPrevSameGroup = !showDateSeparator;
                  const isNextSameGroup =
                    nextMsg && new Date(nextMsg.timestamp).toDateString() === msgDateStr;

                  let roundingClasses = "rounded-[20px]";
                  if (isPrevSameGroup && isNextSameGroup) {
                    roundingClasses = "rounded-[20px] rounded-tr-[4px] rounded-br-[4px]";
                  } else if (isPrevSameGroup && !isNextSameGroup) {
                    roundingClasses = "rounded-[20px] rounded-tr-[4px]";
                  } else if (!isPrevSameGroup && isNextSameGroup) {
                    roundingClasses = "rounded-[20px] rounded-br-[4px]";
                  }

                  return (
                    <div key={msg.id} className="w-full flex flex-col items-end">
                      {showDateSeparator && (
                        <div className="w-full flex items-center justify-center my-4">
                          <span className="px-3 py-1 bg-gray-100 dark:bg-white/10 rounded-full text-[11px] font-medium text-gray-500 dark:text-gray-400">
                            {new Date(msg.timestamp).toLocaleDateString([], {
                              weekday: "long",
                              month: "short",
                              day: "numeric",
                            })}
                          </span>
                        </div>
                      )}
                      <div
                        className="max-w-[85%] flex flex-col items-end group mb-1 cursor-pointer"
                        onClick={() => setExpandedMessageId(isExpanded ? null : msg.id)}
                      >
                        <div
                          className={`bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white px-4 py-2.5 shadow-lg shadow-blue-500/10 transition-transform group-hover:scale-[1.01] ${roundingClasses}`}
                        >
                          <p className="text-[14.5px] leading-relaxed whitespace-pre-wrap">
                            {msg.text}
                          </p>
                        </div>
                        <div
                          className={`overflow-hidden transition-all duration-300 ease-in-out ${
                            isExpanded ? "max-h-20 opacity-100 mt-1 mb-1 px-1" : "max-h-0 opacity-0"
                          }`}
                        >
                          <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold text-gray-500 dark:text-gray-400">
                              {msg.senderName}
                            </span>
                            <span className="text-[10px] text-gray-400">•</span>
                            <span className="text-[10px] text-gray-500 dark:text-gray-400 font-medium">
                              {new Date(msg.timestamp).toLocaleTimeString([], {
                                hour: "2-digit",
                                minute: "2-digit",
                              })}
                            </span>
                            <span className="text-[10px] text-gray-400">•</span>
                            <span
                              className={`text-[10px] font-bold capitalize tracking-wider ${
                                ['sent', 'delivered'].includes(msg.status)
                                  ? "text-green-500"
                                  : ['failed', 'rejected', 'undelivered', 'error'].includes(msg.status)
                                  ? "text-red-500"
                                  : "text-gray-400"
                              }`}
                            >
                              {['sending', 'pending', 'queued'].includes(msg.status)
                                ? "⟳"
                                : msg.status === "delivered"
                                ? "✓✓"
                                : msg.status === "sent"
                                ? "✓"
                                : (
                                    <FiAlertCircle
                                      size={10}
                                      className="inline mb-0.5"
                                    />
                                  )}{" "}
                              {msg.status}
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          ) : (
            <div className={`space-y-1 ${composeMode === 'bulk' && bulkSelectedContacts.length > 1 ? 'max-w-4xl mx-auto w-full' : ''}`}>
              {(() => {
                // Group view: show bulk messages organized by batch
                const isGroupView = (composeMode === 'bulk' && bulkSelectedContacts.length > 1) || activeBulkMessage;

                if (isGroupView) {
                  // In a bulk conversation, many per-recipient rows share the same text.
                  // Group them into "send events" so the thread shows the correct (latest) message(s).
                  const allMessages = (conversationMessages as Message[]).slice().sort((a, b) => {
                    return new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime();
                  });

                  type BulkSendGroup = {
                    id: string;
                    messageText: string;
                    timestamp: Date;
                    rows: Message[];
                  };

                  const groups: BulkSendGroup[] = [];
                  const GAP_MS = 2 * 60 * 1000; // 2 minutes

                  for (const row of allMessages) {
                    const rowText = (row.text || (row as any).message || "").toString();
                    const rowTime = new Date(row.timestamp);

                    const last = groups[groups.length - 1];
                    const canMerge =
                      !!last &&
                      last.messageText === rowText &&
                      Math.abs(rowTime.getTime() - last.timestamp.getTime()) <= GAP_MS;

                    if (canMerge) {
                      last.rows.push(row);
                      // Keep group's timestamp near the center of the send event (latest row time)
                      if (rowTime.getTime() > last.timestamp.getTime()) {
                        last.timestamp = rowTime;
                      }
                    } else {
                      const groupId = `bulkgrp-${rowTime.getTime()}-${groups.length}`;
                      groups.push({
                        id: groupId,
                        messageText: rowText,
                        timestamp: rowTime,
                        rows: [row],
                      });
                    }
                  }

                  // Render groups newest → oldest (chat-like)
                  const renderGroups = groups.slice().sort((a, b) => b.timestamp.getTime() - a.timestamp.getTime());

                  return renderGroups.map((grp, index) => {
                    const date = grp.timestamp;
                    const prev = renderGroups[index - 1];
                    const next = renderGroups[index + 1];

                    const msgDateStr = date.toDateString();
                    const prevDateStr = prev ? prev.timestamp.toDateString() : null;
                    const showDateSeparator = !prevDateStr || prevDateStr !== msgDateStr;

                    const campaignStats = {
                      sent: grp.rows.filter(m => (m.status || '').toLowerCase() === 'sent' || (m.status || '').toLowerCase() === 'delivered').length,
                      pending: grp.rows.filter(m => (m.status || '').toLowerCase() === 'pending' || (m.status || '').toLowerCase() === 'queued').length,
                      failed: grp.rows.filter(m => ['failed', 'error'].includes((m.status || '').toLowerCase())).length,
                      total: grp.rows.length
                    };

                    const isExpanded = expandedMessageId === grp.id;
                    const isNextSameDay = next && next.timestamp.toDateString() === msgDateStr;

                    let roundingClasses = "rounded-[20px]";
                    if (!showDateSeparator && isNextSameDay) {
                      roundingClasses = "rounded-[20px] rounded-tr-[4px] rounded-br-[4px]";
                    } else if (!showDateSeparator && !isNextSameDay) {
                      roundingClasses = "rounded-[20px] rounded-tr-[4px]";
                    } else if (showDateSeparator && isNextSameDay) {
                      roundingClasses = "rounded-[20px] rounded-br-[4px]";
                    }

                    return (
                      <div key={grp.id} className="flex flex-col items-end w-full mb-1">
                        {showDateSeparator && (
                          <div className="w-full flex items-center justify-center my-4">
                            <span className="px-3 py-1 bg-gray-100 dark:bg-white/10 rounded-full text-[11px] font-medium text-gray-500 dark:text-gray-400">
                              {date.toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' })}
                            </span>
                          </div>
                        )}

                        <div
                          className="max-w-[85%] flex flex-col items-end group cursor-pointer"
                          onClick={() => setExpandedMessageId(isExpanded ? null : grp.id)}
                        >
                          <div className={`bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white px-4 py-2.5 shadow-lg shadow-blue-500/10 transition-transform group-hover:scale-[1.01] ${roundingClasses}`}>
                            <div className="text-[14.5px] whitespace-pre-wrap leading-relaxed">
                              {grp.messageText}
                            </div>
                          </div>

                          <div className={`overflow-hidden transition-all duration-300 ease-in-out ${isExpanded ? 'max-h-20 opacity-100 mt-1 mb-1 px-1' : 'max-h-0 opacity-0'}`}>
                            <div className="flex flex-col items-end gap-1">
                              <div className="flex items-center gap-2">
                                <span className="text-[10px] font-semibold text-gray-500 dark:text-gray-400">
                                  {(grp.rows[0] as any).senderName || (grp.rows[0] as any).sender_id || 'NOLASMSPro'}
                                </span>
                                <span className="text-[10px] text-gray-400">•</span>
                                <span className="text-[10px] text-gray-500 dark:text-gray-400 font-medium">
                                  {date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                </span>
                              </div>
                              <StatusBadgeSummary stats={campaignStats} />
                            </div>
                          </div>

                          {!isExpanded && (
                            <div className="mt-1 px-1">
                              <StatusBadgeSummary stats={campaignStats} minimal />
                            </div>
                          )}
                        </div>
                      </div>
                    );
                  });
                }

                const sourceMessages = conversationMessages as any[];
                return sourceMessages.map((msg, index) => {
                  const isExpanded = expandedMessageId === msg.id;
                  const prevMsg = sourceMessages[index - 1];
                  const nextMsg = sourceMessages[index + 1];

                  const msgDateStr = new Date(msg.timestamp).toDateString();
                  const showDateSeparator = !prevMsg || new Date(prevMsg.timestamp).toDateString() !== msgDateStr;

                  const isPrevSameGroup = !showDateSeparator;
                  const isNextSameGroup = nextMsg && new Date(nextMsg.timestamp).toDateString() === msgDateStr;

                  let roundingClasses = "rounded-[20px]";
                  if (isPrevSameGroup && isNextSameGroup) {
                    roundingClasses = "rounded-[20px] rounded-tr-[4px] rounded-br-[4px]";
                  } else if (isPrevSameGroup && !isNextSameGroup) {
                    roundingClasses = "rounded-[20px] rounded-tr-[4px]";
                  } else if (!isPrevSameGroup && isNextSameGroup) {
                    roundingClasses = "rounded-[20px] rounded-br-[4px]";
                  }

                  return (
                    <div key={msg.id} className="w-full flex flex-col items-end">
                      {showDateSeparator && (
                        <div className="w-full flex items-center justify-center my-4">
                          <span className="px-3 py-1 bg-gray-100 dark:bg-white/10 rounded-full text-[11px] font-medium text-gray-500 dark:text-gray-400">
                            {new Date(msg.timestamp).toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' })}
                          </span>
                        </div>
                      )}
                      <div
                        className="max-w-[85%] flex flex-col items-end group mb-1 cursor-pointer"
                        onClick={() => setExpandedMessageId(isExpanded ? null : msg.id)}
                      >
                        <div className={`bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white px-4 py-2.5 shadow-lg shadow-blue-500/10 transition-transform group-hover:scale-[1.01] ${roundingClasses}`}>
                          <p className="text-[14.5px] leading-relaxed whitespace-pre-wrap">{msg.text}</p>
                        </div>

                        {msg.status === 'failed' && (
                          <div className="mt-1 flex flex-col items-end gap-0.5 text-[10px]">
                            <div className="flex items-center gap-1 text-red-500 font-bold">
                              <FiAlertCircle size={10} className="animate-pulse" /> Failed to send
                            </div>
                            {msg.errorReason && (
                              <div className="text-red-400 font-medium max-w-[260px] text-right">
                                {msg.errorReason}
                              </div>
                            )}
                          </div>
                        )}

                        <div className={`overflow-hidden transition-all duration-300 ease-in-out ${isExpanded ? 'max-h-20 opacity-100 mt-1 mb-1 px-1' : 'max-h-0 opacity-0'}`}>
                          <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold text-gray-500 dark:text-gray-400">
                              {msg.senderName}
                            </span>
                            <span className="text-[10px] text-gray-400">•</span>
                            <span className="text-[10px] text-gray-500 dark:text-gray-400 font-medium">
                              {new Date(msg.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                            </span>
                            <span className="text-[10px] text-gray-400">•</span>
                            <span className={`text-[10px] font-bold capitalize tracking-wider ${['sent', 'delivered'].includes(msg.status) ? 'text-green-500' : ['failed', 'rejected', 'undelivered', 'error'].includes(msg.status) ? 'text-red-500' : 'text-gray-400'}`}>
                              {['sending', 'pending', 'queued'].includes(msg.status) ? '⟳' : msg.status === 'delivered' ? '✓✓' : msg.status === 'sent' ? '✓' : <FiAlertCircle size={10} className="inline mb-0.5" />} {msg.status}
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                });
              })()}
            </div>
          )}
        </div>
        <div ref={messagesEndRef} />
      </div>

      {/* 3. Floating Input Card Area */}
      <div className="px-6 pb-6 pt-2 z-20 relative">
        {/* Scroll to bottom floating button */}
        <div
          className={`absolute -top-10 left-1/2 -translate-x-1/2 z-10 transition-all duration-300 ${
            showScrollButton ? "opacity-100 translate-y-0" : "opacity-0 translate-y-8 pointer-events-none"
          }`}
        >
          <button
            onClick={scrollToBottom}
            className="w-7 h-7 sm:w-8 sm:h-8 bg-white/80 dark:bg-black/40 backdrop-blur-md text-gray-500 dark:text-gray-400 border border-gray-200/60 dark:border-white/10 rounded-full shadow-md flex items-center justify-center hover:text-[#2b83fa] dark:hover:text-[#2b83fa] hover:scale-110 active:scale-95 transition-all"
            aria-label="Scroll to bottom"
          >
            <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 sm:h-4 sm:w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
            </svg>
          </button>
        </div>
        <div className="max-w-5xl mx-auto">
          <div className="bg-white dark:bg-[#1a1b1e] rounded-[1.5rem] border border-gray-200/80 dark:border-white/10 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:shadow-[0_20px_50px_rgba(0,0,0,0.3)] p-2 transition-all focus-within:ring-2 focus-within:ring-[#2b83fa]/20 dark:focus-within:ring-[#2b83fa]/10 relative z-20">
            <div className="flex flex-col">
              <textarea
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Type your message..."
                className="w-full bg-transparent border-none px-4 pt-3 pb-1 text-[15px] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 dark:placeholder-gray-500 resize-none focus:outline-none min-h-[56px] max-h-[200px] custom-scrollbar"
                rows={1}
                style={{ height: 'auto', minHeight: '56px' }}
              />

              {attachedFiles.length > 0 && (
                <div className="flex flex-wrap gap-2 px-4 pb-2">
                  {attachedFiles.map((file, index) => (
                    <div
                      key={index}
                      className="flex items-center gap-2 bg-gray-100 dark:bg-white/5 px-2 py-1 rounded-lg border border-gray-200 dark:border-white/10"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.414a4 4 0 00-5.656-5.656l-6.415 6.414a6 6 0 108.486 8.486L20.5 13" />
                      </svg>
                      <span className="text-[11px] font-medium text-gray-600 dark:text-gray-400 truncate max-w-[120px]">
                        {file.name}
                      </span>
                      <button
                        onClick={() => handleRemoveFile(index)}
                        className="p-0.5 hover:bg-gray-200 dark:hover:bg-white/10 rounded-md transition-colors"
                      >
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    </div>
                  ))}
                </div>
              )}

              <div className="flex items-center justify-between px-3 pt-2 pb-1 border-t border-gray-50 dark:border-white/5">
                <div className="flex items-center gap-1">
                  {/* Emoji Button */}
                  <div className="relative" ref={emojiPickerRef}>
                    <button
                      onClick={() => setIsEmojiPickerOpen(!isEmojiPickerOpen)}
                      className={`p-2 rounded-full transition-all ${isEmojiPickerOpen ? "bg-blue-50 text-[#2b83fa] dark:bg-white/10" : "text-gray-400 hover:text-[#2b83fa] hover:bg-blue-50 dark:hover:bg-white/5"}`}
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    </button>

                    {isEmojiPickerOpen && (
                      <div className="absolute bottom-full left-0 mb-2 p-2 bg-white dark:bg-[#1a1b1e] rounded-2xl border border-gray-200 dark:border-white/10 shadow-xl grid grid-cols-4 gap-1 z-50 animate-scale-up w-[184px]">
                        {commonEmojis.map(emoji => (
                          <button
                            key={emoji}
                            onClick={() => handleEmojiSelect(emoji)}
                            className="w-10 h-10 flex items-center justify-center text-xl hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors"
                          >
                            {emoji}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>

                  {/* Attachment Button */}
                  <button
                    onClick={() => fileInputRef.current?.click()}
                    className={`p-2 rounded-full transition-all ${attachedFiles.length > 0 ? "bg-blue-50 text-[#2b83fa] dark:bg-white/10" : "text-gray-400 hover:text-[#2b83fa] hover:bg-blue-50 dark:hover:bg-white/5"}`}
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.414a4 4 0 00-5.656-5.656l-6.415 6.414a6 6 0 108.486 8.486L20.5 13" />
                    </svg>
                  </button>
                  <input
                    type="file"
                    ref={fileInputRef}
                    onChange={handleFileChange}
                    className="hidden"
                    multiple
                  />

                  {isNewMessage && composeMode === "bulk" && message.length > 0 && bulkSelectedContacts.length > 0 && (
                    <div className="ml-2 flex items-center gap-1.5 px-2 py-1 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800/30">
                      <div className="w-1.5 h-1.5 rounded-full bg-[#2b83fa] animate-pulse"></div>
                      <span className="text-[10px] font-bold text-[#2b83fa] uppercase tracking-wide">Est. {totalEstimatedSms} SMS</span>
                    </div>
                  )}
                </div>

                <div className="flex items-center gap-4">
                  <span className="text-[12px] font-medium text-gray-400 dark:text-gray-500 tabular-nums">
                    {message.length} <span className="text-[10px] opacity-70">chars</span>
                  </span>
                  <button
                    onClick={handleSend}
                    disabled={isSendDisabled()}
                    className={`
                      group flex items-center justify-center gap-2 
                      bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] 
                      text-white transition-all 
                      ${isSendDisabled()
                        ? "opacity-30 cursor-not-allowed"
                        : "hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] active:scale-95 cursor-pointer"}
                      ${isNewMessage || selectedContacts.length > 0 ? "px-5 py-2.5 rounded-2xl" : "p-3 rounded-full"}
                      sm:px-6 sm:py-3 sm:rounded-2xl
                    `}
                    title={isSendDisabled() ? getSendDisabledReason() : undefined}
                  >
                    {loading ? (
                      <ShinyText
                        text="Sending..."
                        speed={2}
                        color="#ffffff"
                        shineColor="#ffffff"
                        spread={120}
                        className="font-bold text-[14px] hidden sm:inline"
                      />
                    ) : (
                      <>
                        <span className={`
                          hidden sm:inline font-bold text-[14px] tracking-tight transition-all duration-300
                          ${!isSendDisabled() ? "group-hover:-translate-x-1 group-hover:scale-[0.98]" : ""}
                        `}>
                          {getSendButtonText()}
                        </span>
                        <div className={`
                          transition-all duration-300 
                          ${!isSendDisabled() ? "group-hover:translate-x-1 group-hover:-translate-y-1 group-hover:scale-110 group-active:translate-x-4 group-active:-translate-y-4 group-active:opacity-0" : ""}
                        `}>
                          <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 -rotate-45" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3.478 2.405a.75.75 0 00-.926.94l2.432 7.905H13.5a.75.75 0 010 1.5H4.984l-2.432 7.905a.75.75 0 00.926.94 60.519 60.519 0 0018.445-8.986.75.75 0 000-1.218A60.517 60.517 0 003.478 2.405z" />
                          </svg>
                        </div>
                      </>
                    )}
                  </button>
                </div>
              </div>
            </div>
          </div>

          {!isNewMessage && currentRecipients.length > 0 && (
            <div className="mt-3 flex items-center gap-2 px-2">
              <span className="w-1.5 h-1.5 rounded-full bg-green-500"></span>
              <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 font-mono tracking-tight capitalize">
                To: {currentRecipients.map(c => c.name).join(', ')}
              </p>
            </div>
          )}

          {/* Send disabled reason note */}
          {showDisabledReason && (
            <div className="mt-2 flex items-center gap-2 px-2 py-1.5 bg-amber-50 dark:bg-amber-900/10 rounded-lg border border-amber-200/50 dark:border-amber-700/20">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
              <p className="text-[12px] font-medium text-amber-700 dark:text-amber-400">
                {getSendDisabledReason()}
              </p>
            </div>
          )}
        </div>
      </div>

      {/* 4. Toast Overlay */}
      <Snackbar
        open={toastOpen}
        autoHideDuration={2500}
        onClose={() => setToastOpen(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
        TransitionComponent={(props) => <Slide {...props} direction="up" />}
        transitionDuration={300}
      >
        <Alert
          onClose={() => setToastOpen(false)}
          severity={toastSeverity}
          sx={{
            // Minimal capsule glassmorphism
            backdropFilter: 'blur(16px)',
            WebkitBackdropFilter: 'blur(16px)',
            borderRadius: '9999px',
            px: 2.5,
            py: 1,
            fontSize: '0.85rem',
            fontWeight: 500,
            minWidth: 'auto',
            // Light mode glass effect
            background: toastSeverity === 'success'
              ? 'linear-gradient(135deg, rgba(255, 255, 255, 0.75) 0%, rgba(255, 255, 255, 0.55) 100%)'
              : 'linear-gradient(135deg, rgba(255, 255, 255, 0.75) 0%, rgba(255, 255, 255, 0.55) 100%)',
            border: '1px solid rgba(255, 255, 255, 0.4)',
            boxShadow: '0 4px 20px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.5)',
            color: '#1f2937',
            alignItems: 'center',
            justifyContent: 'center',
            '& .MuiAlert-icon': {
              color: toastSeverity === 'success' ? '#059669' : '#dc2626',
              mr: 1,
              fontSize: '1.1rem',
            },
            '& .MuiAlert-message': {
              py: 0,
            },
            '& .MuiAlert-action': {
              color: '#6b7280',
              pl: 1,
              '&:hover': {
                backgroundColor: 'rgba(0, 0, 0, 0.05)',
              },
            },
            // Dark mode glassmorphism
            '&.dark, .dark &': {
              background: toastSeverity === 'success'
                ? 'linear-gradient(135deg, rgba(30, 30, 35, 0.8) 0%, rgba(20, 20, 25, 0.85) 100%)'
                : 'linear-gradient(135deg, rgba(30, 30, 35, 0.8) 0%, rgba(20, 20, 25, 0.85) 100%)',
              border: '1px solid rgba(255, 255, 255, 0.08)',
              boxShadow: '0 4px 20px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.05)',
              color: '#f3f4f6',
              '& .MuiAlert-icon': {
                color: toastSeverity === 'success' ? '#34d399' : '#f87171',
              },
              '& .MuiAlert-action': {
                color: '#9ca3af',
                '&:hover': {
                  backgroundColor: 'rgba(255, 255, 255, 0.08)',
                },
              },
            },
          }}
          variant="filled"
        >
          {toastMessage}
        </Alert>
      </Snackbar>
    </div >
  );
};
