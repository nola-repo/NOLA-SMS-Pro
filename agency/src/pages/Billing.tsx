import { devLog } from '../utils/devLog';
import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import {
  FiCreditCard, FiRefreshCw, FiZap, FiPlus, FiGift,
  FiInbox, FiCheck, FiX, FiChevronDown, FiChevronLeft, FiChevronRight, FiAlertTriangle,
  FiArrowUpRight, FiArrowDownLeft, FiClock, FiSend, FiDownload,
} from 'react-icons/fi';
import { generateMonthlyReport } from '../utils/pdfGenerator';
import { AgencyLayout } from '../components/layout/AgencyLayout.tsx';
import { useAgency } from '../context/AgencyContext.tsx';
import { useToast } from '../hooks/useToast.ts';
import { ToastContainer } from '../components/ui/ToastContainer.tsx';
import { agencyFetch } from '../services/agencyApi.ts';
import { doc, collection, query, where, onSnapshot } from 'firebase/firestore';
import { ensureFirestoreAuth } from '../services/firestoreAuth';
import { db } from '../services/firebaseConfig';

import { GiftCreditsModal } from './billing/GiftCreditsModal';
import { TopUpModal } from './billing/TopUpModal';
import type { AgencyWallet, Transaction, CreditRequest, Subaccount, CreditPackage, CreditRequestFilter } from './billing/types';

// ─── Constants ────────────────────────────────────────────────────────────────
const API_BASE = import.meta.env.VITE_API_BASE || '';

const RECHARGE_AMOUNTS = [100, 250, 500, 1000, 2000, 5000];
const RECHARGE_THRESHOLDS = [25, 50, 100, 200, 500];
const TRANSACTIONS_PER_PAGE = 8;

type CreditRequestFilter = 'all' | 'pending' | 'approved' | 'rejected';

// ─── Types ────────────────────────────────────────────────────────────────────
interface AgencyWallet {
  balance: number;
  auto_recharge_enabled: boolean;
  auto_recharge_amount: number;
  auto_recharge_threshold: number;
  enforce_master_balance_lock: boolean;
  updated_at?: string;
}

interface Transaction {
  id: string;
  type: 'top_up' | 'gift_sent' | 'gift_received' | 'auto_recharge' | 'request_approved' | 'credit_distribution';
  amount: number;
  balance_after: number;
  description: string;
  timestamp: string;
  reference_id?: string;
  transaction_reference_id?: string;
  request_reference_id?: string;
  transfer_reference_id?: string;
  location_name?: string;
}

interface CreditRequest {
  request_id: string;
  reference_id?: string;
  request_reference_id?: string;
  location_id: string;
  location_name: string;
  amount: number;
  note: string;
  status: 'pending' | 'approved' | 'denied' | 'rejected';
  created_at: string;
}

interface Subaccount {
  location_id: string;
  location_name: string;
  credit_balance: number;
}

interface CreditPackage {
  credits: number;
  price: number;
  link: string;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function fmtDate(iso: string) {
  try {
    const d = new Date(iso);
    const now = new Date();
    const diff = now.getTime() - d.getTime();
    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
  } catch { return iso; }
}

function txIcon(type: string) {
  switch (type) {
    case 'top_up': case 'auto_recharge': return { icon: <FiArrowDownLeft />, color: 'text-emerald-500', bg: 'bg-emerald-500/10' };
    case 'gift_sent': case 'credit_distribution': return { icon: <FiGift />, color: 'text-purple-500', bg: 'bg-purple-500/10' };
    case 'gift_received': return { icon: <FiGift />, color: 'text-emerald-500', bg: 'bg-emerald-500/10' };
    case 'request_approved': return { icon: <FiCheck />, color: 'text-blue-500', bg: 'bg-blue-500/10' };
    default: return { icon: <FiArrowUpRight />, color: 'text-amber-500', bg: 'bg-amber-500/10' };
  }
}

// ─── Skeleton ─────────────────────────────────────────────────────────────────
const normalizeNumber = (value: unknown, fallback = 0) => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const normalizeTimestamp = (value: any): string | undefined => {
  if (!value) return undefined;
  if (typeof value === 'string') return value;
  if (typeof value === 'object' && typeof value.seconds === 'number') {
    return new Date(Number(value.seconds) * 1000).toISOString();
  }
  return undefined;
};

const txAmount = (tx: any) => normalizeNumber(tx?.amount, 0);

const txText = (tx: any) =>
  [
    tx?.type,
    tx?.event_type,
    tx?.kind,
    tx?.description,
    tx?.message,
    tx?.note,
  ].filter(Boolean).join(' ').toLowerCase();

const isAutoRechargeTx = (tx: any) => {
  const text = txText(tx);
  return text.includes('auto_recharge') || text.includes('auto recharge') || text.includes('auto-recharge');
};

const isDistributionTx = (tx: any) => {
  const text = txText(tx);
  return [
    'gift_sent',
    'credit_distribution',
    'request_approved',
    'distributed',
    'distribution',
    'gifted',
    'sent to',
    'approved request',
  ].some(marker => text.includes(marker));
};

const isPositiveTx = (tx: any) => {
  if (isDistributionTx(tx)) return false;
  return txAmount(tx) >= 0;
};

const buildTransactionSummary = (rows: any[]) =>
  rows.reduce(
    (summary, tx) => {
      const amount = Math.abs(txAmount(tx));
      if (!amount) return summary;

      if (isAutoRechargeTx(tx)) {
        summary.autoRecharged += amount;
      } else if (isDistributionTx(tx) || txAmount(tx) < 0) {
        summary.distributedOut += amount;
      } else {
        summary.creditsAdded += amount;
      }

      return summary;
    },
    { creditsAdded: 0, distributedOut: 0, autoRecharged: 0 }
  );

const getTransactionMonth = (tx: any) => {
  const value = tx.timestamp || tx.created_at || tx.createdAt || tx.date;
  if (!value) return '';
  const date = new Date(value);
  if (!Number.isNaN(date.getTime())) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }
  const match = String(value).match(/^(\d{4})-(\d{2})/);
  return match ? `${match[1]}-${match[2]}` : '';
};

const getReportMonthOptions = (transactions: any[]) =>
  Array.from(new Set(transactions.map(getTransactionMonth).filter(Boolean))).sort().reverse();

const getReportMonthLabel = (month: string) => {
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
  if (Array.isArray(json?.results)) return json.results;
  return [];
};

const readSubscriptionPlans = (json: any): any[] => {
  if (!json) return [];
  if (Array.isArray(json)) return json;
  if (Array.isArray(json?.plans)) return json.plans;
  if (Array.isArray(json?.subscriptions)) return json.subscriptions;
  if (Array.isArray(json?.data)) return json.data;
  return json.plan || json.status || json.subaccount_limit ? [json] : [];
};

