import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { FiUsers, FiSend, FiSettings, FiLogOut, FiLock, FiAlertCircle, FiEye, FiEyeOff, FiCopy, FiCheck, FiX, FiRefreshCw, FiKey, FiHome, FiClock, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiShieldOff, FiPlus, FiMinus, FiTrash2, FiChevronLeft, FiChevronRight, FiSearch, FiSun, FiMoon, FiMoreVertical, FiToggleLeft, FiFilter, FiPaperclip, FiExternalLink } from 'react-icons/fi';
import logoUrl from '../../assets/NOLA SMS PRO Logo.png';
import Antigravity from '../../components/ui/Antigravity';
import { useToast } from '../../hooks/useToast';
import { ToastContainer } from '../../components/ui/ToastContainer';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';

const ADMIN_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync



const StatusBadge: React.FC<{ status: string }> = ({ status }) => {
    const styles: Record<string, string> = {
        pending: 'bg-yellow-50 text-yellow-700 border-yellow-200 dark:bg-yellow-900/10 dark:text-yellow-400 dark:border-yellow-800/30',
        approved: 'bg-green-50 text-green-700 border-green-200 dark:bg-green-900/10 dark:text-green-400 dark:border-green-800/30',
        rejected: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/10 dark:text-red-400 dark:border-red-800/30',
        revoked: 'bg-gray-50 text-gray-700 border-gray-200 dark:bg-white/5 dark:text-gray-400 dark:border-white/10',
    };
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider border ${styles[status] || styles.pending}`}>
            {status}
        </span>
    );
};

const normalizeProvider = (req: any): 'system' | 'semaphore' | 'unisms' => {
    const provider = String(req?.provider || req?.provider_preference || '').toLowerCase();
    if (provider.includes('unisms')) return 'unisms';
    if (provider.includes('semaphore')) return 'semaphore';
    return 'semaphore';
};

const providerLabel = (provider: string) => {
    if (provider === 'unisms') return 'UniSMS';
    if (provider === 'system') return 'System';
    return 'Semaphore';
};

const detectProviderFromApiKey = (value: string): 'semaphore' | 'unisms' | null => {
    const normalized = value.trim().toLowerCase();
    if (!normalized) return null;
    if (/unisms|unismsapi|unis?ms[_\s-]?(api|key|token)/i.test(normalized)) return 'unisms';
    if (/semaphore|api\.semaphore\.co|semaphore[_\s-]?(api|key|token)/i.test(normalized)) return 'semaphore';

    const token = normalized
        .replace(/^bearer\s+/i, '')
        .split(/[?&\s"'<>]+/)
        .find((part) => part.includes('apikey=') || part.includes('api_key=') || part.length >= 24) || normalized;
    const cleanedToken = token.replace(/^.*(?:apikey|api_key)=/i, '').replace(/[^a-z0-9_-]/gi, '');

    if (/^sk_[a-z0-9-]{20,}$/i.test(cleanedToken) || /^uni[a-z0-9_-]{12,}$/i.test(cleanedToken) || /^usms[a-z0-9_-]{12,}$/i.test(cleanedToken)) return 'unisms';
    if (/^[a-z0-9]{32}$/i.test(cleanedToken) || /^sem[a-z0-9_-]{12,}$/i.test(cleanedToken)) return 'semaphore';

    return null;
};
const shouldShowProviderBadge = (req: any) => String(req?.status || 'pending').toLowerCase() !== 'pending';

const getRequestTimestamp = (req: any) => {
    const raw = req.created_at || req.createdAt || req.updated_at || req.date_created || '';
    const parsed = new Date(raw);
    return Number.isNaN(parsed.getTime()) ? 0 : parsed.getTime();
};

const formatRequestDate = (value?: string) => {
    if (!value) return 'Unknown';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    return parsed.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const getRequestReferenceId = (req: any) =>
    String(req?.reference_id || req?.request_reference_id || req?.request_ref || req?.id || 'Unknown');

const getDecisionDate = (req: any) => {
    const status = String(req?.status || '').toLowerCase();
    if (status === 'approved') return req.approved_at || req.approvedAt || req.decision_at || req.decisionAt || req.updated_at || req.updatedAt;
    if (status === 'rejected') return req.rejected_at || req.rejectedAt || req.decision_at || req.decisionAt || req.updated_at || req.updatedAt;
    return null;
};

const getDecisionLabel = (req: any) => {
    const status = String(req?.status || '').toLowerCase();
    if (status === 'approved') return 'Approval Date';
    if (status === 'rejected') return 'Rejection Date';
    return 'Decision Date';
};
const ProviderBadge: React.FC<{ provider: string }> = ({ provider }) => {
    const normalized = provider === 'unisms' ? 'unisms' : provider === 'system' ? 'system' : 'semaphore';
    const styles: Record<string, string> = {
        unisms: 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-900/10 dark:text-indigo-400 dark:border-indigo-800/30',
        semaphore: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30',
        system: 'bg-slate-50 text-slate-700 border-slate-200 dark:bg-white/5 dark:text-slate-400 dark:border-white/10',
    };
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${styles[normalized]}`}>
            {providerLabel(normalized)}
        </span>
    );
};

const RowStatus: React.FC<{ status: string }> = ({ status }) => {
    const normalized = String(status || 'pending').toLowerCase();
    const isApproved = normalized === 'approved';
    const isRejected = normalized === 'rejected';
    const isRevoked = normalized === 'revoked';

    if (isApproved) {
        return (
            <span className="inline-flex items-center gap-1.5 text-[11px] font-black text-emerald-600 dark:text-emerald-400">
                <span className="w-2 h-2 rounded-full bg-emerald-500 shadow-[0_0_0_3px_rgba(16,185,129,0.12)]" />
                Active
            </span>
        );
    }

    if (isRejected || isRevoked) {
        return (
            <span className="inline-flex items-center gap-1.5 text-[11px] font-black text-red-600 dark:text-red-400">
                <span className="w-2 h-2 rounded-full bg-red-500 shadow-[0_0_0_3px_rgba(239,68,68,0.12)]" />
                {isRevoked ? 'Revoked' : 'Rejected'}
            </span>
        );
    }

    return null;
};

