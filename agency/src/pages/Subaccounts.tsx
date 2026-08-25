import { devLog } from '../utils/devLog';
import React, { useEffect, useState, useCallback } from 'react';
import {
  FiAlertTriangle, FiUsers, FiRotateCcw, FiChevronLeft, FiChevronRight, FiChevronDown, FiSearch, FiPlus, FiMinus, FiArrowUp, FiArrowDown, FiExternalLink, FiDownload, FiRefreshCw, FiX, FiFilter, FiMoreVertical, FiEye
} from 'react-icons/fi';

const ADD_SUBACCOUNT_URL =
  'https://marketplace.leadconnectorhq.com/v2/oauth/chooselocation?response_type=code' +
  '&redirect_uri=https%3A%2F%2Fsmspro-api.nolacrm.io%2Foauth%2Fcallback' +
  '&client_id=6999da2b8f278296d95f7274-mmn30t4f' +
  '&scope=workflows.readonly+conversations%2Fmessage.readonly+conversations.readonly+conversations.write+contacts.readonly+contacts.write+conversations%2Fmessage.write+saas%2Flocation.read+locations.readonly+locations%2Ftags.readonly+locations%2Ftags.write+locations%2FcustomFields.readonly+oauth.write+oauth.readonly' +
  '&version_id=6999da2b8f278296d95f7274';
const API_BASE = import.meta.env.VITE_API_BASE || '';
import { collection, query, where, onSnapshot } from 'firebase/firestore';
import { ensureFirestoreAuth } from '../services/firestoreAuth.ts';
import { db } from '../services/firebaseConfig.ts';
import { AgencyLayout } from '../components/layout/AgencyLayout.tsx';
import { ToastContainer } from '../components/ui/ToastContainer.tsx';
import { useAgency } from '../context/AgencyContext.tsx';
import { useToast } from '../hooks/useToast.ts';
import {
  getSubaccounts,
  toggleSubaccount,
  updateSubaccountSettings,
  checkInstallStatus,
} from '../services/api.ts';
import { agencyFetch } from '../services/agencyApi.ts';
import { generateMonthlyReport } from '../utils/pdfGenerator';
import { AgencySubaccountProfile } from '../components/AgencySubaccountProfile.tsx';
import {
  getSubscriptionLimitText,
  isSubscriptionActive,
  isSubscriptionLimitReached,
  isUnlimitedSubscription,
  normalizeSubscriptionState,
} from '../utils/subscription.ts';



// ─── Toggle Switch ──────────────────────────────────────────────────────────────
const ToggleSwitch = ({ id, checked, onChange, disabled }) => (
  <label className={`relative inline-flex items-center select-none ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`} htmlFor={`toggle-${id}`}>
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
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => { setLocal(value); }, [value]);

  const commitChange = async (newVal) => {
    let parsed = parseInt(newVal, 10);
    if (isNaN(parsed) || parsed < 1) parsed = 1;
    setLocal(parsed);
    setEditing(false);
    if (parsed === value) return;
    try {
      setSaving(true);
      await onSave(locationId, parsed);
    } catch {
      setLocal(value);
    } finally {
      setSaving(false);
    }
  };

  const adjust = (delta) => {
    if (disabled || saving) return;
    let parsed = parseInt(local, 10);
    if (isNaN(parsed)) parsed = value;
    setLocal(Math.max(1, parsed + delta));
  };

  if (!editing) {
    return (
      <button
        type="button"
        onClick={() => !disabled && setEditing(true)}
        disabled={disabled || saving}
        title="Click to edit credit limit"
        className="flex flex-col items-start hover:bg-[#f0f2f8] dark:hover:bg-white/5 rounded-lg px-2 py-1 transition-colors group/limit disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <span className="text-[13px] font-bold text-[#111111] dark:text-white group-hover/limit:text-[#2b83fa] transition-colors">{Number(value || 0).toLocaleString()}</span>
        <span className="text-[9px] text-[#9aa0a6] font-medium uppercase tracking-tight group-hover/limit:text-[#2b83fa]/60 transition-colors">click to edit</span>
      </button>
    );
  }

  return (
    <div className="inline-flex items-center rounded-lg border border-[#d8dce3] dark:border-white/10 bg-white dark:bg-[#0d0e10] overflow-hidden shadow-sm">
      <button
        type="button"
        onMouseDown={event => event.preventDefault()}
        onClick={() => adjust(-1)}
        disabled={disabled || saving || local <= 1}
        className="px-2.5 py-1.5 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed border-r border-[#e0e0e0] dark:border-white/10"
      >
        <FiMinus className="w-3.5 h-3.5" />
      </button>
      <input
        id={`rate-${locationId}`}
        type="number"
        className="w-14 py-1.5 bg-transparent text-[#111827] dark:text-[#f1f2f4] text-[13px] font-bold text-center focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
        value={local}
        min={1}
        max={9999}
        onChange={e => setLocal(e.target.value)}
        onBlur={() => commitChange(local)}
        onKeyDown={event => {
          if (event.key === 'Enter') commitChange(local);
          if (event.key === 'Escape') {
            setLocal(value);
            setEditing(false);
          }
        }}
        disabled={disabled || saving}
        autoFocus
        title="Auto-saves on blur"
      />
      <button
        type="button"
        onMouseDown={event => event.preventDefault()}
        onClick={() => adjust(1)}
        disabled={disabled || saving}
        className="px-2.5 py-1.5 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed border-l border-[#e0e0e0] dark:border-white/10"
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
        to <strong className="text-[#111111] dark:text-white font-semibold">0</strong>. They will immediately be able to send up to their credit limit again.
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
        This subaccount cannot be enabled at this time. Please contact support
        or try again later. If this issue persists, reach out to your NOLA SMS Pro admin.
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
        <td className="px-6 py-4"><div className="skeleton h-6 w-24 rounded-full" /></td>
        <td className="px-6 py-4"><div className="skeleton h-4 w-28 rounded-md" /></td>
        <td className="px-6 py-4"><div className="skeleton h-4 w-28 rounded-md" /></td>
        <td className="px-6 py-4"><div className="skeleton h-[30px] w-24 rounded-lg" /></td>
        <td className="px-6 py-4"><div className="skeleton h-6 w-16 rounded-full" /></td>
        <td className="px-6 py-4"><div className="skeleton h-[30px] w-20 rounded-lg" /></td>
        <td className="px-6 py-4"><div className="skeleton h-[30px] w-20 rounded-lg" /></td>
        <td className="px-6 py-4"><div className="skeleton h-6 w-11 rounded-full" /></td>
        <td className="px-6 py-4 flex justify-end"><div className="skeleton h-9 w-9 rounded-lg" /></td>
      </tr>
    ))}
  </>
);

