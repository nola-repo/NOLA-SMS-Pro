import { devLog } from '../utils/devLog';
import { useEffect, useState, useCallback, useMemo, useRef } from "react";
import { createPortal } from "react-dom";
import { fetchContacts } from "../api/contacts";
import { renameConversation, deleteConversation, normalizePHNumber, fetchConversations } from "../api/sms";
import { collection, query, where, onSnapshot } from "firebase/firestore";
import { ensureFirestoreAuth } from "../services/firestoreAuth";
import { db } from "../services/firebaseConfig";
import { deleteContact as deleteContactBackend } from "../api/contacts";
import type { Contact } from "../types/Contact";
import type { BulkMessageHistoryItem, Conversation } from "../types/Sms";
import { renameBulkMessage, deleteBulkMessage, deleteContact as deleteContactLocal, getDeletedContactIds, getBulkMessageHistory } from "../utils/storage";
import { TbLayoutSidebarLeftCollapse, TbLayoutSidebarRightCollapse } from "react-icons/tb";
import { FiBookOpen, FiUsers, FiChevronDown, FiEdit2, FiTrash2, FiMoreVertical, FiHome, FiPlus, FiX, FiLogOut } from "react-icons/fi";
import { logout } from "../services/authService";
import GlareHover from "./GlareHover";
import { extractBatchIdFromGroupConversationId, extractPhoneFromDirectConversationId } from "../utils/conversationId";
import { getAccountSettings } from "../utils/settingsStorage";
import { buildContactNameLookup, isPhoneLike, resolveContactNameByPhone, toProperCase } from "../utils/contactDisplay";
import { useLocationId } from "../context/LocationContext";
import faviconLogo from "../assets/FAV ICON - NOLA SMS PRO.png";

export type ViewTab = 'home' | 'compose' | 'contacts' | 'templates' | 'settings' | 'tickets';

interface SidebarProps {
  activeTab: ViewTab;
  onTabChange: (tab: ViewTab) => void;
  onSelectContact: (contact: Contact) => void;
  activeContactId?: string;
  activeBulkMessageId?: string;
  isCollapsed?: boolean;
  onToggleCollapse?: () => void;
  onSelectBulkMessage?: (message: BulkMessageHistoryItem) => void;
  onCloseMobile?: () => void;
}

const SidebarSkeletonRow: React.FC<{ index: number }> = ({ index }) => (
  <div className="flex items-center gap-3 px-3 py-2 rounded-xl animate-pulse">
    <div className="w-9 h-9 rounded-full bg-[#edf0f3] dark:bg-white/10 flex-shrink-0" />
    <div className="flex-1 min-w-0 space-y-1.5">
      <div
        className="h-3 bg-[#edf0f3] dark:bg-white/10 rounded-full"
        style={{ width: `${index % 2 === 0 ? 68 : 54}%` }}
      />
      <div
        className="h-2 bg-[#f4f6f8] dark:bg-white/5 rounded-full"
        style={{ width: `${index % 3 === 0 ? 92 : 78}%` }}
      />
    </div>
  </div>
);

const SidebarLoadingSection: React.FC<{ rowCount: number; labelWidth: string }> = ({ rowCount, labelWidth }) => (
  <div className="mt-1">
    <div className="flex items-center justify-between gap-2 px-3 py-1.5 mx-1 animate-pulse">
      <div className="flex items-center gap-2 flex-1">
        <div className="w-3.5 h-3.5 rounded-md bg-[#edf0f3] dark:bg-white/10" />
        <div className={`h-3 rounded-full bg-[#edf0f3] dark:bg-white/10 ${labelWidth}`} />
      </div>
      <div className="h-4 w-6 rounded-full bg-[#edf0f3] dark:bg-white/10" />
    </div>
    <div className="flex flex-col gap-0.5 py-0.5">
      {Array.from({ length: rowCount }, (_, index) => (
        <SidebarSkeletonRow key={index} index={index} />
      ))}
    </div>
  </div>
);

const SidebarMessagesSkeleton = () => (
  <>
    <div className="px-3 pt-2 pb-1">
      <div className="h-2.5 w-16 rounded-full bg-[#edf0f3] dark:bg-white/10 animate-pulse" />
    </div>
    <SidebarLoadingSection rowCount={4} labelWidth="w-24" />
    <SidebarLoadingSection rowCount={3} labelWidth="w-20" />
  </>
);

const asText = (value: unknown): string => {
  if (typeof value === 'string') return value;
  if (value === null || value === undefined) return '';
  return String(value);
};

const asMembers = (value: unknown): string[] => {
  if (!Array.isArray(value)) return [];
  return value.map(asText).filter(Boolean);
};

const contactPhoneKey = (phone?: string | null) => (phone || '').replace(/\D/g, '').slice(-10);

const normalizeDirectPhone = (phone?: string | null) => {
  const digits = (phone || '').replace(/\D/g, '');
  if (/^639\d{9}$/.test(digits)) return `0${digits.slice(2)}`;
  if (/^9\d{9}$/.test(digits)) return `0${digits}`;
  if (/^09\d{9}$/.test(digits)) return digits;
  return digits;
};

const matchesContactUpdate = (target: Contact, contact: Contact, previous?: Contact | null) => {
  if (target.id === contact.id || (previous && target.id === previous.id)) return true;
  const targetPhone = contactPhoneKey(target.phone);
  return !!targetPhone && (
    targetPhone === contactPhoneKey(contact.phone) ||
    targetPhone === contactPhoneKey(previous?.phone)
  );
};

