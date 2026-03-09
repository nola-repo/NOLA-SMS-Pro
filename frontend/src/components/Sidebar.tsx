import { useEffect, useState, useCallback, useRef } from "react";
import { fetchContacts } from "../api/contacts";
import { fetchAllBulkMessages, fetchConversations } from "../api/sms";
import type { Contact } from "../types/Contact";
import type { BulkMessageHistoryItem } from "../types/Sms";
import { getBulkMessageHistory, renameBulkMessage, deleteBulkMessage, deleteContact, getDeletedContactIds } from "../utils/storage";
import { TbLayoutSidebarLeftCollapse, TbLayoutSidebarRightCollapse } from "react-icons/tb";
import { FiUsers, FiChevronDown, FiEdit2, FiTrash2, FiMoreVertical } from "react-icons/fi";

export type ViewTab = 'compose' | 'contacts' | 'templates' | 'settings';

interface SidebarProps {
  activeTab: ViewTab;
  onTabChange: (tab: ViewTab) => void;
  onSelectContact: (contact: Contact) => void;
  activeContactId?: string;
  activeBulkMessageId?: string;
  isCollapsed?: boolean;
  onToggleCollapse?: () => void;
  onSelectBulkMessage?: (message: BulkMessageHistoryItem) => void;
}

export const Sidebar: React.FC<SidebarProps> = ({
  activeTab,
  onTabChange,
  onSelectContact,
  activeContactId,
  activeBulkMessageId,
  isCollapsed = false,
  onToggleCollapse,
  onSelectBulkMessage
}) => {
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [bulkHistory, setBulkHistory] = useState<BulkMessageHistoryItem[]>(() => getBulkMessageHistory());
  const [directMessagesExpanded, setDirectMessagesExpanded] = useState(true);
  const [bulkMessagesExpanded, setBulkMessagesExpanded] = useState(true);
  const [editingBulkId, setEditingBulkId] = useState<string | null>(null);
  const [editingBulkName, setEditingBulkName] = useState("");
  const [deletingContactId, setDeletingContactId] = useState<string | null>(null);
  const [openMenuId, setOpenMenuId] = useState<string | null>(null);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const touchStartY = useRef<number>(0);
  const contactsListRef = useRef<HTMLDivElement>(null);

  const loadContacts = useCallback(async (showSpinner = false) => {
    if (showSpinner) setIsRefreshing(true);
    try {
      const data = await fetchContacts();
      const deletedIds = getDeletedContactIds();
      const filtered = data.filter(c => !deletedIds.includes(c.id));
      setContacts(filtered);

      // Load bulk messages: try Firestore conversations first, fall back to sms_logs bulk endpoint
      try {
        const mergedBulk = new Map<string, BulkMessageHistoryItem>();

        // 1. Local storage (lowest priority – user renames/deletions tracked here)
        getBulkMessageHistory().forEach(msg => {
          const key = msg.batchId || msg.id;
          mergedBulk.set(key, msg);
        });

        // 2. Old sms_logs-based bulk endpoint (fallback)
        try {
          const dbBulkMessages = await fetchAllBulkMessages();
          dbBulkMessages.forEach(msg => {
            const key = msg.batchId || msg.id;
            // Only override if not already in local storage (local keeps custom names)
            if (!mergedBulk.has(key)) mergedBulk.set(key, msg);
          });
        } catch { /* ignore */ }

        // 3. New conversations collection (highest priority for server truth)
        try {
          const conversations = await fetchConversations();
          conversations
            .filter(c => c.type === 'bulk')
            .forEach(conv => {
              // conversation id format: group_batch_xxx  → batchId = batch_xxx
              const batchId = conv.id.replace(/^group_/, '');
              const key = batchId;
              const existing = mergedBulk.get(key);
              const item: BulkMessageHistoryItem = {
                id: existing?.id || `bulk-db-${batchId}`,
                message: conv.last_message || existing?.message || '',
                recipientCount: conv.members.length,
                recipientNames: existing?.recipientNames,
                recipientNumbers: conv.members,
                recipientKey: existing?.recipientKey || batchId,
                customName: existing?.customName,
                timestamp: conv.last_message_at || conv.updated_at || existing?.timestamp || new Date().toISOString(),
                status: existing?.status || 'sent',
                batchId,
                fromDatabase: true,
              };
              mergedBulk.set(key, item);
            });
        } catch { /* conversations endpoint not yet deployed – silently ignore */ }

        const combined = Array.from(mergedBulk.values())
          .sort((a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime());
        setBulkHistory(combined);
      } catch (bulkErr) {
        console.error('Error loading bulk messages:', bulkErr);
        setBulkHistory(getBulkMessageHistory());
      }
    } catch (e) {
      console.error(e);
    } finally {
      if (showSpinner) setIsRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadContacts();

    // Poll for new contacts every 5 seconds
    const contactInterval = setInterval(() => {
      loadContacts(false);
    }, 5000);

    // Listen for bulk message sent events to refresh history
    const handleBulkMessageSent = () => {
      setBulkHistory(getBulkMessageHistory());
    };
    window.addEventListener('bulk-message-sent', handleBulkMessageSent);

    return () => {
      clearInterval(contactInterval);
      window.removeEventListener('bulk-message-sent', handleBulkMessageSent);
    };
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
  };

  const handleSaveEdit = (id: string) => {
    if (editingBulkName.trim()) {
      renameBulkMessage(id, editingBulkName.trim());
      setBulkHistory(getBulkMessageHistory());
    }
    setEditingBulkId(null);
    setEditingBulkName("");
  };

  const handleDelete = (id: string, e: React.MouseEvent) => {
    e.stopPropagation();
    deleteBulkMessage(id);
    setBulkHistory(getBulkMessageHistory());
  };

  const handleDeleteContact = (id: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setDeletingContactId(id);
  };

  const confirmDeleteContact = () => {
    if (deletingContactId) {
      deleteContact(deletingContactId);
      setContacts(contacts.filter(c => c.id !== deletingContactId));
      setDeletingContactId(null);
    }
  };

  const cancelDeleteContact = () => {
    setDeletingContactId(null);
  };

  const getBulkDisplayName = (item: BulkMessageHistoryItem): string => {
    if (item.customName) return item.customName;
    if (item.recipientNames && item.recipientNames.length > 0) {
      return item.recipientNames.join(", ");
    }
    return `${item.recipientCount} recipient${item.recipientCount !== 1 ? 's' : ''}`;
  };

  // Convert name to proper case (title case)
  const toProperCase = (name: string): string => {
    return name.replace(/\b\w/g, (char) => char.toUpperCase());
  };

  const navItems = [
    {
      id: 'compose',
      label: 'Compose',
      icon: (
        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
        </svg>
      )
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
      h-full bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl flex-shrink-0 flex flex-col border-r border-[#0000000a] dark:border-[#ffffff0a] shadow-[1px_0_0_rgba(0,0,0,0.05)] relative z-[60] transition-all duration-300
      ${isCollapsed ? 'w-20' : 'w-full'}
    `}>
      {/* Header Profile / Logo Area */}
      <div className={`p-5 pb-2 ${isCollapsed ? 'px-0 flex flex-col items-center' : ''}`}>
        <div className={`flex items-center justify-between transition-all ${isCollapsed ? 'mb-6 justify-center' : 'mb-8'}`}>
          <div
            className={`flex items-center relative group cursor-pointer transition-all ${isCollapsed ? '' : 'gap-3.5'}`}
            onClick={isCollapsed ? onToggleCollapse : undefined}
          >
            <div className={`w-10 h-10 rounded-[12px] bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] shadow-[0_4px_12px_rgba(43,131,250,0.3)] flex items-center justify-center transition-all duration-500 relative overflow-hidden group-hover:rotate-6 group-hover:scale-105 active:scale-95`}>
              <div className={`transition-all duration-500 ${isCollapsed ? 'group-hover:opacity-0 group-hover:scale-50' : 'group-hover:rotate-[-6deg]'}`}>
                <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6 text-white" viewBox="0 0 20 20" fill="currentColor">
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
                <h2 className="text-[16px] font-extrabold text-[#111111] dark:text-white tracking-tight leading-none">NOLA SMS Pro</h2>
                <div className="flex items-center gap-1.5 mt-1">
                  <span className="text-[11px] font-bold text-[#2b83fa] uppercase tracking-widest opacity-80">One Way SMS</span>
                </div>
              </div>
            )}
          </div>

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

        {/* Navigation List */}
        <nav className={`flex flex-col gap-1 mt-2 ${isCollapsed ? 'items-center px-2' : ''}`}>
          {navItems.map((item) => {
            const isActive = activeTab === item.id;
            return (
              <button
                key={item.id}
                onClick={() => onTabChange(item.id as ViewTab)}
                className={`
                  flex items-center transition-all duration-300 relative group
                  ${isCollapsed ? 'w-12 h-12 justify-center rounded-2xl' : 'w-full gap-3 px-3 py-2.5 rounded-xl'}
                  ${isActive
                    ? `bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 text-[#2b83fa] ${isCollapsed ? '' : ''}`
                    : 'text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03] hover:text-[#111111] dark:hover:text-[#ececf1]'}
                `}
              >
                {isActive && !isCollapsed && (
                  <div className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-5 bg-[#2b83fa] rounded-r-full shadow-[0_0_8px_rgba(43,131,250,0.5)]"></div>
                )}
                {isActive && isCollapsed && (
                  <div className="absolute left-[-8px] top-1/2 -translate-y-1/2 w-1 h-6 bg-[#2b83fa] rounded-r-full"></div>
                )}
                <div className={`transition-all duration-500 ${isActive ? 'scale-125 text-[#2b83fa] drop-shadow-[0_0_8px_rgba(43,131,250,0.4)]' : 'group-hover:scale-110 group-hover:text-[#2b83fa]'} active:scale-90`}>
                  {item.icon}
                </div>
                {!isCollapsed && (
                  <span className={`text-[13.5px] transition-all duration-200 ${isActive ? 'font-bold tracking-tight' : 'font-medium'}`}>
                    {item.label}
                  </span>
                )}
              </button>
            );
          })}
        </nav>
      </div>

      {/* Activity Feed Section */}
      <div className={`flex-1 min-h-0 flex flex-col mt-4 ${isCollapsed ? 'items-center' : ''}`}>
        {!isCollapsed && (
          <div className="flex-1 flex flex-col px-2 pb-4">
            {/* Messages Section Header - Sticky at top */}
            <div className="px-2 py-2 pt-4 border-t border-[#00000005] dark:border-[#ffffff05] sticky top-0 bg-white/70 dark:bg-[#121415]/80 backdrop-blur-xl z-10">
              <h2 className="text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Messages</h2>
            </div>

            {/* Direct Messages Header - Sticky */}
            <div
              className="flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg hover:bg-black/[0.04] dark:hover:bg-white/[0.04] transition-colors cursor-pointer border-t border-[#00000005] dark:border-[#ffffff05] pt-4 sticky top-[41px] bg-white/70 dark:bg-[#121415]/80 backdrop-blur-xl z-10"
              onClick={() => setDirectMessagesExpanded(!directMessagesExpanded)}
            >
              <div className="flex items-center gap-2">
                <div className={`transition-transform duration-200 ${directMessagesExpanded ? 'rotate-0' : '-rotate-90'}`}>
                  <FiChevronDown className="w-4 h-4 text-[#5f6368] dark:text-[#9aa0a6]" />
                </div>
                <h3 className="text-[13px] font-semibold text-[#3c4043] dark:text-[#e8eaed]">Direct Messages</h3>
              </div>
              <span className="text-[11px] font-medium text-[#5f6368] dark:text-[#9aa0a6] bg-[#f1f3f4] dark:bg-[#3c4043] px-1.5 py-0.5 rounded">{contacts.length}</span>
            </div>

            {/* Direct Messages Content - Pull to refresh + Independent scrollable area */}
            <div
              ref={contactsListRef}
              className={`overflow-y-auto overflow-visible custom-scrollbar transition-all duration-300 pb-4 ${directMessagesExpanded ? 'max-h-[40vh] opacity-100 mb-2' : 'max-h-0 opacity-0'}`}
              onTouchStart={(e) => { touchStartY.current = e.touches[0].clientY; }}
              onTouchEnd={(e) => {
                const delta = e.changedTouches[0].clientY - touchStartY.current;
                const atTop = (contactsListRef.current?.scrollTop ?? 0) === 0;
                if (delta > 60 && atTop) loadContacts(true);
              }}
            >
              {/* Pull-to-refresh indicator */}
              {isRefreshing && (
                <div className="flex justify-center items-center py-2">
                  <svg className="animate-spin h-4 w-4 text-[#2b83fa]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                  </svg>
                </div>
              )}
              <div className="flex flex-col gap-0.5">
                {contacts.map(contact => (
                  <div
                    key={contact.id}
                    className={`
                     group relative transition-all duration-300 overflow-visible
                     px-3 py-3 rounded-2xl cursor-pointer mb-0.5
                     ${activeContactId === contact.id
                        ? 'bg-white dark:bg-[#1c1e21] shadow-[0_4px_15px_rgba(0,0,0,0.05)] dark:shadow-[0_10px_30px_rgba(0,0,0,0.3)] ring-1 ring-[#00000005] dark:ring-[#ffffff05]'
                        : 'hover:bg-black/[0.015] dark:hover:bg-white/[0.015]'}
                  `}
                    onClick={() => {
                      onTabChange('compose');
                      onSelectContact(contact);
                    }}
                  >
                    <div className="flex items-center gap-3.5">
                      <div className="relative flex-shrink-0">
                        <div className={`w-10 h-10 rounded-2xl flex items-center justify-center font-bold text-[14px] transition-all duration-300 shadow-inner
                        ${activeContactId === contact.id
                            ? 'bg-[#2b83fa] text-white shadow-[0_4px_8px_rgba(43,131,250,0.2)]'
                            : 'bg-[#f0f0f0] dark:bg-[#202123] text-[#6e6e73] dark:text-[#ececf1] group-hover:bg-[#e8e8e8] dark:group-hover:bg-[#25262a]'}
                      `}>
                          {(() => {
                            const parts = contact.name.trim().split(/\s+/);
                            const firstInitial = parts[0]?.charAt(0) || '';
                            const lastInitial = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
                            return (firstInitial + lastInitial).toUpperCase();
                          })()}
                        </div>
                        <span className={`absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full border-2 border-white dark:border-[#121415] shadow-sm transition-opacity duration-300
                        ${activeContactId === contact.id ? 'bg-green-500 opacity-100' : 'bg-gray-300 dark:bg-gray-600 opacity-0 group-hover:opacity-100'}
                      `}></span>
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex justify-between items-baseline mb-0.5">
                          <span className={`text-[13.5px] truncate transition-colors duration-200 ${activeContactId === contact.id ? 'font-bold text-[#111111] dark:text-white' : 'font-semibold text-[#37352f] dark:text-[#ececf1]'}`}>
                            {toProperCase(contact.name)}
                          </span>
                          <div className="flex items-center gap-1">
                            <span className="text-[9px] font-bold text-[#b4b4b4] dark:text-[#55565a] uppercase tracking-tighter">2m</span>
                            {deletingContactId === contact.id ? (
                              <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
                                <button
                                  onClick={confirmDeleteContact}
                                  className="p-1 rounded bg-red-500 text-white hover:bg-red-600"
                                >
                                  <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                  </svg>
                                </button>
                                <button
                                  onClick={cancelDeleteContact}
                                  className="p-1 rounded bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-400 dark:hover:bg-gray-500"
                                >
                                  <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                  </svg>
                                </button>
                              </div>
                            ) : (
                              <div className="relative">
                                <button
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    setOpenMenuId(openMenuId === contact.id ? null : contact.id);
                                  }}
                                  className="p-1 rounded hover:bg-[#e8e8e8] dark:hover:bg-[#3c4043]"
                                >
                                  <FiMoreVertical className="w-3 h-3 text-[#5f6368] dark:text-[#9aa0a6]" />
                                </button>
                                {openMenuId === contact.id && (
                                  <div
                                    className="absolute right-0 top-full mt-1 bg-white dark:bg-[#2d2d2d] rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 py-1 min-w-[100px] z-[9999] overflow-visible"
                                    onClick={(e) => e.stopPropagation()}
                                  >
                                    <button
                                      onClick={(e) => {
                                        e.stopPropagation();
                                        handleDeleteContact(contact.id, e);
                                        setOpenMenuId(null);
                                      }}
                                      className="w-full px-3 py-1.5 text-left text-[12px] text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2"
                                    >
                                      <FiTrash2 className="w-3 h-3" />
                                      Delete
                                    </button>
                                  </div>
                                )}
                              </div>
                            )}
                          </div>
                        </div>
                        <div className={`text-[11.5px] truncate leading-tight transition-colors duration-200 ${activeContactId === contact.id ? 'text-[#6e6e73] dark:text-[#a0a0ab]' : 'text-[#a2a2a7] dark:text-[#6e6e73]'}`}>
                          {contact.lastMessage || `Click to message ${contact.phone}`}
                        </div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div> {/* end contacts scrollable */}

            {/* Bulk Messages Header */}
            <>
              <div
                className="flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg hover:bg-black/[0.04] dark:hover:bg-white/[0.04] transition-colors cursor-pointer mt-1"
                onClick={() => setBulkMessagesExpanded(!bulkMessagesExpanded)}
              >
                <div className="flex items-center gap-2">
                  <div className={`transition-transform duration-200 ${bulkMessagesExpanded ? 'rotate-0' : '-rotate-90'}`}>
                    <FiChevronDown className="w-4 h-4 text-[#5f6368] dark:text-[#9aa0a6]" />
                  </div>
                  <h3 className="text-[13px] font-semibold text-[#3c4043] dark:text-[#e8eaed]">Bulk Messages</h3>
                </div>
                <span className="text-[11px] font-medium text-[#5f6368] dark:text-[#9aa0a6] bg-[#f1f3f4] dark:bg-[#3c4043] px-1.5 py-0.5 rounded">{bulkHistory.length}</span>
              </div>

              {/* Bulk Messages Content - Independent scrollable area */}
              <div className={`overflow-y-auto overflow-visible custom-scrollbar transition-all duration-300 pb-24 ${bulkMessagesExpanded ? 'max-h-none opacity-100' : 'max-h-0 opacity-0'}`}>
                <div className="flex flex-col gap-0.5">
                  {bulkHistory.length > 0 ? (
                    bulkHistory.map(item => (
                      <div
                        key={item.id}
                        className={`
                          group relative transition-all duration-200 rounded-lg mx-1
                          px-2 py-2 cursor-pointer overflow-visible
                          ${activeBulkMessageId === item.id
                            ? 'bg-white dark:bg-[#1c1e21] shadow-[0_4px_15px_rgba(0,0,0,0.05)] dark:shadow-[0_10px_30px_rgba(0,0,0,0.3)] ring-1 ring-[#00000005] dark:ring-[#ffffff05]'
                            : 'hover:bg-[#f1f3f4] dark:hover:bg-[#303134]'
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
                            <div className={`w-8 h-8 rounded-full flex items-center justify-center transition-all duration-200
                              ${activeBulkMessageId === item.id
                                ? 'bg-[#7c3aed] text-white shadow-[0_4px_8px_rgba(124,58,237,0.2)]'
                                : 'bg-[#ede9fe] text-[#7c3aed] group-hover:bg-[#ddd6fe]'
                              }
                            `}>
                              <FiUsers className="w-4 h-4" />
                            </div>
                          </div>
                          <div className="flex-1 min-w-0 overflow-visible">
                            {editingBulkId === item.id ? (
                              <input
                                type="text"
                                value={editingBulkName}
                                onChange={(e) => setEditingBulkName(e.target.value)}
                                onBlur={() => handleSaveEdit(item.id)}
                                onKeyDown={(e) => {
                                  if (e.key === 'Enter') handleSaveEdit(item.id);
                                  if (e.key === 'Escape') setEditingBulkId(null);
                                }}
                                onClick={(e) => e.stopPropagation()}
                                autoFocus
                                className="w-full text-[13px] font-medium text-[#3c4043] dark:text-[#e8eaed] bg-white dark:bg-[#3c4043] px-1 py-0.5 rounded border border-[#2b83fa] outline-none"
                              />
                            ) : (
                              <>
                                <div className="flex justify-between items-baseline overflow-visible">
                                  <span className={`text-[13px] truncate font-medium ${activeBulkMessageId === item.id ? 'font-bold text-[#111111] dark:text-white' : 'text-[#3c4043] dark:text-[#e8eaed]'}`}>
                                    {getBulkDisplayName(item)}
                                  </span>
                                  <div className="flex items-center gap-1 overflow-visible">
                                    <div className="relative">
                                      <button
                                        onClick={(e) => {
                                          e.stopPropagation();
                                          setOpenMenuId(openMenuId === item.id ? null : item.id);
                                        }}
                                        className="p-1.5 rounded hover:bg-[#e8e8e8] dark:hover:bg-[#3c4043]"
                                      >
                                        <FiMoreVertical className="w-4 h-4 text-[#5f6368] dark:text-[#9aa0a6]" />
                                      </button>
                                      {openMenuId === item.id && (
                                        <div
                                          className="absolute right-0 top-full mt-1 bg-white dark:bg-[#2d2d2d] rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 py-1 min-w-[100px] z-[9999] overflow-visible"
                                          onClick={(e) => e.stopPropagation()}
                                        >
                                          <button
                                            onClick={(e) => {
                                              e.stopPropagation();
                                              handleStartEdit(item);
                                              setOpenMenuId(null);
                                            }}
                                            className="w-full px-3 py-1.5 text-left text-[12px] text-[#5f6368] dark:text-[#9aa0a6] hover:bg-[#f1f3f4] dark:hover:bg-[#3c4043] flex items-center gap-2"
                                          >
                                            <FiEdit2 className="w-3 h-3" />
                                            Edit
                                          </button>
                                          <button
                                            onClick={(e) => {
                                              e.stopPropagation();
                                              handleDelete(item.id, e);
                                              setOpenMenuId(null);
                                            }}
                                            className="w-full px-3 py-1.5 text-left text-[12px] text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2"
                                          >
                                            <FiTrash2 className="w-3 h-3" />
                                            Delete
                                          </button>
                                        </div>
                                      )}
                                    </div>
                                  </div>
                                </div>
                                <div className={`text-[12px] truncate leading-tight ${activeBulkMessageId === item.id ? 'text-[#6e6e73] dark:text-[#a0a0ab]' : 'text-[#5f6368] dark:text-[#9aa0a6]'}`}>
                                  {item.message.length > 30 ? item.message.substring(0, 30) + '...' : item.message}
                                </div>
                              </>
                            )}
                          </div>
                        </div>
                      </div>
                    ))
                  ) : (
                    <div className="text-[12px] text-[#9aa0a6] dark:text-[#6e6e73] px-3 py-2 italic">
                      No bulk messages yet
                    </div>
                  )}
                </div>
              </div>
            </>
          </div>
        )}
      </div>

      {/* Settings Only Footer */}
      <div className={`p-4 bg-white/90 dark:bg-[#121415]/90 border-t border-[#0000000a] dark:border-[#ffffff0a] relative z-[70] backdrop-blur-sm ${isCollapsed ? 'px-2 flex justify-center' : ''}`}>
        <button
          onClick={() => onTabChange('settings')}
          className={`
            flex items-center rounded-xl transition-all duration-300 group text-sm
            ${isCollapsed ? 'w-12 h-12 justify-center' : 'w-full gap-3 px-3 py-2.5'}
            ${activeTab === 'settings'
              ? 'bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 text-[#2b83fa]'
              : 'text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'}
          `}
        >
          <div className={`transition-all duration-500 ${activeTab === 'settings' ? 'scale-110 rotate-90 text-[#2b83fa]' : 'group-hover:scale-110 group-hover:rotate-45 group-hover:text-[#2b83fa]'}`}>
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>
          {!isCollapsed && <span className="font-semibold text-[13px]">Settings</span>}
        </button>
      </div>
    </div>
  );
};
