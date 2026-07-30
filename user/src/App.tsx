import React, { useState, useEffect, useRef } from "react";
import { BrowserRouter, Routes, Route, Navigate, useLocation, useNavigate } from "react-router-dom";
import { Dashboard } from "./pages/Dashboard";
import { GhlCallback } from "./pages/GhlCallback";
import { ForgotPassword } from "./pages/ForgotPassword";

import { AuthProvider } from "./context/AuthContext";
import { LocationProvider } from "./context/LocationContext";
import { ProtectedRoute } from "./components/ProtectedRoute";
import SharedLogin from "./components/SharedLogin";
import { safeStorage } from "./utils/safeStorage";
import { useUserProfile } from "./hooks/useUserProfile";
import { UserProfileContext } from "./context/UserProfileContext";
import { isAuthenticated } from "./services/authService";
import { hasGhlLaunchSignalInCurrentUrl } from "./utils/ghlLocationDetection";
import { FiBookOpen, FiMessageSquare, FiMoon, FiMoreHorizontal, FiSun, FiX } from "react-icons/fi";
import { UserNotificationBell } from "./components/ui/UserNotificationBell";
import { AppLoadingScreen } from "./components/ui/AppLoadingScreen";
import type { ViewTab } from "./components/Sidebar";
import { TicketsTab } from "./components/TicketsTab";

const isGhlEmbeddedRequest = (): boolean => {
  const hasGhlParam = hasGhlLaunchSignalInCurrentUrl();

  let isIframe = false;
  try {
    isIframe = window.self !== window.top;
  } catch {
    isIframe = true;
  }

  let savedGhlFrame = false;
  try {
    savedGhlFrame = sessionStorage.getItem("nola_is_ghl_frame") === "true";
  } catch {
    // Storage can be blocked in embedded contexts.
  }

  if (hasGhlParam || isIframe) {
    try {
      sessionStorage.setItem("nola_is_ghl_frame", "true");
    } catch {
      // ignore
    }
  }

  return hasGhlParam || isIframe || savedGhlFrame;
};

const RedirectToBackend: React.FC<{ path: string }> = ({ path }) => {
  const alreadySignedIn = isAuthenticated();
  const navigate = useNavigate();
  const isGhlRequest = isGhlEmbeddedRequest();

  useEffect(() => {
    if (alreadySignedIn) {
      navigate({ pathname: "/", search: window.location.search }, { replace: true });
      return;
    }

    if (isGhlRequest) {
      navigate({ pathname: "/", search: window.location.search }, { replace: true });
      return;
    }

    window.location.replace(`${import.meta.env.VITE_API_BASE || ''}${path}${window.location.search}`);
  }, [path, alreadySignedIn, isGhlRequest, navigate]);

  return <AppLoadingScreen message="Redirecting..." subtext="Connecting to authentication provider..." />;
};

const RedirectInstallRegistration: React.FC = () => {
  useEffect(() => {
    const target = new URL(`${import.meta.env.VITE_API_BASE || window.location.origin}/install-register.php`);
    const params = new URLSearchParams(window.location.search);
    params.forEach((value, key) => target.searchParams.set(key, value));
    window.location.replace(target.toString());
  }, []);

  return <AppLoadingScreen message="Opening Installation Setup" subtext="Redirecting to the secure setup page..." />;
};

