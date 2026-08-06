import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    FiAlertCircle,
    FiChevronDown,
    FiChevronLeft,
    FiChevronRight,
    FiChevronUp,
    FiDownload,
    FiEye,
    FiFilter,
    FiMoreVertical,
    FiMinus,
    FiPlus,
    FiRefreshCw,
    FiSearch,
    FiTrash2,
    FiUsers,
    FiX,
} from 'react-icons/fi';
import { useToast } from '../../hooks/useToast';
import { ToastContainer } from '../../components/ui/ToastContainer';
import { generateMonthlyReport } from '../../utils/pdfGenerator';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';
import { AdminSubaccountProfile } from './AdminSubaccountProfile';

const ADMIN_LIST_USERS_API = '/api/admin_list_users.php';
const ADMIN_MANAGE_USER_API = '/api/admin_manage_user.php';
const ADMIN_SENDER_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000;
const ITEMS_PER_PAGE = 10;

type Account = {
    id: string;
    name?: string;
    full_name?: string;
    firstName?: string;
    lastName?: string;
    email?: string;
    phone?: string;
    role?: string;
    active?: boolean;
    status?: string;
    last_active_at?: any;
    last_active?: any;
    last_login_at?: any;
    last_login?: any;
    updated_at?: any;
    location_id?: string;
    active_location_id?: string;
    location_name?: string;
    company_id?: string;
    company_name?: string;
    agency_name?: string;
    source?: string;
    created_at?: string;
    approved_sender_id?: string | null;
    provider?: 'system' | 'semaphore' | 'unisms' | string | null;
    approved_provider?: 'system' | 'semaphore' | 'unisms' | string | null;
    credit_balance?: number;
    credits?: number;
    free_usage_count?: number;
    free_credits_total?: number;
    monthly_reset_enabled?: boolean;
    /** Display name of the resolved SMS provider e.g. "Semaphore" or "UniSMS" */
    sms_provider?: string;
    /** Live available credit balance from the SMS provider API */
    provider_credit_balance?: number;
    /** Whether the provider balance came from custom key or system key */
    provider_balance_source?: 'custom' | 'system';
};

const normalizeNumber = (value: unknown, fallback = 0) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
};

const normalizeAccount = (item: any): Account => {
    const raw = item?.data ? { id: item.id, ...item.data } : item || {};
    const name = raw.name || raw.full_name || [raw.firstName, raw.lastName].filter(Boolean).join(' ').trim();
    const locationName = raw.location_name || raw.subaccount_name || raw.company_name || '';

    return {
        ...raw,
        id: raw.id || raw.user_id || raw.uid || raw.location_id || raw.email || `user-${Math.random().toString(36).slice(2)}`,
        name: name || raw.email || locationName || 'Unnamed User',
        full_name: raw.full_name || name || raw.email || locationName || 'Unnamed User',
        email: raw.email || raw.email_address || '',
        phone: raw.phone || raw.phone_number || '',
        role: raw.role || 'user',
        active: raw.active !== false,
        status: raw.status || (raw.active === false ? 'inactive' : 'active'),
        last_active_at: raw.last_active_at || raw.last_active || raw.lastActiveAt || raw.lastActive || raw.last_login_at || raw.last_login || raw.lastLoginAt || raw.lastLogin || raw.updated_at || raw.updatedAt || raw.created_at || raw.createdAt || '',
        location_id: raw.location_id || raw.active_location_id || '',
        active_location_id: raw.active_location_id || raw.location_id || '',
        location_name: locationName,
        agency_name: raw.agency_name || raw.company_name || '',
        credit_balance: normalizeNumber(raw.credit_balance ?? raw.credits, 0),
        credits: normalizeNumber(raw.credits ?? raw.credit_balance, 0),
        free_usage_count: normalizeNumber(raw.free_usage_count, 0),
        free_credits_total: normalizeNumber(raw.free_credits_total, 10),
        approved_sender_id: raw.approved_sender_id || null,
        provider: raw.provider || null,
        approved_provider: raw.approved_provider || raw.provider || null,
        sms_provider: raw.sms_provider || null,
        provider_credit_balance: typeof raw.provider_credit_balance === 'number' ? raw.provider_credit_balance : null,
        provider_balance_source: raw.provider_balance_source || null,
    };
};

const getAccountName = (account: Account) =>
    account.name ||
    account.full_name ||
    [account.firstName, account.lastName].filter(Boolean).join(' ').trim() ||
    account.email ||
    'Unnamed User';



type AccountStatusFilter = 'all' | 'active' | 'inactive' | 'missing_location' | 'low_credit';

const accountStatusFilters: Array<{ id: AccountStatusFilter; label: string }> = [
    { id: 'all', label: 'All statuses' },
    { id: 'active', label: 'Active' },
    { id: 'inactive', label: 'Inactive' },
    { id: 'missing_location', label: 'Missing Location' },
    { id: 'low_credit', label: 'Low Credits' },
];

const emptyValue = (value?: string | null) => value?.trim() || '-';

const getCreditBalance = (account: Account) => account.credit_balance ?? account.credits ?? 0;



