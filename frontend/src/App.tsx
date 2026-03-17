import React, { useState, useEffect } from "react";
import { Dashboard } from "./pages/Dashboard";
import { useGhlLocation } from "./hooks/useGhlLocation";
import { GhlCallback } from "./pages/GhlCallback";

const App: React.FC = () => {
  // Initialize GHL Location detection at root level so it captures the URL immediately
  useGhlLocation();

  const [darkMode, setDarkMode] = useState(() => {
    const saved = localStorage.getItem('darkMode');
    if (saved !== null) {
      return JSON.parse(saved);
    }
    // Default to system preference or dark mode
    if (typeof window !== 'undefined') {
      return window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
    return false;
  });

  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

  useEffect(() => {
    const root = document.documentElement;
    if (darkMode) {
      root.classList.add('dark');
    } else {
      root.classList.remove('dark');
    }
    localStorage.setItem('darkMode', JSON.stringify(darkMode));
  }, [darkMode]);

  const toggleDarkMode = () => {
    setDarkMode(!darkMode);
  };

  return (
    <div className="h-screen">
      {/* Theme Toggle - Fixed top right (Desktop only) */}
      <button
        onClick={toggleDarkMode}
        className={`hidden md:block fixed top-3 right-3 p-2 rounded-lg bg-[#f7f7f7] dark:bg-[#2a2b32] hover:bg-[#e8e8e8] dark:hover:bg-[#3a3b3f] text-[#37352f] dark:text-[#ececf1] shadow-sm transition-all duration-200 z-50`}
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

      {/* Basic Routing: if the URL has ?code= from GoHighLevel, render the callback component */}
      {window.location.search.includes('code=') ? (
        <GhlCallback />
      ) : (
        <Dashboard
          isMobileMenuOpen={isMobileMenuOpen}
          onMobileMenuToggle={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
          darkMode={darkMode}
          toggleDarkMode={toggleDarkMode}
        />
      )}
    </div>
  );
};

export default App;