const ThemeSwitch: React.FC<{ checked: boolean; onChange: () => void }> = ({ checked, onChange }) => (
  <label className="app-theme-switch" title={checked ? "Switch to Light Mode" : "Switch to Dark Mode"}>
    <input
      type="checkbox"
      className="app-theme-switch__checkbox"
      checked={checked}
      onChange={onChange}
      aria-label="Toggle theme"
    />
    <span className="app-theme-switch__container">
      <span className="app-theme-switch__clouds" />
      <span className="app-theme-switch__stars-container">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 144 55" fill="none" aria-hidden="true">
          <path fillRule="evenodd" clipRule="evenodd" d="M135.831 3.00688C135.055 3.85027 134.111 4.29946 133 4.35447C134.111 4.40947 135.055 4.85867 135.831 5.71123C136.607 6.55462 136.996 7.56303 136.996 8.72727C136.996 7.95722 137.172 7.25134 137.525 6.59129C137.886 5.93124 138.372 5.39954 138.98 5.00535C139.598 4.60199 140.268 4.39114 141 4.35447C139.88 4.2903 138.936 3.85027 138.16 3.00688C137.384 2.16348 136.996 1.16425 136.996 0C136.996 1.16425 136.607 2.16348 135.831 3.00688ZM31 23.3545C32.1114 23.2995 33.0551 22.8503 33.8313 22.0069C34.6075 21.1635 34.9956 20.1642 34.9956 19C34.9956 20.1642 35.3837 21.1635 36.1599 22.0069C36.9361 22.8503 37.8798 23.2903 39 23.3545C38.2679 23.3911 37.5976 23.602 36.9802 24.0053C36.3716 24.3995 35.8864 24.9312 35.5248 25.5913C35.172 26.2513 34.9956 26.9572 34.9956 27.7273C34.9956 26.563 34.6075 25.5546 33.8313 24.7112C33.0551 23.8587 32.1114 23.4095 31 23.3545ZM0 36.3545C1.11136 36.2995 2.05513 35.8503 2.83131 35.0069C3.6075 34.1635 3.99559 33.1642 3.99559 32C3.99559 33.1642 4.38368 34.1635 5.15987 35.0069C5.93605 35.8503 6.87982 36.2903 8 36.3545C7.26792 36.3911 6.59757 36.602 5.98015 37.0053C5.37155 37.3995 4.88644 37.9312 4.52481 38.5913C4.172 39.2513 3.99559 39.9572 3.99559 40.7273C3.99559 39.563 3.6075 38.5546 2.83131 37.7112C2.05513 36.8587 1.11136 36.4095 0 36.3545ZM56.8313 24.0069C56.0551 24.8503 55.1114 25.2995 54 25.3545C55.1114 25.4095 56.0551 25.8587 56.8313 26.7112C57.6075 27.5546 57.9956 28.563 57.9956 29.7273C57.9956 28.9572 58.172 28.2513 58.5248 27.5913C58.8864 26.9312 59.3716 26.3995 59.9802 26.0053C60.5976 25.602 61.2679 25.3911 62 25.3545C60.8798 25.2903 59.9361 24.8503 59.1599 24.0069C58.3837 23.1635 57.9956 22.1642 57.9956 21C57.9956 22.1642 57.6075 23.1635 56.8313 24.0069ZM81 25.3545C82.1114 25.2995 83.0551 24.8503 83.8313 24.0069C84.6075 23.1635 84.9956 22.1642 84.9956 21C84.9956 22.1642 85.3837 23.1635 86.1599 24.0069C86.9361 24.8503 87.8798 25.2903 89 25.3545C88.2679 25.3911 87.5976 25.602 86.9802 26.0053C86.3716 26.3995 85.8864 26.9312 85.5248 27.5913C85.172 28.2513 84.9956 28.9572 84.9956 29.7273C84.9956 28.563 84.6075 27.5546 83.8313 26.7112C83.0551 25.8587 82.1114 25.4095 81 25.3545ZM136 36.3545C137.111 36.2995 138.055 35.8503 138.831 35.0069C139.607 34.1635 139.996 33.1642 139.996 32C139.996 33.1642 140.384 34.1635 141.16 35.0069C141.936 35.8503 142.88 36.2903 144 36.3545C143.268 36.3911 142.598 36.602 141.98 37.0053C141.372 37.3995 140.886 37.9312 140.525 38.5913C140.172 39.2513 139.996 39.9572 139.996 40.7273C139.996 39.563 139.607 38.5546 138.831 37.7112C138.055 36.8587 137.111 36.4095 136 36.3545ZM101.831 49.0069C101.055 49.8503 100.111 50.2995 99 50.3545C100.111 50.4095 101.055 50.8587 101.831 51.7112C102.607 52.5546 102.996 53.563 102.996 54.7273C102.996 53.9572 103.172 53.2513 103.525 52.5913C103.886 51.9312 104.372 51.3995 104.98 51.0053C105.598 50.602 106.268 50.3911 107 50.3545C105.88 50.2903 104.936 49.8503 104.16 49.0069C103.384 48.1635 102.996 47.1642 102.996 46C102.996 47.1642 102.607 48.1635 101.831 49.0069Z" fill="currentColor" />
        </svg>
      </span>
      <span className="app-theme-switch__circle-container">
        <span className="app-theme-switch__sun-moon-container">
          <span className="app-theme-switch__moon">
            <span className="app-theme-switch__spot" />
            <span className="app-theme-switch__spot" />
            <span className="app-theme-switch__spot" />
          </span>
        </span>
      </span>
    </span>
  </label>
);