export const AdminSenderRequests: React.FC = () => {
    const [requests, setRequests] = useState<SenderRequest[]>([]);
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);
    const [expandedId, setExpandedId] = useState<string | null>(null);
    const [rejectNote, setRejectNote] = useState('');
    const [apiKeyInput, setApiKeyInput] = useState('');
    const [actionLoading, setActionLoading] = useState<string | null>(null);
    const [showInputKey, setShowInputKey] = useState(false);
    const [actionPrompt, setActionPrompt] = useState<{ requestId: string; action: 'approved' | 'rejected' } | null>(null);
    const [approvalProvider, setApprovalProvider] = useState<'semaphore' | 'unisms'>('semaphore');
    const [filter, setFilter] = useState<'all' | 'pending' | 'approved' | 'rejected' | 'revoked'>('all');
    const [providerFilter, setProviderFilter] = useState<'all' | 'semaphore' | 'unisms' | 'system'>('all');
    const [sortBy, setSortBy] = useState<'newest' | 'oldest' | 'sender' | 'account'>('newest');
    const [searchTerm, setSearchTerm] = useState('');
    const [filterMenuOpen, setFilterMenuOpen] = useState(false);
    const [openActionMenuId, setOpenActionMenuId] = useState<string | null>(null);
    const [deleteConfirmId, setDeleteConfirmId] = useState<string | null>(null);
    const [revokeConfirmId, setRevokeConfirmId] = useState<string | null>(null);
    const filterMenuRef = useRef<HTMLDivElement>(null);
    const { toasts, showToast, dismissToast } = useToast();

    const [currentPage, setCurrentPage] = useState(1);
    const ITEMS_PER_PAGE = 10;

    useEffect(() => {
        setCurrentPage(1);
    }, [filter, providerFilter, sortBy, searchTerm]);

    useEffect(() => {
        if (!filterMenuOpen) return;
        const handleClick = (event: MouseEvent) => {
            if (filterMenuRef.current && !filterMenuRef.current.contains(event.target as Node)) {
                setFilterMenuOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClick);
        return () => document.removeEventListener('mousedown', handleClick);
    }, [filterMenuOpen]);

    useEffect(() => {
        if (!openActionMenuId) return;
        const closeMenu = () => setOpenActionMenuId(null);
        document.addEventListener('mousedown', closeMenu);
        return () => document.removeEventListener('mousedown', closeMenu);
    }, [openActionMenuId]);

    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    const fetchRequests = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        try {
            const [reqRes, accRes] = await Promise.all([
                adminFetch(ADMIN_API, { headers: getAdminAuthHeaders() }),
                adminFetch(`${ADMIN_API}?action=accounts`, { headers: getAdminAuthHeaders() })
            ]);
            
            const reqJson = await reqRes.json();
            const accJson = await accRes.json();
            
            if (reqJson.status === 'success') {
                setRequests(reqJson.data || []);
            } else {
                showToast(reqJson.message || 'Failed to load requests.', 'error');
            }

            if (accJson.status === 'success') {
                const mappedAccounts = (accJson.data || []).map((item: any) => {
                    if (item.data) return { id: item.id, ...item.data };
                    return item;
                }).filter((acc: any) => acc.id !== 'ghl' && acc.location_id);
                setAccounts(mappedAccounts);
            }
            setLastRefreshed(new Date());
        } catch {
            showToast('Network error. Could not reach the backend.', 'error');
        } finally {
            if (isInitial) setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchRequests(true);
        const timer = setInterval(() => fetchRequests(false), POLL_INTERVAL);
        return () => clearInterval(timer);
    }, [fetchRequests]);

    const doAction = async (action: string, requestId: string, extra: Record<string, string> = {}) => {
        setActionLoading(requestId + action);
        try {
            const res = await adminFetch(ADMIN_API, {
                method: 'POST',
                headers: getAdminAuthHeaders(),
                body: JSON.stringify({ request_id: requestId, status: action, ...extra }),
            });
            const json = await res.json();
            if (json.status === 'success') {
                showToast(json.message || 'Action completed.', 'success');
                fetchRequests();
                setExpandedId(null);
                setRejectNote('');
                setApiKeyInput('');
                setShowInputKey(false);
                setActionPrompt(null);
            } else {
                showToast(json.message || 'Action failed.', 'error');
            }
        } catch {
            showToast('Network error.', 'error');
        } finally {
            setActionLoading(null);
        }
    };

    const openRequest = (requestId: string) => {
        const request = requests.find(r => r.id === requestId);
        setExpandedId(requestId);
        setRejectNote(request?.admin_notes || '');
        setApiKeyInput('');
        setShowInputKey(false);
        setActionPrompt(null);
    };

    const openActionPrompt = (req: any, action: 'approved' | 'rejected') => {
        setApprovalProvider(normalizeProvider(req) === 'unisms' ? 'unisms' : 'semaphore');
        setRejectNote(req?.admin_notes || '');
        setApiKeyInput('');
        setShowInputKey(false);
        setActionPrompt({ requestId: req.id, action });
    };

    const requestStats = useMemo(() => {
        const byStatus = requests.reduce((acc: Record<string, number>, req: any) => {
            acc[req.status || 'pending'] = (acc[req.status || 'pending'] || 0) + 1;
            return acc;
        }, {});
        const byProvider = requests.reduce((acc: Record<string, number>, req: any) => {
            if (!shouldShowProviderBadge(req)) return acc;
            const provider = normalizeProvider(req);
            acc[provider] = (acc[provider] || 0) + 1;
            return acc;
        }, {});
        return {
            pending: byStatus.pending || 0,
            approved: byStatus.approved || 0,
            rejected: byStatus.rejected || 0,
            revoked: byStatus.revoked || 0,
            semaphore: byProvider.semaphore || 0,
            unisms: byProvider.unisms || 0,
            system: byProvider.system || 0,
        };
    }, [requests]);

    const filteredRequests = useMemo(() => {
        const lowSearch = searchTerm.toLowerCase().trim();
        const matched = requests.filter((req: any) => {
            const isStatusMatch = filter === 'all' || req.status === filter;
            const isProviderMatch = providerFilter === 'all' || (shouldShowProviderBadge(req) && normalizeProvider(req) === providerFilter);
            if (!isStatusMatch || !isProviderMatch) return false;

            if (!lowSearch) return true;
            const associatedAccount = accounts.find((a: any) => a.location_id === req.location_id);
            const locName = associatedAccount?.location_name || req.location_name || '';
            const agencyName = req.agency_name || req.company_id || '';

            return [
                req.requested_id,
                req.location_id,
                locName,
                agencyName,
                normalizeProvider(req),
                req.status,
            ].filter(Boolean).join(' ').toLowerCase().includes(lowSearch);
        });

        return [...matched].sort((a: any, b: any) => {
            if (sortBy === 'oldest') return getRequestTimestamp(a) - getRequestTimestamp(b);
            if (sortBy === 'sender') return String(a.requested_id || '').localeCompare(String(b.requested_id || ''));
            if (sortBy === 'account') {
                const aAccount = accounts.find((acc: any) => acc.location_id === a.location_id);
                const bAccount = accounts.find((acc: any) => acc.location_id === b.location_id);
                return String(aAccount?.location_name || a.location_name || '').localeCompare(String(bAccount?.location_name || b.location_name || ''));
            }
            return getRequestTimestamp(b) - getRequestTimestamp(a);
        });
    }, [accounts, filter, providerFilter, requests, searchTerm, sortBy]);

    const totalPages = Math.ceil(filteredRequests.length / ITEMS_PER_PAGE);
    const currentRequests = filteredRequests.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

    return (
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-6 shadow-sm">
            <ToastContainer toasts={toasts} onDismiss={dismissToast} />
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
                <div>
                    <h3 className="text-[16px] font-bold text-[#111111] dark:text-white">Sender ID Requests</h3>
                    <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">Review, approve, or reject sender name registration requests.</p>
                </div>
                <div className="flex items-center gap-2">
                    <div className="relative w-full sm:w-64">
                        <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] w-3.5 h-3.5" />
                        <input
                            type="text"
                            placeholder="Search requests..."
                            value={searchTerm}
                            onChange={e => setSearchTerm(e.target.value)}
                            className="w-full pl-9 pr-4 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] text-[#111111] dark:text-white placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all font-medium"
                        />
                        {searchTerm && (
                            <button 
                                onClick={() => setSearchTerm('')}
                                className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"
                            >
                                <FiX className="w-3 h-3" />
                            </button>
                        )}
                    </div>
                    <div ref={filterMenuRef} className="relative flex-shrink-0">
                        <button
                            type="button"
                            onClick={() => setFilterMenuOpen(open => !open)}
                            className={`relative h-9 w-9 rounded-xl border flex items-center justify-center transition-all ${
                                filterMenuOpen || filter !== 'all' || providerFilter !== 'all' || sortBy !== 'newest'
                                    ? 'bg-[#111111] text-white border-[#111111] dark:bg-white dark:text-[#111111] dark:border-white'
                                    : 'bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e5e5e5] dark:border-white/5 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10'
                            }`}
                            aria-label="Filter sender requests"
                            aria-expanded={filterMenuOpen}
                        >
                            <FiFilter className="w-3.5 h-3.5" />
                            {(filter !== 'all' || providerFilter !== 'all' || sortBy !== 'newest') && (
                                <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-[#2b83fa] ring-2 ring-white dark:ring-[#1a1b1e]" />
                            )}
                        </button>
                        {filterMenuOpen && (
                            <div className="absolute right-0 top-full z-30 mt-2 w-72 rounded-2xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1a1b1e] p-3 shadow-xl shadow-black/10 dark:shadow-black/40">
                                <div className="space-y-3">
                                    <div>
                                        <label className="block text-[10px] font-black uppercase tracking-widest text-[#9aa0a6] mb-1.5">Provider</label>
                                        <select
                                            value={providerFilter}
                                            onChange={(event) => setProviderFilter(event.target.value as any)}
                                            className="w-full appearance-none px-3 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] font-bold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                        >
                                            <option value="all">All Providers</option>
                                            <option value="semaphore">Semaphore</option>
                                            <option value="unisms">UniSMS</option>
                                            <option value="system">System</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-black uppercase tracking-widest text-[#9aa0a6] mb-1.5">Sort</label>
                                        <select
                                            value={sortBy}
                                            onChange={(event) => setSortBy(event.target.value as any)}
                                            className="w-full appearance-none px-3 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] font-bold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                        >
                                            <option value="newest">Newest first</option>
                                            <option value="oldest">Oldest first</option>
                                            <option value="sender">Sender A-Z</option>
                                            <option value="account">Account A-Z</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                    <button onClick={() => fetchRequests(true)} className="p-2 rounded-xl text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-all border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0d0e10]">
                        <FiRefreshCw className={`w-3.5 h-3.5 ${loading && !requests.length ? 'animate-spin' : ''}`} />
                    </button>
                </div>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                {[
                    { label: 'Pending Review', value: requestStats.pending, icon: <FiClock />, tone: 'text-amber-600 bg-amber-50 border-amber-100 dark:bg-amber-900/10 dark:text-amber-400 dark:border-amber-800/30' },
                    { label: 'Active Senders', value: requestStats.approved, icon: <FiShield />, tone: 'text-emerald-600 bg-emerald-50 border-emerald-100 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30' },
                    { label: 'Semaphore', value: requestStats.semaphore, icon: <FiKey />, tone: 'text-blue-600 bg-blue-50 border-blue-100 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30' },
                    { label: 'UniSMS', value: requestStats.unisms, icon: <FiSend />, tone: 'text-indigo-600 bg-indigo-50 border-indigo-100 dark:bg-indigo-900/10 dark:text-indigo-400 dark:border-indigo-800/30' },
                ].map(card => (
                    <div key={card.label} className={`rounded-xl border px-4 py-3 ${card.tone}`}>
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-wider opacity-75">{card.label}</p>
                                <p className="text-[22px] font-black text-[#111111] dark:text-white mt-1">{card.value.toLocaleString()}</p>
                            </div>
                            <div className="w-9 h-9 rounded-xl bg-white/70 dark:bg-white/5 flex items-center justify-center text-[17px]">
                                {card.icon}
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            <div className="rounded-2xl border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#111214] p-3 mb-4">
                <div className="flex items-center gap-2 overflow-x-auto pb-1 lg:pb-0 custom-scrollbar no-scrollbar">
                    {[
                        { id: 'all', label: 'All' },
                        { id: 'pending', label: 'Pending' },
                        { id: 'approved', label: 'Approved' },
                        { id: 'rejected', label: 'Rejected' },
                        { id: 'revoked', label: 'Revoked' },
                    ].map(pill => {
                        const isActive = filter === pill.id;
                        const count = pill.id === 'all' ? requests.length : requests.filter(r => r.status === pill.id).length;
                        return (
                            <button
                                key={pill.id}
                                onClick={() => { setFilter(pill.id as any); setCurrentPage(1); }}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all whitespace-nowrap border opacity-90 hover:opacity-100 ${
                                    isActive
                                        ? 'bg-[#111111] text-white dark:bg-white dark:text-[#111111] border-transparent shadow-sm'
                                        : 'bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#6e6e73] dark:text-[#9aa0a6] border-[#e5e5e5] dark:border-white/5 hover:text-[#111111] dark:hover:text-white'
                                }`}
                            >
                                <span>{pill.label}</span>
                                <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-black min-w-[18px] text-center ${isActive ? 'bg-white/20' : 'opacity-60'}`}>
                                    {count}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {loading ? (
                <div className="space-y-3">
                    {[1, 2, 3].map(i => (
                        <div key={i} className="h-16 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />
                    ))}
                </div>
            ) : requests.length === 0 ? (
                <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
                    <FiSend className="w-8 h-8 mx-auto mb-3 opacity-30" />
                    <p className="text-[14px] font-semibold">No sender requests yet.</p>
                </div>
            ) : filteredRequests.length === 0 ? (
                <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
                    <FiSearch className="w-8 h-8 mx-auto mb-3 opacity-30" />
                    <p className="text-[14px] font-semibold">No requests match the current filters.</p>
                </div>
            ) : (
                <div className="space-y-3">
                    {currentRequests.map(req => {
                                    const associatedAccount = accounts.find(a => a.location_id === req.location_id);
                                    const locName = associatedAccount?.location_name || req.location_name || 'Unknown Account';
                                    const agencyName = req.agency_name || req.company_id;
                                    const isActing = actionLoading?.startsWith(req.id);
                                    
                                    return (
                                        <div key={req.id} className="relative border border-[#e5e5e5] dark:border-white/5 rounded-xl overflow-visible transition-all hover:border-[#2b83fa]/30 dark:hover:border-[#2b83fa]/40 hover:shadow-sm">
                                            {/* Row Header */}
                                            <div
                                                className="flex flex-col lg:flex-row lg:items-center gap-4 px-4 py-3 bg-[#fafafa] dark:bg-[#111214] cursor-pointer hover:bg-[#f0f0f0] dark:hover:bg-[#161718] transition-colors"
                                                onClick={() => openRequest(req.id)}
                                            >
                                                <div className="flex items-center gap-3.5 flex-1 min-w-0 w-full">
                                                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] shadow-sm flex items-center justify-center text-[12px] font-black text-white flex-shrink-0 tracking-tighter">
                                                        {req.requested_id.substring(0, 2).toUpperCase()}
                                                    </div>
                                                    <div className="flex flex-col min-w-0">
                                                        <div className="flex items-center gap-2 mb-1 flex-wrap">
                                                            <span className="font-black text-[15px] text-[#2b83fa] dark:text-[#4da3ff] leading-none">{req.requested_id}</span>
                                                            {shouldShowProviderBadge(req) && <ProviderBadge provider={normalizeProvider(req)} />}
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <p className="text-[11px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] truncate uppercase tracking-tight max-w-[150px]">{locName}</p>
                                                            <span className="w-1 h-1 rounded-full bg-[#d1d5db]/50 dark:bg-gray-700/50"></span>
                                                            <p className="text-[10px] text-[#9aa0a6] truncate opacity-70">{req.location_id}</p>
                                                            {agencyName && (
                                                                <>
                                                                    <span className="w-1 h-1 rounded-full bg-[#d1d5db]/50 dark:bg-gray-700/50"></span>
                                                                    <div className="flex items-center gap-1.5 px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-50 dark:bg-purple-900/10 text-purple-600 dark:text-purple-400 border border-purple-100 dark:border-purple-800/20 truncate max-w-[100px]">
                                                                        <FiUsers className="w-2.5 h-2.5" />
                                                                        {agencyName}
                                                                    </div>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex items-center justify-between lg:justify-end gap-2 flex-shrink-0 w-full lg:w-auto">
                                                    {req.status === 'pending' && (
                                                        <>
                                                            <button
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    setApiKeyInput('');
                                                                    setShowInputKey(false);
                                                                    openActionPrompt(req, 'approved');
                                                                }}
                                                                disabled={!!isActing}
                                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500 text-white text-[11px] font-black hover:bg-emerald-600 disabled:opacity-50 transition-colors"
                                                                title="Approve sender"
                                                            >
                                                                <FiCheck className="w-3.5 h-3.5" /> Approve
                                                            </button>
                                                            <button
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    setRejectNote(req.admin_notes || '');
                                                                    openActionPrompt(req, 'rejected');
                                                                }}
                                                                disabled={!!isActing}
                                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-50 text-red-600 border border-red-100 dark:bg-red-900/10 dark:text-red-400 dark:border-red-800/30 text-[11px] font-black hover:bg-red-100 dark:hover:bg-red-900/20 disabled:opacity-50 transition-colors"
                                                                title="Reject sender"
                                                            >
                                                                <FiX className="w-3.5 h-3.5" /> Reject
                                                            </button>
                                                        </>
                                                    )}
                                                    {req.status !== 'pending' && <RowStatus status={req.status} />}
                                                    <div className="relative" onMouseDown={(event) => event.stopPropagation()} onClick={(event) => event.stopPropagation()}>
                                                        <button
                                                            type="button"
                                                            onClick={() => setOpenActionMenuId(openActionMenuId === req.id ? null : req.id)}
                                                            className="p-1.5 rounded-lg text-[#6e6e73] hover:text-[#111111] dark:hover:text-white hover:bg-black/5 dark:hover:bg-white/5 transition-all"
                                                            title="More options"
                                                            aria-label="More options"
                                                            aria-expanded={openActionMenuId === req.id}
                                                        >
                                                            <FiMoreVertical className="w-4 h-4" />
                                                        </button>
                                                        {openActionMenuId === req.id && (
                                                            <div className="absolute right-0 top-full z-40 mt-2 w-44 rounded-xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1a1b1e] p-1.5 shadow-xl shadow-black/10 dark:shadow-black/40">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => { setOpenActionMenuId(null); openRequest(req.id); }}
                                                                    className="w-full flex items-center gap-2 rounded-lg px-3 py-2 text-left text-[12px] font-bold text-[#111111] dark:text-white hover:bg-[#f7f7f7] dark:hover:bg-white/5"
                                                                >
                                                                    <FiEye className="w-3.5 h-3.5 text-[#6e6e73]" /> View details
                                                                </button>
                                                                {req.status === 'approved' && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => { setOpenActionMenuId(null); setRevokeConfirmId(req.id); }}
                                                                        className="w-full flex items-center gap-2 rounded-lg px-3 py-2 text-left text-[12px] font-bold text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/10"
                                                                    >
                                                                        <FiShieldOff className="w-3.5 h-3.5" /> Revoke
                                                                    </button>
                                                                )}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => { setOpenActionMenuId(null); setDeleteConfirmId(req.id); }}
                                                                    className="w-full flex items-center gap-2 rounded-lg px-3 py-2 text-left text-[12px] font-bold text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                                                                >
                                                                    <FiTrash2 className="w-3.5 h-3.5" /> Delete
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    );
                    })}

                    {/* Pagination Controls */}
                    {totalPages > 1 && (
                        <div className="flex items-center justify-between px-2 py-2 mt-4 bg-transparent">
                            <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] font-medium">
                                Showing <span className="font-bold text-[#111111] dark:text-white">{(currentPage - 1) * ITEMS_PER_PAGE + 1}</span> to <span className="font-bold text-[#111111] dark:text-white">{Math.min(currentPage * ITEMS_PER_PAGE, filteredRequests.length)}</span> of <span className="font-bold text-[#111111] dark:text-white">{filteredRequests.length}</span> entries
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

            {/* Sender Request Modal */}
            {expandedId && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-5 w-full max-w-xl shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {(() => {
                            const req = requests.find(r => r.id === expandedId);
                            if (!req) return null;
                            const isActing = actionLoading?.startsWith(req.id);
                            const associatedAccount = accounts.find(a => a.location_id === req.location_id);
                            const isFormatValid = /^[a-zA-Z0-9]{3,11}$/.test(req.requested_id || '');
                            const decisionDate = getDecisionDate(req);
                            
                            return (
                                <>
                                    <div className="flex items-center justify-between mb-4">
                                        <div className="flex items-center gap-3">
                                            <div className="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/10 flex items-center justify-center">
                                                <FiSend className="w-5 h-5 text-blue-500" />
                                            </div>
                                            <div>
                                                <h3 className="text-[16px] font-bold text-[#111111] dark:text-white leading-none">{req.requested_id}</h3>
                                                <p className="text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] mt-1 uppercase tracking-wide">
                                                    {associatedAccount?.location_name || req.location_name || 'Unknown Account'}
                                                </p>
                                            </div>
                                        </div>
                                        <button onClick={() => { setExpandedId(null); setShowInputKey(false); setActionPrompt(null); }} className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors self-start">
                                            <FiX className="w-5 h-5" />
                                        </button>
                                    </div>

                                    <div className="space-y-4 max-h-[72vh] overflow-y-auto custom-scrollbar pr-1">
                                        <div className="space-y-3">
                                            <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#111214] p-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Requested Sender</p>
                                                        <p className="font-black text-[20px] text-[#2b83fa] dark:text-[#4da3ff] truncate">{req.requested_id}</p>
                                                    </div>
                                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                                        <StatusBadge status={req.status} />
                                                        {shouldShowProviderBadge(req) && <ProviderBadge provider={normalizeProvider(req)} />}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] p-4 min-w-0">
                                                    <p className="text-[10px] font-black text-[#9aa0a6] uppercase tracking-widest mb-2">Reference ID</p>
                                                    <p className="text-[13px] font-black text-[#111111] dark:text-white break-all font-mono">{getRequestReferenceId(req)}</p>
                                                    <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">Use this for tracking and support</p>
                                                </div>
                                                <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] p-4 min-w-0">
                                                    <p className="text-[10px] font-black text-[#9aa0a6] uppercase tracking-widest mb-2">Account</p>
                                                    <p className="text-[14px] font-black text-[#111111] dark:text-white truncate">{associatedAccount?.location_name || req.location_name || 'Unknown Account'}</p>
                                                    <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6] truncate mt-1" title={req.location_id}>{req.location_id}</p>
                                                    {(req.agency_name || req.company_id) && (
                                                        <p className="text-[11px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6] truncate mt-2">{req.agency_name || req.company_id}</p>
                                                    )}
                                                </div>
                                                <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] p-4">
                                                    <p className="text-[10px] font-black text-[#9aa0a6] uppercase tracking-widest mb-2">Submitted</p>
                                                    <p className="text-[14px] font-black text-[#111111] dark:text-white">{formatRequestDate(req.created_at || req.createdAt || req.updated_at)}</p>
                                                    <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">Request timestamp</p>
                                                </div>
                                                {decisionDate && (
                                                    <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] p-4">
                                                        <p className="text-[10px] font-black text-[#9aa0a6] uppercase tracking-widest mb-2">{getDecisionLabel(req)}</p>
                                                        <p className="text-[14px] font-black text-[#111111] dark:text-white">{formatRequestDate(decisionDate)}</p>
                                                        <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">Latest request decision</p>
                                                    </div>
                                                )}
                                            </div>

                                            <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] p-4">
                                                <p className="text-[10px] font-black text-[#9aa0a6] uppercase tracking-widest mb-2">Purpose</p>
                                                <p className="text-[13px] text-[#111111] dark:text-white leading-relaxed">{req.purpose || 'No purpose provided.'}</p>
                                            </div>
                                            
                                            <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] p-4">
                                                <p className="text-[10px] font-black text-[#9aa0a6] uppercase tracking-widest mb-2">Sample Message</p>
                                                <p className="text-[13px] text-[#111111] dark:text-white italic bg-[#f7f7f7] dark:bg-[#0d0e10] p-3 rounded-lg border border-[#e5e5e5] dark:border-white/5">
                                                    &ldquo;{req.sample_message || 'No sample message provided.'}&rdquo;
                                                </p>
                                            </div>

                                            {/* Supporting Documents */}
                                            <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] p-4">
                                                <div className="flex items-center justify-between mb-2">
                                                    <p className="text-[10px] font-black text-[#9aa0a6] uppercase tracking-widest flex items-center gap-1.5">
                                                        <FiPaperclip className="w-3 h-3 text-[#2b83fa]" />
                                                        Supporting Documents
                                                    </p>
                                                    {Array.isArray(req.documents) && req.documents.length > 0 && (
                                                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#2b83fa]/10 text-[#2b83fa]">
                                                            {req.documents.length} {req.documents.length === 1 ? 'file' : 'files'} attached
                                                        </span>
                                                    )}
                                                </div>
                                                {Array.isArray(req.documents) && req.documents.length > 0 ? (
                                                    <div className="space-y-2 mt-1">
                                                        {req.documents.map((doc, idx) => {
                                                            const docName = doc.name || `Document_${idx + 1}`;
                                                            const docSizeLabel = doc.size
                                                                ? (doc.size < 1024 * 1024 ? `${(doc.size / 1024).toFixed(1)} KB` : `${(doc.size / (1024 * 1024)).toFixed(1)} MB`)
                                                                : '';
                                                            const docType = doc.type || 'application/octet-stream';
                                                            const raw = doc.dataUrl || doc.data || '';
                                                            const fullDataUrl = raw.startsWith('data:') ? raw : raw ? `data:${docType};base64,${raw}` : '';
                                                            const isImage = docType.startsWith('image/');

                                                            const handleView = () => {
                                                                if (!fullDataUrl) return;
                                                                const win = window.open();
                                                                if (win) {
                                                                    win.document.write(
                                                                        isImage
                                                                            ? `<title>${docName}</title><body style="margin:0;background:#0d0e10;display:grid;place-items:center;min-height:100vh;"><img src="${fullDataUrl}" style="max-width:95vw;max-height:95vh;object-fit:contain;border-radius:12px;" /></body>`
                                                                            : `<title>${docName}</title><body style="margin:0;"><iframe src="${fullDataUrl}" style="width:100vw;height:100vh;border:none;"></iframe></body>`
                                                                    );
                                                                } else {
                                                                    const a = document.createElement('a');
                                                                    a.href = fullDataUrl;
                                                                    a.download = docName;
                                                                    a.click();
                                                                }
                                                            };

                                                            return (
                                                                <div key={idx} className="flex items-center justify-between p-2.5 rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0d0e10] hover:border-[#2b83fa]/40 transition-colors">
                                                                    <div className="flex items-center gap-2.5 min-w-0 pr-2">
                                                                        <div className="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center text-[15px] flex-shrink-0">
                                                                            {isImage ? '🖼️' : docType === 'application/pdf' ? '📄' : '📎'}
                                                                        </div>
                                                                        <div className="min-w-0">
                                                                            <p className="text-[12px] font-bold text-[#111111] dark:text-white truncate">{docName}</p>
                                                                            {docSizeLabel && <p className="text-[10px] text-[#9aa0a6]">{docSizeLabel}</p>}
                                                                        </div>
                                                                    </div>
                                                                    {fullDataUrl ? (
                                                                        <button
                                                                            type="button"
                                                                            onClick={handleView}
                                                                            className="flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold bg-[#2b83fa]/10 text-[#2b83fa] hover:bg-[#2b83fa] hover:text-white transition-all flex-shrink-0"
                                                                        >
                                                                            <FiExternalLink className="w-3 h-3" /> View
                                                                        </button>
                                                                    ) : (
                                                                        <span className="text-[11px] text-[#9aa0a6] italic flex-shrink-0">No data</span>
                                                                    )}
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                ) : (
                                                    <p className="text-[12px] text-[#9aa0a6] italic mt-1">No supporting documents attached.</p>
                                                )}
                                            </div>

                                            {req.admin_notes && (
                                                <div className="rounded-xl border border-red-100 dark:border-red-800/30 bg-red-50 dark:bg-red-900/10 p-4">
                                                    <p className="text-[10px] font-black text-red-500 dark:text-red-300 uppercase tracking-widest mb-2">Admin Note</p>
                                                    <p className="text-[13px] text-red-700 dark:text-red-300 leading-relaxed">{req.admin_notes}</p>
                                                </div>
                                            )}
                                        </div>

                                        {/* ── Pending: Approve & Activate / Reject ── */}
                                        {req.status === 'pending' && (
                                            <div className="rounded-xl border border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#111214] p-4 space-y-3">
                                                {!isFormatValid && (
                                                    <p className="text-[11px] font-bold text-red-600 dark:text-red-400">Invalid sender format. This request cannot be approved.</p>
                                                )}

                                                <div className="grid grid-cols-2 gap-3">
                                                    <button
                                                        type="button"
                                                        disabled={!isFormatValid || !!isActing}
                                                        onClick={() => {
                                                            setApiKeyInput('');
                                                            setShowInputKey(false);
                                                            openActionPrompt(req, 'approved');
                                                        }}
                                                        className="flex items-center justify-center gap-2 py-3 rounded-xl text-[13px] font-bold bg-emerald-500 hover:bg-emerald-600 text-white transition-all shadow-sm disabled:opacity-50 disabled:shadow-none"
                                                    >
                                                        <FiCheck className="w-4 h-4" />
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        disabled={!!isActing}
                                                        onClick={() => {
                                                            setRejectNote(req.admin_notes || '');
                                                            openActionPrompt(req, 'rejected');
                                                        }}
                                                        className="flex items-center justify-center gap-2 py-3 rounded-xl text-[13px] font-bold bg-red-500 hover:bg-red-600 text-white transition-all shadow-sm disabled:opacity-50 disabled:shadow-none"
                                                    >
                                                        <FiX className="w-4 h-4" />
                                                        Reject
                                                    </button>
                                                </div>
                                            </div>
                                        )}

                                        {/* ── Approved: show confirmation ── */}
                                        {req.status === 'approved' && (
                                            <div className="space-y-4">
                                                <div className="flex items-center gap-3 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-800/30">
                                                    <div className="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                                                        <FiCheck className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
                                                    </div>
                                                    <p className="text-[13px] text-emerald-700 dark:text-emerald-400 font-medium leading-snug">
                                                        Sender ID is active. User can send messages via <strong>{req.requested_id}</strong>.
                                                    </p>
                                                </div>
                                                <button
                                                    disabled={!!isActing}
                                                    onClick={() => setRevokeConfirmId(req.id)}
                                                    className="flex items-center justify-center gap-2 w-full py-3 rounded-xl text-[13px] font-bold text-amber-700 bg-amber-50 hover:bg-amber-100 dark:text-amber-400 dark:bg-amber-900/10 dark:hover:bg-amber-900/20 border border-amber-200 dark:border-amber-800/30 transition-all"
                                                >
                                                    <FiShieldOff className="w-4 h-4" />
                                                    <span>Revoke Approval</span>
                                                </button>
                                            </div>
                                        )}

                                        {req.status === 'revoked' && (
                                            <div className="flex items-center gap-3 p-4 rounded-xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                                                <div className="w-8 h-8 rounded-full bg-white dark:bg-[#111214] flex items-center justify-center flex-shrink-0">
                                                    <FiShieldOff className="w-4 h-4 text-slate-600 dark:text-slate-400" />
                                                </div>
                                                <p className="text-[13px] text-slate-700 dark:text-slate-300 font-medium leading-snug">
                                                    Sender ID was revoked. The customer will fall back to the system sender unless another sender is approved.
                                                </p>
                                            </div>
                                        )}

                                    </div>
                                </>
                            );
                        })()}
                    </div>
                </div>
            )}

            {/* Revoke Confirmation Modal */}
            {revokeConfirmId && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="flex flex-col items-center text-center">
                            <div className="w-12 h-12 rounded-full bg-amber-50 dark:bg-amber-900/10 flex items-center justify-center mb-4">
                                <FiShieldOff className="w-6 h-6 text-amber-600 dark:text-amber-400" />
                            </div>
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white mb-2">Revoke Sender ID</h3>
                            <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mb-6 leading-relaxed">
                                This removes the sender from the customer's active sending options and switches them back to the system sender. The request history stays visible as revoked.
                            </p>
                            <div className="flex w-full gap-3">
                                <button
                                    onClick={() => setRevokeConfirmId(null)}
                                    className="flex-1 py-2.5 rounded-xl text-[13px] font-bold text-[#6e6e73] bg-[#f7f7f7] hover:bg-[#e5e5e5] dark:text-[#9aa0a6] dark:bg-white/5 dark:hover:bg-white/10 transition-colors"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={() => {
                                        doAction('revoked', revokeConfirmId);
                                        setRevokeConfirmId(null);
                                    }}
                                    className="flex-1 py-2.5 rounded-xl text-[13px] font-bold text-white bg-amber-600 hover:bg-amber-700 transition-colors shadow-sm"
                                >
                                    Revoke
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Approve / Reject Step Modal */}
            {actionPrompt && (() => {
                const req = requests.find(r => r.id === actionPrompt.requestId);
                if (!req) return null;
                const isApprove = actionPrompt.action === 'approved';
                const isActing = actionLoading?.startsWith(req.id);
                const apiKey = apiKeyInput.trim();
                const detectedProvider = detectProviderFromApiKey(apiKeyInput);
                const canApprove = apiKey.length > 0;
                const approvalKeyPayload = approvalProvider === 'unisms'
                    ? { api_key: apiKey, unisms_api_key: apiKey }
                    : { api_key: apiKey, semaphore_api_key: apiKey, nola_pro_api_key: apiKey };

                return (
                    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                            <div className="flex items-center justify-between mb-5">
                                <div className="min-w-0">
                                    <h3 className="text-[17px] font-bold text-[#111111] dark:text-white">
                                        {isApprove ? 'Approve Sender ID' : 'Reject Sender ID'}
                                    </h3>
                                    <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1 font-semibold truncate">{req.requested_id}</p>
                                </div>
                                <button
                                    onClick={() => { setActionPrompt(null); setShowInputKey(false); }}
                                    className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors"
                                >
                                    <FiX className="w-5 h-5" />
                                </button>
                            </div>

                            {isApprove ? (
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">
                                            Provider <span className="text-red-500">*</span>
                                        </label>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                            {[
                                                {
                                                    id: 'unisms' as const,
                                                    label: 'UniSMS',
                                                    description: 'Approve this sender with a required UniSMS API key.',
                                                },
                                                {
                                                    id: 'semaphore' as const,
                                                    label: 'Semaphore',
                                                    description: 'Approve this sender with a required Semaphore API key.',
                                                },
                                            ].map((option) => {
                                                const selected = approvalProvider === option.id;
                                                return (
                                                    <button
                                                        key={option.id}
                                                        type="button"
                                                        onClick={() => setApprovalProvider(option.id)}
                                                        className={`text-left rounded-xl border p-3 transition-all ${selected
                                                            ? 'border-[#111111] dark:border-white bg-[#eef5ff] dark:bg-white/10 shadow-sm'
                                                            : 'border-[#e0e0e0] dark:border-white/10 bg-[#f7f7f7] dark:bg-[#0d0e10] hover:border-[#2b83fa]/40'
                                                        }`}
                                                    >
                                                        <div className="flex items-center justify-between gap-2">
                                                            <p className="text-[12.5px] font-black text-[#111111] dark:text-white">{option.label}</p>
                                                            {detectedProvider === option.id && (
                                                                <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-emerald-600 dark:text-emerald-300">Detected</span>
                                                            )}
                                                        </div>
                                                        <p className="mt-1 text-[11px] font-semibold leading-snug text-[#6e6e73] dark:text-[#9aa0a6]">{option.description}</p>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                    <div>
                                        <label className="block text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">
                                            API Key <span className="text-red-500">*</span>
                                        </label>
                                        <div className="relative">
                                            <input
                                                type={showInputKey ? "text" : "password"}
                                                value={apiKeyInput}
                                                onChange={e => {
                                                    const nextValue = e.target.value;
                                                    setApiKeyInput(nextValue);
                                                    const detected = detectProviderFromApiKey(nextValue);
                                                    if (detected) setApprovalProvider(detected);
                                                }}
                                                onPaste={e => {
                                                    const pasted = e.clipboardData.getData('text');
                                                    const detected = detectProviderFromApiKey(pasted);
                                                    if (detected) setApprovalProvider(detected);
                                                }}
                                                placeholder="Required to approve"
                                                className="w-full pl-4 pr-12 py-3 text-[13px] rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-400/30 transition-shadow font-semibold"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowInputKey(!showInputKey)}
                                                className="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                            >
                                                {showInputKey ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                                            </button>
                                        </div>
                                    </div>
                                    <div className="rounded-xl border border-emerald-100 dark:border-emerald-800/30 bg-emerald-50 dark:bg-emerald-900/10 px-4 py-3">
                                        <p className="text-[12px] font-bold text-emerald-700 dark:text-emerald-300">Ready to approve</p>
                                        <p className="text-[12px] font-medium text-emerald-700/75 dark:text-emerald-300/75 mt-1 leading-relaxed">
                                            This will activate the requested sender ID for the selected account.
                                        </p>
                                    </div>
                                    <button
                                        disabled={!canApprove || !!isActing}
                                        onClick={() => doAction('approved', req.id, {
                                            provider: approvalProvider,
                                            provider_preference: `${approvalProvider}_custom`,
                                            ...approvalKeyPayload,
                                        })}
                                        className="w-full flex items-center justify-center gap-2 py-3 rounded-xl text-[13px] font-bold bg-emerald-500 hover:bg-emerald-600 text-white transition-all shadow-sm disabled:opacity-50 disabled:shadow-none"
                                    >
                                        <FiCheck className="w-4 h-4" />
                                        <span>{isActing ? 'Approving...' : 'Proceed to Approve'}</span>
                                    </button>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">Customer Note</label>
                                        <textarea
                                            value={rejectNote}
                                            onChange={e => setRejectNote(e.target.value)}
                                            rows={3}
                                            placeholder="Reason sent to the customer..."
                                            className="w-full px-4 py-3 text-[13px] rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-red-400/30 resize-none transition-shadow font-medium"
                                        />
                                    </div>
                                    <div className="rounded-xl border border-red-100 dark:border-red-800/30 bg-red-50 dark:bg-red-900/10 px-4 py-3">
                                        <p className="text-[12px] font-bold text-red-700 dark:text-red-300">Reject request</p>
                                        <p className="text-[12px] font-medium text-red-700/75 dark:text-red-300/75 mt-1 leading-relaxed">
                                            Add a clear customer-facing reason before rejecting this sender ID.
                                        </p>
                                    </div>
                                    <button
                                        disabled={!rejectNote.trim() || !!isActing}
                                        onClick={() => doAction('rejected', req.id, { note: rejectNote.trim(), rejection_note: rejectNote.trim() })}
                                        className="w-full flex items-center justify-center gap-2 py-3 rounded-xl text-[13px] font-bold bg-red-500 hover:bg-red-600 text-white transition-all shadow-sm disabled:opacity-50 disabled:shadow-none"
                                    >
                                        <FiX className="w-4 h-4" />
                                        <span>{isActing ? 'Rejecting...' : 'Proceed to Reject'}</span>
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                );
            })()}

            {/* Delete Confirmation Modal */}
            {deleteConfirmId && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="flex flex-col items-center text-center">
                            <div className="w-12 h-12 rounded-full bg-red-50 dark:bg-red-900/10 flex items-center justify-center mb-4">
                                <FiAlertCircle className="w-6 h-6 text-red-500" />
                            </div>
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white mb-2">Delete Request</h3>
                            <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mb-6">
                                This removes the request record. If this sender is active on the customer account, it will also be cleared.
                            </p>
                            <div className="flex w-full gap-3">
                                <button
                                    onClick={() => setDeleteConfirmId(null)}
                                    className="flex-1 py-2.5 rounded-xl text-[13px] font-bold text-[#6e6e73] bg-[#f7f7f7] hover:bg-[#e5e5e5] dark:text-[#9aa0a6] dark:bg-white/5 dark:hover:bg-white/10 transition-colors"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={() => {
                                        doAction('deleted', deleteConfirmId);
                                        setDeleteConfirmId(null);
                                    }}
                                    className="flex-1 py-2.5 rounded-xl text-[13px] font-bold text-white bg-red-500 hover:bg-red-600 transition-colors shadow-sm"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