export const Sidebar: React.FC<SidebarProps> = ({
  activeTab,
  onTabChange,
  onSelectContact,
  activeContactId,
  activeBulkMessageId,
  isCollapsed = false,
  onToggleCollapse,
  onSelectBulkMessage,
  onCloseMobile
}) => {
  const { locationId } = useLocationId();
  const resolvedLocationId = locationId || getAccountSettings().ghlLocationId || '';
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [localBulkHistory, setLocalBulkHistory] = useState<BulkMessageHistoryItem[]>(() => getBulkMessageHistory());
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [directMessagesExpanded, setDirectMessagesExpanded] = useState(true);
  const [bulkMessagesExpanded, setBulkMessagesExpanded] = useState(true);
  const [editingBulkId, setEditingBulkId] = useState<string | null>(null);
  const [editingBulkName, setEditingBulkName] = useState("");
  const [deletingBulkId, setDeletingBulkId] = useState<string | null>(null);
  const [deletingContact, setDeletingContact] = useState<{ id: string, phone: string } | null>(null);
  const [openMenuId, setOpenMenuId] = useState<string | null>(null);
  const [menuAnchor, setMenuAnchor] = useState<{ x: number, y: number } | null>(null);
  const [loading, setLoading] = useState(true);
  const [showRenameModal, setShowRenameModal] = useState(false);
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);
  const touchStartY = useRef<number>(0);
  const contactsListRef = useRef<HTMLDivElement>(null);
  const contactsRef = useRef<Contact[]>([]);
  // Track last_message per conversation to detect new messages and notify the Composer
  const lastMessageTracker = useRef<Map<string, string>>(new Map());
  // Track conversations the user has already opened (client-side "mark as read")
  const [dismissedUnreadIds, setDismissedUnreadIds] = useState<Set<string>>(new Set());
  const [onboardingDone, setOnboardingDone] = useState(
    () => localStorage.getItem('nola_onboarding_done') === 'true'
  );

  // Keep badge in sync when modal is completed without page reload
  useEffect(() => {
    const handler = () => setOnboardingDone(localStorage.getItem('nola_onboarding_done') === 'true');
    window.addEventListener('storage', handler);
    // Also listen for GHL location change which may reset state
    window.addEventListener('ghl-location-changed', handler);
    window.addEventListener('nola-onboarding-updated', handler);
    return () => {
      window.removeEventListener('storage', handler);
      window.removeEventListener('ghl-location-changed', handler);
      window.removeEventListener('nola-onboarding-updated', handler);
    };
  }, []);

  useEffect(() => {
    contactsRef.current = contacts;
  }, [contacts]);

  const handleLogout = () => {
    setShowLogoutConfirm(true);
  };

  const confirmLogout = () => {
    logout();
    window.location.href = '/login';
  };

  const loadContacts = useCallback(async () => {
    try {
      const data = await fetchContacts(resolvedLocationId || undefined);
      const deletedIds = getDeletedContactIds();
      const filtered = data.filter(c => !deletedIds.includes(c.id));
      setContacts(filtered);
      contactsRef.current = filtered;
    } catch (e) {
      devLog.error(e);
    }
  }, [resolvedLocationId]);

  useEffect(() => {
    let isSubscribed = true;
    const fetch = async () => {
      try {
        const data = await fetchContacts(resolvedLocationId || undefined);
        if (!isSubscribed) return;
        const deletedIds = getDeletedContactIds();
        const filtered = data.filter(c => !deletedIds.includes(c.id));
        setContacts(filtered);
        contactsRef.current = filtered;
      } catch (e) {
        devLog.error(e);
      }
    };
    void fetch();
    const interval = setInterval(() => {
      void fetch();
    }, 60000);

    return () => {
      isSubscribed = false;
      clearInterval(interval);
    };
  }, [resolvedLocationId]);

  useEffect(() => {
    const handleContactUpdated = (event: Event) => {
      const { contact, previous } = (event as CustomEvent<{ contact?: Contact; previous?: Contact }>).detail || {};
      if (!contact) return;

      setContacts(prev => {
        const next = prev.map(item => matchesContactUpdate(item, contact, previous) ? { ...item, ...contact } : item);
        contactsRef.current = next;
        return next;
      });
      void loadContacts();
    };

    window.addEventListener('nola-contact-updated', handleContactUpdated);
    return () => window.removeEventListener('nola-contact-updated', handleContactUpdated);
  }, [loadContacts]);

  const applyConversationRows = useCallback((docRows: Conversation[]) => {
    setConversations(docRows);
    setLoading(false);
  }, []);

  const directHistory = useMemo<Contact[]>(() => {
    const contactMap = buildContactNameLookup(contacts);
    const contactsByGhlId = new Map<string, Contact>();
    const contactsByPhoneKey = new Map<string, Contact>();
    contacts.forEach(contact => {
      if (contact.id) {
        contactsByGhlId.set(contact.id, contact);
      }
      if (contact.ghl_contact_id) {
        contactsByGhlId.set(contact.ghl_contact_id, contact);
      }
      const key = contactPhoneKey(contact.phone);
      if (key) {
        contactsByPhoneKey.set(key, contact);
      }
    });

    // Handle Direct Conversations
    const directConvs = conversations.filter(c => c.type === 'direct' || !c.type);

    // Deduplicate conversations by phone number (merge legacy and scoped UI items)
    const dedupedDirectConvs = new Map<string, Contact>();

    directConvs.forEach(conv => {
      const convId = asText(conv.id);
      const rawPhone = extractPhoneFromDirectConversationId(convId) || convId;
      const phone = normalizeDirectPhone(rawPhone) || rawPhone;
      const phoneKey = contactPhoneKey(phone);
      // Resolve name: prefer contact name (including digit suffix match), then server metadata, then phone
      const contactByGhlIdCandidate = conv.ghl_contact_id ? contactsByGhlId.get(conv.ghl_contact_id) : undefined;
      const contactByGhlId = contactByGhlIdCandidate && contactPhoneKey(contactByGhlIdCandidate.phone) === phoneKey
        ? contactByGhlIdCandidate
        : undefined;
      const contactByPhone = phoneKey ? contactsByPhoneKey.get(phoneKey) : undefined;
      const contactName = contactByGhlId?.name || contactByPhone?.name || resolveContactNameByPhone(contactMap, phone);
      let serverName = conv.name && !isPhoneLike(asText(conv.name)) ? asText(conv.name) : null;

      // Scrub accidental conversation IDs (e.g. "Name locationId_conv_09XX") from the server name
      if (serverName && serverName.includes('_conv_')) {
        let cleanName = serverName.split('_conv_')[0].trim();
        // Remove the 20-character location ID if it's at the end
        cleanName = cleanName.replace(/\b[a-zA-Z0-9]{20}\b$/, '').trim();
        serverName = cleanName || null;
      }

      const name = contactName || serverName || phone;
      const isUnread = (conv.unread === true || conv.last_message_direction === 'inbound') && !dismissedUnreadIds.has(convId);
      const item: Contact = {
        id: convId,
        name,
        phone: contactByGhlId?.phone || contactByPhone?.phone || phone,
        lastMessage: asText(conv.last_message),
        lastSentAt: conv.last_message_at || conv.updated_at || undefined,
        lastMessageDirection: conv.last_message_direction,
        unread: isUnread,
        ghl_contact_id: contactByGhlId?.ghl_contact_id || contactByGhlId?.id || contactByPhone?.ghl_contact_id || contactByPhone?.id || undefined
      };

      const dedupeKey = phoneKey || phone;
      if (dedupedDirectConvs.has(dedupeKey)) {
        const existing = dedupedDirectConvs.get(dedupeKey)!;
        const existingIsScoped = existing.id.includes('_conv_');
        const newIsScoped = convId.includes('_conv_');

        if (newIsScoped && !existingIsScoped) {
          // Prefer scoped ID over unscoped legacy ID
          dedupedDirectConvs.set(dedupeKey, item);
        } else if (!newIsScoped && existingIsScoped) {
          // Keep existing scoped ID
        } else {
          // Both scoped or both unscoped, keep newest message
          const newTime = new Date(item.lastSentAt || 0).getTime();
          const existingTime = new Date(existing.lastSentAt || 0).getTime();
          if (newTime > existingTime) {
            dedupedDirectConvs.set(dedupeKey, item);
          }
        }
      } else {
        dedupedDirectConvs.set(dedupeKey, item);
      }
    });

    return Array.from(dedupedDirectConvs.values()).sort((a, b) => {
      const timeA = new Date(a.lastSentAt || 0).getTime();
      const timeB = new Date(b.lastSentAt || 0).getTime();
      return timeB - timeA;
    });
  }, [contacts, conversations, dismissedUnreadIds]);

  const bulkHistory = useMemo<BulkMessageHistoryItem[]>(() => {
    const contactMap = buildContactNameLookup(contacts);
    const mergedBulk = new Map<string, BulkMessageHistoryItem>();

    localBulkHistory.forEach(item => {
      const key = item.batchId || item.recipientKey || item.id;
      if (!key) return;
      mergedBulk.set(key, item);
    });

    // Map server conversations to BulkMessageHistoryItem. Server rows replace
    // locally-created rows for the same batch once the backend catches up.
    conversations
      .filter(c => c.type === 'bulk' || c.type === 'group')
      .forEach(conv => {
        const convId = asText(conv.id);
        const batchId = extractBatchIdFromGroupConversationId(convId) || convId.replace(/^group_/, '');
        const key = batchId;
        const existing = mergedBulk.get(key);
        const members = asMembers(conv.members);

        // Resolve recipient names from contact list if not present
        let recipientNames = existing?.recipientNames || [];
        if (recipientNames.length === 0 && members.length > 0) {
          recipientNames = members.map((phone) => {
            return resolveContactNameByPhone(contactMap, phone) || phone;
          });
        }

        // Filter out generic system-generated names ('Bulk Campaign', batch IDs) so the
        // sidebar falls through to showing resolved contact names from the members list.
        const rawServerName = asText(conv.name);
        const isGenericName = !rawServerName
          || rawServerName === 'Bulk Campaign'
          || /^batch[-_]/i.test(rawServerName);
        const resolvedCustomName = isGenericName ? (existing?.customName || '') : rawServerName;

        const item: BulkMessageHistoryItem = {
          id: existing?.id || `bulk-db-${batchId}`,
          message: asText(conv.last_message || existing?.message),
          recipientCount: members.length,
          recipientNames,
          recipientNumbers: members,
          recipientKey: existing?.recipientKey || batchId,
          customName: resolvedCustomName || undefined,
          timestamp: conv.last_message_at || conv.updated_at || existing?.timestamp || new Date().toISOString(),
          status: existing?.status || 'sent',
          batchId,
          fromDatabase: true,
          locationId: conv.location_id,
        };
        mergedBulk.set(key, item);
      });

    return Array.from(mergedBulk.values())
      .sort((a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime());
  }, [contacts, conversations, localBulkHistory]);

  // Detect new messages and notify the Composer for immediate refresh
  useEffect(() => {
    const prevTracker = lastMessageTracker.current;
    const newTracker = new Map<string, string>();

    // Track direct conversations
    directHistory.forEach(c => {
      if (c.lastMessage) newTracker.set(c.id, c.lastMessage);
    });
    // Track bulk conversations
    bulkHistory.forEach(b => {
      const convId = b.batchId ? `group_${b.batchId}` : b.id;
      if (b.message) newTracker.set(convId, b.message);
    });

    // Compare and dispatch events for changed conversations
    if (prevTracker.size > 0) {
      newTracker.forEach((msg, convId) => {
        const prev = prevTracker.get(convId);
        if (prev !== undefined && prev !== msg) {
          // This conversation has a new message — re-enable unread badge
          setDismissedUnreadIds(prevSet => {
            if (!prevSet.has(convId)) return prevSet;
            const next = new Set(prevSet);
            next.delete(convId);
            return next;
          });
          window.dispatchEvent(new CustomEvent('conversation-updated', {
            detail: { conversationId: convId }
          }));
        }
      });
      // Also fire for brand-new conversations not in prev tracker
      newTracker.forEach((_msg, convId) => {
        if (!prevTracker.has(convId)) {
          window.dispatchEvent(new CustomEvent('conversation-updated', {
            detail: { conversationId: convId }
          }));
        }
      });
    }
    lastMessageTracker.current = newTracker;
  }, [directHistory, bulkHistory]);

  useEffect(() => {
    const handleBulkMessageCreated = (event: Event) => {
      const item = (event as CustomEvent<BulkMessageHistoryItem>).detail;
      if (!item) return;

      setLocalBulkHistory(prev => {
        const key = item.batchId || item.recipientKey || item.id;
        const withoutDuplicate = prev.filter(existing =>
          (existing.batchId || existing.recipientKey || existing.id) !== key
        );
        return [item, ...withoutDuplicate].slice(0, 50);
      });
    };

    window.addEventListener('nola-bulk-message-created', handleBulkMessageCreated);
    return () => window.removeEventListener('nola-bulk-message-created', handleBulkMessageCreated);
  }, []);

  useEffect(() => {
    let unsubscribe: (() => void) | undefined;

    const setupConversationsListener = async () => {
      if (!resolvedLocationId) {
        return;
      }

      try {
        await ensureFirestoreAuth();

        const q = query(
          collection(db, 'conversations'),
          where('location_id', '==', resolvedLocationId)
        );

        unsubscribe = onSnapshot(q, (snapshot) => {
          const docRows = snapshot.docs.map(doc => {
            const d = doc.data();
            return {
              id: doc.id,
              location_id: d.location_id ?? null,
              type: d.type ?? null,
              members: asMembers(d.members),
              name: d.name ?? null,
              last_message: d.last_message ?? null,
              last_message_at: d.last_message_at ? (typeof d.last_message_at.toDate === 'function' ? d.last_message_at.toDate().toISOString() : d.last_message_at) : null,
              last_message_direction: d.last_message_direction ?? null,
              unread: d.unread ?? false,
              updated_at: d.updated_at ? (typeof d.updated_at.toDate === 'function' ? d.updated_at.toDate().toISOString() : d.updated_at) : null,
              ghl_contact_id: d.ghl_contact_id ?? null,
            } satisfies Conversation;
          });

          applyConversationRows(docRows);
        }, (err) => {
          devLog.error("Firestore conversations snapshot error:", err);
          void fetchConversations(resolvedLocationId)
            .then(applyConversationRows)
            .catch((fallbackErr) => {
              devLog.error("Sidebar conversations API fallback error:", fallbackErr);
              setLoading(false);
            });
        });
      } catch (err) {
        devLog.error("Firestore setup error in Sidebar:", err);
        void fetchConversations(resolvedLocationId)
          .then(applyConversationRows)
          .catch((fallbackErr) => {
            devLog.error("Sidebar conversations API fallback error:", fallbackErr);
            setLoading(false);
          });
      }
    };

    void setupConversationsListener();

    return () => {
      if (unsubscribe) unsubscribe();
    };
  }, [applyConversationRows, resolvedLocationId]);

  // Close menu when clicking outside
  useEffect(() => {
    const handleClickOutside = () => setOpenMenuId(null);
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, []);

  // Handler functions for bulk message CRUD
  const handleStartEdit = (item: BulkMessageHistoryItem) => {
    setEditingBulkId(item.id);
    setEditingBulkName(item.customName || (item.recipientNames?.join(", ") || `${item.recipientCount} recipients`));
    setShowRenameModal(true);
  };

  const handleCancelEdit = () => {
    setEditingBulkId(null);
    setEditingBulkName("");
    setShowRenameModal(false);
  };

  const handleSaveEdit = async (id: string) => {
    if (editingBulkName.trim()) {
      // 1. Rename in backend (Firestore)
      // Bulk composite ID: bulk-db-{batch_id} -> we need the conversation_id: group_{batch_id}
      const item = bulkHistory.find(h => h.id === id);
      if (item && item.batchId) {
        const conversationId = `group_${item.batchId}`;
        await renameConversation(conversationId, editingBulkName.trim());
      }

      // 2. Rename in local storage (for offline/cache fallback)
      renameBulkMessage(id, editingBulkName.trim());

      // 3. Update UI
      setLocalBulkHistory(prev => prev.map(item => item.id === editingBulkId ? { ...item, customName: editingBulkName } : item));
      // Refresh list from server to ensure sync
      void loadContacts();
    }
    setEditingBulkId(null);
    setEditingBulkName("");
    setShowRenameModal(false);
  };

  const startDeleteBulk = (id: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setDeletingBulkId(id);
    setOpenMenuId(null);
  };

  const confirmDeleteBulk = async () => {
    if (!deletingBulkId) return;

    // 1. Delete in backend
    const item = bulkHistory.find(h => h.id === deletingBulkId);
    if (item && item.batchId) {
      let conversationId = `group_${item.batchId}`;
      const prefix = item.locationId || getAccountSettings().ghlLocationId;
      if (prefix) {
        conversationId = `${prefix}_${conversationId}`;
      }
      await deleteConversation(conversationId);
    }

    // 2. Delete in local storage
    deleteBulkMessage(deletingBulkId);

    // 3. Update UI
    setLocalBulkHistory(prev => prev.filter(item => item.id !== deletingBulkId));
    void loadContacts();
    setDeletingBulkId(null);
  };

  const cancelDeleteBulk = () => setDeletingBulkId(null);

  const startDeleteContact = (id: string, phone: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setDeletingContact({ id, phone });
    setOpenMenuId(null);
  };

  const confirmDeleteContact = async () => {
    if (!deletingContact) return;
    const { id, phone } = deletingContact;

    // 1. Delete from backend (GHL/Firestore) only if it's a real GHL contact ID
    //    Skip conversation-sourced IDs (e.g. "locationId_conv_09XXX" or "conv_09XXX")
    const isConversationId = id.includes('_conv_') || id.startsWith('conv_');
    if (!id.startsWith('manual-') && !isConversationId) {
      try {
        await deleteContactBackend(id);
      } catch (err) {
        devLog.error('Failed to delete contact from backend:', err);
      }
    }

    // 2. Delete Conversation from Backend
    if (isConversationId) {
      await deleteConversation(id);
    } else {
      const normalized = normalizePHNumber(phone);
      if (normalized) {
        const convId = `conv_${normalized}`;
        await deleteConversation(convId);
      }
    }

    // 3. Local soft-delete (adds to deleted IDs list in storage)
    deleteContactLocal(id);

    // 4. Clear from UI immediately
    setContacts(contacts.filter(c => c.id !== id));
    void loadContacts(); // fetch fresh state just in case
    setDeletingContact(null);
  };

  const cancelDeleteContact = () => setDeletingContact(null);

  const getBulkDisplayName = (item: BulkMessageHistoryItem): string => {
    // Avoid showing auto-generated/generic names as the display label
    if (item.customName) {
      const looksLikeBatchId = /^batch[-_]\d+$/i.test(item.customName) || /^batch[-_]/i.test(item.customName);
      const isGeneric = item.customName === 'Bulk Campaign';
      if (!looksLikeBatchId && !isGeneric) return item.customName;
    }
    if (item.recipientNames && item.recipientNames.length > 0) {
      const names = item.recipientNames.map((n) => toProperCase(n)).filter(Boolean);
      const shown = names.slice(0, 3);
      const extra = Math.max(0, names.length - shown.length);
      return extra > 0 ? `${shown.join(", ")} +${extra}` : shown.join(", ");
    }
    return `${item.recipientCount} recipient${item.recipientCount !== 1 ? 's' : ''}`;
  };

  const navItems = [
    {
      id: 'home',
      label: 'Home',
      icon: <FiHome className="h-5 w-5" />
    },
    {
      id: 'contacts',
      label: 'Contacts',
      icon: (
        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
        </svg>
      )
    },
    {
      id: 'templates',
      label: 'Templates',
      icon: (
        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M16.5 8.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v8.25A2.25 2.25 0 006 16.5h2.25m8.25-8.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-7.5A2.25 2.25 0 018.25 18v-1.5m8.25-8.25h-6a2.25 2.25 0 00-2.25 2.25v6" />
        </svg>
      )
    }
  ];

  return (
    <div className={`
      h-screen bg-white/80 dark:bg-[#121415]/90 backdrop-blur-2xl flex-shrink-0 flex flex-col border-r border-black/[0.06] dark:border-white/[0.05] relative z-[60] transition-all duration-300 overflow-hidden
      ${isCollapsed ? 'w-20' : 'w-full'}
    `}>
      {/* Header Profile / Logo Area */}
      <div className={`px-3 pt-3 pb-2 ${isCollapsed ? 'flex flex-col items-center px-2' : ''}`}>
        <div className={`flex items-center justify-between transition-all ${isCollapsed ? 'mb-3 justify-center' : 'mb-3'}`}>
          <div
            className={`flex items-center relative group cursor-pointer transition-all ${isCollapsed ? '' : 'gap-3.5'}`}
            onClick={isCollapsed ? onToggleCollapse : undefined}
          >
            <div className={`w-9 h-9 rounded-[10px] bg-white dark:bg-[#1a1b1e] border border-black/[0.06] dark:border-white/[0.08] shadow-sm flex items-center justify-center transition-all duration-500 relative overflow-hidden group-hover:rotate-3 group-hover:scale-105 active:scale-95`}>
              <div className={`transition-all duration-500 ${isCollapsed ? 'group-hover:opacity-0 group-hover:scale-50' : 'group-hover:rotate-[-6deg]'}`}>
                <img src={faviconLogo} alt="NOLA SMS PRO" className="h-7 w-7 object-contain" />
              </div>

              {/* Rail Mode Expand Toggle (Right Collapse Icon) */}
              {isCollapsed && (
                <div className="absolute inset-0 flex items-center justify-center opacity-0 scale-50 group-hover:opacity-100 group-hover:scale-100 transition-all duration-300">
                  <TbLayoutSidebarRightCollapse className="h-6 w-6 text-[#111111] dark:text-white" />
                </div>
              )}
            </div>

            {!isCollapsed && (
              <div className="flex flex-col">
                <h2 className="text-[14.5px] font-extrabold text-[#111111] dark:text-white tracking-tight leading-none">
                  NOLA SMS PRO
                </h2>
                <div className="flex items-center gap-1.5 mt-0.5">
                  <span className="text-[10px] font-bold text-[#2b83fa] uppercase tracking-widest opacity-90">2-Way SMS Platform</span>
                </div>
              </div>
            )}
          </div>

          <div className="flex items-center gap-1">
            {/* Mobile Close Button */}
            {!isCollapsed && onCloseMobile && (
              <button
                onClick={onCloseMobile}
                className="md:hidden flex p-2 rounded-xl text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 transition-all active:scale-90"
                title="Close Menu"
              >
                <FiX className="h-5 w-5" />
              </button>
            )}

            {/* Collapse Toggle (Desktop Only) - Now on the right end */}
            {!isCollapsed && (
              <button
                onClick={onToggleCollapse}
                className="hidden md:flex p-2 rounded-xl text-gray-400 hover:text-[#111111] hover:bg-black/[0.04] dark:hover:text-white dark:hover:bg-white/[0.06] transition-all active:scale-90"
                title="Collapse Sidebar"
              >
                <TbLayoutSidebarLeftCollapse className="h-5 w-5" />
              </button>
            )}
          </div>
        </div>

        {/* New Message Button */}
        <div className={`mt-2 mb-2 ${isCollapsed ? 'px-2 flex justify-center' : ''}`}>
          <GlareHover
            glareColor="#ffffff"
            glareOpacity={0.25}
            glareAngle={-30}
            glareSize={300}
            transitionDuration={700}
            playOnce={false}
            className={`
              btn-new-message cursor-pointer group active:scale-95 transition-all
              ${isCollapsed ? 'w-10 h-10 rounded-full shadow-md' : 'w-full rounded-full shadow-md'}
            `}
            style={{ display: 'flex' }}
          >
            <button
              onClick={() => onTabChange('compose')}
              className={`
                w-full h-full flex items-center justify-center gap-2
                text-white bg-transparent border-none
                ${isCollapsed ? 'p-0' : 'py-2.5 px-4'}
              `}
              title="New Message"
            >
              <FiPlus className={`h-4 w-4 transition-transform duration-300 group-hover:rotate-90`} />
              {!isCollapsed && <span className="font-bold text-[13px] tracking-tight">New Message</span>}
            </button>
          </GlareHover>
        </div>

        {/* Navigation List */}
        <div className={`mt-2 ${isCollapsed ? 'hidden' : 'px-3 pb-1'}`}>
          <span className="text-[10.5px] font-bold text-[#9aa0a6] dark:text-[#5f6368] uppercase tracking-widest">Main Menu</span>
        </div>
        <nav className={`flex flex-col gap-0.5 mt-0.5 ${isCollapsed ? 'items-center px-1' : 'px-1'}`}>
          {navItems.map((item) => {
            const isActive = activeTab === item.id;
            return (
              <button
                key={item.id}
                onClick={() => onTabChange(item.id as ViewTab)}
                className={`
                  flex items-center transition-all duration-300 relative group
                  ${isCollapsed ? 'w-14 py-1.5 flex-col justify-center gap-0.5 rounded-xl' : 'w-full gap-2.5 px-2.5 py-1.5 rounded-lg'}
                  ${isActive
                    ? 'bg-[#eceff3] text-[#111111] dark:bg-[#202327] dark:text-white'
                    : 'text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03] hover:text-[#111111] dark:hover:text-[#ececf1]'}
                `}
              >
                {isActive && !isCollapsed && (
                  <div className="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-3.5 bg-[#111111] dark:bg-white/80 rounded-r-full shadow-sm" />
                )}
                {isActive && isCollapsed && (
                  <div className="absolute left-[-4px] top-1/2 -translate-y-1/2 w-0.5 h-4 bg-[#111111] dark:bg-white/80 rounded-r-full" />
                )}
                <div className={`text-[17px] transition-all duration-500 ${isActive ? 'scale-110 text-[#111111] dark:text-white' : 'group-hover:scale-105 group-hover:text-[#111111] dark:group-hover:text-white'} active:scale-90`}>
                  {item.icon}
                </div>
                {isCollapsed ? (
                  <span className={`text-[9px] font-semibold leading-none tracking-tight ${isActive ? 'text-[#111111] dark:text-white' : 'text-[#9aa0a6] dark:text-[#5f6368]'}`}>
                    {item.label}
                  </span>
                ) : (
                  <span className={`text-[12.5px] transition-all duration-200 ${isActive ? 'font-bold tracking-tight' : 'font-medium'}`}>
                    {item.label}
                  </span>
                )}
              </button>
            );
          })}
        </nav>
      </div>

      {/* Activity Feed Section */}
      <div className={`flex-1 min-h-0 flex flex-col overflow-hidden ${isCollapsed ? 'items-center' : ''}`}>
        {!isCollapsed && (
          <div className="flex-1 flex flex-col px-2 pb-3 overflow-hidden min-h-0">
            {/* Messages Content */}
            {loading ? (
              <SidebarMessagesSkeleton />
            ) : directHistory.length === 0 && bulkHistory.length === 0 ? (
              <div
                onClick={() => onTabChange('compose')}
                className="flex-1 flex flex-col items-center justify-center p-6 text-center cursor-pointer group rounded-2xl border border-dashed border-black/[0.06] dark:border-white/[0.06] hover:border-black/20 dark:hover:border-white/20 hover:bg-black/[0.02] dark:hover:bg-white/[0.03] transition-all duration-300 mx-2 my-4 relative overflow-hidden"
              >
                {/* Subtle soft ambient light glow in the background on hover */}
                <div className="absolute -inset-10 bg-gradient-to-r from-black/[0.03] via-black/[0.02] to-black/[0.03] dark:from-white/[0.05] dark:via-white/[0.03] dark:to-white/[0.05] rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-700 pointer-events-none" />

                {/* Floating animated icon container */}
                <div className="w-16 h-16 rounded-[22px] bg-gradient-to-tr from-black/[0.06] to-black/[0.02] dark:from-white/[0.1] dark:to-white/[0.03] flex items-center justify-center mb-4 relative transition-all duration-500 group-hover:scale-110 group-hover:-translate-y-1 shadow-sm group-hover:shadow-md border border-white/50 dark:border-white/5">
                  <div className="absolute inset-0 bg-black/[0.08] dark:bg-white/[0.12] rounded-[22px] blur-xl opacity-30 group-hover:opacity-100 transition-opacity duration-500"></div>
                  <FiPlus className="w-7 h-7 text-[#111111] dark:text-white relative z-10 transition-transform duration-300 group-hover:rotate-90" />
                </div>

                <h3 className="text-[14px] font-bold text-[#111111] dark:text-white mb-1.5 transition-colors relative z-10">
                  Start a new conversation
                </h3>

                <p className="text-[11.5px] leading-relaxed text-gray-500 dark:text-gray-400 max-w-[180px] relative z-10">
                  Click here or the <span className="text-[#111111] dark:text-white font-bold group-hover:underline">New Message</span> button above to send your first SMS.
                </p>
              </div>
            ) : (
              <>
                {/* Section label */}
                <div className="px-3 pt-2 pb-1">
                  <span className="text-[10.5px] font-bold text-[#9aa0a6] dark:text-[#5f6368] uppercase tracking-widest">Messages</span>
                </div>

                {/* Direct Messages Header */}
                <div
                  className="flex items-center justify-between gap-2 px-3 py-1.5 rounded-xl hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors cursor-pointer mx-1"
                  onClick={() => setDirectMessagesExpanded(!directMessagesExpanded)}
                >
                  <div className="flex items-center gap-2">
                    <div className={`transition-transform duration-200 ${directMessagesExpanded ? 'rotate-0' : '-rotate-90'}`}>
                      <FiChevronDown className="w-3.5 h-3.5 text-[#9aa0a6] dark:text-[#5f6368]" />
                    </div>
                    <span className="text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6]">Direct Messages</span>
                  </div>
                  <span className="text-[10px] font-bold text-[#9aa0a6] dark:text-[#5f6368] bg-black/5 dark:bg-white/5 px-1.5 py-0.5 rounded-full min-w-[18px] text-center">
                    {directHistory.length}
                  </span>
                </div>

                {/* Direct Messages scrollable area */}
                <div
                  ref={contactsListRef}
                  className={`overflow-y-auto overflow-x-hidden sidebar-scroll transition-all duration-300 pb-1 rounded-xl ${directMessagesExpanded ? 'max-h-[30vh] opacity-100 mb-1' : 'max-h-0 opacity-0'}`}
                  onTouchStart={(e) => { touchStartY.current = e.touches[0].clientY; }}
                  onTouchEnd={(e) => {
                    const delta = e.changedTouches[0].clientY - touchStartY.current;
                    const atTop = (contactsListRef.current?.scrollTop ?? 0) === 0;
                    if (delta > 60 && atTop) loadContacts();
                  }}
                >
                  <div className="flex flex-col gap-0.5 py-0.5">
                    {directHistory.map(contact => {
                      const isContactActive = activeTab === 'compose' && activeContactId === contact.id;
                      return (
                        <div
                          key={contact.id}
                          className={`
                            group relative transition-all duration-200 overflow-visible
                            px-3 py-2 rounded-xl cursor-pointer mx-1
                            ${isContactActive
                              ? 'bg-[#eceff3] dark:bg-[#202327] ring-1 ring-black/[0.06] dark:ring-white/[0.06]'
                              : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'}
                          `}
                          onClick={() => {
                            onTabChange('compose');
                            onSelectContact(contact);
                            // Client-side mark as read: dismiss the unread badge
                            if (contact.unread) {
                              setDismissedUnreadIds(prev => new Set(prev).add(contact.id));
                            }
                          }}
                        >
                          {isContactActive && (
                            <span className="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[#111111] dark:bg-white/80" />
                          )}
                          <div className="flex items-center gap-3">
                            <div className="relative flex-shrink-0">
                              <div className={`w-9 h-9 rounded-full flex items-center justify-center font-bold text-[13px] transition-all duration-200
                              ${isContactActive
                                  ? 'bg-[#111111] text-white shadow-md shadow-black/10 dark:bg-white dark:text-[#111111]'
                                  : contact.unread
                                    ? 'bg-[#2b83fa]/15 text-[#2b83fa] dark:bg-[#2b83fa]/25 dark:text-[#8bbcff]'
                                    : 'bg-[#f0f2f4] dark:bg-[#2a2b32] text-[#5f6368] dark:text-[#9aa0a6]'}
                              `}>
                                {(contact.name || contact.phone).charAt(0).toUpperCase()}
                              </div>
                              {isContactActive && (
                                <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-green-500 border-2 border-white dark:border-[#121415]" />
                              )}
                              {contact.unread && !isContactActive && (
                                <span className="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-[#2b83fa] border-2 border-white dark:border-[#121415] shadow-sm animate-pulse" title="New reply" />
                              )}
                            </div>
                            <div className="flex-1 min-w-0">
                              <div className="flex justify-between items-center mb-0.5">
                                <span className={`text-[13px] truncate leading-tight ${isContactActive ? 'font-bold text-[#111111] dark:text-white' : contact.unread ? 'font-bold text-[#111111] dark:text-white' : 'font-medium text-[#3c4043] dark:text-[#e8eaed]'}`}>
                                  {toProperCase(contact.name || contact.phone)}
                                </span>
                                <div className="flex items-center gap-0.5 flex-shrink-0 ml-1">
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      if (openMenuId === contact.id) {
                                        setOpenMenuId(null);
                                      } else {
                                        const rect = e.currentTarget.getBoundingClientRect();
                                        setMenuAnchor({ x: rect.right, y: rect.bottom });
                                        setOpenMenuId(contact.id);
                                      }
                                    }}
                                    className="p-1 rounded-lg opacity-0 group-hover:opacity-100 hover:bg-black/[0.06] dark:hover:bg-white/10 transition-all"
                                  >
                                    <FiMoreVertical className="w-3.5 h-3.5 text-[#9aa0a6]" />
                                  </button>
                                  {openMenuId === contact.id && menuAnchor && createPortal(
                                    <div
                                      className="fixed bg-white dark:bg-[#1e1f23] rounded-xl shadow-xl border border-black/5 dark:border-white/10 py-1.5 min-w-[120px] z-[99999] animate-in zoom-in-95 duration-150"
                                      style={{ top: menuAnchor.y + 4, left: menuAnchor.x, transform: 'translateX(-100%)', transformOrigin: 'top right' }}
                                      onClick={(e) => e.stopPropagation()}
                                    >
                                      <button
                                        onClick={(e) => startDeleteContact(contact.id, contact.phone, e)}
                                        className="w-full px-3 py-2 text-left text-[12px] text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 flex items-center gap-2 transition-colors"
                                      >
                                        <FiTrash2 className="w-3.5 h-3.5" />
                                        Delete
                                      </button>
                                    </div>,
                                    document.body
                                  )}
                                </div>
                              </div>
                              <div className="text-[11.5px] text-[#80868b] dark:text-[#9aa0a6] truncate flex items-center gap-1 font-normal">
                                {contact.lastMessage ? (
                                  <>
                                    {contact.lastMessageDirection === 'outbound' && (
                                      <span className="font-semibold text-[#5f6368] dark:text-[#bdc1c6]">You:</span>
                                    )}
                                    <span className="truncate">{contact.lastMessage}</span>
                                  </>
                                ) : 'No messages yet'}
                              </div>
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Bulk Messages Header */}
                <div
                  className="flex items-center justify-between gap-2 px-3 py-1.5 rounded-xl hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors cursor-pointer mt-1 mx-1"
                  onClick={() => setBulkMessagesExpanded(!bulkMessagesExpanded)}
                >
                  <div className="flex items-center gap-2">
                    <div className={`transition-transform duration-200 ${bulkMessagesExpanded ? 'rotate-0' : '-rotate-90'}`}>
                      <FiChevronDown className="w-3.5 h-3.5 text-[#9aa0a6] dark:text-[#5f6368]" />
                    </div>
                    <span className="text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6]">Bulk Messages</span>
                  </div>
                  <span className="text-[10px] font-bold text-[#9aa0a6] dark:text-[#5f6368] bg-black/5 dark:bg-white/5 px-1.5 py-0.5 rounded-full min-w-[18px] text-center">{bulkHistory.length}</span>
                </div>

                {/* Bulk Messages scrollable area */}
                <div className={`overflow-y-auto overflow-x-hidden sidebar-scroll transition-all duration-300 pb-2 rounded-xl ${bulkMessagesExpanded ? 'max-h-[30vh] opacity-100' : 'max-h-0 opacity-0'}`}>
                  <div className="flex flex-col gap-0.5 py-0.5">
                    {bulkHistory.length > 0 ? (
                      bulkHistory.map(item => {
                        const isActive = activeTab === 'compose' && activeBulkMessageId === item.id;
                        return (
                          <div
                            key={item.id}
                            className={`
                              group relative transition-all duration-200 rounded-xl mx-1
                              px-3 py-2 cursor-pointer overflow-visible
                              ${isActive
                                ? 'bg-[#eceff3] dark:bg-[#202327] ring-1 ring-black/[0.06] dark:ring-white/[0.06]'
                                : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'
                              }
                            `}
                            onClick={() => {
                              onTabChange('compose');
                              if (onSelectBulkMessage) {
                                onSelectBulkMessage(item);
                              }
                            }}
                          >
                            {isActive && (
                              <span className="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[#111111] dark:bg-white/80" />
                            )}
                            <div className="flex items-center gap-3 overflow-visible">
                              <div className="relative flex-shrink-0">
                                <div className={`w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200
                                  ${isActive
                                    ? 'bg-[#111111] text-white shadow-md shadow-black/10 dark:bg-white dark:text-[#111111]'
                                    : 'bg-[#f1f2f4] dark:bg-white/[0.06] text-[#5f6368] dark:text-[#b6bac2] group-hover:bg-[#e7e9ed] dark:group-hover:bg-white/[0.1] group-hover:text-[#111111] dark:group-hover:text-white'
                                  }
                                `}>
                                  <FiUsers className="w-4 h-4" />
                                </div>
                              </div>
                              <div className="flex-1 min-w-0 overflow-visible">
                                <>
                                  <div className="flex justify-between items-center mb-0.5">
                                    <span className={`text-[13px] truncate leading-tight ${isActive ? 'font-semibold text-[#111111] dark:text-white' : 'font-medium text-[#3c4043] dark:text-[#e8eaed]'}`}>
                                      {getBulkDisplayName(item)}
                                    </span>
                                    <div className="flex items-center gap-0.5 flex-shrink-0 ml-1">
                                      <button
                                        onClick={(e) => {
                                          e.stopPropagation();
                                          if (openMenuId === item.id) {
                                            setOpenMenuId(null);
                                          } else {
                                            const rect = e.currentTarget.getBoundingClientRect();
                                            setMenuAnchor({ x: rect.right, y: rect.bottom });
                                            setOpenMenuId(item.id);
                                          }
                                        }}
                                        className="p-1 rounded-lg opacity-0 group-hover:opacity-100 hover:bg-black/[0.06] dark:hover:bg-white/10 transition-all"
                                      >
                                        <FiMoreVertical className="w-3.5 h-3.5 text-[#9aa0a6]" />
                                      </button>
                                      {openMenuId === item.id && menuAnchor && createPortal(
                                        <div
                                          className="fixed bg-white dark:bg-[#1e1f23] rounded-xl shadow-xl border border-black/5 dark:border-white/10 py-1.5 min-w-[120px] z-[99999] animate-in zoom-in-95 duration-150"
                                          style={{ top: menuAnchor.y + 4, left: menuAnchor.x, transform: 'translateX(-100%)', transformOrigin: 'top right' }}
                                          onClick={(e) => e.stopPropagation()}
                                        >
                                          <button
                                            onClick={(e) => {
                                              e.stopPropagation();
                                              handleStartEdit(item);
                                              setOpenMenuId(null);
                                            }}
                                            className="w-full px-3 py-2 text-left text-[12px] text-[#5f6368] dark:text-[#9aa0a6] hover:bg-black/5 dark:hover:bg-white/5 flex items-center gap-2 transition-colors"
                                          >
                                            <FiEdit2 className="w-3.5 h-3.5" />
                                            Rename
                                          </button>
                                          <button
                                            onClick={(e) => startDeleteBulk(item.id, e)}
                                            className="w-full px-3 py-2 text-left text-[12px] text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 flex items-center gap-2 transition-colors"
                                          >
                                            <FiTrash2 className="w-3.5 h-3.5" />
                                            Delete
                                          </button>
                                        </div>,
                                        document.body
                                      )}
                                    </div>
                                  </div>
                                  <div className={`text-[11.5px] truncate leading-snug ${isActive ? 'text-[#5f6368] dark:text-[#b6bac2]' : 'text-[#9aa0a6] dark:text-[#5f6368]'}`}>
                                    {item.message || 'No message sent'}
                                  </div>
                                </>
                              </div>
                            </div>
                          </div>
                        );
                      })
                    ) : (
                      <div className="text-[11.5px] text-[#9aa0a6] dark:text-[#5f6368] px-4 py-2">
                        No bulk messages yet
                      </div>
                    )}
                  </div>
                </div>
              </>
            )}
          </div>
        )}
      </div>

      {/* Getting Started + Settings Footer */}
      <div className={`px-2 py-2 border-t border-black/[0.05] dark:border-white/[0.05] bg-white/60 dark:bg-[#121415]/60 backdrop-blur-sm flex flex-col gap-1 ${isCollapsed ? 'items-center' : ''}`}>
        {/* Getting Started */}
        <button
          onClick={() => {
            setOnboardingDone(false); // trigger re-render for dot
            window.dispatchEvent(new CustomEvent('open-onboarding', { detail: { step: 0 } }));
          }}
          className={`
            md:hidden flex items-center rounded-xl transition-all duration-200 group relative
            ${isCollapsed ? 'w-10 h-10 justify-center' : 'w-full gap-3 px-3 py-2'}
            text-[#9aa0a6] dark:text-[#5f6368] hover:bg-black/[0.04] dark:hover:bg-white/[0.06] hover:text-[#111111] dark:hover:text-white
          `}
          title="Getting Started"
        >
          <div className="relative flex-shrink-0">
            <FiBookOpen className="h-5 w-5 transition-transform duration-300 group-hover:scale-110" />
            {!onboardingDone && (
              <span className="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-emerald-500 border border-white dark:border-[#121415] animate-pulse" />
            )}
          </div>
          {!isCollapsed && (
            <div className="flex items-center justify-between flex-1 min-w-0">
              <span className="font-medium text-[13px] truncate">Getting Started</span>
              {!onboardingDone && (
                <span className="ml-auto px-1.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-500 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                  New
                </span>
              )}
            </div>
          )}
        </button>

        {/* Settings */}
        <button
          onClick={() => onTabChange('settings')}
          className={`
            flex items-center rounded-xl transition-all duration-200 group
            ${isCollapsed ? 'w-10 h-10 justify-center' : 'w-full gap-3 px-3 py-2'}
            ${activeTab === 'settings'
              ? 'bg-[#eceff3] text-[#111111] dark:bg-[#202327] dark:text-white'
              : 'text-[#9aa0a6] dark:text-[#5f6368] hover:bg-black/[0.04] dark:hover:bg-white/[0.04] hover:text-[#5f6368] dark:hover:text-[#9aa0a6]'}
          `}
        >
          <div className={`transition-all duration-300 ${activeTab === 'settings' ? 'scale-110 rotate-45 text-[#111111] dark:text-white' : 'group-hover:scale-110 group-hover:rotate-45'}`}>
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>
          {!isCollapsed && <span className="font-medium text-[13px]">Settings</span>}
        </button>

        {/* Logout - Hidden when inside GHL iframe */}
        {window.self === window.top && (
          <button
            onClick={handleLogout}
            className={`
              flex items-center rounded-xl transition-all duration-200 group mt-0.5
              ${isCollapsed ? 'w-10 h-10 justify-center' : 'w-full gap-3 px-3 py-2'}
              text-red-500/80 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400
            `}
            title="Log out"
          >
            <div className="transition-transform duration-300 group-hover:-translate-x-0.5">
              <FiLogOut className="h-4 w-4" />
            </div>
            {!isCollapsed && <span className="font-medium text-[13px]">Log out</span>}
          </button>
        )}
      </div>

      {/* Logout Confirmation Modal */}
      {showLogoutConfirm && createPortal(
        <div className="fixed inset-0 z-[99999] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-[#1e1f23] rounded-2xl shadow-xl border border-[#0000001a] dark:border-[#ffffff1a] w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="p-5 pb-4">
              <div className="w-12 h-12 rounded-2xl bg-red-50 dark:bg-red-500/10 text-red-500 flex items-center justify-center mb-4">
                <FiLogOut className="w-5 h-5" />
              </div>
              <h3 className="text-lg font-bold text-[#111111] dark:text-white mb-2">Log out?</h3>
              <p className="text-[13px] text-gray-600 dark:text-gray-300">
                Are you sure you want to log out?
              </p>
            </div>
            <div className="flex bg-gray-50 dark:bg-black/40 border-t border-gray-100 dark:border-white/5 p-4 gap-3 justify-end mt-2">
              <button
                onClick={() => setShowLogoutConfirm(false)}
                className="px-4 py-2 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-white/10 transition-all"
              >
                Cancel
              </button>
              <button
                onClick={confirmLogout}
                className="px-5 py-2 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition-all"
              >
                Log Out
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* Rename Modal Portal */}
      {showRenameModal && createPortal(
        <div className="fixed inset-0 z-[99999] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-[#1e1f23] rounded-2xl shadow-xl border border-[#0000001a] dark:border-[#ffffff1a] w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="p-5 pb-4">
              <h3 className="text-lg font-bold text-[#111111] dark:text-white mb-4">Rename Conversation</h3>
              <input
                type="text"
                value={editingBulkName}
                onChange={(e) => setEditingBulkName(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') handleSaveEdit(editingBulkId!);
                  if (e.key === 'Escape') handleCancelEdit();
                }}
                autoFocus
                placeholder="Enter new name"
                className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-black/20 text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-black/20 dark:focus:ring-white/20 transition-all font-medium"
              />
            </div>
            <div className="flex bg-gray-50 dark:bg-black/40 border-t border-gray-100 dark:border-white/5 p-4 gap-3 justify-end mt-2">
              <button
                onClick={handleCancelEdit}
                className="px-4 py-2 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-white/10 transition-all"
              >
                Cancel
              </button>
              <button
                onClick={() => handleSaveEdit(editingBulkId!)}
                className="px-5 py-2 rounded-xl text-sm font-bold text-white bg-[#111111] hover:bg-[#2b2b2b] dark:bg-white dark:text-[#111111] dark:hover:bg-[#e8eaed] shadow-sm transition-all"
              >
                Save
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* Delete Bulk Confirmation Modal */}
      {deletingBulkId && createPortal(
        <div className="fixed inset-0 z-[99999] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-[#1e1f23] rounded-2xl shadow-xl border border-[#0000001a] dark:border-[#ffffff1a] w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="p-5 pb-4">
              <h3 className="text-lg font-bold text-[#111111] dark:text-white mb-2">Delete bulk message?</h3>
              <p className="text-[13px] text-gray-600 dark:text-gray-300">
                This will remove the bulk conversation and its messages from this account's inbox. This action can't be undone.
              </p>
            </div>
            <div className="flex bg-gray-50 dark:bg-black/40 border-t border-gray-100 dark:border-white/5 p-4 gap-3 justify-end mt-2">
              <button
                onClick={cancelDeleteBulk}
                className="px-4 py-2 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-white/10 transition-all"
              >
                Cancel
              </button>
              <button
                onClick={confirmDeleteBulk}
                className="px-5 py-2 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition-all"
              >
                Delete
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* Delete DM Confirmation Modal */}
      {deletingContact && createPortal(
        <div className="fixed inset-0 z-[99999] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-[#1e1f23] rounded-2xl shadow-xl border border-[#0000001a] dark:border-[#ffffff1a] w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="p-5 pb-4">
              <h3 className="text-lg font-bold text-[#111111] dark:text-white mb-2">Delete direct message?</h3>
              <p className="text-[13px] text-gray-600 dark:text-gray-300">
                This will remove the direct conversation and its messages from this account's inbox. The contact itself will remain. This action can't be undone.
              </p>
            </div>
            <div className="flex bg-gray-50 dark:bg-black/40 border-t border-gray-100 dark:border-white/5 p-4 gap-3 justify-end mt-2">
              <button
                onClick={cancelDeleteContact}
                className="px-4 py-2 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-white/10 transition-all"
              >
                Cancel
              </button>
              <button
                onClick={confirmDeleteContact}
                className="px-5 py-2 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition-all"
              >
                Delete
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}
    </div>
  );
};
