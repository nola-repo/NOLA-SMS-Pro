import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { FiUsers, FiSend, FiLogOut, FiHome, FiActivity, FiShield, FiSun, FiMoon, FiMenu, FiX, FiBriefcase, FiServer } from 'react-icons/fi';
import { NotificationBell } from '../components/ui/NotificationBell';
import faviconLogo from '../assets/FAV ICON - NOLA SMS PRO.png';

import { AdminLogin } from './components/AdminLogin';
import { AdminForgotPassword } from './components/AdminForgotPassword';
import { AdminDashboard } from './components/AdminDashboard';
import { AdminSenderRequests } from './components/SenderRequests';
import { AdminAccounts } from './components/AdminAccounts';
import { AdminTeamManagement } from './components/AdminUsersManagement';
import { AdminLogs } from './components/SystemSettings';
import { AdminAgencies } from './components/AdminAgencies';
import { AdminProfile } from './components/AdminProfile';
import { LogsExplorer } from './components/LogsExplorer';
import { ADMIN_AUTH_REQUIRED_EVENT, adminFetch } from '../utils/adminApi';
import { devLog } from '../utils/devLog';

const NAV_ITEMS = [
    { path: '/dashboard',  label: 'Dashboard',        icon: <FiHome /> },
    { path: '/requests',   label: 'Sender Requests',  icon: <FiSend /> },
    { path: '/activity',   label: 'Platform Activity', icon: <FiActivity /> },
    { path: '/logs-explorer', label: 'Logs Explorer',    icon: <FiServer /> },
    { path: '/accounts',   label: 'All Subaccounts',  icon: <FiUsers /> },
    { path: '/agencies',   label: 'All Agencies',     icon: <FiBriefcase /> },
    { path: '/admins',     label: 'Admin Users',      icon: <FiShield /> },
    // { path: '/settings',   label: 'System Settings',  icon: <FiSettings /> },
] as const;

const PAGE_HEADERS = {
    requests: {
        title: 'Sender Requests',
        subtitle: 'Review sender names, approval status, and account requests.',
    },
    activity: {
        title: 'Platform Activity',
        subtitle: 'Track SMS, credit, and billing events across all accounts.',
    },
    accounts: {
        title: 'All Subaccounts',
        subtitle: 'Manage connected subaccounts, credit balances, and sender access.',
    },
    'logs-explorer': {
        title: 'Logs Explorer',
        subtitle: 'Review live platform logs, SMS events, billing activity, and system signals.',
    },
    agencies: {
        title: 'All Agencies',
        subtitle: 'Monitor agency accounts and platform ownership at a glance.',
    },
    admins: {
        title: 'Admin Users',
        subtitle: 'Manage admin access, permissions, and team membership.',
    },
    profile: {
        title: 'Admin Profile',
        subtitle: 'Review your account identity, session security, and authentication settings.',
    },
    // settings: {
    //     title: 'System Settings',
    //     subtitle: 'Configure platform defaults, free tier limits, and admin controls.',
    // },
} as const;

const ADMIN_AUTH_KEYS = ['nola_admin_auth', 'nola_admin_user', 'nola_admin_token'] as const;
const ADMIN_REMEMBER_KEY = 'nola_admin_remember';
const ADMIN_IDLE_TIMEOUT_MS = 30 * 60 * 1000;

const isJwtExpired = (token: string) => {
    try {
        const payload = token.split('.')[1];
        if (!payload) return false;
        const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
        const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
        const decoded = JSON.parse(atob(padded));
        return typeof decoded.exp === 'number' && decoded.exp * 1000 <= Date.now();
    } catch {
        return false;
    }
};

