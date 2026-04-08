// @ts-nocheck
import React, { useState, useEffect, useCallback } from 'react';
import { FiUsers, FiSend, FiSettings, FiLogOut, FiLock, FiAlertCircle, FiEye, FiEyeOff, FiCopy, FiCheck, FiX, FiRefreshCw, FiKey, FiHome, FiClock, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiPlus, FiMinus, FiTrash2, FiChevronLeft, FiChevronRight, FiSearch, FiSun, FiMoon, FiMoreVertical, FiToggleLeft } from 'react-icons/fi';
import logoUrl from '../assets/NOLA SMS PRO Logo.png';
import Antigravity from '../components/ui/Antigravity';

const ADMIN_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync


import { AdminLogin } from './components/AdminLogin';
import { AdminDashboard } from './components/AdminDashboard';
import { AdminSenderRequests } from './components/SenderRequests';
import { AdminAccounts } from './components/AdminAccounts';
import { AdminTeamManagement } from './components/AdminUsersManagement';
import { AdminLogs, AdminSettings } from './components/SystemSettings';


export const AdminLayout: React.FC<AdminLayoutProps> = ({ darkMode, toggleDarkMode }) => {
    const [isAuthenticated, setIsAuthenticated] = useState(() => {
        return localStorage.getItem('nola_admin_auth') === 'true';
    });
    const [activeTab, setActiveTab] = useState<'dashboard' | 'accounts' | 'requests' | 'admins' | 'activity' | 'settings'>('dashboard');

    const handleLogin = (username: string) => {
        localStorage.setItem('nola_admin_auth', 'true');
        localStorage.setItem('nola_admin_user', username);
        setIsAuthenticated(true);
        // Fire-and-forget: record last_login to backend
        fetch('/api/admin_users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'record_login', username }),
        }).catch(() => {}); // Silently ignore if backend not ready
    };

    const handleLogout = () => {
        localStorage.removeItem('nola_admin_auth');
        setIsAuthenticated(false);
    };

    if (!isAuthenticated) {
        return <AdminLogin onLogin={handleLogin} />;
    }

    return (
        <div className={`h-screen flex overflow-hidden bg-[#f7f7f7] dark:bg-[#111111] ${darkMode ? 'dark' : ''}`}>
            {/* Admin Sidebar */}
            <div className="w-64 bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border-r border-[#0000000a] dark:border-[#ffffff0a] shadow-[1px_0_0_rgba(0,0,0,0.05)] flex flex-col z-20">
                <div className="p-6">
                    <h1 className="text-xl font-black text-[#111111] dark:text-white tracking-tight flex items-center gap-2">
                        <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] shadow-sm flex items-center justify-center">
                            <FiLock className="w-3.5 h-3.5 text-white" />
                        </div>
                        NOLA Admin
                    </h1>
                </div>

                <nav className="flex-1 px-4 py-2 space-y-1.5">
                    {[
                        { id: 'dashboard', label: 'Dashboard', icon: <FiHome /> },
                        { id: 'requests', label: 'Sender Requests', icon: <FiSend /> },
                        { id: 'activity', label: 'Platform Activity', icon: <FiActivity /> },
                        { id: 'accounts', label: 'All Accounts', icon: <FiUsers /> },
                        { id: 'admins', label: 'Admin Users', icon: <FiShield /> },
                        { id: 'settings', label: 'System Settings', icon: <FiSettings /> },
                    ].map(tab => {
                        const isActive = activeTab === tab.id;
                        return (
                            <button
                                key={tab.id}
                                onClick={() => setActiveTab(tab.id as any)}
                                className={`w-full flex items-center gap-3 px-4 py-2.5 rounded-xl text-[14px] font-medium transition-all group ${
                                    isActive
                                        ? 'bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 text-[#2b83fa]'
                                        : 'text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03] hover:text-[#111111] dark:hover:text-[#ececf1]'
                                }`}
                            >
                                <span className={`transition-transform duration-300 ${isActive ? 'scale-110' : 'group-hover:scale-110 group-hover:text-[#2b83fa]'}`}>{tab.icon}</span>
                                <span className={isActive ? 'font-bold' : ''}>{tab.label}</span>
                            </button>
                        );
                    })}
                </nav>

                <div className="p-4 border-t border-[#00000005] dark:border-[#ffffff05]">
                    <button
                        onClick={handleLogout}
                        className="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl text-[13px] font-bold text-[#6e6e73] dark:text-[#94959b] hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 transition-colors"
                    >
                        <FiLogOut /> Logout
                    </button>
                </div>
            </div>

            {/* Main Content Area */}
            <div className="flex-1 flex flex-col h-full overflow-hidden">
                <header className="px-8 py-5 bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border-b border-[#0000000a] dark:border-[#ffffff0a] flex-shrink-0 flex items-center justify-between">
                    <div>
                        <h2 className="text-xl font-bold text-[#111111] dark:text-white capitalize tracking-tight">
                            {activeTab === 'dashboard' ? 'Dashboard' : activeTab === 'requests' ? 'Sender Requests' : activeTab === 'accounts' ? 'All Accounts' : activeTab === 'admins' ? 'Admin Users' : activeTab === 'activity' ? 'Platform Activity' : 'System Settings'}
                        </h2>
                        <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">
                            {activeTab === 'dashboard' ? 'Platform-wide overview of all accounts and activity.' : 'Management overview and administrative actions.'}
                        </p>
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

                <main className="flex-1 overflow-y-auto p-8 custom-scrollbar">
                    <div className="max-w-6xl mx-auto">
                        {activeTab === 'dashboard' && <AdminDashboard onNavigate={setActiveTab} />}
                        {activeTab === 'requests' && <AdminSenderRequests />}
                        {activeTab === 'accounts' && <AdminAccounts />}
                        {activeTab === 'admins' && <AdminTeamManagement />}
                        {activeTab === 'activity' && <AdminLogs />}
                        {activeTab === 'settings' && <AdminSettings />}
                    </div>
                </main>
            </div>
        </div>
    );
};

