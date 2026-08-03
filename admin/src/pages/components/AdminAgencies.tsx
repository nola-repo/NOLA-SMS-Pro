import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { FiAlertCircle, FiEye, FiX, FiRefreshCw, FiPlus, FiMinus, FiChevronDown, FiChevronLeft, FiChevronRight, FiSearch, FiMoreVertical, FiBriefcase, FiDownload } from 'react-icons/fi';
import { useToast } from '../../hooks/useToast';
import { ToastContainer } from '../../components/ui/ToastContainer';
import { generateMonthlyReport } from '../../utils/pdfGenerator';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';

const ADMIN_API = '/api/admin_sender_requests.php';
const ADMIN_AGENCY_USERS_API = '/api/admin_list_agency_users.php';
const AGENCY_USER_ENDPOINTS = [
    ADMIN_AGENCY_USERS_API,
    `${ADMIN_API}?action=agency_users`,
    `${ADMIN_API}?action=agencies`,
];
const POLL_INTERVAL = 15000; // 15 seconds real-time sync

type AgencyAccount = {
    id: string;
    name?: string;
    full_name?: string;
    firstName?: string;
    lastName?: string;
    email?: string;
    phone?: string;
    role?: string;
    active?: boolean;
    company_id?: string;
    company_name?: string;
    agency_name?: string;
    balance?: number;
    credit_balance?: number;
    credits?: number;
    created_at?: string;
    createdAt?: string;
    updated_at?: string;
    source?: string;
};

const normalizeNumber = (value: unknown, fallback = 0) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
};

const compact = (...parts: Array<string | null | undefined>) =>
    parts.map(part => part?.trim()).filter(Boolean).join(' ').trim();

const normalizeAgencyAccount = (item: any): AgencyAccount => {
    const raw = item?.data ? { id: item.id, ...item.data } : item || {};
    const firstName = raw.firstName || raw.first_name || '';
    const lastName = raw.lastName || raw.last_name || '';
    const personName = raw.name || raw.full_name || compact(firstName, lastName);
    const companyName = raw.company_name || raw.companyName || raw.agency_name || raw.agencyName || '';
    const companyId = raw.company_id || raw.companyId || raw.agency_id || raw.agencyId || '';
    const id = raw.id || raw.user_id || raw.uid || raw.email || companyId || `agency-${Math.random().toString(36).slice(2)}`;

    return {
        ...raw,
        id,
        name: personName || companyName || raw.email || 'Unnamed Agency',
        full_name: raw.full_name || personName || companyName || raw.email || 'Unnamed Agency',
        firstName,
        lastName,
        email: raw.email || raw.email_address || '',
        phone: raw.phone || raw.phone_number || '',
        role: raw.role || 'agency',
        active: raw.active !== false,
        company_id: companyId || (raw.appType === 'agency' ? raw.id : ''),
        company_name: companyName || personName || 'Unknown Agency',
        agency_name: raw.agency_name || companyName || '',
        balance: normalizeNumber(raw.balance ?? raw.credit_balance ?? raw.credits, 0),
        credit_balance: normalizeNumber(raw.credit_balance ?? raw.balance ?? raw.credits, 0),
        credits: normalizeNumber(raw.credits ?? raw.credit_balance ?? raw.balance, 0),
        created_at: raw.created_at || raw.createdAt || raw.date_created || '',
        createdAt: raw.createdAt || raw.created_at || raw.date_created || '',
        updated_at: raw.updated_at || raw.updatedAt || '',
    };
};

const getAgencyDisplayName = (account: AgencyAccount) =>
    account.full_name ||
    account.name ||
    compact(account.firstName, account.lastName) ||
    account.company_name ||
    account.email ||
    'Unnamed Agency';

const getInitials = (account: AgencyAccount) => {
    const source = getAgencyDisplayName(account);
    const parts = source.split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] || account.email?.[0] || '?') + (parts[1]?.[0] || '')).toUpperCase();
};

const getCompanyLabel = (account: AgencyAccount) =>
    account.company_name ||
    account.agency_name ||
    'Not linked';

const emptyValue = (value?: string | null) => value?.trim() || '-';