const formatPlanLabel = (value: any) => {
  const text = String(value || 'starter').replace(/_/g, ' ');
  return text.charAt(0).toUpperCase() + text.slice(1);
};

const formatReportDate = (value?: any) => {
  if (!value) return '-';
  if (typeof value === 'object' && typeof value.seconds === 'number') {
    return new Date(value.seconds * 1000).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
  }
  const date = new Date(String(value));
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
};

type AgencyReportContext = {
  name: string;
  companyName?: string;
  companyId?: string;
  balance: number;
};

const normalizeAgencyWalletTransaction = (tx: any, context: AgencyReportContext) => ({
  ...tx,
  timestamp: tx.timestamp || tx.created_at || tx.createdAt || tx.date,
  created_at: tx.created_at || tx.timestamp || tx.createdAt || tx.date,
  type: tx.type || tx.event_type || tx.kind || 'wallet_transaction',
  amount: normalizeNumber(tx.amount, 0),
  balance_after: normalizeNumber(tx.balance_after ?? tx.balance, context.balance),
  description: tx.description || tx.note || tx.memo || tx.reason || 'Agency wallet transaction',
  message: tx.message || tx.description || tx.note || tx.memo || tx.reason || 'Agency wallet transaction',
  location_name: tx.location_name || tx.subaccount_name || context.name,
  agency_name: tx.agency_name || tx.company_name || context.companyName || context.name,
  company_name: tx.company_name || tx.agency_name || context.companyName || context.name,
  company_id: tx.company_id || tx.agency_id || context.companyId,
});

const buildPlanReportTransactions = (plans: any[], context: AgencyReportContext) =>
  plans.map((plan, index) => {
    const nestedSubscription = plan.subscription || {};
    const nestedLimits = plan.limits || {};
    const planName = formatPlanLabel(plan.plan || nestedSubscription.plan || plan.name || plan.id);
    const status = formatPlanLabel(plan.status || nestedSubscription.status || 'active');
    const rawLimit = plan.subaccount_limit ?? plan.max_active_subaccounts ?? nestedSubscription.max_active_subaccounts ?? nestedLimits.max_active_subaccounts ?? plan.limit;
    const limit = rawLimit === -1 ? 'Unlimited' : rawLimit ?? 'Unknown';
    const used = plan.subaccounts_used ?? plan.used ?? nestedSubscription.subaccounts_used;
    const expires = plan.expires_at || plan.renews_at || plan.current_period_end || nestedSubscription.current_period_end;
    const details = [
      `Current plan: ${planName}`,
      `Status: ${status}`,
      `Subaccount limit: ${limit}`,
      used !== undefined ? `Subaccounts used: ${used}` : '',
      expires ? `Renews/expires: ${formatReportDate(expires)}` : '',
    ].filter(Boolean).join('. ');

    return {
      id: `plan-${plan.id || plan.plan || index}`,
      timestamp: plan.updated_at || plan.created_at || plan.started_at || expires || new Date().toISOString(),
      created_at: plan.updated_at || plan.created_at || plan.started_at || expires || new Date().toISOString(),
      type: 'subscription_plan',
      event_type: 'subscription_plan',
      amount: 0,
      balance_after: context.balance,
      description: details,
      message: details,
      location_name: context.name,
      agency_name: context.companyName || context.name,
      company_name: context.companyName || context.name,
      company_id: context.companyId,
    };
  });

const Skeleton = ({ className = '' }) => (
  <div className={`animate-pulse rounded-lg bg-gray-200 dark:bg-white/5 ${className}`} />
);

// Modals (GiftCreditsModal & TopUpModal) imported from ./billing/

