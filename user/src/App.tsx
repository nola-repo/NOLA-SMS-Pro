import React, { useState, useEffect, lazy, Suspense } from "react";
import { BrowserRouter, Routes, Route, Navigate, useLocation, useNavigate } from "react-router-dom";
import { Dashboard } from "./pages/Dashboard";

// Route-level lazy chunks
const GhlCallback    = lazy(() => import("./pages/GhlCallback"));
const ForgotPassword = lazy(() => import("./pages/ForgotPassword"));

import { AuthProvider } from "./context/AuthContext";
import { LocationProvider } from "./context/LocationContext";
import { ProtectedRoute } from "./components/ProtectedRoute";
import SharedLogin from "./components/SharedLogin";
import { safeStorage } from "./utils/safeStorage";
import { useUserProfile } from "./hooks/useUserProfile";
import { UserProfileContext } from "./context/UserProfileContext";
import { FiX } from "react-icons/fi";
import { UserNotificationBell } from "./components/ui/UserNotificationBell";
import type { ViewTab } from "./components/Sidebar";
import { TicketsTab } from "./components/TicketsTab";
import { TopMoreOptions } from "./components/layout/TopMoreOptions";
import { RedirectToBackend, RedirectInstallRegistration } from "./components/auth/RedirectHelpers";
import { ErrorBoundary } from "./components/ui/ErrorBoundary";

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
      <Suspense fallback={
        <div className="min-h-screen flex items-center justify-center bg-[#f7f8fc] dark:bg-[#09090b]">
          <div className="w-10 h-10 rounded-full border-4 border-[#2b83fa]/20 border-t-[#2b83fa] animate-spin" />
        </div>
      }>
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
      </Suspense>
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
    <ErrorBoundary appName="NOLA SMS Pro">
      <AuthProvider>
        <LocationProvider>
          <BrowserRouter>
            <AppLayout />
          </BrowserRouter>
        </LocationProvider>
      </AuthProvider>
    </ErrorBoundary>
  );
};

export default App;
