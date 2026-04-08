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


export const AdminAccounts: React.FC = () => {
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [actionLoading, setActionLoading] = useState<string | null>(null);

    const [successMsg, setSuccessMsg] = useState<string | null>(null);
    
    // Manage Sender States
    const [searchTerm, setSearchTerm] = useState('');
    const [visibleApiKeyId, setVisibleApiKeyId] = useState<string | null>(null);
    const [managingAccount, setManagingAccount] = useState<Account | null>(null);
    const [manageSenderId, setManageSenderId] = useState('');
    const [manageApiKey, setManageApiKey] = useState('');
    const [manageCreditBalance, setManageCreditBalance] = useState<number>(0);
    const [manageFreeCreditsTotal, setManageFreeCreditsTotal] = useState<number>(10);
    const [showApiKey, setShowApiKey] = useState(false);
    const [copiedKey, setCopiedKey] = useState(false);

    const [currentPage, setCurrentPage] = useState(1);
    const ITEMS_PER_PAGE = 10;

    useEffect(() => {
        setCurrentPage(1);
    }, [searchTerm]);

    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    const fetchAccounts = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        setError(null);
        try {
            const res = await fetch(`${ADMIN_API}?action=accounts`);
            const json = await res.json();
            if (json.status === 'success') {
                const mappedAccounts = (json.data || []).map((item: any) => {
                    if (item.data) return { id: item.id, ...item.data };
                    return item;
                }).filter((acc: any) => acc.id !== 'ghl' && acc.location_id);
                
                setAccounts(mappedAccounts);
            } else {
                setError(json.message || 'Failed to load accounts.');
            }
            setLastRefreshed(new Date());
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

    const submitManageSender = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!managingAccount) return;
        setActionLoading('managing');
        try {
            const res = await fetch(ADMIN_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'manage_sender',
                    location_id: managingAccount.location_id,
                    sender_id: manageSenderId,
                    api_key: manageApiKey,
                    credit_balance: manageCreditBalance,
                    free_credits_total: manageFreeCreditsTotal
                }),
            });
            const json = await res.json();
            if (json.status === 'success') {
                setSuccessMsg(json.message || 'Sender ID updated successfully.');
                setManagingAccount(null);
                setTimeout(() => setSuccessMsg(null), 3000);
                fetchAccounts();
            } else {
                setError(json.message || 'Failed to update sender.');
            }
        } catch {
            setError('Network error during update.');
        } finally {
            setActionLoading(null);
        }
    };

    const submitRevokeSender = async () => {
        if (!managingAccount) return;
        if (!confirm(`Are you sure you want to revoke the permanent Sender ID for ${managingAccount.location_name || managingAccount.location_id}?`)) return;
        setActionLoading('managing');
        try {
            const res = await fetch(ADMIN_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'revoke_sender',
                    location_id: managingAccount.location_id
                }),
            });
            const json = await res.json();
            if (json.status === 'success') {
                setSuccessMsg(json.message || 'Sender ID revoked successfully.');
                setManagingAccount(null);
                setTimeout(() => setSuccessMsg(null), 3000);
                fetchAccounts();
            } else {
                setError(json.message || 'Failed to revoke sender.');
            }
        } catch {
            setError('Network error during revocation.');
        } finally {
            setActionLoading(null);
        }
    };

    return (
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-6 shadow-sm">
            <div className="flex items-center justify-end mb-2">
                {!loading && (
                    <span className="text-[10px] text-[#9aa0a6] font-medium uppercase tracking-tight">
                        Last Pull: {lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                    </span>
                )}
            </div>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h3 className="text-[16px] font-bold text-[#111111] dark:text-white">All User Accounts</h3>
                    <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">Overview of all mapped GHL subaccounts, credits, and active Sender IDs.</p>
                </div>
                <div className="flex items-center gap-3">
                    <div className="relative">
                        <FiUsers className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-3.5 h-3.5" />
                        <input 
                            type="text"
                            placeholder="Search accounts..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="pl-9 pr-4 py-2 rounded-xl text-[12px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all w-64"
                        />
                    </div>
                    <button onClick={() => fetchAccounts(true)} className="p-2 rounded-xl text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-all">
                        <FiRefreshCw className={`w-4 h-4 ${loading && !accounts.length ? 'animate-spin' : ''}`} />
                    </button>
                </div>
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
                    {[1, 2, 3, 4].map(i => <div key={i} className="h-14 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />)}
                </div>
            ) : accounts.length === 0 ? (
                <div className="p-12 text-center border-2 border-dashed border-[#e5e5e5] dark:border-[#3a3b3f] rounded-xl text-[#9aa0a6] bg-[#f7f7f7] dark:bg-[#0d0e10]">
                    <FiUsers className="w-8 h-8 mx-auto mb-3 opacity-30" />
                    <p className="text-[14px] font-semibold">No accounts found.</p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="border-b border-[#e5e5e5] dark:border-white/5">
                                <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Account / Location ID</th>
                                <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Sender ID</th>
                                <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">API Key</th>
                                <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Credits</th>
                                <th className="pb-3 pr-4 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Free Used</th>
                                <th className="pb-3 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#f0f0f0] dark:divide-white/[0.03]">
                            {(() => {
                                const filteredAccounts = accounts.filter(acc => 
                                    acc.location_name?.toLowerCase().includes(searchTerm.toLowerCase()) || 
                                    acc.location_id.toLowerCase().includes(searchTerm.toLowerCase()) ||
                                    acc.approved_sender_id?.toLowerCase().includes(searchTerm.toLowerCase())
                                );
                                
                                const currentAccounts = filteredAccounts.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

                                return currentAccounts.map(acc => (
                                <tr key={acc.id} className="group hover:bg-[#f7f7f7] dark:hover:bg-white/[0.015] transition-colors">
                                    <td className="py-4 pr-4">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-[#f0f0f0] dark:bg-white/5 flex items-center justify-center text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6]">
                                                {acc.location_name ? acc.location_name.substring(0, 2).toUpperCase() : '?'}
                                            </div>
                                            <div>
                                                <p className="font-bold text-[13px] text-[#111111] dark:text-white group-hover:text-[#2b83fa] transition-colors">{acc.location_name || '—'}</p>
                                                <p className="text-[10px] text-[#9aa0a6] font-mono mt-0.5">{acc.location_id}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="py-4 pr-4">
                                        {acc.approved_sender_id
                                            ? <span className="inline-flex items-center px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-900/10 text-emerald-700 dark:text-emerald-400 text-[11px] font-bold border border-emerald-200 dark:border-emerald-800/30 uppercase tracking-wider">{acc.approved_sender_id}</span>
                                            : <span className="text-[11px] font-bold text-[#9aa0a6] uppercase tracking-widest pl-2">System</span>}
                                    </td>
                                    <td className="py-4 pr-4">
                                        {(() => {
                                            const apiKey = acc.nola_pro_api_key || acc.api_key || acc.semaphore_api_key;
                                            return apiKey ? (
                                                <div className="flex items-center gap-1.5">
                                                    <span className="text-[11px] text-[#2b83fa] dark:text-[#4da3ff] font-mono bg-[#f0f0f0] dark:bg-white/5 px-2 py-1 rounded-md max-w-[120px] truncate">
                                                        {visibleApiKeyId === acc.id 
                                                            ? apiKey 
                                                            : apiKey.length > 12 
                                                                ? `${apiKey.substring(0, 5)}•••${apiKey.substring(apiKey.length - 5)}`
                                                                : '••••••••••••'}
                                                    </span>
                                                    <div className="flex items-center border-l border-[#e5e5e5] dark:border-white/10 pl-1.5 ml-1 gap-1">
                                                        <button 
                                                            onClick={() => setVisibleApiKeyId(visibleApiKeyId === acc.id ? null : acc.id)}
                                                            className="p-1 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"
                                                            title={visibleApiKeyId === acc.id ? 'Hide Key' : 'Show Key'}
                                                        >
                                                            {visibleApiKeyId === acc.id ? <FiEyeOff size={14} /> : <FiEye size={14} />}
                                                        </button>
                                                        <button 
                                                            onClick={async () => {
                                                                await navigator.clipboard.writeText(apiKey || '');
                                                            }}
                                                            className="p-1 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"
                                                            title="Copy API Key"
                                                        >
                                                            <FiCopy size={14} />
                                                        </button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <span className="text-[11px] font-medium text-[#9aa0a6] opacity-60 flex items-center gap-1 pl-2">
                                                    {acc.approved_sender_id ? (
                                                        <><FiX size={10} /> Missing</>
                                                    ) : (
                                                        <>—</>
                                                    )}
                                                </span>
                                            );
                                        })()}
                                    </td>
                                    <td className="py-4 pr-4">
                                        <div className="flex flex-col">
                                            <span className="text-[13px] font-bold text-[#111111] dark:text-white">{(acc.credit_balance ?? acc.credits ?? 0).toLocaleString()}</span>
                                            <span className="text-[10px] text-[#9aa0a6] font-medium uppercase tracking-tight">Balance</span>
                                        </div>
                                    </td>
                                    <td className="py-4 pr-4">
                                        <div className={`inline-flex flex-col p-1.5 rounded-xl border ${ (acc.free_usage_count ?? 0) >= (acc.free_credits_total ?? 10) ? 'bg-red-50 dark:bg-red-900/10 border-red-100 dark:border-red-900/20' : 'bg-blue-50 dark:bg-blue-900/10 border-blue-100 dark:border-blue-900/20' }`}>
                                            <span className={`text-[12px] font-black text-center ${ (acc.free_usage_count ?? 0) >= (acc.free_credits_total ?? 10) ? 'text-red-600 dark:text-red-400' : 'text-[#2b83fa]' }`}>
                                                {acc.free_usage_count ?? 0} / {acc.free_credits_total ?? 10}
                                            </span>
                                            <div className="w-10 h-1 bg-gray-200 dark:bg-gray-800 rounded-full mt-1 overflow-hidden">
                                                <div className={`h-full ${(acc.free_usage_count ?? 0) >= (acc.free_credits_total ?? 10) ? 'bg-red-500' : 'bg-blue-500'}`} style={{ width: `${Math.min(((acc.free_usage_count ?? 0) / (acc.free_credits_total ?? 10)) * 100, 100)}%` }}></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="py-4">
                                        <div className="flex items-center gap-2 transition-opacity">
                                            <button
                                                onClick={() => {
                                                    setManagingAccount(acc);
                                                    setManageSenderId(acc.approved_sender_id || '');
                                                    setManageApiKey(acc.nola_pro_api_key || acc.api_key || acc.semaphore_api_key || '');
                                                    setManageCreditBalance(acc.credit_balance ?? acc.credits ?? 0);
                                                    setManageFreeCreditsTotal(acc.free_credits_total ?? 10);
                                                }}
                                                className="p-2 rounded-xl text-[#2b83fa] hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all border border-transparent hover:border-blue-100 dark:hover:border-blue-800/30"
                                                title="Manage Account"
                                            >
                                                <FiSettings className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                ));
                            })()}
                        </tbody>
                    </table>
                    
                    {/* Pagination Controls */}
                    {(() => {
                        const filteredAccounts = accounts.filter(acc => 
                            acc.location_name?.toLowerCase().includes(searchTerm.toLowerCase()) || 
                            acc.location_id.toLowerCase().includes(searchTerm.toLowerCase()) ||
                            acc.approved_sender_id?.toLowerCase().includes(searchTerm.toLowerCase())
                        );
                        const totalPages = Math.ceil(filteredAccounts.length / ITEMS_PER_PAGE);
                        if (totalPages <= 1) return null;

                        return (
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
                        );
                    })()}
                </div>
            )}

            {/* Manage Sender Modal */}
            {managingAccount && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl p-6 w-full max-w-md shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="flex items-center justify-between mb-5">
                            <h3 className="text-[18px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                                <FiSettings className="text-[#2b83fa]" /> Manage Account Config
                            </h3>
                            <button onClick={() => setManagingAccount(null)} className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors">
                                <FiX className="w-5 h-5" />
                            </button>
                        </div>

                        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mb-5">
                            Update tracking tools and the permanent Sender ID configuration for <span className="font-semibold text-[#111111] dark:text-white">{managingAccount.location_name || managingAccount.location_id}</span>.
                        </p>

                        <form onSubmit={submitManageSender} className="space-y-4">
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">Approved Sender ID</label>
                                <input
                                    required
                                    value={manageSenderId}
                                    onChange={(e) => setManageSenderId(e.target.value)}
                                    className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow"
                                />
                            </div>
                            <div>
                                <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">API Key</label>
                                <div className="relative">
                                    <input
                                        required
                                        type={showApiKey ? "text" : "password"}
                                        value={manageApiKey}
                                        onChange={(e) => setManageApiKey(e.target.value)}
                                        className="w-full pl-4 pr-24 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 font-mono transition-shadow"
                                    />
                                    <div className="absolute top-[3px] right-[3px] flex items-center gap-1">
                                        <button
                                            type="button"
                                            onClick={() => setShowApiKey(!showApiKey)}
                                            className="p-2 mr-1 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white transition-colors"
                                        >
                                            {showApiKey ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                navigator.clipboard.writeText(manageApiKey);
                                                setCopiedKey(true);
                                                setTimeout(() => setCopiedKey(false), 2000);
                                            }}
                                            className={`flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-bold rounded-lg transition-colors border ${
                                                copiedKey 
                                                ? 'bg-emerald-50 text-emerald-600 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-400 dark:border-emerald-800/30' 
                                                : 'bg-white hover:bg-gray-50 text-gray-700 border-gray-200 dark:bg-white/5 dark:hover:bg-white/10 dark:text-gray-300 dark:border-white/10'
                                            }`}
                                        >
                                            {copiedKey ? <FiCheck className="w-3.5 h-3.5" /> : <FiCopy className="w-3.5 h-3.5" />}
                                            {copiedKey ? 'Copied' : 'Copy'}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4 pt-1">
                                <div>
                                    <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">Credit Balance</label>
                                    <input
                                        required
                                        type="number"
                                        min="0"
                                        value={manageCreditBalance}
                                        onChange={(e) => setManageCreditBalance(parseInt(e.target.value) || 0)}
                                        className="w-full px-4 py-2.5 rounded-xl text-[14px] font-bold border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow"
                                    />
                                </div>
                                <div>
                                    <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">Free Usages Limit</label>
                                    <input
                                        required
                                        type="number"
                                        min="0"
                                        value={manageFreeCreditsTotal}
                                        onChange={(e) => setManageFreeCreditsTotal(parseInt(e.target.value) || 0)}
                                        className="w-full px-4 py-2.5 rounded-xl text-[14px] font-bold border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow"
                                    />
                                </div>
                            </div>

                            <div className="pt-4 flex flex-col gap-3">
                                <button
                                    type="submit"
                                    disabled={actionLoading === 'managing'}
                                    className="flex items-center justify-center gap-2 w-full px-5 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-bold text-[14px] transition-all shadow-md shadow-blue-500/20 active:scale-95 disabled:opacity-50"
                                >
                                    {actionLoading === 'managing' ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiCheck className="w-4 h-4" />}
                                    Save Changes
                                </button>
                                
                                <div className="border-t border-[#e5e5e5] dark:border-white/10 my-1"></div>
                                
                                <button
                                    type="button"
                                    onClick={submitRevokeSender}
                                    disabled={actionLoading === 'managing'}
                                    className="flex items-center justify-center gap-2 w-full px-5 py-3 bg-red-50 dark:bg-red-900/10 hover:bg-red-100 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-900/30 rounded-xl font-bold text-[14px] transition-all disabled:opacity-50"
                                >
                                    Revoke Permanent Sender
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};


// ─── System Settings View ─────────────────────────────────────────────────────

export default AdminAccounts;