// ─── Billing Page ─────────────────────────────────────────────────────────────
export const Billing: React.FC = () => {
  const { agencyId, agencySession } = useAgency();
  const { toasts, showToast, dismissToast } = useToast();
  const mountedRef = useRef(true);

  const [tab, setTab] = useState<'wallet' | 'requests'>('wallet');
  const [txTab, setTxTab] = useState<'summary' | 'detailed'>('summary');

  // Wallet state
  const [wallet, setWallet] = useState<AgencyWallet | null>(null);
  const [walletLoading, setWalletLoading] = useState(true);
  const [walletError, setWalletError] = useState<string | null>(null);

  // Auto-recharge config (local edits)
  const [arEnabled, setArEnabled] = useState(false);
  const [arAmount, setArAmount] = useState(500);
  const [arThreshold, setArThreshold] = useState(100);
  const [arSaving, setArSaving] = useState(false);

  // Master balance lock (optional feature)
  const [masterLock, setMasterLock] = useState(false);
  const [masterLockSaving, setMasterLockSaving] = useState(false);

  // Transactions
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [txLoading, setTxLoading] = useState(true);
  const [txMonth, setTxMonth] = useState(() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  });
  const [availableMonths, setAvailableMonths] = useState<{ val: string, label: string }[]>([]);
  const [txCurrentPage, setTxCurrentPage] = useState(1);

  // Credit requests
  const [requests, setRequests] = useState<CreditRequest[]>([]);
  const [reqLoading, setReqLoading] = useState(true);
  const [pendingCount, setPendingCount] = useState(0);
  const [requestFilter, setRequestFilter] = useState<CreditRequestFilter>('all');
  const [actionLoading, setActionLoading] = useState<Record<string, boolean>>({});

  // Subaccounts (for gift modal)
  const [subaccounts, setSubaccounts] = useState<Subaccount[]>([]);
  const [giftModalOpen, setGiftModalOpen] = useState(false);
  const [topUpModalOpen, setTopUpModalOpen] = useState(false);
  const [reportModalOpen, setReportModalOpen] = useState(false);
  const [reportTransactions, setReportTransactions] = useState<any[]>([]);
  const [reportSelectedMonth, setReportSelectedMonth] = useState('All');
  const [reportLoading, setReportLoading] = useState(false);

  const effectiveAgencyId = agencyId || '';
  const agencyUser = agencySession?.user;
  const balance = wallet?.balance ?? 0;
  const agencyOwnerName = [
    agencyUser?.firstName,
    agencyUser?.lastName,
  ].filter(Boolean).join(' ').trim() || agencyUser?.name || '';
  const agencyReportName = agencyUser?.company_name || agencyUser?.agency_name || agencyUser?.name || effectiveAgencyId || 'Agency Report';
  const agencyReportContext = {
    name: agencyReportName,
    companyName: agencyUser?.company_name || agencyUser?.agency_name || agencyReportName,
    companyId: agencyUser?.company_id || effectiveAgencyId,
    balance,
  };
  const agencyReportProfile = {
    accountName: agencyReportName,
    ownerName: agencyOwnerName,
    email: agencyUser?.email,
    phone: agencyUser?.phone,
    agencyName: agencyUser?.agency_name || agencyUser?.company_name,
    companyName: agencyUser?.company_name || agencyUser?.agency_name,
    companyId: agencyUser?.company_id || effectiveAgencyId,
    reportTitle: 'AGENCY WALLET & PLAN REPORT',
    currentBalance: balance,
  };

  // ── Fetch wallet ────────────────────────────────────────────────────────────
  const transactionSummary = useMemo(() => buildTransactionSummary(transactions), [transactions]);
  const txTotalPages = Math.max(1, Math.ceil(transactions.length / TRANSACTIONS_PER_PAGE));
  const paginatedTransactions = transactions.slice(
    (txCurrentPage - 1) * TRANSACTIONS_PER_PAGE,
    txCurrentPage * TRANSACTIONS_PER_PAGE
  );

  const subaccountNameById = useMemo(() => {
    const names = new Map<string, string>();
    subaccounts.forEach(subaccount => {
      const name = subaccount.location_name?.trim();
      if (subaccount.location_id && name && name.toLowerCase() !== 'unnamed location') {
        names.set(subaccount.location_id, name);
      }
    });
    return names;
  }, [subaccounts]);

  const getRequestLocationName = (request: CreditRequest) => {
    const rawName = request.location_name?.trim();
    if (rawName && rawName.toLowerCase() !== 'unnamed location') return rawName;
    return subaccountNameById.get(request.location_id) || request.location_id || 'Unknown Location';
  };

  const getRequestFilterStatus = (status: CreditRequest['status']): CreditRequestFilter =>
    status === 'denied' || status === 'rejected' ? 'rejected' : status;

  const applyWalletState = useCallback((data: Partial<AgencyWallet> & Record<string, any>) => {
    setWallet(prev => ({
      balance: normalizeNumber(data.balance, prev?.balance ?? 0),
      auto_recharge_enabled: data.auto_recharge_enabled !== undefined ? !!data.auto_recharge_enabled : prev?.auto_recharge_enabled ?? false,
      auto_recharge_amount: normalizeNumber(data.auto_recharge_amount, prev?.auto_recharge_amount ?? 500),
      auto_recharge_threshold: normalizeNumber(data.auto_recharge_threshold, prev?.auto_recharge_threshold ?? 100),
      enforce_master_balance_lock: data.enforce_master_balance_lock !== undefined ? !!data.enforce_master_balance_lock : prev?.enforce_master_balance_lock ?? false,
      updated_at: normalizeTimestamp(data.updated_at) ?? prev?.updated_at,
    }));

    if (data.auto_recharge_enabled !== undefined) setArEnabled(!!data.auto_recharge_enabled);
    if (data.auto_recharge_amount !== undefined) setArAmount(normalizeNumber(data.auto_recharge_amount, 500));
    if (data.auto_recharge_threshold !== undefined) setArThreshold(normalizeNumber(data.auto_recharge_threshold, 100));
    if (data.enforce_master_balance_lock !== undefined) setMasterLock(!!data.enforce_master_balance_lock);
    setWalletError(null);
  }, []);

  const filteredRequests = useMemo(
    () => requestFilter === 'all'
      ? requests
      : requests.filter(request => getRequestFilterStatus(request.status) === requestFilter),
    [requestFilter, requests]
  );

  const fetchWallet = useCallback(async () => {
    setWalletLoading(true);
    setWalletError(null);
    try {
      const res = await agencyFetch(`${API_BASE}/api/billing/agency_wallet.php?agency_id=${encodeURIComponent(effectiveAgencyId)}&refresh=1`, { credentials: 'include' });
      if (!res.ok) throw new Error(`Wallet request failed (${res.status})`);
      const data = await res.json();
      if (!mountedRef.current) return;
      applyWalletState(data);
    } catch {
      if (!mountedRef.current) return;
      setWalletError('Could not refresh wallet balance. Showing the last known balance.');
    } finally {
      if (mountedRef.current) setWalletLoading(false);
    }
  }, [applyWalletState, effectiveAgencyId]);

  // ── Fetch transactions ──────────────────────────────────────────────────────
  const fetchTransactions = useCallback(async () => {
    setTxLoading(true);
    try {
      const monthParam = txMonth === 'All' ? '' : `&month=${txMonth}`;
      const res = await agencyFetch(`${API_BASE}/api/billing/transactions.php?scope=agency&agency_id=${encodeURIComponent(effectiveAgencyId)}${monthParam}`, { credentials: 'include' });
      if (!res.ok) throw new Error(`Transactions request failed (${res.status})`);
      const data = await res.json();
      if (!mountedRef.current) return;
      setTransactions(data.transactions || []);
    } catch {
      if (!mountedRef.current) return;
      setTransactions([]);
    } finally {
      if (mountedRef.current) setTxLoading(false);
    }
  }, [effectiveAgencyId, txMonth]);

  // ── Fetch credit requests ───────────────────────────────────────────────────
  const fetchRequests = useCallback(async () => {
    setReqLoading(true);
    try {
      const res = await agencyFetch(`${API_BASE}/api/billing/credit_requests.php?agency_id=${encodeURIComponent(effectiveAgencyId)}`, { credentials: 'include' });
      if (!res.ok) throw new Error(`Credit requests request failed (${res.status})`);
      const data = await res.json();
      if (!mountedRef.current) return;
      const reqs: CreditRequest[] = data.requests || [];
      setRequests(reqs);
      setPendingCount(reqs.filter(r => r.status === 'pending').length);
    } catch {
      if (!mountedRef.current) return;
      setRequests([]);
    } finally {
      if (mountedRef.current) setReqLoading(false);
    }
  }, [effectiveAgencyId]);

  // ── Fetch subaccounts for gift modal ────────────────────────────────────────
  const fetchSubaccounts = useCallback(async () => {
    try {
      const res = await agencyFetch(`${API_BASE}/api/agency/get_subaccounts.php?agency_id=${encodeURIComponent(effectiveAgencyId)}`, { credentials: 'include' });
      if (!res.ok) throw new Error(`Subaccounts request failed (${res.status})`);
      const data = await res.json();
      if (!mountedRef.current) return;
      setSubaccounts(data.subaccounts || []);
    } catch {
      if (!mountedRef.current) return;
    }
  }, [effectiveAgencyId]);

  // ── Fetch all months that have records ──────────────────────────────────────
  const fetchAvailableMonths = useCallback(async () => {
    try {
      const res = await agencyFetch(`${API_BASE}/api/billing/transactions.php?scope=agency&agency_id=${encodeURIComponent(effectiveAgencyId)}&limit=2000`, { 
        credentials: 'include',
      });
      if (!res.ok) throw new Error(`Transactions request failed (${res.status})`);
      const data = await res.json();
      const txs = data.transactions || [];
      const months = new Set<string>();
      // Use current month as a baseline
      months.add(new Date().toISOString().slice(0, 7));
      
      txs.forEach((tx: any) => {
        const dt = tx.timestamp || tx.created_at;
        if (dt) months.add(dt.slice(0, 7));
      });

      const sorted = Array.from(months)
        .sort((a, b) => b.localeCompare(a))
        .map(m => {
          const [y, mm] = m.split('-');
          const d = new Date(parseInt(y), parseInt(mm) - 1);
          return {
            val: m,
            label: d.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
          };
        });
            setAvailableMonths([{ val: 'All', label: 'All Transactions' }, ...sorted]);
    } catch (e) {
      devLog.error("Failed to fetch available months", e);
      // Fallback
      setAvailableMonths([
        { val: 'All', label: 'All Transactions' },
        { 
          val: new Date().toISOString().slice(0, 7), 
          label: new Date().toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }) 
        }
      ]);
    }
  }, [effectiveAgencyId]);

  useEffect(() => {
    fetchAvailableMonths();
  }, [fetchAvailableMonths]);

  useEffect(() => {
    mountedRef.current = true;
    fetchWallet();
    fetchRequests();
    fetchSubaccounts();
    return () => { mountedRef.current = false; };
  }, [fetchWallet, fetchRequests, fetchSubaccounts]);

  useEffect(() => {
    if (!effectiveAgencyId) return;

    let unsubscribeWallet: (() => void) | null = null;
    let unsubscribeUser: (() => void) | null = null;

    const setupListeners = async () => {
      try {
        await ensureFirestoreAuth();

        unsubscribeWallet = onSnapshot(
          doc(db, 'agency_wallet', effectiveAgencyId),
          (snapshot) => {
            if (snapshot.exists()) {
              const data = snapshot.data();
              const cleanData: Record<string, any> = {};
              Object.keys(data).forEach(k => {
                cleanData[k.trim()] = data[k];
              });

              applyWalletState(cleanData);
              setWalletLoading(false);
              void fetchTransactions();
              void fetchAvailableMonths();
            }
          },
          (err) => {
            devLog.error("Firestore agency wallet listener failed:", err);
            setWalletError('Realtime wallet sync unavailable. Manual refresh still works.');
          }
        );

        const userQuery = query(collection(db, 'agency_users'), where('company_id', '==', effectiveAgencyId));
        unsubscribeUser = onSnapshot(
          userQuery,
          (snapshot) => {
            if (!snapshot.empty) {
              const userData = snapshot.docs[0].data();
              if (userData.balance !== undefined) {
                applyWalletState({
                  balance: userData.balance,
                  updated_at: userData.updated_at,
                });
                setWalletLoading(false);
                void fetchTransactions();
                void fetchAvailableMonths();
              }
            }
          },
          (err) => {
            devLog.error("Firestore agency user wallet listener failed:", err);
            setWalletError('Realtime wallet fallback unavailable. Manual refresh still works.');
          }
        );
      } catch (err) {
        devLog.error("Firestore agency wallet listener setup failed:", err);
        setWalletError('Realtime wallet sync unavailable. Manual refresh still works.');
      }
    };

    setupListeners();

    return () => {
      if (unsubscribeWallet) unsubscribeWallet();
      if (unsubscribeUser) unsubscribeUser();
    };
  }, [applyWalletState, effectiveAgencyId, fetchAvailableMonths, fetchTransactions]);

  useEffect(() => { fetchTransactions(); }, [fetchTransactions]);
  useEffect(() => { setTxCurrentPage(1); }, [txMonth, transactions.length]);

  // ── Save auto-recharge ──────────────────────────────────────────────────────
  const fetchReportForAgency = async () => {
    if (!effectiveAgencyId) {
      showToast('This agency does not have an ID for reporting.', 'error');
      return;
    }

    setReportModalOpen(true);
    setReportLoading(true);
    setReportSelectedMonth(getCurrentReportMonth());
    setReportTransactions([]);

    try {
      const [transactionsResult, subscriptionResult] = await Promise.allSettled([
        agencyFetch(`${API_BASE}/api/billing/transactions.php?scope=agency&agency_id=${encodeURIComponent(effectiveAgencyId)}&limit=5000`, { credentials: 'include' }),
        agencyFetch(`${API_BASE}/api/billing/subscription.php?agency_id=${encodeURIComponent(effectiveAgencyId)}`, { credentials: 'include' }),
      ]);

      const reportRows: any[] = [];
      let loadError = '';

      if (transactionsResult.status === 'fulfilled' && transactionsResult.value.ok) {
        const json = await transactionsResult.value.json().catch(() => null);
        reportRows.push(...readReportTransactions(json).map(tx => normalizeAgencyWalletTransaction(tx, agencyReportContext)));
      } else {
        loadError = 'Wallet transactions could not be loaded.';
      }

      if (subscriptionResult.status === 'fulfilled' && subscriptionResult.value.ok) {
        const json = await subscriptionResult.value.json().catch(() => null);
        reportRows.push(...buildPlanReportTransactions(readSubscriptionPlans(json), agencyReportContext));
      } else {
        loadError = loadError || 'Subscription plan could not be loaded.';
      }

      setReportTransactions(reportRows);
      setReportSelectedMonth(getReportMonthSelection(reportRows));
      if (loadError && reportRows.length === 0) {
        showToast(loadError, 'error');
      } else if (loadError) {
        showToast(`${loadError} Showing available report data.`, 'info');
      }
    } catch {
      setReportTransactions([]);
      showToast('Failed to load agency report data.', 'error');
    } finally {
      setReportLoading(false);
    }
  };

  const saveAutoRecharge = async () => {
    setArSaving(true);
    try {
      const res = await agencyFetch(`${API_BASE}/api/billing/agency_wallet.php`, {
        method: 'POST',
        credentials: 'include',
        body: JSON.stringify({ action: 'set_auto_recharge', agency_id: effectiveAgencyId, enabled: arEnabled, amount: arAmount, threshold: arThreshold }),
      });
      const data = await res.json();
      if (data.success) showToast('Auto-recharge settings saved.', 'success');
      else throw new Error(data.error);
    } catch {
      showToast('Failed to save auto-recharge settings.', 'error');
    } finally {
      setArSaving(false);
    }
  };

  // ── Save master balance lock ────────────────────────────────────────────────
  const saveMasterLock = async (val: boolean) => {
    setMasterLock(val);
    setMasterLockSaving(true);
    try {
      const res = await agencyFetch(`${API_BASE}/api/billing/agency_wallet.php`, {
        method: 'POST',
        credentials: 'include',
        body: JSON.stringify({ action: 'set_master_lock', agency_id: effectiveAgencyId, enabled: val }),
      });
      const data = await res.json();
      if (data.success) showToast(val ? 'Master balance lock enabled.' : 'Master balance lock disabled.', 'info');
      else throw new Error(data.error);
    } catch {
      showToast('Failed to update master balance lock.', 'error');
      setMasterLock(v => !v); // revert
    } finally {
      setMasterLockSaving(false);
    }
  };

  // ── Top up modal trigger ────────────────────────────────────────────────────
  const handleTopUp = () => {
    setTopUpModalOpen(true);
  };

  // ── Approve / deny request ──────────────────────────────────────────────────
  const handleRequestAction = async (requestId: string, action: 'approve' | 'deny') => {
    setActionLoading(prev => ({ ...prev, [requestId]: true }));
    try {
      const res = await agencyFetch(`${API_BASE}/api/billing/credit_requests.php`, {
        method: 'POST',
        credentials: 'include',
        body: JSON.stringify({ action, request_id: requestId, agency_id: effectiveAgencyId }),
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Action failed');
      showToast(action === 'approve' ? 'Credits sent to subaccount.' : 'Request denied.', action === 'approve' ? 'success' : 'info');
      fetchRequests();
      if (action === 'approve') {
        fetchWallet();
        fetchSubaccounts();
        fetchTransactions();
        fetchAvailableMonths();
      }
    } catch (e: any) {
      showToast(`Failed: ${e.message}`, 'error');
    } finally {
      setActionLoading(prev => ({ ...prev, [requestId]: false }));
    }
  };

  const isLow = balance < 100;

  return (
    <AgencyLayout title="Credits & Billing" subtitle="Agency funding wallet — distribute credits to subaccounts and manage top-ups">
      <ToastContainer toasts={toasts} onDismiss={dismissToast} />

      {topUpModalOpen && (
        <TopUpModal 
          agencyId={effectiveAgencyId}
          onClose={() => setTopUpModalOpen(false)}
          onSuccess={() => {
             showToast('Top up flow completed. Verifying balance...', 'info');
             fetchWallet();
             fetchTransactions();
             fetchAvailableMonths();
          }}
        />
      )}

      {giftModalOpen && (
        <GiftCreditsModal
          subaccounts={subaccounts}
          agencyId={effectiveAgencyId}
          agencyBalance={balance}
          onClose={() => setGiftModalOpen(false)}
          onSuccess={(locId, amt) => {
            showToast(`Gifted ${amt.toLocaleString()} credits successfully.`, 'success');
            setGiftModalOpen(false);
            fetchWallet();
            fetchSubaccounts();
            fetchTransactions();
            fetchAvailableMonths();
          }}
        />
      )}

      {/* ── Page Tabs ─────────────────────────────────────────────────────────── */}
      {reportModalOpen && (() => {
        const monthOptions = getReportMonthOptions(reportTransactions);
        const selectedEventCount = getReportMonthCount(reportTransactions, reportSelectedMonth);
        const canDownloadReport = !reportLoading && selectedEventCount > 0;
        const selectedLabel = reportSelectedMonth === 'All' ? 'All Wallet & Plan Events' : getReportMonthLabel(reportSelectedMonth);

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
                      {agencyReportName}
                    </div>
                  </div>
                </div>
                <button
                  onClick={() => setReportModalOpen(false)}
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
                    <p className="text-[13px] font-bold text-[#111111] dark:text-white">Loading agency report data...</p>
                    <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a9]">Fetching wallet transactions and current plan details.</p>
                  </div>
                ) : reportTransactions.length === 0 ? (
                  <div className="py-8 flex flex-col items-center justify-center gap-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5 text-center">
                    <div className="w-11 h-11 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                      <FiDownload className="w-5 h-5" />
                    </div>
                    <div>
                      <p className="text-[14px] font-bold text-[#111111] dark:text-white">No reportable agency activity</p>
                      <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a9] mt-1">PDF download is disabled until wallet transactions or plan data are available.</p>
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
                          <option value="All">All Wallet & Plan Events ({reportTransactions.length} events)</option>
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
                      onClick={() => generateMonthlyReport(reportSelectedMonth, reportTransactions, 'agency', agencyReportName, agencyReportProfile)}
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

      <div className="flex items-center gap-1 mb-6 border-b border-[#e5e5e5] dark:border-white/5">
        {[
          { id: 'wallet', label: 'Wallet & Transactions' },
          { id: 'requests', label: 'Credit Requests', badge: pendingCount },
        ].map(t => (
          <button
            key={t.id}
            onClick={() => setTab(t.id as any)}
            className={`relative flex items-center gap-2 px-4 py-3 text-[13.5px] font-semibold transition-all border-b-2 -mb-[2px] ${tab === t.id
              ? 'border-[#2b83fa] text-[#2b83fa]'
              : 'border-transparent text-[#6e6e73] dark:text-[#9aa0a9] hover:text-[#111111] dark:hover:text-white'}`}
          >
            {t.label}
            {!!t.badge && (
              <span className="flex items-center justify-center w-5 h-5 rounded-full bg-red-500 text-white text-[10px] font-bold">{t.badge}</span>
            )}
          </button>
        ))}
      </div>

      {/* ══════════════════════ WALLET TAB ══════════════════════════════════════ */}
      {tab === 'wallet' && (
        <div className="space-y-5">
          {/* Low-balance informational nudge (non-blocking by default) */}
          {!walletLoading && balance < 100 && !masterLock && (
            <div className="flex items-center gap-3 p-4 rounded-xl bg-amber-500/5 border border-amber-500/20 text-amber-600 dark:text-amber-400 animate-in fade-in slide-in-from-top-2 duration-300">
              <FiAlertTriangle className="w-5 h-5 flex-shrink-0" />
              <div>
                <div className="text-[13px] font-bold">Agency wallet is running low</div>
                <div className="text-[12px] opacity-80 mt-0.5">Subaccounts can still send SMS. Top up or distribute credits to stay ahead.</div>
              </div>
              <button onClick={handleTopUp} className="ml-auto flex-shrink-0 px-4 py-2 rounded-lg bg-amber-500 text-white text-[12.5px] font-bold hover:bg-amber-600 transition-colors shadow-sm">
                Add Balance
              </button>
            </div>
          )}
          {/* Wallet Card */}
          <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-6 shadow-sm">
            <div className="flex items-start justify-between mb-4">
              <div>
                <div className="text-[12px] font-semibold text-[#6e6e73] dark:text-[#9aa0a9] uppercase tracking-wider mb-2">Agency Funding Wallet</div>
                {walletLoading && !wallet ? (
                  <Skeleton className="h-10 w-36" />
                ) : (
                  <div className="flex items-center gap-3">
                    <span className={`text-[42px] font-black leading-none tracking-tight ${isLow ? 'text-red-500' : 'text-[#111111] dark:text-white'}`}>
                      {balance.toLocaleString()}
                    </span>
                    <span className="text-[14px] font-semibold text-[#6e6e73] dark:text-[#9aa0a9] mt-3">credits</span>
                    {isLow && (
                      <span className="flex items-center gap-1 px-2.5 py-1 rounded-full bg-red-500/10 text-red-500 text-[11px] font-bold mt-2">
                        <FiAlertTriangle className="w-3 h-3" /> Low
                      </span>
                    )}
                  </div>
                )}
                {walletError && (
                  <div className="mt-3 flex items-center gap-2 text-[12px] font-semibold text-amber-600 dark:text-amber-400">
                    <FiAlertTriangle className="w-3.5 h-3.5 flex-shrink-0" />
                    {walletError}
                  </div>
                )}
              </div>
              <div className="flex items-center gap-2">
                <button onClick={fetchWallet} title="Refresh" className="p-2 rounded-xl bg-[#f7f7f7] dark:bg-white/5 border border-[#e0e0e0] dark:border-white/5 text-[#6e6e73] hover:text-[#111111] dark:hover:text-white transition-all">
                  <FiRefreshCw className={`w-4 h-4 ${walletLoading ? 'animate-spin' : ''}`} />
                </button>
                <button onClick={handleTopUp}
                  className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-[#2b83fa] hover:bg-[#1d6bd4] text-white text-[13px] font-bold shadow-md shadow-[#2b83fa]/25 transition-all hover:shadow-[#2b83fa]/40">
                  <FiPlus className="w-4 h-4" /> Add Balance
                </button>
              </div>
            </div>

            {/* Auto-recharge row */}
            <div className="flex flex-wrap items-center gap-3 pt-4 border-t border-[#f0f0f0] dark:border-white/5">
              <button
                onClick={() => setArEnabled(v => !v)}
                className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0 focus:outline-none ${arEnabled ? 'bg-[#2b83fa]' : 'bg-gray-200 dark:bg-[#3a3b3f]'}`}
              >
                <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform ${arEnabled ? 'translate-x-[18px]' : 'translate-x-1'}`} />
              </button>
              <span className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a9] font-medium">Auto recharge with</span>
              <div className="relative">
                <select
                  value={arAmount}
                  onChange={e => setArAmount(Number(e.target.value))}
                  disabled={!arEnabled}
                  className="pl-3 pr-7 py-1.5 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] text-[13px] font-bold text-[#111111] dark:text-white appearance-none focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 disabled:opacity-50"
                >
                  {RECHARGE_AMOUNTS.map(v => <option key={v} value={v}>{v.toLocaleString()} credits</option>)}
                </select>
                <FiChevronDown className="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
              </div>
              <span className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a9] font-medium">when balance is lower than</span>
              <div className="relative">
                <select
                  value={arThreshold}
                  onChange={e => setArThreshold(Number(e.target.value))}
                  disabled={!arEnabled}
                  className="pl-3 pr-7 py-1.5 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] text-[13px] font-bold text-[#111111] dark:text-white appearance-none focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 disabled:opacity-50"
                >
                  {RECHARGE_THRESHOLDS.map(v => <option key={v} value={v}>{v.toLocaleString()} credits</option>)}
                </select>
                <FiChevronDown className="absolute right-2 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
              </div>
              <button onClick={saveAutoRecharge} disabled={arSaving}
                className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] text-[12.5px] font-semibold text-[#6e6e73] dark:text-[#9aa0a9] hover:text-[#111111] dark:hover:text-white transition-colors disabled:opacity-50">
                {arSaving ? <FiRefreshCw className="w-3.5 h-3.5 animate-spin" /> : <FiCheck className="w-3.5 h-3.5" />}
                Save
              </button>
            </div>
          </div>

          {/* ── Gift Credits Card ──────────────────────────────────────────────── */}
          <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-5 shadow-sm">
            <div className="flex items-center justify-between gap-4">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-500 flex-shrink-0">
                  <FiGift className="w-5 h-5" />
                </div>
                <div>
                  <div className="text-[13.5px] font-bold text-[#111111] dark:text-white">Gift Credits to Subaccount</div>
                  <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a9] mt-0.5">Transfer credits directly from your agency wallet to any subaccount.</div>
                </div>
              </div>
              <button onClick={() => setGiftModalOpen(true)}
                className="flex-shrink-0 flex items-center gap-2 px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-[13px] font-bold shadow-md shadow-purple-500/20 hover:shadow-purple-500/40 transition-all">
                <FiGift className="w-4 h-4" /> Gift Credits
              </button>
            </div>
          </div>

          {/* Master balance lock (optional) */}
          {!walletLoading && (
            <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-5 shadow-sm">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <div className="text-[13.5px] font-bold text-[#111111] dark:text-white">Master Balance Lock</div>
                  <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a9] mt-1">
                    When enabled, sending is blocked for all subaccounts when this agency wallet reaches 0. Off by default — subaccounts use only their own balances.
                  </div>
                </div>
                <button
                  onClick={() => saveMasterLock(!masterLock)}
                  disabled={masterLockSaving}
                  className={`relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors focus:outline-none mt-0.5 disabled:opacity-50 ${
                    masterLock ? 'bg-red-500' : 'bg-gray-200 dark:bg-[#3a3b3f]'
                  }`}
                >
                  <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${masterLock ? 'translate-x-6' : 'translate-x-1'}`} />
                </button>
              </div>
              {masterLock && balance === 0 && (
                <div className="mt-3 flex items-center gap-2 text-[12px] text-red-500 font-semibold">
                  <FiAlertTriangle className="w-3.5 h-3.5" />
                  Master lock is ON and wallet is empty — all subaccounts are currently blocked from sending.
                </div>
              )}
            </div>
          )}

          {/* Wallet Transactions */}
          <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-[#e5e5e5] dark:border-white/5 flex items-center justify-between flex-wrap gap-3">
              <div className="text-[15px] font-bold text-[#111111] dark:text-white">Wallet Transactions</div>
              <div className="flex items-center gap-3">
                {/* Month selector */}
                <div className="relative">
                  <select value={txMonth} onChange={e => setTxMonth(e.target.value)}
                    className="pl-3 pr-8 py-1.5 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] text-[12.5px] font-semibold text-[#111111] dark:text-white appearance-none focus:outline-none">
                    {availableMonths.map(m => <option key={m.val} value={m.val}>{m.label}</option>)}
                  </select>
                  <FiChevronDown className="absolute right-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
                </div>
                <button onClick={fetchTransactions} className="p-1.5 rounded-lg text-[#6e6e73] hover:text-[#111111] dark:hover:text-white hover:bg-black/5 dark:hover:bg-white/5 transition-all">
                  <FiRefreshCw className={`w-4 h-4 ${txLoading ? 'animate-spin' : ''}`} />
                </button>
                <button
                  onClick={fetchReportForAgency}
                  disabled={reportLoading}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-[#ffffff] border border-transparent hover:bg-[#f3f4f6] dark:hover:bg-[#1f2023] disabled:opacity-50 transition-all ml-2"
                >
                  <FiDownload className="w-3.5 h-3.5" /> {reportLoading ? 'Loading...' : 'Download Report'}
                </button>
              </div>
            </div>

            {/* Sub-tabs */}
            <div className="flex border-b border-[#e5e5e5] dark:border-white/5 px-6">
              {[{ id: 'summary', label: 'Summary' }, { id: 'detailed', label: 'Detailed Transactions' }].map(t => (
                <button key={t.id} onClick={() => setTxTab(t.id as any)}
                  className={`px-1 py-3 mr-6 text-[13px] font-semibold border-b-2 -mb-[2px] transition-colors ${txTab === t.id ? 'border-[#2b83fa] text-[#2b83fa]' : 'border-transparent text-[#6e6e73] dark:text-[#9aa0a9] hover:text-[#111111] dark:hover:text-white'}`}>
                  {t.label}
                </button>
              ))}
            </div>

            <div className="p-6">
              {txLoading ? (
                <div className="space-y-3">{[1, 2, 3, 4].map(i => (
                  <div key={i} className="flex items-center gap-3">
                    <Skeleton className="w-9 h-9 rounded-xl flex-shrink-0" />
                    <div className="flex-1 space-y-1.5"><Skeleton className="h-3 w-3/4" /><Skeleton className="h-2.5 w-1/3" /></div>
                    <Skeleton className="h-3 w-16 flex-shrink-0" />
                  </div>
                ))}</div>
              ) : transactions.length === 0 ? (
                <div className="flex flex-col items-center py-10 gap-2 text-center">
                  <div className="w-12 h-12 rounded-xl bg-[#2b83fa]/10 flex items-center justify-center"><FiCreditCard className="w-6 h-6 text-[#2b83fa]/50" /></div>
                  <div className="text-[14px] font-semibold text-[#6e6e73] dark:text-[#9aa0a9]">No transactions this month</div>
                  <div className="text-[12px] text-[#9aa0a6]">Activity will appear here once credits are added or used.</div>
                </div>
              ) : txTab === 'summary' ? (
                /* Summary view */
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
                  {[
                    { label: 'Credits Added', value: transactionSummary.creditsAdded, color: 'text-emerald-500', bg: 'bg-emerald-500/10', icon: <FiArrowDownLeft /> },
                    { label: 'Distributed Out', value: transactionSummary.distributedOut, color: 'text-purple-500', bg: 'bg-purple-500/10', icon: <FiGift /> },
                    { label: 'Auto-Recharged', value: transactionSummary.autoRecharged, color: 'text-blue-500', bg: 'bg-blue-500/10', icon: <FiRefreshCw /> },
                  ].map(s => (
                    <div key={s.label} className="bg-[#f7f7f7] dark:bg-white/[0.03] rounded-xl p-4">
                      <div className={`w-8 h-8 rounded-lg flex items-center justify-center mb-2.5 ${s.bg} ${s.color}`}>{s.icon}</div>
                      <div className={`text-[24px] font-black ${s.color}`}>{s.value.toLocaleString()}</div>
                      <div className="text-[11px] text-[#9aa0a6] mt-0.5">{s.label}</div>
                    </div>
                  ))}
                </div>
              ) : (
                /* Detailed list */
                <div className="space-y-0">
                  {paginatedTransactions.map(tx => {
                    const { icon, color, bg } = txIcon(tx.type);
                    const isPos = isPositiveTx(tx);
                    const amount = Math.abs(txAmount(tx));
                    return (
                      <div key={tx.id} className="flex items-center gap-3 py-3 border-b border-[#f0f0f0] dark:border-white/5 last:border-0 hover:bg-black/[0.015] dark:hover:bg-white/[0.015] px-2 rounded-xl transition-colors">
                        <div className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 text-[14px] ${bg} ${color}`}>{icon}</div>
                        <div className="flex-1 min-w-0">
                          <div className="text-[13px] font-medium text-[#111111] dark:text-[#ececf1] truncate">{tx.description}</div>
                          <div className="text-[11px] text-[#9aa0a6]">{fmtDate(tx.timestamp)}</div>
                          {(tx.reference_id || tx.transaction_reference_id) && (
                            <div className="text-[10px] font-mono text-[#9aa0a6] truncate">Ref: {tx.reference_id || tx.transaction_reference_id}</div>
                          )}
                        </div>
                        <div className="flex flex-col items-end flex-shrink-0">
                          <span className={`text-[13px] font-bold ${isPos ? 'text-emerald-500' : 'text-red-500'}`}>
                            {isPos ? '+' : '-'}{amount.toLocaleString()}
                          </span>
                          <span className="text-[10px] text-[#9aa0a6]">bal: {tx.balance_after?.toLocaleString()}</span>
                        </div>
                      </div>
                    );
                  })}
                  {transactions.length > TRANSACTIONS_PER_PAGE && (
                    <div className="flex items-center justify-between gap-3 px-2 pt-4 mt-2 border-t border-[#f0f0f0] dark:border-white/5 flex-wrap">
                      <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a9]">
                        Showing <span className="font-bold text-[#111111] dark:text-white">{(txCurrentPage - 1) * TRANSACTIONS_PER_PAGE + 1}</span> to <span className="font-bold text-[#111111] dark:text-white">{Math.min(txCurrentPage * TRANSACTIONS_PER_PAGE, transactions.length)}</span> of <span className="font-bold text-[#111111] dark:text-white">{transactions.length}</span> transactions
                      </div>
                      <div className="flex items-center gap-1.5">
                        <button
                          type="button"
                          onClick={() => setTxCurrentPage(page => Math.max(1, page - 1))}
                          disabled={txCurrentPage === 1}
                          className="h-8 w-8 rounded-lg border border-[#d8dce3] dark:border-white/10 bg-white dark:bg-[#0d0e10] text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white hover:bg-[#f3f4f6] dark:hover:bg-white/5 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center transition-colors"
                          aria-label="Previous transactions page"
                        >
                          <FiChevronLeft className="w-4 h-4" />
                        </button>
                        {Array.from({ length: txTotalPages }, (_, index) => index + 1)
                          .slice(Math.max(0, txCurrentPage - 3), Math.max(0, txCurrentPage - 3) + 5)
                          .map(page => (
                            <button
                              key={page}
                              type="button"
                              onClick={() => setTxCurrentPage(page)}
                              className={`h-8 w-8 rounded-lg text-[12px] font-bold flex items-center justify-center transition-colors ${
                                txCurrentPage === page
                                  ? 'bg-[#2b83fa] text-white'
                                  : 'border border-[#d8dce3] dark:border-white/10 bg-white dark:bg-[#0d0e10] text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white hover:bg-[#f3f4f6] dark:hover:bg-white/5'
                              }`}
                            >
                              {page}
                            </button>
                          ))}
                        <button
                          type="button"
                          onClick={() => setTxCurrentPage(page => Math.min(txTotalPages, page + 1))}
                          disabled={txCurrentPage === txTotalPages}
                          className="h-8 w-8 rounded-lg border border-[#d8dce3] dark:border-white/10 bg-white dark:bg-[#0d0e10] text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white hover:bg-[#f3f4f6] dark:hover:bg-white/5 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center transition-colors"
                          aria-label="Next transactions page"
                        >
                          <FiChevronRight className="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* ══════════════════════ REQUESTS TAB ════════════════════════════════════ */}
      {tab === 'requests' && (
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl shadow-sm overflow-hidden">
          <div className="px-6 py-4 border-b border-[#e5e5e5] dark:border-white/5 flex items-center justify-between gap-3">
            <div>
              <div className="text-[15px] font-bold text-[#111111] dark:text-white">Credit Requests</div>
              <div className="text-[12.5px] text-[#6e6e73] dark:text-[#9aa0a9] mt-0.5">Subaccounts requesting credit top-ups from your agency wallet</div>
            </div>
            <button onClick={fetchRequests} className="p-1.5 rounded-lg text-[#6e6e73] hover:text-[#111111] dark:hover:text-white hover:bg-black/5 dark:hover:bg-white/5 transition-all">
              <FiRefreshCw className={`w-4 h-4 ${reqLoading ? 'animate-spin' : ''}`} />
            </button>
          </div>

          {/* Filter tabs */}
          <div className="flex gap-1 px-6 py-3 border-b border-[#e5e5e5] dark:border-white/5">
            {[
              { id: 'all', label: 'All' },
              { id: 'pending', label: 'Pending' },
              { id: 'approved', label: 'Approved' },
              { id: 'rejected', label: 'Rejected' },
            ].map(({ id, label }) => {
              const filterId = id as CreditRequestFilter;
              const isActive = requestFilter === filterId;
              const count = filterId === 'all'
                ? requests.length
                : requests.filter(r => getRequestFilterStatus(r.status) === filterId).length;
              return (
                <button
                  key={id}
                  onClick={() => setRequestFilter(filterId)}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-semibold transition-colors ${
                    isActive
                      ? 'bg-[#111111] text-white dark:bg-white dark:text-[#111111]'
                      : 'bg-[#f0f2f8] dark:bg-[#1c1e21] text-[#6e6e73] dark:text-[#9aa0a9] hover:text-[#111111] dark:hover:text-white'
                  }`}
                >
                  {label} <span className="text-[10px] font-bold">{count}</span>
                </button>
              );
            })}
          </div>

          <div className="p-6">
            {reqLoading ? (
              <div className="space-y-4">{[1, 2, 3].map(i => (
                <div key={i} className="flex items-center gap-4 p-4 rounded-xl border border-[#e5e5e5] dark:border-white/5">
                  <Skeleton className="w-10 h-10 rounded-xl flex-shrink-0" />
                  <div className="flex-1 space-y-2"><Skeleton className="h-3.5 w-48" /><Skeleton className="h-2.5 w-32" /></div>
                  <Skeleton className="h-8 w-20 rounded-lg" />
                  <Skeleton className="h-8 w-20 rounded-lg" />
                </div>
              ))}</div>
            ) : requests.length === 0 ? (
              <div className="flex flex-col items-center py-12 gap-3 text-center">
                <div className="w-14 h-14 rounded-2xl bg-[#2b83fa]/10 flex items-center justify-center"><FiInbox className="w-7 h-7 text-[#2b83fa]/50" /></div>
                <div className="text-[15px] font-semibold text-[#6e6e73] dark:text-[#9aa0a9]">No credit requests yet</div>
                <div className="text-[12.5px] text-[#9aa0a6] max-w-xs">When subaccounts request credits, they'll appear here for you to approve or deny.</div>
              </div>
            ) : filteredRequests.length === 0 ? (
              <div className="flex flex-col items-center py-12 gap-3 text-center">
                <div className="w-14 h-14 rounded-2xl bg-[#2b83fa]/10 flex items-center justify-center"><FiInbox className="w-7 h-7 text-[#2b83fa]/50" /></div>
                <div className="text-[15px] font-semibold text-[#6e6e73] dark:text-[#9aa0a9]">No {requestFilter} requests</div>
                <div className="text-[12.5px] text-[#9aa0a6] max-w-xs">Choose another status to review the rest of your credit requests.</div>
              </div>
            ) : (
              <div className="space-y-3">
                {filteredRequests.map(req => {
                  const isPending = req.status === 'pending';
                  const isRejected = getRequestFilterStatus(req.status) === 'rejected';
                  const isLoading = !!actionLoading[req.request_id];
                  const requestLocationName = getRequestLocationName(req);
                  return (
                    <div key={req.request_id} className="flex items-center gap-4 p-4 rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7]/50 dark:bg-white/[0.02] hover:bg-white dark:hover:bg-white/[0.04] transition-colors">
                      <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${req.status === 'approved' ? 'bg-emerald-500/10 text-emerald-500' : isRejected ? 'bg-red-500/10 text-red-500' : 'bg-[#2b83fa]/10 text-[#2b83fa]'}`}>
                        {req.status === 'approved' ? <FiCheck className="w-5 h-5" /> : isRejected ? <FiX className="w-5 h-5" /> : <FiClock className="w-5 h-5" />}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="text-[13.5px] font-semibold text-[#111111] dark:text-white">{requestLocationName}</div>
                        <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a9]">
                          Requesting <strong className="text-[#2b83fa]">{req.amount.toLocaleString()} credits</strong>
                          {req.note && <> · <span className="italic">"{req.note}"</span></>}
                        </div>
                        <div className="text-[11px] text-[#9aa0a6] mt-0.5">{fmtDate(req.created_at)}</div>
                        {(req.reference_id || req.request_reference_id) && (
                          <div className="text-[10px] font-mono text-[#9aa0a6] mt-0.5 truncate">Ref: {req.reference_id || req.request_reference_id}</div>
                        )}
                      </div>
                      {isPending ? (
                        <div className="flex items-center gap-2 flex-shrink-0">
                          <button
                            onClick={() => handleRequestAction(req.request_id, 'deny')}
                            disabled={isLoading}
                            className="px-3.5 py-1.5 rounded-lg text-[12.5px] font-bold text-[#6e6e73] dark:text-[#9aa0a9] bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] hover:bg-red-50 dark:hover:bg-red-900/10 hover:text-red-500 transition-all disabled:opacity-50"
                          >
                            Deny
                          </button>
                          <button
                            onClick={() => handleRequestAction(req.request_id, 'approve')}
                            disabled={isLoading || req.amount > balance}
                            className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-[12.5px] font-bold text-white bg-emerald-500 hover:bg-emerald-600 transition-all shadow-sm shadow-emerald-500/20 disabled:opacity-50 disabled:cursor-not-allowed"
                          >
                            {isLoading ? <FiRefreshCw className="w-3.5 h-3.5 animate-spin" /> : <FiCheck className="w-3.5 h-3.5" />}
                            Approve
                          </button>
                        </div>
                      ) : (
                        <span className={`px-3 py-1 rounded-full text-[11.5px] font-bold flex-shrink-0 ${req.status === 'approved' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-red-500/10 text-red-500'}`}>
                          {req.status === 'approved' ? 'Approved' : 'Rejected'}
                        </span>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </div>
      )}
    </AgencyLayout>
  );
};
