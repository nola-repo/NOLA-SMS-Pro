import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { FiSend, FiAlertCircle, FiCheck, FiX, FiRefreshCw, FiKey, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiPlus, FiMinus, FiChevronLeft, FiChevronRight, FiSearch, FiFilter, FiCopy } from 'react-icons/fi';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';

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
    const [providerCost, setProviderCost] = useState(localStorage.getItem('admin_setting_provider_cost') || '0.02');
    const [chargedRate, setChargedRate] = useState(localStorage.getItem('admin_setting_charged_rate') || '0.05');
    const [activeProvider, setActiveProvider] = useState(localStorage.getItem('admin_setting_active_provider') || 'unisms');
    const [unismsConfigured, setUnismsConfigured] = useState(false);
    const [unismsMaskedKey, setUnismsMaskedKey] = useState('');
    const [unismsKeyInput, setUnismsKeyInput] = useState('');
    const [unismsSenderId, setUnismsSenderId] = useState(localStorage.getItem('admin_setting_unisms_sender') || 'NOLASMSPro');
    const [unismsEndpoint, setUnismsEndpoint] = useState(localStorage.getItem('admin_setting_unisms_endpoint') || 'https://unismsapi.com/api');
    const [unismsTimeout, setUnismsTimeout] = useState(localStorage.getItem('admin_setting_unisms_timeout') || '15');
    const [failoverTimeout, setFailoverTimeout] = useState(localStorage.getItem('admin_setting_failover_timeout') || '8');
    const [failoverLogEnabled, setFailoverLogEnabled] = useState(localStorage.getItem('admin_setting_failover_log') !== 'false');

    // Monthly Credit Reset state
    const [monthlyResetEnabled, setMonthlyResetEnabled] = useState(localStorage.getItem('admin_setting_monthly_reset_enabled') === 'true');
    const [monthlyAllocation, setMonthlyAllocation] = useState(localStorage.getItem('admin_setting_monthly_allocation') || '500');
    const [lastResetAt, setLastResetAt] = useState<string | null>(null);
    const [lastResetCount, setLastResetCount] = useState<number>(0);

    // Load settings from backend on mount
    useEffect(() => {
        const fetchSettings = async () => {
            try {
                const res = await adminFetch('/api/admin_settings.php', { headers: getAdminAuthHeaders() });
                if (res.ok) {
                    const json = await res.json();
                    if (json.status === 'success' && json.data) {
                        const d = json.data;
                        if (d.sender_default !== undefined) setSenderDefault(d.sender_default);
                        if (d.free_limit !== undefined) setFreeLimit(String(d.free_limit));
                        if (d.maintenance_mode !== undefined) setMaintenanceMode(Boolean(d.maintenance_mode));
                        if (d.poll_interval !== undefined) setPollInterval(String(d.poll_interval));
                        if (d.provider_cost !== undefined) setProviderCost(String(d.provider_cost));
                        if (d.charged_rate !== undefined) setChargedRate(String(d.charged_rate));
                        if (d.sms_provider) {
                            const provider = d.sms_provider;
                            if (provider.active_provider !== undefined) setActiveProvider(provider.active_provider);
                            if (provider.unisms_configured !== undefined) setUnismsConfigured(Boolean(provider.unisms_configured));
                            if (provider.unisms_api_key_masked !== undefined) setUnismsMaskedKey(provider.unisms_api_key_masked || '');
                            if (provider.unisms_sender_id !== undefined) setUnismsSenderId(provider.unisms_sender_id || 'NOLASMSPro');
                            if (provider.unisms_endpoint !== undefined) setUnismsEndpoint(provider.unisms_endpoint || 'https://unismsapi.com/api');
                            if (provider.unisms_timeout_seconds !== undefined) setUnismsTimeout(String(provider.unisms_timeout_seconds));
                            if (provider.failover_timeout_seconds !== undefined) setFailoverTimeout(String(provider.failover_timeout_seconds));
                            if (provider.failover_log_enabled !== undefined) setFailoverLogEnabled(Boolean(provider.failover_log_enabled));
                        }
                        if (d.monthly_credit_reset) {
                            const mr = d.monthly_credit_reset;
                            if (mr.enabled !== undefined) setMonthlyResetEnabled(Boolean(mr.enabled));
                            if (mr.monthly_allocation !== undefined) setMonthlyAllocation(String(mr.monthly_allocation));
                            if (mr.last_reset_at !== undefined) setLastResetAt(mr.last_reset_at);
                            if (mr.last_reset_count !== undefined) setLastResetCount(Number(mr.last_reset_count));
                        }
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
            provider_cost: parseFloat(providerCost) || 0,
            charged_rate: parseFloat(chargedRate) || 0,
            sms_provider: {
                active_provider: activeProvider,
                unisms_sender_id: unismsSenderId.trim(),
                unisms_endpoint: unismsEndpoint.trim(),
                unisms_timeout_seconds: parseInt(unismsTimeout, 10) || 15,
                failover_timeout_seconds: parseInt(failoverTimeout, 10) || 8,
                failover_log_enabled: failoverLogEnabled,
                ...(unismsKeyInput.trim() ? { unisms_api_key: unismsKeyInput.trim() } : {}),
            },
            monthly_credit_reset: {
                enabled: monthlyResetEnabled,
                monthly_allocation: parseInt(monthlyAllocation, 10) || 500,
            },
        };
        try {
            const res = await adminFetch('/api/admin_settings.php', {
                method: 'POST',
                headers: getAdminAuthHeaders(),
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
        localStorage.setItem('admin_setting_provider_cost', providerCost);
        localStorage.setItem('admin_setting_charged_rate', chargedRate);
        localStorage.setItem('admin_setting_active_provider', activeProvider);
        localStorage.setItem('admin_setting_unisms_sender', unismsSenderId);
        localStorage.setItem('admin_setting_unisms_endpoint', unismsEndpoint);
        localStorage.setItem('admin_setting_unisms_timeout', unismsTimeout);
        localStorage.setItem('admin_setting_failover_timeout', failoverTimeout);
        localStorage.setItem('admin_setting_failover_log', String(failoverLogEnabled));
        localStorage.setItem('admin_setting_monthly_reset_enabled', String(monthlyResetEnabled));
        localStorage.setItem('admin_setting_monthly_allocation', monthlyAllocation);
        setUnismsKeyInput('');
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

            {/* SMS Provider Settings */}
            <Section title="SMS Provider" icon={<FiKey className="w-4 h-4" />}>
                <Field label="Active Provider" help="Controls the system-level SMS route used by the backend. Browser sends still go through /api/sms only.">
                    <select
                        value={activeProvider}
                        onChange={e => setActiveProvider(e.target.value)}
                        className="w-full max-w-[280px] px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow font-bold"
                    >
                        <option value="unisms">UniSMS</option>
                        <option value="semaphore">Semaphore</option>
                        <option value="system">System Default</option>
                    </select>
                </Field>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <Field label="UniSMS Sender ID" help="Default sender used when routing through the master UniSMS account.">
                        <input
                            value={unismsSenderId}
                            onChange={e => setUnismsSenderId(e.target.value)}
                            placeholder="NOLASMSPro"
                            className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow font-mono"
                        />
                    </Field>

                    <Field label="UniSMS API Key" help="Paste a new key only when rotating it. Saved keys are shown masked by the backend.">
                        <div className="space-y-2">
                            <div className="flex items-center gap-2 text-[12px] font-bold">
                                <span className={`px-2.5 py-1 rounded-full ${unismsConfigured ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/10 dark:text-emerald-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-900/10 dark:text-amber-400'}`}>
                                    {unismsConfigured ? 'Configured' : 'Not configured'}
                                </span>
                                {unismsMaskedKey && <span className="font-mono text-[#6e6e73] dark:text-[#9aa0a6]">{unismsMaskedKey}</span>}
                            </div>
                            <input
                                type="password"
                                value={unismsKeyInput}
                                onChange={e => setUnismsKeyInput(e.target.value)}
                                placeholder="Enter replacement UniSMS key"
                                autoComplete="off"
                                className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow font-mono"
                            />
                        </div>
                    </Field>
                </div>

                <Field label="UniSMS Endpoint" help="Backend UniSMS API base URL. Keep the default unless the provider changes it.">
                    <input
                        value={unismsEndpoint}
                        onChange={e => setUnismsEndpoint(e.target.value)}
                        placeholder="https://unismsapi.com/api"
                        className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-shadow font-mono"
                    />
                </Field>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <Field label="UniSMS Timeout" help="Provider request timeout in seconds.">
                        <ValueAdjuster value={unismsTimeout} onChange={setUnismsTimeout} min={3} max={120} step={1} suffix="s" />
                    </Field>
                    <Field label="Failover Timeout" help="How long to wait before backend failover handling.">
                        <ValueAdjuster value={failoverTimeout} onChange={setFailoverTimeout} min={3} max={120} step={1} suffix="s" />
                    </Field>
                </div>

                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-[14px] font-bold text-[#111111] dark:text-white">Failover Logging</p>
                        <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">Record provider failover events for support and audit review.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setFailoverLogEnabled(v => !v)}
                        className={`relative flex-shrink-0 w-12 h-6 rounded-full transition-colors duration-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2b83fa]/50 ${failoverLogEnabled ? 'bg-[#2b83fa]' : 'bg-gray-200 dark:bg-white/10'}`}
                        aria-label="Toggle failover logging"
                    >
                        <span className={`absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-300 ${failoverLogEnabled ? 'translate-x-6' : 'translate-x-0'}`} />
                    </button>
                </div>
            </Section>

            {/* Monthly Credit Reset Settings */}
            <Section title="Monthly Credit Reset" icon={<FiRefreshCw className="w-4 h-4" />}>
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-[14px] font-bold text-[#111111] dark:text-white">Monthly Credit Reset</p>
                        <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">
                            Automatically resets every eligible subaccount's credits to the monthly allocation on the 1st of every month. Unused credits do not carry over.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setMonthlyResetEnabled(v => !v)}
                        className={`relative flex-shrink-0 w-12 h-6 rounded-full transition-colors duration-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2b83fa]/50 ${monthlyResetEnabled ? 'bg-[#2b83fa]' : 'bg-gray-200 dark:bg-white/10'}`}
                        aria-label="Toggle monthly credit reset"
                    >
                        <span className={`absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-300 ${monthlyResetEnabled ? 'translate-x-6' : 'translate-x-0'}`} />
                    </button>
                </div>

                {monthlyResetEnabled && (
                    <Field label="Monthly Credit Allocation" help="The credit amount assigned to each active subaccount on the 1st day of every month.">
                        <ValueAdjuster
                            value={monthlyAllocation}
                            onChange={setMonthlyAllocation}
                            min={0}
                            max={99999}
                            step={50}
                            suffix=" credits"
                        />
                    </Field>
                )}

                {lastResetAt && (
                    <div className="text-[11px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] pt-3 border-t border-[#e5e5e5] dark:border-white/5 flex items-center justify-between">
                        <span>Last Automated Reset: <b>{new Date(lastResetAt).toLocaleString()}</b></span>
                        <span>Subaccounts Reset: <b>{lastResetCount}</b></span>
                    </div>
                )}
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


export const AdminLogs: React.FC<{ hideHeader?: boolean; onCardClick?: () => void }> = ({ hideHeader = false, onCardClick }) => {
    const [logs, setLogs] = useState<any[]>([]);
    const [accounts, setAccounts] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [searchTerm, setSearchTerm] = useState('');

    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [filterType, setFilterType] = useState<'all' | 'message' | 'sender_request' | 'credit_purchase' | 'credit_usage'>('all');
    const [statusFilter, setStatusFilter] = useState<'all' | 'successful' | 'pending' | 'failed'>('all');
    const [filterMenuOpen, setFilterMenuOpen] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const [selectedLog, setSelectedLog] = useState<any | null>(null);
    const [copiedContent, setCopiedContent] = useState(false);
    const ITEMS_PER_PAGE = 10;
    const [selectedMonth, setSelectedMonth] = useState<string>('All');
    const filterMenuRef = useRef<HTMLDivElement>(null);
    const seenMonthsRef = useRef<Set<string>>(new Set());

    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(searchTerm), 300);
        return () => clearTimeout(t);
    }, [searchTerm]);

    useEffect(() => { setCurrentPage(1); }, [debouncedSearch, filterType, statusFilter, selectedMonth]);

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

    const fetchLogs = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        setError(null);
        try {
            // Kick off both requests in parallel, but resolve them independently
            // so the fast `accounts` response updates the UI without waiting for
            // the slower `logs` response (which hits 8 Firestore queries on cache miss).
            const logsPromise = adminFetch(`${ADMIN_API}?action=logs`, { headers: getAdminAuthHeaders() });
            const accsPromise = adminFetch(`${ADMIN_API}?action=accounts`, { headers: getAdminAuthHeaders() })
                .then(r => r.json())
                .then(accsData => {
                    if (accsData.status === 'success') {
                        const mapped = (accsData.data || []).map((item: any) => item.data ? { id: item.id, ...item.data } : item);
                        setAccounts(mapped);
                    }
                })
                .catch(() => { /* accounts load failure is non-fatal */ });

            const logsRes = await logsPromise;
            const logsData = await logsRes.json();
            if (logsData.status === 'success') setLogs(logsData.data || []);
            else setError(logsData.message || 'Failed to load logs.');

            await accsPromise;
        } catch { setError('Network error. Could not reach the backend.'); }
        finally { if (isInitial) setLoading(false); }
    }, []);

    useEffect(() => {
        fetchLogs(true);
        const t = setInterval(() => fetchLogs(false), POLL_INTERVAL);
        return () => clearInterval(t);
    }, [fetchLogs]);


    const getType = (log: any) => {
        if (log.type === 'message' && (log.amount === undefined || log.amount === null)) return 'message';
        
        const isFreeTrial = log.amount === 0;
        const neg = (typeof log.amount === 'number' && log.amount < 0) || (typeof log.amount === 'string' && log.amount.startsWith('-'));
        
        if (neg || log.type === 'deduction' || log.type === 'credit_usage' || isFreeTrial) return 'credit_usage';
        if (log.amount !== undefined || log.type === 'top_up' || log.type === 'credit_purchase') return 'credit_purchase';
        
        return log.type || 'message';
    };

    const getStatusGroup = (log: any) => {
        const status = String(log.status || log.delivery_status || '').toLowerCase();
        if (['sent', 'delivered', 'approved', 'completed', 'paid', 'success', 'successful'].includes(status)) return 'successful';
        if (['pending', 'queued', 'processing', 'requested'].includes(status)) return 'pending';
        if (['failed', 'rejected', 'revoked', 'error', 'denied'].includes(status)) return 'failed';
        return status ? 'pending' : 'successful';
    };

    // Always extract month in LOCAL timezone so the filter matches the displayed date
    const toMonthString = (raw: any): string | null => {
        if (!raw) return null;
        let d: Date | null = null;
        if (typeof raw === 'string') {
            const parsed = new Date(raw);
            d = !isNaN(parsed.getTime()) ? parsed : null;
        } else if (typeof raw === 'number') {
            const ms = raw < 10000000000 ? raw * 1000 : raw;
            d = new Date(ms);
        } else if (typeof raw === 'object' && raw !== null) {
            const sec = (raw as any)._seconds ?? (raw as any).seconds;
            if (typeof sec === 'number') d = new Date(sec * 1000);
        }
        if (!d || isNaN(d.getTime())) return null;
        // Use local year/month — matches toLocaleDateString() used in the rows
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        return `${yyyy}-${mm}`;
    };

    const filtered = useMemo(() => logs.filter(log => {
        const type = getType(log);
        if (filterType !== 'all' && type !== filterType) return false;
        if (statusFilter !== 'all' && getStatusGroup(log) !== statusFilter) return false;
        
        // Month Filter — compare YYYY-MM prefix
        if (selectedMonth !== 'All') {
            const rawDate = log.timestamp || log.date_created || log.created_at;
            const m = toMonthString(rawDate);
            if (!m || !m.startsWith(selectedMonth)) return false;
        }

        if (debouncedSearch) {
            const q = debouncedSearch.toLowerCase();
            const s = [
                log.number,
                log.to,
                log.message,
                log.requested_id,
                log.location_id,
                log.sender_name,
                log.sendername,
                log.provider_message_id,
                log.provider_reference_id,
                log.status,
            ].filter(Boolean).join(' ').toLowerCase();
            if (!s.includes(q)) return false;
        }
        return true;
    }), [logs, filterType, statusFilter, selectedMonth, debouncedSearch]);

    // Stats cards reflect the currently filtered (month-scoped) data
    const activityStats = useMemo(() => {
        const source = filtered;
        const counts = source.reduce((acc: Record<string, number>, log: any) => {
            const type = getType(log);
            acc[type] = (acc[type] || 0) + 1;
            acc[getStatusGroup(log)] = (acc[getStatusGroup(log)] || 0) + 1;
            return acc;
        }, {});
        return {
            total: source.length,
            messages: counts.message || 0,
            credits: (counts.credit_purchase || 0) + (counts.credit_usage || 0),
            senderRequests: counts.sender_request || 0,
            needsAttention: (counts.pending || 0) + (counts.failed || 0),
        };
    }, [filtered]);

    const totalPages = Math.ceil(filtered.length / ITEMS_PER_PAGE);
    const currentLogs = filtered.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

    const availableMonths = useMemo(() => {
        // Accumulate months into the stable ref — never shrinks on refetch
        logs.forEach(log => {
            const rawDate = log.timestamp || log.date_created || log.created_at;
            const m = toMonthString(rawDate);
            if (m) seenMonthsRef.current.add(m);
        });
        return Array.from(seenMonthsRef.current).sort().reverse();
    }, [logs]);

    const pills = [
        { id: 'all', label: 'All' },
        { id: 'message', label: 'SMS History', icon: <FiMessageSquare size={11} /> },
        { id: 'sender_request', label: 'Sender Requests', icon: <FiSend size={11} /> },
        { id: 'credit_purchase', label: 'Credits Added', icon: <FiCreditCard size={11} /> },
        { id: 'credit_usage', label: 'Credits Used', icon: <FiActivity size={11} /> },
    ] as const;



    const pillColors: Record<string, { active: string; inactive: string }> = {
        neutral: {
            active: 'bg-[#111111] text-white dark:bg-white dark:text-[#111111] border-transparent shadow-sm',
            inactive: 'bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#6e6e73] dark:text-[#9aa0a6] border-[#e5e5e5] dark:border-white/5 hover:text-[#111111] dark:hover:text-white',
        },
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

    const normalizeProvider = (log: any): 'system' | 'semaphore' | 'unisms' => {
        const provider = String(log?.provider || log?.approved_provider || log?.provider_preference || '').toLowerCase();
        if (provider.includes('unisms')) return 'unisms';
        if (provider.includes('system')) return 'system';
        return 'semaphore';
    };

    const providerBadge = (log: any) => {
        const provider = normalizeProvider(log);
        const label = provider === 'unisms' ? 'UniSMS' : provider === 'system' ? 'System' : 'Semaphore';
        const styles: Record<string, string> = {
            unisms: 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-900/10 dark:text-indigo-400 dark:border-indigo-800/30',
            semaphore: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30',
            system: 'bg-slate-50 text-slate-700 border-slate-200 dark:bg-white/5 dark:text-slate-400 dark:border-white/10',
        };
        return (
            <span className={`text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded border ${styles[provider]}`}>
                {label}
            </span>
        );
    };

    const getLocationName = (log: any) => {
        const locId = log.location_id || log.account_id;
        const account = accounts.find(a => a.id === locId || a.location_id === locId);
        return log.location_name || log.account_name || log.subaccount_name || account?.location_name || (locId ? `Location ${String(locId).substring(0, 8)}` : 'System');
    };

    const getFailureReason = (log: any) => {
        if (getStatusGroup(log) !== 'failed') return '';
        const providerResponse = log.provider_response || log.response || log.raw_response;
        const candidates = [
            log.failure_reason,
            log.failed_reason,
            log.error_message,
            log.error,
            log.reason,
            log.status_message,
            log.provider_error,
            log.response_message,
            providerResponse?.error,
            providerResponse?.message,
            providerResponse?.description,
        ];
        const value = candidates.find(item => typeof item === 'string' && item.trim());
        if (value) return String(value).trim();
        if (providerResponse && typeof providerResponse === 'object') return JSON.stringify(providerResponse);
        return '';
    };

    const renderRow = (log: any, isModal = false) => {
        const type = getType(log);
        const isFreeTrial = log.amount === 0;
        const ts = log.timestamp || log.date_created || log.created_at;
        const parseLogDate = (val: any) => {
            if (!val) return null;
            if (typeof val === 'number') {
                return new Date(val < 10000000000 ? val * 1000 : val);
            }
            if (typeof val === 'object' && val !== null) {
                const sec = (val as any)._seconds ?? (val as any).seconds;
                if (typeof sec === 'number') return new Date(sec * 1000);
            }
            const d = new Date(val);
            return !isNaN(d.getTime()) ? d : null;
        };
        const parsedDate = parseLogDate(ts);
        const date = parsedDate ? parsedDate.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' }) : '';
        const time = parsedDate ? parsedDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
        const publicReferenceId = log.message_reference_id || log.transaction_reference_id || log.request_reference_id || log.reference_id;
        const failureReason = getFailureReason(log);
        const locationName = getLocationName(log);
        const locId = log.location_id || log.account_id;
        const account = accounts.find(a => a.id === locId || a.location_id === locId);
        const base = `group flex items-center gap-3 p-3 rounded-xl border transition-all duration-200 ${isModal ? 'border-transparent bg-transparent' : 'bg-white dark:bg-[#1a1b1e] border-[#e5e5e5] dark:border-white/10 hover:border-[#2b83fa]/40 dark:hover:border-[#2b83fa]/50 hover:bg-[#fbfdff] dark:hover:bg-white/[0.03] hover:shadow-md dark:hover:shadow-black/20 cursor-pointer'}`;

        const handleClick = () => {
            if (isModal) return;
            if (onCardClick) { onCardClick(); } else { setSelectedLog(log); }
        };

        const metaChip = (content: React.ReactNode, extra = '') => (
            <span className={`inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400 ${extra}`}>
                {content}
            </span>
        );

        const subAccountPill = locId ? (
            <span className="inline-flex max-w-[150px] items-center gap-1.5 rounded-full border border-gray-200/60 bg-gray-100/70 py-0.5 pl-1 pr-2 dark:border-white/10 dark:bg-white/5" title={account?.location_name || locationName}>
                <span className="flex h-4 w-4 items-center justify-center rounded-full bg-[#e5e5e5] text-[9px] font-black text-gray-500 dark:bg-white/10 dark:text-gray-300">
                    {locationName ? locationName.substring(0, 1).toUpperCase() : '?'}
                </span>
                <span className="truncate text-[10px] font-bold text-gray-700 dark:text-gray-300">{locationName}</span>
                <span className="font-mono text-[9px] text-gray-400">({String(locId).substring(0, 5)})</span>
            </span>
        ) : null;

        const dateBlock = (
            <div className="text-right flex-shrink-0">
                <span className="block text-[11px] font-bold text-[#111111] dark:text-white">{date}</span>
                <span className="block text-[10px] uppercase text-[#9aa0a6]">{time}</span>
            </div>
        );

        if (type === 'message') {
            return (
                <div key={log.id} className={base} onClick={handleClick}>
                    <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-blue-50 text-[#2b83fa] shadow-sm ring-1 ring-inset ring-black/5 transition-transform duration-200 group-hover:scale-105 dark:bg-blue-900/20 dark:text-[#569cfe] dark:ring-white/10">
                        <FiMessageSquare className="h-4 w-4" />
                    </div>
                    <div className="flex min-w-0 flex-1 flex-col justify-center">
                        <div className="mb-0.5 flex items-center justify-between gap-2">
                            <div className="flex min-w-0 items-center gap-2">
                                <p className="truncate text-[13px] font-bold text-[#111111] dark:text-white">
                                    To <span className="ml-1 font-mono text-[12px]">{log.number || log.to || 'Unknown'}</span>
                                </p>
                                <span className="flex-shrink-0 scale-90 origin-left">{statusBadge(log.status || 'unknown')}</span>
                            </div>
                            {dateBlock}
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-[12.5px] leading-5 text-[#6e6e73] dark:text-[#9aa0a6]" title={log.message || 'No content'}>{log.message || 'No content'}</p>
                                {failureReason && <p className="mt-0.5 truncate text-[11px] font-semibold text-red-600 dark:text-red-400" title={failureReason}>Reason: {failureReason}</p>}
                            </div>
                            <div className="flex flex-shrink-0 items-center gap-1.5 opacity-85">
                                {metaChip(<>{(log.message || '').length} <span className="text-[9px] opacity-70">chars</span></>)}
                                {(log.sender_name || log.sendername) && metaChip(<><span className="opacity-70">Via:</span> <span className="font-mono text-[9px]">{log.sender_name || log.sendername}</span></>)}
                                {publicReferenceId && metaChip(<><span className="opacity-70">Ref:</span> <span className="font-mono text-[9px]">{String(publicReferenceId).slice(0, 10)}</span></>)}
                                {subAccountPill}
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        if (type === 'sender_request') {
            const requestStatus = log.status || 'pending';
            return (
                <div key={log.id} className={base} onClick={handleClick}>
                    <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600 shadow-sm ring-1 ring-inset ring-black/5 transition-transform duration-200 group-hover:scale-105 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-white/10">
                        <FiSend className="h-4 w-4" />
                    </div>
                    <div className="flex min-w-0 flex-1 flex-col justify-center">
                        <div className="mb-0.5 flex items-center justify-between gap-2">
                            <div className="flex min-w-0 items-center gap-2">
                                <p className="truncate text-[13px] font-bold text-[#111111] dark:text-white">
                                    Sender Request <span className="font-mono text-[#2b83fa]">{log.requested_id || log.sender_id || log.sendername || ''}</span>
                                </p>
                                <span className="flex-shrink-0 scale-90 origin-left">{providerBadge(log)}</span>
                                <span className="flex-shrink-0 scale-90 origin-left">{statusBadge(requestStatus)}</span>
                            </div>
                            {dateBlock}
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-[12.5px] leading-5 text-[#6e6e73] dark:text-[#9aa0a6]">
                                    {locationName} requested sender approval{log.provider ? ` via ${log.provider}` : ''}.
                                </p>
                                {failureReason && <p className="mt-0.5 truncate text-[11px] font-semibold text-red-600 dark:text-red-400" title={failureReason}>Reason: {failureReason}</p>}
                            </div>
                            <div className="flex flex-shrink-0 items-center gap-1.5 opacity-85">
                                {publicReferenceId && metaChip(<><span className="opacity-70">Ref:</span> <span className="font-mono text-[9px]">{String(publicReferenceId).slice(0, 10)}</span></>)}
                                {subAccountPill}
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        if (type === 'credit_purchase' || type === 'credit_usage') {
            const isUsage = type === 'credit_usage' || (typeof log.amount === 'number' && log.amount < 0) || isFreeTrial;
            return (
                <div key={log.id} className={base} onClick={handleClick}>
                    <div className={`flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl shadow-sm ring-1 ring-inset ring-black/5 transition-transform duration-200 group-hover:scale-105 dark:ring-white/10 ${isUsage ? 'bg-purple-50 text-purple-600 dark:bg-purple-900/20 dark:text-purple-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400'}`}>
                        {isUsage ? <FiActivity className="h-4 w-4" /> : <FiCreditCard className="h-4 w-4" />}
                    </div>
                    <div className="flex min-w-0 flex-1 flex-col justify-center">
                        <div className="mb-0.5 flex items-center justify-between gap-2">
                            <p className="truncate text-[13px] font-bold text-[#111111] dark:text-white">{isFreeTrial ? 'Free Trial Used' : (isUsage ? 'Credits Used' : 'Credits Purchased')}</p>
                            {dateBlock}
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-[12.5px] leading-5 text-[#6e6e73] dark:text-[#9aa0a6]">{isFreeTrial ? 'Deducted' : (isUsage ? 'Deducted' : 'Added')} <span className={`font-bold ${isUsage ? 'text-purple-600 dark:text-purple-400' : 'text-emerald-600 dark:text-emerald-400'}`}>{!isUsage && '+'}{isFreeTrial ? '1' : log.amount?.toLocaleString()}</span> {isFreeTrial ? 'free message' : 'credits'}</p>
                                {log.balance_after !== undefined && <p className="text-[10px] text-[#9aa0a6]">Balance: {log.balance_after?.toLocaleString()} credits</p>}
                            </div>
                            <div className="flex flex-shrink-0 items-center gap-1.5 opacity-85">
                                {log.status && metaChip(log.status === 'completed' ? 'Paid' : log.status, isUsage ? 'text-purple-600 dark:text-purple-400' : 'text-emerald-600 dark:text-emerald-400')}
                                {publicReferenceId && metaChip(<><span className="opacity-70">Ref:</span> <span className="font-mono text-[9px]">{String(publicReferenceId).slice(0, 10)}</span></>)}
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
            {!hideHeader && (
            <div className="px-6 pt-6 pb-5 border-b border-[#e5e5e5] dark:border-white/5">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
                    <div>
                        <h3 className="text-[16px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                            <FiActivity className="w-4 h-4 text-[#2b83fa]" /> Platform Activity
                        </h3>
                        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">Platform-wide activity logs across all accounts.</p>
                    </div>
                    <div className="flex items-center gap-3">
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
                            <div ref={filterMenuRef} className="relative flex-shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setFilterMenuOpen(open => !open)}
                                    className={`relative h-9 w-9 rounded-xl border flex items-center justify-center transition-all ${
                                        filterMenuOpen || selectedMonth !== 'All' || statusFilter !== 'all'
                                            ? 'bg-[#111111] text-white border-[#111111] dark:bg-white dark:text-[#111111] dark:border-white'
                                            : 'bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e5e5e5] dark:border-white/5 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10'
                                    }`}
                                    aria-label="Filter platform activity"
                                    aria-expanded={filterMenuOpen}
                                >
                                    <FiFilter className="w-3.5 h-3.5" />
                                    {(selectedMonth !== 'All' || statusFilter !== 'all') && (
                                        <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-[#2b83fa] ring-2 ring-white dark:ring-[#1a1b1e]" />
                                    )}
                                </button>
                                {filterMenuOpen && (
                                    <div className="absolute right-0 top-full z-30 mt-2 w-72 rounded-2xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1a1b1e] p-3 shadow-xl shadow-black/10 dark:shadow-black/40">
                                        <div className="space-y-3">
                                            <div>
                                                <label className="block text-[10px] font-black uppercase tracking-widest text-[#9aa0a6] mb-1.5">Transactions</label>
                                                <select
                                                    value={selectedMonth}
                                                    onChange={(e) => setSelectedMonth(e.target.value)}
                                                    className="w-full appearance-none px-3 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] font-bold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                                >
                                                    <option value="All">All Transactions</option>
                                                    {availableMonths.map(m => {
                                                        const [y, mm] = m.split('-');
                                                        const label = new Date(parseInt(y), parseInt(mm) - 1).toLocaleString('default', { month: 'short', year: 'numeric' });
                                                        return <option key={m} value={m}>{label}</option>;
                                                    })}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-[10px] font-black uppercase tracking-widest text-[#9aa0a6] mb-1.5">Status</label>
                                                <select
                                                    value={statusFilter}
                                                    onChange={(event) => setStatusFilter(event.target.value as any)}
                                                    className="w-full appearance-none px-3 py-2 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 text-[12px] font-bold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30"
                                                >
                                                    <option value="all">All Statuses</option>
                                                    <option value="successful">Successful</option>
                                                    <option value="pending">Pending</option>
                                                    <option value="failed">Failed or Rejected</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                            <button onClick={() => fetchLogs(true)} className="p-2 text-[#6e6e73] hover:text-[#2b83fa] hover:bg-[#2b83fa]/10 transition-all border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl flex-shrink-0">
                                <FiRefreshCw className={`w-3.5 h-3.5 ${loading && !logs.length ? 'animate-spin' : ''}`} />
                            </button>
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
                    {[
                        { label: 'Events', value: activityStats.total, icon: <FiActivity />, tone: 'text-blue-600 bg-blue-50 border-blue-100 dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-800/30' },
                        { label: 'SMS', value: activityStats.messages, icon: <FiMessageSquare />, tone: 'text-sky-600 bg-sky-50 border-sky-100 dark:bg-sky-900/10 dark:text-sky-400 dark:border-sky-800/30' },
                        { label: 'Credits', value: activityStats.credits, icon: <FiCreditCard />, tone: 'text-emerald-600 bg-emerald-50 border-emerald-100 dark:bg-emerald-900/10 dark:text-emerald-400 dark:border-emerald-800/30' },
                        { label: 'Senders', value: activityStats.senderRequests, icon: <FiSend />, tone: 'text-amber-600 bg-amber-50 border-amber-100 dark:bg-amber-900/10 dark:text-amber-400 dark:border-amber-800/30' },
                        { label: 'Review', value: activityStats.needsAttention, icon: <FiAlertCircle />, tone: 'text-red-600 bg-red-50 border-red-100 dark:bg-red-900/10 dark:text-red-400 dark:border-red-800/30' },
                    ].map(card => (
                        <div key={card.label} className={`rounded-xl border px-3.5 py-3 ${card.tone}`}>
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <p className="text-[10px] font-black uppercase tracking-wider opacity-75">{card.label}</p>
                                    <p className="text-[20px] font-black text-[#111111] dark:text-white mt-1">{card.value.toLocaleString()}</p>
                                </div>
                                <div className="w-8 h-8 rounded-lg bg-white/70 dark:bg-white/5 flex items-center justify-center">{card.icon}</div>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Type Pill Filters */}
                <div className="rounded-2xl border border-[#e5e5e5] dark:border-white/5 bg-[#f7f7f7] dark:bg-[#111214] p-3">
                    <div className="flex items-center gap-2 overflow-x-auto pb-1 lg:pb-0 no-scrollbar">
                        {pills.map(pill => {
                            const isActive = filterType === pill.id;
                            const theme = pillColors.neutral;
                            const count = pill.id === 'all' ? logs.length : logs.filter(l => getType(l) === pill.id).length;
                            return (
                                <button key={pill.id} onClick={() => { setFilterType(pill.id as any); setCurrentPage(1); }}
                                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all whitespace-nowrap border opacity-90 hover:opacity-100 ${ isActive ? theme.active : theme.inactive }`}
                                >
                                    {'icon' in pill ? pill.icon : null}
                                    <span>{pill.label}</span>
                                    <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-black min-w-[18px] text-center ${isActive ? 'bg-white/20' : 'opacity-60'}`}>{count}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
            )}

            {/* Body */}
            <div className="p-6">
                {error && <div className="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/20 text-red-600 dark:text-red-400 text-[13px] font-medium"><FiAlertCircle className="w-4 h-4 flex-shrink-0" /> {error}</div>}

                {loading ? (
                    <div className="space-y-2.5">
                        {[...Array(7)].map((_, i) => (
                            <div key={i} className="flex items-center gap-3 p-3 rounded-xl border border-[#e5e5e5] dark:border-white/10 bg-white dark:bg-[#1a1b1e] animate-pulse" style={{ animationDelay: `${i * 60}ms` }}>
                                <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-[#f0f0f0] dark:bg-white/5" />
                                <div className="flex-1 min-w-0 space-y-2">
                                    <div className="flex items-center gap-2">
                                        <div className="h-3 w-32 rounded-full bg-[#f0f0f0] dark:bg-white/5" />
                                        <div className="h-4 w-12 rounded-md bg-[#f0f0f0] dark:bg-white/5" />
                                    </div>
                                    <div className="h-2.5 w-48 rounded-full bg-[#f5f5f5] dark:bg-white/[0.03]" />
                                </div>
                                <div className="flex-shrink-0 space-y-1.5 text-right">
                                    <div className="h-2.5 w-16 rounded-full bg-[#f0f0f0] dark:bg-white/5 ml-auto" />
                                    <div className="h-2 w-10 rounded-full bg-[#f5f5f5] dark:bg-white/[0.03] ml-auto" />
                                </div>
                            </div>
                        ))}
                    </div>
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
                const providerMessageId = log.provider_message_id || log.provider_reference_id;
                const publicReferenceId = log.message_reference_id || log.transaction_reference_id || log.request_reference_id || log.reference_id;
                const dtStr = ts ? new Date(ts).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '�';
                const modalLocationName = getLocationName(log);
                const modalFailureReason = getFailureReason(log);
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
                                            <div><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Sender Name</p><p className="text-[13px] font-mono text-[#111111] dark:text-white">{log.sender_name || log.sendername || 'System'}</p></div>
                                            <div><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Date</p><p className="text-[13px] text-[#111111] dark:text-white">{dtStr}</p></div>
                                        </div>
                                        <div className="pt-3 border-t border-[#e5e5e5] dark:border-white/5">
                                            <p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Location Name</p>
                                            <p className="text-[13px] font-bold text-[#111111] dark:text-white">{modalLocationName}</p>
                                            {log.location_id && <p className="mt-1 text-[11px] font-mono text-[#6e6e73] dark:text-[#9aa0a6] break-all">{log.location_id}</p>}
                                        </div>
                                        {modalFailureReason && <div className="pt-3 border-t border-red-200 dark:border-red-900/30"><p className="text-[10px] font-bold text-red-500 uppercase tracking-widest mb-1">Failure Reason</p><p className="text-[12px] font-semibold text-red-600 dark:text-red-400 break-words">{modalFailureReason}</p></div>}
                                        {publicReferenceId && <div className="pt-3 border-t border-[#e5e5e5] dark:border-white/5"><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Reference ID</p><p className="text-[12px] font-mono text-[#6e6e73] dark:text-[#9aa0a6] break-all">{publicReferenceId}</p></div>}
                                        {providerMessageId && <div className="pt-3 border-t border-[#e5e5e5] dark:border-white/5"><p className="text-[10px] font-bold text-[#9aa0a6] uppercase tracking-widest mb-1">Provider Message ID</p><p className="text-[12px] font-mono text-[#6e6e73] dark:text-[#9aa0a6] break-all">{providerMessageId}</p></div>}
                                    </div>
                                    <div>
                                        <div className="flex items-center justify-between mb-2">
                                            <p className="text-[11px] font-bold text-[#9aa0a6] uppercase tracking-widest flex items-center gap-2">
                                                Message Content
                                                <span className="text-[9px] px-1.5 py-0.5 bg-gray-100 dark:bg-white/5 rounded-md border border-gray-200 dark:border-white/10 normal-case tracking-tight font-medium text-gray-500">
                                                    {(log.message || '').length} chars
                                                </span>
                                            </p>
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