const getTransactionMonth = (tx: any): string => {
  const raw = tx?.timestamp || tx?.created_at || tx?.createdAt || tx?.date;
  if (!raw) return '';
  if (typeof raw === 'object' && raw.seconds) {
    const date = new Date(Number(raw.seconds) * 1000);
    return Number.isNaN(date.getTime()) ? '' : `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }
  const text = String(raw);
  const parsed = new Date(text);
  if (!Number.isNaN(parsed.getTime())) {
    return `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}`;
  }
  return text.slice(0, 7);
};

const getReportMonthOptions = (transactions: any[]): string[] =>
  Array.from(new Set<string>(transactions.map(getTransactionMonth).filter(Boolean))).sort().reverse();

const getReportMonthLabel = (month: string): string => {
  const [year, monthNumber] = month.split('-').map(Number);
  if (!year || !monthNumber) return month;
  return new Date(year, monthNumber - 1).toLocaleString('default', { month: 'long', year: 'numeric' });
};

const getReportMonthCount = (transactions: any[], month: string) =>
  month === 'All'
    ? transactions.length
    : transactions.filter(tx => getTransactionMonth(tx) === month).length;

const getCurrentReportMonth = () => {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
};

const getReportMonthSelection = (transactions: any[]) => {
  if (transactions.length === 0) return 'All';
  const currentMonth = getCurrentReportMonth();
  const months = getReportMonthOptions(transactions);
  return months.includes(currentMonth) ? currentMonth : months[0] || 'All';
};

const readReportTransactions = (json: any): any[] => {
  if (Array.isArray(json)) return json;
  if (Array.isArray(json?.transactions)) return json.transactions;
  if (Array.isArray(json?.data)) return json.data;
  return [];
};

const buildSubaccountReportProfile = (subaccount: any, agencyId: string | null) => ({
  accountName: subaccount.location_name || subaccount.location_id || 'Subaccount',
  locationName: subaccount.location_name,
  locationId: subaccount.location_id,
  agencyName: subaccount.agency_name || subaccount.company_name,
  companyName: subaccount.company_name || subaccount.agency_name,
  companyId: agencyId,
  reportTitle: 'SUBACCOUNT CREDIT REPORT',
  currentBalance: subaccount.credit_balance ?? subaccount.credits ?? 0,
});
const SUBACCOUNT_STATUS_FILTERS = [
  { id: 'all', label: 'All Statuses' },
  { id: 'active', label: 'Active' },
  { id: 'sms_off', label: 'SMS Off' },
  { id: 'not_installed', label: 'Not Installed' },
  { id: 'at_limit', label: 'At Limit' },
  { id: 'low_credit', label: 'Low/No Credits' },
];

const getSubaccountLastActiveRaw = (subaccount: any) => (
  subaccount.last_active_at ||
  subaccount.last_active ||
  subaccount.lastActiveAt ||
  subaccount.lastActive ||
  subaccount.last_login_at ||
  subaccount.last_login ||
  subaccount.lastLoginAt ||
  subaccount.lastLogin ||
  subaccount.updated_at ||
  subaccount.updatedAt ||
  subaccount.created_at ||
  subaccount.createdAt ||
  ''
);

const getSubaccountTimestamp = (value: any) => {
  if (!value) return 0;
  if (typeof value === 'object' && value.seconds) return Number(value.seconds) * 1000;
  const parsed = new Date(value).getTime();
  return Number.isNaN(parsed) ? 0 : parsed;
};

const formatSubaccountLastActive = (subaccount: any) => {
  const raw = getSubaccountLastActiveRaw(subaccount);
  const timestamp = getSubaccountTimestamp(raw);
  if (!timestamp) return 'No activity yet';
  return new Date(timestamp).toLocaleString([], {
    month: 'short',
    day: 'numeric',
    year: new Date(timestamp).getFullYear() === new Date().getFullYear() ? undefined : 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const getSubaccountStatusMeta = (subaccount: any, installedLocations: Set<any>) => {
  const isInstalled = installedLocations.size === 0 || installedLocations.has(subaccount.location_id) || subaccount.is_live;
  const rateLimit = Number(subaccount.rate_limit ?? 0);
  const attempts = Number(subaccount.attempt_count ?? 0);
  const credits = Number(subaccount.credit_balance ?? subaccount.credits ?? 0);

  if (!isInstalled) {
    return { id: 'not_installed', label: 'Not Installed', className: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-white/5 dark:text-slate-300 dark:border-white/10' };
  }
  if (!subaccount.toggle_enabled) {
    return { id: 'sms_off', label: 'SMS Off', className: 'bg-gray-100 text-gray-600 border-gray-200 dark:bg-white/5 dark:text-gray-400 dark:border-white/10' };
  }
  if (rateLimit > 0 && attempts >= rateLimit) {
    return { id: 'at_limit', label: 'At Limit', className: 'bg-red-50 text-red-600 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20' };
  }
  if (credits <= 0) {
    return { id: 'no_credit', label: 'No Credits', className: 'bg-red-50 text-red-600 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20' };
  }
  if (credits < 25) {
    return { id: 'low_credit', label: 'Low Credits', className: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/20' };
  }
  return { id: 'active', label: 'Active', className: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20' };
};

const matchesSubaccountStatusFilter = (subaccount: any, statusFilter: string, installedLocations: Set<any>) => {
  if (statusFilter === 'all') return true;
  const status = getSubaccountStatusMeta(subaccount, installedLocations).id;
  if (statusFilter === 'low_credit') return status === 'low_credit' || status === 'no_credit';
  return status === statusFilter;
};

const SubaccountStatusBadge = ({ meta }: { meta: any }) => (
  <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-black uppercase tracking-wider whitespace-nowrap ${meta.className}`}>
    {meta.label}
  </span>
);

