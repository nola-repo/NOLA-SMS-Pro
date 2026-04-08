import { safeStorage } from '../utils/safeStorage';
import { useState, useEffect } from "react";
import type { Contact } from "../types/Contact";
import type { BulkMessageHistoryItem } from "../types/Sms";
import { Sidebar } from "../components/Sidebar";
import type { ViewTab } from "../components/Sidebar";
import { Composer } from "../components/Composer";
import { ContactsTab } from "../components/ContactsTab";
import { TemplatesTab } from "../components/TemplatesTab";
import { Settings } from "./Settings";
import { FiMenu, FiSettings } from "react-icons/fi";
import { Home } from "../components/Home";
import { getAccountSettings } from "../utils/settingsStorage";
import { useOnboarding } from "../components/onboarding/useOnboarding";
import { OnboardingModal } from "../components/onboarding/OnboardingModal";

interface DashboardProps {
  isMobileMenuOpen?: boolean;
  onMobileMenuToggle?: () => void;
  darkMode?: boolean;
  toggleDarkMode?: () => void;
}

export const Dashboard: React.FC<DashboardProps> = ({ isMobileMenuOpen: externalIsMobileMenuOpen, onMobileMenuToggle, darkMode, toggleDarkMode }) => {
  const onboarding = useOnboarding();
  const [activeContact, setActiveContact] = useState<Contact | null>(() => {
    try {
      const saved = safeStorage.getItem('nola_active_contact');
      return saved ? JSON.parse(saved) : null;
    } catch { return null; }
  });
  const [activeBulkMessage, setActiveBulkMessage] = useState<BulkMessageHistoryItem | null>(() => {
    try {
      const saved = safeStorage.getItem('nola_active_bulk_message');
      return saved ? JSON.parse(saved) : null;
    } catch { return null; }
  });
  const [selectedContacts, setSelectedContacts] = useState<Contact[]>(() => {
    try {
      const saved = safeStorage.getItem('nola_active_contact');
      const contact = saved ? JSON.parse(saved) : null;
      return contact ? [contact] : [];
    } catch { return []; }
  });
  const [currentView, setCurrentView] = useState<ViewTab>(
    () => (safeStorage.getItem('nola_active_tab') as ViewTab) || 'home'
  );
  const [settingsTab, setSettingsTab] = useState<"account" | "senderIds" | "notifications" | "credits" | undefined>(undefined);
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
  const [settingsOpen] = useState(false);

  const isMobileMenuOpen = externalIsMobileMenuOpen !== undefined ? externalIsMobileMenuOpen : false;

  const handleSelectContact = (contact: Contact) => {
    setSelectedContacts([contact]);
    setActiveContact(contact);
    setActiveBulkMessage(null);
    safeStorage.setItem('nola_active_contact', JSON.stringify(contact));
    safeStorage.removeItem('nola_active_bulk_message');
    setCurrentView('compose');
    safeStorage.setItem('nola_active_tab', 'compose');
  };

  const handleSelectBulkMessage = (bulkMessage: BulkMessageHistoryItem) => {
    console.log('Selected bulk message:', bulkMessage);
    setSelectedContacts([]);
    setActiveContact(null);
    setActiveBulkMessage(bulkMessage);
    safeStorage.removeItem('nola_active_contact');
    safeStorage.setItem('nola_active_bulk_message', JSON.stringify(bulkMessage));
    setCurrentView('compose');
    safeStorage.setItem('nola_active_tab', 'compose');
  };

  const handleSendToComposer = (contacts: Contact[]) => {
    setSelectedContacts(contacts);
    if (contacts.length === 1) {
      setActiveContact(contacts[0]);
      safeStorage.setItem('nola_active_contact', JSON.stringify(contacts[0]));
    } else {
      setActiveContact(null);
      safeStorage.removeItem('nola_active_contact');
    }
    setCurrentView('compose');
    safeStorage.setItem('nola_active_tab', 'compose');
  };

  const handleViewMessages = (contact: Contact) => {
    setActiveContact(contact);
    setSelectedContacts([contact]);
    setActiveBulkMessage(null);
    safeStorage.setItem('nola_active_contact', JSON.stringify(contact));
    safeStorage.removeItem('nola_active_bulk_message');
    setCurrentView('compose');
    safeStorage.setItem('nola_active_tab', 'compose');
  };

  const handleTabChange = (tab: ViewTab) => {
    setCurrentView(tab);
    safeStorage.setItem('nola_active_tab', tab);

    // Clear selection for ALL tab changes to ensure Sidebar highlights are removed
    setSelectedContacts([]);
    setActiveContact(null);
    setActiveBulkMessage(null);
    safeStorage.removeItem('nola_active_contact');
    safeStorage.removeItem('nola_active_bulk_message');

    // On mobile, close sidebar when selecting any tab
    if (window.innerWidth < 768 && onMobileMenuToggle) {
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
        safeStorage.setItem('nola_active_tab', 'settings');
      }
    };

    window.addEventListener('navigate-to-settings', handleNavigateToSettings as EventListener);
    return () => {
      window.removeEventListener('navigate-to-settings', handleNavigateToSettings as EventListener);
    };
  }, []);

  // When the GHL location / subaccount changes, auto-reset to Home and clear
  // any active selections so the UI always starts fresh for that account.
  useEffect(() => {
    const handleLocationChanged = () => {
      setCurrentView('home');
      safeStorage.setItem('nola_active_tab', 'home');

      setSelectedContacts([]);
      setActiveContact(null);
      setActiveBulkMessage(null);
      safeStorage.removeItem('nola_active_contact');
      safeStorage.removeItem('nola_active_bulk_message');
    };

    window.addEventListener('ghl-location-changed', handleLocationChanged);
    return () => {
      window.removeEventListener('ghl-location-changed', handleLocationChanged);
    };
  }, []);

  return (
    <div className="flex h-screen bg-[#ffffff] dark:bg-[#202123] overflow-visible">
      {/* Sidebar - Left */}
      <div className={`
        fixed inset-y-0 left-0 z-[100] md:relative md:z-50 h-full transition-all duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]
        ${isMobileMenuOpen ? 'w-80 translate-x-0 shadow-2xl opacity-100 visible' : 'w-0 -translate-x-full md:w-auto md:translate-x-0 opacity-0 md:opacity-100 invisible md:visible pointer-events-none md:pointer-events-auto'}
        overflow-visible md:shadow-none
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
            onCloseMobile={toggleMobileMenu}
          />
        </div>
      </div>

      {/* Main Content Area */}
      <div className="flex-1 flex flex-col h-full w-full min-w-0 bg-[#f7f7f7] dark:bg-[#18191d]">
        {/* Mobile Header with Sidebar Toggle */}
        <div className="md:hidden flex items-center justify-between px-4 py-2.5 border-b border-[#0000000a] dark:border-[#ffffff0a] bg-white/80 dark:bg-[#121415]/80 backdrop-blur-lg sticky top-0 z-30">
          <button
            onClick={toggleMobileMenu}
            className="p-2 -ml-2 rounded-xl hover:bg-black/[0.04] dark:hover:bg-white/[0.04] text-[#3c4043] dark:text-[#e8eaed] transition-all active:scale-90"
            aria-label="Open Menu"
          >
            <FiMenu className="h-5 w-5" />
          </button>
          <div className="flex items-center gap-2">
            <div className="w-6 h-6 rounded-md bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex items-center justify-center shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5 text-white" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clipRule="evenodd" />
              </svg>
            </div>
            <span className="font-bold text-[15px] text-[#111111] dark:text-white tracking-tight">NOLA SMS Pro</span>
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={() => handleTabChange('settings')}
              className="p-2 rounded-lg hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] text-[#37352f] dark:text-[#ececf1] transition-colors settings-icon-rotate"
              aria-label="Settings"
            >
              <FiSettings className="h-5 w-5" strokeWidth={1.5} />
            </button>
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
        </div>

        {/* Missing Location Alert */}
        {window.self === window.top && !getAccountSettings().ghlLocationId && currentView !== 'settings' && (
          <div className="px-6 py-3 bg-amber-50 dark:bg-amber-900/10 border-b border-amber-200 dark:border-amber-800/20 flex items-center justify-between gap-4 flex-wrap">
            <div className="flex items-center gap-3">
              <div className="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-800/30 flex items-center justify-center text-amber-600 dark:text-amber-400">
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                  <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                </svg>
              </div>
              <p className="text-[13px] text-amber-800 dark:text-amber-300 font-medium">
                <strong>GHL Location Not Detected.</strong> Please open the app via the GoHighLevel sidebar, connect your account, or enter manually in Settings.
              </p>
            </div>
            <div className="flex items-center gap-2">
              <button 
                onClick={() => window.location.href = 'https://marketplace.gohighlevel.com/oauth/chooselocation?appId=65f8a0c2837bc281e59eef7b'} // Using generic/placeholder if they need one, but they can update the appId
                className="px-4 py-1.5 rounded-lg bg-gradient-to-r from-[#1d6bd4] to-[#2b83fa] text-white text-[12px] font-bold shadow-md hover:shadow-lg transition-all"
              >
                Connect GHL
              </button>
              <button 
                onClick={() => handleTabChange('settings')} 
                className="px-4 py-1.5 rounded-lg bg-amber-200 dark:bg-amber-800/50 text-amber-800 dark:text-amber-200 text-[12px] font-bold shadow-sm hover:bg-amber-300 dark:hover:bg-amber-700/50 transition-all"
              >
                Type Location ID
              </button>
            </div>
          </div>
        )}

        {/* Content Router */}
        <div className="flex-1 h-full overflow-hidden">
          {currentView === 'home' ? (
            <Home
              onTabChange={handleTabChange}
              onSelectContact={handleSelectContact}
              onSelectBulkMessage={handleSelectBulkMessage}
            />
          ) : currentView === 'compose' ? (
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
            <TemplatesTab />
          ) : null}
        </div>
      </div>

      {/* Mobile Overlay */}
      {
        isMobileMenuOpen && (
          <div
            className="fixed inset-0 bg-black/40 backdrop-blur-[2px] z-[90] md:hidden transition-all duration-300 pointer-events-auto"
            onClick={toggleMobileMenu}
          />
        )
      }

      {/* Onboarding Modal */}
      <OnboardingModal onboarding={onboarding} />
    </div>
  );
};
