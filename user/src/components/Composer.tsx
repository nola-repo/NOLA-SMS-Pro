import { devLog } from '../utils/devLog';
import { useState, useRef, useEffect, useMemo, useCallback } from "react";

import { fetchContacts } from "../api/contacts";
import { fetchTemplates } from "../api/templates";
import { sendSms, sendBulkSms, interpolateMessage, checkMessageStatus, normalizePHNumber, type SenderId } from "../api/sms";
import { getRecipientKey, saveBulkMessage } from "../utils/storage";
import type { BulkMessageHistoryItem, Message } from "../types/Sms";
import type { Contact } from "../types/Contact";
import type { Template } from "../types/Template";
import { FiUser, FiUsers, FiMenu, FiMoreHorizontal, FiCopy, FiRefreshCw } from "react-icons/fi";
import ShinyText from "./ShinyText";
import { DotLottieReact } from "@lottiefiles/dotlottie-react";
import { useConversationMessages } from "../hooks/useConversationMessages";
import { useMessages as usePhoneMessages } from "../hooks/useMessages";
import { useLocationId } from "../context/LocationContext";
import { SenderSelector } from "./SenderSelector";
import { CreditBadge } from "./CreditBadge";
import { FiCheck, FiAlertCircle, FiLoader, FiFileText } from "react-icons/fi";
import { getAccountSettings } from "../utils/settingsStorage";
import { fetchAccountSenderConfig } from "../api/senderRequests";
import { buildDirectConversationId, buildGroupConversationId } from "../utils/conversationId";
import { estimateSmsSegments } from "../utils/smsSegments";
import { formatTemplateValidationMessage, validateTemplateContent } from "../utils/templateValidation";
import { buildContactNameLookup, isPhoneLike, resolveContactNameByPhone } from "../utils/contactDisplay";
import { getRetryBadgeInfo } from "../utils/smsStatus";

import { BulkConfirmationModal, BulkSendSummaryModal, MessageDetailsModal, type MessageDetailsSelection, type BulkConfirmationState, type BulkSendSummaryState } from "./composer/ComposerModals";

interface ComposerProps {
  selectedContacts: Contact[];
  isNewMessage?: boolean;
  activeContact?: Contact | null;
  activeBulkMessage?: BulkMessageHistoryItem | null;
  onSelectContact?: (contact: Contact) => void;
  onSelectBulkMessage?: (item: BulkMessageHistoryItem) => void;
  onRequestSettings?: () => void;
  onToggleMobileMenu?: () => void;
  darkMode?: boolean;
}

type RecipientAnalysis = {
  uniqueRecipients: Contact[];
  invalidRecipients: Contact[];
  duplicateCount: number;
  duplicatePhones: string[];
  totalCount: number;
  uniqueCount: number;
};



const MessageHistorySkeleton: React.FC = () => {
  const rows = [
    { width: "w-[46%]", height: "h-10" },
    { width: "w-[68%]", height: "h-16" },
    { width: "w-[58%]", height: "h-14" },
    { width: "w-[72%]", height: "h-20" },
    { width: "w-[42%]", height: "h-11" },
    { width: "w-[66%]", height: "h-16" },
    { width: "w-[50%]", height: "h-12" },
    { width: "w-[62%]", height: "h-14" },
  ];

  return (
    <div className="flex-1 flex flex-col items-end justify-start gap-2 px-1 sm:px-3 pt-2 pb-6 animate-in fade-in duration-300">
      <div className="mx-auto mb-3 h-7 w-28 rounded-full bg-[#edf0f3] dark:bg-white/10 skeleton-gleam" />
      {rows.map((row, index) => (
        <div key={`history-skeleton-${index}`} className="flex w-full justify-end">
          <div className={`${row.width} max-w-[620px] ${row.height} rounded-[20px] rounded-br-md bg-[#edf0f3] dark:bg-white/10 skeleton-gleam`} />
        </div>
      ))}
    </div>
  );
};

type ContactWithPhoneAliases = Contact & {
  phone_number?: string;
  phoneNumber?: string;
  mobileNumber?: string;
  number?: string;
};

const resolveContactPhone = (contact: ContactWithPhoneAliases | undefined | null): string => {
  return (
    contact?.phone ||
    contact?.phone_number ||
    contact?.phoneNumber ||
    contact?.mobileNumber ||
    contact?.number ||
    ""
  ).trim();
};

const normalizeRecipient = (contact: Contact): Contact => ({
  ...contact,
  phone: resolveContactPhone(contact),
});

const contactPhoneKey = (phone?: string | null) => (phone || "").replace(/\D/g, "").slice(-10);

const analyzeRecipients = (recipients: Contact[]): RecipientAnalysis => {
  const uniqueRecipients: Contact[] = [];
  const invalidRecipients: Contact[] = [];
  const duplicatePhones = new Set<string>();
  const seen = new Set<string>();

  recipients.forEach((recipient) => {
    const normalized = normalizePHNumber(resolveContactPhone(recipient));
    if (!normalized) {
      invalidRecipients.push(recipient);
      return;
    }

    if (seen.has(normalized)) {
      duplicatePhones.add(normalized);
      return;
    }

    seen.add(normalized);
    uniqueRecipients.push({ ...recipient, phone: normalized });
  });

  return {
    uniqueRecipients,
    invalidRecipients,
    duplicateCount: recipients.length - invalidRecipients.length - uniqueRecipients.length,
    duplicatePhones: Array.from(duplicatePhones),
    totalCount: recipients.length,
    uniqueCount: uniqueRecipients.length,
  };
};



