import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  FiAlertCircle,
  FiCheck,
  FiCopy,
  FiEdit3,
  FiExternalLink,
  FiEye,
  FiEyeOff,
  FiLock,
  FiMapPin,
  FiMoreVertical,
  FiRefreshCw,
  FiSave,
  FiSend,
  FiX,
} from 'react-icons/fi';
import { AgencyLayout } from '../components/layout/AgencyLayout.tsx';
import { useAgency } from '../context/AgencyContext.tsx';
import {
  fetchAgencyProfile,
  linkCompany,
  requestPasswordOtp,
  resetPasswordWithOtp,
  updateAgencyProfile,
  SESSION_KEYS,
  type AgencyAuthUser,
} from '../services/agencyAuthHelper';
import { useToast } from '../hooks/useToast';
import { safeStorage } from '../utils/safeStorage';
import { ToastContainer } from '../components/ui/ToastContainer.tsx';

const buildAgencyConnectUrl = () => {
  const redirectUri = encodeURIComponent(import.meta.env.VITE_GHL_REDIRECT_URI ?? 'https://agency.nolacrm.io/oauth/callback');
  const clientId = import.meta.env.VITE_GHL_CLIENT_ID ?? '';
  const versionId = clientId.split('-')[0] || '';
  return `https://marketplace.leadconnectorhq.com/oauth/chooselocation?response_type=code&redirect_uri=${redirectUri}&client_id=${clientId}&version_id=${versionId}&scope=companies.readonly`;
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

type PasswordStep = 'send_code' | 'enter_code' | 'change_password';

export const Settings = () => {
  const { agencyId, agencySession, isGhlFrame } = useAgency();
  const [copiedId, setCopiedId] = useState(false);
  const [localCompanyId, setLocalCompanyId] = useState(agencyId || '');
  const [freshProfile, setFreshProfile] = useState<AgencyAuthUser | null>(null);
  const [savingCompany, setSavingCompany] = useState(false);
  const [savingProfile, setSavingProfile] = useState(false);
  const [profileStatus, setProfileStatus] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
  const [profileMenuOpen, setProfileMenuOpen] = useState(false);
  const [passwordPanelOpen, setPasswordPanelOpen] = useState(false);
  const [passwordStep, setPasswordStep] = useState<PasswordStep>('send_code');
  const [passwordForm, setPasswordForm] = useState({ otp: '', newPassword: '', confirmPassword: '' });
  const [passwordBusy, setPasswordBusy] = useState(false);
  const [passwordStatus, setPasswordStatus] = useState<{ type: 'success' | 'error' | 'info'; message: string } | null>(null);
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [profileForm, setProfileForm] = useState({ name: '', email: '', phone: '' });
  const profileMenuRef = useRef<HTMLDivElement | null>(null);
  const { toasts, showToast, dismissToast } = useToast();

  useEffect(() => {
    if (agencyId) setLocalCompanyId(agencyId);
  }, [agencyId]);

  useEffect(() => {
    if (!agencySession?.token) return;

    let isMounted = true;
    fetchAgencyProfile()
      .then(profile => {
        if (!isMounted || !profile) return;
        setFreshProfile(profile);
        if (profile.company_id) setLocalCompanyId(profile.company_id);
      })
      .catch(() => {
        // Cached login/session data remains the fallback if the profile endpoint is unavailable.
      });

    return () => { isMounted = false; };
  }, [agencySession?.token, agencyId]);

  const user = useMemo(() => ({
    ...(agencySession?.user ?? {}),
    ...(freshProfile ?? {}),
  }), [agencySession?.user, freshProfile]);

  const fullName = useMemo(() => {
    const firstName = user?.firstName ?? '';
    const lastName = user?.lastName ?? '';
    return `${firstName} ${lastName}`.trim() || user?.name || '';
  }, [user]);

  const email = user?.email ?? '';
  const phone = user?.phone ?? '';
  const companyName = user?.company_name || user?.agency_name || '';
  const savedCompanyId = user?.company_id ?? agencyId ?? '';
  const initial = (fullName || email || 'A').charAt(0).toUpperCase();

  useEffect(() => {
    setProfileForm({
      name: fullName,
      email,
      phone,
    });
  }, [fullName, email, phone]);

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
    profileForm.name.trim() !== fullName ||
    profileForm.email.trim() !== email ||
    profileForm.phone.trim() !== phone;

  const cacheAgencyUser = (patch: AgencyAuthUser) => {
    const nextUser = {
      ...(agencySession?.user ?? {}),
      ...(freshProfile ?? {}),
      ...patch,
      role: 'agency',
    };
    safeStorage.setItem(SESSION_KEYS.user, JSON.stringify(nextUser));
    setFreshProfile(nextUser);
  };

  const handleSaveProfile = async () => {
    if (!profileForm.name.trim() || !profileForm.email.trim()) {
      setProfileStatus({ type: 'error', message: 'Name and email are required.' });
      return;
    }

    setSavingProfile(true);
    setProfileStatus(null);
    const localPatch = {
      name: profileForm.name.trim(),
      email: profileForm.email.trim(),
      phone: profileForm.phone.trim(),
    };

    try {
      const updated = await updateAgencyProfile(localPatch);
      cacheAgencyUser(updated);
      setProfileStatus({ type: 'success', message: 'Profile updated successfully.' });
      showToast('Profile updated successfully.', 'success');
    } catch (err) {
      cacheAgencyUser(localPatch);
      const message = err instanceof Error ? err.message : 'Profile saved locally while the backend endpoint is pending.';
      setProfileStatus({
        type: 'success',
        message: message.includes('Could not') ? 'Profile saved locally while the backend endpoint is pending.' : message,
      });
      showToast('Profile saved locally.', 'info');
    } finally {
      setSavingProfile(false);
    }
  };

  const handleSaveCompanyId = async () => {
    if (!localCompanyId.trim()) return;
    setSavingCompany(true);
    try {
      await linkCompany(localCompanyId.trim());
      safeStorage.setItem('nola_agency_id', localCompanyId.trim());
      cacheAgencyUser({ company_id: localCompanyId.trim() });
      showToast('Company ID successfully updated!', 'success');
    } catch (err: any) {
      showToast(err.message || 'Failed to save Company ID', 'error');
    } finally {
      setSavingCompany(false);
    }
  };

  const handleCopyId = () => {
    if (!savedCompanyId) return;
    navigator.clipboard.writeText(savedCompanyId);
    setCopiedId(true);
    setTimeout(() => setCopiedId(false), 2000);
  };

  const openPasswordModal = () => {
    setPasswordPanelOpen(true);
    setPasswordStep('send_code');
    setPasswordStatus(null);
    setPasswordForm({ otp: '', newPassword: '', confirmPassword: '' });
    setShowNewPassword(false);
    setShowConfirmPassword(false);
  };

  const closePasswordModal = () => {
    setPasswordPanelOpen(false);
    setPasswordStep('send_code');
    setPasswordStatus(null);
    setPasswordForm({ otp: '', newPassword: '', confirmPassword: '' });
    setShowNewPassword(false);
    setShowConfirmPassword(false);
  };

  const handleSendPasswordCode = async () => {
    const targetEmail = profileForm.email.trim();
    if (!targetEmail) {
      setPasswordStatus({ type: 'error', message: 'Save an email address before requesting a reset code.' });
      return;
    }

    setPasswordBusy(true);
    setPasswordStatus(null);
    try {
      await requestPasswordOtp(targetEmail);
      setPasswordStep('enter_code');
      setPasswordStatus({ type: 'success', message: `Reset code sent to ${targetEmail}.` });
    } catch (err) {
      setPasswordStatus({
        type: 'error',
        message: err instanceof Error ? err.message : 'Could not send reset code.',
      });
    } finally {
      setPasswordBusy(false);
    }
  };

  const handlePasswordCodeContinue = () => {
    const otp = passwordForm.otp.trim();
    if (!otp || otp.length < 4) {
      setPasswordStatus({ type: 'error', message: 'Enter the full verification code.' });
      return;
    }
    setPasswordStatus(null);
    setPasswordStep('change_password');
  };

  const handlePasswordReset = async () => {
    const targetEmail = profileForm.email.trim();
    const otp = passwordForm.otp.trim();
    const newPassword = passwordForm.newPassword;

    if (!targetEmail || !otp || !newPassword || !passwordForm.confirmPassword) {
      setPasswordStatus({ type: 'error', message: 'Enter the code and your new password.' });
      return;
    }
    if (newPassword.length < 8) {
      setPasswordStatus({ type: 'error', message: 'Use at least 8 characters for the new password.' });
      return;
    }
    if (newPassword !== passwordForm.confirmPassword) {
      setPasswordStatus({ type: 'error', message: 'Passwords do not match.' });
      return;
    }

    setPasswordBusy(true);
    setPasswordStatus(null);
    try {
      await resetPasswordWithOtp(targetEmail, otp, newPassword);
      setPasswordForm({ otp: '', newPassword: '', confirmPassword: '' });
      setPasswordStep('send_code');
      setPasswordPanelOpen(false);
      setProfileStatus({ type: 'success', message: 'Password updated successfully.' });
      showToast('Password updated successfully.', 'success');
    } catch (err) {
      setPasswordStatus({
        type: 'error',
        message: err instanceof Error ? err.message : 'Could not update password.',
      });
    } finally {
      setPasswordBusy(false);
    }
  };

  const handleReconnectGhl = () => {
    window.location.href = buildAgencyConnectUrl();
  };

  return (
    <AgencyLayout
      title="Settings"
      subtitle="Manage your agency account settings and GoHighLevel connection"
    >
      <ToastContainer toasts={toasts} onDismiss={dismissToast} />

      <div className="w-full max-w-5xl mx-auto">
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-5 sm:p-6 shadow-sm">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div className="flex items-center gap-4 min-w-0">
              <div className="w-20 h-20 rounded-full bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] flex items-center justify-center text-white text-[30px] font-black shadow-sm shadow-blue-500/20 ring-4 ring-black/5 dark:ring-white/5">
                {initial}
              </div>
              <div className="min-w-0">
                <h3 className="text-[22px] font-black text-[#111111] dark:text-[#ececf1] truncate">
                  {profileForm.name || 'Agency Owner'}
                </h3>
                <p className="text-[13px] font-medium text-[#9aa0a6] truncate">{profileForm.email || 'agency@example.com'}</p>
              </div>
            </div>

            <div className="relative self-start sm:self-center" ref={profileMenuRef}>
              <button
                type="button"
                onClick={() => setProfileMenuOpen(prev => !prev)}
                className="h-10 w-10 inline-flex items-center justify-center rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-white/10 text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#2b83fa] hover:border-[#2b83fa]/30 transition-all"
                aria-label="More profile options"
                title="More options"
              >
                <FiMoreVertical className="w-4 h-4" />
              </button>
              {profileMenuOpen && (
                <div className="absolute right-0 top-full mt-2 w-56 rounded-2xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1a1b1e] shadow-2xl z-30 p-1.5">
                  <button
                    type="button"
                    onClick={() => {
                      setProfileMenuOpen(false);
                      openPasswordModal();
                    }}
                    className="w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-left text-[13px] font-bold text-[#111111] dark:text-white hover:bg-[#f5f5f6] dark:hover:bg-white/5 transition-colors"
                  >
                    <FiLock className="w-4 h-4 text-[#2b83fa]" />
                    Change Password
                  </button>
                </div>
              )}
            </div>
          </div>

          {profileStatus && (
            <div className={`mb-5 flex items-center gap-2 rounded-xl border px-3 py-2 text-[12px] font-semibold ${
              profileStatus.type === 'success'
                ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20'
                : 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/20'
            }`}>
              {profileStatus.type === 'success' ? <FiCheck className="w-4 h-4" /> : <FiAlertCircle className="w-4 h-4" />}
              {profileStatus.message}
            </div>
          )}

          <div className="space-y-4">
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
          </div>

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

          <div className="flex items-center gap-4 mb-4">
            <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500">
              <FiMapPin className="w-6 h-6" />
            </div>
            <div className="flex-1 min-w-0">
              <h3 className="text-[15px] font-bold text-[#111111] dark:text-[#ececf1] truncate">
                {companyName || profileForm.name || 'GHL Workspace'}
              </h3>
              <p className="text-[12px] text-[#9aa0a6] truncate">GoHighLevel agency connection</p>
            </div>
          </div>

          <div className="p-4 rounded-xl bg-amber-50/70 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/20 flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div>
              <p className="text-[13px] font-bold text-amber-800 dark:text-amber-200">GoHighLevel connection</p>
              <p className="text-[12px] text-amber-700/90 dark:text-amber-200/75 mt-1">
                Reconnect your agency if company data stops loading or the connection expires.
              </p>
            </div>
            <button
              type="button"
              onClick={handleReconnectGhl}
              className="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-[#1d6bd4] to-[#2b83fa] text-white text-[13px] font-bold shadow-md hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] active:scale-95 transition-all"
            >
              <FiRefreshCw className="w-4 h-4" />
              Reconnect GoHighLevel
              <FiExternalLink className="w-3.5 h-3.5 opacity-80" />
            </button>
          </div>

          <div className="space-y-4">
            <ReadOnlyField label="Company Name" value={companyName || profileForm.name} />
            <div>
              <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">
                Agency / Company ID
              </label>
              <div className="flex items-center gap-2">
                <input
                  type="text"
                  value={localCompanyId}
                  onChange={(event) => setLocalCompanyId(event.target.value)}
                  placeholder="Enter GHL Company ID"
                  disabled={isGhlFrame}
                  className={`min-w-0 flex-1 px-4 py-3 rounded-xl border text-[13px] font-mono placeholder:font-sans focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/50 transition-all ${
                    isGhlFrame
                      ? 'border-transparent bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#6e6e73] dark:text-[#9aa0a6]'
                      : 'border-[#e0e0e0] dark:border-[#ffffff0a] bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#111111] dark:text-[#ececf1] shadow-inner hover:border-[#2b83fa]/50'
                  }`}
                />

                {!isGhlFrame && localCompanyId !== savedCompanyId && (
                  <button
                    type="button"
                    onClick={handleSaveCompanyId}
                    disabled={savingCompany || !localCompanyId.trim()}
                    className="shrink-0 px-4 py-3 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] transition-all shadow-md inline-flex items-center gap-2 font-semibold disabled:opacity-50 text-[13px]"
                    title="Save Company ID"
                  >
                    {savingCompany ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSave className="w-4 h-4" />}
                    <span className="hidden sm:inline">Save</span>
                  </button>
                )}

                {savedCompanyId && localCompanyId === savedCompanyId && (
                  <button
                    type="button"
                    onClick={handleCopyId}
                    className="shrink-0 p-3 rounded-xl border border-[#e0e0e0] dark:border-[#ffffff0a] bg-white dark:bg-[#25282c] text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#2b83fa] transition-all shadow-sm"
                    title="Copy Company ID"
                  >
                    {copiedId ? <FiCheck className="w-4 h-4 text-emerald-500" /> : <FiCopy className="w-4 h-4" />}
                  </button>
                )}
              </div>
            </div>
            <ReadOnlyField label="Role" value={String(user?.role ?? 'agency').replace(/_/g, ' ')} />
          </div>
        </div>
      </div>

      {passwordPanelOpen && typeof document !== 'undefined' && createPortal((
        <div
          className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/55 backdrop-blur-md px-4 py-6"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) closePasswordModal();
          }}
        >
          <div className="w-full max-w-lg rounded-2xl bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 shadow-2xl overflow-hidden">
            <div className="flex items-start justify-between gap-4 p-5 border-b border-[#f0f0f0] dark:border-[#ffffff08]">
              <div className="flex items-start gap-3">
                <div className="w-11 h-11 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center shrink-0">
                  <FiLock className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-[16px] font-black text-[#111111] dark:text-white">
                    {passwordStep === 'send_code' && 'Send Verification Code'}
                    {passwordStep === 'enter_code' && 'Enter Verification Code'}
                    {passwordStep === 'change_password' && 'Change Password'}
                  </h3>
                  <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1 leading-relaxed">
                    {passwordStep === 'send_code' && "We'll email a verification code to your agency profile email."}
                    {passwordStep === 'enter_code' && 'Enter the code you received before setting a new password.'}
                    {passwordStep === 'change_password' && 'Create and confirm your new password.'}
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={closePasswordModal}
                className="h-9 w-9 rounded-xl inline-flex items-center justify-center text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f5f5f6] dark:hover:bg-white/5 hover:text-red-500 transition-colors"
                aria-label="Close account management modal"
              >
                <FiX className="w-4 h-4" />
              </button>
            </div>

            <div className="p-5 space-y-4">
              {passwordStep === 'send_code' && (
                <div className="space-y-3">
                  <div className="px-4 py-3 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] text-[12.5px] text-[#6e6e73] dark:text-[#9aa0a6] font-semibold">
                    {profileForm.email || 'Add an email address first'}
                  </div>
                  <button
                    type="button"
                    onClick={handleSendPasswordCode}
                    disabled={passwordBusy || !profileForm.email.trim()}
                    className="w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[12.5px] font-bold shadow-md shadow-blue-500/20 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                  >
                    {passwordBusy ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSend className="w-4 h-4" />}
                    Send Verification Code
                  </button>
                </div>
              )}

              {passwordStep === 'enter_code' && (
                <div className="space-y-3">
                  <input
                    type="text"
                    inputMode="numeric"
                    value={passwordForm.otp}
                    onChange={(event) => setPasswordForm(prev => ({ ...prev, otp: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                    placeholder="Verification code"
                    className="h-11 w-full px-4 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                  />
                  <div className="grid grid-cols-2 gap-3">
                    <button
                      type="button"
                      onClick={handleSendPasswordCode}
                      disabled={passwordBusy || !profileForm.email.trim()}
                      className="h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] text-[#111111] dark:text-white text-[12.5px] font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                    >
                      {passwordBusy ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiSend className="w-4 h-4" />}
                      Resend Code
                    </button>
                    <button
                      type="button"
                      onClick={handlePasswordCodeContinue}
                      disabled={!passwordForm.otp.trim()}
                      className="h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white text-[12.5px] font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                    >
                      Continue
                    </button>
                  </div>
                </div>
              )}

              {passwordStep === 'change_password' && (
                <div className="space-y-3">
                  <div className="relative">
                    <input
                      type={showNewPassword ? 'text' : 'password'}
                      value={passwordForm.newPassword}
                      onChange={(event) => setPasswordForm(prev => ({ ...prev, newPassword: event.target.value }))}
                      placeholder="New password"
                      autoComplete="new-password"
                      className="w-full h-11 pl-4 pr-10 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                    />
                    <button
                      type="button"
                      onClick={() => setShowNewPassword(prev => !prev)}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#2b83fa]"
                      aria-label="Toggle new password visibility"
                    >
                      {showNewPassword ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                    </button>
                  </div>
                  <div className="relative">
                    <input
                      type={showConfirmPassword ? 'text' : 'password'}
                      value={passwordForm.confirmPassword}
                      onChange={(event) => setPasswordForm(prev => ({ ...prev, confirmPassword: event.target.value }))}
                      placeholder="Confirm password"
                      autoComplete="new-password"
                      className="w-full h-11 pl-4 pr-10 rounded-xl bg-[#f5f5f6] dark:bg-[#0d0e10] border border-transparent dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] font-semibold placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                    />
                    <button
                      type="button"
                      onClick={() => setShowConfirmPassword(prev => !prev)}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#2b83fa]"
                      aria-label="Toggle confirm password visibility"
                    >
                      {showConfirmPassword ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                    </button>
                  </div>
                  <button
                    type="button"
                    onClick={handlePasswordReset}
                    disabled={passwordBusy || !passwordForm.newPassword || !passwordForm.confirmPassword}
                    className="w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-[#111111] dark:bg-white text-white dark:text-[#111111] text-[12.5px] font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                  >
                    {passwordBusy ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiCheck className="w-4 h-4" />}
                    Change Password
                  </button>
                </div>
              )}

              {passwordStatus && (
                <div className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-[12px] font-semibold ${
                  passwordStatus.type === 'success'
                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20'
                    : passwordStatus.type === 'info'
                      ? 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:border-blue-500/20'
                      : 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/20'
                }`}>
                  {passwordStatus.type === 'success' ? <FiCheck className="w-4 h-4" /> : <FiAlertCircle className="w-4 h-4" />}
                  {passwordStatus.message}
                </div>
              )}
            </div>
          </div>
        </div>
      ), document.body)}
    </AgencyLayout>
  );
};

export default Settings;
