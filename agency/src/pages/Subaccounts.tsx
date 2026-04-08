import React, { useEffect, useState, useRef, useCallback } from 'react';
import {
  FiRefreshCw, FiAlertTriangle, FiToggleLeft, FiUsers, FiX, FiRotateCcw, FiChevronLeft, FiChevronRight, FiSearch, FiPlus, FiMinus, FiArrowUp, FiArrowDown
} from 'react-icons/fi';
import { AgencyLayout } from '../components/layout/AgencyLayout.tsx';
import { ToastContainer } from '../components/ui/ToastContainer.tsx';
import { useAgency } from '../context/AgencyContext.tsx';
import { useToast } from '../hooks/useToast.ts';
import {
  getSubaccounts,
  updateSubaccountSettings,
  checkInstallStatus,
} from '../services/api.ts';

const POLL_MS = 10000;

// ─── Toggle Switch ──────────────────────────────────────────────────────────────
const ToggleSwitch = ({ id, checked, onChange, disabled }) => (
  <label className={`relative inline-flex items-center cursor-pointer select-none ${disabled ? 'opacity-50 cursor-wait' : ''}`} htmlFor={`toggle-${id}`}>
    <input
      id={`toggle-${id}`}
      type="checkbox"
      className="sr-only peer"
      checked={checked}
      onChange={e => !disabled && onChange(e.target.checked)}
      disabled={disabled}
      aria-label={`Toggle SMS for subaccount ${id}`}
    />
    <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-[#2b83fa]/50 dark:bg-[#1c1e21] rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#2b83fa] shadow-sm border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)]" />
  </label>
);

// ─── Rate Limit Input ──────────────────────────────────────────────────────────
const RateLimitInput = ({ locationId, value, onSave, disabled }) => {
  const [local, setLocal] = useState(value);
  const [saving, setSaving] = useState(false);

  useEffect(() => { setLocal(value); }, [value]);

  const commitChange = async (newVal) => {
    let parsed = parseInt(newVal, 10);
    if (isNaN(parsed) || parsed < 1) parsed = 1;
    setLocal(parsed);
    if (parsed === value) return;
    setSaving(true);
    await onSave(locationId, parsed);
    setSaving(false);
  };

  const handleBlur = () => commitChange(local);
  
  const adjust = (delta) => {
    if (disabled || saving) return;
    let parsed = parseInt(local, 10);
    if (isNaN(parsed)) parsed = value;
    commitChange(parsed + delta);
  };

  return (
    <div className="inline-flex items-center bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] rounded-lg overflow-hidden">
      <button 
        onClick={() => adjust(-1)}
        disabled={disabled || saving || local <= 1}
        className="px-2.5 py-1.5 text-[#6b7280] dark:text-[#9aa0a9] hover:bg-black/5 dark:hover:bg-white/5 hover:text-[#111111] dark:hover:text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed border-r border-[#00000010] dark:border-[#ffffff10]"
      >
        <FiMinus className="w-3.5 h-3.5" />
      </button>
      <input
        id={`rate-${locationId}`}
        type="number"
        className="w-[50px] py-1.5 bg-transparent text-[#111827] dark:text-[#f1f2f4] text-[13px] font-bold text-center focus:outline-none focus:bg-white dark:focus:bg-[#121415] transition-all"
        value={local}
        min={1}
        max={9999}
        onChange={e => setLocal(e.target.value)}
        onBlur={handleBlur}
        disabled={disabled || saving}
        title="Auto-saves on blur"
      />
      <button 
        onClick={() => adjust(1)}
        disabled={disabled || saving}
        className="px-2.5 py-1.5 text-[#6b7280] dark:text-[#9aa0a9] hover:bg-black/5 dark:hover:bg-white/5 hover:text-[#111111] dark:hover:text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed border-l border-[#00000010] dark:border-[#ffffff10]"
      >
        <FiPlus className="w-3.5 h-3.5" />
      </button>
    </div>
  );
};

// ─── Reset Confirm Modal ───────────────────────────────────────────────────────
const ResetModal = ({ subaccount, onConfirm, onCancel, loading }) => (
  <div className="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[1000] animate-[fadeIn_0.15s_ease]" onClick={onCancel}>
    <div className="bg-white dark:bg-[#141618] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] rounded-xl shadow-2xl p-7 w-full max-w-[380px] mx-4 animate-[scaleIn_0.2s_ease]" onClick={e => e.stopPropagation()}>
      <div className="text-[16px] font-bold text-[#111111] dark:text-white mb-2">Reset Attempt Counter?</div>
      <div className="text-[13.5px] text-[#6b7280] dark:text-[#9aa0a9] mb-6 leading-relaxed">
        This will reset the send counter for <strong className="text-[#111111] dark:text-white font-semibold">{subaccount?.location_name || subaccount?.location_id}</strong> back
        to <strong className="text-[#111111] dark:text-white font-semibold">0</strong>. They will immediately be able to send up to their rate limit again.
      </div>
      <div className="flex gap-2.5 justify-end">
        <button 
          className="flex items-center justify-center px-4 py-2 rounded-lg text-[13px] font-semibold bg-[#f0f2f8] dark:bg-[#1c1e21] text-[#6b7280] dark:text-[#9aa0a9] hover:bg-[rgba(0,0,0,0.05)] dark:hover:bg-[rgba(255,255,255,0.05)] hover:text-[#111111] dark:hover:text-white transition-colors border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)]"
          onClick={onCancel} disabled={loading}
        >
          Cancel
        </button>
        <button 
          className="flex items-center justify-center px-4 py-2 rounded-lg text-[13px] font-semibold bg-red-500/10 text-red-500 hover:bg-red-500 hover:text-white transition-colors border border-red-500/20"
          onClick={onConfirm} disabled={loading}
        >
          {loading ? 'Resetting…' : 'Yes, Reset'}
        </button>
      </div>
    </div>
  </div>
);

// ─── Upgrade Modal (403 Limit) ─────────────────────────────────────────────────
const UpgradeModal = ({ onCancel }) => (
  <div className="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-[1000] animate-[fadeIn_0.15s_ease]" onClick={onCancel}>
    <div className="bg-white dark:bg-[#141618] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] rounded-xl shadow-2xl p-7 w-full max-w-[380px] mx-4 animate-[scaleIn_0.2s_ease]" onClick={e => e.stopPropagation()}>
      <div className="flex items-center gap-3 mb-3 text-red-500">
        <FiAlertTriangle className="w-6 h-6" />
        <div className="text-[16px] font-bold text-[#111111] dark:text-white">Activation Limit Reached</div>
      </div>
      <div className="text-[13.5px] text-[#6b7280] dark:text-[#9aa0a9] mb-6 leading-relaxed">
        This subaccount has reached the maximum of <strong>3 activations</strong> for the myCRMSIM routing feature to prevent abuse. 
        Please upgrade your agency plan to unlock unlimited activations.
      </div>
      <div className="flex justify-end">
        <button 
          className="flex items-center justify-center px-6 py-2 rounded-lg text-[13px] font-semibold bg-[#2b83fa] text-white hover:bg-[#1d6bd4] transition-colors shadow-md shadow-[#2b83fa]/20"
          onClick={onCancel}
        >
          Got it
        </button>
      </div>
    </div>
  </div>
);

// ─── Skeleton Rows ─────────────────────────────────────────────────────────────
const SkeletonRows = ({ count = 5 }) => (
  <>
    {[...Array(count)].map((_, i) => (
      <tr key={i} className="border-b border-[#0000000a] dark:border-[#ffffff0a]">
        <td className="px-6 py-4">
          <div className="flex flex-col gap-1.5">
            <div className="skeleton h-3.5 w-36 rounded-md" />
            <div className="skeleton h-2.5 w-24 rounded-md" />
          </div>
        </td>
        <td className="px-6 py-4"><div className="skeleton h-4 w-28 rounded-md" /></td>
        <td className="px-6 py-4"><div className="skeleton h-[30px] w-24 rounded-lg" /></td>
        <td className="px-6 py-4"><div className="skeleton h-6 w-16 rounded-full" /></td>
        <td className="px-6 py-4"><div className="skeleton h-[30px] w-20 rounded-lg" /></td>
        <td className="px-6 py-4 flex justify-end"><div className="skeleton h-6 w-11 rounded-full" /></td>
      </tr>
    ))}
  </>
);

// ─── Main Page ─────────────────────────────────────────────────────────────────
export const Subaccounts = () => {
  const { agencyId } = useAgency();
  const { toasts, showToast, dismissToast } = useToast();

  const [subaccounts, setSubaccounts]   = useState([]);
  const [loading, setLoading]           = useState(true);
  const [refreshing, setRefreshing]     = useState(false);
  const [error, setError]               = useState(null);
  const [toggleLoading, setToggleLoading] = useState({}); // { [id]: bool }
  const [installedLocations, setInstalledLocations] = useState(new Set());
  const [resetModal, setResetModal]     = useState(null); // subaccount obj | null
  const [resetLoading, setResetLoading] = useState(false);
  const [upgradeModalOpen, setUpgradeModalOpen] = useState(false);
  const [lastPolled, setLastPolled]     = useState(null);
  const [currentPage, setCurrentPage]   = useState(1);
  const [searchTerm, setSearchTerm]     = useState('');
  const [sortField, setSortField]       = useState('location_name');
  const [sortDirection, setSortDirection] = useState('asc');

  const pollRef = useRef(null);

  // ── Fetch ──────────────────────────────────────────────────────────────────
  const fetchSubaccounts = useCallback(async ({ silent = false } = {}) => {
    if (!agencyId) { setLoading(false); return; }
    if (!silent) setRefreshing(true);

    try {
      const [data, installs] = await Promise.all([
        getSubaccounts(agencyId),
        checkInstallStatus(agencyId).catch(() => []) // fail gracefully if endpoint doesn't exist yet
      ]);
      setSubaccounts(data.subaccounts || []);
      setInstalledLocations(new Set(installs));
      setError(null);
      setLastPolled(new Date());
    } catch (e) {
      setError(e.message);
      if (!silent) showToast(`Failed to load subaccounts: ${e.message}`, 'error');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [agencyId]);

  // Initial load + polling
  useEffect(() => {
    fetchSubaccounts();
    pollRef.current = setInterval(() => fetchSubaccounts({ silent: true }), POLL_MS);
    return () => clearInterval(pollRef.current);
  }, [fetchSubaccounts]);

  // ── Toggle ─────────────────────────────────────────────────────────────────
  const handleToggle = async (locationId, enabled) => {
    setToggleLoading(prev => ({ ...prev, [locationId]: true }));

    // Optimistic update
    setSubaccounts(prev =>
      prev.map(s => s.location_id === locationId ? { ...s, toggle_enabled: enabled } : s)
    );

    const targetSubaccount = subaccounts.find(s => s.location_id === locationId) || {};

    try {
      await updateSubaccountSettings(agencyId, {
        location_id: locationId,
        toggle_enabled: enabled,
        rate_limit: targetSubaccount.rate_limit ?? 5,
        reset_counter: false
      });
      // Once succeeded, update toggle_activation_count roughly in optimistic state
      setSubaccounts(prev =>
        prev.map(s => s.location_id === locationId 
          ? { 
              ...s, 
              toggle_activation_count: enabled 
                ? (s.toggle_activation_count || 0) + 1 
                : s.toggle_activation_count 
            } 
          : s)
      );
      showToast(
        `SMS ${enabled ? 'enabled' : 'disabled'} for subaccount.`,
        enabled ? 'success' : 'info'
      );
    } catch (e: any) {
      // Rollback
      setSubaccounts(prev =>
        prev.map(s => s.location_id === locationId ? { ...s, toggle_enabled: !enabled } : s)
      );
      if (e.status === 403) {
        setUpgradeModalOpen(true);
      } else {
        showToast(`Toggle failed: ${e.message}`, 'error');
      }
    } finally {
      setToggleLoading(prev => ({ ...prev, [locationId]: false }));
    }
  };

  // ── Rate Limit Save ────────────────────────────────────────────────────────
  const handleRateLimitSave = async (locationId, newLimit) => {
    const targetSubaccount = subaccounts.find(s => s.location_id === locationId) || {};
    try {
      await updateSubaccountSettings(agencyId, {
        location_id: locationId,
        toggle_enabled: !!targetSubaccount.toggle_enabled,
        rate_limit: newLimit,
        reset_counter: false
      });
      setSubaccounts(prev =>
        prev.map(s => s.location_id === locationId ? { ...s, rate_limit: newLimit } : s)
      );
      showToast('Rate limit updated.', 'success');
    } catch (e) {
      showToast(`Failed to update rate limit: ${e.message}`, 'error');
    }
  };

  // ── Reset Attempt Counter ──────────────────────────────────────────────────
  const handleResetConfirm = async () => {
    if (!resetModal) return;
    setResetLoading(true);
    try {
      await updateSubaccountSettings(agencyId, {
        location_id: resetModal.location_id,
        toggle_enabled: !!resetModal.toggle_enabled,
        rate_limit: resetModal.rate_limit ?? 5,
        reset_counter: true
      });
      setSubaccounts(prev =>
        prev.map(s =>
          s.location_id === resetModal.location_id
            ? { ...s, attempt_count: 0 }
            : s
        )
      );
      showToast(`Counter reset for ${resetModal.location_name || resetModal.location_id}.`, 'success');
      setResetModal(null);
    } catch (e) {
      showToast(`Reset failed: ${e.message}`, 'error');
    } finally {
      setResetLoading(false);
    }
  };

  // ── Derived stats ──────────────────────────────────────────────────────────
  const active  = subaccounts.filter(s => s.toggle_enabled).length;
  const atLimit = subaccounts.filter(s => s.attempt_count >= s.rate_limit).length;

  const filtered = subaccounts.filter(s => 
    s.location_name?.toLowerCase().includes(searchTerm.toLowerCase()) || 
    s.location_id.toLowerCase().includes(searchTerm.toLowerCase()) ||
    s.company_name?.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const sorted = [...filtered].sort((a, b) => {
    let valA = a[sortField];
    let valB = b[sortField];
    if (typeof valA === 'string') valA = valA.toLowerCase();
    if (typeof valB === 'string') valB = valB.toLowerCase();
    
    if (valA < valB) return sortDirection === 'asc' ? -1 : 1;
    if (valA > valB) return sortDirection === 'asc' ? 1 : -1;
    return 0;
  });

  const ITEMS_PER_PAGE = 10;
  const total = sorted.length;
  const totalPages = Math.ceil(total / ITEMS_PER_PAGE);
  const paginatedSubaccounts = sorted.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

  useEffect(() => {
    if (currentPage > totalPages && totalPages > 0) {
      setCurrentPage(totalPages);
    }
  }, [total, currentPage, totalPages]);
  
  const handleSort = (field) => {
    if (sortField === field) {
      setSortDirection(prev => prev === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };
  
  const SortIcon = ({ field }) => {
    if (sortField !== field) return null;
    return sortDirection === 'asc' ? <FiArrowUp className="inline w-3 h-3 ml-1" /> : <FiArrowDown className="inline w-3 h-3 ml-1" />;
  };

  return (
    <AgencyLayout
      title="Subaccounts"
      subtitle="Control SMS access, rate limits, and attempt counters for each subaccount"
    >
      <ToastContainer toasts={toasts} onDismiss={dismissToast} />
      {resetModal && (
        <ResetModal
          subaccount={resetModal}
          onConfirm={handleResetConfirm}
          onCancel={() => setResetModal(null)}
          loading={resetLoading}
        />
      )}
      {upgradeModalOpen && (
        <UpgradeModal onCancel={() => setUpgradeModalOpen(false)} />
      )}

      {/* Page header */}
      <div className="flex items-start justify-between mb-6 flex-wrap gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white tracking-tight">
            {subaccounts[0]?.agency_name || subaccounts[0]?.company_name || 'NOLA SMS Pro'} Subaccounts
          </h1>
          <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">
            Toggle SMS access, set rate limits, and reset send counters for each subaccount under your agency.
          </p>
        </div>
        <div className="flex items-center gap-2.5 flex-wrap">
          {lastPolled && (
            <span className="inline-flex items-center gap-1.5 px-3 py-1 bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] rounded-full text-[11px] font-medium text-[#6b7280] dark:text-[#9aa0a9]">
              <span className="w-1.5 h-1.5 rounded-full bg-[#22c55e] animate-[pulse_2s_ease-in-out_infinite]" />
              Live · {lastPolled.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
            </span>
          )}
          <button
            className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-[12.5px] font-semibold bg-[#f0f2f8] dark:bg-[#1c1e21] text-[#6b7280] dark:text-[#9aa0a9] hover:bg-[rgba(0,0,0,0.05)] dark:hover:bg-[rgba(255,255,255,0.05)] hover:text-[#111111] dark:hover:text-white border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            onClick={() => fetchSubaccounts()}
            disabled={refreshing}
            id="refresh-subaccounts-btn"
          >
            <FiRefreshCw className={refreshing ? 'animate-spin' : ''} />
            Refresh
          </button>
        </div>
      </div>

      {/* No Agency ID warning */}
      {!agencyId && (
        <div className="bg-[#f59e0b]/[0.05] border border-[#f59e0b]/30 rounded-xl p-4 mb-5 shadow-sm flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div className="flex items-center gap-2 text-[#f59e0b]">
              <FiAlertTriangle className="w-[18px] h-[18px]" />
              <strong className="text-[13px]">No GHL Company ID linked.</strong>
            </div>
            <p className="text-[12.5px] mt-1.5 text-[#6e6e73] dark:text-[#94959b]">
              Your account is not connected to a GoHighLevel agency. Log in again to link your GHL Company ID.
            </p>
          </div>
          <a
            href="/login"
            className="shrink-0 px-4 py-2 bg-[#f59e0b] text-white text-[12.5px] font-bold rounded-lg hover:bg-[#d97706] transition-colors"
          >
            Connect Now →
          </a>
        </div>
      )}

      {/* Quick stats strip */}
      {!loading && total > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
          <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-5 shadow-sm hover:shadow-md hover:-translate-y-[1px] transition-all group">
            <div className="text-[11.5px] font-semibold uppercase tracking-widest text-[#6e6e73] dark:text-[#9aa0a6] mb-2">Total</div>
            <div className="text-3xl font-extrabold tracking-tight leading-none text-[#2b83fa]">{total}</div>
            <div className="text-[11.5px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1.5 line-clamp-1">subaccounts</div>
          </div>
          <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-5 shadow-sm hover:shadow-md hover:-translate-y-[1px] transition-all group">
            <div className="text-[11.5px] font-semibold uppercase tracking-widest text-[#6e6e73] dark:text-[#9aa0a6] mb-2">SMS Active</div>
            <div className="text-3xl font-extrabold tracking-tight leading-none text-[#22c55e]">{active}</div>
            <div className="text-[11.5px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1.5 line-clamp-1">webhook enabled</div>
          </div>
          <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-5 shadow-sm hover:shadow-md hover:-translate-y-[1px] transition-all group">
            <div className="text-[11.5px] font-semibold uppercase tracking-widest text-[#6e6e73] dark:text-[#9aa0a6] mb-2">At Limit</div>
            <div className="text-3xl font-extrabold tracking-tight leading-none text-[#ef4444]">{atLimit}</div>
            <div className="text-[11.5px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1.5 line-clamp-1">need reset</div>
          </div>
        </div>
      )}

      {/* Main Container */}
      {!loading && total === 0 ? (
        <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
          <FiUsers className="w-8 h-8 mx-auto mb-3 opacity-30" />
          <div className="text-[15px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6]">No subaccounts found for your agency.</div>
          <div className="text-[13px] text-[#9ca3af] mt-1">
            Subaccounts will appear here once they are registered in the <code className="bg-black/5 dark:bg-white/10 px-1 py-0.5 rounded font-mono text-[11px]">ghl_tokens</code> Firestore collection.
          </div>
        </div>
      ) : (
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[#e5e5e5] dark:border-white/5 rounded-2xl shadow-sm overflow-hidden flex flex-col">
          <div className="px-6 py-5 border-b border-[#e5e5e5] dark:border-white/5 flex items-center justify-between flex-wrap gap-4">
            <div>
              <div className="text-[15px] font-bold text-[#111111] dark:text-white tracking-tight">All Subaccounts</div>
              <div className="text-[13px] text-[#6e6e73] dark:text-[#94959b] mt-1">Changes take effect immediately. Rate limit auto-saves on blur.</div>
            </div>
            <div className="relative">
                <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-3.5 h-3.5" />
                <input 
                    type="text"
                    placeholder="Search subaccounts..."
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    className="pl-9 pr-4 py-1.5 rounded-lg text-[12.5px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all w-48 sm:w-64"
                />
            </div>
          </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-[#0000000a] dark:border-[#ffffff0a]">
                <th onClick={() => handleSort('location_name')} className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap cursor-pointer hover:text-[#111111] dark:hover:text-white transition-colors">Subaccount <SortIcon field="location_name" /></th>
                <th onClick={() => handleSort('agency_name')} className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap cursor-pointer hover:text-[#111111] dark:hover:text-white transition-colors">Agency Name <SortIcon field="agency_name" /></th>
                <th onClick={() => handleSort('rate_limit')} className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap cursor-pointer hover:text-[#111111] dark:hover:text-white transition-colors">Rate Limit <SortIcon field="rate_limit" /></th>
                <th onClick={() => handleSort('attempt_count')} className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap cursor-pointer hover:text-[#111111] dark:hover:text-white transition-colors">Sends Used <SortIcon field="attempt_count" /></th>
                <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap text-right">SMS Active</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[rgba(0,0,0,0.05)] dark:divide-[rgba(255,255,255,0.05)]">
              {loading ? (
                <SkeletonRows count={5} />
              ) : (
                paginatedSubaccounts.map(sub => {
                  const isAtLimit   = sub.attempt_count >= sub.rate_limit;
                  const isNearLimit = !isAtLimit && sub.attempt_count >= sub.rate_limit * 0.8;
                  const isBusy      = !!toggleLoading[sub.location_id];

                  return (
                    <tr key={sub.location_id} className="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                      {/* Name */}
                      <td className="px-6 py-4 align-middle">
                        <div className="flex flex-col">
                          <span className="text-[13.5px] font-semibold text-[#111111] dark:text-[#ececf1]">
                            {sub.location_name || <em className="text-[#9ca3af]">Unnamed</em>}
                          </span>
                          <span className="text-[11px] font-mono text-[#6e6e73] dark:text-[#94959b] mt-0.5">{sub.location_id}</span>
                        </div>
                      </td>

                      {/* Agency Name */}
                      <td className="px-6 py-4 align-middle">
                        <span className="text-[13px] font-medium text-[#6b7280] dark:text-[#9ca3af]">
                          {sub.agency_name || sub.company_name || <em className="text-[#9ca3af] opacity-50">Unknown Agency</em>}
                        </span>
                      </td>

                      {/* Rate Limit */}
                      <td className="px-6 py-4 align-middle">
                        <RateLimitInput
                          locationId={sub.location_id}
                          value={sub.rate_limit ?? 5}
                          onSave={handleRateLimitSave}
                          disabled={!agencyId}
                        />
                      </td>

                      {/* Attempt Counter & Inline Actions */}
                      <td className="px-6 py-4 align-middle">
                        <div className="flex items-center gap-3">
                          <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[12.5px] font-bold ${isAtLimit ? 'bg-red-50 dark:bg-red-500/10 text-red-500' : isNearLimit ? 'bg-[#f59e0b]/10 text-[#f59e0b]' : 'bg-[#f0f2f8] dark:bg-white/5 text-[#6b7280] dark:text-[#94959b]'}`}>
                            {sub.attempt_count ?? 0} / {sub.rate_limit ?? 5}
                          </span>
                          {isAtLimit && (
                            <button
                              className="flex items-center justify-center p-1.5 bg-red-50 dark:bg-red-500/10 text-red-500 rounded border border-red-200 dark:border-red-500/20 hover:bg-red-500 hover:text-white transition-all shadow-sm"
                              onClick={() => setResetModal(sub)}
                              title="Reset Counter"
                            >
                              <FiRotateCcw className="w-3.5 h-3.5" />
                            </button>
                          )}
                        </div>
                      </td>

                      {/* SMS Active Toggle (Rightmost) */}
                      <td className="px-6 py-4 align-middle">
                        <div className="flex flex-col items-end gap-1">
                          {installedLocations.has(sub.location_id) ? (
                            <>
                              <div className="flex items-center gap-2.5">
                                <span className={`text-[11.5px] font-bold ${sub.toggle_enabled ? 'text-[#22c55e]' : 'text-[#9ca3af]'}`}>
                                  {sub.toggle_enabled ? 'ON' : 'OFF'}
                                </span>
                                <ToggleSwitch
                                  id={sub.location_id}
                                  checked={!!sub.toggle_enabled}
                                  onChange={enabled => handleToggle(sub.location_id, enabled)}
                                  disabled={isBusy}
                                />
                              </div>
                              <div className="text-[10.5px] font-medium text-[#9ca3af]">
                                {sub.toggle_activation_count ?? 0}/3 activations
                              </div>
                            </>
                          ) : (
                            <div className="flex flex-col items-end gap-1.5">
                              <a
                                href="https://marketplace.leadconnectorhq.com/oauth/chooselocation?response_type=code&redirect_uri=https%3A%2F%2Fsms-api-116662437564.asia-southeast1.run.app%2Foauth%2Fcallback&client_id=6999da2b8f278296d95f7274-mmn30t4f&scope=workflows.readonly+conversations%2Fmessage.readonly+conversations.readonly+conversations.write+contacts.readonly+contacts.write+conversations%2Fmessage.write+saas%2Flocation.read+locations.readonly+locations%2Ftags.readonly+locations%2Ftags.write&version_id=6999da2b8f278296d95f7274"
                                target="_blank"
                                rel="noreferrer"
                                className="px-3 py-1.5 bg-[#2b83fa] hover:bg-[#1d6bd4] text-white text-[11.5px] font-bold inline-flex items-center rounded flex-shrink-0 transition-colors shadow-sm whitespace-nowrap"
                              >
                                Install App
                              </a>
                              <span className="text-[10px] text-[#ef4444] font-medium">Not Installed</span>
                            </div>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {/* Table footer */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between px-6 py-4 border-t border-[rgba(0,0,0,0.05)] dark:border-[#ffffff0a]">
                <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] font-medium">
                    Showing <span className="font-bold text-[#111111] dark:text-white">{(currentPage - 1) * ITEMS_PER_PAGE + 1}</span> to <span className="font-bold text-[#111111] dark:text-white">{Math.min(currentPage * ITEMS_PER_PAGE, total)}</span> of <span className="font-bold text-[#111111] dark:text-white">{total}</span> entries
                </div>
                <div className="flex items-center gap-1.5">
                    <button
                        disabled={currentPage === 1}
                        onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                        className="p-1.5 rounded-lg text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f0f0f0] dark:hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                    >
                        <FiChevronLeft className="w-4 h-4" />
                    </button>
                    
                    <div className="flex items-center gap-1">
                        {Array.from({ length: Math.min(5, totalPages - Math.floor((currentPage - 1) / 5) * 5) }, (_, i) => Math.floor((currentPage - 1) / 5) * 5 + 1 + i).map(page => (
                            <button
                                key={page}
                                onClick={() => setCurrentPage(page)}
                                className={`w-7 h-7 rounded-lg text-[12px] font-bold flex items-center justify-center transition-all ${
                                    currentPage === page
                                        ? 'bg-[#2b83fa] text-white shadow-sm'
                                        : 'text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f0f0f0] dark:hover:bg-white/5'
                                }`}
                            >
                                {page}
                            </button>
                        ))}
                    </div>

                    <button
                        disabled={currentPage === totalPages}
                        onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                        className="p-1.5 rounded-lg text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f0f0f0] dark:hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                    >
                        <FiChevronRight className="w-4 h-4" />
                    </button>
                </div>
            </div>
          )}
      </div>
      )}
    </AgencyLayout>
  );
};
