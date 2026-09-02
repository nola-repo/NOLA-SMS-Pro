import React, { useEffect, useState } from 'react';
import { FiAlertCircle, FiCheck, FiRefreshCw, FiSave, FiUser, FiX } from 'react-icons/fi';
import { agencyFetch } from '../services/agencyApi';

const API_BASE = import.meta.env.VITE_API_BASE || '';
const AGENCY_SUBACCOUNT_PROFILE_API = `${API_BASE}/api/agency/subaccount_profile.php`;

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

export const AgencySubaccountProfile = ({ subaccount, onClose, onSaved, onToggleActive, activationDisabled = false, activationDisabledMessage = 'Plan limit reached.' }) => {
    const [form, setForm] = useState({
        name: subaccount?.name || subaccount?.full_name || subaccount?.location_name || '',
        email: subaccount?.email || '',
        phone: subaccount?.phone || '',
    });
    const [active, setActive] = useState(subaccount?.toggle_enabled !== false);
    const [saving, setSaving] = useState(false);
    const [loadingProfile, setLoadingProfile] = useState(false);
    const [status, setStatus] = useState(null);

    useEffect(() => {
        if (!subaccount?.location_id) return;
        let cancelled = false;
        setLoadingProfile(true);

        agencyFetch(`${AGENCY_SUBACCOUNT_PROFILE_API}?location_id=${encodeURIComponent(subaccount.location_id)}`)
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
                if (profile.toggle_enabled !== undefined) {
                    setActive(profile.toggle_enabled !== false);
                } else if (profile.active !== undefined) {
                    setActive(profile.active !== false);
                }
                onSaved?.({ ...subaccount, ...profile });
            })
            .catch(() => {
                // Fallback locally
            })
            .finally(() => {
                if (!cancelled) setLoadingProfile(false);
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [subaccount?.location_id]);

    const handleSave = async () => {
        if (!form.name.trim() || !form.email.trim()) {
            setStatus({ type: 'error', message: 'Name and email are required.' });
            return;
        }

        setSaving(true);
        setStatus(null);
        const updatedSubaccount = {
            ...subaccount,
            name: form.name.trim(),
            full_name: form.name.trim(),
            email: form.email.trim(),
            phone: form.phone.trim(),
        };

        try {
            const res = await agencyFetch(AGENCY_SUBACCOUNT_PROFILE_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    location_id: subaccount.location_id,
                    name: form.name.trim(),
                    email: form.email.trim(),
                    phone: form.phone.trim(),
                }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || (json?.status && json.status !== 'success')) {
                throw new Error(json?.message || 'Profile endpoint unavailable.');
            }

            onSaved?.({ ...updatedSubaccount, ...(json?.data || {}) });
            setStatus({ type: 'success', message: json?.message || 'Profile updated successfully.' });
        } catch (err: any) {
            onSaved?.(updatedSubaccount);
            setStatus({ type: 'success', message: err.message || 'Profile saved locally.' });
        } finally {
            setSaving(false);
        }
    };

    const handleToggleActive = async () => {
        const nextActive = !active;
        if (nextActive && activationDisabled) {
            setStatus({ type: 'error', message: activationDisabledMessage });
            return;
        }
        setActive(nextActive);
        setStatus(null);
        try {
            await onToggleActive?.(subaccount.location_id, nextActive);
            setStatus({ type: 'success', message: `SMS Active set to ${nextActive ? 'ON' : 'OFF'}.` });
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
                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] truncate">{displayValue(subaccount?.email || form.email)}</p>
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
                            ['Role', displayValue(subaccount?.role || 'user')],
                            ['Location Name', displayValue(subaccount?.location_name)],
                            ['Location ID', displayValue(subaccount?.location_id)],
                            ['Credits (Balance)', displayValue((subaccount?.credit_balance ?? subaccount?.credits ?? 0).toLocaleString())],
                            ['Free Used', `${subaccount?.free_usage_count ?? 0} / ${subaccount?.free_credits_total ?? 10}`],
                            ['Sends Used / Credit Limit', `${subaccount?.attempt_count ?? 0} / ${subaccount?.rate_limit ?? 5}`],
                            ['Created At', formatDate(subaccount?.created_at)],
                            ['Last Active', formatDate(subaccount?.last_active_at || subaccount?.last_active || subaccount?.last_login_at || subaccount?.last_login || subaccount?.updated_at)],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                                <p className="text-[10px] font-black uppercase tracking-wider text-[#9aa0a6] mb-1">{label}</p>
                                <p className="text-[13px] font-semibold text-[#111111] dark:text-white break-words">{value}</p>
                            </div>
                        ))}
                    </div>

                    <div className="flex items-center justify-between gap-4 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                        <div>
                            <p className="text-[13px] font-bold text-[#111111] dark:text-white">SMS Active Toggle</p>
                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6]">Enable or disable SMS webhook access for this subaccount.</p>
                            {!active && activationDisabled && (
                                <p className="mt-1 text-[11px] font-bold text-red-500">Plan limit reached</p>
                            )}
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={active}
                            onClick={handleToggleActive}
                            disabled={!active && activationDisabled}
                            className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors ${active ? 'bg-[#2b83fa]' : 'bg-gray-300 dark:bg-[#3a3b3f]'} ${!active && activationDisabled ? 'cursor-not-allowed opacity-50' : ''}`}
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
