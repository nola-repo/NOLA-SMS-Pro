import { useEffect, useState, useCallback, useRef } from "react";
import { fetchContacts } from "../api/contacts";
import { fetchAllBulkMessages, fetchConversations } from "../api/sms";
import { useAuth } from "../context/AuthContext";
import type { Contact } from "../types/Contact";
import type { BulkMessageHistoryItem } from "../types/Sms";
import { getBulkMessageHistory, renameBulkMessage, deleteBulkMessage, deleteContact, getDeletedContactIds } from "../utils/storage";
import { TbLayoutSidebarLeftCollapse, TbLayoutSidebarRightCollapse } from "react-icons/tb";
import { FiUsers, FiChevronDown, FiMoreVertical, FiTrash2, FiEdit2 } from "react-icons/fi";

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
  const { role } = useAuth();
  
  const [isRefreshing, setIsRefreshing] = useState(false);
  const contactsListRef = useRef<HTMLDivElement>(null);

  const loadContacts = useCallback(async (showSpinner = false) => {
    if (showSpinner) setIsRefreshing(true);
    try {
      const data = await fetchContacts();
      const deletedIds = getDeletedContactIds();
      const filtered = data.filter(c => !deletedIds.includes(c.id));
      setContacts(filtered);

      // Load bulk messages logic
      try {
        const mergedBulk = new Map<string, BulkMessageHistoryItem>();
        getBulkMessageHistory().forEach(msg => {
          const key = msg.batchId || msg.id;
          mergedBulk.set(key, msg);
        });

        try {
          const dbBulkMessages = await fetchAllBulkMessages();
          dbBulkMessages.forEach(msg => {
            const key = msg.batchId || msg.id;
            if (!mergedBulk.has(key)) mergedBulk.set(key, msg);
          });
        } catch { /* ignore */ }

        try {
          const conversations = await fetchConversations();
          conversations
            .filter(c => c.type === 'bulk')
            .forEach(conv => {
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
        } catch { /* ignore */ }

        const combined = Array.from(mergedBulk.values())
          .sort((a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime());
        setBulkHistory(combined);
      } catch (bulkErr) {
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
    if (role !== 'agency') {
        const contactInterval = setInterval(() => loadContacts(false), 5000);
        return () => clearInterval(contactInterval);
    }
  }, [loadContacts, role]);

  const getBulkDisplayName = (item: BulkMessageHistoryItem): string => {
    if (item.customName) return item.customName;
    if (item.recipientNames && item.recipientNames.length > 0) {
      return item.recipientNames.join(", ");
    }
    return `${item.recipientCount} recipient${item.recipientCount !== 1 ? 's' : ''}`;
  };

  const toProperCase = (name: string): string => {
    return name.replace(/\b\w/g, (char) => char.toUpperCase());
  };

  const navItems = [
    {
      id: 'compose',
      label: role === 'agency' ? 'Subaccounts' : 'Compose',
      icon: (
        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        </svg>
      )
    },
    {
      id: 'contacts',
      label: role === 'agency' ? 'SIM Controls' : 'Contacts',
      icon: (
        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
        </svg>
      )
    },
    {
      id: 'templates',
      label: role === 'agency' ? 'Logs' : 'Templates',
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
      ${isCollapsed ? 'w-20' : 'w-[280px]'}
    `}>
      {/* Header Profile / Logo Area */}
      <div className={`p-5 pb-2 ${isCollapsed ? 'px-0 flex flex-col items-center' : ''}`}>
        <div className={`flex items-center justify-between transition-all ${isCollapsed ? 'mb-6 justify-center' : 'mb-8'}`}>
          <div
            className={`flex items-center relative group cursor-pointer transition-all ${isCollapsed ? '' : 'gap-3.5'}`}
            onClick={isCollapsed ? onToggleCollapse : undefined}
          >
            <div className={`w-10 h-10 rounded-[12px] bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex items-center justify-center transition-all duration-500 relative overflow-hidden`}>
              <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6 text-white" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clipRule="evenodd" />
              </svg>
            </div>

            {!isCollapsed && (
              <div className="flex flex-col">
                <h2 className="text-[16px] font-extrabold text-[#111111] dark:text-white tracking-tight leading-none">NOLA SMS Pro</h2>
              </div>
            )}
          </div>
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
                    ? `bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 text-[#2b83fa]`
                    : 'text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03] hover:text-[#111111] dark:hover:text-[#ececf1]'}
                `}
              >
                {isActive && !isCollapsed && (
                  <div className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-5 bg-[#2b83fa] rounded-r-full shadow-[0_0_8px_rgba(43,131,250,0.5)]"></div>
                )}
                <div className={`transition-all duration-500 ${isActive ? 'scale-125 text-[#2b83fa]' : 'group-hover:scale-110 group-hover:text-[#2b83fa]'} active:scale-90`}>
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

      {/* Activity Feed Section - HIDDEN FOR AGENCY USERS */}
      {role !== 'agency' && (
        <div className={`flex-1 min-h-0 flex flex-col mt-4 ${isCollapsed ? 'items-center' : ''}`}>
          {!isCollapsed && (
            <div className="flex-1 flex flex-col px-2 pb-4 overflow-y-auto custom-scrollbar">
              <div className="px-2 py-2 pt-4 border-t border-[#00000005] dark:border-[#ffffff05] sticky top-0 bg-white/70 dark:bg-[#121415]/80 backdrop-blur-xl z-10">
                <h2 className="text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Messages</h2>
              </div>

              {/* Direct Messages */}
              <div
                className="flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg hover:bg-black/[0.04] dark:hover:bg-white/[0.04] cursor-pointer mt-2"
                onClick={() => setDirectMessagesExpanded(!directMessagesExpanded)}
              >
                <div className="flex items-center gap-2">
                  <FiChevronDown className={`w-4 h-4 text-[#5f6368] transition-transform ${directMessagesExpanded ? '' : '-rotate-90'}`} />
                  <h3 className="text-[13px] font-semibold text-[#3c4043] dark:text-[#e8eaed]">Direct Messages</h3>
                </div>
                <span className="text-[11px] font-medium text-[#5f6368] bg-[#f1f3f4] dark:bg-[#3c4043] px-1.5 py-0.5 rounded">{contacts.length}</span>
              </div>
              
              {directMessagesExpanded && (
                <div ref={contactsListRef} className="flex flex-col gap-0.5 mt-1">
                  {contacts.map(contact => (
                    <div
                      key={contact.id}
                      className={`px-3 py-3 rounded-2xl cursor-pointer mb-0.5 ${activeContactId === contact.id ? 'bg-white dark:bg-[#1c1e21] shadow-sm ring-1 ring-black/5' : 'hover:bg-black/[0.015]'}`}
                      onClick={() => { onTabChange('compose'); onSelectContact(contact); }}
                    >
                      <div className="flex items-center gap-3">
                        <div className={`w-10 h-10 rounded-2xl flex items-center justify-center font-bold text-[14px] ${activeContactId === contact.id ? 'bg-[#2b83fa] text-white' : 'bg-[#f0f0f0] dark:bg-[#202123]'}`}>
                            {contact.name.charAt(0).toUpperCase()}
                        </div>
                        <div className="flex-1 min-w-0">
                          <span className={`text-[13.5px] truncate block ${activeContactId === contact.id ? 'font-bold' : 'font-semibold'}`}>
                            {toProperCase(contact.name)}
                          </span>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              {/* Bulk Messages */}
              <div
                className="flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg hover:bg-black/[0.04] dark:hover:bg-white/[0.04] cursor-pointer mt-4"
                onClick={() => setBulkMessagesExpanded(!bulkMessagesExpanded)}
              >
                <div className="flex items-center gap-2">
                  <FiChevronDown className={`w-4 h-4 text-[#5f6368] transition-transform ${bulkMessagesExpanded ? '' : '-rotate-90'}`} />
                  <h3 className="text-[13px] font-semibold text-[#3c4043] dark:text-[#e8eaed]">Bulk Messages</h3>
                </div>
                <span className="text-[11px] font-medium text-[#5f6368] bg-[#f1f3f4] dark:bg-[#3c4043] px-1.5 py-0.5 rounded">{bulkHistory.length}</span>
              </div>

              {bulkMessagesExpanded && (
                <div className="flex flex-col gap-0.5 mt-1">
                  {bulkHistory.map(item => (
                    <div
                      key={item.id}
                      className={`px-2 py-2 rounded-lg mx-1 cursor-pointer ${activeBulkMessageId === item.id ? 'bg-white dark:bg-[#1c1e21] shadow-sm' : 'hover:bg-[#f1f3f4]'}`}
                      onClick={() => { onTabChange('compose'); onSelectBulkMessage?.(item); }}
                    >
                        <div className="flex items-center gap-3">
                          <div className={`w-8 h-8 rounded-full flex items-center justify-center ${activeBulkMessageId === item.id ? 'bg-[#7c3aed] text-white' : 'bg-[#ede9fe] text-[#7c3aed]'}`}>
                              <FiUsers className="w-4 h-4" />
                          </div>
                          <span className={`text-[13px] truncate font-medium ${activeBulkMessageId === item.id ? 'font-bold' : ''}`}>
                            {getBulkDisplayName(item)}
                          </span>
                        </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* Settings Footer */}
      <div className={`p-4 bg-white/90 dark:bg-[#121415]/90 border-t border-[#0000000a] dark:border-[#ffffff0a] ${isCollapsed ? 'px-2 flex justify-center' : ''}`}>
        <button
          onClick={() => onTabChange('settings')}
          className={`flex items-center rounded-xl transition-all duration-300 ${isCollapsed ? 'w-12 h-12 justify-center' : 'w-full gap-3 px-3 py-2.5'} ${activeTab === 'settings' ? 'bg-[#2b83fa]/10 text-[#2b83fa]' : 'text-[#6e6e73]'}`}
        >
          <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          {!isCollapsed && <span className="font-semibold text-[13px]">Settings</span>}
        </button>
      </div>
    </div>
  );
};