const readRememberedAdminSession = () => {
    const token = sessionStorage.getItem('nola_admin_token') || localStorage.getItem('nola_admin_token');
    const remembered = localStorage.getItem(ADMIN_REMEMBER_KEY) === 'true';
    if (!token) return false;
    if (isJwtExpired(token)) {
        ADMIN_AUTH_KEYS.forEach(key => {
            sessionStorage.removeItem(key);
            localStorage.removeItem(key);
        });
        localStorage.removeItem(ADMIN_REMEMBER_KEY);
        return false;
    }

    if (!sessionStorage.getItem('nola_admin_token')) {
        sessionStorage.setItem('nola_admin_token', token);
        sessionStorage.setItem('nola_admin_auth', 'true');
        const user = localStorage.getItem('nola_admin_user');
        if (user) sessionStorage.setItem('nola_admin_user', user);
    }

    return sessionStorage.getItem('nola_admin_auth') === 'true' || remembered;
};

const readAdminIdentity = () => {
    const token = sessionStorage.getItem('nola_admin_token') || localStorage.getItem('nola_admin_token') || '';
    const storedUser = sessionStorage.getItem('nola_admin_user') || localStorage.getItem('nola_admin_user') || '';
    const storedName = sessionStorage.getItem('nola_admin_name') || localStorage.getItem('nola_admin_name') || '';
    let email = storedUser;
    let name = storedName;

    try {
        const payload = token.split('.')[1];
        if (payload) {
            const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
            const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
            const claims = JSON.parse(atob(padded));
            email = claims.email || claims.username || email;
            if (!name) {
                name = claims.name || claims.full_name || claims.display_name || '';
            }
        }
    } catch {
        // Stored username remains a useful fallback.
    }

    const displayEmail = email || 'admin@nolasmspro.com';
    const displayName = name || displayEmail.split('@')[0]?.replace(/[._-]+/g, ' ') || 'Admin Account';

    return {
        name: displayName.replace(/\b\w/g, char => char.toUpperCase()),
        email: displayEmail,
        initial: (displayName || displayEmail || 'A').charAt(0).toUpperCase(),
    };
};

const NavItems = ({ onNav }: { onNav?: () => void }) => {
    const navigate = useNavigate();
    const { pathname } = useLocation();

    return (
        <>
            {NAV_ITEMS.map(item => {
                const isActive = pathname === item.path || (pathname === '/' && item.path === '/dashboard');
                return (
                    <button
                        key={item.path}
                        onClick={() => { navigate(item.path); onNav?.(); }}
                        className={`
                          w-full flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all duration-300 relative group
                          ${isActive
                              ? 'bg-[#eceff3] text-[#111111] dark:bg-[#202327] dark:text-white'
                              : 'text-[#6e6e73] dark:text-[#94959b] hover:bg-black/[0.03] dark:hover:bg-white/[0.03] hover:text-[#111111] dark:hover:text-[#ececf1]'}
                        `}
                    >
                        {isActive && (
                          <div className="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-4 bg-[#111111] dark:bg-white/80 rounded-r-full shadow-sm" />
                        )}
                        <div className={`text-[19px] transition-all duration-500 ${isActive ? 'scale-110 text-[#111111] dark:text-white' : 'group-hover:scale-105 group-hover:text-[#111111] dark:group-hover:text-white'} active:scale-90`}>
                            {item.icon}
                        </div>
                        <span className={`text-[13.5px] transition-all duration-200 ${isActive ? 'font-bold tracking-tight' : 'font-medium'}`}>
                            {item.label}
                        </span>
                    </button>
                );
            })}
        </>
    );
};

