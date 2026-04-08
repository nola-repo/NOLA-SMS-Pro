// @ts-nocheck
import React, { useState, useEffect, useCallback } from 'react';
import { FiUsers, FiSend, FiSettings, FiLogOut, FiLock, FiAlertCircle, FiEye, FiEyeOff, FiCopy, FiCheck, FiX, FiRefreshCw, FiKey, FiHome, FiClock, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiPlus, FiMinus, FiTrash2, FiChevronLeft, FiChevronRight, FiSearch, FiSun, FiMoon, FiMoreVertical, FiToggleLeft } from 'react-icons/fi';
import logoUrl from '../../assets/NOLA SMS PRO Logo.png';
import Antigravity from '../../components/ui/Antigravity';

const ADMIN_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync



export const AdminSettings: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Settings state — seeded from localStorage as fallback, overwritten by API
    const [senderDefault, setSenderDefault] = useState(localStorage.getItem('admin_setting_sender') || 'NOLASMSPro');
    const [freeLimit, setFreeLimit] = useState(localStorage.getItem('admin_setting_free_limit') || '10');
    const [maintenanceMode, setMaintenanceMode] = useState(localStorage.getItem('admin_setting_maintenance') === 'true');
    const [pollInterval, setPollInterval] = useState(localStorage.getItem('admin_setting_poll_interval') || '15');

    // Load settings from backend on mount
    useEffect(() => {
        const fetchSettings = async () => {
            try {
                const res = await fetch('/api/admin_settings.php');
                if (res.ok) {
                    const json = await res.json();
                    if (json.status === 'success' && json.data) {
                        const d = json.data;
                        if (d.sender_default !== undefined) setSenderDefault(d.sender_default);
                        if (d.free_limit !== undefined) setFreeLimit(String(d.free_limit));
                        if (d.maintenance_mode !== undefined) setMaintenanceMode(Boolean(d.maintenance_mode));
                        if (d.poll_interval !== undefined) setPollInterval(String(d.poll_interval));
                    }
                }
                // If API not deployed yet, keep localStorage values (already seeded above)
            } catch {
                // Silently fall back to localStorage values
            } finally {
                setLoading(false);
            }
        };
        fetchSettings();
    }, []);

    const handleSave = async () => {
        setSaving(true);
        setError(null);
        const payload = {
            sender_default: senderDefault,
            free_limit: parseInt(freeLimit, 10) || 0,
            maintenance_mode: maintenanceMode,
            poll_interval: parseInt(pollInterval, 10) || 15,
        };
        try {
            const res = await fetch('/api/admin_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (res.ok) {
                const json = await res.json();
                if (json.status !== 'success') {
                    setError(json.message || 'Failed to save settings.');
                }
            }
            // Whether or not the backend is available, persist locally as fallback
        } catch {
            // API not yet deployed — save locally only
        }
        // Always persist to localStorage as reliable fallback
        localStorage.setItem('admin_setting_sender', senderDefault);
        localStorage.setItem('admin_setting_free_limit', freeLimit);
        localStorage.setItem('admin_setting_maintenance', String(maintenanceMode));
        localStorage.setItem('admin_setting_poll_interval', pollInterval);
        setSaving(false);
        setSaved(true);
        setTimeout(() => setSaved(false), 3500);
    };

    const Section = ({ title, icon, children }: { title: string; icon: React.ReactNode; children: React.ReactNode }) => (
        <div className="border border-[#e5e5e5] dark:border-white/5 rounded-2xl overflow-hidden">
            <div className="flex items-center gap-2.5 px-5 py-3.5 bg-[#f7f7f7] dark:bg-[#111214] border-b border-[#e5e5e5] dark:border-white/5">
                <span className="text-[#2b83fa]">{icon}</span>
                <h4 className="text-[12px] font-black text-[#111111] dark:text-white uppercase tracking-wider">{title}</h4>
            </div>
            <div className="p-5 space-y-5">{children}</div>
        </div>
    );

    const Field = ({ label, help, children }: { label: string; help?: string; children: React.ReactNode }) => (
        <div>
            <label className="block text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">{label}</label>
            {children}
            {help && <p className="text-[11px] text-[#9aa0a6] mt-1.5">{help}</p>}
        </div>
    );

    const ValueAdjuster = ({ value, min = 0, max = 9999, step = 1, onChange, suffix = '' }: { value: string; min?: number; max?: number; step?: number; onChange: (v: string) => void; suffix?: string }) => {
        const numVal = parseInt(value, 10) || 0;
        
        const handleChange = (newVal: number) => {
            if (newVal >= min && newVal <= max) {
                onChange(String(newVal));
            }
        };

        return (
            <div className="flex items-center w-full max-w-[240px] bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] rounded-xl overflow-hidden shadow-sm transition-shadow focus-within:ring-2 focus-within:ring-[#2b83fa]/30 group">
                <button
                    type="button"
                    onClick={() => handleChange(numVal - step)}
                    disabled={numVal <= min}
                    className="flex items-center justify-center px-4 py-3 text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-black/5 dark:hover:bg-white/5 active:bg-black/10 dark:active:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent transition-colors border-r border-[#e0e0e0] dark:border-[#ffffff0a]"
                >
                    <FiMinus className="w-4 h-4" />
                </button>
                <div className="flex-1 flex justify-center py-2 bg-white dark:bg-[#151618] border-y border-transparent">
                    <span className="text-[15px] font-bold text-[#111111] dark:text-white font-mono tracking-tight">{numVal}{suffix}</span>
                </div>
                <button
                    type="button"
                    onClick={() => handleChange(numVal + step)}
                    disabled={numVal >= max}
                    className="flex items-center justify-center px-4 py-3 text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-black/5 dark:hover:bg-white/5 active:bg-black/10 dark:active:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent transition-colors border-l border-[#e0e0e0] dark:border-[#ffffff0a]"
                >
                    <FiPlus className="w-4 h-4" />
                </button>
            </div>
        );
    };

    if (loading) {
        return (
            <div className="space-y-4">
                {[1, 2].map(i => <div key={i} className="h-40 rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />)}
            </div>
        );
    }

    return (
        <div className="space-y-5">
            {saved && (
                <div className="flex items-center gap-2 px-4 py-3 rounded-xl bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-800/30 text-green-700 dark:text-green-400 text-[13px] font-medium animate-in fade-in duration-200">
                    <FiCheck className="w-4 h-4 flex-shrink-0" /> Settings saved successfully.
                </div>
            )}
            {error && (
                <div className="flex items-center gap-2 px-4 py-3 rounded-xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/20 text-red-600 dark:text-red-400 text-[13px] font-medium animate-in fade-in duration-200">
                    <FiAlertCircle className="w-4 h-4 flex-shrink-0" /> {error}
                </div>
            )}

            {/* Messaging Settings */}
            <Section title="Messaging" icon={<FiMessageSquare className="w-4 h-4" />}>
                <Field label="System Default Sender ID" help="Used as fallback when no custom sender is assigned to an account.">
                    <input
                        value={senderDefault}
                        onChange={e => setSenderDefault(e.target.value)}
                        placeholder="e.g. NOLASMSPro"
                        className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow font-mono"
                    />
                </Field>
                <Field label="Free Message Limit" help="Max free messages each new sub-account can send before credits are required.">
                    <ValueAdjuster
                        value={freeLimit}
                        onChange={setFreeLimit}
                        min={0}
                        max={9999}
                        step={5}
                    />
                </Field>
                <Field label="Dashboard Refresh Rate" help="How often the admin dashboard polls for new data. Minimum 5 seconds.">
                    <ValueAdjuster
                        value={pollInterval}
                        onChange={setPollInterval}
                        min={5}
                        max={300}
                        step={5}
                        suffix="s"
                    />
                </Field>
            </Section>

            {/* Platform Settings */}
            <Section title="Platform" icon={<FiShield className="w-4 h-4" />}>
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-[14px] font-bold text-[#111111] dark:text-white">Maintenance Mode</p>
                        <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">When enabled, all outgoing SMS sending is blocked platform-wide. Use during maintenance windows.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setMaintenanceMode(v => !v)}
                        className={`relative flex-shrink-0 w-12 h-6 rounded-full transition-colors duration-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2b83fa]/50 ${maintenanceMode ? 'bg-amber-500' : 'bg-gray-200 dark:bg-white/10'}`}
                        aria-label="Toggle maintenance mode"
                    >
                        <span className={`absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-300 ${maintenanceMode ? 'translate-x-6' : 'translate-x-0'}`} />
                    </button>
                </div>
                {maintenanceMode && (
                    <div className="flex items-center gap-2 px-4 py-3 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/30 text-amber-700 dark:text-amber-400 text-[12px] font-medium animate-in fade-in duration-200">
                        <FiAlertCircle className="w-4 h-4 flex-shrink-0" />
                        Maintenance mode is <strong>ON</strong>. New SMS sends will be rejected until disabled.
                    </div>
                )}
            </Section>

            <button
                onClick={handleSave}
                disabled={saving}
                className="flex items-center justify-center gap-2 w-full py-3.5 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-bold text-[14px] transition-all shadow-md shadow-blue-500/20 active:scale-95 disabled:opacity-70"
            >
                {saving ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : saved ? <FiCheck className="w-4 h-4" /> : null}
                {saving ? 'Saving…' : saved ? 'Saved!' : 'Save Settings'}
            </button>
        </div>
    );
};


export const AdminLogs: React.FC = () => {
    const [logs, setLogs] = useState<any[]>([]);
    const [accounts, setAccounts] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [searchTerm, setSearchTerm] = useState('');

    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [filterType, setFilterType] = useState<'all' | 'message' | 'sender_request' | 'credit_purchase' | 'credit_usage'>('all');
    const [currentPage, setCurrentPage] = useState(1);
    const [selectedLog, setSelectedLog] = useState<any | null>(null);
    const [copiedContent, setCopiedContent] = useState(false);
    const ITEMS_PER_PAGE = 10;
    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(searchTerm), 300);
        return () => clearTimeout(t);
    }, [searchTerm]);

    useEffect(() => { setCurrentPage(1); }, [debouncedSearch, filterType]);

    const fetchLogs = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        setError(null);
        try {
            const [logsRes, accsRes] = await Promise.all([
                fetch(`${ADMIN_API}?action=logs`),
                fetch(`${ADMIN_API}?action=accounts`)
            ]);
            const logsData = await logsRes.json();
            const accsData = await accsRes.json();
            
            if (logsData.status === 'success') setLogs(logsData.data || []);
            else setError(logsData.message || 'Failed to load logs.');
            
            if (accsData.status === 'success') {
                const mapped = (accsData.data || []).map((item: any) => item.data ? { id: item.id, ...item.data } : item);
                setAccounts(mapped);
            }
            setLastRefreshed(new Date());
        } catch { setError('Network error. Could not reach the backend.'); }
        finally { if (isInitial) setLoading(false); }
    }, []);

    useEffect(() => {
        fetchLogs(true);
        const t = setInterval(() => fetchLogs(false), POLL_INTERVAL);
        return () => clearInterval(t);
    }, [fetchLogs]);

    const getType = (log: any) => {
        if (log.type === 'message' && log.amount === undefined) return 'message';
        
        const neg = (typeof log.amount === 'number' && log.amount < 0) || (typeof log.amount === 'string' && log.amount.startsWith('-'));
        if (neg || log.type === 'deduction' || log.type === 'credit_usage') return 'credit_usage';
        if (log.amount !== undefined || log.type === 'top_up' || log.type === 'credit_purchase') return 'credit_purchase';
        
        return log.type || 'message';
    };

    const filtered = logs.filter(log => {
        const type = getType(log);
        if (filterType !== 'all' && type !== filterType) return false;
        if (debouncedSearch) {
            const q = debouncedSearch.toLowerCase();
            const s = [log.number, log.to, log.message, log.requested_id, log.location_id, log.sendername, log.status].filter(Boolean).join(' ').toLowerCase();
            if (!s.includes(q)) return false;
        }
        return true;
    });

    const totalPages = Math.ceil(filtered.length / ITEMS_PER_PAGE);
    const currentLogs = filtered.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

    const pills = [
        { id: 'all', label: 'All', color: 'blue' },
        { id: 'message', label: 'SMS History', icon: <FiMessageSquare size={11} />, color: 'blue' },
        { id: 'credit_purchase', label: 'Credits Added', icon: <FiCreditCard size={11} />, color: 'emerald' },
        { id: 'credit_usage', label: 'Credits Used', icon: <FiActivity size={11} />, color: 'purple' },
    ] as const;

    const pillColors: Record<string, { active: string; inactive: string }> = {
        blue:    { active: 'bg-blue-600 text-white border-transparent shadow-sm',    inactive: 'bg-blue-50/50 dark:bg-blue-500/5 text-blue-600 dark:text-blue-400 border-blue-100 dark:border-blue-500/10 hover:opacity-100' },
        amber:   { active: 'bg-amber-500 text-white border-transparent shadow-sm',   inactive: 'bg-amber-50/50 dark:bg-amber-500/5 text-amber-600 dark:text-amber-400 border-amber-100 dark:border-amber-500/10 hover:opacity-100' },
        emerald: { active: 'bg-emerald-600 text-white border-transparent shadow-sm', inactive: 'bg-emerald-50/50 dark:bg-emerald-500/5 text-emerald-600 dark:text-emerald-400 border-emerald-100 dark:border-emerald-500/10 hover:opacity-100' },
        purple:  { active: 'bg-purple-600 text-white border-transparent shadow-sm',  inactive: 'bg-purple-50/50 dark:bg-purple-500/5 text-purple-600 dark:text-purple-400 border-purple-100 dark:border-purple-500/10 hover:opacity-100' },
    };

    const statusBadge = (status: string) => {
        const s = (status || '').toLowerCase();
        const map: Record<string, string> = {
            sent: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30',
            delivered: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30',
            pending: 'bg-blue-50 text-[#2b83fa] border-blue-200 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30',
            queued: 'bg-blue-50 text-[#2b83fa] border-blue-200 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30',
            failed: 'bg-red-50 text-red-600 border-red-200 dark:bg-red-900/10 dark:text-red-400 dark:border-red-800/30',
            rejected: 'bg-red-50 text-red-600 border-red-200 dark:bg-red-900/10 dark:text-red-400 dark:border-red-800/30',
            approved: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30',
        };
        return <span className={`text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded border ${map[s] || 'bg-gray-50 text-gray-600 border-gray-200 dark:bg-gray-900/10 dark:text-gray-400 dark:border-gray-800/30'}`}>{status}</span>;
    };

    const renderRow = (log: any, isModal = false) => {
        const type = getType(log);
        const ts = log.timestamp || log.date_created || log.created_at;
        const date = ts ? new Date(ts).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' }) : '';
        const time = ts ? new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
        
        const base = `group flex items-center gap-4 p-4 rounded-[16px] border transition-all duration-300 ${isModal ? 'border-transparent bg-transparent' : 'bg-white dark:bg-[#1a1b1e] border-[#e5e5e5] dark:border-white/10 hover:border-[#2b83fa]/40 dark:hover:border-[#2b83fa]/50 hover:shadow-lg dark:hover:shadow-[#2b83fa]/5 cursor-pointer hover:-translate-y-1'}`;

        const locId = log.location_id || log.account_id;
        const account = accounts.find(a => a.id === locId || a.location_id === locId);
        
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

        if (type === 'message') {

            return (
                <div key={log.id} className={base} onClick={() => !isModal && setSelectedLog(log)}>
                    <div className={`w-12 h-12 rounded-[16px] flex items-center justify-center flex-shrink-0 shadow-sm ring-1 ring-inset ring-black/5 dark:ring-white/10 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300 bg-blue-50 dark:bg-blue-900/20 text-[#2b83fa] dark:text-[#569cfe]`}>
                        <FiMessageSquare className="w-5 h-5" />
                    </div>
                    <div className="flex-1 min-w-0 flex flex-col justify-center">
                        <div className="flex items-center justify-between mb-1 gap-2">
                            <div className="flex items-center gap-2 overflow-hidden">
                                <p className="text-[14px] font-bold text-[#111111] dark:text-white truncate">
                                    To <span className="font-mono text-[13px] ml-1">{log.number || log.to || 'Unknown'}</span>
                                </p>
                                <div className="flex-shrink-0 scale-90 origin-left">{statusBadge(log.status || 'unknown')}</div>
                            </div>
                            <div className="text-right flex-shrink-0"><span className="block text-[11px] font-bold text-[#111111] dark:text-white">{date}</span><span className="block text-[10px] uppercase text-[#9aa0a6]">{time}</span></div>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] truncate flex-1">{log.message || 'No content'}</p>
                            <div className="flex items-center gap-1.5 flex-shrink-0 opacity-80">
                                {log.sendername && <span className="text-[10px] font-mono text-gray-500 bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 px-1.5 py-0.5 rounded">Via: {log.sendername}</span>}
                                {subAccountPill}
                            </div>
                        </div>
                    </div>
                </div>
            );
        }



        if (type === 'credit_purchase' || type === 'credit_usage') {
            const isUsage = type === 'credit_usage' || (typeof log.amount === 'number' && log.amount < 0);
            return (
                <div key={log.id} className={base} onClick={() => !isModal && setSelectedLog(log)}>
                    <div className={`w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm ring-1 ring-inset ring-black/5 dark:ring-white/10 group-hover:scale-110 group-hover:-rotate-3 transition-transform duration-300 ${isUsage ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-600 dark:text-purple-400' : 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400'}`}>
                        {isUsage ? <FiActivity className="w-5 h-5" /> : <FiCreditCard className="w-5 h-5" />}
                    </div>
                    <div className="flex-1 min-w-0 flex flex-col justify-center">
                        <div className="flex items-center justify-between mb-1 gap-2">
                            <p className="text-[14px] font-bold text-[#111111] dark:text-white">{isUsage ? 'Credits Used' : 'Credits Purchased'}</p>
                            <div className="text-right flex-shrink-0"><span className="block text-[11px] font-bold text-[#111111] dark:text-white">{date}</span><span className="block text-[10px] uppercase text-[#9aa0a6]">{time}</span></div>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] truncate flex-1">{isUsage ? 'Deducted' : 'Added'} <span className={`font-bold ${isUsage ? 'text-purple-600 dark:text-purple-400' : 'text-emerald-600 dark:text-emerald-400'}`}>{!isUsage && '+'}{log.amount?.toLocaleString()}</span> credits</p>
                            <div className="flex items-center gap-1.5 flex-shrink-0 opacity-80">
                                {log.status && <span className="text-[10px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded border bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-900/10 dark:text-purple-400 dark:border-purple-800/30">{log.status === 'completed' ? 'Paid' : log.status}</span>}
                                {subAccountPill}
                            </div>
                        </div>
                    </div>
                </div>
            );
        }
        return null;
    };

    return (
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl shadow-[0_2px_15px_rgba(0,0,0,0.03)] dark:shadow-[0_2px_15px_rgba(0,0,0,0.2)]">
            {/* Header */}
            <div className="px-6 pt-6 pb-5 border-b border-[#e5e5e5] dark:border-white/5">
                <div className="flex items-center justify-end mb-2">
                    {!loading && (
                        <span className="text-[10px] text-[#9aa0a6] font-medium uppercase tracking-tight">
                            Updated: {lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                        </span>
                    )}
                </div>
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
                    <div>
                        <h3 className="text-[16px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                            <FiActivity className="w-4 h-4 text-[#2b83fa]" /> Platform Activity
                        </h3>
                        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">Platform-wide activity logs across all accounts.</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="relative w-full sm:w-64">
                            <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] w-3.5 h-3.5" />
                            <input
                                type="text"
                                placeholder="Search logs..."
                                value={searchTerm}
                                onChange={e => setSearchTerm(e.target.value)}
                                className="w-full pl-9 pr-8 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] text-[#111111] dark:text-white placeholder-[#9aa0a6] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all font-medium"
                            />
                            {searchTerm && <button onClick={() => setSearchTerm('')} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors"><FiX className="w-3 h-3" /></button>}
                        </div>
                        <button onClick={() => fetchLogs(true)} className="p-2 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-all border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl flex-shrink-0">
                            <FiRefreshCw className={`w-3.5 h-3.5 ${loading && !logs.length ? 'animate-spin' : ''}`} />
                        </button>
                    </div>
                </div>

                {/* Type Pill Filters */}
                <div className="flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar">
                    {pills.map(pill => {
                        const isActive = filterType === pill.id;
                        const theme = pillColors[pill.color];
                        const count = pill.id === 'all' ? logs.length : logs.filter(l => getType(l) === pill.id).length;
                        return (
                            <button key={pill.id} onClick={() => { setFilterType(pill.id as any); setCurrentPage(1); }}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all whitespace-nowrap border opacity-80 hover:opacity-100 ${ isActive ? theme.active : theme.inactive }`}
                            >
                                {'icon' in pill ? pill.icon : null}
                                <span>{pill.label}</span>
                                <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-black min-w-[18px] text-center ${isActive ? 'bg-white/20' : 'opacity-60'}`}>{count}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* Body */}
            <div className="p-6">
                {error && <div className="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/20 text-red-600 dark:text-red-400 text-[13px] font-medium"><FiAlertCircle className="w-4 h-4 flex-shrink-0" /> {error}</div>}

                {loading ? (
                    <div className="space-y-3">{[...Array(8)].map((_, i) => <div key={i} className="h-[76px] rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />)}</div>
                ) : filtered.length === 0 ? (
                    <div className="py-20 text-center">
                        <FiActivity className="w-12 h-12 mx-auto mb-4 text-[#d0d0d0] dark:text-[#3a3b3f]" />
                        <h3 className="text-[15px] font-bold text-[#111111] dark:text-white mb-1">No Logs Found</h3>
                        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6]">{debouncedSearch || filterType !== 'all' ? 'Try adjusting your filters.' : 'Platform logs will appear here as activity occurs.'}</p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-3 max-h-[55vh] overflow-y-auto custom-scrollbar pr-2 pb-2">{currentLogs.map(log => renderRow(log))}</div>

                        {totalPages > 1 && (
                            <div className="flex items-center justify-between mt-6 pt-4 border-t border-[#e5e5e5] dark:border-white/5">
                                <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] font-medium">
                                    Showing <b className="text-[#111111] dark:text-white">{(currentPage - 1) * ITEMS_PER_PAGE + 1}</b> – <b className="text-[#111111] dark:text-white">{Math.min(currentPage * ITEMS_PER_PAGE, filtered.length)}</b> of <b className="text-[#111111] dark:text-white">{filtered.length}</b>
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

            {/* Detail Modal */}
            {selectedLog && (() => {
                const log = selectedLog;
                const type = getType(log);
                const ts = log.timestamp || log.date_created || log.created_at;
                const dtStr = ts ? new Date(ts).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
                return (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white/95 dark:bg-[#1a1b1e]/95 backdrop-blur-xl border border-white/20 dark:border-white/10 rounded-[24px] p-7 w-full max-w-lg shadow-[0_20px_60px_rgba(0,0,0,0.1)] dark:shadow-[0_20px_60px_rgba(0,0,0,0.5)] animate-in fade-in zoom-in-95 duration-200">
                            <div className="flex items-center justify-between mb-5">
                                <h3 className="text-[16px] font-bold text-[#111111] dark:text-white">
                                    {type === 'message' ? 'Message Detail' : type === 'sender_request' ? 'Sender Request Detail' : 'Credit Event Detail'}
                                </h3>
                                <button onClick={() => setSelectedLog(null)} className="p-1.5 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors"><FiX className="w-5 h-5" /></button>
                            </div>

                            {type === 'message' && (
                                <div className="space-y-4">
                                    <div className="bg-[#f7f7f7] dark:bg-[#111214] rounded-xl p-4 border border-[#e5e5e5] dark:border-white/5 space-y-3">
                                        <div className="grid grid-cols-2 gap-4">
                                            <div><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Recipient</p><p className="text-[13px] font-mono font-bold text-[#111111] dark:text-white">{log.number || log.to || '—'}</p></div>
                                            <div><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Status</p>{statusBadge(log.status || 'unknown')}</div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-4 pt-3 border-t border-[#e5e5e5] dark:border-white/5">
                                            <div><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Sender Name</p><p className="text-[13px] font-mono text-[#111111] dark:text-white">{log.sendername || 'System'}</p></div>
                                            <div><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Date</p><p className="text-[13px] text-[#111111] dark:text-white">{dtStr}</p></div>
                                        </div>
                                        {log.location_id && <div className="pt-3 border-t border-[#e5e5e5] dark:border-white/5"><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Location ID</p><p className="text-[12px] font-mono text-[#6e6e73] dark:text-[#9aa0a6]">{log.location_id}</p></div>}
                                    </div>
                                    <div>
                                        <div className="flex items-center justify-between mb-2">
                                            <p className="text-[11px] font-bold text-[#9aa0a6] uppercase tracking-widest">Message Content</p>
                                            <button
                                                onClick={() => { navigator.clipboard.writeText(log.message || ''); setCopiedContent(true); setTimeout(() => setCopiedContent(false), 2000); }}
                                                className={`flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-lg transition-colors border ${copiedContent ? 'bg-emerald-50 text-emerald-600 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-400 dark:border-emerald-800/30' : 'text-[#6e6e73] border-[#e5e5e5] dark:border-white/5 hover:border-[#d0d0d0]'}`}
                                            >
                                                {copiedContent ? <FiCheck className="w-3 h-3" /> : <FiCopy className="w-3 h-3" />} {copiedContent ? 'Copied!' : 'Copy'}
                                            </button>
                                        </div>
                                        <div className="bg-[#f7f7f7] dark:bg-[#111214] rounded-xl p-4 border border-[#e5e5e5] dark:border-white/5 max-h-40 overflow-y-auto custom-scrollbar">
                                            <p className="text-[13px] text-[#111111] dark:text-white leading-relaxed whitespace-pre-wrap">{log.message || 'No content'}</p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {type !== 'message' && <div className="mt-2">{renderRow(log, true)}</div>}

                            <button onClick={() => setSelectedLog(null)} className="mt-6 w-full py-3.5 text-[14px] font-bold text-center text-[#111111] dark:text-white bg-[#f7f7f7] dark:bg-white/5 hover:bg-[#efefef] dark:hover:bg-white/10 transition-colors rounded-xl border border-[#e5e5e5] dark:border-white/5">
                                Close
                            </button>
                        </div>
                    </div>
                );
            })()}
        </div>
    );
};