const TopMoreOptions: React.FC<{
  darkMode: boolean;
  toggleDarkMode: () => void;
  onboardingDone: boolean;
  onOpenGettingStarted: () => void;
  onOpenTickets: () => void;
}> = ({ darkMode, toggleDarkMode, onboardingDone, onOpenGettingStarted, onOpenTickets }) => {
  const [open, setOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const optionIconClass = "flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-[#f1f3f4] text-[#5f6368] dark:bg-white/[0.06] dark:text-[#b6bac2]";

  useEffect(() => {
    if (!open) return;
    const handleOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", handleOutside);
    return () => document.removeEventListener("mousedown", handleOutside);
  }, [open]);

  const handleGettingStarted = () => {
    onOpenGettingStarted();
    setOpen(false);
  };

  const handleTickets = () => {
    onOpenTickets();
    setOpen(false);
  };

  return (
    <div ref={menuRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className={`
          relative inline-flex items-center justify-center rounded-xl border p-2 shadow-sm transition-all active:scale-95
          ${open
            ? "border-white/30 bg-white/25 text-white"
            : "border-white/20 bg-white/10 text-white hover:bg-white/20"
          }
        `}
        aria-label="More options"
        aria-expanded={open}
        title="More options"
      >
        <FiMoreHorizontal className="h-4 w-4" />
        {!onboardingDone && (
          <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500 dark:border-[#1a1b1e]" />
        )}
      </button>

      {open && (
        <div className="absolute right-0 top-[calc(100%+10px)] z-[80] w-72 overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white/95 p-2 shadow-2xl backdrop-blur-xl animate-in fade-in-0 zoom-in-[0.97] slide-in-from-top-1 duration-150 dark:border-white/10 dark:bg-[#1a1b1e]/95">
          <button
            type="button"
            onClick={handleGettingStarted}
            className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-[#f4f7fb] dark:hover:bg-white/[0.05]"
          >
            <span className={`relative ${optionIconClass}`}>
              <FiBookOpen className="h-[18px] w-[18px]" />
              {!onboardingDone && (
                <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500 dark:border-[#1a1b1e]" />
              )}
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-[13px] font-bold text-[#111111] dark:text-white">Get Started</span>
              <span className="block truncate text-[11.5px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                Open onboarding
              </span>
            </span>
            {!onboardingDone && (
              <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-500">
                New
              </span>
            )}
          </button>

          <button
            type="button"
            onClick={handleTickets}
            className="mt-1 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-[#f4f7fb] dark:hover:bg-white/[0.05]"
          >
            <span className={optionIconClass}>
              <FiMessageSquare className="h-[18px] w-[18px]" />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-[13px] font-bold text-[#111111] dark:text-white">Tickets</span>
              <span className="block truncate text-[11.5px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                View support tickets
              </span>
            </span>
          </button>

          <div className="mt-1 flex items-center justify-between gap-3 rounded-xl px-3 py-2.5">
            <span className={optionIconClass}>
              {darkMode ? <FiMoon className="h-[18px] w-[18px]" /> : <FiSun className="h-[18px] w-[18px]" />}
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-[13px] font-bold text-[#111111] dark:text-white">Theme</span>
              <span className="block text-[11.5px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                {darkMode ? "Dark mode" : "Light mode"}
              </span>
            </span>
            <ThemeSwitch checked={darkMode} onChange={toggleDarkMode} />
          </div>
        </div>
      )}
    </div>
  );
};

const AppLayout: React.FC = () => {
  const [darkMode, setDarkMode] = useState(() => {
    const saved = safeStorage.getItem('darkMode');
    if (saved !== null) {
      return JSON.parse(saved);
    }
    return false;
  });

  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [ticketsModalOpen, setTicketsModalOpen] = useState(false);
  const [onboardingDone, setOnboardingDone] = useState(
    () => localStorage.getItem('nola_onboarding_done') === 'true'
  );
  const location = useLocation();
  const navigate = useNavigate();

  useEffect(() => {
    const pathTitle: Record<string, string> = {
      '/': 'Dashboard',
      '/compose': 'Compose',
      '/contacts': 'Contacts',
      '/settings': 'Account Settings',
      '/settings/account': 'Account Settings',
      '/settings/notifications': 'Notification Settings',
      '/settings/sender-id': 'Sender IDs',
      '/settings/credits': 'Credits & Billing',
      '/templates': 'Templates',
      '/tickets': 'Support Tickets',
      '/login': 'Login',
      '/forgot-password': 'Reset Password',
      '/register': 'Register',
      '/register-from-install': 'Installation Setup',
      '/oauth/callback': 'GoHighLevel Connection',
    };
    document.title = `${pathTitle[location.pathname] || 'Dashboard'} | NOLA SMS Pro`;
  }, [location.pathname]);

  // Dynamically fetch and sync profile immediately on app boot
  const userProfile = useUserProfile();

  useEffect(() => {
    const root = document.documentElement;
    if (darkMode) {
      root.classList.add('dark');
    } else {
      root.classList.remove('dark');
    }
    safeStorage.setItem('darkMode', JSON.stringify(darkMode));
  }, [darkMode]);

  const toggleDarkMode = () => {
    setDarkMode(!darkMode);
  };

  useEffect(() => {
    const syncOnboardingDone = () => setOnboardingDone(localStorage.getItem('nola_onboarding_done') === 'true');
    window.addEventListener('storage', syncOnboardingDone);
    window.addEventListener('ghl-location-changed', syncOnboardingDone);
    window.addEventListener('nola-onboarding-updated', syncOnboardingDone);
    return () => {
      window.removeEventListener('storage', syncOnboardingDone);
      window.removeEventListener('ghl-location-changed', syncOnboardingDone);
      window.removeEventListener('nola-onboarding-updated', syncOnboardingDone);
    };
  }, []);

  const openGettingStarted = () => {
    setOnboardingDone(false);
    window.dispatchEvent(new CustomEvent('open-onboarding', { detail: { step: 0 } }));
  };

  const handleTabChange = (tab: ViewTab) => {
    const urlMap: Record<ViewTab, string> = {
      home: '/',
      compose: '/compose',
      contacts: '/contacts',
      settings: '/settings/account',
      templates: '/templates',
      tickets: '/tickets',
    };
    navigate({ pathname: urlMap[tab] ?? '/', search: window.location.search });
  };

  const hideTogglePaths = ['/login', '/register-from-install', '/forgot-password'];
  const hideToggle = hideTogglePaths.includes(location.pathname.toLowerCase());
  const topControls = !hideToggle ? (
    <div className="hidden md:flex items-center gap-2 flex-shrink-0">
      <UserNotificationBell onTabChange={handleTabChange} variant="light" />
      <TopMoreOptions
        darkMode={darkMode}
        toggleDarkMode={toggleDarkMode}
        onboardingDone={onboardingDone}
        onOpenGettingStarted={openGettingStarted}
        onOpenTickets={() => setTicketsModalOpen(true)}
      />
    </div>
  ) : null;

  return (
    <UserProfileContext.Provider value={userProfile}>
      <div className="relative h-screen overflow-hidden bg-[#ffffff] dark:bg-[#1a1b1e]">
      <Routes>
        <Route path="/login"                  element={<SharedLogin />} />
        <Route path="/forgot-password"        element={<ForgotPassword />} />
        <Route path="/register"               element={<RedirectToBackend path="/register" />} />
        <Route path="/register-from-install"  element={<RedirectInstallRegistration />} />
        <Route path="/oauth/callback"         element={<GhlCallback />} />
        {/* Protected routes - requires a valid auth token */}
        <Route element={<ProtectedRoute />}>
          <Route
            path="/"
            element={
              window.location.search.includes('code=') ? (
                <GhlCallback />
              ) : (
                <Dashboard
                  isMobileMenuOpen={isMobileMenuOpen}
                  onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                  darkMode={darkMode}
                  toggleDarkMode={toggleDarkMode}
                  initialView="home"
                  topControls={topControls}
                />
              )
            }
          />
          <Route
            path="/compose"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="compose"
                topControls={topControls}
              />
            }
          />
          <Route
            path="/contacts"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="contacts"
                topControls={topControls}
              />
            }
          />
          <Route
            path="/settings"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="settings"
                settingsInitialTab="account"
                topControls={topControls}
              />
            }
          />
          <Route
            path="/settings/account"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="settings"
                settingsInitialTab="account"
                topControls={topControls}
              />
            }
          />
          <Route
            path="/settings/notifications"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="settings"
                settingsInitialTab="notifications"
                topControls={topControls}
              />
            }
          />
          <Route path="/settings/notification" element={<Navigate to="/settings/notifications" replace />} />
          <Route
            path="/settings/sender-id"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="settings"
                settingsInitialTab="senderIds"
                topControls={topControls}
              />
            }
          />
          <Route path="/settings/sender-ids" element={<Navigate to="/settings/sender-id" replace />} />
          <Route path="/settings/senderIds" element={<Navigate to="/settings/sender-id" replace />} />
          <Route
            path="/settings/credits"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="settings"
                settingsInitialTab="credits"
                topControls={topControls}
              />
            }
          />
          <Route
            path="/templates"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="templates"
                topControls={topControls}
              />
            }
          />
          <Route
            path="/tickets"
            element={
              <Dashboard
                isMobileMenuOpen={isMobileMenuOpen}
                onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                darkMode={darkMode}
                toggleDarkMode={toggleDarkMode}
                initialView="tickets"
                topControls={topControls}
              />
            }
          />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
      {ticketsModalOpen && (
        <div className="fixed inset-0 z-[140] flex items-center justify-center bg-black/45 p-4 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="relative flex h-[min(760px,calc(100dvh-2rem))] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-[#e5e5e5] bg-[#f7f7f7] shadow-2xl dark:border-white/10 dark:bg-[#111214]">
            <button
              type="button"
              onClick={() => setTicketsModalOpen(false)}
              className="absolute right-4 top-4 z-20 flex h-9 w-9 items-center justify-center rounded-xl border border-black/10 bg-white/90 text-[#5f6368] shadow-sm transition-colors hover:bg-[#f1f3f4] hover:text-[#111111] dark:border-white/10 dark:bg-[#1a1b1e]/90 dark:text-[#9aa0a6] dark:hover:bg-white/10 dark:hover:text-white"
              aria-label="Close tickets"
            >
              <FiX className="h-4 w-4" />
            </button>
            <div className="h-full overflow-y-auto">
              <TicketsTab />
            </div>
          </div>
        </div>
      )}
    </div>
    </UserProfileContext.Provider>
  );
};

const App: React.FC = () => {
  return (
    <AuthProvider>
      <LocationProvider>
        <BrowserRouter>
          <AppLayout />
        </BrowserRouter>
      </LocationProvider>
    </AuthProvider>
  );
};

export default App;