// ─── Main Page ─────────────────────────────────────────────────────────────────
export const Subaccounts = () => {
  const { agencyId } = useAgency();
  const { toasts, showToast, dismissToast } = useToast();

  const [profileSubaccount, setProfileSubaccount] = useState<any>(null);
  const [subaccounts, setSubaccounts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [toggleLoading, setToggleLoading] = useState({}); // { [id]: bool }
  const [installedLocations, setInstalledLocations] = useState(new Set());
  const [resetModal, setResetModal] = useState(null); // subaccount obj | null
  const [resetLoading, setResetLoading] = useState(false);
  const [subState, setSubState] = useState<any>(null);
  const [upgradeModalOpen, setUpgradeModalOpen] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [sortField, setSortField] = useState('location_name');
  const [sortDirection, setSortDirection] = useState('asc');
  const [reportSubaccount, setReportSubaccount] = useState<any>(null);
  const [reportTransactions, setReportTransactions] = useState<any[]>([]);
  const [reportSelectedMonth, setReportSelectedMonth] = useState('All');
  const [reportLoading, setReportLoading] = useState(false);
  const [statusFilterMenuOpen, setStatusFilterMenuOpen] = useState(false);
  const statusFilterMenuRef = React.useRef<HTMLDivElement>(null);
  const [actionMenuSubId, setActionMenuSubId] = useState<string | null>(null);
  const [actionMenuPos, setActionMenuPos] = useState({ top: 0, right: 0 });
  const actionMenuRef = React.useRef<HTMLDivElement>(null);

  // ── Initial load (PHP) ───────────────────────────────────────────────────────
  // The PHP API aggregates richer data (location name, rate limit, credits, etc.)
  // from Firestore server-side and returns a normalised shape. This runs once per
  // agencyId to seed the table. Real-time toggle state is handled by the listener below.
  useEffect(() => {
    if (!agencyId) { setLoading(false); return; }
    setLoading(true);

    Promise.all([
      getSubaccounts(agencyId),
      agencyFetch(`${API_BASE}/api/billing/subscription.php?agency_id=${encodeURIComponent(agencyId)}`, {
        credentials: 'include',
      }).then(r => r.ok ? r.json() : null).catch(() => null)
    ])
      .then(([subData, subStateData]) => {
        const loadedSubaccounts = subData.subaccounts || [];
        setSubaccounts(loadedSubaccounts);
        setError(null);
        setSubState(normalizeSubscriptionState(subStateData, {
          fallbackSubaccountsUsed: loadedSubaccounts.filter((sub: any) => sub.toggle_enabled).length,
        }));
      })
      .catch(e => {
        setError(e.message);
        showToast(`Failed to load subaccounts: ${e.message}`, 'error');
      })
      .finally(() => setLoading(false));

    // Install status: background, non-blocking
    checkInstallStatus(agencyId)
      .then(installs => setInstalledLocations(new Set(installs)))
      .catch(() => { });
  }, [agencyId]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Real-time Firestore listener (live counters + toggle) ─────────────────
  // Patches ONLY the fields that are canonically stored on `ghl_tokens`:
  // - toggle_enabled
  // - attempt_count (sends used)
  // - rate_limit (credit limit)
  // - toggle_activation_count
  // Everything else (location_name, agency_name, credit_balance, etc.) remains
  // sourced from the initial PHP load to avoid wiping richer fields.
  useEffect(() => {
    if (!agencyId) return;
    let unsubscribe: (() => void) | undefined;

    const setup = async () => {
      try {
        await ensureFirestoreAuth();

        const q = query(
          collection(db, 'ghl_tokens'),
          where('companyId', '==', agencyId)
        );

        unsubscribe = onSnapshot(
          q,
          (snapshot) => {
            // Build a map of live states keyed by location_id
            const liveStates = new Map<string, { toggle_enabled: boolean; attempt_count: number; rate_limit: number; toggle_activation_count: number }>();
            snapshot.docs.forEach(doc => {
              const d = doc.data();
              const id = d.location_id ?? doc.id;
              liveStates.set(id, {
                toggle_enabled: typeof d.toggle_enabled === 'boolean' ? d.toggle_enabled : true,
                attempt_count:  Number(d.attempt_count ?? 0),
                rate_limit: Number(d.rate_limit ?? 5),
                toggle_activation_count: Number(d.toggle_activation_count ?? 0),
              });
            });

            // Patch only the live fields into the existing rows
            // If initial load hasn't completed yet, prev is [] and this is a no-op
            setSubaccounts(prev =>
              prev.map(s => {
                const live = liveStates.get(s.location_id);
                return live ? { ...s, ...live } : s;
              })
            );
          },
          (err) => {
            devLog.error('[Subaccounts] onSnapshot error:', err);
            // Don't surface as blocking error — data is still usable from PHP load
          }
        );
      } catch (err: any) {
        devLog.error('[Subaccounts] Firebase setup error:', err);
      }
    };

    setup();
    return () => { if (unsubscribe) unsubscribe(); };
  }, [agencyId]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Toggle ─────────────────────────────────────────────────────────────────
  // Optimistic update flips the UI instantly. The PHP endpoint writes to Firestore,
  // which triggers the onSnapshot listener (~1 s) to confirm the canonical state.
  // No lock mechanism needed — the listener naturally converges to the correct value.
  const handleToggle = async (locationId, enabled) => {
    const targetSubaccount = subaccounts.find(s => s.location_id === locationId);
    const wasEnabled = !!targetSubaccount?.toggle_enabled;
    if (enabled && !wasEnabled) {
      const activeCount = subaccounts.filter(s => s.toggle_enabled).length;
      const currentSubscription = {
        ...normalizeSubscriptionState(subState, {
          fallbackSubaccountsUsed: activeCount,
        }),
        subaccounts_used: activeCount,
      };
      const limit = currentSubscription.subaccount_limit;

      if (!isSubscriptionActive(currentSubscription.status)) {
        showToast(`Your subscription is ${currentSubscription.status.replace(/_/g, ' ')}. Please renew before enabling more subaccounts.`, 'error');
        setUpgradeModalOpen(true);
        return;
      }

      if (!isUnlimitedSubscription(limit) && activeCount >= limit) {
        showToast(`You have reached the limit of your ${currentSubscription.plan} plan (${getSubscriptionLimitText(limit)} active subaccounts). Please upgrade in the Subscription tab.`, 'error');
        setUpgradeModalOpen(true);
        return;
      }
    }

    setToggleLoading(prev => ({ ...prev, [locationId]: true }));

    // Optimistic update — flip UI immediately so the user sees instant feedback
    setSubaccounts(prev =>
      prev.map(s => {
        if (s.location_id !== locationId) return s;
        const wasEnabled = !!s.toggle_enabled;
        const next: any = { ...s, toggle_enabled: enabled };
        // Activation count increments only when going OFF -> ON (server-enforced max 3)
        if (enabled && !wasEnabled) {
          next.toggle_activation_count = Math.min(3, Number(s.toggle_activation_count ?? 0) + 1);
        }
        return next;
      })
    );

    try {
      await toggleSubaccount(agencyId, {
        subaccount_id: locationId,
        enabled,
      });
      // PHP write is done — onSnapshot will confirm within ~1 s automatically.
      showToast(
        `SMS ${enabled ? 'enabled' : 'disabled'} for subaccount.`,
        enabled ? 'success' : 'info'
      );
    } catch (e: any) {
      // Rollback optimistic state on failure (onSnapshot will also restore the correct server value)
      setSubaccounts(prev =>
        prev.map(s => {
          if (s.location_id !== locationId) return s;
          const next: any = { ...s, toggle_enabled: !enabled };
          // If the user attempted OFF -> ON and it failed, revert the optimistic activation bump.
          if (enabled) {
            next.toggle_activation_count = Math.max(0, Number(s.toggle_activation_count ?? 0) - 1);
          }
          return next;
        })
      );
      if (e.status === 403) {
        showToast(e.message || 'Activation limit reached. Please upgrade in the Subscription tab.', 'error');
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
      showToast('Credit limit updated.', 'success');
    } catch (e) {
      showToast(`Failed to update credit limit: ${e.message}`, 'error');
      throw e;
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

  const fetchReportForSubaccount = async (subaccount) => {
    if (!subaccount?.location_id) {
      showToast('This subaccount does not have a location ID for reporting.', 'error');
      return;
    }

    setReportSubaccount(subaccount);
    setReportSelectedMonth(getCurrentReportMonth());
    setReportTransactions([]);
    setReportLoading(true);

    try {
      const params = new URLSearchParams({
        account_id: 'default',
        limit: '5000',
        location_id: subaccount.location_id,
      });
      const loadTransactions = async (url: string) => {
        const res = await agencyFetch(url, { credentials: 'include' });
        const json = await res.json().catch(() => null);
        return { res, json };
      };

      let { res, json } = await loadTransactions(`${API_BASE}/api/get_credit_transactions?${params.toString()}`);
      if (!res.ok || (json?.status && json.status !== 'success')) {
        ({ res, json } = await loadTransactions(`${API_BASE}/api/get_credit_transactions.php?${params.toString()}`));
      }

      if (!res.ok || (json?.status && json.status !== 'success')) {
        throw new Error(json?.message || json?.error || 'Failed to load transaction history.');
      }

      const transactions = readReportTransactions(json);
      setReportTransactions(transactions);
      setReportSelectedMonth(getReportMonthSelection(transactions));
    } catch (e) {
      setReportTransactions([]);
      showToast(e instanceof Error ? e.message : 'Failed to load transaction history.', 'error');
    } finally {
      setReportLoading(false);
    }
  };

  // ── Derived stats ──────────────────────────────────────────────────────────
  const active = subaccounts.filter(s => s.toggle_enabled).length;
  const atLimit = subaccounts.filter(s => s.attempt_count >= s.rate_limit).length;
  const normalizedSubscription = normalizeSubscriptionState(subState, {
    fallbackSubaccountsUsed: active,
  });
  const effectiveSubscription = { ...normalizedSubscription, subaccounts_used: active };
  const subscriptionAllowsSubaccounts = isSubscriptionActive(effectiveSubscription.status);
  const atSubscriptionLimit = !subscriptionAllowsSubaccounts || isSubscriptionLimitReached(effectiveSubscription);
  const subscriptionBlockedMessage = !subscriptionAllowsSubaccounts
    ? `Your subscription is ${effectiveSubscription.status.replace(/_/g, ' ')}. Please renew before adding subaccounts.`
    : `You have reached the limit of your ${effectiveSubscription.plan} plan (${getSubscriptionLimitText(effectiveSubscription.subaccount_limit)} active subaccounts). Please upgrade in the Subscription tab.`;

  const filtered = subaccounts.filter(s => {
    const statusMeta = getSubaccountStatusMeta(s, installedLocations);
    const search = searchTerm.toLowerCase().trim();
    const matchesSearch = !search || [
      s.location_name,
      s.location_id,
      s.company_name,
      s.agency_name,
      statusMeta.label,
    ].filter(Boolean).join(' ').toLowerCase().includes(search);

    return matchesSearch && matchesSubaccountStatusFilter(s, statusFilter, installedLocations);
  });

  const sorted = [...filtered].sort((a, b) => {
    let valA = sortField === 'last_active' ? getSubaccountTimestamp(getSubaccountLastActiveRaw(a)) : a[sortField];
    let valB = sortField === 'last_active' ? getSubaccountTimestamp(getSubaccountLastActiveRaw(b)) : b[sortField];
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

  useEffect(() => {
    if (!statusFilterMenuOpen) return;
    const handler = (event: MouseEvent) => {
      if (statusFilterMenuRef.current && !statusFilterMenuRef.current.contains(event.target as Node)) {
        setStatusFilterMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [statusFilterMenuOpen]);

  useEffect(() => {
    if (!actionMenuSubId) return;
    const handler = (event: MouseEvent) => {
      if (actionMenuRef.current && !actionMenuRef.current.contains(event.target as Node)) {
        setActionMenuSubId(null);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [actionMenuSubId]);

  const openSubActionMenu = (id: string, el: HTMLElement) => {
    const rect = el.getBoundingClientRect();
    setActionMenuPos({ top: rect.bottom + window.scrollY + 4, right: window.innerWidth - rect.right });
    setActionMenuSubId(id);
  };

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
      {reportSubaccount && (() => {
        const monthOptions = getReportMonthOptions(reportTransactions);
        const selectedEventCount = getReportMonthCount(reportTransactions, reportSelectedMonth);
        const canDownloadReport = !reportLoading && selectedEventCount > 0;
        const selectedLabel = reportSelectedMonth === 'All' ? 'All Transactions' : getReportMonthLabel(reportSelectedMonth);

        return (
          <div className="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-[fadeIn_0.15s_ease]">
            <div className="bg-white dark:bg-[#141618] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] rounded-2xl shadow-2xl w-full max-w-lg mx-4 animate-[scaleIn_0.2s_ease] overflow-hidden">
              <div className="px-6 py-5 border-b border-[#e5e5e5] dark:border-white/10 flex items-start justify-between gap-4">
                <div className="flex items-center gap-2.5 min-w-0">
                  <div className="w-9 h-9 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                    <FiDownload className="w-4 h-4" />
                  </div>
                  <div className="min-w-0">
                    <div className="text-[17px] font-bold text-[#111111] dark:text-white leading-tight">Download Report</div>
                    <div className="text-[12px] font-medium text-[#6b7280] dark:text-[#9aa0a9] mt-0.5 truncate">
                      {reportSubaccount.location_name || reportSubaccount.location_id}
                    </div>
                  </div>
                </div>
                <button
                  onClick={() => setReportSubaccount(null)}
                  className="p-2 rounded-full text-[#6e6e73] hover:text-[#111111] dark:hover:text-white hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                  title="Close"
                >
                  <FiX className="w-5 h-5" />
                </button>
              </div>

              <div className="p-6 space-y-5">
                {reportLoading ? (
                  <div className="py-8 flex flex-col items-center justify-center gap-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5">
                    <FiRefreshCw className="w-5 h-5 text-[#2b83fa] animate-spin" />
                    <p className="text-[13px] font-bold text-[#111111] dark:text-white">Loading transaction history...</p>
                    <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a9]">Fetching the same full history used by the user transaction report.</p>
                  </div>
                ) : reportTransactions.length === 0 ? (
                  <div className="py-8 flex flex-col items-center justify-center gap-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5 text-center">
                    <div className="w-11 h-11 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                      <FiDownload className="w-5 h-5" />
                    </div>
                    <div>
                      <p className="text-[14px] font-bold text-[#111111] dark:text-white">No reportable events yet</p>
                      <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a9] mt-1">PDF download is disabled until this subaccount has credit activity.</p>
                    </div>
                  </div>
                ) : (
                  <>
                    <div className="grid grid-cols-2 gap-3">
                      <div className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                        <p className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a9]">Total Events</p>
                        <p className="text-[22px] font-black text-[#111111] dark:text-white mt-1">{reportTransactions.length.toLocaleString()}</p>
                      </div>
                      <div className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                        <p className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a9]">Selected Period</p>
                        <p className="text-[13px] font-black text-[#111111] dark:text-white mt-1 truncate">{selectedLabel}</p>
                        <p className="text-[11px] font-semibold text-[#6e6e73] dark:text-[#9aa0a9] mt-1">{selectedEventCount.toLocaleString()} events</p>
                      </div>
                    </div>

                    <div className="space-y-2">
                      <label className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a9]">Report Period</label>
                      <div className="relative">
                        <select
                          value={reportSelectedMonth}
                          onChange={(event) => setReportSelectedMonth(event.target.value)}
                          className="w-full appearance-none pl-3.5 pr-10 py-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#d8dce3] dark:border-white/10 text-[13px] font-bold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all cursor-pointer"
                        >
                          <option value="All">All Transactions ({reportTransactions.length} events)</option>
                          {monthOptions.map(month => (
                            <option key={month} value={month}>
                              {getReportMonthLabel(month)} ({getReportMonthCount(reportTransactions, month)})
                            </option>
                          ))}
                        </select>
                        <FiChevronDown className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[#9aa0a9] pointer-events-none" />
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={() => generateMonthlyReport(
                        reportSelectedMonth,
                        reportTransactions,
                        'subaccount',
                        reportSubaccount.location_name || reportSubaccount.location_id,
                        buildSubaccountReportProfile(reportSubaccount, agencyId)
                      )}
                      disabled={!canDownloadReport}
                      className="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#2b83fa] hover:bg-[#1d6bd4] text-white text-[13px] font-bold transition-colors shadow-sm disabled:opacity-45 disabled:cursor-not-allowed disabled:hover:bg-[#2b83fa]"
                    >
                      <FiDownload className="w-4 h-4" />
                      {canDownloadReport ? 'Download PDF' : 'No events to download'}
                    </button>
                  </>
                )}
              </div>
            </div>
          </div>
        );
      })()}

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
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
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
          <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-5 shadow-sm hover:shadow-md hover:-translate-y-[1px] transition-all group">
            <div className="text-[11.5px] font-semibold uppercase tracking-widest text-[#6e6e73] dark:text-[#9aa0a6] mb-2">Total Credits</div>
            <div className="text-3xl font-extrabold tracking-tight leading-none text-purple-600">
              {subaccounts.reduce((acc, s) => acc + (s.credit_balance || s.credits || 0), 0).toLocaleString()}
            </div>
            <div className="text-[11.5px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1.5 line-clamp-1">across all locations</div>
          </div>
        </div>
      )}

      {/* Main Container */}
      {!loading && total === 0 ? (
        <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
          <FiUsers className="w-8 h-8 mx-auto mb-3 opacity-30" />
          <div className="text-[15px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6]">No subaccounts found for your agency.</div>
          <div className="text-[13px] text-[#9ca3af] mt-1 mb-5">
            Connect a GoHighLevel location to start managing it here.
          </div>
          {atSubscriptionLimit ? (
            <button
              onClick={() => showToast(subscriptionBlockedMessage, 'error')}
              className="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-200 dark:bg-[#1c1e21] text-gray-500 dark:text-[#6e6e73] text-[13px] font-bold rounded-xl cursor-not-allowed"
            >
              <FiPlus className="w-4 h-4" />
              Add Subaccount
            </button>
          ) : (
            <a
              href={ADD_SUBACCOUNT_URL}
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center gap-2 px-5 py-2.5 bg-[#111111] dark:bg-white text-white dark:text-[#111111] hover:bg-[#333333] dark:hover:bg-[#e5e5e5] text-[13px] font-bold rounded-xl transition-colors shadow-sm"
            >
              <FiPlus className="w-4 h-4" />
              Add Subaccount
              <FiExternalLink className="w-3.5 h-3.5 opacity-70" />
            </a>
          )}
        </div>
      ) : (
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[#e5e5e5] dark:border-white/5 rounded-2xl shadow-sm overflow-hidden flex flex-col">
          <div className="px-6 py-5 border-b border-[#e5e5e5] dark:border-white/5 flex items-center justify-between flex-wrap gap-4">
            <div>
              <div className="text-[15px] font-bold text-[#111111] dark:text-white tracking-tight">All Subaccounts</div>
              <div className="text-[13px] text-[#6e6e73] dark:text-[#94959b] mt-1">Changes take effect immediately. Credit limit auto-saves on blur.</div>
            </div>
            <div className="flex items-center gap-3 flex-wrap">
              {/* Search */}
              <div className="relative">
                <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-3.5 h-3.5" />
                <input
                  type="text"
                  placeholder="Search subaccounts..."
                  value={searchTerm}
                  onChange={(e) => {
                    setSearchTerm(e.target.value);
                    setCurrentPage(1);
                  }}
                  className="pl-9 pr-4 py-1.5 rounded-lg text-[12.5px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all w-48 sm:w-64"
                />
              </div>
              {/* Status filter as icon button */}
              <div ref={statusFilterMenuRef} className="relative flex-shrink-0">
                <button
                  type="button"
                  onClick={() => setStatusFilterMenuOpen(open => !open)}
                  className={`h-9 w-9 rounded-lg border flex items-center justify-center transition-all ${
                    statusFilter !== 'all'
                      ? 'border-[#2b83fa] bg-[#2b83fa]/10 text-[#2b83fa]'
                      : 'border-[#e0e0e0] dark:border-[#ffffff0a] bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 hover:border-[#2b83fa]/30'
                  }`}
                  title={`Filter by status${statusFilter !== 'all' ? ': ' + (SUBACCOUNT_STATUS_FILTERS.find(f => f.id === statusFilter)?.label || statusFilter) : ''}`}
                  aria-label="Filter by status"
                  aria-expanded={statusFilterMenuOpen}
                >
                  <FiFilter className="w-3.5 h-3.5" />
                </button>
                {statusFilterMenuOpen && (
                  <div className="absolute right-0 top-full z-30 mt-2 w-52 rounded-xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1e2023] shadow-2xl py-1.5 overflow-hidden">
                    <div className="px-3 pb-1.5 pt-0.5">
                      <span className="text-[11px] font-black uppercase tracking-wider text-[#5f6368] dark:text-[#9aa0a6]">Status</span>
                    </div>
                    {SUBACCOUNT_STATUS_FILTERS.map(option => (
                      <button
                        key={option.id}
                        type="button"
                        onClick={() => { setStatusFilter(option.id); setCurrentPage(1); setStatusFilterMenuOpen(false); }}
                        className={`w-full text-left px-3 py-2 text-[12.5px] font-semibold transition-colors flex items-center justify-between ${
                          statusFilter === option.id
                            ? 'text-[#2b83fa] bg-[#2b83fa]/[0.06]'
                            : 'text-[#111111] dark:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/[0.04]'
                        }`}
                      >
                        {option.label}
                        {statusFilter === option.id && (
                          <span className="w-1.5 h-1.5 rounded-full bg-[#2b83fa] flex-shrink-0" />
                        )}
                      </button>
                    ))}
                  </div>
                )}
              </div>
              {/* Add Subaccount */}
              {atSubscriptionLimit ? (
                <button
                  onClick={() => showToast(subscriptionBlockedMessage, 'error')}
                  className="inline-flex items-center gap-2 px-4 py-1.5 bg-gray-200 dark:bg-[#1c1e21] text-gray-500 dark:text-[#6e6e73] text-[12.5px] font-bold rounded-lg cursor-not-allowed whitespace-nowrap"
                  title="Plan limit reached"
                >
                  <FiPlus className="w-3.5 h-3.5" />
                  Add Subaccount
                </button>
              ) : (
                <a
                  href={ADD_SUBACCOUNT_URL}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-2 px-4 py-1.5 bg-[#111111] dark:bg-white text-white dark:text-[#111111] hover:bg-[#333333] dark:hover:bg-[#e5e5e5] text-[12.5px] font-bold rounded-lg transition-colors shadow-sm whitespace-nowrap"
                  title="Connect a new GHL location as a subaccount"
                >
                  <FiPlus className="w-3.5 h-3.5" />
                  Add Subaccount
                  <FiExternalLink className="w-3 h-3 opacity-70" />
                </a>
              )}
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="border-b border-[#0000000a] dark:border-[#ffffff0a]">
                  <th onClick={() => handleSort('location_name')} className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap cursor-pointer hover:text-[#111111] dark:hover:text-white transition-colors">Subaccount <SortIcon field="location_name" /></th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap">Status</th>
                  <th onClick={() => handleSort('agency_name')} className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap cursor-pointer hover:text-[#111111] dark:hover:text-white transition-colors">Agency Name <SortIcon field="agency_name" /></th>
                  <th onClick={() => handleSort('rate_limit')} className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap cursor-pointer hover:text-[#111111] dark:hover:text-white transition-colors">Credit Limit <SortIcon field="rate_limit" /></th>
                  <th onClick={() => handleSort('attempt_count')} className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap cursor-pointer hover:text-[#111111] dark:hover:text-white transition-colors">Sends Used <SortIcon field="attempt_count" /></th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap">Credits</th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap">Free Used</th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap">SMS Active</th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b] whitespace-nowrap text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[rgba(0,0,0,0.05)] dark:divide-[rgba(255,255,255,0.05)]">
                {loading ? (
                  <SkeletonRows count={5} />
                ) : (
                  paginatedSubaccounts.map(sub => {
                    const isAtLimit = sub.attempt_count >= sub.rate_limit;
                    const isNearLimit = !isAtLimit && sub.attempt_count >= sub.rate_limit * 0.8;
                    const isBusy = !!toggleLoading[sub.location_id];
                    const activationBlocked = !sub.toggle_enabled && atSubscriptionLimit;
                    const statusMeta = getSubaccountStatusMeta(sub, installedLocations);

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

                        {/* Status */}
                        <td className="px-6 py-4 align-middle">
                          <SubaccountStatusBadge meta={statusMeta} />
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

                        {/* Credit Balance */}
                        <td className="px-6 py-4 align-middle">
                          <div className="flex flex-col">
                            <span className="text-[14px] font-bold text-[#111111] dark:text-white">
                              {(sub.credit_balance ?? sub.credits ?? 0).toLocaleString()}
                            </span>
                            <span className="text-[10px] text-[#9aa0a6] font-medium uppercase tracking-tight">balance</span>
                          </div>
                        </td>

                        {/* Free Used */}
                        <td className="px-6 py-4 align-middle">
                          <div className={`inline-flex flex-col p-1.5 rounded-xl border ${ (sub.free_usage_count ?? 0) >= (sub.free_credits_total ?? 10) ? 'bg-red-50 dark:bg-red-900/10 border-red-100 dark:border-red-900/20' : 'bg-blue-50 dark:bg-blue-900/10 border-blue-100 dark:border-blue-900/20' }`}>
                              <span className={`text-[12px] font-black text-center ${ (sub.free_usage_count ?? 0) >= (sub.free_credits_total ?? 10) ? 'text-red-600 dark:text-red-400' : 'text-[#2b83fa]' }`}>
                                  {sub.free_usage_count ?? 0} / {sub.free_credits_total ?? 10}
                              </span>
                              <div className="w-10 h-1 bg-gray-200 dark:bg-gray-800 rounded-full mt-1 overflow-hidden">
                                  <div className={`h-full ${(sub.free_usage_count ?? 0) >= (sub.free_credits_total ?? 10) ? 'bg-red-500' : 'bg-blue-500'}`} style={{ width: `${Math.min(((sub.free_usage_count ?? 0) / (sub.free_credits_total ?? 10)) * 100, 100)}%` }}></div>
                              </div>
                          </div>
                        </td>

                        {/* SMS Active Toggle */}
                        <td className="px-6 py-4 align-middle">
                          <div className="flex flex-col items-start gap-1">
                            {(installedLocations.size === 0 || installedLocations.has(sub.location_id) || sub.is_live) ? (
                              <>
                                <div className="flex items-center gap-2.5">
                                  <span className={`text-[11.5px] font-bold ${sub.toggle_enabled ? 'text-[#22c55e]' : 'text-[#9ca3af]'}`}>
                                    {sub.toggle_enabled ? 'ON' : 'OFF'}
                                  </span>
                                  <ToggleSwitch
                                    id={sub.location_id}
                                    checked={!!sub.toggle_enabled}
                                    onChange={enabled => handleToggle(sub.location_id, enabled)}
                                    disabled={isBusy || activationBlocked}
                                  />
                                </div>
                                {activationBlocked ? (
                                  <div className="text-[10.5px] font-bold text-red-500">Plan limit reached</div>
                                ) : (
                                  <div className="text-[10.5px] font-medium text-[#9ca3af]">
                                    {sub.toggle_activation_count ?? 0}/3 activations
                                  </div>
                                )}
                              </>
                            ) : (
                              <div className="flex flex-col items-start gap-1.5">
                                <a
                                  href="https://marketplace.leadconnectorhq.com/oauth/chooselocation?response_type=code&redirect_uri=https%3A%2F%2Fsmspro-api.nolacrm.io%2Foauth%2Fcallback&client_id=69d31f33b3071b25dbcc5656-mnqxvt3&scope=workflows.readonly+conversations%2Fmessage.readonly+conversations.readonly+conversations.write+contacts.readonly+contacts.write+conversations%2Fmessage.write+saas%2Flocation.read+locations.readonly+locations%2Ftags.readonly+locations%2Ftags.write+locations%2FcustomFields.readonly&version_id=69d31f33b3071b25dbcc5656"
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

                        {/* Actions menu (Rightmost) */}
                        <td className="px-6 py-4 align-middle text-right">
                          <button
                            onClick={(event) => openSubActionMenu(sub.location_id, event.currentTarget)}
                            className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-transparent text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white hover:bg-white dark:hover:bg-[#1a1b1e] hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-sm transition-all"
                            title="More actions"
                            aria-label={`Actions for ${sub.location_name || sub.location_id}`}
                          >
                            <FiMoreVertical className="w-4 h-4" />
                          </button>
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
                      className={`w-7 h-7 rounded-lg text-[12px] font-bold flex items-center justify-center transition-all ${currentPage === page
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

      {/* Floating action menu */}
      {actionMenuSubId && (() => {
        const sub = subaccounts.find(s => s.location_id === actionMenuSubId);
        if (!sub) return null;
        return (
          <div
            ref={actionMenuRef}
            style={{ position: 'fixed', top: actionMenuPos.top, right: actionMenuPos.right, zIndex: 9999 }}
            className="w-52 bg-white dark:bg-[#1e2023] border border-[#e5e5e5] dark:border-white/10 rounded-xl shadow-2xl overflow-hidden py-1"
          >
            <button
              type="button"
              onClick={() => { setActionMenuSubId(null); setProfileSubaccount(sub); }}
              className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-[#111111] dark:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/[0.04] transition-colors text-left"
            >
              <FiEye className="w-3.5 h-3.5 text-[#2b83fa]" /> View Profile
            </button>
            <button
              type="button"
              onClick={() => { setActionMenuSubId(null); fetchReportForSubaccount(sub); }}
              className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-[#111111] dark:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/[0.04] transition-colors text-left"
            >
              <FiDownload className="w-3.5 h-3.5 text-emerald-500" /> Download Report
            </button>
          </div>
        );
      })()}

      {profileSubaccount && (
        <AgencySubaccountProfile
          subaccount={profileSubaccount}
          onClose={() => setProfileSubaccount(null)}
          onSaved={(updatedSub) => {
            setSubaccounts(prev => prev.map(s => s.location_id === updatedSub.location_id ? { ...s, ...updatedSub } : s));
            setProfileSubaccount(updatedSub);
          }}
          onToggleActive={async (locationId, enabled) => {
            await handleToggle(locationId, enabled);
          }}
          activationDisabled={!profileSubaccount.toggle_enabled && atSubscriptionLimit}
          activationDisabledMessage={subscriptionBlockedMessage}
        />
      )}
    </AgencyLayout>
  );
};

export default Subaccounts;
