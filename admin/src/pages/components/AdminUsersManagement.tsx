// @ts-nocheck
import React, { useState, useEffect, useCallback } from 'react';
import { FiUsers, FiSend, FiSettings, FiLogOut, FiLock, FiAlertCircle, FiEye, FiEyeOff, FiCopy, FiCheck, FiX, FiRefreshCw, FiKey, FiHome, FiClock, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiPlus, FiMinus, FiTrash2, FiChevronLeft, FiChevronRight, FiSearch, FiSun, FiMoon, FiMoreVertical, FiToggleLeft } from 'react-icons/fi';
import logoUrl from '../../assets/NOLA SMS PRO Logo.png';
import Antigravity from '../../components/ui/Antigravity';

const ADMIN_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync



export const AdminTeamManagement: React.FC = () => {
    const [admins, setAdmins] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [successMsg, setSuccessMsg] = useState<string | null>(null);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');

    // Action menu – uses fixed positioning to avoid table clipping
    const [actionMenuId, setActionMenuId] = useState<string | null>(null);
    const [menuPos, setMenuPos] = useState<{ top: number; right: number }>({ top: 0, right: 0 });
    const menuRef = React.useRef<HTMLDivElement>(null);

    // Reset Password modal state
    const [resetTarget, setResetTarget] = useState<string | null>(null);
    const [resetPassword, setResetPassword] = useState('');
    const [showResetPw, setShowResetPw] = useState(false);

    const [newUsername, setNewUsername] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [newRole, setNewRole] = useState('support');
    const [actionLoading, setActionLoading] = useState(false);
    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    // Close menu on outside click
    useEffect(() => {
        if (!actionMenuId) return;
        const handler = (e: MouseEvent) => {
            if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
                setActionMenuId(null);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [actionMenuId]);

    const openMenu = (username: string, btn: HTMLButtonElement) => {
        const rect = btn.getBoundingClientRect();
        setMenuPos({ top: rect.bottom + 4, right: window.innerWidth - rect.right });
        setActionMenuId(prev => prev === username ? null : username);
    };

    const formatLastLogin = (ts: string | null | undefined) => {
        if (!ts) return <span className="italic opacity-40">Never</span>;
        const d = new Date(ts);
        const diffMs = Date.now() - d.getTime();
        const diffMin = Math.floor(diffMs / 60000);
        if (diffMin < 1) return 'Just now';
        if (diffMin < 60) return `${diffMin}m ago`;
        const diffH = Math.floor(diffMin / 60);
        if (diffH < 24) return `${diffH}h ago`;
        return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const fetchAdmins = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        try {
            const res = await fetch('/api/admin_users.php');
            if (res.ok) {
                const json = await res.json();
                if (json.status === 'success') setAdmins(json.data || []);
                else setError(json.message || 'Failed to fetch admin users.');
            } else {
                // Graceful fallback before backend is deployed
                setAdmins(prev => prev.length ? prev : [{ username: 'admin', role: 'super_admin', created_at: new Date().toISOString().split('T')[0], active: true, last_login: null }]);
            }
            setLastRefreshed(new Date());
        } catch {
            setAdmins(prev => prev.length ? prev : [{ username: 'admin', role: 'super_admin', created_at: new Date().toISOString().split('T')[0], active: true, last_login: null }]);
        } finally { if (isInitial) setLoading(false); }
    }, []);

    useEffect(() => {
        fetchAdmins(true);
        const timer = setInterval(() => fetchAdmins(false), POLL_INTERVAL);
        return () => clearInterval(timer);
    }, [fetchAdmins]);

    const toast = (msg: string, isError = false) => {
        if (isError) { setError(msg); setTimeout(() => setError(null), 4000); }
        else { setSuccessMsg(msg); setTimeout(() => setSuccessMsg(null), 3500); }
    };

    const handleCreateAdmin = async (e: React.FormEvent) => {
        e.preventDefault();
        setActionLoading(true);
        try {
            const res = await fetch('/api/admin_users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'create', username: newUsername, password: newPassword, role: newRole })
            });
            if (res.ok) {
                const json = await res.json();
                if (json.status === 'success') {
                    toast('Admin user created successfully.');
                    setShowCreateModal(false);
                    setNewUsername(''); setNewPassword(''); setNewRole('support');
                    fetchAdmins();
                } else { toast(json.message || 'Failed to create admin.', true); }
            } else {
                // Optimistic mock when backend not yet deployed
                toast('Admin user created (pending backend deployment).');
                setAdmins(prev => [...prev, { username: newUsername, role: newRole, created_at: new Date().toISOString().split('T')[0], active: true, last_login: null }]);
                setShowCreateModal(false);
                setNewUsername(''); setNewPassword(''); setNewRole('support');
            }
        } catch { toast('Network error creating admin.', true); }
        finally { setActionLoading(false); }
    };

    const handleResetPassword = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!resetTarget || !resetPassword.trim()) return;
        setActionLoading(true);
        try {
            const res = await fetch('/api/admin_users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reset_password', username: resetTarget, new_password: resetPassword })
            });
            if (res.ok) {
                const json = await res.json();
                if (json.status === 'success') {
                    toast(`Password for "${resetTarget}" has been reset.`);
                    setResetTarget(null); setResetPassword('');
                } else { toast(json.message || 'Failed to reset password.', true); }
            } else {
                toast(`Password reset queued (backend pending deployment).`);
                setResetTarget(null); setResetPassword('');
            }
        } catch { toast('Network error resetting password.', true); }
        finally { setActionLoading(false); }
    };

    const handleToggleStatus = async (admin: any) => {
        setActionMenuId(null);
        const newActive = !(admin.active !== false);
        // Optimistic update
        setAdmins(prev => prev.map(a => a.username === admin.username ? { ...a, active: newActive } : a));
        try {
            const res = await fetch('/api/admin_users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle_status', username: admin.username, active: newActive })
            });
            if (res.ok) {
                const json = await res.json();
                if (json.status === 'success') {
                    toast(`${admin.username} is now ${newActive ? 'active' : 'inactive'}.`);
                    fetchAdmins();
                } else {
                    // Revert optimistic update
                    setAdmins(prev => prev.map(a => a.username === admin.username ? { ...a, active: !newActive } : a));
                    toast(json.message || 'Failed to toggle status.', true);
                }
            } else {
                toast(`Status updated (backend pending deployment).`);
            }
        } catch {
            setAdmins(prev => prev.map(a => a.username === admin.username ? { ...a, active: !newActive } : a));
            toast('Network error toggling status.', true);
        }
    };

    const handleDeleteAdmin = async (usernameToDelete: string) => {
        if (!confirm(`Are you sure you want to delete '${usernameToDelete}'? This cannot be undone.`)) return;
        setActionLoading(true); setActionMenuId(null);
        try {
            const res = await fetch('/api/admin_users.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: usernameToDelete })
            });
            if (res.ok) {
                const json = await res.json();
                if (json.status === 'success') { toast(`Admin "${usernameToDelete}" deleted.`); fetchAdmins(); }
                else toast(json.message || 'Failed to delete admin.', true);
            } else {
                toast(`Admin "${usernameToDelete}" deleted (pending backend deployment).`);
                setAdmins(prev => prev.filter(a => a.username !== usernameToDelete));
            }
        } catch { toast('Network error deleting admin.', true); }
        finally { setActionLoading(false); }
    };

    const getRoleBadge = (role: string) => {
        const map: Record<string, { bg: string; label: string }> = {
            super_admin: { bg: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30', label: 'Super Admin' },
            support:     { bg: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-900/10 dark:text-purple-400 dark:border-purple-800/30', label: 'Support' },
            viewer:      { bg: 'bg-gray-50 text-gray-600 border-gray-200 dark:bg-gray-900/10 dark:text-gray-400 dark:border-gray-800/30', label: 'Viewer' },
        };
        const style = map[role] || map.viewer;
        return <span className={`inline-flex px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider border ${style.bg}`}>{style.label || role}</span>;
    };

    const filtered = admins.filter(a =>
        a.username?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        a.role?.toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-6 shadow-sm">
            <div className="flex items-center justify-end mb-2">
                {!loading && <span className="text-[10px] text-[#9aa0a6] font-medium uppercase tracking-tight">Synced: {lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>}
            </div>
            <div className="flex items-center justify-between mb-5">
                <div>
                    <h3 className="text-[16px] font-bold text-[#111111] dark:text-white">Admin Users</h3>
                    <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">Manage dashboard access and role permissions.</p>
                </div>
                <button onClick={() => setShowCreateModal(true)} className="flex items-center gap-2 px-4 py-2 bg-[#111111] dark:bg-white text-white dark:text-[#111111] rounded-xl font-bold text-[13px] hover:bg-[#333333] dark:hover:bg-[#e5e5e5] transition-colors">
                    <FiPlus className="w-4 h-4" /> Create Admin
                </button>
            </div>

            {/* Search */}
            <div className="relative mb-5">
                <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] w-3.5 h-3.5" />
                <input type="text" placeholder="Search by username or role..." value={searchTerm} onChange={e => setSearchTerm(e.target.value)}
                    className="w-full pl-9 pr-4 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] text-[#111111] dark:text-white placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all font-medium"
                />
                {searchTerm && <button onClick={() => setSearchTerm('')} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"><FiX className="w-3 h-3" /></button>}
            </div>

            {successMsg && <div className="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-800/30 text-green-700 dark:text-green-400 text-[13px] font-medium animate-in fade-in duration-200"><FiCheck className="w-4 h-4 flex-shrink-0" /> {successMsg}</div>}
            {error && <div className="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/20 text-red-600 dark:text-red-400 text-[13px] font-medium animate-in fade-in duration-200"><FiAlertCircle className="w-4 h-4 flex-shrink-0" /> {error}</div>}

            <div className="overflow-x-auto pb-4">
                <table className="w-full text-left border-collapse">
                    <thead>
                        <tr className="border-b-2 border-[#e5e5e5] dark:border-white/10 text-[11px] font-black text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-widest">
                            <th className="pb-3 pl-4">Username</th>
                            <th className="pb-3">Role</th>
                            <th className="pb-3">Status</th>
                            <th className="pb-3">Created</th>
                            <th className="pb-3">Last Login</th>
                            <th className="pb-3 text-right pr-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[#f0f0f0] dark:divide-white/5 text-[14px]">
                        {loading ? (
                            <tr><td colSpan={6} className="py-8 text-center"><div className="flex items-center justify-center gap-2 text-[#9aa0a6] text-[13px]"><FiRefreshCw className="w-4 h-4 animate-spin" /> Loading admins...</div></td></tr>
                        ) : filtered.length === 0 ? (
                            <tr><td colSpan={6} className="py-12 text-center"><FiShield className="w-8 h-8 mx-auto mb-2 text-[#d0d0d0] dark:text-[#3a3b3f]" /><p className="text-[13px] text-[#9aa0a6] font-medium">{searchTerm ? 'No admins match your search.' : 'No admin users found.'}</p></td></tr>
                        ) : filtered.map(admin => (
                            <tr key={admin.username} className="group hover:bg-[#f7f7f7] dark:hover:bg-[#151618] transition-all duration-200">
                                <td className="py-4 pl-4 rounded-l-xl">
                                    <div className="flex items-center gap-3">
                                        <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex items-center justify-center text-white text-[12px] font-black flex-shrink-0 shadow-sm transition-transform group-hover:scale-105">{admin.username?.charAt(0).toUpperCase()}</div>
                                        <span className="font-bold text-[14px] text-[#111111] dark:text-white">{admin.username}</span>
                                    </div>
                                </td>
                                <td className="py-4">{getRoleBadge(admin.role)}</td>
                                <td className="py-4">
                                    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider border ${admin.active !== false ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30 shadow-sm' : 'bg-gray-50 text-gray-500 border-gray-200 dark:bg-gray-900/10 dark:text-gray-400 dark:border-gray-800/30'}`}>
                                        <span className={`w-1.5 h-1.5 rounded-full ${admin.active !== false ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : 'bg-gray-400'}`} />
                                        {admin.active !== false ? 'Active' : 'Inactive'}
                                    </span>
                                </td>
                                <td className="py-4 font-medium text-[#6e6e73] dark:text-[#9aa0a6]">{admin.created_at || '—'}</td>
                                <td className="py-4 font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                                    {formatLastLogin(admin.last_login)}
                                </td>
                                <td className="py-4 pr-4 text-right rounded-r-xl">
                                    <button
                                        onClick={e => openMenu(admin.username, e.currentTarget)}
                                        className="p-2 rounded-xl text-[#6e6e73] hover:text-[#111111] dark:hover:text-white hover:bg-white dark:hover:bg-[#1a1b1e] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-sm transition-all"
                                    >
                                        <FiMoreVertical className="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Floating Action Dropdown — fixed position to avoid table clipping */}
            {actionMenuId && (
                <div
                    ref={menuRef}
                    style={{ position: 'fixed', top: menuPos.top, right: menuPos.right, zIndex: 9999 }}
                    className="w-48 bg-white dark:bg-[#1e2023] border border-[#e5e5e5] dark:border-white/10 rounded-xl shadow-2xl overflow-hidden animate-in zoom-in-95 fade-in duration-100"
                >
                    {(() => {
                        const admin = admins.find(a => a.username === actionMenuId);
                        if (!admin) return null;
                        return (
                            <>
                                <button
                                    onClick={() => { setResetTarget(admin.username); setResetPassword(''); setActionMenuId(null); }}
                                    className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-white/5 hover:text-[#111111] dark:hover:text-white transition-colors text-left"
                                >
                                    <FiKey className="w-3.5 h-3.5" /> Reset Password
                                </button>
                                <button
                                    onClick={() => handleToggleStatus(admin)}
                                    className={`w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold transition-colors text-left ${admin.active !== false ? 'text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/10' : 'text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/10'}`}
                                >
                                    <FiToggleLeft className="w-3.5 h-3.5" />
                                    {admin.active !== false ? 'Deactivate' : 'Activate'}
                                </button>
                                <div className="border-t border-[#f0f0f0] dark:border-white/5" />
                                <button
                                    onClick={() => handleDeleteAdmin(admin.username)}
                                    disabled={actionLoading}
                                    className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors text-left disabled:opacity-50"
                                >
                                    <FiTrash2 className="w-3.5 h-3.5" /> Delete
                                </button>
                            </>
                        );
                    })()}
                </div>
            )}

            {/* Reset Password Modal */}
            {resetTarget && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="flex items-center justify-between mb-5">
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                                <FiKey className="text-[#2b83fa]" /> Reset Password
                            </h3>
                            <button onClick={() => { setResetTarget(null); setResetPassword(''); }} className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors"><FiX className="w-5 h-5" /></button>
                        </div>
                        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mb-5">
                            Set a new password for <span className="font-bold text-[#111111] dark:text-white">{resetTarget}</span>.
                        </p>
                        <form onSubmit={handleResetPassword} className="space-y-4">
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">New Password</label>
                                <div className="relative">
                                    <input
                                        required
                                        type={showResetPw ? 'text' : 'password'}
                                        value={resetPassword}
                                        onChange={e => setResetPassword(e.target.value)}
                                        placeholder="Enter new password"
                                        className="w-full px-4 pr-12 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow"
                                    />
                                    <button type="button" onClick={() => setShowResetPw(v => !v)} className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors">
                                        {showResetPw ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                                    </button>
                                </div>
                            </div>
                            <div className="pt-2 flex gap-2">
                                <button type="button" onClick={() => { setResetTarget(null); setResetPassword(''); }}
                                    className="flex-1 py-2.5 rounded-xl border border-[#e5e5e5] dark:border-white/10 text-[13px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors">
                                    Cancel
                                </button>
                                <button type="submit" disabled={actionLoading || !resetPassword.trim()}
                                    className="flex-1 flex items-center justify-center gap-2 py-2.5 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-bold text-[13px] transition-all shadow-md active:scale-[0.98] disabled:opacity-50">
                                    {actionLoading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiKey className="w-4 h-4" />}
                                    Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Create Admin Modal */}
            {showCreateModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="flex items-center justify-between mb-5">
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white">Create Admin</h3>
                            <button onClick={() => setShowCreateModal(false)} className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors"><FiX className="w-5 h-5" /></button>
                        </div>
                        <form onSubmit={handleCreateAdmin} className="space-y-4">
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Username</label>
                                <input required value={newUsername} onChange={e => setNewUsername(e.target.value)} placeholder="e.g. nola_admin"
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow" />
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Initial Password</label>
                                <input required type="text" value={newPassword} onChange={e => setNewPassword(e.target.value)} placeholder="Secure password"
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow" />
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Role</label>
                                <select value={newRole} onChange={e => setNewRole(e.target.value)}
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow">
                                    <option value="super_admin">Super Admin</option>
                                    <option value="support">Support</option>
                                    <option value="viewer">Viewer</option>
                                </select>
                            </div>
                            <div className="pt-2">
                                <button type="submit" disabled={actionLoading || !newUsername.trim() || !newPassword.trim()}
                                    className="flex items-center justify-center gap-2 w-full py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-[14px] font-bold text-[14px] transition-all shadow-md active:scale-[0.98] disabled:opacity-50">
                                    {actionLoading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : 'Create Account'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};

