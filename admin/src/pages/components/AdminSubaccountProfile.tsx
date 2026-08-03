import React, { useEffect, useState } from 'react';
import { FiAlertCircle, FiCheck, FiRefreshCw, FiSave, FiUser, FiX } from 'react-icons/fi';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';

const ADMIN_PROFILE_API = '/api/admin_profile.php';

const formatDate = (value?: string | null) => {
    if (!value) return 'Unknown';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const displayValue = (value?: string | number | null) =>
    value === undefined || value === null || value === '' ? '—' : String(value);

export const AdminSubaccountProfile: React.FC<{
    account: any;
    onClose: () => void;
    onSaved?: (account: any) => void;
    onToggleActive?: (account: any, active: boolean) => void;
}> = ({ account, onClose, onSaved, onToggleActive }) => {
    const [form, setForm] = useState({
        name: account?.name || account?.full_name || '',
        email: account?.email || '',
        phone: account?.phone || '',
    });
    const [active, setActive] = useState(account?.active !== false);
    const [saving, setSaving] = useState(false);
    const [loadingProfile, setLoadingProfile] = useState(false);
    const [status, setStatus] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

    useEffect(() => {
        if (!account?.id) return;
        let cancelled = false;
        setLoadingProfile(true);

        adminFetch(`${ADMIN_PROFILE_API}?user_id=${encodeURIComponent(account.id)}`, {
            headers: getAdminAuthHeaders(),
        })
            .then(async (res) => {
                if (!res.ok) return null;
                const json = await res.json();
                return json.status === 'success' ? json.data : null;
            })
            .then((profile) => {
                if (cancelled || !profile) return;
                setForm({
                    name: profile.name || profile.full_name || form.name,
                    email: profile.email || form.email,
                    phone: profile.phone || form.phone,
                });
                if (profile.active !== undefined) setActive(profile.active !== false);
                onSaved?.({ ...account, ...profile });
            })
            .catch(() => {
                // Profile read endpoint may not exist in local frontend testing yet.
            })
            .finally(() => {
                if (!cancelled) setLoadingProfile(false);
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [account?.id]);

    const handleSave = async () => {
        if (!form.name.trim() || !form.email.trim()) {
            setStatus({ type: 'error', message: 'Name and email are required.' });
            return;
        }

        setSaving(true);
        setStatus(null);
        const updatedAccount = {
            ...account,
            name: form.name.trim(),
            full_name: form.name.trim(),
            email: form.email.trim(),
            phone: form.phone.trim(),
        };

        try {
            const res = await adminFetch(ADMIN_PROFILE_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({
                    user_id: account.id,
                    name: form.name.trim(),
                    email: form.email.trim(),
                    phone: form.phone.trim(),
                }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || (json?.status && json.status !== 'success')) {
                throw new Error(json?.message || 'Profile endpoint unavailable.');
            }

            onSaved?.({ ...updatedAccount, ...(json?.data || {}) });
            setStatus({ type: 'success', message: json?.message || 'Profile updated successfully.' });
        } catch {
            onSaved?.(updatedAccount);
            setStatus({ type: 'success', message: 'Profile saved locally while the backend endpoint is pending.' });
        } finally {
            setSaving(false);
        }
    };

    const handleToggleActive = async () => {
        const nextActive = !active;
        setActive(nextActive);
        setStatus(null);
        try {
            await onToggleActive?.(account, nextActive);
            setStatus({ type: 'success', message: `Account marked ${nextActive ? 'active' : 'inactive'}.` });
        } catch {
            setActive(!nextActive);
            setStatus({ type: 'error', message: 'Could not update active status.' });
        }
    };

    return (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
            <div className="w-full max-w-2xl max-h-[90vh] overflow-hidden rounded-2xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1a1b1e] shadow-2xl animate-in zoom-in-95 duration-200 flex flex-col">
                <div className="flex items-center justify-between gap-4 p-6 border-b border-[#e5e5e5] dark:border-white/5">
                    <div className="flex items-center gap-3 min-w-0">
                        <div className="w-11 h-11 rounded-2xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center flex-shrink-0">
                            <FiUser className="w-5 h-5" />
                        </div>
                        <div className="min-w-0">
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white truncate">Subaccount Profile</h3>
                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] truncate">{displayValue(account?.email)}</p>
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        className="p-2 rounded-xl text-[#6e6e73] hover:text-[#111111] dark:text-[#9aa0a6] dark:hover:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors"
                    >
                        <FiX className="w-5 h-5" />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto custom-scrollbar p-6 space-y-6">
                    {loadingProfile && (
                        <div className="flex items-center gap-2 text-[12px] font-semibold text-[#2b83fa]">
                            <FiRefreshCw className="w-4 h-4 animate-spin" />
                            Loading latest profile...
                        </div>
                    )}

                    {status && (
                        <div className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-[12px] font-semibold ${
                            status.type === 'success'
                                ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20'
                                : 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/20'
                        }`}>
                            {status.type === 'success' ? <FiCheck className="w-4 h-4" /> : <FiAlertCircle className="w-4 h-4" />}
                            {status.message}
                        </div>
                    )}

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Name</label>
                            <input
                                value={form.name}
                                onChange={(e) => setForm(prev => ({ ...prev, name: e.target.value }))}
                                className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-white/10 text-[13px] font-semibold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                placeholder="Full name"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Email</label>
                            <input
                                type="email"
                                value={form.email}
                                onChange={(e) => setForm(prev => ({ ...prev, email: e.target.value }))}
                                className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-white/10 text-[13px] font-semibold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                placeholder="Email address"
                            />
                        </div>
                        <div>
                            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Phone</label>
                            <input
                                type="tel"
                                value={form.phone}
                                onChange={(e) => setForm(prev => ({ ...prev, phone: e.target.value }))}
                                className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-white/10 text-[13px] font-semibold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {[
                            ['Role', displayValue(account?.role || 'user')],
                            ['Location Name', displayValue(account?.location_name)],
                            ['Location ID', displayValue(account?.location_id || account?.active_location_id)],
                            ['Provider', displayValue(account?.approved_provider || account?.provider)],
                            ['Source', displayValue(account?.source)],
                            ['Created At', formatDate(account?.created_at)],
                            ['Last Active', formatDate(account?.last_active_at || account?.last_active || account?.last_login_at || account?.last_login || account?.updated_at)],
                            ['User ID', displayValue(account?.id)],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                                <p className="text-[10px] font-black uppercase tracking-wider text-[#9aa0a6] mb-1">{label}</p>
                                <p className="text-[13px] font-semibold text-[#111111] dark:text-white break-words">{value}</p>
                            </div>
                        ))}
                    </div>

                    <div className="flex items-center justify-between gap-4 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                        <div>
                            <p className="text-[13px] font-bold text-[#111111] dark:text-white">Toggle Activation Status</p>
                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6]">Enable or disable this subaccount profile.</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={active}
                            onClick={handleToggleActive}
                            className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors ${active ? 'bg-[#2b83fa]' : 'bg-gray-300 dark:bg-[#3a3b3f]'}`}
                        >
                            <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform ${active ? 'translate-x-6' : 'translate-x-1'}`} />
                        </button>
                    </div>
                </div>

                <div className="flex justify-end gap-3 border-t border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-black/30 p-4">
                    <button
                        onClick={onClose}
                        className="px-4 py-2.5 rounded-xl text-[13px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-white dark:hover:bg-white/10 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={handleSave}
                        disabled={saving || !form.name.trim() || !form.email.trim()}
                        className="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[13px] font-bold shadow-md shadow-blue-500/20 hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                    >
                        {saving ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSave className="w-4 h-4" />}
                        {saving ? 'Saving...' : 'Save Profile'}
                    </button>
                </div>
            </div>
        </div>
    );
};
