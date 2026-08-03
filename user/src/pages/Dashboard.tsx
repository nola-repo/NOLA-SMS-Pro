import { safeStorage } from '../utils/safeStorage';
import { useState, useEffect } from "react";
import nolaLogo from "../assets/NOLA SMS PRO Logo.png";
import { useNavigate } from "react-router-dom";
import type { Contact } from "../types/Contact";
import type { BulkMessageHistoryItem } from "../types/Sms";
import { Sidebar } from "../components/Sidebar";
import type { ViewTab } from "../components/Sidebar";
import { Composer } from "../components/Composer";
import { ContactsTab } from "../components/ContactsTab";
import { TemplatesTab } from "../components/TemplatesTab";
import { Settings, type SettingsTab } from "./Settings";
import { FiAlertCircle, FiArrowRight, FiCheckCircle, FiMenu, FiRefreshCw, FiSettings } from "react-icons/fi";
import { Home } from "../components/Home";
import { TicketsTab } from "../components/TicketsTab";
import { useOnboarding } from "../components/onboarding/useOnboarding";
import { OnboardingModal } from "../components/onboarding/OnboardingModal";
import { SenderRequestModal } from "../components/SenderRequestModal";
import { fetchSenderRequests } from "../api/senderRequests";
import { useLocationId } from "../context/LocationContext";
import { GHL_BACKEND_ONBOARDING_URL, GHL_MARKETPLACE_CONNECT_URL, GHL_RECONNECT_REQUIRED_STORAGE_KEY } from "../config";
import faviconLogo from "../assets/FAV ICON - NOLA SMS PRO.png";
import { isAuthenticated } from "../services/authService";
import { useLocationBootstrap } from "../hooks/useLocationBootstrap";
import type { LocationBootstrapResponse } from "../utils/locationBootstrap";

interface DashboardProps {
  isMobileMenuOpen?: boolean;
  onMobileMenuToggle?: () => void;
  darkMode?: boolean;
  toggleDarkMode?: () => void;
  initialView?: ViewTab;
  settingsInitialTab?: SettingsTab;
  topControls?: React.ReactNode;
}


const getBootstrapDiagnostic = (response?: LocationBootstrapResponse): string => {
  if (!response) return 'Workspace verification did not return diagnostics.';
  return response.code || response.message || response.error || response.next_action || 'Workspace verification did not return diagnostics.';
};

