// @ts-nocheck
import React, { useState, useEffect, useCallback } from 'react';
import { FiUsers, FiSend, FiSettings, FiLogOut, FiLock, FiAlertCircle, FiEye, FiEyeOff, FiCopy, FiCheck, FiX, FiRefreshCw, FiKey, FiHome, FiClock, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiPlus, FiMinus, FiTrash2, FiChevronLeft, FiChevronRight, FiSearch, FiSun, FiMoon, FiMoreVertical, FiToggleLeft } from 'react-icons/fi';
import logoUrl from '../assets/NOLA SMS PRO Logo.png';
import Antigravity from '../components/ui/Antigravity';

const ADMIN_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync

// ─── Types ───────────────────────────────────────────────────────────────────

interface SenderRequest {
    id: string;
    location_id: string;
    requested_id: string;
    purpose?: string;
    sample_message?: string;
    status: 'pending' | 'approved' | 'rejected';
    rejection_note?: string;
    created_at?: string;
    location_name?: string;
}

interface Account {
    id: string;
    location_id: string;
    location_name?: string;
    approved_sender_id?: string;
    nola_pro_api_key?: string;
    api_key?: string;
    semaphore_api_key?: string;
    credits?: number;
    credit_balance?: number;
    free_usage_count?: number;
    free_credits_total?: number;
}

interface AdminLayoutProps {
    darkMode: boolean;
    toggleDarkMode: () => void;
}

// ─── Admin Login ─────────────────────────────────────────────────────────────