const formatAgencyDate = (value?: any) => {
    if (!value) return '-';
    if (typeof value === 'object' && typeof value.seconds === 'number') {
        return new Date(value.seconds * 1000).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    const raw = String(value);
    const normalized = raw
        .replace(' at ', ' ')
        .replace(/ UTC([+-]\d{1,2})$/, ' GMT$1')
        .replace(/ UTC([+-]\d{1,2}):?(\d{2})$/, ' GMT$1$2');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return raw;
    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
};

const roleBadge = (role?: string) => (
    <span className="inline-flex px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30 text-[10px] font-black uppercase tracking-wider">
        {(role || 'agency').replace(/_/g, ' ')}
    </span>
);

const getAgencyRows = (json: any) => {
    const rows = json?.data ?? json?.agency_users ?? json?.users ?? json?.agencies ?? [];
    if (Array.isArray(rows)) return rows;
    if (rows && typeof rows === 'object') {
        return Object.entries(rows).map(([id, data]) => ({ id, ...(data as Record<string, unknown>) }));
    }
    return [];
};

const getTransactionMonth = (tx: any) => {
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

const normalizeAgencyWalletTransaction = (tx: any, account: AgencyAccount) => ({
    ...tx,
    timestamp: tx.timestamp || tx.created_at || tx.createdAt || tx.date,
    created_at: tx.created_at || tx.timestamp || tx.createdAt || tx.date,
    type: tx.type || tx.event_type || tx.kind || 'wallet_transaction',
    amount: normalizeNumber(tx.amount, 0),
    balance_after: normalizeNumber(tx.balance_after ?? tx.balance, account.balance ?? account.credit_balance ?? account.credits ?? 0),
    description: tx.description || tx.note || tx.memo || tx.reason || 'Agency wallet transaction',
    message: tx.message || tx.description || tx.note || tx.memo || tx.reason || 'Agency wallet transaction',
    location_name: tx.location_name || tx.subaccount_name || getAgencyDisplayName(account),
    agency_name: tx.agency_name || tx.company_name || getCompanyLabel(account),
    company_name: tx.company_name || tx.agency_name || getCompanyLabel(account),
    company_id: tx.company_id || tx.agency_id || account.company_id || account.id,
});

const buildPlanReportTransactions = (plans: any[], account: AgencyAccount) =>
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
            expires ? `Renews/expires: ${formatAgencyDate(expires)}` : '',
        ].filter(Boolean).join('. ');

        return {
            id: `plan-${plan.id || plan.plan || index}`,
            timestamp: plan.updated_at || plan.created_at || plan.started_at || expires || new Date().toISOString(),
            created_at: plan.updated_at || plan.created_at || plan.started_at || expires || new Date().toISOString(),
            type: 'subscription_plan',
            event_type: 'subscription_plan',
            amount: 0,
            balance_after: account.balance ?? account.credit_balance ?? account.credits ?? 0,
            description: details,
            message: details,
            location_name: getAgencyDisplayName(account),
            agency_name: getCompanyLabel(account),
            company_name: getCompanyLabel(account),
            company_id: account.company_id || account.id,
        };
    });

const buildAgencyReportProfile = (account: AgencyAccount) => ({
    accountName: getAgencyDisplayName(account),
    ownerName: getAgencyDisplayName(account),
    email: account.email,
    phone: account.phone,
    agencyName: getCompanyLabel(account),
    companyName: account.company_name || account.agency_name,
    companyId: account.company_id || account.id,
    reportTitle: 'AGENCY WALLET & PLAN REPORT',
    currentBalance: account.balance ?? account.credit_balance ?? account.credits ?? 0,
});