const getWorkspaceBlockedCopy = (response: LocationBootstrapResponse): { title: string; message: string; retryLabel?: string } => {
  switch (response.code) {
    case 'LOCATION_CLEANUP_IN_PROGRESS':
    case 'cleanup_in_progress':
      return {
        title: 'Cleanup in progress',
        message: 'This test workspace is temporarily unavailable while cleanup is in progress. Sending and background sync are paused until verification succeeds again.',
        retryLabel: 'Recheck',
      };
    case 'LOCATION_INSTALL_PENDING':
    case 'install_pending':
      return {
        title: 'Installation still processing',
        message: 'HighLevel is showing the app, but NOLA provisioning is still completing. Product data will stay closed until setup finishes.',
        retryLabel: 'Try again',
      };
    case 'LOCATION_ONBOARDING_EXPIRED':
    case 'onboarding_expired':
      return {
        title: 'Installation setup expired',
        message: 'The previous Marketplace setup session expired before this location became ready. Restart installation to continue.',
        retryLabel: 'Recheck',
      };
    case 'LOCATION_NOT_INSTALLED':
    case 'LOCATION_UNINSTALLED':
    case 'app_uninstalled':
    case 'not_installed':
      return {
        title: 'NOLA SMS Pro is not installed',
        message: 'Install or reinstall NOLA SMS Pro from HighLevel Marketplace before using messages, contacts, templates, or billing tools.',
        retryLabel: 'Recheck',
      };
    case 'LOCATION_COMPANY_MISMATCH':
      return {
        title: 'Wrong company or location',
        message: 'The current NOLA session does not match this HighLevel company/location. Automatic loading has stopped so the wrong workspace is not exposed.',
        retryLabel: 'Recheck',
      };
    case 'LOCATION_INACTIVE':
      return {
        title: 'Workspace inactive',
        message: 'This NOLA workspace is inactive. Contact your admin or support before loading product data.',
        retryLabel: 'Recheck',
      };
    case 'DUPLICATE_LOCATION_USERS':
      return {
        title: 'Default account needs setup',
        message: 'Ask your admin to choose the default NOLA SMS Pro account for this subaccount. No linked account list is shown here for privacy.',
        retryLabel: 'Recheck',
      };
    case 'DUPLICATE_LOCATION_USER_IDENTITY':
      return {
        title: 'Account match needs support',
        message: 'We found more than one matching NOLA account for this GHL user. Please contact support to repair the account mapping.',
        retryLabel: 'Recheck',
      };
    case 'LOCATION_USER_NOT_LINKED':
      return {
        title: 'Account link required',
        message: 'Your GHL user is not linked to a NOLA SMS Pro account for this subaccount. Complete account linking, then recheck.',
        retryLabel: 'Recheck',
      };
    case 'GHL_AUTOLOGIN_REQUIRED':
    case 'LOCATION_SESSION_MISMATCH':
      return {
        title: 'Workspace session needs refresh',
        message: 'The stored NOLA session does not match this GHL subaccount yet. Recheck to request a fresh location-scoped session.',
        retryLabel: 'Recheck',
      };
    default:
      return {
        title: 'Workspace temporarily unavailable',
        message: 'The backend could not finish workspace verification yet. No protected data will load until this check passes.',
      };
  }
};

const isRunningInGhlFrame = (): boolean => {
  try {
    return window.self !== window.top || sessionStorage.getItem('nola_is_ghl_frame') === 'true';
  } catch {
    return true;
  }
};