export const AdminSenderRequests: React.FC = () => {
    const [requests, setRequests] = useState<SenderRequest[]>([]);
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [expandedId, setExpandedId] = useState<string | null>(null);
    const [rejectNote, setRejectNote] = useState('');
    const [apiKeyInput, setApiKeyInput] = useState('');
    const [actionLoading, setActionLoading] = useState<string | null>(null);
    const [successMsg, setSuccessMsg] = useState<string | null>(null);
    const [showApiKey, setShowApiKey] = useState(false);
    const [showInputKey, setShowInputKey] = useState(false);
    const [filter, setFilter] = useState<'all' | 'pending' | 'approved' | 'rejected'>('all');
    const [searchTerm, setSearchTerm] = useState('');
    const [deleteConfirmId, setDeleteConfirmId] = useState<string | null>(null);

    const [currentPage, setCurrentPage] = useState(1);
    const ITEMS_PER_PAGE = 10;

    useEffect(() => {
        setCurrentPage(1);
    }, [filter, searchTerm]);

    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    const fetchRequests = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        setError(null);
        try {
            const [reqRes, accRes] = await Promise.all([
                fetch(ADMIN_API),
                fetch(`${ADMIN_API}?action=accounts`)
            ]);
            
            const reqJson = await reqRes.json();
            const accJson = await accRes.json();
            
            if (reqJson.status === 'success') {
                setRequests(reqJson.data || []);
            } else {
                setError(reqJson.message || 'Failed to load requests.');
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
            setError('Network error. Could not reach the backend.');
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
            const res = await fetch(ADMIN_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ request_id: requestId, status: action, ...extra }),
            });
            const json = await res.json();
            if (json.status === 'success') {
                setSuccessMsg(json.message || 'Action completed.');
                setTimeout(() => setSuccessMsg(null), 3000);
                fetchRequests();
                setExpandedId(null);
                setRejectNote('');
                setApiKeyInput('');
                setShowApiKey(false);
                setShowInputKey(false);
            } else {
                setError(json.message || 'Action failed.');
            }
        } catch {
            setError('Network error.');
        } finally {
            setActionLoading(null);
        }
    };

    return (
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-6 shadow-sm">
            <div className="flex items-center justify-end mb-2">
                {!loading && (
                    <span className="text-[10px] text-[#9aa0a6] font-medium uppercase tracking-tight">
                        Updated: {lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                    </span>
                )}
            </div>
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
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
                    <button onClick={() => fetchRequests(true)} className="p-2 rounded-xl text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-all border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0d0e10]">
                        <FiRefreshCw className={`w-3.5 h-3.5 ${loading && !requests.length ? 'animate-spin' : ''}`} />
                    </button>
                </div>
            </div>

            {/* Filter Pills with Improved UI */}
            <div className="flex items-center gap-2 mb-6 overflow-x-auto pb-2 custom-scrollbar no-scrollbar">
                {[
                    { id: 'all', label: 'All', icon: null, color: 'blue' },
                    { id: 'pending', label: 'Pending', icon: <FiClock size={12} />, color: 'amber' },
                    { id: 'approved', label: 'Approved', icon: <FiCheck size={12} />, color: 'emerald' },
                    { id: 'rejected', label: 'Rejected', icon: <FiX size={12} />, color: 'red' },
                ].map(pill => {
                    const isActive = filter === pill.id;
                    const count = pill.id === 'all' ? requests.length : requests.filter(r => r.status === pill.id).length;
                    
                    const colorMap: Record<string, any> = {
                        blue: { active: 'bg-blue-600 text-white shadow-blue-500/25', inactive: 'bg-blue-50/50 dark:bg-blue-500/5 text-blue-600 dark:text-blue-400 border-blue-100 dark:border-blue-500/10 hover:bg-blue-100/50' },
                        amber: { active: 'bg-amber-500 text-white shadow-amber-500/25', inactive: 'bg-amber-50/50 dark:bg-amber-500/5 text-amber-600 dark:text-amber-400 border-amber-100 dark:border-amber-500/10 hover:bg-amber-100/50' },
                        emerald: { active: 'bg-emerald-600 text-white shadow-emerald-500/25', inactive: 'bg-emerald-50/50 dark:bg-emerald-500/5 text-emerald-600 dark:text-emerald-400 border-emerald-100 dark:border-emerald-500/10 hover:bg-emerald-100/50' },
                        red: { active: 'bg-red-600 text-white shadow-red-500/25', inactive: 'bg-red-50/50 dark:bg-red-500/5 text-red-600 dark:text-red-400 border-red-100 dark:border-red-500/10 hover:bg-red-100/50' },
                    };

                    const theme = colorMap[pill.color];

                    return (
                        <button
                            key={pill.id}
                            onClick={() => { setFilter(pill.id as any); setCurrentPage(1); }}
                            className={`flex items-center gap-2 px-3.5 py-2 rounded-xl text-[12px] font-bold transition-all whitespace-nowrap border ${
                                isActive 
                                    ? `${theme.active} border-transparent scale-[1.02]` 
                                    : `${theme.inactive} opacity-80 hover:opacity-100`
                            }`}
                        >
                            {pill.icon}
                            <span>{pill.label}</span>
                            <span className={`flex items-center justify-center px-1.5 py-0.5 rounded-full text-[10px] font-black min-w-[20px] ${
                                isActive 
                                    ? 'bg-white/20 text-white' 
                                    : 'bg-black/5 dark:bg-white/10 text-current opacity-70'
                            }`}>
                                {count}
                            </span>
                        </button>
                    );
                })}
            </div>

            {successMsg && (
                <div className="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-800/30 text-green-700 dark:text-green-400 text-[13px] font-medium">
                    <FiCheck className="w-4 h-4 flex-shrink-0" /> {successMsg}
                </div>
            )}

            {error && (
                <div className="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/20 text-red-600 dark:text-red-400 text-[13px] font-medium">
                    <FiAlertCircle className="w-4 h-4 flex-shrink-0" /> {error}
                </div>
            )}

            {loading ? (
                <div className="space-y-3">
                    {[1, 2, 3].map(i => (
                        <div key={i} className="h-16 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />
                    ))}
                </div>
            ) : requests.length === 0 ? (
                <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
                    <FiSend className="w-8 h-8 mx-auto mb-3 opacity-30" />
                    <p className="text-[14px] font-semibold">No sender requests found.</p>
                </div>
            ) : (
                <div className="space-y-3">
                    {(() => {
                        const lowSearch = searchTerm.toLowerCase().trim();
                        const filteredRequests = requests.filter(req => {
                            const isStatusMatch = filter === 'all' || req.status === filter;
                            if (!isStatusMatch) return false;
                            
                            if (!lowSearch) return true;
                            const associatedAccount = accounts.find(a => a.location_id === req.location_id);
                            const locName = associatedAccount?.location_name || req.location_name || '';
                            
                            return (
                                req.requested_id.toLowerCase().includes(lowSearch) ||
                                req.location_id.toLowerCase().includes(lowSearch) ||
                                locName.toLowerCase().includes(lowSearch)
                            );
                        });
                        const totalPages = Math.ceil(filteredRequests.length / ITEMS_PER_PAGE);
                        const currentRequests = filteredRequests.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

                        return (
                            <>
                                {currentRequests.map(req => {
                                    const associatedAccount = accounts.find(a => a.location_id === req.location_id);
                                    const locName = associatedAccount?.location_name || req.location_name || 'Unknown Account';
                                    
                                    return (
                                        <div key={req.id} className="border border-[#e5e5e5] dark:border-white/5 rounded-xl overflow-hidden transition-all">
                                            {/* Row Header */}
                                            <div
                                                className="flex items-center gap-4 px-4 py-3 bg-[#fafafa] dark:bg-[#111214] cursor-pointer hover:bg-[#f0f0f0] dark:hover:bg-[#161718] transition-colors"
                                                onClick={() => setExpandedId(req.id)}
                                            >
                                                <div className="flex items-center gap-3.5 flex-1 min-w-0">
                                                    <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] shadow-sm flex items-center justify-center text-[12px] font-black text-white flex-shrink-0 font-mono tracking-tighter">
                                                        {req.requested_id.substring(0, 2).toUpperCase()}
                                                    </div>
                                                    <div className="flex flex-col min-w-0">
                                                        <div className="flex items-center gap-2 mb-1 flex-wrap">
                                                            <span className="font-black text-[15px] text-[#2b83fa] dark:text-[#4da3ff] font-mono leading-none">{req.requested_id}</span>
                                                            <StatusBadge status={req.status} />
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <p className="text-[11px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] truncate uppercase tracking-tight max-w-[150px]">{locName}</p>
                                                            <span className="w-1 h-1 rounded-full bg-[#d1d5db]/50 dark:bg-gray-700/50"></span>
                                                            <p className="text-[10px] text-[#9aa0a6] font-mono truncate opacity-70">{req.location_id}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 flex-shrink-0">
                                                    {/* Approved: show masked key inline */}
                                                    {req.status === 'approved' && (
                                                        <span className="text-[11px] font-mono text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/10 px-2 py-1 rounded-lg border border-emerald-200 dark:border-emerald-800/30">
                                                            <FiKey className="w-3 h-3 inline mr-1" />Active
                                                        </span>
                                                    )}
                                                    <button
                                                        onClick={(e) => { e.stopPropagation(); setExpandedId(req.id); }}
                                                        className="p-1.5 rounded-lg text-[#6e6e73] hover:bg-black/5 dark:hover:bg-white/5 transition-all"
                                                        title="View Details"
                                                    >
                                                        <FiEye className="w-4 h-4" />
                                                    </button>
                                                    <button
                                                        onClick={(e) => { e.stopPropagation(); setDeleteConfirmId(req.id); }}
                                                        className="p-1.5 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all"
                                                        title="Delete Request"
                                                    >
                                                        <FiTrash2 className="w-4 h-4" />
                                                    </button>
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
                            </>
                        );
                    })()}
                </div>
            )}

            {/* Sender Request Modal */}
            {expandedId && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-xl shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {(() => {
                            const req = requests.find(r => r.id === expandedId);
                            if (!req) return null;
                            const isActing = actionLoading?.startsWith(req.id);
                            const associatedAccount = accounts.find(a => a.location_id === req.location_id);
                            
                            return (
                                <>
                                    <div className="flex items-center justify-between mb-5">
                                        <div className="flex items-center gap-3">
                                            <div className="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/10 flex items-center justify-center">
                                                <FiSend className="w-5 h-5 text-blue-500" />
                                            </div>
                                            <div>
                                                <h3 className="text-[16px] font-bold text-[#111111] dark:text-white font-mono leading-none">{req.requested_id}</h3>
                                                <p className="text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] mt-1 uppercase tracking-wide">
                                                    {associatedAccount?.location_name || req.location_name || 'Unknown Account'}
                                                </p>
                                            </div>
                                        </div>
                                        <button onClick={() => { setExpandedId(null); setShowApiKey(false); setShowInputKey(false); }} className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors self-start">
                                            <FiX className="w-5 h-5" />
                                        </button>
                                    </div>

                                    <div className="space-y-4 max-h-[70vh] overflow-y-auto custom-scrollbar pr-2">
                                        {/* Status & Highlights Banner */}
                                        <div className="grid grid-cols-2 gap-px bg-[#e5e5e5] dark:bg-white/10 rounded-xl overflow-hidden border border-[#e5e5e5] dark:border-white/5">
                                            <div className="bg-[#f7f7f7] dark:bg-[#111214] p-4 flex flex-col">
                                                <span className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1.5 opacity-70">Sender ID</span>
                                                <span className="font-black text-[16px] text-[#2b83fa] dark:text-[#4da3ff] font-mono leading-none truncate">{req.requested_id}</span>
                                            </div>
                                            <div className="bg-[#f7f7f7] dark:bg-[#111214] p-4 flex flex-col">
                                                <span className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1.5 opacity-70">Status</span>
                                                <div className="flex items-center">
                                                    <StatusBadge status={req.status} />
                                                </div>
                                            </div>
                                        </div>

                                        {/* Request Details Grid */}
                                        <div className="bg-[#f7f7f7] dark:bg-[#111214] rounded-xl p-4 gap-y-4 border border-[#e5e5e5] dark:border-white/5 flex flex-col">
                                            <div>
                                                <p className="text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">Subaccount</p>
                                                <div className="flex items-center gap-2 bg-white dark:bg-[#1a1b1e] p-2.5 rounded-lg border border-[#e5e5e5] dark:border-white/5">
                                                    <div className="w-6 h-6 rounded-full bg-[#f0f0f0] dark:bg-white/5 flex items-center justify-center text-[10px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] flex-shrink-0">
                                                        {(associatedAccount?.location_name || req.location_name || 'U').substring(0, 2).toUpperCase()}
                                                    </div>
                                                    <span className="text-[13px] font-bold text-[#111111] dark:text-white leading-none">{associatedAccount?.location_name || req.location_name || 'Unknown Account'}</span>
                                                </div>
                                            </div>
                                            
                                            <div className="grid grid-cols-2 gap-4 pt-3 border-t border-[#e5e5e5] dark:border-white/5">
                                                <div>
                                                    <p className="text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1">Location ID</p>
                                                    <p className="text-[12px] font-mono font-medium text-[#111111] dark:text-white truncate bg-white dark:bg-[#1a1b1e] p-2.5 rounded-lg border border-[#e5e5e5] dark:border-white/5" title={req.location_id}>
                                                        {req.location_id}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1">Submitted On</p>
                                                    <p className="text-[12px] font-medium text-[#111111] dark:text-white bg-white dark:bg-[#1a1b1e] p-2.5 rounded-lg border border-[#e5e5e5] dark:border-white/5 h-[38px] flex items-center">
                                                        {req.created_at || 'Unknown'}
                                                    </p>
                                                </div>
                                            </div>

                                            {/* Existing API Key from Associated Account */}
                                            {(() => {
                                                const apiKey = associatedAccount?.nola_pro_api_key || associatedAccount?.api_key || associatedAccount?.semaphore_api_key;
                                                return associatedAccount && apiKey && (
                                                    <div className="pt-3 border-t border-[#e5e5e5] dark:border-white/5">
                                                        <div className="flex items-center justify-between mb-1">
                                                            <p className="text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Current API Key</p>
                                                            <button 
                                                                onClick={(e) => { e.preventDefault(); setShowApiKey(!showApiKey); }}
                                                                className="text-[11px] text-[#2b83fa] font-semibold hover:underline"
                                                            >
                                                                {showApiKey ? "Hide" : "Show"}
                                                            </button>
                                                        </div>
                                                        <p className="text-[13px] font-mono text-[#111111] dark:text-white bg-white dark:bg-[#1a1b1e] p-2.5 rounded-lg border border-[#e5e5e5] dark:border-white/5 break-all">
                                                            {showApiKey ? apiKey : "••••••••••••••••••••••••••••••••••••••••••••••"}
                                                        </p>
                                                    </div>
                                                );
                                            })()}

                                            {req.purpose && (
                                                <div className="pt-3 border-t border-[#e5e5e5] dark:border-white/5">
                                                    <p className="text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1">Purpose</p>
                                                    <p className="text-[13px] text-[#111111] dark:text-white leading-relaxed">{req.purpose}</p>
                                                </div>
                                            )}
                                            
                                            {req.sample_message && (
                                                <div className="pt-3 border-t border-[#e5e5e5] dark:border-white/5">
                                                    <p className="text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1">Sample Message</p>
                                                    <p className="text-[13px] text-[#111111] dark:text-white italic bg-white dark:bg-[#1a1b1e] p-3 rounded-lg border border-[#e5e5e5] dark:border-white/5">"{req.sample_message}"</p>
                                                </div>
                                            )}
                                        </div>

                                        {/* ── Pending: Approve & Activate / Reject ── */}
                                        {req.status === 'pending' && (
                                            <div className="space-y-4 pt-4 border-t border-[#e5e5e5] dark:border-white/5">
                                                <div className="flex items-center gap-2 mb-1">
                                                    <div className="h-6 w-1 bg-[#2b83fa] rounded-full"></div>
                                                    <h4 className="text-[12px] font-black text-[#111111] dark:text-white uppercase tracking-wider">Admin Review Action</h4>
                                                </div>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                    {/* API Key for Approval */}
                                                    <div>
                                                    <label className="block text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">Semaphore API Key</label>
                                                    <div className="relative">
                                                        <input
                                                            type={showInputKey ? "text" : "password"}
                                                            value={apiKeyInput}
                                                            onChange={e => setApiKeyInput(e.target.value)}
                                                            placeholder="Enter Semaphore API Key..."
                                                            className="w-full pl-4 pr-12 py-3 text-[13px] rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow font-mono"
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

                                                    {/* Optional Rejection Note */}
                                                    <div>
                                                        <label className="block text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">Rejection Note (Optional)</label>
                                                        <textarea
                                                            value={rejectNote}
                                                            onChange={e => setRejectNote(e.target.value)}
                                                            rows={2}
                                                            placeholder="Reason for rejection..."
                                                            className="w-full px-4 py-2.5 text-[12px] rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-red-400/30 resize-none transition-shadow h-[40px]"
                                                        />
                                                    </div>
                                                </div>

                                                {/* Action Buttons - Equal Size */}
                                                <div className="grid grid-cols-2 gap-3 pt-2">
                                                    <button
                                                        disabled={!apiKeyInput.trim() || !!isActing}
                                                        onClick={() => doAction('approved', req.id, { api_key: apiKeyInput.trim() })}
                                                        className="flex items-center justify-center gap-2 py-3 rounded-xl text-[13px] font-bold bg-emerald-500 hover:bg-emerald-600 text-white transition-all shadow-sm disabled:opacity-50 disabled:shadow-none"
                                                    >
                                                        <FiCheck className="w-4 h-4" />
                                                        <span>Approve</span>
                                                    </button>
                                                    <button
                                                        disabled={!!isActing}
                                                        onClick={() => doAction('rejected', req.id, { rejection_note: rejectNote })}
                                                        className="flex items-center justify-center gap-2 py-3 rounded-xl text-[13px] font-bold bg-red-50 hover:bg-red-100 text-red-600 dark:bg-red-900/10 dark:hover:bg-red-900/20 dark:text-red-400 border border-red-200 dark:border-red-800/30 transition-all disabled:opacity-50"
                                                    >
                                                        <FiX className="w-4 h-4" />
                                                        <span>Reject</span>
                                                    </button>
                                                </div>
                                            </div>
                                        )}

                                        {/* ── Approved: show confirmation ── */}
                                        {req.status === 'approved' && (
                                            <div className="flex items-center gap-3 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-800/30">
                                                <div className="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                                                    <FiCheck className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
                                                </div>
                                                <p className="text-[13px] text-emerald-700 dark:text-emerald-400 font-medium leading-snug">
                                                    Sender ID is active. User can send messages via <strong>{req.requested_id}</strong>.
                                                </p>
                                            </div>
                                        )}

                                        {/* ── Approved/Rejected: show account key toggle if available ── */}
                                        {(() => {
                                            const apiKey = associatedAccount?.nola_pro_api_key || associatedAccount?.api_key || associatedAccount?.semaphore_api_key;
                                            return (req.status === 'approved' || req.status === 'rejected') && associatedAccount && apiKey && (
                                                <div className="pt-4 border-t border-[#e5e5e5] dark:border-white/5 mt-4">
                                                    <div className="flex items-center justify-between p-4 rounded-xl bg-[#f7f7f7] dark:bg-[#111214] border border-[#e5e5e5] dark:border-white/5">
                                                        <span className="text-[13px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] whitespace-nowrap">Account API Key</span>
                                                        
                                                        <div className="flex items-center gap-3">
                                                            <span className="text-[14px] font-mono text-[#2b83fa] dark:text-[#4da3ff] truncate max-w-[200px] md:max-w-none">
                                                                {showApiKey 
                                                                    ? apiKey 
                                                                    : apiKey.length > 12 
                                                                        ? `${apiKey.substring(0, 6)}•••${apiKey.substring(apiKey.length - 6)}`
                                                                        : '••••••••••••'}
                                                            </span>
                                                            
                                                            <div className="flex items-center gap-1.5 pl-3 border-l border-[#e5e5e5] dark:border-white/10">
                                                                <button 
                                                                    onClick={(e) => { e.preventDefault(); setShowApiKey(!showApiKey); }}
                                                                    className="text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"
                                                                    title={showApiKey ? 'Hide Key' : 'Show Key'}
                                                                >
                                                                    {showApiKey ? <FiEyeOff size={15} /> : <FiEye size={15} />}
                                                                </button>
                                                                <button 
                                                                    onClick={async (e) => {
                                                                        e.preventDefault();
                                                                        await navigator.clipboard.writeText(apiKey || '');
                                                                    }}
                                                                    className="text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"
                                                                    title="Copy API Key"
                                                                >
                                                                    <FiCopy size={15} />
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })()}
                                    </div>
                                </>
                            );
                        })()}
                    </div>
                </div>
            )}

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
                                Are you sure you want to delete this sender request? This action cannot be undone.
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
                                        doAction('delete', deleteConfirmId);
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

// ─── Admin Users Management View ──────────────────────────────────────────────

export default AdminSenderRequests;
