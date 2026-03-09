import { useState, useEffect } from "react";
import type { Contact } from "../types/Contact";
import type { BulkMessageHistoryItem } from "../types/Sms";
import { Sidebar } from "../components/Sidebar";
import type { ViewTab } from "../components/Sidebar";
import { Composer } from "../components/Composer";
import { ContactsTab } from "../components/ContactsTab";
import { Settings } from "./Settings";
import { TbLayoutSidebarRightCollapse } from "react-icons/tb";
import { FiMenu } from "react-icons/fi";

interface DashboardProps {
  isMobileMenuOpen?: boolean;
  onMobileMenuToggle?: () => void;
  darkMode?: boolean;
  toggleDarkMode?: () => void;
}

export const Dashboard: React.FC<DashboardProps> = ({ isMobileMenuOpen: externalIsMobileMenuOpen, onMobileMenuToggle, darkMode, toggleDarkMode }) => {
  const [activeContact, setActiveContact] = useState<Contact | null>(() => {
    try {
      const saved = localStorage.getItem('nola_active_contact');
      return saved ? JSON.parse(saved) : null;
    } catch { return null; }
  });
  const [activeBulkMessage, setActiveBulkMessage] = useState<BulkMessageHistoryItem | null>(() => {
    try {
      const saved = localStorage.getItem('nola_active_bulk_message');
      return saved ? JSON.parse(saved) : null;
    } catch { return null; }
  });
  const [selectedContacts, setSelectedContacts] = useState<Contact[]>(() => {
    try {
      const saved = localStorage.getItem('nola_active_contact');
      const contact = saved ? JSON.parse(saved) : null;
      return contact ? [contact] : [];
    } catch { return []; }
  });
  const [currentView, setCurrentView] = useState<ViewTab>(
    () => (localStorage.getItem('nola_active_tab') as ViewTab) || 'compose'
  );
  const [settingsTab, setSettingsTab] = useState<"account" | "senderIds" | "api" | "notifications" | "credits" | undefined>(undefined);
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
  const [settingsOpen] = useState(false);

  const isMobileMenuOpen = externalIsMobileMenuOpen !== undefined ? externalIsMobileMenuOpen : false;

  const handleSelectContact = (contact: Contact) => {
    setSelectedContacts([contact]);
    setActiveContact(contact);
    setActiveBulkMessage(null);
    localStorage.setItem('nola_active_contact', JSON.stringify(contact));
    localStorage.removeItem('nola_active_bulk_message');
    setCurrentView('compose');
    localStorage.setItem('nola_active_tab', 'compose');
  };

  const handleSelectBulkMessage = (bulkMessage: BulkMessageHistoryItem) => {
    console.log('Selected bulk message:', bulkMessage);
    setSelectedContacts([]);
    setActiveContact(null);
    setActiveBulkMessage(bulkMessage);
    localStorage.removeItem('nola_active_contact');
    localStorage.setItem('nola_active_bulk_message', JSON.stringify(bulkMessage));
    setCurrentView('compose');
    localStorage.setItem('nola_active_tab', 'compose');
  };

  const handleSendToComposer = (contacts: Contact[]) => {
    setSelectedContacts(contacts);
    if (contacts.length === 1) {
      setActiveContact(contacts[0]);
      localStorage.setItem('nola_active_contact', JSON.stringify(contacts[0]));
    } else {
      setActiveContact(null);
      localStorage.removeItem('nola_active_contact');
    }
    setCurrentView('compose');
    localStorage.setItem('nola_active_tab', 'compose');
  };

  const handleViewMessages = (contact: Contact) => {
    setActiveContact(contact);
    setSelectedContacts([contact]);
    localStorage.setItem('nola_active_contact', JSON.stringify(contact));
    setCurrentView('compose');
    localStorage.setItem('nola_active_tab', 'compose');
  };

  const handleTabChange = (tab: ViewTab) => {
    setCurrentView(tab);
    localStorage.setItem('nola_active_tab', tab);
    if (tab === 'compose') {
      setSelectedContacts([]);
      setActiveContact(null);
      setActiveBulkMessage(null);
      localStorage.removeItem('nola_active_contact');
      localStorage.removeItem('nola_active_bulk_message');
    }
    // On mobile, close sidebar when selecting a main action area
    if (window.innerWidth < 768 && tab !== 'compose' && onMobileMenuToggle) {
      onMobileMenuToggle();
    }
  };

  const toggleMobileMenu = () => {
    if (onMobileMenuToggle) {
      onMobileMenuToggle();
    }
  };

  const toggleCollapse = () => {
    setIsSidebarCollapsed(!isSidebarCollapsed);
  };

  // Handle navigation to settings tabs from CreditBadge
  useEffect(() => {
    const handleNavigateToSettings = (e: CustomEvent) => {
      const tab = e.detail?.tab;
      if (tab) {
        setSettingsTab(tab);
        setCurrentView('settings');
        localStorage.setItem('nola_active_tab', 'settings');
      }
    };

    window.addEventListener('navigate-to-settings', handleNavigateToSettings as EventListener);
    return () => {
      window.removeEventListener('navigate-to-settings', handleNavigateToSettings as EventListener);
    };
  }, []);

  return (
    <div className="flex h-screen bg-[#ffffff] dark:bg-[#202123] overflow-visible">
      {/* Sidebar - Left */}
      <div className={`
        fixed md:relative z-[45] md:z-50 h-full transition-all duration-300 ease-in-out
        ${isMobileMenuOpen ? 'w-80 border-r border-[#0000001a] dark:border-[#ffffff1a] bg-white dark:bg-[#121415]' : 'w-0 md:w-auto'}
        ${isMobileMenuOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'}
        overflow-visible
      `}>
        <div className={`h-full transition-all duration-300 z-[60] ${isSidebarCollapsed ? 'md:w-20' : 'md:w-80 w-80'}`}>
          <Sidebar
            activeTab={currentView}
            onTabChange={handleTabChange}
            onSelectContact={handleSelectContact}
            activeContactId={activeContact?.id}
            activeBulkMessageId={activeBulkMessage?.id}
            isCollapsed={isSidebarCollapsed}
            onToggleCollapse={toggleCollapse}
            onSelectBulkMessage={handleSelectBulkMessage}
          />
        </div>
      </div>

      {/* Main Content Area */}
      <div className="flex-1 flex flex-col h-full w-full min-w-0 bg-[#f7f7f7] dark:bg-[#18191d]">
        {/* Mobile Header with Sidebar Toggle */}
        <div className="md:hidden flex items-center justify-between p-3 border-b border-[#e5e5e5] dark:border-[#2a2b32] bg-[#ffffff] dark:bg-[#202123] sticky top-0 z-30">
          <button
            onClick={toggleMobileMenu}
            className="p-2 rounded-lg hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] text-[#37352f] dark:text-[#ececf1] transition-colors"
          >
            <FiMenu className="h-5 w-5" />
          </button>
          <span className="font-semibold text-[#37352f] dark:text-[#ececf1] tracking-tight">NOLA SMS Pro</span>
          {/* Dark Mode Toggle in Mobile Header */}
          {toggleDarkMode && (
            <button
              onClick={toggleDarkMode}
              className="p-2 rounded-lg hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] text-[#37352f] dark:text-[#ececf1] transition-colors"
              aria-label="Toggle theme"
            >
              {darkMode ? (
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
              ) : (
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                </svg>
              )}
            </button>
          )}
        </div>

        {/* ChatGPT Style Expand Toggle for Desktop */}
        {isSidebarCollapsed && (
          <div className="hidden md:flex absolute top-4 left-4 z-40">
            <button
              onClick={toggleCollapse}
              className="p-2.5 rounded-xl bg-white/50 dark:bg-[#202123]/50 backdrop-blur-md border border-[#0000000a] dark:border-[#ffffff0a] text-gray-500 hover:text-[#2b83fa] shadow-sm transition-all hover:scale-110 active:scale-95 group"
              title="Expand Sidebar"
            >
              <TbLayoutSidebarRightCollapse className="h-5 w-5 transition-transform group-hover:translate-x-0.5" />
            </button>
          </div>
        )}

        {/* Content Router */}
        <div className="flex-1 h-full overflow-hidden">
          {currentView === 'compose' ? (
            <Composer
              selectedContacts={selectedContacts}
              activeContact={activeContact}
              activeBulkMessage={activeBulkMessage}
              onSelectContact={handleSelectContact}
              onSelectBulkMessage={handleSelectBulkMessage}
              onToggleMobileMenu={toggleMobileMenu}
            />
          ) : currentView === 'contacts' ? (
            <ContactsTab
              onSendToComposer={handleSendToComposer}
              onViewMessages={handleViewMessages}
            />
          ) : currentView === 'settings' || settingsOpen ? (
            <Settings
              darkMode={darkMode ?? false}
              toggleDarkMode={toggleDarkMode ?? (() => { })}
              initialTab={settingsOpen ? "senderIds" : settingsTab || undefined}
              autoOpenAddModal={settingsOpen}
            />
          ) : currentView === 'templates' ? (
            <div className="h-full flex flex-col items-center justify-center p-8 text-center bg-white/50 dark:bg-[#202123]/50 backdrop-blur-sm">
              <div className="w-20 h-20 mb-6 rounded-2xl bg-gradient-to-br from-[#2b83fa]/20 to-[#60a5fa]/20 dark:from-[#2b83fa]/10 dark:to-[#60a5fa]/10 flex items-center justify-center border border-[#2b83fa]/20">
                <svg xmlns="http://www.w3.org/2000/svg" className="h-10 w-10 text-[#2b83fa]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16.5 8.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v8.25A2.25 2.25 0 006 16.5h2.25m8.25-8.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-7.5A2.25 2.25 0 018.25 18v-1.5m8.25-8.25h-6a2.25 2.25 0 00-2.25 2.25v6" />
                </svg>
              </div>
              <h2 className="text-2xl font-bold text-[#37352f] dark:text-[#ececf1] mb-2 tracking-tight">Templates Module</h2>
              <p className="text-[#6e6e73] dark:text-[#a0a0ab] max-w-sm text-[15px]">
                This section is currently under construction. Please check back later.
              </p>
            </div>
          ) : null}
        </div>
      </div>

      {/* Mobile Overlay */}
      {isMobileMenuOpen && (
        <div
          className="fixed inset-0 bg-black/40 backdrop-blur-sm z-[45] md:hidden transition-opacity"
          onClick={toggleMobileMenu}
        />
      )}
    </div>
  );
};
