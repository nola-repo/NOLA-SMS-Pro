import React, { useState, useEffect } from "react";
import { AdminLayout } from "./pages/AdminLayout";

export const AdminApp: React.FC = () => {
  const [darkMode, setDarkMode] = useState(() => {
    const saved = localStorage.getItem('darkMode');
    return saved !== null ? JSON.parse(saved) : false;
  });

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
    <div className="h-screen w-full">
      <AdminLayout darkMode={darkMode} toggleDarkMode={toggleDarkMode} />
    </div>
  );
};
