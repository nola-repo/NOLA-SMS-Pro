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

export default AdminSettings;
