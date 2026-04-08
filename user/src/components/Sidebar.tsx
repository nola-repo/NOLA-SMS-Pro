import { useEffect, useState, useCallback, useRef } from "react";
import { createPortal } from "react-dom";
import { fetchContacts } from "../api/contacts";
import { fetchConversations, renameConversation, deleteConversation, normalizePHNumber } from "../api/sms";
import { deleteContact as deleteContactBackend } from "../api/contacts";
import type { Contact } from "../types/Contact";
import type { BulkMessageHistoryItem } from "../types/Sms";
import { renameBulkMessage, deleteBulkMessage, deleteContact as deleteContactLocal, getDeletedContactIds } from "../utils/storage";
import { TbLayoutSidebarLeftCollapse, TbLayoutSidebarRightCollapse } from "react-icons/tb";
import { FiUsers, FiChevronDown, FiEdit2, FiTrash2, FiMoreVertical, FiHome, FiPlus, FiX, FiLogOut } from "react-icons/fi";
import { logout } from "../services/authService";
import GlareHover from "./GlareHover";
import { extractBatchIdFromGroupConversationId, extractPhoneFromDirectConversationId } from "../utils/conversationId";
import { getAccountSettings } from "../utils/settingsStorage";

export type ViewTab = 'home' | 'compose' | 'contacts' | 'templates' | 'settings';

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

