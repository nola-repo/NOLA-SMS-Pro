import React, { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { FiEdit3, FiEye, FiEyeOff, FiLock, FiMoreVertical, FiRefreshCw, FiSave, FiShield, FiX } from 'react-icons/fi';
import { ToastContainer } from '../../components/ui/ToastContainer';
import { useToast } from '../../hooks/useToast';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';

const USERS_API = '/api/admin_users.php';
const decodeJwt = (token: string) => {
    try {
        const payload = token.split('.')[1];
        if (!payload) return {};
        const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
        const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
        return JSON.parse(atob(padded));
    } catch {
        return {};
    }
};

const formatDateTime = (value?: string | number) => {
    if (!value) return 'Not available';
    const date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const roleLabel = (role?: string) => (role || 'viewer').replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());

const normalizeIdentity = (value?: unknown) => String(value || '').trim().toLowerCase();

const displayFullName = (value?: unknown, fallback = 'Admin Account') => {
    const source = String(value || fallback).trim();
    const withoutEmailDomain = source.includes('@') ? source.split('@')[0] : source;
    return withoutEmailDomain
        .replace(/[._-]+/g, ' ')
        .replace(/\b\w/g, char => char.toUpperCase());
};

const FieldInput: React.FC<{
    label: string;
    value: string;
    type?: string;
    placeholder?: string;
    onChange: (value: string) => void;
}> = ({ label, value, type = 'text', placeholder, onChange }) => (
    <div>
        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">
            {label}
        </label>
        <div className="relative">
            <input
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                className="w-full px-4 py-3 pr-10 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all"
            />
            <FiEdit3 className="absolute right-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-[#9aa0a6]" />
        </div>
    </div>
);

const ReadOnlyField: React.FC<{
    label: string;
    value: string | null | undefined;
    mono?: boolean;
    action?: React.ReactNode;
}> = ({ label, value, mono, action }) => (
    <div>
        <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">
            {label}
        </label>
        <div className="flex items-center gap-2">
            <div className={`min-w-0 flex-1 px-4 py-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold truncate ${mono ? 'font-mono' : ''}`}>
                {value || <span className="text-[#9aa0a6] font-normal">N/A</span>}
            </div>
            {action}
        </div>
    </div>
);

export const AdminProfile: React.FC = () => {
    const [, setLoading] = useState(true);
    const [savingPassword, setSavingPassword] = useState(false);
    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);
    const [profileMenuOpen, setProfileMenuOpen] = useState(false);
    const [passwordPanelOpen, setPasswordPanelOpen] = useState(false);
    
    const profileMenuRef = useRef<HTMLDivElement | null>(null);
    const { toasts, showToast, dismissToast } = useToast();

    const token = sessionStorage.getItem('nola_admin_token') || localStorage.getItem('nola_admin_token') || '';
    const storedUser = sessionStorage.getItem('nola_admin_user') || localStorage.getItem('nola_admin_user') || '';
    const claims = useMemo(() => decodeJwt(token), [token]);
    const email = normalizeIdentity(claims.email || claims.username || storedUser);
    
    const displayRole = claims.role || 'viewer';
    const displayPhone = claims.phone || '';
    const initialName = useMemo(() => {
        return displayFullName(claims.full_name || claims.name || claims.display_name || email);
    }, [claims, email]);

    const [profile, setProfile] = useState({
        name: initialName,
        email: email,
        phone: displayPhone,
        role: displayRole,
        created_at: '',
        last_login: ''
    });

    const [profileForm, setProfileForm] = useState({ name: '', email: '', phone: '' });
    const [savingProfile, setSavingProfile] = useState(false);

    const fetchAdminProfile = useCallback(async () => {
        setLoading(true);
        try {
            const res = await adminFetch('/api/admin_auth.php', { headers: getAdminAuthHeaders() });
            const json = await res.json().catch(() => ({}));
            if (res.ok && json.status === 'success') {
                setProfile({
                    name: json.full_name || json.name || '',
                    email: json.email || '',
                    phone: json.phone || '',
                    role: json.role || 'viewer',
                    created_at: json.created_at || '',
                    last_login: json.last_login || ''
                });
            }
        } catch {
            // fallback
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchAdminProfile();
    }, [fetchAdminProfile]);

    useEffect(() => {
        setProfileForm({
            name: profile.name,
            email: profile.email,
            phone: profile.phone,
        });
    }, [profile.name, profile.email, profile.phone]);

    useEffect(() => {
        if (!profileMenuOpen) return;
        const handler = (event: MouseEvent) => {
            if (profileMenuRef.current && !profileMenuRef.current.contains(event.target as Node)) {
                setProfileMenuOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [profileMenuOpen]);

    const profileChanged =
        profileForm.name.trim() !== profile.name ||
        profileForm.email.trim() !== profile.email ||
        profileForm.phone.trim() !== profile.phone;

    const handleSaveProfile = async () => {
        if (!profileForm.name.trim() || !profileForm.email.trim()) {
            showToast('Name and email are required.', 'error');
            return;
        }

        setSavingProfile(true);
        try {
            const payload: any = {
                action: 'update_profile',
                name: profileForm.name.trim(),
                email: profileForm.email.trim(),
                phone: profileForm.phone.trim(),
            };

            const res = await adminFetch('/api/admin_auth.php', {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify(payload),
            });
            const json = await res.json().catch(() => ({}));

            if (res.ok && json.status === 'success') {
                showToast(json.message || 'Profile updated successfully.', 'success');
                if (json.token) {
                    sessionStorage.setItem('nola_admin_token', json.token);
                    if (localStorage.getItem('nola_admin_token')) {
                        localStorage.setItem('nola_admin_token', json.token);
                    }
                }
                if (json.user) {
                    sessionStorage.setItem('nola_admin_user', json.user.email);
                    if (localStorage.getItem('nola_admin_user')) {
                        localStorage.setItem('nola_admin_user', json.user.email);
                    }
                    if (json.user.name) {
                        sessionStorage.setItem('nola_admin_name', json.user.name);
                        if (localStorage.getItem('nola_admin_name')) {
                            localStorage.setItem('nola_admin_name', json.user.name);
                        }
                    }
                }
                window.dispatchEvent(new Event('storage'));
                if (json.user?.name) {
                    window.dispatchEvent(new CustomEvent('nola-admin-name-updated', { detail: { name: json.user.name } }));
                }
                
                setProfile(prev => ({
                    ...prev,
                    name: json.user?.name || json.user?.full_name || prev.name,
                    email: json.user?.email || prev.email,
                    phone: json.user?.phone || prev.phone,
                }));
            } else {
                showToast(json.message || 'Failed to update profile.', 'error');
            }
        } catch {
            showToast('Network error updating profile.', 'error');
        } finally {
            setSavingProfile(false);
        }
    };

    const handlePasswordReset = async (event: React.FormEvent) => {
        event.preventDefault();
        if (password.length < 8) {
            showToast('Use at least 8 characters for the new password.', 'error');
            return;
        }
        if (password !== confirmPassword) {
            showToast('Passwords do not match.', 'error');
            return;
        }
        setSavingPassword(true);
        try {
            const res = await adminFetch(USERS_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({ action: 'reset_password', email: profile.email, new_password: password }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || json.status !== 'success') {
                throw new Error(json.message || 'Password update failed.');
            }
            setPassword('');
            setConfirmPassword('');
            setPasswordPanelOpen(false);
            showToast('Password updated successfully.', 'success');
        } catch (error: any) {
            showToast(error.message || 'Could not update password.', 'error');
        } finally {
            setSavingPassword(false);
        }
    };

    const initialLetter = (profileForm.name || profileForm.email || 'A').charAt(0).toUpperCase();

    return (
        <div className="mx-auto w-full max-w-4xl space-y-5">
            <ToastContainer toasts={toasts} onDismiss={dismissToast} />

            <div className="overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white shadow-sm dark:border-white/5 dark:bg-[#1a1b1e]">
                <div className="p-5 sm:p-6">
                    <div className="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div className="flex items-center gap-4 min-w-0">
                            <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] text-[30px] font-black text-white shadow-sm shadow-blue-500/20 ring-4 ring-black/5 dark:ring-white/5">
                                {initialLetter}
                            </div>
                            <div className="min-w-0 flex-1">
                                <h3 className="truncate text-[22px] font-black text-[#111111] dark:text-[#ececf1]">{profileForm.name || 'Admin Account'}</h3>
                                <p className="mt-1 truncate text-[13px] font-medium text-[#9aa0a6]">{profileForm.email || 'admin@example.com'}</p>
                            </div>
                        </div>
                        <div className="relative self-start sm:self-center" ref={profileMenuRef}>
                            <button
                                type="button"
                                onClick={() => setProfileMenuOpen(open => !open)}
                                className="flex h-10 w-10 items-center justify-center rounded-xl border border-transparent bg-[#f5f5f6] text-[#6e6e73] transition-all hover:border-[#2b83fa]/30 hover:text-[#2b83fa] dark:border-white/10 dark:bg-[#0d0e10] dark:text-[#9aa0a6]"
                                aria-label="More profile options"
                                title="More options"
                            >
                                <FiMoreVertical className="h-4 w-4" />
                            </button>
                            {profileMenuOpen && (
                                <div className="absolute right-0 top-full z-30 mt-2 w-56 rounded-2xl border border-[#e5e5e5] bg-white p-1.5 shadow-2xl dark:border-white/10 dark:bg-[#1a1b1e]">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setProfileMenuOpen(false);
                                            setPasswordPanelOpen(true);
                                        }}
                                        className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-[13px] font-bold text-[#111111] transition-colors hover:bg-[#f5f5f6] dark:text-white dark:hover:bg-white/5"
                                    >
                                        <FiLock className="h-4 w-4 text-[#2b83fa]" />
                                        Change Password
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="space-y-4 border-t border-[#f0f0f0] pt-5 dark:border-[#ffffff05]">
                        <FieldInput
                            label="Full Name"
                            value={profileForm.name}
                            placeholder="Full name"
                            onChange={(value) => setProfileForm(prev => ({ ...prev, name: value }))}
                        />
                        <FieldInput
                            label="Email Address"
                            type="email"
                            value={profileForm.email}
                            placeholder="Email address"
                            onChange={(value) => setProfileForm(prev => ({ ...prev, email: value }))}
                        />
                        <FieldInput
                            label="Phone Number"
                            type="tel"
                            value={profileForm.phone}
                            placeholder="Phone number"
                            onChange={(value) => setProfileForm(prev => ({ ...prev, phone: value }))}
                        />

                        {(profileChanged || savingProfile) && (
                            <div className="mt-5">
                                <button
                                    type="button"
                                    onClick={handleSaveProfile}
                                    disabled={savingProfile || !profileForm.name.trim() || !profileForm.email.trim()}
                                    className="w-full h-12 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[13px] font-black hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                >
                                    {savingProfile ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSave className="w-4 h-4" />}
                                    {savingProfile ? 'Saving...' : 'Save Changes'}
                                </button>
                            </div>
                        )}

                        <div className="my-6 border-t border-[#f0f0f0] dark:border-[#ffffff08]" />

                        <ReadOnlyField
                            label="Admin Role"
                            value={roleLabel(profile.role)}
                            action={<FiShield className="h-4 w-4 text-[#9aa0a6]" />}
                        />
                        {profile.created_at && (
                            <ReadOnlyField
                                label="Created"
                                value={formatDateTime(profile.created_at)}
                            />
                        )}
                        {profile.last_login && (
                            <ReadOnlyField
                                label="Last Login"
                                value={formatDateTime(profile.last_login)}
                            />
                        )}
                    </div>
                </div>
            </div>

            {passwordPanelOpen && typeof document !== 'undefined' && createPortal((
                <div
                    className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/55 px-4 py-6 backdrop-blur-md"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) setPasswordPanelOpen(false);
                    }}
                >
                    <div className="w-full max-w-lg overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white shadow-2xl dark:border-white/10 dark:bg-[#1a1b1e]">
                        <div className="flex items-start justify-between gap-4 border-b border-[#f0f0f0] p-5 dark:border-[#ffffff08]">
                            <div className="flex items-start gap-3">
                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#2b83fa]/10 text-[#2b83fa]">
                                    <FiLock className="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 className="text-[16px] font-black text-[#111111] dark:text-white">Change Password</h3>
                                    <p className="mt-1 text-[12px] leading-relaxed text-[#6e6e73] dark:text-[#9aa0a6]">Update the password for this admin account.</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPasswordPanelOpen(false)}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-[#6e6e73] transition-colors hover:bg-[#f5f5f6] hover:text-red-500 dark:text-[#9aa0a6] dark:hover:bg-white/5"
                                aria-label="Close change password modal"
                            >
                                <FiX className="h-4 w-4" />
                            </button>
                        </div>

                        <form onSubmit={handlePasswordReset} className="space-y-4 p-5">
                            <div className="relative">
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={event => setPassword(event.target.value)}
                                    minLength={8}
                                    className="h-11 w-full rounded-xl border border-transparent bg-[#f5f5f6] px-4 pr-10 text-[13px] font-semibold text-[#111111] placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 dark:border-[#ffffff0a] dark:bg-[#0d0e10] dark:text-[#ececf1]"
                                    placeholder="New password"
                                    autoComplete="new-password"
                                />
                                <button type="button" onClick={() => setShowPassword(value => !value)} className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#2b83fa]">
                                    {showPassword ? <FiEyeOff className="h-4 w-4" /> : <FiEye className="h-4 w-4" />}
                                </button>
                            </div>
                            <div className="relative">
                                <input
                                    type={showConfirm ? 'text' : 'password'}
                                    value={confirmPassword}
                                    onChange={event => setConfirmPassword(event.target.value)}
                                    minLength={8}
                                    className="h-11 w-full rounded-xl border border-transparent bg-[#f5f5f6] px-4 pr-10 text-[13px] font-semibold text-[#111111] placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 dark:border-[#ffffff0a] dark:bg-[#0d0e10] dark:text-[#ececf1]"
                                    placeholder="Confirm new password"
                                    autoComplete="new-password"
                                />
                                <button type="button" onClick={() => setShowConfirm(value => !value)} className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#2b83fa]">
                                    {showConfirm ? <FiEyeOff className="h-4 w-4" /> : <FiEye className="h-4 w-4" />}
                                </button>
                            </div>
                            <div className="flex justify-end gap-3 border-t border-[#f0f0f0] pt-4 dark:border-[#ffffff08]">
                                <button
                                    type="button"
                                    onClick={() => setPasswordPanelOpen(false)}
                                    className="h-11 rounded-xl px-5 text-[13px] font-bold text-[#6e6e73] transition-colors hover:bg-[#f5f5f6] dark:text-[#9aa0a6] dark:hover:bg-white/5"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingPassword || password.length < 8 || password !== confirmPassword}
                                    className="h-11 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] px-6 text-[13px] font-bold text-white shadow-md shadow-blue-500/20 transition-all hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {savingPassword ? 'Updating...' : 'Update Password'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            ), document.body)}
        </div>
    );
};