const matchesContactUpdate = (target: Contact, contact: Contact, previous?: Contact | null) => {
  if (target.id === contact.id || (previous && target.id === previous.id)) return true;
  const targetPhone = contactPhoneKey(resolveContactPhone(target));
  return !!targetPhone && (
    targetPhone === contactPhoneKey(resolveContactPhone(contact)) ||
    targetPhone === contactPhoneKey(resolveContactPhone(previous))
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
  onToggleMobileMenu,
  darkMode
}) => {
  const [message, setMessage] = useState("");
  const [sendingProgress, setSendingProgress] = useState<{current: number; total: number} | null>(null);
  const { locationId } = useLocationId();
  const [senderName, setSenderName] = useState<SenderId>("");
  const [approvedSenderId, setApprovedSenderId] = useState<string | undefined>(undefined);
  const [toggleEnabled, setToggleEnabled] = useState(true);

  const handleSenderChange = (val: SenderId) => {
    setSenderName(val);
  };

  // Default to subaccount's active approved sender if available, else platform default
  useEffect(() => {
    let cancelled = false;
    fetchAccountSenderConfig(locationId || undefined).then(cfg => {
      if (cancelled) return;
      setApprovedSenderId(cfg.approved_sender_id || undefined);
      
      if (cfg.toggle_enabled !== undefined) {
        setToggleEnabled(cfg.toggle_enabled);
      }
      
      const defaultSender = cfg.approved_sender_id || cfg.system_default_sender || "NOLASMSPro";
      setSenderName((current) => current || defaultSender);
    });
    return () => { cancelled = true; };
  }, [locationId]);
  const [expandedMessageId, setExpandedMessageId] = useState<string | null>(null);
  const [messageDetails, setMessageDetails] = useState<MessageDetailsSelection | null>(null);
  const [bulkConfirmation, setBulkConfirmation] = useState<BulkConfirmationState | null>(null);
  const [bulkSendSummary, setBulkSendSummary] = useState<BulkSendSummaryState | null>(null);
  const [lottieError, setLottieError] = useState(false);

  useEffect(() => {
    if (!messageDetails) return;

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setMessageDetails(null);
      }
    };

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [messageDetails]);

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

  const contactMap = useMemo(() => {
    return buildContactNameLookup(allContacts);
  }, [allContacts]);

  const getResolvedContactName = useCallback((contact: Contact | undefined | null): string => {
    if (!contact) return "";
    const name = contact.name || "";
    if (name && !isPhoneLike(name)) {
      return name;
    }
    const phone = resolveContactPhone(contact) || name;
    const matched = resolveContactNameByPhone(contactMap, phone);
    return matched || phone || name;
  }, [contactMap]);

  const activePhoneNumber = useMemo(() => {
    if (activeContact) return resolveContactPhone(activeContact);
    if (selectedContacts.length === 1) return resolveContactPhone(selectedContacts[0]);
    return undefined;
  }, [activeContact, selectedContacts]);

  const draftSingleContact = useMemo(() => {
    if (activeContact || activeBulkMessage || selectedContacts.length > 0) return null;
    if (composeMode !== "single" || bulkSelectedContacts.length !== 1) return null;
    return bulkSelectedContacts[0];
  }, [activeContact, activeBulkMessage, selectedContacts.length, composeMode, bulkSelectedContacts]);

  const historyPhoneNumber = activePhoneNumber || draftSingleContact?.phone;

  /**
   * Stable conversation_id for message fetching:
   *  - Direct chat:             {locationId}_conv_{phone} (or legacy conv_{phone})
   *  - Existing bulk from sidebar: group_{batchId}  (batchId already contains "batch_" prefix from server)
   *  - New bulk in progress:    undefined (messages will appear after navigation to activeBulkMessage)
   *
   * Priority: live LocationContext value → last-known value from storage (handles the
   * brief window before the iframe handshake completes, or standalone URL usage).
   */
  const conversationId = useMemo(() => {
    const effectiveLocationId = locationId || getAccountSettings().ghlLocationId || null;
    if (historyPhoneNumber) {
      return buildDirectConversationId(historyPhoneNumber, effectiveLocationId) || undefined;
    }
    if (activeBulkMessage?.batchId) {
      const prefix = activeBulkMessage.locationId || effectiveLocationId;
      return buildGroupConversationId(activeBulkMessage.batchId, prefix) || undefined;
    }
    return undefined;
  }, [historyPhoneNumber, activeBulkMessage, locationId]);

  const {
    messages: conversationMessages,
    loading: historyLoading,
    error: historyError,
    errorStatus: historyErrorStatus,
    addOptimisticMessage,
    updateMessageStatus,
    refresh,
    conversation,
  } = useConversationMessages(conversationId, recipientKeyFocus);

  // Optional per-contact fallback: raw outbound log view by phone number
  const {
    messages: phoneLogMessages,
    loading: phoneLogLoading,
    error: phoneLogError,
  } = usePhoneMessages(historyPhoneNumber);

  const [useRawLogView, setUseRawLogView] = useState(false);
  const shouldUseRawLogFallback =
    !useRawLogView &&
    !!historyPhoneNumber &&
    !historyLoading &&
    conversationMessages.length === 0 &&
    phoneLogMessages.length > 0;

  const dropdownRef = useRef<HTMLDivElement>(null);

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const customValuesRef = useRef<HTMLDivElement>(null);
  const templatePickerRef = useRef<HTMLDivElement>(null);
  const messageInputRef = useRef<HTMLTextAreaElement>(null);
  const sendGuardRef = useRef(false);
  const tagsRef = useRef<HTMLDivElement>(null);
  const msgAreaRef = useRef<HTMLDivElement>(null);
  const touchStartYMsg = useRef<number>(0);
  const [isPullRefreshing, setIsPullRefreshing] = useState(false);

  // ─── Lifecycle & Timer Cleanup (Memory Leak Protection) ─────────────────────
  const isMountedRef = useRef(true);
  const activeTimersRef = useRef<Set<number>>(new Set());

  const setSafeTimeout = useCallback((callback: () => void, ms?: number): number => {
    const timerId = window.setTimeout(() => {
      activeTimersRef.current.delete(timerId);
      if (isMountedRef.current) {
        callback();
      }
    }, ms);
    activeTimersRef.current.add(timerId);
    return timerId;
  }, []);

  const clearSafeTimeout = useCallback((timerId: number | null | undefined) => {
    if (timerId !== null && timerId !== undefined) {
      window.clearTimeout(timerId);
      activeTimersRef.current.delete(timerId);
    }
  }, []);

  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
      activeTimersRef.current.forEach((timerId) => window.clearTimeout(timerId));
      activeTimersRef.current.clear();
    };
  }, []);

  // Interactive features state
  const [isCustomValuesOpen, setIsCustomValuesOpen] = useState(false);
  const [isTagsOpen, setIsTagsOpen] = useState(false);
  const [isTemplatesOpen, setIsTemplatesOpen] = useState(false);
  const [templateOptions, setTemplateOptions] = useState<Template[]>([]);
  const [templatesLoading, setTemplatesLoading] = useState(false);
  const [selectedTagsToApply, setSelectedTagsToApply] = useState<string[]>([]);

  // ─── Toast (custom, no-blink) ───────────────────────────────────────────────
  // We keep toast state in a ref *and* in state so the equality check is never
  // stale, and we never do a close→open cycle (which caused the blink).
  const [toast, setToast] = useState<{ open: boolean; severity: 'success' | 'error'; message: string }>({
    open: false, severity: 'success', message: ''
  });
  const toastRef = useRef(toast);
  toastRef.current = toast;
  const toastHideTimer = useRef<number | null>(null);

  const showToast = useCallback((severity: 'success' | 'error', msg: string) => {
    if (!isMountedRef.current) return;
    // If the exact same toast is already visible — do nothing
    if (toastRef.current.open && toastRef.current.severity === severity && toastRef.current.message === msg) return;
    // Cancel any pending auto-close from a previous toast
    if (toastHideTimer.current) clearSafeTimeout(toastHideTimer.current);
    // Update in-place (no close→open = no blink)
    setToast({ open: true, severity, message: msg });
    // Auto-dismiss after 3 s
    toastHideTimer.current = setSafeTimeout(() => {
      if (isMountedRef.current) {
        setToast(t => ({ ...t, open: false }));
      }
    }, 3000);
  }, [setSafeTimeout, clearSafeTimeout]);

  const dismissToast = useCallback(() => {
    if (toastHideTimer.current) clearSafeTimeout(toastHideTimer.current);
    if (isMountedRef.current) {
      setToast(t => ({ ...t, open: false }));
    }
  }, [clearSafeTimeout]);
  const [showDisabledReason, setShowDisabledReason] = useState(false);
  const [showScrollButton, setShowScrollButton] = useState(false);



  const handleScroll = () => {
    if (!msgAreaRef.current) return;
    const { scrollTop, scrollHeight, clientHeight } = msgAreaRef.current;
    // Show the button if scrolled up more than 150px from the bottom
    setShowScrollButton(scrollHeight - scrollTop - clientHeight > 150);
  };

  const scrollToBottom = () => {
    if (msgAreaRef.current) {
      msgAreaRef.current.scrollTo({
        top: msgAreaRef.current.scrollHeight,
        behavior: "smooth"
      });
    } else {
      messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
    }
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
      setSafeTimeout(scrollToBottom, 50);
    }
    
    prevConversationId.current = conversationId;
    prevRawLogView.current = useRawLogView;
    prevMessagesLength.current = currentLength;
  }, [conversationMessages, phoneLogMessages, useRawLogView, conversationId, setSafeTimeout]);

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

  // Sync bulkSelectedContacts with real-time conversation members for group chats
  const lastSyncedMembersKeyRef = useRef<string>("");
  useEffect(() => {
    if (activeBulkMessage && conversation?.members && conversation.members.length > 0) {
      const derivedContacts: Contact[] = conversation.members.map((num) => {
        const contactName = resolveContactNameByPhone(contactMap, num);
        return {
          id: `bulk-derive-${num}`,
          name: contactName || num,
          phone: num
        };
      });
      const membersKey = derivedContacts.map(c => `${c.phone}:${c.name}`).sort().join(',');
      if (lastSyncedMembersKeyRef.current !== membersKey) {
        lastSyncedMembersKeyRef.current = membersKey;
        setBulkSelectedContacts(derivedContacts);
      }
    } else {
      lastSyncedMembersKeyRef.current = "";
    }
  }, [activeBulkMessage, conversation, contactMap]);

  // Reset bulkSelectedContacts when switching from bulk to single mode
  useEffect(() => {
    setBulkSelectedContacts((current) =>
      composeMode === "single" && current.length > 1 ? current.slice(0, 1) : current
    );
    // Clear recipient filter when switching modes or conversations
    setRecipientKeyFocus(undefined);
  }, [composeMode, conversationId]);

  useEffect(() => {
    fetchContacts(locationId || undefined).then((contacts) => {
      if (isMountedRef.current) {
        setAllContacts(contacts);
      }
    }).catch(devLog.error);
  }, [locationId]);

  useEffect(() => {
    const handleContactUpdated = (event: Event) => {
      const { contact, previous } = (event as CustomEvent<{ contact?: Contact; previous?: Contact }>).detail || {};
      if (!contact || !isMountedRef.current) return;

      setAllContacts((current) => current.map((item) =>
        matchesContactUpdate(item, contact, previous) ? { ...item, ...contact } : item
      ));
      setBulkSelectedContacts((current) => current.map((item) =>
        matchesContactUpdate(item, contact, previous) ? { ...item, ...contact } : item
      ));
      fetchContacts(locationId || undefined).then((contacts) => {
        if (isMountedRef.current) setAllContacts(contacts);
      }).catch(devLog.error);
    };

    window.addEventListener("nola-contact-updated", handleContactUpdated);
    return () => window.removeEventListener("nola-contact-updated", handleContactUpdated);
  }, [locationId]);

  // Pre-warm template cache on mount/location change
  useEffect(() => {
    const prewarm = async () => {
      if (locationId) {
        try {
          const templates = await fetchTemplates(locationId, false);
          if (isMountedRef.current) {
            setTemplateOptions(templates);
          }
        } catch (err) {
          devLog.warn("Background template cache pre-warming failed:", err);
        }
      }
    };
    prewarm();
  }, [locationId]);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsPickerOpen(false);
      }
      if (customValuesRef.current && !customValuesRef.current.contains(event.target as Node)) {
        setIsCustomValuesOpen(false);
      }
      if (templatePickerRef.current && !templatePickerRef.current.contains(event.target as Node)) {
        setIsTemplatesOpen(false);
      }
      if (tagsRef.current && !tagsRef.current.contains(event.target as Node)) {
        setIsTagsOpen(false);
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
    const phoneQ = searchQuery.replace(/\s+/g, "").toLowerCase();
    return allContacts.filter(c =>
      c.name.toLowerCase().includes(lowerQ) ||
      c.phone.replace(/\s+/g, "").toLowerCase().includes(phoneQ)
    );
  }, [searchQuery, allContacts]);

  const handleSelectBulkContact = (contact: Contact) => {
    if (composeMode === "single") {
      if (bulkSelectedContacts.length > 0 && bulkSelectedContacts[0].id !== contact.id) {
        setComposeMode("bulk");
        setBulkSelectedContacts(prev => {
          if (!prev.find(c => c.id === contact.id)) {
            return [...prev, contact];
          }
          return prev;
        });
      } else {
        setBulkSelectedContacts([contact]);
        setIsPickerOpen(false);
      }
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
        if (bulkSelectedContacts.length > 0 && bulkSelectedContacts[0].phone !== newManualContact.phone) {
          setComposeMode("bulk");
          setBulkSelectedContacts(prev => {
            if (!prev.find(c => c.phone === newManualContact.phone)) {
              return [...prev, newManualContact];
            }
            return prev;
          });
        } else {
          setBulkSelectedContacts([newManualContact]);
        }
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
  const customValuesList = [
    { label: "Contact First Name", value: "{{contact.first_name}}" },
    { label: "Contact Last Name", value: "{{contact.last_name}}" },
    { label: "Contact Full Name", value: "{{contact.name}}" },
    { label: "Contact Phone", value: "{{contact.phone}}" },
    { label: "Contact Email", value: "{{contact.email}}" },
    { label: "Company Name", value: "{{company.name}}" },
  ];

  const insertAtCursor = (text: string) => {
    const input = messageInputRef.current;
    const start = input?.selectionStart ?? message.length;
    const end = input?.selectionEnd ?? message.length;
    const nextMessage = `${message.slice(0, start)}${text}${message.slice(end)}`;
    const nextCursor = start + text.length;

    setMessage(nextMessage);
    setSafeTimeout(() => {
      input?.focus();
      input?.setSelectionRange(nextCursor, nextCursor);
    }, 0);
  };

  const handleCustomValueSelect = (val: string) => {
    insertAtCursor(val);
    setIsCustomValuesOpen(false);
  };

  const handleTemplateToggle = async () => {
    const nextOpen = !isTemplatesOpen;
    setIsTemplatesOpen(nextOpen);
    setIsCustomValuesOpen(false);
    setIsTagsOpen(false);

    if (nextOpen && templateOptions.length === 0 && !templatesLoading) {
      setTemplatesLoading(true);
      try {
        setTemplateOptions(await fetchTemplates(locationId || undefined));
      } catch (error) {
        devLog.error("Failed to load templates:", error);
        showToast("error", "Templates could not be loaded. Check your connection and try again.");
      } finally {
        setTemplatesLoading(false);
      }
    }
  };

  const handleTemplateSelect = (template: Template) => {
    const validation = validateTemplateContent(template.content);

    // Hard block: the template contains unsupported {{...}} variables that won't resolve
    if (!validation.isValid) {
      showToast("error", `Template contains unsupported placeholders. ${formatTemplateValidationMessage(validation)}`);
      return;
    }

    insertAtCursor(template.content);
    setIsTemplatesOpen(false);

    // Soft warning: template is missing recommended contact placeholders (still inserted)
    if (validation.missingGroups.length > 0) {
      showToast("success", `Inserted ${template.name}. Tip: add a contact placeholder like ${validation.missingGroups[0].examples[0]} to personalise it.`);
    } else {
      showToast("success", `Inserted ${template.name}.`);
    }
  };

  const allTags = useMemo(() => {
    const tagSet = new Set<string>();
    allContacts.forEach(c => {
      if (c.tags) {
        c.tags.forEach(t => tagSet.add(t));
      }
    });
    return Array.from(tagSet).sort();
  }, [allContacts]);

  const handleTagToggle = (tag: string) => {
    setSelectedTagsToApply(prev =>
      prev.includes(tag) ? prev.filter(t => t !== tag) : [...prev, tag]
    );
  };

  // SMS length calculation
  const smsEstimate = estimateSmsSegments(message);
  const smsSegments = smsEstimate.segments;

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

  const activeRecipientAnalysis = analyzeRecipients(getActiveRecipients().map(normalizeRecipient).filter(contact => contact.phone));
  const totalEstimatedSms = composeMode === "bulk" && isNewMessage
    ? smsSegments * activeRecipientAnalysis.uniqueCount
    : smsSegments;
  const estimatedCreditCost = smsSegments * Math.max(activeRecipientAnalysis.uniqueCount, activeRecipientAnalysis.totalCount > 0 ? 1 : 0);
  const handleSend = (confirmedBulk = false) => {
    if (sendGuardRef.current) return;

    // Guard to ensure only one toast fires per send action
    let toastShown = false;
    const guardedToast = (severity: "success" | "error", msg: string) => {
      toastShown = true;
      showToast(severity, msg);
    };
    let recipients = getActiveRecipients()
      .map(normalizeRecipient)
      .filter(contact => contact.phone);
    const messageText = (messageInputRef.current?.value ?? message).trim();

    if (!messageText) {
      setShowDisabledReason(true);
      setSafeTimeout(() => {
        if (isMountedRef.current) setShowDisabledReason(false);
      }, 3000);
      return;
    }
    if (recipients.length === 0) {
      setShowDisabledReason(true);
      setSafeTimeout(() => {
        if (isMountedRef.current) setShowDisabledReason(false);
      }, 3000);
      return;
    }

    const analysis = analyzeRecipients(recipients);
    if (analysis.invalidRecipients.length > 0) {
      showToast("error", `${analysis.invalidRecipients.length} recipient${analysis.invalidRecipients.length === 1 ? " has" : "s have"} an invalid phone number.`);
      setShowDisabledReason(true);
      setSafeTimeout(() => {
        if (isMountedRef.current) setShowDisabledReason(false);
      }, 3000);
      return;
    }

    const isBulkSend = analysis.uniqueCount > 1;
    if (isBulkSend && !confirmedBulk) {
      const confirmationSegments = estimateSmsSegments(messageText).segments;
      setBulkConfirmation({
        messageText,
        totalCount: analysis.totalCount,
        uniqueCount: analysis.uniqueCount,
        duplicateCount: analysis.duplicateCount,
        duplicatePhones: analysis.duplicatePhones,
        segments: confirmationSegments,
        estimatedCredits: confirmationSegments * analysis.uniqueCount,
      });
      return;
    }

    const skippedDuplicateCount = analysis.duplicateCount;
    recipients = analysis.uniqueRecipients;
    setBulkConfirmation(null);

    sendGuardRef.current = true;
    setSafeTimeout(() => {
      sendGuardRef.current = false;
    }, 750);

    // Snapshot inputs and clear the compose area immediately so the user
    // can start typing/sending the next message right away.
    const currentTags = [...selectedTagsToApply];
    setMessage("");
    setSelectedTagsToApply([]);

    // Fire-and-forget: kick off the async work in the background.
    // The button is NOT disabled while the API call is in-flight.
    (async () => {
      try {
        // Check if we're viewing an existing bulk message conversation
        const isExistingBulkConversation = activeBulkMessage && recipients.length > 1;

        if (recipients.length === 1 || isExistingBulkConversation) {
          // Single message or appending to existing bulk conversation
          if (recipients.length === 1) {
            // Optimistic update for single message
            const resolvedRecipientName = getResolvedContactName(recipients[0]);
            const optimisticText = interpolateMessage(messageText, { name: resolvedRecipientName, phone: recipients[0].phone });
            const shouldPromoteDraftConversation = !activeContact && !activeBulkMessage && !!onSelectContact;
            const optimisticConversationId = shouldPromoteDraftConversation
              ? buildDirectConversationId(
                  recipients[0].phone,
                  locationId || getAccountSettings().ghlLocationId || null
                ) || undefined
              : conversationId;
            const tempId = addOptimisticMessage(optimisticText, senderName, optimisticConversationId);
            if (shouldPromoteDraftConversation) {
              onSelectContact(recipients[0]);
            }
            const smsResult = await sendSms(recipients[0].phone, messageText, senderName, undefined, resolvedRecipientName, undefined, recipients[0].ghl_contact_id, currentTags, recipients[0].email);

            if (smsResult.success) {
              const messageIds = smsResult.messageIds || [];
              if (messageIds.length > 0) {
                updateMessageStatus(tempId, 'sending', messageIds[0]);
              } else {
                updateMessageStatus(tempId, 'sending');
              }
              guardedToast("success", smsResult.message || "Message sent successfully!");

              // Dispatch event to refresh credit balance
              window.dispatchEvent(new Event('sms-sent'));

              // Re-fetch from database after a short delay to get the stored message
              setSafeTimeout(() => {
                if (isMountedRef.current) refresh();
              }, 2000);

              // Real-time status polling: check Semaphore for actual delivery status
              // within seconds rather than waiting for the 5-min cron.
              if (messageIds.length > 0) {
                let attempts = 0;
                const maxAttempts = 30; // ~60s total
                const pollStatus = async () => {
                  if (!isMountedRef.current) return;
                  attempts++;
                  const statusMap = await checkMessageStatus(messageIds);
                  if (!isMountedRef.current) return;
                  const allResolved = messageIds.every(id => {
                    const s = (statusMap[id] || '').toLowerCase();
                    return s === 'sent' || s === 'failed' || s === 'success';
                  });

                  // Refresh messages to show DB-persisted status
                  if (allResolved || attempts >= maxAttempts) {
                    if (isMountedRef.current) refresh();
                  } else {
                    setSafeTimeout(pollStatus, 2000);
                  }
                };
                setSafeTimeout(pollStatus, 2000);
              }

              // Navigate to contact view if not already there
              if (activeContact || shouldPromoteDraftConversation) {
                // Already viewing contact, just refresh
              } else if (onSelectContact && recipients[0]) {
                setSafeTimeout(() => {
                  if (isMountedRef.current) onSelectContact(recipients[0]);
                }, 500);
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

            // Add optimistic messages to each recipient's individual conversation thread
            const tempIds: Record<string, string> = {};
            recipients.forEach(r => {
              const optConvId = buildDirectConversationId(
                r.phone,
                locationId || getAccountSettings().ghlLocationId || null
              ) || undefined;
              const resolvedName = getResolvedContactName(r);
              const personalizedText = interpolateMessage(messageText, { name: resolvedName, phone: r.phone, email: r.email });
              const tempId = addOptimisticMessage(personalizedText, senderName, optConvId);
              tempIds[r.phone] = tempId;
            });

            // Add a single optimistic message in the current bulk conversation (if viewing it)
            let groupTempId: string | undefined = undefined;
            if (activeBulkMessage) {
              groupTempId = addOptimisticMessage(messageText, senderName, conversationId);
            }

            setSendingProgress({ current: 0, total: recipients.length });

            // Send bulk SMS - if we have an existing batchId, it will add to that conversation
            const { results } = await sendBulkSms(
              phones,
              messageText,
              senderName,
              recipients,
              recipientKey,
              batchId,
              currentTags,
              (current, total, result) => {
                if (!isMountedRef.current) return;
                setSendingProgress({ current, total });
                const phone = result.number;
                const tempId = phone ? tempIds[phone] : undefined;
                if (tempId) {
                  const messageIds = result.messageIds || [];
                  updateMessageStatus(tempId, result.success ? 'sending' : 'failed', messageIds[0], result.success ? undefined : result.message);
                }
              }
            );
            if (!isMountedRef.current) return;
            const successCount = results.filter(r => r.success).length;
            // Use results.length (deduplicated unique phones) as the denominator so that
            // contacts sharing the same phone number don't inflate the "failed" count.
            const sentTotal = results.length;
            const failedCount = sentTotal - successCount;
            setBulkSendSummary({ total: sentTotal + skippedDuplicateCount, sent: successCount, failed: failedCount, skipped: skippedDuplicateCount });

            if (groupTempId) {
              updateMessageStatus(groupTempId, successCount > 0 ? 'sending' : 'failed');
            }

            if (successCount > 0) {
              const successMsg = failedCount > 0
                ? `Sent ${successCount}/${sentTotal} - ${failedCount} failed${skippedDuplicateCount > 0 ? `, ${skippedDuplicateCount} skipped` : ""}`
                : `Sent all ${sentTotal} messages successfully${skippedDuplicateCount > 0 ? `; ${skippedDuplicateCount} duplicate skipped` : ""}.`;
              guardedToast(failedCount > 0 ? "error" : "success", successMsg);

              // Refresh to show new messages in the conversation
              setSafeTimeout(() => {
                if (isMountedRef.current) refresh();
              }, 2000);

              // Real-time status polling: check Semaphore for actual delivery status
              const allMessageIds = results.flatMap(r => r.messageIds || []);
              if (allMessageIds.length > 0) {
                let attempts = 0;
                const maxAttempts = 30; // ~60s total
                const pollStatus = async () => {
                  if (!isMountedRef.current) return;
                  attempts++;
                  const statusMap = await checkMessageStatus(allMessageIds);
                  if (!isMountedRef.current) return;
                  const allResolved = allMessageIds.every(id => {
                    const s = (statusMap[id] || '').toLowerCase();
                    return s === 'sent' || s === 'failed' || s === 'success';
                  });

                  // Refresh messages to show DB-persisted status
                  if (allResolved || attempts >= maxAttempts) {
                    if (isMountedRef.current) refresh();
                  } else {
                    setSafeTimeout(pollStatus, 2000);
                  }
                };
                setSafeTimeout(pollStatus, 2000);
              }
            } else {
              guardedToast("error", "Failed to send bulk messages");
            }
            if (isMountedRef.current) {
              setSendingProgress(null);
            }
          }
        } else {
          // NEW bulk SMS sending (creating new conversation)
          const phones = recipients.map(c => c.phone);
          const recipientKey = getRecipientKey(phones);
          const generatedBatchId = `batch-${Date.now()}`;

          // Add optimistic messages to each recipient's individual conversation thread
          const tempIds: Record<string, string> = {};
          recipients.forEach(r => {
            const optConvId = buildDirectConversationId(
              r.phone,
              locationId || getAccountSettings().ghlLocationId || null
            ) || undefined;
            const resolvedName = getResolvedContactName(r);
            const personalizedText = interpolateMessage(messageText, { name: resolvedName, phone: r.phone, email: r.email });
            const tempId = addOptimisticMessage(personalizedText, senderName, optConvId);
            tempIds[r.phone] = tempId;
          });

          // Define item for navigation immediately with status 'sending'
          const effectiveLocationId = locationId || getAccountSettings().ghlLocationId || undefined;
          const bulkItemForNav: BulkMessageHistoryItem = {
            id: `bulk-db-${generatedBatchId}`,
            message: messageText,
            recipientCount: recipients.length,
            recipientNames: recipients.map(r => getResolvedContactName(r)),
            recipientNumbers: recipients.map(r => r.phone),
            recipientKey: recipientKey,
            timestamp: new Date().toISOString(),
            status: 'sending',
            batchId: generatedBatchId,
            fromDatabase: true,
            locationId: effectiveLocationId
          };
          const groupConversationId = buildGroupConversationId(generatedBatchId, effectiveLocationId) || `group_${generatedBatchId}`;
          const groupTempId = addOptimisticMessage(messageText, senderName, groupConversationId);

          saveBulkMessage(bulkItemForNav);
          window.dispatchEvent(new CustomEvent('nola-bulk-message-created', {
            detail: bulkItemForNav
          }));

          // First, navigate to bulk message view immediately (this sets activeBulkMessage in Dashboard)
          if (onSelectBulkMessage) {
            onSelectBulkMessage(bulkItemForNav);
          }

          setSendingProgress({ current: 0, total: recipients.length });

          // Fire-and-forget sending process in background
          (async () => {
            try {
              const { results } = await sendBulkSms(
                phones,
                messageText,
                senderName,
                recipients,
                recipientKey,
                generatedBatchId,
                currentTags,
                (current, total, result) => {
                  if (!isMountedRef.current) return;
                  setSendingProgress({ current, total });
                  const phone = result.number;
                  const tempId = phone ? tempIds[phone] : undefined;
                  if (tempId) {
                    const messageIds = result.messageIds || [];
                    updateMessageStatus(tempId, result.success ? 'sending' : 'failed', messageIds[0], result.success ? undefined : result.message);
                  }
                }
              );
              if (!isMountedRef.current) return;
              const successCount = results.filter(r => r.success).length;
              // Use deduplicated result count to avoid false "failed" for duplicate phone numbers
              const sentTotal = results.length;
              const failedCount = sentTotal - successCount;
              setBulkSendSummary({ total: sentTotal + skippedDuplicateCount, sent: successCount, failed: failedCount, skipped: skippedDuplicateCount });

              if (groupTempId) {
                updateMessageStatus(groupTempId, successCount > 0 ? 'sending' : 'failed');
              }

              if (successCount > 0) {
                const successMsg = failedCount > 0
                  ? `Sent ${successCount}/${sentTotal} - ${failedCount} failed${skippedDuplicateCount > 0 ? `, ${skippedDuplicateCount} skipped` : ""}`
                  : `Sent all ${sentTotal} messages successfully${skippedDuplicateCount > 0 ? `; ${skippedDuplicateCount} duplicate skipped` : ""}.`;
                guardedToast(failedCount > 0 ? "error" : "success", successMsg);
                window.dispatchEvent(new Event('sms-sent'));

                const updatedBulkItem: BulkMessageHistoryItem = {
                  ...bulkItemForNav,
                  status: 'sent'
                };
                saveBulkMessage(updatedBulkItem);
                window.dispatchEvent(new CustomEvent('nola-bulk-message-created', {
                  detail: updatedBulkItem
                }));

                // Refresh after navigation to fetch from Firestore
                setSafeTimeout(() => {
                  if (isMountedRef.current) refresh();
                }, 2000);

                // Real-time status polling: check Semaphore for actual delivery status
                const allMessageIds = results.flatMap(r => r.messageIds || []);
                if (allMessageIds.length > 0) {
                  let attempts = 0;
                  const maxAttempts = 30; // ~60s total
                  const pollStatus = async () => {
                    if (!isMountedRef.current) return;
                    attempts++;
                    const statusMap = await checkMessageStatus(allMessageIds);
                    if (!isMountedRef.current) return;
                    const allResolved = allMessageIds.every(id => {
                      const s = (statusMap[id] || '').toLowerCase();
                      return s === 'sent' || s === 'failed' || s === 'success';
                    });

                    // Refresh messages to show DB-persisted status
                    if (allResolved || attempts >= maxAttempts) {
                      if (isMountedRef.current) refresh();
                    } else {
                      setSafeTimeout(pollStatus, 2000);
                    }
                  };
                  setSafeTimeout(pollStatus, 2000);
                }
              } else {
                guardedToast("error", "Failed to send bulk messages");
                const updatedBulkItem: BulkMessageHistoryItem = {
                  ...bulkItemForNav,
                  status: 'failed'
                };
                saveBulkMessage(updatedBulkItem);
                window.dispatchEvent(new CustomEvent('nola-bulk-message-created', {
                  detail: updatedBulkItem
                }));
              }
            } catch (err) {
              if (groupTempId) {
                updateMessageStatus(groupTempId, 'failed');
              }
              const updatedBulkItem: BulkMessageHistoryItem = {
                ...bulkItemForNav,
                status: 'failed'
              };
              saveBulkMessage(updatedBulkItem);
              window.dispatchEvent(new CustomEvent('nola-bulk-message-created', {
                detail: updatedBulkItem
              }));
              if (!toastShown) {
                showToast("error", err instanceof Error ? err.message : "Failed to send bulk messages");
              }
            } finally {
              if (isMountedRef.current) {
                setSendingProgress(null);
              }
            }
          })();
        }
        // toast already shown via guardedToast() in each branch above
      } catch (error) {
        if (!toastShown) {
          showToast("error", error instanceof Error ? error.message : "Failed to send message");
        }
      }
    })();
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
    const draftMessage = (messageInputRef.current?.value ?? message).trim();
    if (!draftMessage || !toggleEnabled) return true;
    // Allow sending when viewing an existing conversation
    if (activeBulkMessage || activeContact) {
      return getActiveRecipients().map(normalizeRecipient).filter(contact => contact.phone).length === 0;
    }
    // For new message flow, need recipients
    if (getActiveRecipients().map(normalizeRecipient).filter(contact => contact.phone).length === 0) return true;
    return false;
  };

  const getSendDisabledReason = (): string => {
    const draftMessage = (messageInputRef.current?.value ?? message).trim();
    if (!toggleEnabled) return "SMS sending is disabled for this account";
    if (!draftMessage) return "Enter a message to send";
    // Allow sending when viewing an existing conversation
    if (activeBulkMessage || activeContact) {
      return getActiveRecipients().map(normalizeRecipient).filter(contact => contact.phone).length === 0
        ? "Enter a phone number or select a contact"
        : "";
    }
    // For new message flow, need recipients
    if (getActiveRecipients().map(normalizeRecipient).filter(contact => contact.phone).length === 0) return "Select at least one recipient";
    return "";
  };

  const copyToClipboard = async (label: string, value?: string | null) => {
    const text = (value || "").trim();
    if (!text) {
      showToast("error", `No ${label.toLowerCase()} to copy.`);
      return;
    }

    try {
      await navigator.clipboard.writeText(text);
      showToast("success", `${label} copied.`);
    } catch {
      showToast("error", `Could not copy ${label.toLowerCase()}.`);
    }
  };

  const retryFailedMessage = async (msg: Message, recipientOverride?: string) => {
    const recipient = normalizePHNumber(recipientOverride || msg.number || historyPhoneNumber || "");
    const text = (msg.text || msg.message || "").trim();
    if (!recipient || !text) {
      showToast("error", "This failed message is missing a valid recipient or message text.");
      return;
    }

    const tempId = addOptimisticMessage(text, senderName, conversationId);
    const result = await sendSms(recipient, text, senderName, msg.batch_id, undefined, msg.recipient_key, undefined, selectedTagsToApply);
    if (result.success) {
      updateMessageStatus(tempId, "sending", result.messageIds?.[0]);
      showToast("success", result.message || "Retry queued for delivery.");
      window.dispatchEvent(new Event("sms-sent"));
      setSafeTimeout(() => {
        if (isMountedRef.current) refresh();
      }, 1500);
    } else {
      updateMessageStatus(tempId, "failed", undefined, result.message || "Retry failed");
      showToast("error", result.message || "Retry failed.");
    }
  };

  const renderMessageQuickActions = (msg: Message, recipient?: string) => {
    if (msg.status !== "failed") return null;

    return (
      <div className="mt-2 flex flex-wrap items-center justify-end gap-1.5">
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            retryFailedMessage(msg, recipient);
          }}
          className="inline-flex items-center gap-1 rounded-full bg-red-50 dark:bg-red-500/10 px-2 py-1 text-[10px] font-black text-red-600 dark:text-red-300 ring-1 ring-red-200/70 dark:ring-red-500/20 hover:bg-red-100 dark:hover:bg-red-500/15"
        >
          <FiRefreshCw className="h-3 w-3" /> Retry
        </button>
      </div>
    );
  };

  const showMessageDetails = (msg: Message) => {
    setMessageDetails({
      kind: "message",
      message: msg,
      recipient: historyPhoneNumber,
      conversationId,
    });
  };

  const toggleMessageDetails = (id: string, isExpanded: boolean, shouldRevealBottom = false) => {
    setExpandedMessageId(isExpanded ? null : id);
    if (!isExpanded && shouldRevealBottom) {
      setSafeTimeout(scrollToBottom, 80);
    }
  };

  const showBulkDetails = (
    id: string,
    text: string,
    timestamp: Date,
    rows: Message[],
    stats: { sent: number; sending: number; failed: number; total: number }
  ) => {
    setMessageDetails({
      kind: "bulk",
      id,
      text,
      timestamp,
      rows,
      stats,
      conversationId,
    });
  };

  const composeWidthClass = "max-w-4xl mx-auto w-full";
  const dateSeparatorClass = "px-3 py-1.5 rounded-full bg-white/[0.85] dark:bg-white/[0.07] border border-[#dce4ee] dark:border-white/10 text-[11px] font-bold text-[#667085] dark:text-[#b8bdc7]";
  const messageContainerClass = "max-w-[78%] sm:max-w-[620px] flex flex-col group mb-0.5 cursor-pointer";
  const outboundBubbleClass = "bg-gradient-to-r from-[#1d6bd4] via-[#2b83fa] to-[#2563eb] text-white px-4 py-3 ring-1 ring-white/25 transition-colors shadow-sm";
  const inboundBubbleClass = "bg-[#f0f3f8] dark:bg-[#1c1e24] text-[#111111] dark:text-[#ececf1] px-4 py-3 border border-[#dce4ee] dark:border-white/10 shadow-sm transition-colors";
  const bubbleOptionsButtonClass = "absolute -left-9 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full border border-[#d6e0eb] dark:border-white/10 bg-white/[0.9] dark:bg-[#17191f]/90 text-[#667085] dark:text-[#a7adba] opacity-0 pointer-events-auto transition-all group-hover:opacity-100 hover:opacity-100 focus-visible:opacity-100 hover:text-[#1d6bd4] dark:hover:text-[#8bbcff] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#2b83fa]/30";
  const inboundBubbleOptionsButtonClass = "absolute -right-9 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full border border-[#d6e0eb] dark:border-white/10 bg-white/[0.9] dark:bg-[#17191f]/90 text-[#667085] dark:text-[#a7adba] opacity-0 pointer-events-auto transition-all group-hover:opacity-100 hover:opacity-100 focus-visible:opacity-100 hover:text-[#1d6bd4] dark:hover:text-[#8bbcff] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#2b83fa]/30";

  return (
    <div className="flex flex-col h-full bg-[#f8fafc] dark:bg-[#0c0d10] relative overflow-hidden transition-colors duration-300">
      {/* 1. Header & Recipient Area (Sticky) */}
      <div className="flex-shrink-0 z-30 rounded-b-[26px] bg-gradient-to-r from-[#1d6bd4] via-[#2b83fa] to-[#2563eb] border-b border-blue-200/30 shadow-[0_10px_28px_rgba(37,99,235,0.16)]">
        {activePhoneNumber ? (
          /* Chat Header for specific contact - Direct Messages */
          (<div className={`${composeWidthClass} px-4 sm:px-6 py-3.5 flex flex-row items-center justify-between gap-3`}>
            <div className="flex items-center gap-2 sm:gap-4 min-w-0 flex-1">
              <button
                onClick={onToggleMobileMenu}
                className="md:hidden p-2 rounded-xl text-white/80 hover:text-white hover:bg-white/10 transition-colors"
                aria-label="Toggle sidebar"
              >
                <FiMenu className="h-5 w-5" />
              </button>
              <div className="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/20 flex-shrink-0 flex items-center justify-center text-white font-black text-base sm:text-lg shadow-lg shadow-blue-950/20 ring-1 ring-white/35">
                {(getResolvedContactName(activeContact || selectedContacts[0]) || "?").charAt(0).toUpperCase()}
              </div>
              <div className="flex flex-col min-w-0">
                <h2 className="max-w-full truncate text-[15px] font-black leading-tight tracking-tight text-white sm:text-[17px]" title={toProperCase(getResolvedContactName(activeContact || selectedContacts[0]))}>
                  {toProperCase(getResolvedContactName(activeContact || selectedContacts[0]))}
                </h2>
                <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                  <span className="text-[12px] sm:text-[13px] text-white/75 font-semibold truncate">
                    {activePhoneNumber}
                  </span>
                  <button
                    type="button"
                    onClick={() => copyToClipboard("Phone number", activePhoneNumber)}
                    className="inline-flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-white/15 text-white/85 ring-1 ring-white/20 hover:bg-white/25"
                    title="Copy phone number"
                    aria-label="Copy phone number"
                  >
                    <FiCopy className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            </div>
            {/* Sender + Credits */}
            <div className="flex items-center gap-2 group">
              <div className="flex-shrink-0 order-2 sm:order-1">
                <CreditBadge tone="onBlue" />
              </div>
              <div className="flex-shrink-0 order-1 sm:order-2">
                <SenderSelector
                  value={senderName}
                  onChange={handleSenderChange}
                  tone="onBlue"
                  onRequestSettings={onRequestSettings}
                  approvedSenderId={approvedSenderId}
                />
              </div>
            </div>
          </div>)
        ) : activeBulkMessage ? (
          /* Bulk Message Conversation Header - Clean view like direct messages */
          (<div className={`${composeWidthClass} px-4 sm:px-6 py-3.5 flex flex-row items-center justify-between gap-3`}>
            <div className="flex items-center gap-2 sm:gap-4 min-w-0 flex-1">
              <button
                onClick={onToggleMobileMenu}
                className="md:hidden p-2 rounded-xl text-white/80 hover:text-white hover:bg-white/10 transition-colors"
                aria-label="Toggle sidebar"
              >
                <FiMenu className="h-5 w-5" />
              </button>
              <div className="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/20 flex-shrink-0 flex items-center justify-center text-white font-bold text-base sm:text-lg shadow-lg shadow-blue-950/20 ring-1 ring-white/35">
                <FiUsers className="h-5 w-5 sm:h-6 sm:w-6" />
              </div>
              <div className="flex flex-col min-w-0">
                <h2 className="text-[15px] sm:text-[17px] font-black text-white leading-tight tracking-tight truncate">
                  {(() => {
                    const custom = activeBulkMessage.customName;
                    const looksLikeBatchId = !!custom && (/^batch[-_]\d+$/i.test(custom) || /^batch[-_]/i.test(custom));
                    const usableCustom = custom && !looksLikeBatchId ? custom : undefined;

                    if (usableCustom) return usableCustom;

                    const numbers = activeBulkMessage.recipientNumbers || [];
                    const names = (numbers.length > 0 ? numbers : (activeBulkMessage.recipientNames || []))
                      .map(numOrName => {
                        return resolveContactNameByPhone(contactMap, numOrName) || numOrName;
                      }).filter(Boolean);
                    if (names.length > 0) {
                      return (
                        names.slice(0, 3).join(", ") +
                        (names.length > 3 ? ` +${names.length - 3}` : "")
                      );
                    }

                    return `${activeBulkMessage.recipientCount} recipients`;
                  })()}
                </h2>
                <span className="text-[12px] sm:text-[13px] text-white/75 font-semibold truncate">
                  {activeBulkMessage.recipientCount} recipient{activeBulkMessage.recipientCount !== 1 ? 's' : ''}
                </span>
              </div>
            </div>
            {/* Sender and Credits */}
            <div className="flex items-center gap-2 group">
              <div className="flex-shrink-0 order-2 sm:order-1">
                <CreditBadge tone="onBlue" />
              </div>
              <div className="flex-shrink-0 order-1 sm:order-2">
                <SenderSelector
                  value={senderName}
                  onChange={handleSenderChange}
                  tone="onBlue"
                  onRequestSettings={onRequestSettings}
                  approvedSenderId={approvedSenderId}
                />
              </div>
            </div>
          </div>)
        ) : (
          /* New Message / Bulk Header - Styled like individual contact header */
          (<div className={`${composeWidthClass} px-4 sm:px-6 pt-3.5 pb-3`}>
            <div className="flex flex-row items-center justify-between mb-3 gap-3">
              <div className="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
                <button
                  onClick={onToggleMobileMenu}
                  className="md:hidden p-2 rounded-xl text-white/80 hover:text-white hover:bg-white/10 transition-colors"
                  aria-label="Toggle sidebar"
                >
                  <FiMenu className="h-5 w-5" />
                </button>

                {/* Circular Avatar - Standardized Blue for new message / bulk */}
                <div className="w-10 h-10 sm:w-11 sm:h-11 rounded-full flex-shrink-0 flex items-center justify-center text-white font-bold text-base bg-white/20 shadow-lg shadow-blue-950/20 ring-1 ring-white/35">
                  {activeBulkMessage ? (
                    <FiUsers className="h-5 w-5" />
                  ) : (
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.2}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                    </svg>
                  )}
                </div>

                <div className="flex flex-col min-w-0">
                  <h2 className="text-[15px] sm:text-[16px] font-black text-white leading-tight tracking-tight truncate">
                    {composeMode === 'bulk' && bulkSelectedContacts.length > 0 ? (
                      (() => {
                        const count = bulkSelectedContacts.length;
                        if (count === 1) return `To: ${toProperCase(getResolvedContactName(bulkSelectedContacts[0]))}`;
                        if (count === 2) return `To: ${toProperCase(getResolvedContactName(bulkSelectedContacts[0]))}, ${toProperCase(getResolvedContactName(bulkSelectedContacts[1]))}`;
                        return `To: ${toProperCase(getResolvedContactName(bulkSelectedContacts[0]))}, ${toProperCase(getResolvedContactName(bulkSelectedContacts[1]))} +${count - 2} more`;
                      })()
                    ) : (
                      "New Message"
                    )}
                  </h2>
                  <span className="text-[12px] sm:text-[13px] text-white/75 font-semibold truncate">
                    {bulkSelectedContacts.length > 0 ? `${bulkSelectedContacts.length} selected` : 'Select recipients'}
                  </span>
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 sm:gap-3 flex-wrap">
                <div className="order-1 hidden sm:block">
                  <CreditBadge tone="onBlue" />
                </div>

                {/* Compact Toggle - restored for new message flow */}
                <div className="flex p-1 bg-white/15 rounded-2xl border border-white/20 order-2 shadow-inner">
                  <button
                    onClick={() => setComposeMode("single")}
                    className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-1.5 sm:py-1 text-[10px] sm:text-[11px] font-bold uppercase tracking-wider rounded-lg transition-all duration-200 ${composeMode === "single"
                      ? "bg-white text-[#1d6bd4] shadow-sm shadow-blue-950/10"
                      : "text-white/75 hover:text-white"
                      }`}
                  >
                    <FiUser className="h-3 w-3 sm:h-3.5 sm:w-3.5" />
                    Single
                  </button>
                  <button
                    onClick={() => setComposeMode("bulk")}
                    className={`flex-1 flex items-center justify-center gap-1.5 px-3 py-1.5 sm:py-1 text-[10px] sm:text-[11px] font-bold uppercase tracking-wider rounded-lg transition-all duration-200 ${composeMode === "bulk"
                      ? "bg-white text-[#1d6bd4] shadow-sm shadow-blue-950/10"
                      : "text-white/75 hover:text-white"
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
                  <span className="text-[14px] font-semibold text-white/75 whitespace-nowrap">From:</span>
                  <div className="flex-1">
                    <SenderSelector
                      value={senderName}
                      onChange={handleSenderChange}
                      align="left"
                      tone="onBlue"
                      onRequestSettings={onRequestSettings}
                      approvedSenderId={approvedSenderId}
                    />
                  </div>
                </div>
                <div className="flex-shrink-0 scale-90 origin-right">
                  <CreditBadge tone="onBlue" />
                </div>
              </div>
            </div>
            {/* Recipient Line */}
            <div className="flex items-start gap-2 rounded-2xl border border-[#dce4ee] bg-white/[0.65] px-3 py-2 shadow-sm dark:border-white/10 dark:bg-white/[0.07] sm:items-center sm:gap-3 sm:py-1.5">
              <span className="text-[12px] font-black text-[#667085] dark:text-white/70 whitespace-nowrap uppercase">To</span>

              <div className="flex min-h-[40px] min-w-0 flex-1 items-center">
                <div className="relative w-full" ref={dropdownRef}>
                  <div
                    className="flex max-h-[112px] cursor-text flex-wrap items-center gap-2 overflow-y-auto py-1 pr-1 custom-scrollbar sm:max-h-[88px]"
                    onClick={() => setIsPickerOpen(true)}
                  >
                    {bulkSelectedContacts.map(contact => {
                      const isFocused = recipientKeyFocus === contact.phone;
                      const isBulkActive = !!activeBulkMessage;

                      return (
                        <span
                          key={contact.id}
                          className={`flex min-w-0 max-w-[152px] flex-shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-bold shadow-sm ring-1 transition-all sm:max-w-[220px] sm:text-[13px]
                            ${isFocused
                              ? 'bg-[#1d6bd4] text-white ring-white/45 shadow-md scale-105'
                              : isBulkActive
                                ? 'bg-white text-[#155fbe] ring-[#9cc7ff]/70 hover:bg-[#eef6ff] cursor-pointer dark:bg-white/95 dark:text-[#155fbe]'
                                : 'bg-white text-[#155fbe] ring-[#9cc7ff]/70 dark:bg-white/95 dark:text-[#155fbe]'
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
                          <span className="min-w-0 truncate">{toProperCase(getResolvedContactName(contact))}</span>
                          {!isBulkActive && (
                            <button
                              onClick={(e) => handleRemoveBulkContact(contact.id, e)}
                              className="flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full text-red-500 transition-colors hover:bg-red-50 hover:text-red-600"
                              aria-label={`Remove ${getResolvedContactName(contact)}`}
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
                      className="min-w-[96px] flex-1 border-none bg-transparent py-1 text-[14px] font-medium text-[#111111] placeholder-[#667085]/60 focus:outline-none dark:text-[#ececf1] dark:placeholder-white/50 sm:min-w-[120px] sm:text-[15px]"
                    />
                  </div>

                  {/* Dropdown */}
                  {isPickerOpen && (
                    <div className="absolute left-0 right-0 top-full z-40 mt-1 max-h-[52vh] overflow-y-auto rounded-2xl border border-gray-200/80 bg-white/95 py-2 shadow-2xl backdrop-blur-2xl transition-all scale-up-center custom-scrollbar dark:border-white/10 dark:bg-[#1a1b1e]/95 sm:max-h-64">
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
                            const hasPhone = !!contact.phone;
                            return (
                              <div
                                key={contact.id}
                                onClick={() => {
                                  if (!hasPhone) return;
                                  isSelected ? handleRemoveBulkContact(contact.id) : handleSelectBulkContact(contact);
                                }}
                                title={!hasPhone ? 'This contact has no phone number and cannot receive messages' : ''}
                                className={`px-3 py-2.5 rounded-xl flex items-center justify-between transition-all duration-150 ${!hasPhone
                                  ? 'opacity-50 cursor-not-allowed'
                                  : isSelected
                                    ? 'cursor-pointer bg-[#2b83fa]/5 dark:bg-[#2b83fa]/10'
                                    : 'cursor-pointer hover:bg-gray-100/50 dark:hover:bg-white/5'
                                  }`}
                              >
                                <div className="flex items-center gap-3 min-w-0">
                                  <div className={`w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold ${isSelected ? 'bg-[#2b83fa] text-white' : !hasPhone ? 'bg-gray-100 dark:bg-white/5 text-gray-400' : 'bg-gray-200 dark:bg-white/10 text-gray-600 dark:text-gray-400'}`}>
                                    {(() => {
                                      const resolvedName = getResolvedContactName(contact);
                                      const parts = resolvedName.split(' ').filter(p => p.length > 0);
                                      const first = parts[0]?.charAt(0) || '';
                                      const last = parts.length > 1 ? parts[parts.length - 1]?.charAt(0) || '' : '';
                                      return (first + last).toUpperCase() || '?';
                                    })()}
                                  </div>
                                  <div className="min-w-0">
                                    <p className={`font-semibold text-[13px] truncate ${!hasPhone ? 'text-gray-400 dark:text-gray-500' : 'text-[#111111] dark:text-[#ececf1]'}`}>{toProperCase(getResolvedContactName(contact))}</p>
                                    {hasPhone ? (
                                      <p className="text-[11px] text-gray-500 truncate">{contact.phone}</p>
                                    ) : (
                                      <p className="text-[11px] text-amber-500 dark:text-amber-400 font-semibold flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                          <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                        </svg>
                                        No phone number
                                      </p>
                                    )}
                                  </div>
                                </div>
                                {isSelected && hasPhone && (
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

              {/* Separator + From label + Sender Selector — right of To: row (Desktop only) */}
              <div className="flex-shrink-0 hidden sm:flex items-center gap-2.5">
                <div className="w-px h-4 bg-[#dce4ee] dark:bg-white/20 flex-shrink-0" />
                <span className="text-[12px] font-black text-[#667085] dark:text-white/70 whitespace-nowrap uppercase">From</span>
                <SenderSelector
                  value={senderName}
                  onChange={handleSenderChange}
                  label=""
                  size="sm"
                  tone={darkMode ? "onBlue" : "default"}
                  onRequestSettings={onRequestSettings}
                  approvedSenderId={approvedSenderId}
                />
              </div>
            </div>
          </div>)
        )}
      </div>
      {/* 2. Message History Area */}
      <div
        ref={msgAreaRef}
        className="flex-1 overflow-y-auto px-4 sm:px-6 lg:px-8 pt-6 pb-2 space-y-1 flex flex-col custom-scrollbar"
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
        <div className={`${composeWidthClass} min-h-full flex flex-col pb-1`}>
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
                {historyPhoneNumber && (
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
            <MessageHistorySkeleton />
          ) : !useRawLogView && !shouldUseRawLogFallback && conversationMessages.length === 0 ? (
            <div className="flex-1 flex flex-col items-center justify-center">
              {!lottieError ? (
                <DotLottieReact
                  src="https://lottie.host/8bff6661-62db-4473-adb8-7eced34f3649/mii3gOOlir.lottie"
                  loop
                  autoplay
                  className="w-36 h-36 md:w-48 md:h-48 mb-1"
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
                {historyPhoneNumber ? "No history yet" : (composeMode === "bulk" ? "New Broadcast" : "New Message")}
              </h3>
              <p className="text-[14px] text-gray-500 dark:text-gray-400 text-center max-w-xs leading-relaxed">
                {historyPhoneNumber
                  ? "Start a professional conversation by typing your first message below."
                  : (composeMode === "bulk" ? "Select contacts to send a synchronized update across your network." : "Type a message below to start a new professional conversation.")}
              </p>
            </div>
          ) : (useRawLogView || shouldUseRawLogFallback) ? (
            <div className="space-y-1 max-w-4xl mx-auto w-full mt-auto">
              <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className="px-2 py-0.5 rounded-full bg-gray-100 dark:bg-white/10 text-[11px] font-semibold text-gray-600 dark:text-gray-300">
                    {shouldUseRawLogFallback ? "Synced from outbound log" : "Raw outbound log view"}
                  </span>
                  {phoneLogLoading && (
                    <span className="text-[11px] text-gray-400 flex items-center gap-1">
                      <FiLoader className="h-3 w-3 animate-spin" /> Loading…
                    </span>
                  )}
                </div>
                {useRawLogView && (
                  <button
                    onClick={() => {
                      setUseRawLogView(false);
                    }}
                    className="text-[11px] font-semibold text-[#2b83fa] hover:text-[#1d6bd4]"
                  >
                    Back to conversation view
                  </button>
                )}
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
                phoneLogMessages.map((msg, index) => {
                  const isInbound = msg.direction === 'inbound';
                  const isExpanded = expandedMessageId === msg.id;
                  const prevMsg = phoneLogMessages[index - 1];
                  const nextMsg = phoneLogMessages[index + 1];

                  const msgDateStr = new Date(msg.timestamp).toDateString();
                  const showDateSeparator =
                    !prevMsg || new Date(prevMsg.timestamp).toDateString() !== msgDateStr;

                  const isPrevSameGroup = !showDateSeparator && prevMsg && (prevMsg.direction === msg.direction || (!prevMsg.direction && !isInbound));
                  const isNextSameGroup =
                    nextMsg && (nextMsg.direction === msg.direction || (!nextMsg.direction && !isInbound)) && new Date(nextMsg.timestamp).toDateString() === msgDateStr;

                  let roundingClasses = "rounded-[20px]";
                  if (isInbound) {
                    if (isPrevSameGroup && isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-tl-[4px] rounded-bl-[4px]";
                    } else if (isPrevSameGroup && !isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-tl-[4px]";
                    } else if (!isPrevSameGroup && isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-bl-[4px]";
                    }
                  } else {
                    if (isPrevSameGroup && isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-tr-[4px] rounded-br-[4px]";
                    } else if (isPrevSameGroup && !isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-tr-[4px]";
                    } else if (!isPrevSameGroup && isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-br-[4px]";
                    }
                  }

                  return (
                    <div key={msg.id} className={`w-full flex flex-col ${isInbound ? 'items-start' : 'items-end'}`}>
                      {showDateSeparator && (
                        <div className="w-full flex items-center justify-center my-5">
                          <span className={dateSeparatorClass}>
                            {new Date(msg.timestamp).toLocaleDateString([], {
                              weekday: "long",
                              month: "short",
                              day: "numeric",
                            })}
                          </span>
                        </div>
                      )}
                      <div
                        className={`${messageContainerClass} ${isInbound ? 'items-start' : 'items-end'}`}
                        onClick={() => toggleMessageDetails(msg.id, isExpanded, index === phoneLogMessages.length - 1)}
                      >
                        <div className={`relative flex items-end ${isInbound ? 'justify-start' : 'justify-end'}`}>
                          {!isInbound && (
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                showMessageDetails(msg);
                              }}
                              className={bubbleOptionsButtonClass}
                              aria-label="Message options"
                              title="Message details"
                            >
                              <FiMoreHorizontal className="h-4 w-4" />
                            </button>
                          )}
                          <div className={`${isInbound ? inboundBubbleClass : outboundBubbleClass} ${roundingClasses}`}>
                            <p className="text-[14.5px] leading-relaxed whitespace-pre-wrap">
                              {msg.text}
                            </p>
                          </div>
                          {isInbound && (
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                showMessageDetails(msg);
                              }}
                              className={inboundBubbleOptionsButtonClass}
                              aria-label="Message options"
                              title="Message details"
                            >
                              <FiMoreHorizontal className="h-4 w-4" />
                            </button>
                          )}
                        </div>
                        <div
                          className={`overflow-hidden transition-all duration-300 ease-in-out ${
                            isExpanded ? "max-h-20 opacity-100 mt-1 mb-1 px-1" : "max-h-0 opacity-0"
                          }`}
                        >
                          <div className={`flex items-center gap-2 ${isInbound ? 'justify-start' : 'justify-end'}`}>
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
                            {isInbound ? (
                              <span className="text-[10px] font-bold text-blue-500 dark:text-blue-400 capitalize tracking-wider flex items-center gap-0.5">
                                <FiCheck className="inline mb-0.5 mr-1" size={10} />
                                Received
                              </span>
                            ) : (() => {
                              const badge = getRetryBadgeInfo(msg);
                              const toneClass =
                                badge.tone === 'green'
                                  ? "text-green-500"
                                  : badge.tone === 'red'
                                  ? "text-red-500"
                                  : badge.tone === 'amber' || badge.tone === 'amber_pulse'
                                  ? "text-amber-500 dark:text-amber-400"
                                  : "text-gray-400";

                              return (
                                <span
                                  className={`text-[10px] font-bold capitalize tracking-wider flex items-center gap-0.5 ${toneClass}`}
                                  title={badge.tooltip || (msg.errorReason ? `Failure reason: ${msg.errorReason}` : undefined)}
                                >
                                  {badge.tone === 'green' ? (
                                    <FiCheck className="inline mb-0.5 mr-1" size={10} />
                                  ) : badge.tone === 'red' ? (
                                    <FiAlertCircle size={10} className="inline mb-0.5 mr-1" />
                                  ) : badge.tone === 'amber_pulse' ? (
                                    <FiLoader className="animate-spin inline mb-0.5 mr-1" size={10} />
                                  ) : badge.tone === 'amber' ? (
                                    <FiRefreshCw className="inline mb-0.5 mr-1 animate-spin" size={10} />
                                  ) : (
                                    <FiLoader className="animate-spin inline mb-0.5 mr-1" size={10} />
                                  )}
                                  {badge.label}
                                </span>
                              );
                            })()}
                          </div>
                          {renderMessageQuickActions(msg, msg.number || historyPhoneNumber)}
                        </div>
                        {!isInbound && !isExpanded && (msg.status === "sending" || (msg.retry_status && ['pending_retry', 'processing'].includes(msg.retry_status))) && (
                          <div
                            className="mt-1 flex items-center justify-end px-1"
                            title={msg.retry_status === 'pending_retry' ? 'Retrying in background...' : msg.retry_status === 'processing' ? 'Retrying now...' : 'Sending...'}
                          >
                            <FiLoader className={`h-3 w-3 animate-spin ${msg.retry_status ? 'text-amber-500' : 'text-gray-400 dark:text-gray-500'}`} />
                          </div>
                        )}
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          ) : (
            <div className="space-y-0.5 mt-auto w-full">
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
                    const rowText = (row.text || row.message || "").toString();
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

                  // Render groups oldest → newest so that scrollToBottom always
                  // reveals the most recent message at the bottom (matches chat UX).
                  const renderGroups = groups.slice().sort((a, b) => a.timestamp.getTime() - b.timestamp.getTime());

                  return renderGroups.map((grp, index) => {
                    const date = grp.timestamp;
                    const prev = renderGroups[index - 1];
                    const next = renderGroups[index + 1];

                    const msgDateStr = date.toDateString();
                    const prevDateStr = prev ? prev.timestamp.toDateString() : null;
                    const showDateSeparator = !prevDateStr || prevDateStr !== msgDateStr;

                    const campaignStats = {
                      sent: grp.rows.filter(m => (m.status || '').toLowerCase() === 'sent').length,
                      sending: grp.rows.filter(m => (m.status || '').toLowerCase() === 'sending').length,
                      failed: grp.rows.filter(m => ['failed', 'error'].includes((m.status || '').toLowerCase())).length,
                      retrying: grp.rows.filter(m => m.retry_status && ['pending_retry', 'processing'].includes(m.retry_status)).length,
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
                          <div className="w-full flex items-center justify-center my-5">
                            <span className={dateSeparatorClass}>
                              {date.toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' })}
                            </span>
                          </div>
                        )}

                        <div
                          className={messageContainerClass}
                          onClick={() => toggleMessageDetails(grp.id, isExpanded, index === renderGroups.length - 1)}
                        >
                          <div className="relative flex items-end justify-end">
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                showBulkDetails(grp.id, grp.messageText, grp.timestamp, grp.rows, campaignStats);
                              }}
                              className={bubbleOptionsButtonClass}
                              aria-label="Message options"
                              title="Message details"
                            >
                              <FiMoreHorizontal className="h-4 w-4" />
                            </button>
                            <div className={`${outboundBubbleClass} ${roundingClasses}`}>
                              <div className="text-[14.5px] whitespace-pre-wrap leading-relaxed">
                                {grp.messageText}
                              </div>
                            </div>
                          </div>

                          <div className={`overflow-hidden transition-all duration-300 ease-in-out ${isExpanded ? 'max-h-40 opacity-100 mt-1 mb-1 px-1' : 'max-h-0 opacity-0'}`}>
                            <div className="flex items-center gap-2">
                              <span className="text-[10px] font-semibold text-gray-500 dark:text-gray-400">
                                {grp.rows[0]?.senderName || 'NOLASMSPro'}
                              </span>
                              <span className="text-[10px] text-gray-400">•</span>
                              <span className="text-[10px] text-gray-500 dark:text-gray-400 font-medium">
                                {date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                              </span>
                              <span className="text-[10px] text-gray-400">•</span>
                              {campaignStats.retrying > 0 ? (
                                <span className="text-[10px] font-bold text-amber-500 dark:text-amber-400 capitalize tracking-wider flex items-center gap-0.5" title="Background delivery retries in progress">
                                  <FiRefreshCw className="animate-spin inline mb-0.5 mr-1" size={10} />
                                  Retrying... {campaignStats.retrying > 1 ? `(${campaignStats.retrying}/${campaignStats.total})` : ''}
                                </span>
                              ) : campaignStats.sending > 0 ? (
                                <span className="text-[10px] font-bold text-gray-400 capitalize tracking-wider flex items-center gap-0.5">
                                  <FiLoader className="animate-spin inline mb-0.5 mr-1" size={10} />
                                  Sending...
                                </span>
                              ) : campaignStats.failed > 0 ? (
                                <span className="text-[10px] font-bold text-red-500 capitalize tracking-wider flex items-center gap-0.5">
                                  <FiAlertCircle size={10} className="inline mb-0.5 mr-1 animate-pulse" />
                                  Failed {campaignStats.failed > 0 && `(${campaignStats.failed}/${campaignStats.total} failed)`}
                                </span>
                              ) : (
                                <span className="text-[10px] font-bold text-green-500 capitalize tracking-wider flex items-center gap-0.5">
                                  <FiCheck className="inline mb-0.5 mr-1" size={10} />
                                  Sent
                                </span>
                              )}
                            </div>
                          </div>

                          {!isExpanded && (campaignStats.sending > 0 || campaignStats.retrying > 0) && (
                            <div
                              className="mt-1 flex items-center justify-end px-1"
                              title={campaignStats.retrying > 0 ? 'Retrying in background...' : 'Sending...'}
                            >
                              <FiLoader className={`h-3 w-3 animate-spin ${campaignStats.retrying > 0 ? 'text-amber-500' : 'text-gray-400 dark:text-gray-500'}`} />
                            </div>
                          )}
                        </div>
                      </div>
                    );
                  });
                }

                const sourceMessages = conversationMessages;
                return sourceMessages.map((msg, index) => {
                  const isInbound = msg.direction === 'inbound';
                  const isExpanded = expandedMessageId === msg.id;
                  const prevMsg = sourceMessages[index - 1];
                  const nextMsg = sourceMessages[index + 1];

                  const msgDateStr = new Date(msg.timestamp).toDateString();
                  const showDateSeparator = !prevMsg || new Date(prevMsg.timestamp).toDateString() !== msgDateStr;

                  const isPrevSameGroup = !showDateSeparator && prevMsg && (prevMsg.direction === msg.direction || (!prevMsg.direction && !isInbound));
                  const isNextSameGroup = nextMsg && (nextMsg.direction === msg.direction || (!nextMsg.direction && !isInbound)) && new Date(nextMsg.timestamp).toDateString() === msgDateStr;

                  let roundingClasses = "rounded-[20px]";
                  if (isInbound) {
                    if (isPrevSameGroup && isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-tl-[4px] rounded-bl-[4px]";
                    } else if (isPrevSameGroup && !isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-tl-[4px]";
                    } else if (!isPrevSameGroup && isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-bl-[4px]";
                    }
                  } else {
                    if (isPrevSameGroup && isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-tr-[4px] rounded-br-[4px]";
                    } else if (isPrevSameGroup && !isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-tr-[4px]";
                    } else if (!isPrevSameGroup && isNextSameGroup) {
                      roundingClasses = "rounded-[20px] rounded-br-[4px]";
                    }
                  }

                  return (
                    <div key={msg.id} className={`w-full flex flex-col ${isInbound ? 'items-start' : 'items-end'}`}>
                      {showDateSeparator && (
                        <div className="w-full flex items-center justify-center my-5">
                          <span className={dateSeparatorClass}>
                            {new Date(msg.timestamp).toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' })}
                          </span>
                        </div>
                      )}
                      <div
                        className={`${messageContainerClass} ${isInbound ? 'items-start' : 'items-end'}`}
                        onClick={() => toggleMessageDetails(msg.id, isExpanded, index === sourceMessages.length - 1)}
                      >
                        <div className={`relative flex items-end ${isInbound ? 'justify-start' : 'justify-end'}`}>
                          {!isInbound && (
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                showMessageDetails(msg);
                              }}
                              className={bubbleOptionsButtonClass}
                              aria-label="Message options"
                              title="Message details"
                            >
                              <FiMoreHorizontal className="h-4 w-4" />
                            </button>
                          )}
                          <div className={`${isInbound ? inboundBubbleClass : outboundBubbleClass} ${roundingClasses}`}>
                            <p className="text-[14.5px] leading-relaxed whitespace-pre-wrap">{msg.text}</p>
                          </div>
                          {isInbound && (
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                showMessageDetails(msg);
                              }}
                              className={inboundBubbleOptionsButtonClass}
                              aria-label="Message options"
                              title="Message details"
                            >
                              <FiMoreHorizontal className="h-4 w-4" />
                            </button>
                          )}
                        </div>

                        <div className={`overflow-hidden transition-all duration-300 ease-in-out ${isExpanded ? 'max-h-40 opacity-100 mt-1 mb-1 px-1' : 'max-h-0 opacity-0'}`}>
                          <div className={`flex items-center gap-2 ${isInbound ? 'justify-start' : 'justify-end'}`}>
                            <span className="text-[10px] font-semibold text-gray-500 dark:text-gray-400">
                              {msg.senderName}
                            </span>
                            <span className="text-[10px] text-gray-400">•</span>
                            <span className="text-[10px] text-gray-500 dark:text-gray-400 font-medium">
                              {new Date(msg.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                            </span>
                            <span className="text-[10px] text-gray-400">•</span>
                            <span className={`text-[10px] font-bold capitalize tracking-wider ${isInbound ? 'text-blue-500 dark:text-blue-400' : msg.status === 'sent' ? 'text-green-500' : msg.status === 'failed' ? 'text-red-500' : 'text-gray-400'}`}>
                              {isInbound ? (
                                <>
                                  <FiCheck className="inline mb-0.5 mr-1" size={10} />
                                  Received
                                </>
                              ) : msg.status === 'sending' ? (
                                <>
                                  <FiLoader className="animate-spin inline mb-0.5 mr-1" size={10} />
                                  Sending...
                                </>
                              ) : msg.status === 'sent' ? (
                                <>
                                  <FiCheck className="inline mb-0.5 mr-1" size={10} />
                                  Sent
                                </>
                              ) : (
                                <>
                                  <FiAlertCircle size={10} className="inline mb-0.5 mr-1 animate-pulse" />
                                  Failed
                                </>
                              )}
                            </span>
                            {!isInbound && (() => {
                              const isSuccess = msg.ghlSyncSuccess ?? msg.ghl_sync_success;
                              const isSkipped = msg.ghlSyncSkipped ?? msg.ghl_sync_skipped;
                              const isQueued = msg.ghlSyncQueued ?? msg.ghl_sync_queued ?? !!(msg.ghlSyncJobId || msg.ghl_sync_job_id);
                              const error = msg.ghlSyncError || msg.ghl_sync_error;
                              if (isSuccess === true) {
                                return (
                                  <>
                                    <span className="text-[10px] text-gray-400">•</span>
                                    <span className="text-[10px] font-bold text-emerald-500">CRM synced</span>
                                  </>
                                );
                              }
                              if (isSkipped === true) {
                                return (
                                  <>
                                    <span className="text-[10px] text-gray-400">•</span>
                                    <span className="text-[10px] font-semibold text-gray-400">CRM sync skipped</span>
                                  </>
                                );
                              }
                              if (error || isSuccess === false) {
                                return (
                                  <>
                                    <span className="text-[10px] text-gray-400">•</span>
                                    <span className="text-[10px] font-bold text-red-500" title={error}>CRM sync failed</span>
                                  </>
                                );
                              }
                              if (isQueued) {
                                return (
                                  <>
                                    <span className="text-[10px] text-gray-400">•</span>
                                    <span className="text-[10px] font-bold text-amber-500">CRM sync pending</span>
                                  </>
                                );
                              }
                              return null;
                            })()}
                            {!isInbound && msg.status === 'failed' && msg.errorReason && (
                              <span className="text-[10px] text-red-400 font-medium truncate max-w-[160px]" title={msg.errorReason}>
                                - {msg.errorReason}
                              </span>
                            )}
                          </div>
                          {renderMessageQuickActions(msg, msg.number || historyPhoneNumber)}
                        </div>
                        {!isInbound && !isExpanded && msg.status === "sending" && (
                          <div className="mt-1 flex items-center justify-end px-1">
                            <FiLoader className="h-3 w-3 animate-spin text-gray-400 dark:text-gray-500" />
                          </div>
                        )}
                      </div>
                    </div>
                  );
                });
              })()}
            </div>
          )}
          <div ref={messagesEndRef} className="h-1 w-full flex-shrink-0" />
        </div>
      </div>
      {/* 3. Floating Input Card Area */}
      <div className="px-4 sm:px-6 lg:px-8 pb-4 pt-1 z-20 relative bg-gradient-to-t from-[#f2f6fb] via-[#f2f6fb]/95 to-transparent dark:from-[#0c0d10] dark:via-[#0c0d10]/95">
        {/* Scroll to bottom floating button */}
        <div
          className={`absolute -top-10 left-1/2 -translate-x-1/2 z-10 transition-all duration-300 ${
            showScrollButton ? "opacity-100 translate-y-0" : "opacity-0 translate-y-8 pointer-events-none"
          }`}
        >
          <button
            onClick={scrollToBottom}
            className="w-8 h-8 bg-white/90 dark:bg-[#191b22]/90 backdrop-blur-md text-[#667085] dark:text-[#a7adba] border border-[#dce4ee] dark:border-white/10 rounded-full shadow-lg flex items-center justify-center hover:text-[#2b83fa] dark:hover:text-[#8bbcff] hover:scale-110 active:scale-95 transition-all"
            aria-label="Scroll to bottom"
          >
            <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 sm:h-4 sm:w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
            </svg>
          </button>
        </div>
        <div className={composeWidthClass}>
          {!toggleEnabled && (
            <div className="mb-3 mx-2 sm:mx-0 px-4 py-3 bg-red-50 dark:bg-red-900/10 border border-red-200/60 dark:border-red-800/40 rounded-2xl flex items-start gap-3 shadow-sm">
              <svg className="w-5 h-5 text-red-500 mt-0.5 flex-shrink-0 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
              <div>
                <h4 className="text-[13px] font-bold text-red-800 dark:text-red-300 tracking-tight">SMS Sending Disabled</h4>
                <p className="text-[12px] text-red-700/90 dark:text-red-400/80 leading-snug mt-0.5">
                  Your agency has restricted SMS sending out of this account. Manual messages and workflow automations are currently blocked. Please contact your agency to re-enable messaging.
                </p>
              </div>
            </div>
          )}
          <div className="bg-white/[0.96] dark:bg-[#17191f]/[0.96] rounded-[24px] border border-[#d8e1ec] dark:border-white/10 shadow-[0_20px_55px_rgba(15,23,42,0.12)] dark:shadow-[0_24px_64px_rgba(0,0,0,0.48)] p-2.5 transition-all focus-within:ring-2 focus-within:ring-[#2b83fa]/25 dark:focus-within:ring-[#2b83fa]/20 relative z-20">
            <div className="flex flex-col">
              <textarea
                ref={messageInputRef}
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Type your message..."
                className="w-full bg-transparent border-none px-4 pt-3.5 pb-2 text-[15px] text-[#101828] dark:text-[#f7f8fb] placeholder-[#98a2b3] dark:placeholder-[#737b89] resize-none focus:outline-none min-h-[58px] max-h-[200px] custom-scrollbar"
                rows={1}
                style={{ height: 'auto', minHeight: '58px' }}
              />

              {/* Contextual hints for new message flow */}
              {isNewMessage && (bulkSelectedContacts.length === 0 || !message) && (
                <div className="flex items-center gap-2 px-4 py-2 flex-wrap">
                  {bulkSelectedContacts.length === 0 && (
                    <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/30 rounded-full px-2.5 py-1">
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                      </svg>
                      {composeMode === "bulk" ? "Select at least one contact to send to" : "Enter a phone number or select a contact"}
                    </span>
                  )}
                  {!message && (
                    <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 rounded-full px-2.5 py-1">
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                      </svg>
                      Type a message to send
                    </span>
                  )}
                </div>
              )}

              <div className="flex flex-col gap-3 border-t border-[#edf1f6] px-3 pb-1.5 pt-2.5 dark:border-white/[0.08] sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                  {/* Templates Button */}
                  <div className="relative" ref={templatePickerRef}>
                    <button
                      onClick={handleTemplateToggle}
                      title="Use Template"
                      className={`px-3 py-1.5 rounded-full transition-all flex items-center gap-1.5 text-[12px] font-bold ${isTemplatesOpen ? "bg-[#eaf3ff] text-[#1d6bd4] dark:bg-white/10 dark:text-[#8bbcff]" : "text-[#7a8492] hover:text-[#1d6bd4] hover:bg-[#eef6ff] dark:text-[#8f96a3] dark:hover:text-[#8bbcff] dark:hover:bg-white/[0.06]"}`}
                    >
                      <FiFileText className="h-4 w-4" />
                      <span className="hidden sm:inline">Use Template</span>
                    </button>

                    {isTemplatesOpen && (
                      <div className="absolute bottom-full left-0 z-50 mb-2 flex max-h-[44vh] w-[min(18rem,calc(100vw-2rem))] flex-col gap-1 overflow-y-auto rounded-2xl border border-gray-200 bg-white p-2 shadow-xl animate-scale-up custom-scrollbar dark:border-white/10 dark:bg-[#1a1b1e] sm:max-h-72 sm:w-72">
                        {templatesLoading ? (
                          <div className="flex items-center justify-center gap-2 px-3 py-5 text-[12px] font-semibold text-gray-500">
                            <FiLoader className="h-4 w-4 animate-spin" />
                            Loading templates...
                          </div>
                        ) : templateOptions.length === 0 ? (
                          <div className="px-3 py-5 text-center">
                            <p className="text-[12px] font-bold text-gray-600 dark:text-gray-300">No templates yet</p>
                            <p className="mt-1 text-[11px] font-medium leading-relaxed text-gray-500 dark:text-gray-400">Create templates from the Templates page, then insert them here.</p>
                          </div>
                        ) : (
                          templateOptions.map(template => (
                            <button
                              key={template.id}
                              onClick={() => handleTemplateSelect(template)}
                              className="w-full px-3 py-2.5 text-left rounded-xl transition-colors hover:bg-gray-100 dark:hover:bg-white/5"
                            >
                              <span className="block truncate text-[13px] font-bold text-[#111111] dark:text-[#ececf1]">{template.name}</span>
                              <span className="block truncate text-[11px] text-[#7a8492] dark:text-[#8f96a3]">{template.content}</span>
                            </button>
                          ))
                        )}
                      </div>
                    )}
                  </div>

                  {/* Custom Values Button */}
                  <div className="relative" ref={customValuesRef}>
                    <button
                      onClick={() => setIsCustomValuesOpen(!isCustomValuesOpen)}
                      title="Custom Values"
                      className={`px-3 py-1.5 rounded-full transition-all flex items-center gap-1.5 text-[12px] font-bold ${isCustomValuesOpen ? "bg-[#eaf3ff] text-[#1d6bd4] dark:bg-white/10 dark:text-[#8bbcff]" : "text-[#7a8492] hover:text-[#1d6bd4] hover:bg-[#eef6ff] dark:text-[#8f96a3] dark:hover:text-[#8bbcff] dark:hover:bg-white/[0.06]"}`}
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                      </svg>
                      <span className="hidden sm:inline">Custom Values</span>
                    </button>

                    {isCustomValuesOpen && (
                      <div className="absolute bottom-full left-0 mb-2 p-2 bg-white dark:bg-[#1a1b1e] rounded-2xl border border-gray-200 dark:border-white/10 shadow-xl flex flex-col gap-1 z-50 animate-scale-up w-48">
                        {customValuesList.map(item => (
                          <button
                            key={item.value}
                            onClick={() => handleCustomValueSelect(item.value)}
                            className="w-full px-3 py-2 text-left text-[13px] font-medium text-[#111111] dark:text-[#ececf1] hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors"
                          >
                            {item.label}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>

                  {/* Tags Button */}
                  <div className="relative" ref={tagsRef}>
                    <button
                      onClick={() => setIsTagsOpen(!isTagsOpen)}
                      title="Apply Tags to Recipients"
                      className={`px-3 py-1.5 rounded-full transition-all flex items-center gap-1.5 text-[12px] font-bold ${selectedTagsToApply.length > 0 || isTagsOpen ? "bg-[#eaf3ff] text-[#1d6bd4] dark:bg-white/10 dark:text-[#8bbcff]" : "text-[#7a8492] hover:text-[#1d6bd4] hover:bg-[#eef6ff] dark:text-[#8f96a3] dark:hover:text-[#8bbcff] dark:hover:bg-white/[0.06]"}`}
                    >
                      <div className="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                        </svg>
                        {selectedTagsToApply.length > 0 && (
                          <span className="absolute -top-1.5 -right-1.5 flex h-3 w-3 items-center justify-center rounded-full bg-[#2b83fa] text-[8px] font-bold text-white border border-white dark:border-[#1a1b1e]">
                            {selectedTagsToApply.length}
                          </span>
                        )}
                      </div>
                      <span className="hidden sm:inline">Apply Tags</span>
                    </button>
                    
                    {isTagsOpen && (
                      <div className="absolute bottom-full left-0 mb-2 p-2 bg-white dark:bg-[#1a1b1e] rounded-2xl border border-gray-200 dark:border-white/10 shadow-xl flex flex-col gap-1 z-50 animate-scale-up w-56 max-h-60 overflow-y-auto custom-scrollbar">
                        {allTags.length === 0 ? (
                          <div className="px-3 py-4 text-center text-[12px] text-gray-500 font-medium">No tags available in your contacts</div>
                        ) : (
                          allTags.map(tag => {
                            const isSelected = selectedTagsToApply.includes(tag);
                            return (
                              <button
                                key={tag}
                                onClick={(e) => {
                                  e.preventDefault();
                                  handleTagToggle(tag);
                                }}
                                className={`w-full px-3 py-2 text-left text-[13px] font-medium rounded-xl transition-colors flex items-center justify-between ${isSelected ? 'bg-[#2b83fa]/10 text-[#2b83fa]' : 'text-[#111111] dark:text-[#ececf1] hover:bg-gray-100 dark:hover:bg-white/5'}`}
                              >
                                <span className="truncate mr-2">{tag}</span>
                                <div className={`w-4 h-4 rounded flex items-center justify-center flex-shrink-0 border ${isSelected ? 'border-[#2b83fa] bg-[#2b83fa]' : 'border-gray-300 dark:border-gray-600'}`}>
                                  {isSelected && <FiCheck className="h-3 w-3 text-white" />}
                                </div>
                              </button>
                            );
                          })
                        )}
                      </div>
                    )}
                  </div>

                  {isNewMessage && composeMode === "bulk" && message.length > 0 && bulkSelectedContacts.length > 0 && (
                    <div className="ml-2 flex items-center gap-1.5 px-2.5 py-1 bg-[#eef6ff] dark:bg-blue-900/20 rounded-full border border-blue-100 dark:border-blue-800/30">
                      <div className="w-1.5 h-1.5 rounded-full bg-[#2b83fa] animate-pulse"></div>
                      <span className="text-[10px] font-bold text-[#2b83fa] uppercase tracking-wide">Est. {totalEstimatedSms} SMS / {estimatedCreditCost} credits</span>
                    </div>
                  )}
                </div>

                <div className="flex w-full items-center justify-between gap-3 sm:w-auto sm:gap-4">
                  <span className="text-[11px] font-semibold tabular-nums text-[#98a2b3] dark:text-[#737b89] sm:text-[12px]">
                    {smsEstimate.lengthUnits} <span className="text-[10px] opacity-70">chars</span> / est. {estimatedCreditCost} <span className="text-[10px] opacity-70">credit{estimatedCreditCost === 1 ? "" : "s"}</span>
                  </span>
                  <button
                    onClick={() => handleSend()}
                    disabled={isSendDisabled()}
                    className={`
                      group flex items-center justify-center gap-2 
                      bg-gradient-to-br from-[#2b83fa] via-[#2563eb] to-[#1d4ed8]
                      text-white transition-all shadow-[0_12px_24px_rgba(37,99,235,0.28)]
                      ${isSendDisabled()
                        ? "opacity-35 cursor-not-allowed shadow-none"
                        : "hover:shadow-[0_16px_32px_rgba(37,99,235,0.36)] hover:-translate-y-0.5 active:scale-95 cursor-pointer"}
                      ${isNewMessage || selectedContacts.length > 0 ? "px-4 py-2.5 rounded-2xl sm:px-5" : "p-3 rounded-full"}
                      sm:px-6 sm:py-3 sm:rounded-2xl
                    `}
                    title={isSendDisabled() ? getSendDisabledReason() : undefined}
                  >
                    {sendingProgress ? (
                      <ShinyText
                        text={`Sending ${sendingProgress.current}/${sendingProgress.total}...`}
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
      {bulkConfirmation && (
        <BulkConfirmationModal
          bulkConfirmation={bulkConfirmation}
          onCancel={() => setBulkConfirmation(null)}
          onConfirm={() => handleSend(true)}
        />
      )}

      {bulkSendSummary && (
        <BulkSendSummaryModal
          bulkSendSummary={bulkSendSummary}
          onClose={() => setBulkSendSummary(null)}
        />
      )}

      {messageDetails && (
        <MessageDetailsModal
          messageDetails={messageDetails}
          onClose={() => setMessageDetails(null)}
          onCopy={copyToClipboard}
        />
      )}
      {/* 4. Toast Overlay - custom, no-blink */}
      <div
        aria-live="polite"
        role="status"
        style={{ pointerEvents: toast.open ? 'auto' : 'none' }}
        className={`
          fixed bottom-6 left-1/2 -translate-x-1/2 z-[9999]
          flex items-center gap-2.5 px-5 py-3 rounded-full
          text-[13px] font-semibold tracking-wide
          shadow-lg backdrop-blur-xl
          border transition-none
          ${toast.severity === 'success'
            ? 'bg-white/80 dark:bg-[#1a1b1e]/90 border-emerald-200/60 dark:border-emerald-500/20 text-emerald-700 dark:text-emerald-300'
            : 'bg-white/80 dark:bg-[#1a1b1e]/90 border-red-200/60 dark:border-red-500/20 text-red-600 dark:text-red-400'
          }
          ${toast.open
            ? 'opacity-100 translate-y-0 transition-all duration-300 ease-out'
            : 'opacity-0 translate-y-3 transition-all duration-200 ease-in pointer-events-none'
          }
        `}
      >
        {/* Icon */}
        {toast.severity === 'success' ? (
          <svg className="w-4 h-4 flex-shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        ) : (
          <svg className="w-4 h-4 flex-shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        )}
        <span>{toast.message}</span>
        {/* Dismiss */}
        <button
          onClick={dismissToast}
          className="ml-1 opacity-50 hover:opacity-100 transition-opacity"
          aria-label="Dismiss"
        >
          <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div >
  );
};