const SidebarSkeleton = () => (
  <div className="flex items-center gap-3 px-3 py-2 rounded-xl animate-pulse">
    <div className="w-9 h-9 rounded-full bg-gray-200 dark:bg-white/10 flex-shrink-0" />
    <div className="flex-1 min-w-0 space-y-1.5">
      <div className="h-3 w-2/3 bg-gray-200 dark:bg-white/10 rounded-full" />
      <div className="h-2 w-full bg-gray-100 dark:bg-white/5 rounded-full" />
    </div>
  </div>
);

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
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [directHistory, setDirectHistory] = useState<Contact[]>([]);
  const [bulkHistory, setBulkHistory] = useState<BulkMessageHistoryItem[]>([]);
  const [directMessagesExpanded, setDirectMessagesExpanded] = useState(true);
  const [bulkMessagesExpanded, setBulkMessagesExpanded] = useState(true);
  const [editingBulkId, setEditingBulkId] = useState<string | null>(null);
  const [editingBulkName, setEditingBulkName] = useState("");
  const [deletingBulkId, setDeletingBulkId] = useState<string | null>(null);
  const [deletingContact, setDeletingContact] = useState<{id: string, phone: string} | null>(null);
  const [openMenuId, setOpenMenuId] = useState<string | null>(null);
  const [menuAnchor, setMenuAnchor] = useState<{ x: number, y: number } | null>(null);
  const [loading, setLoading] = useState(true);
  const [showRenameModal, setShowRenameModal] = useState(false);
  const touchStartY = useRef<number>(0);
  const contactsListRef = useRef<HTMLDivElement>(null);
  // Track last_message per conversation to detect new messages and notify the Composer
  const lastMessageTracker = useRef<Map<string, string>>(new Map());
  const [onboardingDone, setOnboardingDone] = useState(
    () => localStorage.getItem('nola_onboarding_done') === 'true'
  );

  // Keep badge in sync when modal is completed without page reload
  useEffect(() => {
    const handler = () => setOnboardingDone(localStorage.getItem('nola_onboarding_done') === 'true');
    window.addEventListener('storage', handler);
    // Also listen for GHL location change which may reset state
    window.addEventListener('ghl-location-changed', handler);
    return () => {
      window.removeEventListener('storage', handler);
      window.removeEventListener('ghl-location-changed', handler);
    };
  }, []);

  const handleLogout = () => {
    logout();
    window.location.href = '/login';
  };

  const loadContacts = useCallback(async () => {
    try {
      const data = await fetchContacts();
      const deletedIds = getDeletedContactIds();
      const filtered = data.filter(c => !deletedIds.includes(c.id));
      setContacts(filtered);

      // Load conversations from server
      try {
        const conversations = await fetchConversations();
        
        // Build a phone -> name lookup map from freshly-fetched contacts (not stale state)
        const contactMap = new Map<string, string>();
        data.forEach(c => {
          contactMap.set(c.phone, c.name);
          const cleaned = c.phone.replace(/\D/g, "");
          if (cleaned) {
            contactMap.set(cleaned, c.name);
            // Also map +63 variants for GHL phone format matching
            if (cleaned.startsWith('0')) contactMap.set('+63' + cleaned.slice(1), c.name);
            if (cleaned.startsWith('9')) contactMap.set('+63' + cleaned, c.name);
          }
        });

        // Handle Direct Conversations
        const directConvs = conversations.filter(c => c.type === 'direct' || !c.type);
        
        // Deduplicate conversations by phone number (merge legacy and scoped UI items)
        const dedupedDirectConvs = new Map<string, Contact>();
        
        directConvs.forEach(conv => {
          const phone = extractPhoneFromDirectConversationId(conv.id) || conv.id;
          const cleanPhone = phone.replace(/\D/g, "");
          // Resolve name: prefer contact name, then server metadata (only if it's a real name, not a phone number), then phone
          const isPhoneNumber = (s: string) => /^[\d+\-() ]+$/.test(s);
          const contactName = contactMap.get(phone) || contactMap.get(cleanPhone) || contactMap.get('+63' + cleanPhone);
          let serverName = conv.name && !isPhoneNumber(conv.name) ? conv.name : null;
          
          // Scrub accidental conversation IDs (e.g. "Name locationId_conv_09XX") from the server name
          if (serverName && serverName.includes('_conv_')) {
             let cleanName = serverName.split('_conv_')[0].trim();
             // Remove the 20-character location ID if it's at the end
             cleanName = cleanName.replace(/\b[a-zA-Z0-9]{20}\b$/, '').trim();
             serverName = cleanName || null;
          }
          
          const name = contactName || serverName || phone;
          
          const item: Contact = {
            id: conv.id,
            name: name,
            phone: phone,
            lastMessage: conv.last_message,
            lastSentAt: conv.last_message_at || conv.updated_at || undefined
          };
          
          if (dedupedDirectConvs.has(phone)) {
            const existing = dedupedDirectConvs.get(phone)!;
            const existingIsScoped = existing.id.includes('_conv_');
            const newIsScoped = conv.id.includes('_conv_');
            
            if (newIsScoped && !existingIsScoped) {
               // Prefer scoped ID over unscoped legacy ID
               dedupedDirectConvs.set(phone, item);
            } else if (!newIsScoped && existingIsScoped) {
               // Keep existing scoped ID
            } else {
               // Both scoped or both unscoped, keep newest message
               const newTime = new Date(item.lastSentAt || 0).getTime();
               const existingTime = new Date(existing.lastSentAt || 0).getTime();
               if (newTime > existingTime) {
                 dedupedDirectConvs.set(phone, item);
               }
            }
          } else {
            dedupedDirectConvs.set(phone, item);
          }
        });

        const historyContacts: Contact[] = Array.from(dedupedDirectConvs.values()).sort((a, b) => {
          const timeA = new Date(a.lastSentAt || 0).getTime();
          const timeB = new Date(b.lastSentAt || 0).getTime();
          return timeB - timeA;
        });
        setDirectHistory(historyContacts);

        // 1. Initial server-side only
        const mergedBulk = new Map<string, BulkMessageHistoryItem>();

        // 2. Map server conversations to BulkMessageHistoryItem
        conversations
          .filter(c => c.type === 'bulk' || c.type === 'group')
          .forEach(conv => {
            const batchId = extractBatchIdFromGroupConversationId(conv.id) || conv.id.replace(/^group_/, '');
            const key = batchId;
            const existing = mergedBulk.get(key);
            
            // Resolve recipient names from contact list if not present
            let recipientNames = existing?.recipientNames || [];
            if (recipientNames.length === 0 && conv.members.length > 0) {
              recipientNames = conv.members.map(phone => {
                const clean = phone.replace(/\D/g, "");
                return contactMap.get(phone) || contactMap.get(clean) || phone;
              });
            }

            const item: BulkMessageHistoryItem = {
              id: existing?.id || `bulk-db-${batchId}`,
              message: conv.last_message || existing?.message || '',
              recipientCount: conv.members.length,
              recipientNames: recipientNames,
              recipientNumbers: conv.members,
              recipientKey: existing?.recipientKey || batchId,
              customName: conv.name || existing?.customName, // Prioritize server-side renamed group
              timestamp: conv.last_message_at || conv.updated_at || existing?.timestamp || new Date().toISOString(),
              status: existing?.status || 'sent',
              batchId,
              fromDatabase: true,
              locationId: conv.location_id, // Ensure location_id is used from the conversation object
            };
            mergedBulk.set(key, item);
          });

        const combined = Array.from(mergedBulk.values())
          .sort((a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime());
        setBulkHistory(combined);

        // Detect new messages and notify the Composer for immediate refresh
        const prevTracker = lastMessageTracker.current;
        const newTracker = new Map<string, string>();

        // Track direct conversations
        historyContacts.forEach(c => {
          if (c.lastMessage) newTracker.set(c.id, c.lastMessage);
        });
        // Track bulk conversations
        combined.forEach(b => {
          const convId = b.batchId ? `group_${b.batchId}` : b.id;
          if (b.message) newTracker.set(convId, b.message);
        });

        // Compare and dispatch events for changed conversations
        if (prevTracker.size > 0) {
          newTracker.forEach((msg, convId) => {
            const prev = prevTracker.get(convId);
            if (prev !== undefined && prev !== msg) {
              // This conversation has a new message
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
      } catch (err) {
        console.error('Error loading history:', err);
      }
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadContacts();
    // Real-time polling: refresh history and contacts every 15 seconds
    const interval = setInterval(() => {
      loadContacts();
    }, 15000);

    return () => clearInterval(interval);
  }, [loadContacts]);

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
      setBulkHistory(prev => prev.map(item => item.id === editingBulkId ? { ...item, customName: editingBulkName } : item));
      // Refresh list from server to ensure sync
      loadContacts();
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
    setBulkHistory(prev => prev.filter(item => item.id !== deletingBulkId));
    loadContacts();
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
        console.error('Failed to delete contact from backend:', err);
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
    setDirectHistory(prev => prev.filter(c => c.id !== id));
    loadContacts(); // fetch fresh state just in case
    setDeletingContact(null);
  };

  const cancelDeleteContact = () => setDeletingContact(null);

  const getBulkDisplayName = (item: BulkMessageHistoryItem): string => {
    const toProperCase = (name: string): string => {
      return name.replace(/\b\w/g, (char) => char.toUpperCase());
    };

    // Avoid showing auto-generated batch IDs as the "name"
    if (item.customName) {
      const looksLikeBatchId = /^batch[-_]\d+$/i.test(item.customName) || /^batch[-_]/i.test(item.customName);
      if (!looksLikeBatchId) return item.customName;
    }
    if (item.recipientNames && item.recipientNames.length > 0) {
      const names = item.recipientNames.map((n) => toProperCase(n)).filter(Boolean);
      const shown = names.slice(0, 3);
      const extra = Math.max(0, names.length - shown.length);
      return extra > 0 ? `${shown.join(", ")} +${extra}` : shown.join(", ");
    }
    return `${item.recipientCount} recipient${item.recipientCount !== 1 ? 's' : ''}`;
  };

  // Convert name to proper case (title case)
  const toProperCase = (name: string): string => {
    return name.replace(/\b\w/g, (char) => char.toUpperCase());
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
            <div className={`w-9 h-9 rounded-[10px] bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] shadow-md flex items-center justify-center transition-all duration-500 relative overflow-hidden group-hover:rotate-6 group-hover:scale-105 active:scale-95`}>
              <div className={`transition-all duration-500 ${isCollapsed ? 'group-hover:opacity-0 group-hover:scale-50' : 'group-hover:rotate-[-6deg]'}`}>
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-white" viewBox="0 0 20 20" fill="currentColor">
                  <path fillRule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clipRule="evenodd" />
                </svg>
              </div>

              {/* Rail Mode Expand Toggle (Right Collapse Icon) */}
              {isCollapsed && (
                <div className="absolute inset-0 flex items-center justify-center opacity-0 scale-50 group-hover:opacity-100 group-hover:scale-100 transition-all duration-300">
                  <TbLayoutSidebarRightCollapse className="h-6 w-6 text-white" />
                </div>
              )}
            </div>

            {!isCollapsed && (
              <div className="flex flex-col">
                <h2 className="text-[14.5px] font-extrabold text-[#111111] dark:text-white tracking-tight leading-none">
                  NOLA SMS PRO
                </h2>
                <div className="flex items-center gap-1.5 mt-0.5">
                  <span className="text-[10px] font-bold text-[#2b83fa] uppercase tracking-widest opacity-80">One Way SMS</span>
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
                className="hidden md:flex p-2 rounded-xl text-gray-400 hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-all active:scale-90"
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
                    ? 'bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 text-[#2b83fa]'
                    : 'text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03] hover:text-[#111111] dark:hover:text-[#ececf1]'}
                `}
              >
                {isActive && !isCollapsed && (
                  <div className="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-3.5 bg-[#2b83fa] rounded-r-full shadow-sm" />
                )}
                {isActive && isCollapsed && (
                  <div className="absolute left-[-4px] top-1/2 -translate-y-1/2 w-0.5 h-4 bg-[#2b83fa] rounded-r-full" />
                )}
                <div className={`text-[17px] transition-all duration-500 ${isActive ? 'scale-110 text-[#2b83fa]' : 'group-hover:scale-105 group-hover:text-[#2b83fa]'} active:scale-90`}>
                  {item.icon}
                </div>
                {isCollapsed ? (
                  <span className={`text-[9px] font-semibold leading-none tracking-tight ${isActive ? 'text-[#2b83fa]' : 'text-[#9aa0a6] dark:text-[#5f6368]'}`}>
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
            {!loading && directHistory.length === 0 && bulkHistory.length === 0 ? (
              <div className="flex-1 flex flex-col items-center justify-center p-6 text-center">
                <div className="w-16 h-16 rounded-[20px] bg-[#2b83fa]/10 flex items-center justify-center mb-4 relative group">
                  <div className="absolute inset-0 bg-[#2b83fa]/20 rounded-[20px] blur-xl opacity-50 group-hover:opacity-100 transition-opacity duration-500"></div>
                  <FiPlus className="w-8 h-8 text-[#2b83fa] relative z-10" />
                </div>
                <h3 className="text-[14px] font-bold text-[#111111] dark:text-white mb-1.5">Start a new conversation</h3>
                <p className="text-[11.5px] leading-relaxed text-gray-500 dark:text-gray-400 max-w-[160px]">
                  Click the <span className="text-[#2b83fa] font-bold">New Message</span> button above to send your first SMS.
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
                    <span className="text-[12px] font-semibold text-[#5f6368] dark:text-[#9aa0a6]">Direct Messages</span>
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
                    {loading ? (
                      [1, 2, 3, 4].map(i => <SidebarSkeleton key={i} />)
                    ) : (
                      directHistory.map(contact => (
                        <div
                          key={contact.id}
                          className={`
                          group relative transition-all duration-200 overflow-visible
                          px-3 py-2 rounded-xl cursor-pointer mx-1
                          ${activeContactId === contact.id
                              ? 'bg-[#2b83fa]/8 dark:bg-[#2b83fa]/12'
                              : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'}
                        `}
                          onClick={() => {
                            onTabChange('compose');
                            onSelectContact(contact);
                          }}
                        >
                          <div className="flex items-center gap-3">
                            <div className="relative flex-shrink-0">
                              <div className={`w-9 h-9 rounded-full flex items-center justify-center font-bold text-[13px] transition-all duration-200
                            ${activeContactId === contact.id
                                  ? 'bg-[#2b83fa] text-white shadow-md shadow-blue-500/20'
                                  : 'bg-[#f0f2f4] dark:bg-[#2a2b32] text-[#5f6368] dark:text-[#9aa0a6]'}
                            `}>
                                {(contact.name || contact.phone).charAt(0).toUpperCase()}
                              </div>
                              {activeContactId === contact.id && (
                                <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-green-500 border-2 border-white dark:border-[#121415]" />
                              )}
                            </div>
                            <div className="flex-1 min-w-0">
                              <div className="flex justify-between items-center mb-0.5">
                                <span className={`text-[13px] truncate leading-tight ${activeContactId === contact.id ? 'font-semibold text-[#111111] dark:text-white' : 'font-medium text-[#3c4043] dark:text-[#e8eaed]'}`}>
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
                              <div className={`text-[11.5px] truncate leading-snug ${activeContactId === contact.id ? 'text-[#2b83fa]/80 dark:text-[#60a5fa]/80' : 'text-[#9aa0a6] dark:text-[#5f6368]'}`}>
                                {contact.lastMessage || 'No messages yet'}
                              </div>
                            </div>
                          </div>
                        </div>
                      ))
                    )}
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
                    <span className="text-[12px] font-semibold text-[#5f6368] dark:text-[#9aa0a6]">Bulk Messages</span>
                  </div>
                  <span className="text-[10px] font-bold text-[#9aa0a6] dark:text-[#5f6368] bg-black/5 dark:bg-white/5 px-1.5 py-0.5 rounded-full min-w-[18px] text-center">{bulkHistory.length}</span>
                </div>

                {/* Bulk Messages scrollable area */}
                <div className={`overflow-y-auto overflow-x-hidden sidebar-scroll transition-all duration-300 pb-2 rounded-xl ${bulkMessagesExpanded ? 'max-h-[30vh] opacity-100' : 'max-h-0 opacity-0'}`}>
                  <div className="flex flex-col gap-0.5 py-0.5">
                    {loading ? (
                      [1, 2, 3].map(i => <SidebarSkeleton key={i} />)
                    ) : bulkHistory.length > 0 ? (
                      bulkHistory.map(item => {
                        const isActive = activeBulkMessageId === item.id;
                        return (
                          <div
                            key={item.id}
                            className={`
                              group relative transition-all duration-200 rounded-xl mx-1
                              px-3 py-2 cursor-pointer overflow-visible
                              ${isActive
                                ? 'bg-[#2b83fa]/8 dark:bg-[#2b83fa]/12'
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
                            <div className="flex items-center gap-3 overflow-visible">
                              <div className="relative flex-shrink-0">
                                <div className={`w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200
                                  ${isActive
                                    ? 'bg-[#2b83fa] text-white shadow-md shadow-blue-500/20'
                                    : 'bg-blue-50 dark:bg-[#2b83fa]/15 text-[#2b83fa] group-hover:bg-blue-100 dark:group-hover:bg-[#2b83fa]/25'
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
                                  <div className={`text-[11.5px] truncate leading-snug ${isActive ? 'text-[#2b83fa]/80 dark:text-[#60a5fa]/80' : 'text-[#9aa0a6] dark:text-[#5f6368]'}`}>
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
            flex items-center rounded-xl transition-all duration-200 group relative
            ${isCollapsed ? 'w-10 h-10 justify-center' : 'w-full gap-3 px-3 py-2'}
            text-[#9aa0a6] dark:text-[#5f6368] hover:bg-[#2b83fa]/5 dark:hover:bg-[#2b83fa]/10 hover:text-[#2b83fa]
          `}
          title="Getting Started"
        >
          <div className="relative flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 transition-transform duration-300 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
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
              ? 'bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 text-[#2b83fa]'
              : 'text-[#9aa0a6] dark:text-[#5f6368] hover:bg-black/[0.04] dark:hover:bg-white/[0.04] hover:text-[#5f6368] dark:hover:text-[#9aa0a6]'}
          `}
        >
          <div className={`transition-all duration-300 ${activeTab === 'settings' ? 'scale-110 rotate-45 text-[#2b83fa]' : 'group-hover:scale-110 group-hover:rotate-45'}`}>
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
            title="Sign out"
          >
            <div className="transition-transform duration-300 group-hover:-translate-x-0.5">
              <FiLogOut className="h-4 w-4" />
            </div>
            {!isCollapsed && <span className="font-medium text-[13px]">Sign out</span>}
          </button>
        )}
      </div>

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
                className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-black/20 text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/50 transition-all font-medium"
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
                className="px-5 py-2 rounded-xl text-sm font-bold text-white bg-[#2b83fa] hover:bg-[#1d6ee6] shadow-sm transition-all"
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