const getAccountStatusMeta = (account: Account) => {
    const hasLocation = Boolean((account.location_id || account.active_location_id || '').trim());
    const credits = getCreditBalance(account);
    const normalized = String(account.status || '').toLowerCase();

    if (!hasLocation) {
        return { label: 'Missing Location', classes: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/10 dark:text-amber-400 dark:border-amber-800/30' };
    }
    if (account.active === false || normalized === 'inactive' || normalized === 'disabled' || normalized === 'suspended') {
        return { label: 'Inactive', classes: 'bg-gray-100 text-gray-600 border-gray-200 dark:bg-white/5 dark:text-[#9aa0a6] dark:border-white/10' };
    }
    if (credits <= 0) {
        return { label: 'No Credits', classes: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/10 dark:text-red-400 dark:border-red-800/30' };
    }
    if (credits < 25) {
        return { label: 'Low Credits', classes: 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-900/10 dark:text-orange-400 dark:border-orange-800/30' };
    }
    return { label: 'Active', classes: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30' };
};

const renderAccountStatusBadge = (account: Account) => {
    const status = getAccountStatusMeta(account);
    return (
        <span className={`inline-flex px-2.5 py-1 rounded-full border text-[10px] font-black uppercase tracking-wider whitespace-nowrap ${status.classes}`}>
            {status.label}
        </span>
    );
};

const matchesAccountStatusFilter = (account: Account, filter: AccountStatusFilter) => {
    if (filter === 'all') return true;
    const hasLocation = Boolean((account.location_id || account.active_location_id || '').trim());
    const credits = getCreditBalance(account);
    const normalized = String(account.status || '').toLowerCase();
    if (filter === 'missing_location') return !hasLocation;
    if (filter === 'low_credit') return credits < 25;
    if (filter === 'inactive') return account.active === false || ['inactive', 'disabled', 'suspended'].includes(normalized);
    if (filter === 'active') return hasLocation && account.active !== false && !['inactive', 'disabled', 'suspended'].includes(normalized);
    return true;
};

const getAgencyName = (account: Account) => {
    const raw = (account.agency_name || account.company_name || '').trim();
    if (raw && raw !== 'Agency name unavailable') {
        return raw;
    }
    return 'Unassigned Agency';
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
    return [];
};

const buildSubaccountReportProfile = (account: Account) => ({
    accountName: account.location_name || getAccountName(account),
    ownerName: getAccountName(account),
    email: account.email,
    phone: account.phone,
    locationName: account.location_name,
    locationId: account.location_id || account.active_location_id,
    agencyName: getAgencyName(account),
    companyName: account.company_name || account.agency_name,
    companyId: account.company_id,
    reportTitle: 'SUBACCOUNT CREDIT REPORT',
    currentBalance: account.credit_balance ?? account.credits ?? 0,
});

export const AdminAccounts: React.FC = () => {
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [actionLoading, setActionLoading] = useState<string | null>(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [agencyFilter, setAgencyFilter] = useState('all');
    const [accountStatusFilter, setAccountStatusFilter] = useState<AccountStatusFilter>('all');
    const [sortField, setSortField] = useState<'subaccount' | 'agency' | 'credits'>('subaccount');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
    const editContainerRef = useRef<HTMLDivElement>(null);
    const [filterMenuOpen, setFilterMenuOpen] = useState(false);
    const [editingCreditId, setEditingCreditId] = useState<string | null>(null);
    const [editingCreditValue, setEditingCreditValue] = useState<string>('');
    const [currentPage, setCurrentPage] = useState(1);
    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    const [profileAccount, setProfileAccount] = useState<Account | null>(null);
    const [confirmAction, setConfirmAction] = useState<{ type: 'reset' | 'delete' | 'monthly_credit_reset'; account: Account } | null>(null);
    const [resetModalAmount, setResetModalAmount] = useState<number>(500);
    const [resetModalToggle, setResetModalToggle] = useState<boolean>(true);
    const [actionMenuId, setActionMenuId] = useState<string | null>(null);
    const [menuPos, setMenuPos] = useState({ top: 0, right: 0 });
    const menuRef = useRef<HTMLDivElement>(null);
    const filterMenuRef = useRef<HTMLDivElement>(null);

    const [reportTransactions, setReportTransactions] = useState<any[]>([]);
    const [isLoadingReport, setIsLoadingReport] = useState(false);
    const [reportSelectedMonth, setReportSelectedMonth] = useState('All');
    const [selectedReportAccount, setSelectedReportAccount] = useState<Account | null>(null);
    const userListUnavailableRef = useRef(false);

    const { toasts, showToast, dismissToast } = useToast();

    useEffect(() => {
        setCurrentPage(1);
    }, [searchTerm, agencyFilter, accountStatusFilter, sortField, sortDir]);

    useEffect(() => {
        if (!filterMenuOpen) return;
        const handler = (event: MouseEvent) => {
            if (filterMenuRef.current && !filterMenuRef.current.contains(event.target as Node)) {
                setFilterMenuOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [filterMenuOpen]);

    useEffect(() => {
        if (!actionMenuId) return;
        const handler = (event: MouseEvent) => {
            if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
                setActionMenuId(null);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [actionMenuId]);

    useEffect(() => {
        if (editingCreditId === null) return;
        const handler = (event: MouseEvent) => {
            if (editContainerRef.current && !editContainerRef.current.contains(event.target as Node)) {
                const account = accounts.find(acc => acc.id === editingCreditId);
                if (account) {
                    void saveEditCredit(account);
                } else {
                    setEditingCreditId(null);
                }
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [editingCreditId, editingCreditValue, accounts]);

    const fetchAccounts = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        setError(null);

        try {
            if (userListUnavailableRef.current && !isInitial) {
                throw new Error('USER_LIST_SKIPPED_AFTER_401');
            }

            const res = await adminFetch(ADMIN_LIST_USERS_API, { headers: getAdminAuthHeaders() });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || json.status !== 'success') {
                const error = new Error(json.message || (res.status === 401 ? 'Admin session was rejected by the user list endpoint.' : 'Failed to load registered users.'));
                (error as any).status = res.status;
                throw error;
            }

            userListUnavailableRef.current = false;
            setAccounts((json.data || []).map(normalizeAccount));
            setLastRefreshed(new Date());
        } catch (primaryError) {
            const primaryStatus = (primaryError as any)?.status;
            if (primaryStatus === 401) {
                userListUnavailableRef.current = true;
            }

            try {
                const legacyRes = await adminFetch(`${ADMIN_SENDER_API}?action=accounts`, { headers: getAdminAuthHeaders() });
                const legacyJson = await legacyRes.json();
                if (!legacyRes.ok || legacyJson.status !== 'success') throw primaryError;
                setAccounts((legacyJson.data || []).map(normalizeAccount));
                setError(primaryStatus === 401
                    ? 'Admin user list rejected this session (401). Showing legacy account data. Log out and back in if this keeps happening.'
                    : 'User list endpoint is unavailable. Showing legacy account data.');
                setLastRefreshed(new Date());
            } catch {
                setError(primaryError instanceof Error ? primaryError.message : 'Network error. Could not reach the backend.');
            }
        } finally {
            if (isInitial) setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchAccounts(true);
        const timer = setInterval(() => fetchAccounts(false), POLL_INTERVAL);
        return () => clearInterval(timer);
    }, [fetchAccounts]);

    const agencyOptions = useMemo(() => {
        const agencies = new Map<string, string>();
        accounts.forEach(account => {
            const agency = getAgencyName(account);
            agencies.set(agency.toLowerCase(), agency);
        });
        return Array.from(agencies.values()).sort((a, b) => a.localeCompare(b));
    }, [accounts]);

    const filteredAccounts = useMemo(() => {
        const query = searchTerm.trim().toLowerCase();

        const matches = accounts.filter(acc => {
            const agencyName = getAgencyName(acc);
            const matchesSearch = !query ||
                getAccountName(acc).toLowerCase().includes(query) ||
                agencyName.toLowerCase().includes(query) ||
                acc.email?.toLowerCase().includes(query) ||
                acc.phone?.toLowerCase().includes(query) ||
                acc.role?.toLowerCase().includes(query) ||
                acc.company_id?.toLowerCase().includes(query) ||
                acc.location_id?.toLowerCase().includes(query) ||
                acc.location_name?.toLowerCase().includes(query) ||
                acc.approved_sender_id?.toLowerCase().includes(query);

            const matchesAgency = agencyFilter === 'all' || agencyName === agencyFilter;
            const matchesStatus = matchesAccountStatusFilter(acc, accountStatusFilter);

            return matchesSearch && matchesAgency && matchesStatus;
        });

        return [...matches].sort((a, b) => {
            if (sortField === 'subaccount') {
                const aName = (a.location_name || getAccountName(a)).toLowerCase();
                const bName = (b.location_name || getAccountName(b)).toLowerCase();
                return sortDir === 'asc' ? aName.localeCompare(bName) : bName.localeCompare(aName);
            }
            if (sortField === 'agency') {
                const aName = getAgencyName(a).toLowerCase();
                const bName = getAgencyName(b).toLowerCase();
                return sortDir === 'asc' ? aName.localeCompare(bName) : bName.localeCompare(aName);
            }
            if (sortField === 'credits') {
                const aVal = a.credit_balance ?? a.credits ?? 0;
                const bVal = b.credit_balance ?? b.credits ?? 0;
                return sortDir === 'asc' ? aVal - bVal : bVal - aVal;
            }
            return 0;
        });
    }, [accounts, searchTerm, agencyFilter, accountStatusFilter, sortField, sortDir]);

    const totalPages = Math.ceil(filteredAccounts.length / ITEMS_PER_PAGE);
    const currentAccounts = filteredAccounts.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

    useEffect(() => {
        if (totalPages > 0 && currentPage > totalPages) {
            setCurrentPage(totalPages);
        }
    }, [currentPage, totalPages]);

    const openActionMenu = (accountId: string, button: HTMLButtonElement) => {
        const rect = button.getBoundingClientRect();
        setMenuPos({ top: rect.bottom + 6, right: window.innerWidth - rect.right });
        setActionMenuId(prev => prev === accountId ? null : accountId);
    };

    const fetchReportForAccount = async (account: Account, showModal = true) => {
        if (!account.location_id) {
            showToast('This account does not have a location ID for reporting.', 'error');
            return;
        }

        if (showModal) setSelectedReportAccount(account);
        setIsLoadingReport(true);
        setReportSelectedMonth(getCurrentReportMonth());
        setReportTransactions([]);

        try {
            const params = new URLSearchParams({
                account_id: 'default',
                limit: '5000',
                location_id: account.location_id,
            });
            const loadTransactions = async (url: string) => {
                const res = await adminFetch(url, {
                    headers: getAdminAuthHeaders(),
                });
                const json = await res.json().catch(() => null);
                return { res, json };
            };

            let { res, json } = await loadTransactions(`/api/get_credit_transactions?${params.toString()}`);
            if (!res.ok || (json?.status && json.status !== 'success')) {
                ({ res, json } = await loadTransactions(`/api/get_credit_transactions.php?${params.toString()}`));
            }

            if (res.ok && (!json?.status || json.status === 'success')) {
                const transactions = readReportTransactions(json);
                setReportTransactions(transactions);
                setReportSelectedMonth(getReportMonthSelection(transactions));
            } else {
                showToast(json?.message || 'Failed to load transaction history.', 'error');
            }
        } catch {
            showToast('Failed to load transaction history.', 'error');
        } finally {
            setIsLoadingReport(false);
        }
    };

    const updateAccountLocally = (accountId: string, patch: Partial<Account>) => {
        setAccounts(prev => prev.map(acc => acc.id === accountId ? { ...acc, ...patch } : acc));
        setProfileAccount(prev => prev?.id === accountId ? { ...prev, ...patch } : prev);
    };

    const handleManageUserAction = async (
        type: 'reset' | 'delete' | 'monthly_credit_reset',
        account: Account,
        extraPayload?: { amount?: number; monthly_reset_enabled?: boolean }
    ) => {
        setActionLoading(`${type}:${account.id}`);
        try {
            const payloadBody: any = { action: type, user_id: account.id };
            if (extraPayload) {
                if (extraPayload.amount !== undefined) payloadBody.amount = extraPayload.amount;
                if (extraPayload.monthly_reset_enabled !== undefined) payloadBody.monthly_reset_enabled = extraPayload.monthly_reset_enabled;
            }

            const res = await adminFetch(ADMIN_MANAGE_USER_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify(payloadBody),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || (json?.status && json.status !== 'success')) {
                throw new Error(json?.message || 'Endpoint unavailable.');
            }

            if (type === 'monthly_credit_reset') {
                const targetAmount = extraPayload?.amount ?? json?.monthly_allocation ?? 500;
                updateAccountLocally(account.id, {
                    credit_balance: targetAmount,
                    credits: targetAmount,
                });
            } else if (type === 'reset') {
                updateAccountLocally(account.id, {
                    credit_balance: 0,
                    credits: 0,
                    free_usage_count: 0,
                    approved_sender_id: null,
                });
            } else {
                setAccounts(prev => prev.filter(acc => acc.id !== account.id));
                if (profileAccount?.id === account.id) setProfileAccount(null);
            }
            showToast(json?.message || `${type === 'monthly_credit_reset' ? 'Monthly credits reset' : type === 'reset' ? 'Subaccount reset' : 'Account deleted'} successfully.`, 'success');
        } catch {
            if (type === 'monthly_credit_reset') {
                const targetAmount = extraPayload?.amount ?? 500;
                updateAccountLocally(account.id, {
                    credit_balance: targetAmount,
                    credits: targetAmount,
                });
                showToast('Monthly credits reset applied locally while backend endpoint is pending.', 'info');
            } else if (type === 'reset') {
                updateAccountLocally(account.id, {
                    credit_balance: 0,
                    credits: 0,
                    free_usage_count: 0,
                    approved_sender_id: null,
                });
                showToast('Reset applied locally while the backend endpoint is pending.', 'info');
            } else {
                setAccounts(prev => prev.filter(acc => acc.id !== account.id));
                if (profileAccount?.id === account.id) setProfileAccount(null);
                showToast('Account removed locally while the backend endpoint is pending.', 'info');
            }
        } finally {
            setActionLoading(null);
            setConfirmAction(null);
            setActionMenuId(null);
        }
    };

    const handleProfileSaved = (updatedAccount: Account) => {
        const normalized = normalizeAccount(updatedAccount);
        setAccounts(prev => prev.map(acc => acc.id === normalized.id ? { ...acc, ...normalized } : acc));
        setProfileAccount(prev => prev?.id === normalized.id ? { ...prev, ...normalized } : prev);
    };

    const handleToggleActive = async (account: Account, active: boolean) => {
        updateAccountLocally(account.id, { active });
        try {
            const res = await adminFetch(ADMIN_MANAGE_USER_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({ action: 'toggle_active', user_id: account.id, active }),
            });
            const json = await res.json().catch(() => null);
            if (res.ok && json?.status === 'success') {
                showToast(`Account marked ${active ? 'active' : 'inactive'}.`, 'success');
            } else {
                showToast('Status updated locally while the backend endpoint is pending.', 'info');
            }
        } catch {
            showToast('Status updated locally while the backend endpoint is pending.', 'info');
        }
    };

    const renderFreeUsage = (account: Account) => {
        const used = account.free_usage_count ?? 0;
        const total = account.free_credits_total ?? 10;
        const atLimit = used >= total;
        const percent = Math.min((used / Math.max(total, 1)) * 100, 100);

        return (
            <div className={`inline-flex flex-col p-1.5 rounded-xl border ${atLimit ? 'bg-red-50 dark:bg-red-900/10 border-red-100 dark:border-red-900/20' : 'bg-blue-50 dark:bg-blue-900/10 border-blue-100 dark:border-blue-900/20'}`}>
                <span className={`text-[12px] font-black text-center ${atLimit ? 'text-red-600 dark:text-red-400' : 'text-[#2b83fa]'}`}>
                    {used} / {total}
                </span>
                <div className="w-12 h-1 bg-gray-200 dark:bg-gray-800 rounded-full mt-1 overflow-hidden">
                    <div className={`h-full ${atLimit ? 'bg-red-500' : 'bg-blue-500'}`} style={{ width: `${percent}%` }} />
                </div>
            </div>
        );
    };

    const handleSort = (field: 'subaccount' | 'agency' | 'credits') => {
        if (sortField === field) {
            setSortDir(prev => prev === 'asc' ? 'desc' : 'asc');
        } else {
            setSortField(field);
            setSortDir('asc');
        }
    };

    const startEditCredit = (account: Account) => {
        setEditingCreditId(account.id);
        setEditingCreditValue(String(account.credit_balance ?? account.credits ?? 0));
    };

    const saveEditCredit = async (account: Account) => {
        const newVal = parseInt(editingCreditValue) || 0;
        setEditingCreditId(null);
        if (newVal === (account.credit_balance ?? account.credits ?? 0)) return;
        updateAccountLocally(account.id, { credit_balance: newVal, credits: newVal });
        try {
            const res = await adminFetch(ADMIN_SENDER_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({
                    action: 'manage_sender',
                    location_id: account.location_id,
                    sender_id: account.approved_sender_id || '',
                    credit_balance: newVal,
                    free_credits_total: account.free_credits_total ?? 10,
                }),
            });
            const json = await res.json();
            if (json.status === 'success') {
                showToast('Credits updated.', 'success');
            } else {
                showToast(json.message || 'Failed to update credits.', 'error');
            }
        } catch {
            showToast('Network error updating credits.', 'error');
        }
    };

    return (
        <>
            <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl shadow-[0_2px_15px_rgba(0,0,0,0.03)] dark:shadow-[0_2px_15px_rgba(0,0,0,0.2)] overflow-hidden">
                <ToastContainer toasts={toasts} onDismiss={dismissToast} />

                <div className="px-6 pt-6 pb-5 border-b border-[#e5e5e5] dark:border-white/5">
                    <div className="flex items-center justify-end mb-2">
                        {!loading && (
                            <span className="text-[10px] text-[#9aa0a6] font-medium uppercase tracking-tight">
                                Synced: {lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                            </span>
                        )}
                    </div>
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h3 className="text-[16px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                                <FiUsers className="w-4 h-4 text-[#2b83fa]" /> Registered Subaccounts
                            </h3>
                            <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">
                                Users from the Firestore users collection with profile and credit controls.
                            </p>
                        </div>
                        <div className="flex items-center gap-2 w-full sm:w-auto">
                            <div className="relative min-w-0 flex-1 sm:w-72">
                                <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] w-3.5 h-3.5" />
                                <input
                                    type="text"
                                    placeholder="Search users..."
                                    value={searchTerm}
                                    onChange={e => setSearchTerm(e.target.value)}
                                    className="w-full pl-9 pr-8 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] text-[#111111] dark:text-white placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all font-medium"
                                />
                                {searchTerm && (
                                    <button onClick={() => setSearchTerm('')} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors">
                                        <FiX className="w-3 h-3" />
                                    </button>
                                )}
                            </div>
                            <div ref={filterMenuRef} className="relative flex-shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setFilterMenuOpen(open => !open)}
                                    className="h-10 w-10 rounded-xl border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e5e5e5] dark:border-white/5 text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 flex items-center justify-center transition-all"
                                    title="Filter users"
                                    aria-label="Filter users"
                                    aria-expanded={filterMenuOpen}
                                    aria-haspopup="true"
                                >
                                    <FiFilter className="w-3.5 h-3.5" />
                                </button>

                                {filterMenuOpen && (
                                    <div className="absolute right-0 top-full z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1e2023] shadow-2xl p-4 animate-in zoom-in-95 fade-in duration-100">
                                        <div className="mb-3">
                                            <span className="text-[12px] font-black uppercase tracking-wider text-[#5f6368] dark:text-[#9aa0a6]">Filters</span>
                                        </div>

                                        <div className="space-y-3">
                                            <label className="block">
                                                <span className="block text-[11px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] mb-1.5 uppercase tracking-wider">Agency</span>
                                                <select
                                                    value={agencyFilter}
                                                    onChange={e => setAgencyFilter(e.target.value)}
                                                    className="w-full px-3 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 font-bold"
                                                >
                                                    <option value="all">All agencies</option>
                                                    {agencyOptions.map(agency => (
                                                        <option key={agency} value={agency}>{agency}</option>
                                                    ))}
                                                </select>
                                            </label>

                                            <label className="block">
                                                <span className="block text-[11px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] mb-1.5 uppercase tracking-wider">Status</span>
                                                <select
                                                    value={accountStatusFilter}
                                                    onChange={e => setAccountStatusFilter(e.target.value as AccountStatusFilter)}
                                                    className="w-full px-3 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 font-bold"
                                                >
                                                    {accountStatusFilters.map(filter => (
                                                        <option key={filter.id} value={filter.id}>{filter.label}</option>
                                                    ))}
                                                </select>
                                            </label>

                                            {/* Sorting is managed directly by clicking column headers */}
                                        </div>
                                    </div>
                                )}
                            </div>
                            <button
                                onClick={() => fetchAccounts(true)}
                                className="p-2 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-all border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl flex-shrink-0"
                                title="Refresh users"
                            >
                                <FiRefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
                            </button>
                        </div>
                    </div>
                </div>

                <div className="p-6">
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
                            <FiUsers className="w-8 h-8 mx-auto mb-3 opacity-30" />
                            <p className="text-[14px] font-semibold">No registered users yet.</p>
                        </div>
                    ) : filteredAccounts.length === 0 ? (
                        <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
                            <FiSearch className="w-8 h-8 mx-auto mb-3 opacity-30" />
                            <p className="text-[14px] font-semibold">No subaccounts match your filters.</p>
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto pb-4">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="border-b border-[#e5e5e5] dark:border-white/5">
                                            <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                                <button
                                                    onClick={() => handleSort('subaccount')}
                                                    className="flex items-center gap-1 hover:text-[#2b83fa] transition-colors group"
                                                >
                                                    SUBACCOUNT
                                                    <span className="opacity-50 group-hover:opacity-100 transition-opacity">
                                                        {sortField === 'subaccount' ? (
                                                            sortDir === 'asc' ? <FiChevronUp className="w-3 h-3" /> : <FiChevronDown className="w-3 h-3" />
                                                        ) : (
                                                            <FiChevronUp className="w-3 h-3 opacity-40" />
                                                        )}
                                                    </span>
                                                </button>
                                            </th>
                                            <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                                STATUS
                                            </th>
                                            <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                                <button
                                                    onClick={() => handleSort('agency')}
                                                    className="flex items-center gap-1 hover:text-[#2b83fa] transition-colors group"
                                                >
                                                    AGENCY
                                                    <span className="opacity-50 group-hover:opacity-100 transition-opacity">
                                                        {sortField === 'agency' ? (
                                                            sortDir === 'asc' ? <FiChevronUp className="w-3 h-3" /> : <FiChevronDown className="w-3 h-3" />
                                                        ) : (
                                                            <FiChevronUp className="w-3 h-3 opacity-40" />
                                                        )}
                                                    </span>
                                                </button>
                                            </th>
                                            <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                                EMAIL
                                            </th>

                                            <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                                <button
                                                    onClick={() => handleSort('credits')}
                                                    className="flex items-center gap-1 hover:text-[#2b83fa] transition-colors group"
                                                >
                                                    CREDITS
                                                    <span className="opacity-50 group-hover:opacity-100 transition-opacity">
                                                        {sortField === 'credits' ? (
                                                            sortDir === 'asc' ? <FiChevronUp className="w-3 h-3" /> : <FiChevronDown className="w-3 h-3" />
                                                        ) : (
                                                            <FiChevronUp className="w-3 h-3 opacity-40" />
                                                        )}
                                                    </span>
                                                </button>
                                            </th>
                                            <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                                FREE USED
                                            </th>
                                            <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                                SMS PROVIDER
                                            </th>
                                            <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider whitespace-nowrap">
                                                ACTIONS
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#f0f0f0] dark:divide-white/[0.03]">
                                        {currentAccounts.map(account => (
                                            <tr key={account.id} className="group hover:bg-[#f7f7f7] dark:hover:bg-white/[0.015] transition-colors">
                                                <td className="py-4 pr-4 min-w-[220px]">
                                                    <div className="flex flex-col gap-0.5">
                                                        <p className="font-bold text-[13px] text-[#111111] dark:text-white truncate max-w-[200px]">
                                                            {account.location_name || getAccountName(account)}
                                                        </p>
                                                        <p className="text-[10px] font-mono text-[#9aa0a6] truncate max-w-[200px]">
                                                            {account.location_id || account.active_location_id || '-'}
                                                        </p>
                                                    </div>
                                                </td>
                                                <td className="py-4 pr-4 min-w-[130px]">
                                                    {renderAccountStatusBadge(account)}
                                                </td>
                                                <td className="py-4 pr-4 text-[12px] font-bold text-[#111111] dark:text-white min-w-[170px]">
                                                    <div className="flex flex-col">
                                                        <span className="truncate max-w-[190px]">{getAgencyName(account)}</span>
                                                        {account.company_id && (
                                                            <span className="text-[10px] text-[#9aa0a6] font-medium truncate max-w-[190px]">{account.company_id}</span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="py-4 pr-4 text-[12px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] min-w-[190px]">{emptyValue(account.email)}</td>

                                                <td className="py-4 pr-4 min-w-[110px]">
                                                    {editingCreditId === account.id ? (
                                                        <div
                                                            ref={editContainerRef}
                                                            className="inline-flex items-center rounded-lg border border-[#d8dce3] dark:border-white/10 bg-white dark:bg-[#0d0e10] overflow-hidden shadow-sm"
                                                        >
                                                            <button
                                                                type="button"
                                                                onClick={() => setEditingCreditValue(prev => String(Math.max(0, (parseInt(prev) || 0) - 1)))}
                                                                className="px-2.5 py-1.5 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors border-r border-[#e0e0e0] dark:border-white/10"
                                                            >
                                                                <FiMinus className="w-3 h-3" />
                                                            </button>
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                autoFocus
                                                                value={editingCreditValue}
                                                                onChange={e => setEditingCreditValue(e.target.value)}
                                                                onKeyDown={e => {
                                                                    if (e.key === 'Enter') saveEditCredit(account);
                                                                    if (e.key === 'Escape') setEditingCreditId(null);
                                                                }}
                                                                className="w-14 text-center text-[13px] font-bold bg-transparent text-[#111111] dark:text-white focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                                            />
                                                            <button
                                                                type="button"
                                                                onClick={() => setEditingCreditValue(prev => String((parseInt(prev) || 0) + 1))}
                                                                className="px-2.5 py-1.5 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors border-l border-[#e0e0e0] dark:border-white/10"
                                                            >
                                                                <FiPlus className="w-3 h-3" />
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <button
                                                            onClick={() => startEditCredit(account)}
                                                            title="Click to edit credits"
                                                            className="flex flex-col items-start hover:bg-[#f0f7ff] dark:hover:bg-blue-900/10 rounded-lg px-2 py-1 transition-colors group/credits"
                                                        >
                                                            <span className="text-[13px] font-bold text-[#111111] dark:text-white group-hover/credits:text-[#2b83fa] transition-colors">{(account.credit_balance ?? account.credits ?? 0).toLocaleString()}</span>
                                                            <span className="text-[9px] text-[#9aa0a6] font-medium uppercase tracking-tight group-hover/credits:text-[#2b83fa]/60 transition-colors">click to edit</span>
                                                        </button>
                                                    )}
                                                </td>
                                                <td className="py-4 pr-4">{renderFreeUsage(account)}</td>
                                                <td className="py-4 pr-4 min-w-[130px]">
                                                    {account.sms_provider ? (
                                                        <div className="flex flex-col gap-0.5">
                                                            <div className="flex items-center gap-1.5">
                                                                <span className="text-[12px] font-bold text-[#111111] dark:text-white">
                                                                    {account.sms_provider}
                                                                </span>
                                                                {account.provider_balance_source && (
                                                                    <span className={`text-[8.5px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-md ${
                                                                        account.provider_balance_source === 'custom'
                                                                            ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-500 dark:text-blue-400'
                                                                            : 'bg-[#f0f0f0] dark:bg-white/5 text-[#9aa0a6]'
                                                                    }`}>
                                                                        {account.provider_balance_source === 'custom' ? 'Custom' : 'System'}
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <span className={`text-[11px] font-semibold ${
                                                                account.provider_credit_balance === null || account.provider_credit_balance === undefined
                                                                    ? 'text-[#9aa0a6]'
                                                                    : account.provider_credit_balance > 0
                                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                                        : 'text-red-500 dark:text-red-400'
                                                            }`}>
                                                                {account.provider_credit_balance !== null && account.provider_credit_balance !== undefined
                                                                    ? `${account.provider_credit_balance.toLocaleString()} credits`
                                                                    : '—'}
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <span className="text-[12px] text-[#9aa0a6]">—</span>
                                                    )}
                                                </td>
                                                <td className="py-4 pr-2 text-right min-w-[60px]">
                                                    <button
                                                        onClick={(event) => openActionMenu(account.id, event.currentTarget)}
                                                        className="p-2 rounded-xl text-[#6e6e73] hover:text-[#111111] dark:hover:text-white hover:bg-white dark:hover:bg-[#1a1b1e] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-sm transition-all"
                                                        title="More actions"
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
                                <div className="flex items-center justify-between mt-6 pt-4 border-t border-[#e5e5e5] dark:border-white/5">
                                    <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] font-medium">
                                        Showing <b className="text-[#111111] dark:text-white">{(currentPage - 1) * ITEMS_PER_PAGE + 1}</b> - <b className="text-[#111111] dark:text-white">{Math.min(currentPage * ITEMS_PER_PAGE, filteredAccounts.length)}</b> of <b className="text-[#111111] dark:text-white">{filteredAccounts.length}</b>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <button disabled={currentPage === 1} onClick={() => setCurrentPage(p => p - 1)} className="p-1.5 rounded-lg text-[#6e6e73] hover:bg-[#f0f0f0] dark:hover:bg-white/5 disabled:opacity-30 transition-colors"><FiChevronLeft className="w-4 h-4" /></button>
                                        {Array.from({ length: Math.min(5, totalPages - Math.floor((currentPage - 1) / 5) * 5) }, (_, i) => Math.floor((currentPage - 1) / 5) * 5 + 1 + i).map(page => (
                                            <button key={page} onClick={() => setCurrentPage(page)} className={`w-7 h-7 rounded-lg text-[12px] font-bold flex items-center justify-center transition-all ${currentPage === page ? 'bg-[#2b83fa] text-white shadow-sm' : 'text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f0f0f0] dark:hover:bg-white/5'}`}>{page}</button>
                                        ))}
                                        <button disabled={currentPage === totalPages} onClick={() => setCurrentPage(p => p + 1)} className="p-1.5 rounded-lg text-[#6e6e73] hover:bg-[#f0f0f0] dark:hover:bg-white/5 disabled:opacity-30 transition-colors"><FiChevronRight className="w-4 h-4" /></button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>

            {actionMenuId && (
                <div
                    ref={menuRef}
                    style={{ position: 'fixed', top: menuPos.top, right: menuPos.right, zIndex: 9999 }}
                    className="w-52 bg-white dark:bg-[#1e2023] border border-[#e5e5e5] dark:border-white/10 rounded-xl shadow-2xl overflow-hidden animate-in zoom-in-95 fade-in duration-100 py-1"
                >
                    {(() => {
                        const account = accounts.find(acc => acc.id === actionMenuId);
                        if (!account) return null;
                        return (
                            <>
                                <button
                                    onClick={() => { setProfileAccount(account); setActionMenuId(null); }}
                                    className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-[#111111] dark:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/[0.04] transition-colors text-left"
                                >
                                    <FiEye className="w-3.5 h-3.5 text-[#2b83fa]" /> View Profile
                                </button>
                                <button
                                    onClick={() => { void fetchReportForAccount(account, true); setActionMenuId(null); }}
                                    disabled={!account.location_id}
                                    className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-[#111111] dark:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/[0.04] transition-colors text-left disabled:opacity-40 disabled:cursor-not-allowed"
                                >
                                    <FiDownload className="w-3.5 h-3.5 text-emerald-500" /> Download Report
                                </button>
                                <div className="my-1 border-t border-[#e5e5e5] dark:border-white/5" />
                                <button
                                    onClick={() => {
                                        setConfirmAction({ type: 'monthly_credit_reset', account });
                                        setResetModalAmount(500);
                                        setResetModalToggle(account.monthly_reset_enabled !== false);
                                        setActionMenuId(null);
                                    }}
                                    className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-[#2b83fa] hover:bg-blue-50 dark:hover:bg-blue-900/10 transition-colors text-left"
                                >
                                    <FiRefreshCw className="w-3.5 h-3.5 text-[#2b83fa]" /> Reset Monthly Credits
                                </button>
                                <button
                                    onClick={() => {
                                        setConfirmAction({ type: 'reset', account });
                                        setActionMenuId(null);
                                    }}
                                    className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/10 transition-colors text-left"
                                >
                                    <FiRefreshCw className="w-3.5 h-3.5" /> Reset Subaccount
                                </button>
                                <button
                                    onClick={() => {
                                        setConfirmAction({ type: 'delete', account });
                                        setActionMenuId(null);
                                    }}
                                    className="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors text-left"
                                >
                                    <FiTrash2 className="w-3.5 h-3.5" /> Delete Account
                                </button>
                            </>
                        );
                    })()}
                </div>
            )}

            {profileAccount && (
                <AdminSubaccountProfile
                    account={profileAccount}
                    onClose={() => setProfileAccount(null)}
                    onSaved={handleProfileSaved}
                    onToggleActive={handleToggleActive}
                />
            )}

            {confirmAction && (
                <div className="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-md shadow-2xl animate-in zoom-in-95 duration-200">
                        {confirmAction.type === 'monthly_credit_reset' ? (
                            <>
                                <div className="flex items-center justify-between pb-4 border-b border-[#e5e5e5] dark:border-white/10 mb-5">
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 text-[#2b83fa] flex items-center justify-center flex-shrink-0">
                                            <FiRefreshCw className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <h3 className="text-[16px] font-bold text-[#111111] dark:text-white leading-tight">
                                                Reset Subaccount Credits
                                            </h3>
                                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5 font-medium truncate max-w-[240px]">
                                                {getAccountName(confirmAction.account)}
                                            </p>
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => setConfirmAction(null)}
                                        className="p-1.5 text-[#6e6e73] hover:text-[#111111] dark:hover:text-white rounded-lg hover:bg-[#f7f7f7] dark:hover:bg-white/5 transition-colors"
                                    >
                                        <FiX className="w-4 h-4" />
                                    </button>
                                </div>

                                <div className="space-y-5">
                                    <div className="p-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5 flex items-center justify-between">
                                        <span className="text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6]">Current Balance</span>
                                        <span className="text-[13px] font-extrabold text-[#111111] dark:text-white font-mono">
                                            {(confirmAction.account.credit_balance ?? confirmAction.account.credits ?? 0).toLocaleString()} credits
                                        </span>
                                    </div>

                                    <div>
                                        <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">
                                            New Reset Balance Amount
                                        </label>
                                        <div className="flex items-center gap-2">
                                            <div className="flex items-center flex-1 bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-white/10 rounded-xl overflow-hidden shadow-sm">
                                                <button
                                                    type="button"
                                                    onClick={() => setResetModalAmount(prev => Math.max(0, prev - 50))}
                                                    className="px-3 py-2.5 text-[#6e6e73] hover:bg-black/5 dark:hover:bg-white/5 transition-colors border-r border-[#e0e0e0] dark:border-white/10"
                                                >
                                                    <FiMinus className="w-4 h-4" />
                                                </button>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    max="99999"
                                                    value={resetModalAmount}
                                                    onChange={e => setResetModalAmount(Math.max(0, parseInt(e.target.value) || 0))}
                                                    className="w-full text-center text-[15px] font-bold font-mono bg-transparent text-[#111111] dark:text-white focus:outline-none"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => setResetModalAmount(prev => prev + 50)}
                                                    className="px-3 py-2.5 text-[#6e6e73] hover:bg-black/5 dark:hover:bg-white/5 transition-colors border-l border-[#e0e0e0] dark:border-white/10"
                                                >
                                                    <FiPlus className="w-4 h-4" />
                                                </button>
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-4 gap-2 mt-3">
                                            {[0, 100, 500, 1000].map(val => (
                                                <button
                                                    key={val}
                                                    type="button"
                                                    onClick={() => setResetModalAmount(val)}
                                                    className={`w-full py-2 rounded-xl text-[13px] font-bold transition-all ${
                                                        resetModalAmount === val
                                                            ? 'bg-[#2b83fa] text-white shadow-md shadow-blue-500/20'
                                                            : 'bg-[#f7f7f7] dark:bg-white/5 text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#e0e8ff] dark:hover:bg-blue-500/10 hover:text-[#2b83fa] dark:hover:text-[#2b83fa]'
                                                    }`}
                                                >
                                                    {val}
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="pt-2 border-t border-[#e5e5e5] dark:border-white/5 flex items-center justify-between">
                                        <div>
                                            <p className="text-[12.5px] font-bold text-[#111111] dark:text-white">Auto Monthly Reset</p>
                                            <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6]">Include in 1st-of-month reset routine</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setResetModalToggle(v => !v)}
                                            className={`relative flex-shrink-0 w-11 h-6 rounded-full transition-colors duration-300 focus:outline-none ${
                                                resetModalToggle ? 'bg-[#2b83fa]' : 'bg-gray-200 dark:bg-white/10'
                                            }`}
                                        >
                                            <span className={`absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-300 ${
                                                resetModalToggle ? 'translate-x-5' : 'translate-x-0'
                                            }`} />
                                        </button>
                                    </div>

                                    <div className="flex items-center gap-3 pt-3">
                                        <button
                                            type="button"
                                            onClick={() => setConfirmAction(null)}
                                            className="flex-1 px-4 py-3 text-[13px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-xl transition-colors"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleManageUserAction('monthly_credit_reset', confirmAction.account, {
                                                amount: resetModalAmount,
                                                monthly_reset_enabled: resetModalToggle
                                            })}
                                            disabled={actionLoading === `monthly_credit_reset:${confirmAction.account.id}`}
                                            className="flex-1 flex items-center justify-center gap-2 px-4 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-lg text-white rounded-xl font-bold text-[13px] transition-all disabled:opacity-60 shadow-md shadow-blue-500/20 active:scale-95"
                                        >
                                            {actionLoading === `monthly_credit_reset:${confirmAction.account.id}` ? (
                                                <FiRefreshCw className="w-4 h-4 animate-spin" />
                                            ) : (
                                                <FiRefreshCw className="w-4 h-4" />
                                            )}
                                            Reset to {resetModalAmount}
                                        </button>
                                    </div>
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="flex items-center justify-center mb-5">
                                    <div className={`w-14 h-14 rounded-2xl flex items-center justify-center ${
                                        confirmAction.type === 'delete'
                                            ? 'bg-red-100 dark:bg-red-500/20 text-red-500'
                                            : 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-300'
                                    }`}>
                                        {confirmAction.type === 'delete' ? <FiTrash2 className="w-7 h-7" /> : <FiRefreshCw className="w-7 h-7" />}
                                    </div>
                                </div>
                                <h3 className="text-[18px] font-bold text-[#111111] dark:text-white text-center mb-3">
                                    {confirmAction.type === 'delete' ? 'Delete Account?' : 'Reset Subaccount?'}
                                </h3>
                                <p className="text-[14px] text-[#6e6e73] dark:text-[#9aa0a6] text-center mb-6 leading-relaxed">
                                    {confirmAction.type === 'delete'
                                        ? `Permanently delete user profile and account for ${getAccountName(confirmAction.account)}?`
                                        : `Reset credits, free usage, and sender ID for ${getAccountName(confirmAction.account)}?`}
                                </p>
                                <div className="flex items-center gap-3">
                                    <button
                                        onClick={() => setConfirmAction(null)}
                                        className="flex-1 px-4 py-3 text-[14px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-xl transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        onClick={() => handleManageUserAction(confirmAction.type, confirmAction.account)}
                                        disabled={actionLoading === `${confirmAction.type}:${confirmAction.account.id}`}
                                        className={`flex-1 flex items-center justify-center gap-2 px-4 py-3 text-white rounded-xl font-bold text-[14px] transition-all disabled:opacity-60 ${
                                            confirmAction.type === 'delete'
                                                ? 'bg-red-600 hover:bg-red-700'
                                                : 'bg-amber-500 hover:bg-amber-600'
                                        }`}
                                    >
                                        {actionLoading === `${confirmAction.type}:${confirmAction.account.id}` ? (
                                            <FiRefreshCw className="w-4 h-4 animate-spin" />
                                        ) : confirmAction.type === 'delete' ? (
                                            <FiTrash2 className="w-4 h-4" />
                                        ) : (
                                            <FiRefreshCw className="w-4 h-4" />
                                        )}
                                        {confirmAction.type === 'delete' ? 'Delete' : 'Reset'}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}



            {selectedReportAccount && (() => {
                const monthOptions = getReportMonthOptions(reportTransactions);
                const selectedEventCount = getReportMonthCount(reportTransactions, reportSelectedMonth);
                const canDownloadReport = !isLoadingReport && selectedEventCount > 0;
                const selectedLabel = reportSelectedMonth === 'All' ? 'All Transactions' : getReportMonthLabel(reportSelectedMonth);

                return (
                    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl w-full max-w-lg shadow-2xl animate-in zoom-in-95 duration-200 overflow-hidden">
                            <div className="px-6 py-5 border-b border-[#e5e5e5] dark:border-white/10 flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2.5 text-[#111111] dark:text-white">
                                        <div className="w-9 h-9 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                                            <FiDownload className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <h3 className="text-[17px] font-bold leading-tight">Download Report</h3>
                                            <p className="text-[12px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5 truncate">
                                                {getAccountName(selectedReportAccount)}
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
                                        <p className="text-[13px] font-bold text-[#111111] dark:text-white">Loading transaction history...</p>
                                        <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6]">Fetching the same full history used by the user transaction report.</p>
                                    </div>
                                ) : reportTransactions.length === 0 ? (
                                    <div className="py-8 flex flex-col items-center justify-center gap-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5 text-center">
                                        <div className="w-11 h-11 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                                            <FiDownload className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <p className="text-[14px] font-bold text-[#111111] dark:text-white">No reportable events yet</p>
                                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">PDF download is disabled until this subaccount has credit activity.</p>
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
                                                    <option value="All">All Transactions ({reportTransactions.length} events)</option>
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
                                            onClick={() => generateMonthlyReport(reportSelectedMonth, reportTransactions, 'subaccount', getAccountName(selectedReportAccount), buildSubaccountReportProfile(selectedReportAccount))}
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
        </>
    );
};
