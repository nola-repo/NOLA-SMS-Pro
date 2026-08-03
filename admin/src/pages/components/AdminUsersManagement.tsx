import React, { useState, useEffect, useCallback } from 'react';
import { FiUsers, FiSend, FiSettings, FiLogOut, FiLock, FiAlertCircle, FiEye, FiEyeOff, FiCopy, FiCheck, FiX, FiRefreshCw, FiKey, FiHome, FiClock, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiPlus, FiMinus, FiTrash2, FiChevronLeft, FiChevronRight, FiSearch, FiSun, FiMoon, FiMoreVertical, FiToggleLeft, FiEdit2 } from 'react-icons/fi';
import logoUrl from '../../assets/NOLA SMS PRO Logo.png';
import Antigravity from '../../components/ui/Antigravity';
import { useToast } from '../../hooks/useToast';
import { ToastContainer } from '../../components/ui/ToastContainer';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';

const ADMIN_API = '/api/admin_sender_requests.php';
const USERS_API = '/api/admin_users.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync



export const AdminTeamManagement: React.FC = () => {
    const [admins, setAdmins] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [error, setError] = useState<string | null>(null);
    const { toasts, showToast, dismissToast } = useToast();

    // Action menu – uses fixed positioning to avoid table clipping
    const [actionMenuId, setActionMenuId] = useState<string | null>(null);
    const [menuPos, setMenuPos] = useState<{ top: number; right: number }>({ top: 0, right: 0 });
    const menuRef = React.useRef<HTMLDivElement>(null);

    // Reset Password modal state
    const [resetTarget, setResetTarget] = useState<string | null>(null);
    const [resetPassword, setResetPassword] = useState('');
    const [showResetPw, setShowResetPw] = useState(false);

    const [newEmail, setNewEmail] = useState('');
    const [newName, setNewName] = useState('');
    const [newPhone, setNewPhone] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [newRole, setNewRole] = useState('support');
    const [editingAdmin, setEditingAdmin] = useState<any | null>(null);
    const [editName, setEditName] = useState('');
    const [editEmail, setEditEmail] = useState('');
    const [editPhone, setEditPhone] = useState('');
    const [editRole, setEditRole] = useState('support');
    const [actionLoading, setActionLoading] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState<string | null>(null);
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

    const openMenu = (email: string, btn: HTMLButtonElement) => {
        const rect = btn.getBoundingClientRect();
        setMenuPos({ top: rect.bottom + 4, right: window.innerWidth - rect.right });
        setActionMenuId(prev => prev === email ? null : email);
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
        // Don't fire if there's no active session — avoids 401 spam when logged out
        const token = sessionStorage.getItem('nola_admin_token');
        if (!token) {
            if (isInitial) setLoading(false);
            return;
        }
        if (isInitial) setLoading(true);
        setError(null);
        try {
            const res = await adminFetch(USERS_API, { headers: getAdminAuthHeaders() });
            const json = await res.json();
            if (res.ok && json.status === 'success') {
                setAdmins(json.data || []);
            } else {
                setError(json.message || `Server error (${res.status}). Please try again.`);
            }
            setLastRefreshed(new Date());
        } catch {
            setError('Network error — could not reach the server.');
        } finally { if (isInitial) setLoading(false); }
    }, []);

    useEffect(() => {
        fetchAdmins(true);
        const timer = setInterval(() => fetchAdmins(false), POLL_INTERVAL);
        return () => clearInterval(timer);
    }, [fetchAdmins]);

    const toast = (msg: string, isError = false) => {
        showToast(msg, isError ? 'error' : 'success');
    };

    const handleCreateAdmin = async (e: React.FormEvent) => {
        e.preventDefault();
        const trimmedEmail = newEmail.trim().toLowerCase();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
            toast('Please enter a valid email address.', true);
            return;
        }

        setActionLoading(true);
        try {
            const res = await adminFetch(USERS_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({
                    action: 'create',
                    email: trimmedEmail,
                    username: trimmedEmail,
                    name: newName.trim(),
                    full_name: newName.trim(),
                    phone: newPhone.trim(),
                    password: newPassword,
                    role: newRole,
                })
            });
            if (res.ok) {
                const json = await res.json();
                if (json.status === 'success') {
                    toast('Admin user created successfully.');
                    setShowCreateModal(false);
                    setNewEmail(''); setNewName(''); setNewPhone(''); setNewPassword(''); setNewRole('support');
                    fetchAdmins();
                } else { toast(json.message || 'Failed to create admin.', true); }
            } else {
                const json = await res.json().catch(() => ({}));
                toast(json.message || `Failed to create admin (HTTP ${res.status}).`, true);
            }
        } catch { toast('Network error creating admin.', true); }
        finally { setActionLoading(false); }
    };

    const openEditAdmin = (admin: any) => {
        setEditingAdmin(admin);
        setEditName(admin.name || admin.full_name || '');
        setEditEmail((admin.email || '').toLowerCase());
        setEditPhone(admin.phone || admin.phone_number || '');
        setEditRole(admin.role || 'viewer');
        setActionMenuId(null);
    };

    const closeEditAdmin = () => {
        setEditingAdmin(null);
        setEditName('');
        setEditEmail('');
        setEditPhone('');
        setEditRole('support');
    };

    const handleEditAdmin = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingAdmin) return;
        const trimmedEmail = editEmail.trim().toLowerCase();
        const trimmedName = editName.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
            toast('Please enter a valid email address.', true);
            return;
        }
        if (!trimmedName) {
            toast('Full name is required.', true);
            return;
        }

        setActionLoading(true);
        try {
            const res = await adminFetch(USERS_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({
                    action: 'update',
                    email: editingAdmin.email,
                    new_email: trimmedEmail,
                    username: trimmedEmail,
                    name: trimmedName,
                    full_name: trimmedName,
                    phone: editPhone.trim(),
                    role: editRole,
                })
            });
            const json = await res.json().catch(() => ({}));
            if (res.ok && json.status === 'success') {
                toast('Admin account updated successfully.');
                closeEditAdmin();
                fetchAdmins();
            } else {
                toast(json.message || `Failed to update admin (HTTP ${res.status}).`, true);
            }
        } catch {
            toast('Network error updating admin.', true);
        } finally {
            setActionLoading(false);
        }
    };
    const handleResetPassword = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!resetTarget || !resetPassword.trim()) return;
        setActionLoading(true);
        try {
            const res = await adminFetch(USERS_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({ action: 'reset_password', email: resetTarget, new_password: resetPassword })
            });
            const json = await res.json();
            if (res.ok && json.status === 'success') {
                toast(`Password for "${resetTarget}" has been reset.`);
                setResetTarget(null); setResetPassword('');
            } else {
                toast(json.message || 'Failed to reset password.', true);
            }
        } catch { toast('Network error resetting password.', true); }
        finally { setActionLoading(false); }
    };

    const handleToggleStatus = async (admin: any) => {
        setActionMenuId(null);
        const newActive = !(admin.active !== false);
        // Optimistic update
        setAdmins(prev => prev.map(a => a.email === admin.email ? { ...a, active: newActive } : a));
        try {
            const res = await adminFetch(USERS_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({ action: 'toggle_status', email: admin.email, active: newActive })
            });
            const json = await res.json();
            if (res.ok && json.status === 'success') {
                toast(`${admin.email} is now ${newActive ? 'active' : 'inactive'}.`);
                fetchAdmins();
            } else {
                // Revert optimistic update
                setAdmins(prev => prev.map(a => a.email === admin.email ? { ...a, active: !newActive } : a));
                toast(json.message || 'Failed to toggle status.', true);
            }
        } catch {
            setAdmins(prev => prev.map(a => a.email === admin.email ? { ...a, active: !newActive } : a));
            toast('Network error toggling status.', true);
        }
    };

    const requestDeleteAdmin = (emailToDelete: string) => {
        setDeleteTarget(emailToDelete);
        setActionMenuId(null);
    };

    const handleDeleteAdmin = async () => {
        const emailToDelete = deleteTarget;
        if (!emailToDelete) return;
        setActionLoading(true); setActionMenuId(null);
        try {
            const res = await adminFetch(USERS_API, {
                method: 'DELETE',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({ email: emailToDelete })
            });
            const json = await res.json();
            if (res.ok && json.status === 'success') {
                toast(`Admin "${emailToDelete}" deleted.`);
                setAdmins(prev => prev.filter(a => a.email !== emailToDelete));
                setDeleteTarget(null);
            } else {
                toast(json.message || 'Failed to delete admin.', true);
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
        a.email?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        a.name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        a.full_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        a.phone?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        a.phone_number?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        a.role?.toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-6 shadow-sm">
            <ToastContainer toasts={toasts} onDismiss={dismissToast} />
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

            {error && (
                <div className="flex items-center gap-2 px-4 py-3 mb-5 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-800/30 text-amber-700 dark:text-amber-400 text-[12px] font-medium animate-in fade-in slide-in-from-top-1">
                    <FiAlertCircle className="w-4 h-4 flex-shrink-0" /> {error}
                </div>
            )}

            {/* Search */}
            <div className="relative mb-5">
                <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] w-3.5 h-3.5" />
                <input type="text" placeholder="Search by email or role..." value={searchTerm} onChange={e => setSearchTerm(e.target.value)}
                    className="w-full pl-9 pr-4 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] text-[#111111] dark:text-white placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all font-medium"
                />
                {searchTerm && <button onClick={() => setSearchTerm('')} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"><FiX className="w-3 h-3" /></button>}
            </div>

            <div className="overflow-x-auto pb-4">
                <table className="w-full text-left border-collapse">
                    <thead>
                        <tr className="border-b-2 border-[#e5e5e5] dark:border-white/10 text-[11px] font-black text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-widest">
                            <th className="pb-3 pl-4">Admin</th>
                            <th className="pb-3">Phone</th>
                            <th className="pb-3">Role</th>
                            <th className="pb-3">Status</th>
                            <th className="pb-3">Created</th>
                            <th className="pb-3 text-right pr-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[#f0f0f0] dark:divide-white/5 text-[14px]">
                        {loading ? (
                            <tr><td colSpan={6} className="py-8 text-center"><div className="flex items-center justify-center gap-2 text-[#9aa0a6] text-[13px]"><FiRefreshCw className="w-4 h-4 animate-spin" /> Loading admins...</div></td></tr>
                        ) : filtered.length === 0 ? (
                            <tr><td colSpan={6} className="py-12 text-center"><FiShield className="w-8 h-8 mx-auto mb-2 text-[#d0d0d0] dark:text-[#3a3b3f]" /><p className="text-[13px] text-[#9aa0a6] font-medium">{searchTerm ? 'No admins match your search.' : 'No admin users found.'}</p></td></tr>
                        ) : filtered.map(admin => (
                            <tr key={admin.email} className="group hover:bg-[#f7f7f7] dark:hover:bg-[#151618] transition-all duration-200">
                                <td className="py-4 pl-4 rounded-l-xl">
                                    <div className="flex items-center gap-3">
                                        <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex items-center justify-center text-white text-[12px] font-black flex-shrink-0 shadow-sm transition-transform group-hover:scale-105">{(admin.name || admin.full_name || admin.email)?.charAt(0).toUpperCase()}</div>
                                        <div className="min-w-0">
                                            <p className="font-bold text-[14px] text-[#111111] dark:text-white truncate">{admin.name || admin.full_name || admin.email}</p>
                                            <p className="text-[11.5px] text-[#6e6e73] dark:text-[#9aa0a6] truncate">{admin.email}</p>
                                        </div>
                                    </div>
                                </td>
                                <td className="py-4 text-[12px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] whitespace-nowrap">{admin.phone || admin.phone_number || '—'}</td>
                                <td className="py-4">{getRoleBadge(admin.role)}</td>
                                <td className="py-4">
                                    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider border ${admin.active !== false ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30 shadow-sm' : 'bg-gray-50 text-gray-500 border-gray-200 dark:bg-gray-900/10 dark:text-gray-400 dark:border-gray-800/30'}`}>
                                        <span className={`w-1.5 h-1.5 rounded-full ${admin.active !== false ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : 'bg-gray-400'}`} />
                                        {admin.active !== false ? 'Active' : 'Inactive'}
                                    </span>
                                </td>
                                <td className="py-4 font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                                    {admin.created_at ? new Date(admin.created_at).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' }) : '—'}
                                </td>
                                <td className="py-4 pr-4 text-right rounded-r-xl">
                                    <button
                                        onClick={e => openMenu(admin.email, e.currentTarget)}
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
                        const admin = admins.find(a => a.email === actionMenuId);
                        if (!admin) return null;
                        return (
                            <>
                                <button
                                    onClick={() => openEditAdmin(admin)}
                                    className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-white/5 hover:text-[#111111] dark:hover:text-white transition-colors text-left"
                                >
                                    <FiEdit2 className="w-3.5 h-3.5" /> Edit Account
                                </button>
                                <button
                                    onClick={() => { setResetTarget(admin.email); setResetPassword(''); setActionMenuId(null); }}
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
                                    onClick={() => requestDeleteAdmin(admin.email)}
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

            {/* Delete Admin Confirmation Modal */}
            {deleteTarget && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="flex items-center gap-3 mb-4">
                            <div className="w-10 h-10 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 flex items-center justify-center">
                                <FiAlertCircle className="w-5 h-5" />
                            </div>
                            <div>
                                <h3 className="text-[18px] font-bold text-[#111111] dark:text-white">Delete admin?</h3>
                                <p className="text-[12px] font-bold text-red-600 dark:text-red-400 uppercase tracking-wider">This cannot be undone</p>
                            </div>
                        </div>
                        <p className="text-[13px] leading-6 text-[#6e6e73] dark:text-[#9aa0a6] mb-5">
                            You are about to permanently remove <span className="font-bold text-[#111111] dark:text-white">{deleteTarget}</span> from the admin team.
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => setDeleteTarget(null)}
                                disabled={actionLoading}
                                className="flex-1 py-2.5 rounded-xl border border-[#e5e5e5] dark:border-white/10 text-[13px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={handleDeleteAdmin}
                                disabled={actionLoading}
                                className="flex-1 flex items-center justify-center gap-2 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl font-bold text-[13px] transition-all shadow-md active:scale-[0.98] disabled:opacity-50"
                            >
                                {actionLoading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiTrash2 className="w-4 h-4" />}
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            )}
            {/* Edit Admin Modal */}
            {editingAdmin && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="flex items-center justify-between mb-5">
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                                <FiEdit2 className="text-[#2b83fa]" /> Edit Account
                            </h3>
                            <button onClick={closeEditAdmin} className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors"><FiX className="w-5 h-5" /></button>
                        </div>
                        <form onSubmit={handleEditAdmin} className="space-y-4">
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Full Name</label>
                                <input required type="text" value={editName} onChange={e => setEditName(e.target.value)} placeholder="Admin name"
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow" />
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Email Address</label>
                                <input required type="email" value={editEmail} onChange={e => setEditEmail(e.target.value)} placeholder="you@company.com"
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow" />
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Phone Number</label>
                                <input type="tel" value={editPhone} onChange={e => setEditPhone(e.target.value)} placeholder="Admin phone number"
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow" />
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Role</label>
                                <select value={editRole} onChange={e => setEditRole(e.target.value)}
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow">
                                    <option value="super_admin">Super Admin</option>
                                    <option value="support">Support</option>
                                    <option value="viewer">Viewer</option>
                                </select>
                            </div>
                            <div className="pt-2 flex gap-2">
                                <button type="button" onClick={closeEditAdmin}
                                    className="flex-1 py-2.5 rounded-xl border border-[#e5e5e5] dark:border-white/10 text-[13px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors">
                                    Cancel
                                </button>
                                <button type="submit" disabled={actionLoading || !editName.trim() || !editEmail.trim()}
                                    className="flex-1 flex items-center justify-center gap-2 py-2.5 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-bold text-[13px] transition-all shadow-md active:scale-[0.98] disabled:opacity-50">
                                    {actionLoading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiCheck className="w-4 h-4" />}
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
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
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Full Name</label>
                                <input required type="text" value={newName} onChange={e => setNewName(e.target.value)} placeholder="Admin name"
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow" />
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Email Address</label>
                                <input required type="email" value={newEmail} onChange={e => setNewEmail(e.target.value)} placeholder="you@company.com"
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow" />
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Phone Number</label>
                                <input required type="tel" value={newPhone} onChange={e => setNewPhone(e.target.value)} placeholder="Admin phone number"
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
                                <button type="submit" disabled={actionLoading || !newName.trim() || !newPhone.trim() || !newEmail.trim() || !newPassword.trim()}
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
