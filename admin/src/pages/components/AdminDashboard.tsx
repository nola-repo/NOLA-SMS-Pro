// @ts-nocheck
import React, { useState, useEffect, useCallback } from 'react';
import { FiUsers, FiSend, FiSettings, FiLogOut, FiLock, FiAlertCircle, FiEye, FiEyeOff, FiCopy, FiCheck, FiX, FiRefreshCw, FiKey, FiHome, FiClock, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiPlus, FiMinus, FiTrash2, FiChevronLeft, FiChevronRight, FiSearch, FiSun, FiMoon, FiMoreVertical, FiToggleLeft } from 'react-icons/fi';
import logoUrl from '../../assets/NOLA SMS PRO Logo.png';
import Antigravity from '../../components/ui/Antigravity';

const ADMIN_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync



export const AdminDashboard: React.FC<{ onNavigate: (tab: any) => void }> = ({ onNavigate }) => {
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [requests, setRequests] = useState<SenderRequest[]>([]);
    const [logs, setLogs] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    const fetchData = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        try {
            const [accRes, logsRes, reqRes] = await Promise.all([
                fetch(`${ADMIN_API}?action=accounts`).catch(() => null),
                fetch(`${ADMIN_API}?action=logs`).catch(() => null),
                fetch(ADMIN_API).catch(() => null)
            ]);

            if (accRes) {
                const accJson = await accRes.json();
                if (accJson.status === 'success') {
                    const mapped = (accJson.data || []).map((item: any) => item.data ? { id: item.id, ...item.data } : item)
                        .filter((acc: any) => acc.id !== 'ghl' && acc.location_id);
                    setAccounts(mapped);
                }
            }
            if (reqRes) {
                const reqJson = await reqRes.json();
                if (reqJson.status === 'success') setRequests(reqJson.data || []);
            }
            if (logsRes) {
                const logsJson = await logsRes.json();
                if (logsJson.status === 'success') setLogs(logsJson.data || []);
            }
            setLastRefreshed(new Date());
        } catch (err) {
            console.error("Dashboard poll error:", err);
        } finally {
            if (isInitial) setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchData(true);
        const timer = setInterval(() => fetchData(false), POLL_INTERVAL);
        return () => clearInterval(timer);
    }, [fetchData]);

    const totalAccounts = accounts.length;
    const pendingRequests = requests.filter(r => r.status === 'pending').length;
    const totalMessages = logs.length;
    const approvedSenders = accounts.filter(a => a.approved_sender_id).length;
    const recentRequests = [...requests].sort((a, b) => (b.created_at || '').localeCompare(a.created_at || '')).slice(0, 6);

    const StatCard = ({ label, value, color, icon }: { label: string; value: number | string; color: string; icon: React.ReactNode }) => (
        <div className={`relative p-6 rounded-3xl bg-gradient-to-br ${color} shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 overflow-hidden group`}>
            <div className="absolute top-0 right-0 p-4 opacity-10 group-hover:scale-110 transition-transform duration-500 text-white">
                <div className="w-20 h-20">{icon}</div>
            </div>
            <div className="relative z-10">
                <div className="w-10 h-10 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white mb-4 group-hover:rotate-6 transition-transform duration-300 shadow-inner">
                    {icon}
                </div>
                <p className="text-[12px] font-bold text-white/80 uppercase tracking-widest mb-1">{label}</p>
                <h2 className="text-3xl font-black text-white tracking-tight drop-shadow-sm">
                    {loading ? <span className="inline-block w-10 h-8 bg-white/20 animate-pulse rounded-lg" /> : value}
                </h2>
            </div>
        </div>
    );

    return (
        <div className="space-y-8">
            <div className="flex items-center justify-end">
                {!loading && (
                    <span className="text-[11px] text-[#9aa0a6] font-medium">
                        Last checked: {lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                    </span>
                )}
            </div>
            
            {/* Stats Grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
                <StatCard label="Registered Users" value={totalAccounts} color="from-[#2b83fa] to-[#60a5fa]" icon={<FiUsers className="w-full h-full" />} />
                <StatCard label="Pending Requests" value={pendingRequests} color={pendingRequests > 0 ? 'from-amber-500 to-orange-500' : 'from-slate-400 to-slate-500'} icon={<FiClock className="w-full h-full" />} />
                <StatCard label="Approved Senders" value={approvedSenders} color="from-emerald-500 to-teal-600" icon={<FiCheck className="w-full h-full" />} />
                <StatCard label="Total Messages" value={totalMessages} color="from-indigo-500 to-purple-600" icon={<FiMessageSquare className="w-full h-full" />} />
            </div>

            {/* Content Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Quick Actions */}
                <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-6 shadow-sm">
                    <h3 className="text-[14px] font-bold text-[#111111] dark:text-white uppercase tracking-wider mb-5">Quick Actions</h3>
                    <div className="space-y-3">
                        {[
                            { tab: 'requests', label: 'Review Sender Requests', desc: `${pendingRequests} pending approval`, color: 'text-amber-500 bg-amber-50 dark:bg-amber-900/20', icon: <FiSend className="w-5 h-5" />, badge: pendingRequests },
                            { tab: 'accounts', label: 'View All Accounts', desc: `${totalAccounts} total installed subaccounts`, color: 'text-[#2b83fa] bg-blue-50 dark:bg-blue-900/20', icon: <FiUsers className="w-5 h-5" />, badge: 0 },
                            { tab: 'settings', label: 'System Settings', desc: 'Global sender ID and free tier config', color: 'text-slate-500 bg-slate-50 dark:bg-slate-900/20', icon: <FiSettings className="w-5 h-5" />, badge: 0 },
                        ].map(item => (
                            <button key={item.tab} onClick={() => onNavigate(item.tab)}
                                className="w-full flex items-center gap-4 p-4 rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 text-left group">
                                <div className={`w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 ${item.color} group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300 ring-1 ring-inset ring-black/5 dark:ring-white/10`}>
                                    {item.icon}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-[14px] font-bold text-[#111111] dark:text-white group-hover:text-[#2b83fa] transition-colors">{item.label}</p>
                                    <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">{item.desc}</p>
                                </div>
                                <div className="flex items-center gap-3">
                                    {item.badge > 0 && (
                                        <span className="flex-shrink-0 w-6 h-6 rounded-full bg-amber-500 shadow-lg shadow-amber-500/30 text-white text-[11px] font-black flex items-center justify-center animate-pulse">{item.badge}</span>
                                    )}
                                    <FiChevronRight className="w-5 h-5 text-[#9aa0a6] opacity-0 -translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300" />
                                </div>
                            </button>
                        ))}
                    </div>
                </div>

                {/* Recent Sender Requests */}
                <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-6 shadow-sm flex flex-col h-[400px] overflow-hidden">
                    <div className="flex items-center justify-between mb-5">
                        <h3 className="text-[14px] font-bold text-[#111111] dark:text-white uppercase tracking-wider">Recent Requests</h3>
                        <button onClick={() => onNavigate('requests')} className="group text-[11px] font-black text-[#2b83fa] hover:underline transition-all duration-300 flex items-center gap-1 active:scale-95 uppercase tracking-wider">
                            See All <FiChevronRight className="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" />
                        </button>
                    </div>
                    <div className="space-y-3">
                        {loading ? (
                            [1,2,3].map(i => <div key={i} className="h-16 rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />)
                        ) : recentRequests.length === 0 ? (
                            <div className="py-10 text-center">
                                <FiSend className="w-8 h-8 mx-auto mb-2 text-[#d0d0d0] dark:text-[#3a3b3f]" />
                                <p className="text-[13px] text-[#9aa0a6]">No requests yet.</p>
                            </div>
                        ) : recentRequests.slice(0, 4).map(req => (
                            <div key={req.id} onClick={() => onNavigate('requests')}
                                className="group flex items-center gap-4 p-3.5 rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 cursor-pointer">
                                <div className={`w-10 h-10 rounded-xl flex items-center justify-center text-white text-[13px] font-black flex-shrink-0 shadow-sm transition-transform duration-300 group-hover:scale-110 ${
                                    req.status === 'pending' ? 'bg-gradient-to-br from-amber-400 to-orange-500' : req.status === 'approved' ? 'bg-gradient-to-br from-emerald-400 to-teal-500' : 'bg-gradient-to-br from-red-400 to-rose-600'
                                }`}>
                                    {req.requested_id?.charAt(0).toUpperCase() || '?'}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-[14px] font-bold text-[#111111] dark:text-white font-mono truncate tracking-tight">{req.requested_id}</p>
                                    <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] truncate mt-0.5">{req.location_name || req.location_id}</p>
                                </div>
                                <span className={`flex-shrink-0 text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border shadow-sm ${
                                    req.status === 'pending' ? 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800/40' :
                                    req.status === 'approved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-400 dark:border-emerald-800/40' :
                                    'bg-red-50 text-red-600 border-red-200 dark:bg-red-900/20 dark:text-red-400 dark:border-red-800/40'
                                }`}>{req.status}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Platform Activity Logs (Full Width Bottom) */}
            <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-6 shadow-sm flex flex-col h-[600px]">
                <div className="flex items-center justify-between mb-5">
                    <h3 className="text-[14px] font-bold text-[#111111] dark:text-white uppercase tracking-wider flex items-center gap-2">
                        <FiActivity className="w-4 h-4 text-[#2b83fa]" /> Platform Activity
                    </h3>
                    <button onClick={() => onNavigate('activity')} className="group text-[11px] font-black text-[#2b83fa] hover:underline transition-all duration-300 flex items-center gap-1 active:scale-95 uppercase tracking-wider">
                        See All <FiChevronRight className="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" />
                    </button>
                </div>
                <div className="space-y-2 overflow-y-auto custom-scrollbar flex-1 pr-2">
                    {loading ? (
                        [1,2,3,4,5,6].map(i => <div key={i} className="h-14 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />)
                    ) : logs.length === 0 ? (
                        <div className="py-10 text-center">
                            <FiMessageSquare className="w-8 h-8 mx-auto mb-2 text-[#d0d0d0] dark:text-[#3a3b3f]" />
                            <p className="text-[13px] text-[#9aa0a6]">No logs recorded yet.</p>
                        </div>
                    ) : logs.map(log => {
                        // Determine type based on explicit type or fallback properties
                        const isNegative = typeof log.amount === 'number' && log.amount < 0;
                        const type = log.type || (
                            log.requested_id ? 'sender_request' :
                            log.amount ? (isNegative ? 'credit_usage' : 'credit_purchase') :
                            'message'
                        );
                        
                        // Get unified timestamp
                        const timestamp = log.timestamp || log.date_created || log.created_at;
                        const timeString = timestamp ? new Date(timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                        
                        const locId = log.location_id || log.account_id;
                        const account = accounts.find((a: any) => a.id === locId || a.location_id === locId);
                        
                        const subAccountPill = locId ? (
                            <div className="flex items-center gap-1.5 bg-gray-100/50 dark:bg-white/5 border border-gray-200/50 dark:border-white/10 pl-1 pr-2 py-0.5 rounded-full" title={account?.location_name || 'Unknown Account'}>
                                <div className="w-4 h-4 rounded-full bg-[#e5e5e5] dark:bg-white/10 flex items-center justify-center text-[9px] font-bold text-gray-500">
                                    {account?.location_name ? account.location_name.substring(0, 1).toUpperCase() : '?'}
                                </div>
                                <div className="flex items-baseline gap-1">
                                    <span className="text-[10px] font-bold text-gray-700 dark:text-gray-300 truncate max-w-[80px]">{account?.location_name || 'System'}</span>
                                    <span className="text-[9px] font-mono text-gray-400">({locId.substring(0, 5)})</span>
                                </div>
                            </div>
                        ) : null;
                        
                        // Message Event
                        if (type === 'message') {
                            const isSent = ['sent', 'delivered', 'pending', 'queued'].includes(log.status);
                            const isFailed = ['failed', 'rejected', 'undelivered', 'error'].includes(log.status);
                            return (
                                <div key={log.id} className="group flex items-center gap-4 p-3.5 rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
                                    <div className={`w-10 h-10 rounded-[16px] flex items-center justify-center flex-shrink-0 shadow-sm ring-1 ring-inset ring-black/5 dark:ring-white/10 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300 bg-blue-50 dark:bg-blue-900/20 text-[#2b83fa] dark:text-[#569cfe]`}>
                                        <FiMessageSquare className="w-5 h-5" />
                                    </div>
                                    <div className="flex-1 min-w-0 flex flex-col justify-center">
                                        <div className="flex items-center justify-between mb-1 gap-2">
                                            <div className="flex items-center gap-2 overflow-hidden">
                                                <p className="text-[14px] font-bold text-[#111111] dark:text-white truncate">
                                                    To <span className="font-mono text-[13px] ml-1">{log.number || log.to || 'Unknown'}</span>
                                                </p>
                                                <div className="flex-shrink-0 scale-90 origin-left">
                                                    <span className={`text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border shadow-sm ${
                                                        isSent ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30' :
                                                        isFailed ? 'bg-red-50 text-red-600 border-red-200 dark:bg-red-900/10 dark:text-red-400 dark:border-red-800/30' :
                                                        'bg-blue-50 text-[#2b83fa] border-blue-200 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30'
                                                    }`}>{log.status || 'unknown'}</span>
                                                </div>
                                            </div>
                                            <span className="text-[11px] uppercase font-bold text-[#9aa0a6] tracking-wider whitespace-nowrap flex-shrink-0">{timeString}</span>
                                        </div>
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] truncate flex-1">{log.message || 'No content'}</p>
                                            <div className="flex items-center gap-1.5 flex-shrink-0 opacity-80">
                                                {log.sendername && <span className="text-[10px] font-mono text-gray-500 bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 px-1.5 py-0.5 rounded-md shadow-sm">Via: {log.sendername}</span>}
                                                {subAccountPill}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        }

                        // Credit Purchase/Usage Event
                        if (type === 'credit_purchase' || type === 'credit_usage') {
                            const isUsage = type === 'credit_usage' || (typeof log.amount === 'number' && log.amount < 0);
                            return (
                                <div key={log.id} className="group flex items-center gap-4 p-3.5 rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
                                    <div className={`w-10 h-10 rounded-[16px] flex items-center justify-center flex-shrink-0 shadow-sm ring-1 ring-inset ring-black/5 dark:ring-white/10 group-hover:scale-110 group-hover:-rotate-3 transition-transform duration-300 ${isUsage ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-600 dark:text-purple-400' : 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400'}`}>
                                        {isUsage ? <FiActivity className="w-5 h-5" /> : <FiCreditCard className="w-5 h-5" />}
                                    </div>
                                    <div className="flex-1 min-w-0 flex flex-col justify-center">
                                        <div className="flex items-center justify-between mb-1 gap-2">
                                            <p className="text-[14px] font-bold text-[#111111] dark:text-white truncate">
                                                {isUsage ? 'Credits Used' : 'Credits Purchased'}
                                            </p>
                                            <span className="text-[11px] uppercase font-bold text-[#9aa0a6] tracking-wider whitespace-nowrap flex-shrink-0">{timeString}</span>
                                        </div>
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] truncate flex-1">
                                                {isUsage ? 'Deducted' : 'Added'} <span className={`font-bold ${isUsage ? 'text-purple-600 dark:text-purple-400' : 'text-emerald-600 dark:text-emerald-400'}`}>{!isUsage && '+'}{log.amount?.toLocaleString()}</span> credits
                                            </p>
                                            <div className="flex items-center gap-1.5 flex-shrink-0 opacity-80">
                                                {log.status && <span className="text-[10px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded-full border shadow-sm bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-900/10 dark:text-purple-400 dark:border-purple-800/30">{log.status === 'completed' ? 'Paid' : log.status}</span>}
                                                {subAccountPill}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        }

                        return null;
                    })}
                </div>
            </div>
        </div>
    );
};

