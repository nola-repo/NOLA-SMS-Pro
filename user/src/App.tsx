import React, { useState, useEffect } from "react";
import { BrowserRouter, Routes, Route, Navigate, useLocation } from "react-router-dom";
import { Dashboard } from "./pages/Dashboard";
import { useGhlLocation } from "./hooks/useGhlLocation";
import { GhlCallback } from "./pages/GhlCallback";
import SharedLogin from "./pages/SharedLogin";
import Register from "./pages/Register";
import { AuthProvider } from "./context/AuthContext";
import { ProtectedRoute } from "./components/ProtectedRoute";
import { safeStorage } from "./utils/safeStorage";

const AppLayout: React.FC = () => {
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

  const isLoginPage = location.pathname.toLowerCase() === '/login';

  return (
    <div className="h-screen bg-[#ffffff] dark:bg-[#1a1b1e]">
      {/* Theme Toggle - Fixed top right (Desktop only) */}
      {!isLoginPage && location.pathname.toLowerCase() !== '/register' && (
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
        <Route path="/login"            element={<SharedLogin darkMode={darkMode} toggleDarkMode={toggleDarkMode} />} />
        <Route path="/register"         element={<Register darkMode={darkMode} toggleDarkMode={toggleDarkMode} />} />
        <Route path="/oauth/callback"   element={<GhlCallback />} />
        {/* Protected routes — requires a valid auth token */}
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
                />
              )
            }
          />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </div>
  );
};

const App: React.FC = () => {
  const locationId = useGhlLocation();

  useEffect(() => {
    if (!locationId) return;
    window.dispatchEvent(
      new CustomEvent("ghl-location-changed", { detail: { locationId } })
    );
  }, [locationId]);

  return (
    <AuthProvider>
      <BrowserRouter>
        <AppLayout />
      </BrowserRouter>
    </AuthProvider>
  );
};

export default App;

