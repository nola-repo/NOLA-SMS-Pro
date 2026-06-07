import React, { useState, useEffect } from "react";
import { BrowserRouter, Routes, Route, Navigate, useLocation } from "react-router-dom";
import { Dashboard } from "./pages/Dashboard";
import { GhlCallback } from "./pages/GhlCallback";
import SharedLogin from "./pages/SharedLogin";
import RegisterFromInstall from "./pages/RegisterFromInstall";
import { AuthProvider } from "./context/AuthContext";
import { LocationProvider } from "./context/LocationContext";
import { ProtectedRoute } from "./components/ProtectedRoute";
import { safeStorage } from "./utils/safeStorage";
import { useUserProfile } from "./hooks/useUserProfile";
import { UserProfileContext } from "./context/UserProfileContext";

const AppLayout: React.FC = () => {
  const userProfile = useUserProfile();
  const [darkMode, setDarkMode] = useState(() => {
    const saved = safeStorage.getItem('darkMode');
    if (saved !== null) {
      return JSON.parse(saved);
    }
    return false;
  });

  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const location = useLocation();


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

  const hideTogglePaths = ['/login', '/register-from-install'];
  const hideToggle = hideTogglePaths.includes(location.pathname.toLowerCase());

  return (
    <UserProfileContext.Provider value={userProfile}>
    <div className="h-screen bg-[#ffffff] dark:bg-[#1a1b1e]">
      {/* Theme Toggle - Fixed top right (Desktop only) */}
      {!hideToggle && (
        <div className="hidden md:flex fixed top-3 right-3 gap-2 z-50">
          <button
            onClick={toggleDarkMode}
            className="p-2 rounded-lg bg-[#f7f7f7] dark:bg-[#2a2b32] hover:bg-[#e8e8e8] dark:hover:bg-[#3a3b3f] text-[#37352f] dark:text-[#ececf1] shadow-sm transition-all duration-200"
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
        </div>
      )}

      <Routes>
        <Route path="/login"                  element={<SharedLogin darkMode={darkMode} toggleDarkMode={toggleDarkMode} />} />
        <Route path="/register"               element={<Navigate to="/login" replace />} />
        <Route path="/register-from-install"  element={<RegisterFromInstall />} />
        <Route path="/oauth/callback"         element={<GhlCallback />} />
        {/* Protected routes GÇö requires a valid auth token */}
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
              />
            }
          />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
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
