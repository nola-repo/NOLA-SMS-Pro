import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { FiGrid, FiToggleLeft, FiLogOut, FiZap, FiSun, FiMoon, FiUser, FiSettings } from 'react-icons/fi';
import { useAgency } from '../../context/AgencyContext.tsx';

export const AgencyLayout = ({ children, title, subtitle }) => {
  const { agencyId, agencySession, logout, darkMode, toggleDarkMode, isGhlFrame } = useAgency();
  const location = useLocation();

  const userName = agencySession?.user
    ? `${agencySession.user.firstName} ${agencySession.user.lastName}`.trim()
    : null;

  const navItems = [
    { to: '/', label: 'Dashboard', icon: <FiGrid /> },
    { to: '/subaccounts', label: 'Subaccounts', icon: <FiToggleLeft /> },
    { to: '/settings', label: 'Settings', icon: <FiSettings /> },
  ];

  return (
    <div className={`h-screen flex overflow-hidden bg-[#f7f7f7] dark:bg-[#111111] ${darkMode ? 'dark' : ''}`}>
      {/* Sidebar */}
      <div className="w-64 bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border-r border-[#0000000a] dark:border-[#ffffff0a] shadow-[1px_0_0_rgba(0,0,0,0.05)] flex flex-col z-20">
        <div className="p-6">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-[#1d6bd4] to-[#2b83fa] shadow-sm flex items-center justify-center shrink-0">
              <FiZap className="w-4 h-4 text-white" />
            </div>
            <div className="flex flex-col leading-tight">
              <span className="text-[13px] font-bold text-[#111111] dark:text-white tracking-tight">NOLA SMS Pro</span>
              <span className="text-[10px] font-medium text-[#2b83fa] tracking-wider uppercase">Agency</span>
            </div>
          </div>
        </div>

        <nav className="flex-1 px-4 py-4 space-y-1.5 overflow-y-auto sidebar-scroll">
          {navItems.map(({ to, label, icon }) => {
            const isActive = location.pathname === to || (to !== '/' && location.pathname.startsWith(to));
            return (
              <NavLink
                key={to}
                to={to}
                className={`w-full flex items-center gap-3 px-4 py-2.5 rounded-xl text-[14px] font-medium transition-all group ${
                  isActive
                    ? 'bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 text-[#2b83fa]'
                    : 'text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03] hover:text-[#111111] dark:hover:text-[#ececf1]'
                }`}
              >
                <span className={`transition-transform duration-300 ${isActive ? 'scale-110' : 'group-hover:scale-110 group-hover:text-[#2b83fa]'}`}>
                  {icon}
                </span>
                <span className={isActive ? 'font-bold' : ''}>{label}</span>
              </NavLink>
            );
          })}
        </nav>

        <div className="p-4 border-t border-[#00000005] dark:border-[#ffffff05] flex flex-col gap-2">
          {/* Logged-in user info */}
          {userName && (
            <div className="flex items-center gap-2 px-2 py-1.5">
              <div className="w-7 h-7 rounded-full bg-[#2b83fa]/10 flex items-center justify-center flex-shrink-0">
                <FiUser className="w-3.5 h-3.5 text-[#2b83fa]" />
              </div>
              <div className="min-w-0">
                <div className="text-[12px] font-semibold text-[#111111] dark:text-white truncate">{userName}</div>
                <div className="text-[10px] text-[#9aa0a6] truncate">{agencySession?.user?.email}</div>
              </div>
            </div>
          )}
          {/* Agency ID (dev helper) */}
          {agencyId && !userName && (
            <div className="px-2 text-[11px] text-[#6e6e73] dark:text-[#94959b]">
              Agency ID: <span className="font-mono text-[#111111] dark:text-[#ececf1] font-semibold">{agencyId.slice(0, 12)}…</span>
            </div>
          )}
          {isGhlFrame ? (
            <div className="px-2 py-1.5 flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse flex-shrink-0" />
              <span className="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                GoHighLevel Connected
              </span>
            </div>
          ) : (
            <button
              onClick={logout}
              className="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl text-[13px] font-bold text-[#6e6e73] dark:text-[#94959b] hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 transition-colors"
            >
              <FiLogOut className="w-4 h-4" /> Sign Out
            </button>
          )}
        </div>
      </div>

      {/* Main Content */}
      <div className="flex-1 flex flex-col h-full overflow-hidden">
        <header className="px-8 py-5 bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border-b border-[#0000000a] dark:border-[#ffffff0a] flex-shrink-0 flex items-center justify-between z-10">
          <div>
            <h2 className="text-xl font-bold text-[#111111] dark:text-white capitalize tracking-tight">
              {title || 'Agency Panel'}
            </h2>
            {subtitle && (
              <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">
                {subtitle}
              </p>
            )}
          </div>
          <button
            onClick={toggleDarkMode}
            className="p-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#1e2023] border border-[#e5e5e5] dark:border-white/5 text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white hover:bg-[#efefef] dark:hover:bg-white/5 transition-all shadow-sm"
            aria-label="Toggle theme"
            title={darkMode ? 'Switch to Light Mode' : 'Switch to Dark Mode'}
          >
            {darkMode ? <FiSun className="w-4 h-4" /> : <FiMoon className="w-4 h-4" />}
          </button>
        </header>

        <main className="flex-1 overflow-y-auto p-8">
          <div className="max-w-6xl mx-auto">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
};