export const AdminAgencies: React.FC = () => {
    const [accounts, setAccounts] = useState<AgencyAccount[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [_actionLoading, setActionLoading] = useState<string | null>(null);
    const [managingAccount, setManagingAccount] = useState<AgencyAccount | null>(null);
    const { toasts, showToast, dismissToast } = useToast();
    
    const [searchTerm, setSearchTerm] = useState('');

    const [reportTransactions, setReportTransactions] = useState<any[]>([]);
    const [isLoadingReport, setIsLoadingReport] = useState(false);
    const [reportSelectedMonth, setReportSelectedMonth] = useState('All');
    const [selectedReportAccount, setSelectedReportAccount] = useState<AgencyAccount | null>(null);
    const [openActionMenuId, setOpenActionMenuId] = useState<string | null>(null);
    const [actionMenuPos, setActionMenuPos] = useState({ top: 0, right: 0 });
    const actionMenuRef = useRef<HTMLDivElement>(null);
    const [editingBalanceId, setEditingBalanceId] = useState<string | null>(null);
    const [editingBalanceValue, setEditingBalanceValue] = useState('');

    const [currentPage, setCurrentPage] = useState(1);
    const ITEMS_PER_PAGE = 10;

    useEffect(() => {
        setCurrentPage(1);
    }, [searchTerm]);

    useEffect(() => {
        if (!openActionMenuId) return;
        const handler = (event: MouseEvent) => {
            if (actionMenuRef.current && !actionMenuRef.current.contains(event.target as Node)) {
                setOpenActionMenuId(null);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [openActionMenuId]);

    const openAgencyActionMenu = (accId: string, el: HTMLElement) => {
        const rect = el.getBoundingClientRect();
        setActionMenuPos({ top: rect.bottom + window.scrollY + 4, right: window.innerWidth - rect.right });
        setOpenActionMenuId(prev => prev === accId ? null : accId);
    };

    const fetchAccounts = useCallback(async (isInitial = false, bypassCache = false) => {
        if (isInitial) setLoading(true);
        setError(null);
        try {
            let lastError: Error | null = null;

            for (const endpoint of AGENCY_USER_ENDPOINTS) {
                try {
                    const endpointUrl = bypassCache && endpoint === ADMIN_AGENCY_USERS_API
                        ? `${endpoint}?refresh=1`
                        : endpoint;
                    const res = await adminFetch(endpointUrl, { headers: getAdminAuthHeaders() });
                    const json = await res.json().catch(() => ({}));
                    const rows = getAgencyRows(json);

                    if (!res.ok || (json.status && json.status !== 'success') || json.success === false) {
                        throw new Error(json.message || `Failed to load agencies from ${endpointUrl}.`);
                    }

                    setAccounts(rows.map(normalizeAgencyAccount));
                    setError(endpoint.includes('action=agencies')
                        ? 'Agency user endpoint is unavailable. Showing legacy agency records until the backend route is deployed.'
                        : null);
                    return;
                } catch (error) {
                    lastError = error instanceof Error ? error : new Error('Failed to load agencies.');
                }
            }

            throw lastError || new Error('Failed to load agencies.');
        } catch {
            setError('Network error. Could not reach the backend.');
        } finally {
            if (isInitial) setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchAccounts(true);
        const timer = setInterval(() => fetchAccounts(false), POLL_INTERVAL);
        return () => clearInterval(timer);
    }, [fetchAccounts]);

    const filteredAccounts = useMemo(() => {
        const query = searchTerm.trim().toLowerCase();
        if (!query) return accounts;

        return accounts.filter(acc =>
            getAgencyDisplayName(acc).toLowerCase().includes(query) ||
            getCompanyLabel(acc).toLowerCase().includes(query) ||
            acc.email?.toLowerCase().includes(query) ||
            acc.phone?.toLowerCase().includes(query) ||
            acc.role?.toLowerCase().includes(query) ||
            acc.company_id?.toLowerCase().includes(query) ||
            acc.id?.toLowerCase().includes(query)
        );
    }, [accounts, searchTerm]);

    const totalPages = Math.ceil(filteredAccounts.length / ITEMS_PER_PAGE);
    const currentAccounts = filteredAccounts.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

    useEffect(() => {
        if (totalPages > 0 && currentPage > totalPages) {
            setCurrentPage(totalPages);
        }
    }, [currentPage, totalPages]);

    const fetchReportForAccount = async (acc: AgencyAccount) => {
        const reportKey = acc.company_id || acc.id;
        if (!reportKey) {
            showToast('This agency does not have a company ID for reporting.', 'error');
            return;
        }

        setSelectedReportAccount(acc);
        setIsLoadingReport(true);
        setReportSelectedMonth(getCurrentReportMonth());
        setReportTransactions([]);
        try {
            const [transactionsResult, subscriptionResult] = await Promise.allSettled([
                adminFetch(`/api/billing/transactions.php?scope=agency&agency_id=${encodeURIComponent(reportKey)}&limit=5000`, { headers: getAdminAuthHeaders() }),
                adminFetch(`/api/billing/subscription.php?agency_id=${encodeURIComponent(reportKey)}`, { headers: getAdminAuthHeaders() }),
            ]);

            const reportRows: any[] = [];
            let loadError = '';

            if (transactionsResult.status === 'fulfilled' && transactionsResult.value.ok) {
                const json = await transactionsResult.value.json().catch(() => null);
                reportRows.push(...readReportTransactions(json).map(tx => normalizeAgencyWalletTransaction(tx, acc)));
            } else {
                loadError = 'Wallet transactions could not be loaded.';
            }

            if (subscriptionResult.status === 'fulfilled' && subscriptionResult.value.ok) {
                const json = await subscriptionResult.value.json().catch(() => null);
                reportRows.push(...buildPlanReportTransactions(readSubscriptionPlans(json), acc));
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
            showToast('Failed to load agency report data.', 'error');
        } finally {
            setIsLoadingReport(false);
        }
    };

    const startEditBalance = (account: AgencyAccount) => {
        setEditingBalanceId(account.id);
        setEditingBalanceValue(String(account.balance ?? account.credit_balance ?? account.credits ?? 0));
    };

    const updateAgencyBalanceLocally = (accountId: string, balance: number) => {
        setAccounts(prev => prev.map(acc => acc.id === accountId ? {
            ...acc,
            balance,
            credit_balance: balance,
            credits: balance,
        } : acc));
        setManagingAccount((prev: any) => prev?.id === accountId ? {
            ...prev,
            balance,
            credit_balance: balance,
            credits: balance,
        } : prev);
    };

    const saveEditBalance = async (account: AgencyAccount) => {
        const newVal = parseInt(editingBalanceValue) || 0;
        const currentBalance = account.balance ?? account.credit_balance ?? account.credits ?? 0;
        const agencyKey = account.company_id || account.id;

        setEditingBalanceId(null);
        if (newVal === currentBalance) return;
        if (!agencyKey) {
            showToast('This agency does not have an ID for balance updates.', 'error');
            return;
        }

        updateAgencyBalanceLocally(account.id, newVal);
        setActionLoading(`balance:${account.id}`);
        try {
            const res = await adminFetch(ADMIN_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({
                    action: 'manage_agency',
                    user_id: account.id,
                    company_id: agencyKey,
                    agency_id: agencyKey,
                    balance: newVal,
                    credit_balance: newVal,
                    credits: newVal,
                }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || (json?.status && json.status !== 'success') || (json?.success === false)) {
                throw new Error(json?.message || json?.error || 'Failed to update agency balance.');
            }

            showToast(json?.message || 'Agency balance updated.', 'success');
        } catch (err) {
            updateAgencyBalanceLocally(account.id, currentBalance);
            showToast(err instanceof Error ? err.message : 'Network error updating agency balance.', 'error');
        } finally {
            setActionLoading(null);
        }
    };



    return (
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl shadow-[0_2px_15px_rgba(0,0,0,0.03)] dark:shadow-[0_2px_15px_rgba(0,0,0,0.2)] overflow-visible">
            <ToastContainer toasts={toasts} onDismiss={dismissToast} />

            {/* Header */}
            <div className="px-6 pt-6 pb-5 border-b border-[#e5e5e5] dark:border-white/5">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h3 className="text-[16px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                            <FiBriefcase className="w-4 h-4 text-[#2b83fa]" /> All Agencies
                        </h3>
                        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">Manage all partner agencies and their capabilities.</p>
                    </div>
                    <div className="flex items-center gap-2 mb-2 sm:mb-0">
                        <div className="relative w-full sm:w-64 transform translate-y-1.5 sm:translate-y-0">
                            <FiSearch className="absolute left-3 top-1/2 -translate-y-[60%] sm:-translate-y-1/2 text-[#9aa0a6] w-3.5 h-3.5" />
                            <input
                                type="text"
                                placeholder="Search agencies..."
                                value={searchTerm}
                                onChange={e => setSearchTerm(e.target.value)}
                                className="w-full pl-9 pr-8 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] text-[#111111] dark:text-white placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all font-medium"
                            />
                            {searchTerm && <button onClick={() => setSearchTerm('')} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"><FiX className="w-3 h-3" /></button>}
                        </div>
                        <button onClick={() => fetchAccounts(true, true)} className="p-2 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-all border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl flex-shrink-0">
                            <FiRefreshCw className={`w-3.5 h-3.5 ${loading && accounts.length === 0 ? 'animate-spin' : ''}`} />
                        </button>
                    </div>
                </div>
            </div>

            <div className="p-6 overflow-visible">
            {error && (
                <div className="flex items-center gap-2 px-4 py-3 mb-5 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-800/30 text-amber-700 dark:text-amber-400 text-[12px] font-medium">
                    <FiAlertCircle className="w-4 h-4 flex-shrink-0" />
                    {error}
                </div>
            )}

            {loading ? (
                <div className="space-y-3">
                    {[1, 2, 3, 4].map(i => <div key={i} className="h-14 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />)}
                </div>
            ) : accounts.length === 0 ? (
                <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
                    <FiBriefcase className="w-8 h-8 mx-auto mb-3 opacity-30" />
                    <p className="text-[14px] font-semibold">No agencies yet.</p>
                </div>
            ) : filteredAccounts.length === 0 ? (
                <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
                    <FiSearch className="w-8 h-8 mx-auto mb-3 opacity-30" />
                    <p className="text-[14px] font-semibold">No agencies match your search.</p>
                </div>
            ) : (
                <>
                    <div className="overflow-x-auto pb-4">
                        <table className="w-full text-left border-collapse">
                            <thead>
                                <tr className="border-b border-[#e5e5e5] dark:border-white/5">
                                    {['Agency', 'Email', 'Phone', 'Role', 'Balance', 'Actions'].map(header => (
                                        <th key={header} className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                            {header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#f0f0f0] dark:divide-white/[0.03]">
                                {currentAccounts.map(acc => (
                                    <tr key={acc.id} className="group hover:bg-[#f7f7f7] dark:hover:bg-white/[0.015] transition-colors">
                                        <td className="py-4 pr-4 w-[34%]">
                                            <div className="flex items-center gap-3">
                                                <div className="relative w-9 h-9 rounded-xl bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex items-center justify-center text-white text-[12px] font-black flex-shrink-0 shadow-sm">
                                                    {getInitials(acc)}
                                                    <span className={`absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-white dark:border-[#1a1b1e] ${acc.active !== false ? 'bg-emerald-500' : 'bg-gray-400'}`} />
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="font-bold text-[13px] text-[#111111] dark:text-white group-hover:text-[#2b83fa] transition-colors truncate">{getAgencyDisplayName(acc)}</p>
                                                    <p className="text-[10px] text-[#9aa0a6] font-medium mt-0.5 truncate">
                                                        {getCompanyLabel(acc)} {acc.company_id ? `- ${acc.company_id}` : `- ${acc.id}`}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="py-4 pr-4 w-[22%] text-[12px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] truncate">{emptyValue(acc.email)}</td>
                                        <td className="py-4 pr-4 w-[14%] text-[12px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] whitespace-nowrap">{emptyValue(acc.phone)}</td>
                                        <td className="py-4 pr-4 w-[12%]">{roleBadge(acc.role)}</td>
                                        <td className="py-4 pr-4 w-[10%]">
                                            {editingBalanceId === acc.id ? (
                                                <div className="inline-flex items-center rounded-lg border border-[#d8dce3] dark:border-white/10 bg-white dark:bg-[#0d0e10] overflow-hidden shadow-sm">
                                                    <button
                                                        type="button"
                                                        onClick={() => setEditingBalanceValue(prev => String(Math.max(0, (parseInt(prev) || 0) - 1)))}
                                                        className="px-2.5 py-1.5 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors border-r border-[#e0e0e0] dark:border-white/10"
                                                    >
                                                        <FiMinus className="w-3 h-3" />
                                                    </button>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        autoFocus
                                                        value={editingBalanceValue}
                                                        onChange={e => setEditingBalanceValue(e.target.value)}
                                                        onKeyDown={e => {
                                                            if (e.key === 'Enter') saveEditBalance(acc);
                                                            if (e.key === 'Escape') setEditingBalanceId(null);
                                                        }}
                                                        className="w-14 text-center text-[13px] font-bold bg-transparent text-[#111111] dark:text-white focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => setEditingBalanceValue(prev => String((parseInt(prev) || 0) + 1))}
                                                        className="px-2.5 py-1.5 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors border-l border-[#e0e0e0] dark:border-white/10"
                                                    >
                                                        <FiPlus className="w-3 h-3" />
                                                    </button>
                                                </div>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => startEditBalance(acc)}
                                                    title="Click to edit balance"
                                                    className="flex flex-col items-start hover:bg-[#f0f7ff] dark:hover:bg-blue-900/10 rounded-lg px-2 py-1 transition-colors group/balance"
                                                >
                                                    <span className="text-[13px] font-bold text-[#111111] dark:text-white group-hover/balance:text-[#2b83fa] transition-colors">{(acc.balance ?? acc.credit_balance ?? acc.credits ?? 0).toLocaleString()}</span>
                                                    <span className="text-[9px] text-[#9aa0a6] font-medium uppercase tracking-tight group-hover/balance:text-[#2b83fa]/60 transition-colors">click to edit</span>
                                                </button>
                                            )}
                                        </td>
                                        <td className="py-4 pr-2 w-[8%] text-right">
                                            <button
                                                type="button"
                                                onClick={(event) => openAgencyActionMenu(acc.id, event.currentTarget)}
                                                className="p-2 rounded-xl text-[#6e6e73] hover:text-[#111111] dark:hover:text-white hover:bg-white dark:hover:bg-[#1a1b1e] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-sm transition-all"
                                                title="More actions"
                                                aria-label="More actions"
                                                aria-expanded={openActionMenuId === acc.id}
                                            >
                                                <FiMoreVertical className="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {totalPages > 1 && (
                        <div className="flex items-center justify-between px-4 py-4 mt-2 border-t border-[#e5e5e5] dark:border-white/5">
                            <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] font-medium">
                                Showing <span className="font-bold text-[#111111] dark:text-white">{(currentPage - 1) * ITEMS_PER_PAGE + 1}</span> to <span className="font-bold text-[#111111] dark:text-white">{Math.min(currentPage * ITEMS_PER_PAGE, filteredAccounts.length)}</span> of <span className="font-bold text-[#111111] dark:text-white">{filteredAccounts.length}</span> entries
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
                </>
            )}

            {/* Floating action menu for agency rows */}
            {openActionMenuId && (() => {
                const acc = accounts.find(a => a.id === openActionMenuId);
                if (!acc) return null;
                return (
                    <div
                        ref={actionMenuRef}
                        style={{ position: 'fixed', top: actionMenuPos.top, right: actionMenuPos.right, zIndex: 9999 }}
                        className="w-44 bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-xl shadow-2xl overflow-hidden py-1"
                    >
                        <button
                            type="button"
                            onClick={() => { setOpenActionMenuId(null); setManagingAccount(acc); }}
                            className="w-full flex items-center gap-2 px-3 py-2.5 text-left text-[12px] font-bold text-[#111111] dark:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/[0.04] transition-colors"
                        >
                            <FiEye className="w-3.5 h-3.5 text-[#2b83fa]" /> View details
                        </button>
                        <button
                            type="button"
                            onClick={() => { setOpenActionMenuId(null); fetchReportForAccount(acc); }}
                            className="w-full flex items-center gap-2 px-3 py-2.5 text-left text-[12px] font-bold text-[#111111] dark:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/[0.04] transition-colors"
                        >
                            <FiDownload className="w-3.5 h-3.5 text-emerald-500" /> Download report
                        </button>
                    </div>
                );
            })()}

            {/* Agency Details Modal */}
            {managingAccount && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-md shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="flex items-center justify-between mb-5">
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                                <FiBriefcase className="text-[#2b83fa]" /> Agency Details
                            </h3>
                            <button onClick={() => setManagingAccount(null)} className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors">
                                <FiX className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#111214] p-4">
                                <p className="text-[10px] font-black uppercase tracking-widest text-[#9aa0a6] mb-1">Agency</p>
                                <p className="text-[17px] font-black text-[#111111] dark:text-white">{getAgencyDisplayName(managingAccount)}</p>
                                <p className="text-[12px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6] mt-1">{getCompanyLabel(managingAccount)}</p>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                {[
                                    ['Email', emptyValue(managingAccount.email)],
                                    ['Phone', emptyValue(managingAccount.phone)],
                                    ['Role', managingAccount.role || 'agency'],
                                    ['Balance', `${(managingAccount.balance ?? managingAccount.credit_balance ?? managingAccount.credits ?? 0).toLocaleString()} credits`],
                                    ['Company ID', managingAccount.company_id || managingAccount.id],
                                    ['Status', managingAccount.active !== false ? 'Active' : 'Inactive'],
                                ].map(([label, value]) => (
                                    <div key={label} className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] px-4 py-3">
                                        <p className="text-[10px] font-black uppercase tracking-widest text-[#9aa0a6] mb-1">{label}</p>
                                        <p className="text-[13px] font-bold text-[#111111] dark:text-white break-words">{value}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            )}
            </div>

            {/* Standalone Report Modal */}
            {selectedReportAccount && (() => {
                const monthOptions = getReportMonthOptions(reportTransactions);
                const selectedEventCount = getReportMonthCount(reportTransactions, reportSelectedMonth);
                const canDownloadReport = !isLoadingReport && selectedEventCount > 0;
                const selectedLabel = reportSelectedMonth === 'All' ? 'All Wallet & Plan Events' : getReportMonthLabel(reportSelectedMonth);

                return (
                    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl w-full max-w-lg shadow-2xl animate-in fade-in zoom-in-95 duration-200 overflow-hidden">
                            <div className="px-6 py-5 border-b border-[#e5e5e5] dark:border-white/10 flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2.5 text-[#111111] dark:text-white">
                                        <div className="w-9 h-9 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                                            <FiDownload className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <h3 className="text-[17px] font-bold leading-tight">Download Report</h3>
                                            <p className="text-[12px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5 truncate">
                                                {getAgencyDisplayName(selectedReportAccount)}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <button onClick={() => setSelectedReportAccount(null)} className="p-2 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors">
                                    <FiX className="w-5 h-5" />
                                </button>
                            </div>

                            <div className="p-6 space-y-5">
                                {isLoadingReport ? (
                                    <div className="py-8 flex flex-col items-center justify-center gap-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5">
                                        <FiRefreshCw className="w-5 h-5 text-[#2b83fa] animate-spin" />
                                        <p className="text-[13px] font-bold text-[#111111] dark:text-white">Loading agency report data...</p>
                                        <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6]">Fetching wallet transactions and current plan details.</p>
                                    </div>
                                ) : reportTransactions.length === 0 ? (
                                    <div className="py-8 flex flex-col items-center justify-center gap-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5 text-center">
                                        <div className="w-11 h-11 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                                            <FiDownload className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <p className="text-[14px] font-bold text-[#111111] dark:text-white">No reportable agency activity</p>
                                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">PDF download is disabled until wallet transactions or plan data are available.</p>
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                                                <p className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Total Events</p>
                                                <p className="text-[22px] font-black text-[#111111] dark:text-white mt-1">{reportTransactions.length.toLocaleString()}</p>
                                            </div>
                                            <div className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                                                <p className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Selected Period</p>
                                                <p className="text-[13px] font-black text-[#111111] dark:text-white mt-1 truncate">{selectedLabel}</p>
                                                <p className="text-[11px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6] mt-1">{selectedEventCount.toLocaleString()} events</p>
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <label className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Report Period</label>
                                            <div className="relative">
                                                <select
                                                    value={reportSelectedMonth}
                                                    onChange={(event) => setReportSelectedMonth(event.target.value)}
                                                    className="w-full appearance-none pl-3.5 pr-10 py-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#d8dce3] dark:border-white/10 text-[13px] font-bold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all cursor-pointer"
                                                >
                                                    <option value="All">All Wallet & Plan Events ({reportTransactions.length} events)</option>
                                                    {monthOptions.map(month => {
                                                        const count = getReportMonthCount(reportTransactions, month);
                                                        return <option key={month} value={month}>{getReportMonthLabel(month)} ({count})</option>;
                                                    })}
                                                </select>
                                                <FiChevronDown className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[#9aa0a6] pointer-events-none" />
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => generateMonthlyReport(reportSelectedMonth, reportTransactions, 'agency', getAgencyDisplayName(selectedReportAccount), buildAgencyReportProfile(selectedReportAccount))}
                                            disabled={!canDownloadReport}
                                            className="w-full flex items-center justify-center gap-2 px-5 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] text-white rounded-xl text-[13px] font-bold transition-all shadow-md shadow-blue-500/20 active:scale-[0.98] disabled:opacity-45 disabled:cursor-not-allowed disabled:hover:shadow-none"
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
        </div>
    );
};