const SidebarContent = ({ onNav, onLogout, identity }: { onNav?: () => void; onLogout: () => void; identity: ReturnType<typeof readAdminIdentity> }) => {
    const navigate = useNavigate();
    const { pathname } = useLocation();
    const isProfileActive = pathname === '/profile';

    return (
    <>
        <div className="px-4 pt-3 pb-2">
            <div className="flex items-center gap-3.5 group cursor-pointer transition-all">
                <div className="w-9 h-9 rounded-[10px] bg-white dark:bg-[#1a1b1e] border border-black/[0.06] dark:border-white/[0.08] shadow-sm flex items-center justify-center shrink-0 transition-all duration-500 relative overflow-hidden group-hover:rotate-3 group-hover:scale-105 active:scale-95">
                    <div className="transition-all duration-500 group-hover:rotate-[-6deg]">
                       <img src={faviconLogo} alt="NOLA SMS PRO" className="h-7 w-7 object-contain" />
                    </div>
                </div>
                <div className="flex flex-col">
                    <h2 className="text-[14.5px] font-extrabold text-[#111111] dark:text-white tracking-tight leading-none">
                        NOLA SMS PRO
                    </h2>
                    <div className="flex items-center gap-1.5 mt-0.5">
                        <span className="text-[10px] font-bold text-[#6e6e73] dark:text-[#94959b] uppercase tracking-widest opacity-80">Admin</span>
                    </div>
                </div>
            </div>
        </div>

        <nav className="flex-1 flex flex-col gap-0.5 px-3 pt-0 overflow-y-auto sidebar-scroll">
            <div className="px-2 pt-1 pb-1.5">
              <span className="text-[10.5px] font-bold text-[#9aa0a6] dark:text-[#5f6368] uppercase tracking-widest">Main Menu</span>
            </div>
            <NavItems onNav={onNav} />
        </nav>

        <div className="p-4 border-t border-[#00000005] dark:border-[#ffffff05]">
            <div className={`flex items-center gap-2 rounded-2xl border px-2.5 py-2 transition-colors ${
                isProfileActive
                    ? 'border-[#2b83fa]/30 bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15'
                    : 'border-transparent hover:bg-black/[0.03] dark:hover:bg-white/[0.04]'
            }`}>
                <button
                    type="button"
                    onClick={() => { navigate('/profile'); onNav?.(); }}
                    className="min-w-0 flex flex-1 items-center gap-2.5 text-left"
                    aria-label="Open admin profile"
                >
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] text-[13px] font-black text-white shadow-sm">
                        {identity.initial}
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-[12.5px] font-extrabold text-[#111111] dark:text-white">
                            {identity.name}
                        </span>
                        <span className="block truncate text-[10.5px] font-medium text-[#6e6e73] dark:text-[#94959b]">
                            {identity.email}
                        </span>
                    </span>
                </button>
                <button
                    type="button"
                    onClick={onLogout}
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl text-[#6e6e73] transition-colors hover:bg-red-50 hover:text-red-500 dark:text-[#94959b] dark:hover:bg-red-900/10"
                    aria-label="Log out"
                    title="Log out"
                >
                    <FiLogOut className="h-4 w-4" />
                </button>
            </div>
        </div>
    </>
    );
};

export const AdminLayout: React.FC<{ darkMode: boolean; toggleDarkMode: () => void }> = ({ darkMode, toggleDarkMode }) => {
    const [isAuthenticated, setIsAuthenticated] = useState(readRememberedAdminSession);
    const [isMobileOpen, setIsMobileOpen] = useState(false);
    const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);
    const [authNotice, setAuthNotice] = useState<string | null>(null);
    const [adminName, setAdminName] = useState(() => {
        return sessionStorage.getItem('nola_admin_name') || localStorage.getItem('nola_admin_name') || '';
    });
    const idleTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const navigate = useNavigate();
    const { pathname } = useLocation();
    const baseIdentity = readAdminIdentity();
    const adminIdentity = React.useMemo(() => {
        if (!adminName) return baseIdentity;
        const formattedName = adminName.replace(/\b\w/g, char => char.toUpperCase());
        return {
            ...baseIdentity,
            name: formattedName,
            initial: (formattedName || baseIdentity.email || 'A').charAt(0).toUpperCase(),
        };
    }, [adminName, baseIdentity.email]);

    const clearAdminSession = useCallback((includeRemembered = true, notice?: string) => {
        ADMIN_AUTH_KEYS.forEach(key => sessionStorage.removeItem(key));
        sessionStorage.removeItem('nola_admin_name');
        if (includeRemembered) {
            ADMIN_AUTH_KEYS.forEach(key => localStorage.removeItem(key));
            localStorage.removeItem(ADMIN_REMEMBER_KEY);
            localStorage.removeItem('nola_admin_name');
        }
        setAdminName('');
        setShowLogoutConfirm(false);
        setAuthNotice(notice || null);
        setIsAuthenticated(false);
        navigate('/dashboard');
    }, [navigate]);

    useEffect(() => {
        if (!isAuthenticated) {
            document.title = 'Admin Login | NOLA SMS Pro';
            return;
        }

        const routeKey = pathname.replace(/^\//, '') || 'dashboard';
        const routeTitle = routeKey === 'dashboard'
            ? 'Admin Dashboard'
            : PAGE_HEADERS[routeKey as keyof typeof PAGE_HEADERS]?.title || 'Admin Dashboard';
        document.title = `${routeTitle} | NOLA SMS Pro Admin`;
    }, [isAuthenticated, pathname]);

    const resetIdleTimer = useCallback(() => {
        if (idleTimerRef.current) clearTimeout(idleTimerRef.current);
        if (localStorage.getItem(ADMIN_REMEMBER_KEY) === 'true') return;
        idleTimerRef.current = setTimeout(() => clearAdminSession(false, 'Your admin session timed out after 30 minutes of inactivity. Please sign in again to continue.'), ADMIN_IDLE_TIMEOUT_MS);
    }, [clearAdminSession]);

    useEffect(() => {
        if (!isAuthenticated) return;
        const events = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'];
        events.forEach(e => window.addEventListener(e, resetIdleTimer, { passive: true }));
        resetIdleTimer();
        return () => {
            events.forEach(e => window.removeEventListener(e, resetIdleTimer));
            if (idleTimerRef.current) clearTimeout(idleTimerRef.current);
        };
    }, [isAuthenticated, resetIdleTimer]);

    useEffect(() => {
        const handleAdminAuthRequired = (event: Event) => {
            const detail = (event as CustomEvent<{ message?: string }>).detail;
            clearAdminSession(true, detail?.message || 'Your admin access could not be verified. Please sign in again to continue.');
        };
        window.addEventListener(ADMIN_AUTH_REQUIRED_EVENT, handleAdminAuthRequired);
        return () => window.removeEventListener(ADMIN_AUTH_REQUIRED_EVENT, handleAdminAuthRequired);
    }, [clearAdminSession]);

    useEffect(() => {
        if (!isAuthenticated) return;

        const fetchSelf = async () => {
            try {
                const res = await adminFetch('/api/admin_auth.php');
                if (res.ok) {
                    const json = await res.json().catch(() => ({}));
                    if (json.status === 'success' && json.name) {
                        setAdminName(json.name);
                        sessionStorage.setItem('nola_admin_name', json.name);
                        if (localStorage.getItem('nola_admin_remember') === 'true') {
                            localStorage.setItem('nola_admin_name', json.name);
                        }
                    }
                }
            } catch (err) {
                devLog.error('Failed to fetch admin self profile:', err);
            }
        };

        fetchSelf();
    }, [isAuthenticated]);

    useEffect(() => {
        const handleNameUpdated = (event: Event) => {
            const detail = (event as CustomEvent<{ name: string }>).detail;
            if (detail?.name) {
                setAdminName(detail.name);
            }
        };
        window.addEventListener('nola-admin-name-updated', handleNameUpdated);
        return () => window.removeEventListener('nola-admin-name-updated', handleNameUpdated);
    }, []);

    const handleLogin = (username: string, token: string, rememberMe = true, name?: string) => {
        sessionStorage.setItem('nola_admin_auth', 'true');
        sessionStorage.setItem('nola_admin_user', username);
        sessionStorage.setItem('nola_admin_token', token);
        if (name) {
            sessionStorage.setItem('nola_admin_name', name);
            setAdminName(name);
        }
        if (rememberMe) {
            localStorage.setItem('nola_admin_auth', 'true');
            localStorage.setItem('nola_admin_user', username);
            localStorage.setItem('nola_admin_token', token);
            localStorage.setItem(ADMIN_REMEMBER_KEY, 'true');
            if (name) {
                localStorage.setItem('nola_admin_name', name);
            }
        } else {
            ADMIN_AUTH_KEYS.forEach(key => localStorage.removeItem(key));
            localStorage.removeItem('nola_admin_name');
            localStorage.removeItem(ADMIN_REMEMBER_KEY);
        }
        setAuthNotice(null);
        setIsAuthenticated(true);
    };

    const handleLogout = () => {
        clearAdminSession();
    };

    if (!isAuthenticated) {
        return (
            <Routes>
                <Route path="/forgot-password" element={<AdminForgotPassword darkMode={darkMode} toggleDarkMode={toggleDarkMode} />} />
                <Route path="*" element={<AdminLogin onLogin={handleLogin} darkMode={darkMode} toggleDarkMode={toggleDarkMode} notice={authNotice} />} />
            </Routes>
        );
    }

    const renderMobileMenuButton = (light = false) => (
        <button
            onClick={() => setIsMobileOpen(true)}
            className={`md:hidden flex h-10 w-10 items-center justify-center rounded-xl transition-all active:scale-95 ${
                light
                    ? 'bg-white/10 border border-white/20 text-white hover:bg-white/20'
                    : 'bg-white dark:bg-[#1c1e21] border border-[#e5e5e5] dark:border-white/10 text-[#111111] dark:text-white shadow-sm hover:bg-[#f7f7f7] dark:hover:bg-white/10'
            }`}
            aria-label="Open menu"
        >
            <FiMenu className="w-5 h-5" />
        </button>
    );

    const dashboardTopControls = (
        <div className="flex items-center gap-2">
            <NotificationBell variant="light" />
            <button
                onClick={toggleDarkMode}
                className="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 border border-white/20 text-white hover:bg-white/20 transition-all shadow-sm"
                aria-label="Toggle theme"
                title={darkMode ? 'Switch to Light Mode' : 'Switch to Dark Mode'}
            >
                {darkMode ? <FiSun className="w-4 h-4" /> : <FiMoon className="w-4 h-4" />}
            </button>
        </div>
    );

    const renderPage = (page: React.ReactNode, pageKey: keyof typeof PAGE_HEADERS) => {
        const header = PAGE_HEADERS[pageKey];

        return (
        <div className="relative min-h-full bg-[#f3f4f6] dark:bg-[#09090b]">
            <div className="absolute left-0 top-0 h-[132px] w-full rounded-b-[28px] bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] pointer-events-none" />
            <div className="relative z-10 mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                <div className="mb-14 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-3 text-white">
                        {renderMobileMenuButton(true)}
                        <div className="min-w-0">
                            <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight leading-tight">
                                {header.title}
                            </h1>
                            <p className="mt-1 max-w-2xl text-[14px] sm:text-[15px] font-semibold text-white/80">
                                {header.subtitle}
                            </p>
                        </div>
                    </div>
                    <div className="ml-auto flex items-center gap-2">
                        {dashboardTopControls}
                    </div>
                </div>
                <div className="pb-8">
                    {page}
                </div>
            </div>
        </div>
        );
    };

    return (
        <div className={`h-screen flex overflow-hidden bg-[#f3f4f6] dark:bg-[#09090b] ${darkMode ? 'dark' : ''}`}>
            <div className="hidden md:flex w-64 bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border-r border-[#0000000a] dark:border-[#ffffff0a] shadow-[1px_0_0_rgba(0,0,0,0.05)] flex-col z-20 flex-shrink-0">
                <SidebarContent identity={adminIdentity} onLogout={() => setShowLogoutConfirm(true)} />
            </div>

            {isMobileOpen && (
                <div
                    className="fixed inset-0 bg-black/40 backdrop-blur-[2px] z-[90] md:hidden"
                    onClick={() => setIsMobileOpen(false)}
                />
            )}

            <div className={`fixed inset-y-0 left-0 z-[100] md:hidden w-72 bg-white/95 dark:bg-[#121415]/95 backdrop-blur-2xl border-r border-[#0000000a] dark:border-[#ffffff0a] shadow-2xl flex flex-col transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)] ${isMobileOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="absolute top-3 right-3">
                    <button
                        onClick={() => setIsMobileOpen(false)}
                        className="p-2 rounded-xl text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 transition-all"
                        aria-label="Close menu"
                    >
                        <FiX className="h-5 w-5" />
                    </button>
                </div>
                <SidebarContent
                    identity={adminIdentity}
                    onNav={() => setIsMobileOpen(false)}
                    onLogout={() => {
                        setIsMobileOpen(false);
                        setShowLogoutConfirm(true);
                    }}
                />
            </div>

            <div className="flex-1 flex flex-col h-full overflow-hidden min-w-0">
                <main className="flex-1 overflow-y-auto custom-scrollbar bg-[#f3f4f6] dark:bg-[#09090b]">
                    <Routes>
                        <Route path="/" element={<Navigate to="/dashboard" replace />} />
                        <Route
                            path="/dashboard"
                            element={(
                                <AdminDashboard
                                    onNavigate={(tab) => navigate(`/${tab}`)}
                                    mobileMenuButton={renderMobileMenuButton(true)}
                                    topControls={dashboardTopControls}
                                />
                            )}
                        />
                        <Route path="/requests" element={renderPage(<AdminSenderRequests />, 'requests')} />
                        <Route path="/activity" element={renderPage(<AdminLogs />, 'activity')} />
                        <Route path="/accounts" element={renderPage(<AdminAccounts />, 'accounts')} />
                        <Route path="/logs-explorer" element={renderPage(<LogsExplorer />, 'logs-explorer')} />
                        <Route path="/health" element={<Navigate to="/logs-explorer" replace />} />
                        <Route path="/agencies" element={renderPage(<AdminAgencies />, 'agencies')} />
                        <Route path="/profile" element={renderPage(<AdminProfile />, 'profile')} />
                        <Route path="/admins" element={renderPage(<AdminTeamManagement />, 'admins')} />
                        <Route path="/settings" element={<Navigate to="/dashboard" replace />} />
                        <Route path="*" element={<Navigate to="/dashboard" replace />} />
                    </Routes>
                </main>
            </div>

            {showLogoutConfirm && (
                <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="w-full max-w-sm overflow-hidden rounded-2xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1a1b1e] shadow-2xl animate-in zoom-in-95 duration-200">
                        <div className="p-6">
                            <div className="w-12 h-12 rounded-2xl bg-red-50 dark:bg-red-500/10 text-red-500 flex items-center justify-center mb-4">
                                <FiLogOut className="w-5 h-5" />
                            </div>
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white mb-2">Log out of Admin Panel?</h3>
                            <p className="text-[13px] leading-relaxed text-[#6e6e73] dark:text-[#9aa0a6]">
                                This will clear the current admin session and return you to the login screen.
                            </p>
                        </div>
                        <div className="flex justify-end gap-3 border-t border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-black/30 p-4">
                            <button
                                onClick={() => setShowLogoutConfirm(false)}
                                className="px-4 py-2 rounded-xl text-[13px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-white dark:hover:bg-white/10 transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={handleLogout}
                                className="px-5 py-2 rounded-xl text-[13px] font-bold text-white bg-red-600 hover:bg-red-700 shadow-sm transition-colors"
                            >
                                Log Out
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