const WorkspaceActionState: React.FC<{
  title: string;
  message: string;
  locationId: string;
  response?: LocationBootstrapResponse;
  primaryLabel?: string;
  onPrimary?: () => void;
  retryLabel?: string;
  onRetry: () => void;
}> = ({ title, message, locationId, response, primaryLabel, onPrimary, retryLabel = 'Try again', onRetry }) => (
  <div className="min-h-screen bg-[#f7f7f7] dark:bg-[#18191d] flex items-center justify-center px-4 py-8">
    <div className="w-full max-w-xl bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl shadow-xl p-6 sm:p-8">
      <div className="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 flex items-center justify-center mb-5">
        <FiAlertCircle className="w-6 h-6" />
      </div>
      <h1 className="text-[24px] sm:text-[28px] font-black tracking-tight text-[#111111] dark:text-white mb-3">{title}</h1>
      <p className="text-[14px] leading-6 text-[#5f6368] dark:text-[#b6bac2] mb-5">{message}</p>
      <div className="rounded-xl border border-[#e5e5e5] dark:border-white/10 bg-[#fafafa] dark:bg-white/[0.03] px-4 py-3 mb-6">
        <div className="text-[11px] font-bold uppercase text-[#8a8f98] dark:text-[#8f949e] mb-1">GHL Location ID</div>
        <div className="font-mono text-[13px] text-[#111111] dark:text-white break-all">{locationId}</div>
        <div className="mt-2 font-mono text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] break-all">{getBootstrapDiagnostic(response)}</div>
        {response?.request_id && (
          <div className="mt-2 font-mono text-[11px] text-[#8a8f98] dark:text-[#9aa0a6] break-all">Request ID: {response.request_id}</div>
        )}
      </div>
      <div className="flex flex-col sm:flex-row gap-3">
        {primaryLabel && onPrimary && (
          <button
            onClick={onPrimary}
            className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gradient-to-r from-[#1d6bd4] to-[#2b83fa] text-white text-[13px] font-bold shadow-md shadow-blue-500/20 hover:shadow-lg transition-all"
          >
            {primaryLabel}
            <FiArrowRight className="w-4 h-4" />
          </button>
        )}
        <button
          onClick={onRetry}
          className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#f1f3f4] dark:bg-[#2a2b32] text-[#37352f] dark:text-[#ececf1] text-[13px] font-bold hover:bg-[#e8eaed] dark:hover:bg-[#34363d] transition-all"
        >
          <FiRefreshCw className="w-4 h-4" />
          {retryLabel}
        </button>
      </div>
    </div>
  </div>
);

const RegistrationRequiredState: React.FC<{
  locationId: string;
  response: LocationBootstrapResponse;
  onRetry: () => void;
}> = ({ locationId, response, onRetry }) => {
  const locationName = response.location_name && response.location_name !== 'Unknown'
    ? response.location_name
    : 'this subaccount';
  const issuedOnboardingUrl = response.onboarding_url || response.registration_url || '';
  const loginUrl = response.login_url || '';
  const primaryUrl = response.code === 'LOCATION_ALREADY_REGISTERED'
    ? loginUrl
    : issuedOnboardingUrl;
  const primaryLabel = response.code === 'LOCATION_ALREADY_REGISTERED'
    ? 'Go to login'
    : 'Continue onboarding';

  return (
    <div className="min-h-screen bg-[#f7f7f7] dark:bg-[#18191d] flex items-center justify-center px-4 py-8">
      <div className="w-full max-w-xl bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl shadow-xl p-6 sm:p-8">
        <div className="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-300 flex items-center justify-center mb-5">
          <FiAlertCircle className="w-6 h-6" />
        </div>

        <h1 className="text-[24px] sm:text-[28px] font-black tracking-tight text-[#111111] dark:text-white mb-3">
          Registration required
        </h1>
        <p className="text-[14px] leading-6 text-[#5f6368] dark:text-[#b6bac2] mb-5">
          {locationName} is not linked to a registered NOLA user yet. Complete onboarding before using messages, contacts, templates, or billing tools.
        </p>

        <div className="rounded-xl border border-[#e5e5e5] dark:border-white/10 bg-[#fafafa] dark:bg-white/[0.03] px-4 py-3 mb-6">
          <div className="text-[11px] font-bold uppercase text-[#8a8f98] dark:text-[#8f949e] mb-1">GHL Location ID</div>
          <div className="font-mono text-[13px] text-[#111111] dark:text-white break-all">{locationId}</div>
          <div className="mt-2 inline-flex items-center gap-1.5 text-[12px] font-semibold text-amber-700 dark:text-amber-300">
            <FiAlertCircle className="w-3.5 h-3.5" />
            {response.registration_status === 'not_installed' ? 'Not installed' : 'Not registered'}
          </div>
          <div className="mt-2 font-mono text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] break-all">{getBootstrapDiagnostic(response)}</div>
        {response?.request_id && (
          <div className="mt-2 font-mono text-[11px] text-[#8a8f98] dark:text-[#9aa0a6] break-all">Request ID: {response.request_id}</div>
        )}
        </div>

        <div className="flex flex-col sm:flex-row gap-3">
          {primaryUrl && (
            <button
              onClick={() => { window.location.href = primaryUrl; }}
              className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gradient-to-r from-[#1d6bd4] to-[#2b83fa] text-white text-[13px] font-bold shadow-md shadow-blue-500/20 hover:shadow-lg transition-all"
            >
              {primaryLabel}
              <FiArrowRight className="w-4 h-4" />
            </button>
          )}
          <button
            onClick={onRetry}
            className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#f1f3f4] dark:bg-[#2a2b32] text-[#37352f] dark:text-[#ececf1] text-[13px] font-bold hover:bg-[#e8eaed] dark:hover:bg-[#34363d] transition-all"
          >
            <FiRefreshCw className="w-4 h-4" />
            Recheck
          </button>
        </div>

        <div className="mt-6 flex items-center gap-2 text-[12px] text-[#6e6e73] dark:text-[#9aa0a6]">
          <FiCheckCircle className="w-4 h-4 text-emerald-500" />
          Already completed registration in another tab? Recheck to return to the app.
        </div>
      </div>
    </div>
  );
};
const SETTINGS_TAB_ROUTES: Record<SettingsTab, string> = {
  account: '/settings/account',
  senderIds: '/settings/sender-id',
  notifications: '/settings/notifications',
  credits: '/settings/credits',
};

const contactPhoneKey = (phone?: string | null) => (phone || '').replace(/\D/g, '').slice(-10);

const isSameContactUpdateTarget = (target: Contact, contact: Contact, previous?: Contact | null) => {
  if (target.id === contact.id || (previous && target.id === previous.id)) return true;
  const targetPhone = contactPhoneKey(target.phone);
  return !!targetPhone && (
    targetPhone === contactPhoneKey(contact.phone) ||
    targetPhone === contactPhoneKey(previous?.phone)
  );
};

export const Dashboard: React.FC<DashboardProps> = ({ isMobileMenuOpen: externalIsMobileMenuOpen, onMobileMenuToggle, darkMode, toggleDarkMode, initialView, settingsInitialTab, topControls }) => {
  const navigate = useNavigate();
  const onboarding = useOnboarding();
  // Reactive location ID from context — re-renders whenever subaccount changes
  const { locationId, isLocationResolving } = useLocationId();
  const { state: bootstrapState, retry: retryBootstrap } = useLocationBootstrap(locationId, Boolean(locationId));

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
  const [senderModalOpen, setSenderModalOpen] = useState(false);
  // initialView from the router takes priority; fall back to persisted storage
  const [currentView, setCurrentView] = useState<ViewTab>(
    () => initialView || (safeStorage.getItem('nola_active_tab') as ViewTab) || 'home'
  );
  const [settingsTab, setSettingsTab] = useState<SettingsTab | undefined>(settingsInitialTab);
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
  const [settingsOpen] = useState(false);
  const [autoOpenAddContact, setAutoOpenAddContact] = useState(false);
  const [workspaceProfileSyncComplete, setWorkspaceProfileSyncComplete] = useState(false);

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

    // Sync to URL so the browser history and address bar stay up to date
    const urlMap: Record<ViewTab, string> = {
      home: '/',
      compose: '/compose',
      contacts: '/contacts',
      settings: settingsInitialTab ? SETTINGS_TAB_ROUTES[settingsInitialTab] : '/settings/account',
      templates: '/templates',
      tickets: '/tickets',
    };
    const search = window.location.search;
    navigate({ pathname: urlMap[tab] ?? '/', search }, { replace: false });

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

  const handleCreateContactShortcut = () => {
    setAutoOpenAddContact(true);
    setCurrentView('contacts');
    safeStorage.setItem('nola_active_tab', 'contacts');
    navigate({ pathname: '/contacts', search: window.location.search }, { replace: false });

    setSelectedContacts([]);
    setActiveContact(null);
    setActiveBulkMessage(null);
    safeStorage.removeItem('nola_active_contact');
    safeStorage.removeItem('nola_active_bulk_message');
  };

  const toggleMobileMenu = () => {
    if (onMobileMenuToggle) {
      onMobileMenuToggle();
    }
  };

  const toggleCollapse = () => {
    setIsSidebarCollapsed(!isSidebarCollapsed);
  };


  useEffect(() => {
    if (locationId) return;

    const shouldWaitForWorkspace =
      isAuthenticated() || isRunningInGhlFrame();

    if (!shouldWaitForWorkspace) return;

    const handleProfileSyncComplete = () => {
      setWorkspaceProfileSyncComplete(true);
    };
    const fallbackTimer = window.setTimeout(handleProfileSyncComplete, 1000);

    window.addEventListener('nola-profile-sync-complete', handleProfileSyncComplete);
    return () => {
      window.clearTimeout(fallbackTimer);
      window.removeEventListener('nola-profile-sync-complete', handleProfileSyncComplete);
    };
  }, [locationId]);

  // Handle navigation to settings tabs from CreditBadge
  useEffect(() => {
    setSettingsTab(settingsInitialTab);
  }, [settingsInitialTab]);

  useEffect(() => {
    const handleNavigateToSettings = (e: CustomEvent) => {
      const tab = e.detail?.tab as SettingsTab | undefined;
      if (e.detail?.reconnect) {
        safeStorage.setItem(GHL_RECONNECT_REQUIRED_STORAGE_KEY, 'true');
      }
      if (tab) {
        setSettingsTab(tab);
        setCurrentView('settings');
        safeStorage.setItem('nola_active_tab', 'settings');
        navigate({ pathname: SETTINGS_TAB_ROUTES[tab] || '/settings/account', search: window.location.search }, { replace: false });
      }
    };

    window.addEventListener('navigate-to-settings', handleNavigateToSettings as EventListener);
    return () => {
      window.removeEventListener('navigate-to-settings', handleNavigateToSettings as EventListener);
    };
  }, [navigate]);

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

  useEffect(() => {
    const handleContactUpdated = (event: Event) => {
      const { contact, previous } = (event as CustomEvent<{ contact?: Contact; previous?: Contact }>).detail || {};
      if (!contact) return;

      setActiveContact((current) => {
        if (!current || !isSameContactUpdateTarget(current, contact, previous)) return current;
        const next = { ...current, ...contact };
        safeStorage.setItem('nola_active_contact', JSON.stringify(next));
        return next;
      });
      setSelectedContacts((current) => current.map((item) =>
        isSameContactUpdateTarget(item, contact, previous) ? { ...item, ...contact } : item
      ));
    };

    window.addEventListener('nola-contact-updated', handleContactUpdated);
    return () => window.removeEventListener('nola-contact-updated', handleContactUpdated);
  }, []);

  useEffect(() => {
    if (!locationId || bootstrapState.status !== 'ready') return;
    
    const checkSenderRequests = async () => {
      const onboardingDone = localStorage.getItem('nola_onboarding_done') === 'true';
      const alreadyPrompted = sessionStorage.getItem('nola_sender_id_prompted') === 'true';
      
      if (onboardingDone && !alreadyPrompted) {
        try {
          const requests = await fetchSenderRequests(locationId);
          if (requests.length === 0) {
            setSenderModalOpen(true);
            sessionStorage.setItem('nola_sender_id_prompted', 'true');
          }
        } catch (e) {
          console.error("Error checking sender requests:", e);
        }
      }
    };
    
    checkSenderRequests();
  }, [locationId, bootstrapState.status]);

  useEffect(() => {
    const handleOnboardingUpdated = () => {
      const onboardingDone = localStorage.getItem('nola_onboarding_done') === 'true';
      if (onboardingDone && locationId) {
        fetchSenderRequests(locationId).then(requests => {
          if (requests.length === 0) {
            setSenderModalOpen(true);
            sessionStorage.setItem('nola_sender_id_prompted', 'true');
          }
        }).catch(err => console.error(err));
      }
    };
    window.addEventListener('nola-onboarding-updated', handleOnboardingUpdated);
    return () => window.removeEventListener('nola-onboarding-updated', handleOnboardingUpdated);
  }, [locationId]);

  // Open sender ID request modal when triggered from the notification bell reminder
  useEffect(() => {
    const handleOpenSenderIdModal = () => {
      setSenderModalOpen(true);
    };
    window.addEventListener('open-sender-id-modal', handleOpenSenderIdModal);
    return () => window.removeEventListener('open-sender-id-modal', handleOpenSenderIdModal);
  }, []);

  const bootstrapIsCurrent = !locationId || bootstrapState.locationId === locationId;
  const shouldWaitForWorkspaceBootstrap =
    isAuthenticated() || isRunningInGhlFrame();
  const workspaceBootstrapComplete =
    Boolean(locationId) ||
    (!isLocationResolving && workspaceProfileSyncComplete) ||
    !shouldWaitForWorkspaceBootstrap;
  const isAwaitingWorkspaceBootstrap =
    !locationId && (isLocationResolving || !workspaceBootstrapComplete);
  const isCheckingActiveLocation =
    isAwaitingWorkspaceBootstrap ||
    (!!locationId &&
      (!bootstrapIsCurrent ||
        bootstrapState.status === 'checking' ||
        bootstrapState.status === 'idle'));



  if (locationId && bootstrapIsCurrent && bootstrapState.status === 'action_required') {
    const response = bootstrapState.response;
    const nextAction = response.next_action;

    if (nextAction === 'complete_registration') {
      return (
        <RegistrationRequiredState
          locationId={locationId}
          response={response}
          onRetry={retryBootstrap}
        />
      );
    }

    if (nextAction === 'show_not_installed') {
      const copy = getWorkspaceBlockedCopy(response);
      return (
        <WorkspaceActionState
          title={copy.title}
          message={copy.message}
          locationId={locationId}
          response={response}
          primaryLabel="Restart Installation"
          onPrimary={() => { window.location.href = GHL_BACKEND_ONBOARDING_URL || GHL_MARKETPLACE_CONNECT_URL; }}
          retryLabel={copy.retryLabel}
          onRetry={retryBootstrap}
        />
      );
    }

    if (nextAction === 'show_reconnect' || response.requires_reconnect) {
      return (
        <WorkspaceActionState
          title="GHL reconnect required"
          message="This workspace needs its GoHighLevel connection refreshed before contacts and messages can load."
          locationId={locationId}
          response={response}
          primaryLabel="Reconnect GHL"
          onPrimary={() => { window.location.href = GHL_MARKETPLACE_CONNECT_URL; }}
          onRetry={retryBootstrap}
        />
      );
    }

    const copy = getWorkspaceBlockedCopy(response);
    return (
      <WorkspaceActionState
        title={copy.title}
        message={copy.message}
        locationId={locationId}
        response={response}
        retryLabel={copy.retryLabel}
        onRetry={retryBootstrap}
      />
    );
  }

  if (locationId && bootstrapIsCurrent && bootstrapState.status === 'error') {
    return (
      <WorkspaceActionState
        title="Workspace verification failed"
        message={bootstrapState.message}
        locationId={locationId}
        response={bootstrapState.response}
        onRetry={retryBootstrap}
      />
    );
  }
  return (
    <div className="flex h-[100dvh] min-h-0 bg-[#ffffff] dark:bg-[#202123] overflow-hidden">
      {/* Sidebar - Left */}
      <div className={`
        fixed inset-y-0 left-0 z-[100] md:relative md:z-50 h-full w-80 md:w-auto transition-transform duration-300 ease-[cubic-bezier(0.25,0.46,0.45,0.94)]
        ${isMobileMenuOpen ? 'translate-x-0 shadow-2xl pointer-events-auto' : '-translate-x-full md:translate-x-0 pointer-events-none md:pointer-events-auto'}
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
      <div className="flex-1 flex flex-col h-full min-h-0 w-full min-w-0 overflow-hidden bg-[#f7f7f7] dark:bg-[#18191d]">
        {/* Mobile Header with Sidebar Toggle */}
        <div className="md:hidden flex items-center justify-between px-4 py-2.5 border-b border-[#0000000a] dark:border-[#ffffff0a] bg-white/80 dark:bg-[#121415]/80 backdrop-blur-lg z-30">
          <button
            onClick={toggleMobileMenu}
            className="p-2 -ml-2 rounded-xl hover:bg-black/[0.04] dark:hover:bg-white/[0.04] text-[#3c4043] dark:text-[#e8eaed] transition-all active:scale-90"
            aria-label="Open Menu"
          >
            <FiMenu className="h-5 w-5" />
          </button>
          <div className="flex items-center gap-2">
            <div className="w-7 h-7 rounded-lg bg-white dark:bg-[#1a1b1e] border border-black/[0.06] dark:border-white/[0.08] flex items-center justify-center shadow-sm overflow-hidden">
              <img src={faviconLogo} alt="NOLA SMS PRO" className="h-5 w-5 object-contain" />
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
        {!locationId && !isLocationResolving && currentView !== 'settings' && (
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
                onClick={() => window.location.href = GHL_MARKETPLACE_CONNECT_URL}
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
        <div key={locationId || 'default'} className="flex-1 flex flex-col min-h-0 overflow-hidden w-full">
          {currentView === 'home' ? (
            <Home
              onTabChange={handleTabChange}
              onCreateContact={handleCreateContactShortcut}
              onSelectContact={handleSelectContact}
              onSelectBulkMessage={handleSelectBulkMessage}
              topControls={topControls}
            />
          ) : currentView === 'compose' ? (
            <Composer
              selectedContacts={selectedContacts}
              activeContact={activeContact}
              activeBulkMessage={activeBulkMessage}
              isNewMessage={!activeContact && !activeBulkMessage}
              onSelectContact={handleSelectContact}
              onSelectBulkMessage={handleSelectBulkMessage}
              onToggleMobileMenu={toggleMobileMenu}
              darkMode={darkMode}
            />
          ) : currentView === 'contacts' ? (
            <ContactsTab
              onSendToComposer={handleSendToComposer}
              onViewMessages={handleViewMessages}
              autoOpenAddModal={autoOpenAddContact}
              onAutoOpenAddModalHandled={() => setAutoOpenAddContact(false)}
            />
          ) : currentView === 'settings' || settingsOpen ? (
            <Settings
              darkMode={darkMode ?? false}
              toggleDarkMode={toggleDarkMode ?? (() => { })}
              initialTab={settingsOpen ? "senderIds" : settingsTab || settingsInitialTab}
              autoOpenAddModal={settingsOpen}
            />
          ) : currentView === 'templates' ? (
            <TemplatesTab />
          ) : currentView === 'tickets' ? (
            <TicketsTab />
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

      {/* Sender ID Request Modal */}
      <SenderRequestModal
        isOpen={senderModalOpen}
        onClose={() => setSenderModalOpen(false)}
      />

      {/* Verifying Workspace Full Screen Frosted Overlay */}
      {isCheckingActiveLocation && (
        <div className="fixed inset-0 z-[99999] flex items-center justify-center px-4 bg-white/20 dark:bg-black/40 backdrop-blur-md">
          <div className="relative z-10 w-full max-w-sm flex flex-col items-center p-8 rounded-[2rem] bg-white/95 dark:bg-[#121415]/95 backdrop-blur-xl border border-black/[0.06] dark:border-white/[0.05] shadow-2xl text-center">
            {/* Logo */}
            <img
              src={nolaLogo}
              alt="NOLA SMS PRO"
              className="w-24 h-24 mb-3 drop-shadow-xl object-contain"
            />
            <h2 className="text-[17px] font-black tracking-tight text-[#111111] dark:text-white mb-1">
              Verifying your workspace
            </h2>
            <p className="text-[12.5px] font-medium text-gray-500 dark:text-[#b6bac2] mb-6">
              Verifying your NOLA SMS Pro workspace...
            </p>
            <div className="w-full bg-[#f1f3f4] dark:bg-white/[0.06] h-1.5 rounded-full overflow-hidden relative">
              <div className="workspace-progress-bar absolute top-0 bottom-0 left-0 w-[42%] rounded-full bg-gradient-to-r from-[#2b83fa] via-[#1e40af] to-[#2b83fa]" />
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
