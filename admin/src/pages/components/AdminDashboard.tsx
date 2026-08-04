import { devLog } from '../../utils/devLog';
import React, { useState, useEffect, useCallback } from 'react';
import { FiUsers, FiSend, FiActivity, FiMessageSquare, FiCreditCard, FiPlus, FiChevronLeft, FiChevronRight, FiSearch, FiArrowRight, FiBriefcase } from 'react-icons/fi';
import type { Account, SenderRequest } from './Types';

import SplitText from './SplitText';
import FadeContent from './FadeContent';
import AnimatedContent from './AnimatedContent';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';
import { ProviderBalanceCard, ProviderBalancesResponse } from './ProviderBalanceCard';

const ADMIN_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync

const readAdminTopbarInitial = () => {
    const token = sessionStorage.getItem('nola_admin_token') || localStorage.getItem('nola_admin_token') || '';
    const storedUser = sessionStorage.getItem('nola_admin_user') || localStorage.getItem('nola_admin_user') || '';
    let display = storedUser || 'Admin';

    try {
        const payload = token.split('.')[1];
        if (payload) {
            const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
            const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
            const claims = JSON.parse(atob(padded));
            display = claims.name || claims.full_name || claims.display_name || claims.email || claims.username || display;
        }
    } catch {
        // Stored username remains the fallback.
    }

    return String(display || 'A').charAt(0).toUpperCase();
};

const normalizeNumber = (value: unknown, fallback = 0) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
};

const normalizeAccount = (item: any) => {
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
    };
};

type DashboardMetricCardProps = {
    label: string;
    value: number | string;
    note: string;
    icon: React.ReactNode;
    gradient: string;
    iconClass: string;
    labelClass: string;
    valueClass: string;
    buttonClass: string;
    onClick: () => void;
    actionLabel: string;
    loading: boolean;
    index?: number;
};

const DashboardMetricCard = ({
    label,
    value,
    note,
    icon,
    gradient,
    iconClass,
    labelClass,
    valueClass,
    buttonClass,
    onClick,
    actionLabel,
    loading,
    index = 0,
}: DashboardMetricCardProps) => (
    <AnimatedContent delay={0.1 + index * 0.1} distance={50} direction="vertical">
        <div className={`p-6 rounded-[24px] ${gradient} shadow-xl transition-all group overflow-hidden relative h-full min-h-[184px] border border-white/70 dark:border-white/15 hover:-translate-y-0.5`}>
            <div className="absolute inset-0 bg-white/10 dark:bg-white/[0.05] pointer-events-none" />
            <div className="absolute bottom-0 right-0 p-4 opacity-[0.13] dark:opacity-[0.16] group-hover:scale-110 transition-transform duration-500">
                <div className="w-24 h-24">{icon}</div>
            </div>
            <button
                onClick={onClick}
                className={`group/action absolute right-4 top-4 z-20 flex h-10 w-10 items-center justify-center rounded-full text-white shadow-[0_10px_24px_rgba(15,23,42,0.22)] ring-1 ring-white/45 hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white transition-all ${buttonClass}`}
                aria-label={actionLabel}
                title={actionLabel}
            >
                <FiPlus className="h-[18px] w-[18px] stroke-[2.4] transition-transform duration-200 group-hover/action:rotate-90" />
            </button>
            <div className="relative z-10 flex h-full flex-col justify-between">
                <div>
                    <div className={`w-10 h-10 rounded-xl bg-white/70 dark:bg-white/[0.14] flex items-center justify-center mb-4 shadow-sm ring-1 ring-white/40 dark:ring-white/10 ${iconClass}`}>
                        <div className="h-5 w-5">{icon}</div>
                    </div>
                    <p className={`text-[12px] font-bold uppercase tracking-widest mb-1 ${labelClass}`}>
                        {label}
                    </p>
                </div>
                <div className="mt-4">
                    <h2 className={`text-3xl sm:text-4xl font-black leading-none ${valueClass}`}>
                        {loading ? <span className="inline-block h-10 w-20 rounded-lg bg-white/40 dark:bg-white/15 animate-pulse" /> : value}
                    </h2>
                    <p className={`mt-2 text-[12px] font-bold ${labelClass}`}>{note}</p>
                </div>
            </div>
        </div>
    </AnimatedContent>
);

export const AdminDashboard: React.FC<{
    onNavigate: (tab: 'accounts' | 'requests' | 'activity' | 'agencies' | 'profile' | 'logs-explorer' | 'admins') => void;
    topControls?: React.ReactNode;
    mobileMenuButton?: React.ReactNode;
}> = ({ onNavigate, topControls, mobileMenuButton }) => {
    const adminInitial = readAdminTopbarInitial();
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [requests, setRequests] = useState<SenderRequest[]>([]);
    const [logs, setLogs] = useState<any[]>([]);
    const [totalMessages, setTotalMessages] = useState(0);
    const [loading, setLoading] = useState(true);
    const [currentPage, setCurrentPage] = useState(1);
    const [searchQuery, setSearchQuery] = useState('');
    const ITEMS_PER_PAGE = 5;

    // Provider Balances State
    const [balanceData, setBalanceData] = useState<ProviderBalancesResponse | null>(null);
    const [isBalanceLoading, setIsBalanceLoading] = useState(false);
    const [balanceError, setBalanceError] = useState<string | null>(null);

    const fetchProviderBalances = useCallback(async (isManual = false) => {
        if (isManual) setIsBalanceLoading(true);
        setBalanceError(null);
        try {
            const endpoint = isManual ? '/api/admin/provider-balances?refresh=1' : '/api/admin/provider-balances';
            const res = await adminFetch(endpoint, { headers: getAdminAuthHeaders() });
            const json = await res.json().catch(() => ({}));
            if (res.ok && json.status === 'success') {
                setBalanceData(json);
            } else {
                setBalanceError(json?.message || json?.error || 'Could not fetch provider balances.');
            }
        } catch (e) {
            setBalanceError('Network error. Could not fetch provider balances.');
        } finally {
            setIsBalanceLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchProviderBalances(true);
        const interval = setInterval(() => fetchProviderBalances(false), 5 * 60 * 1000);
        return () => clearInterval(interval);
    }, [fetchProviderBalances]);

    // Admin Health Stats State
    const [healthStats, setHealthStats] = useState<{
        total_subaccounts?: number;
        total_messages?: number;
        subaccount_stats_source?: string;
        subaccount_stats_truncated?: boolean;
    } | null>(null);

    const fetchData = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        try {
            const [logsRes, reqRes, healthRes] = await Promise.all([
                adminFetch(`${ADMIN_API}?action=logs`, { headers: getAdminAuthHeaders() }).catch(() => null),
                adminFetch(ADMIN_API, { headers: getAdminAuthHeaders() }).catch(() => null),
                adminFetch('/api/v2/admin_health', { headers: getAdminAuthHeaders() }).catch(() => null),
            ]);

            if (healthRes && healthRes.ok) {
                const healthJson = await healthRes.json().catch(() => null);
                if (healthJson?.status === 'success' && healthJson?.data?.stats) {
                    setHealthStats({
                        total_subaccounts: healthJson.data.stats.total_subaccounts,
                        total_messages: healthJson.data.stats.total_messages,
                        subaccount_stats_source: healthJson.data.stats.subaccount_stats_source || healthJson.data.subaccount_stats_source,
                        subaccount_stats_truncated: healthJson.data.stats.subaccount_stats_truncated || healthJson.data.subaccount_stats_truncated,
                    });
                }
            }

            // Fetch accurate registered users list
            let accs = [];
            try {
                const res = await adminFetch('/api/admin_list_users.php', { headers: getAdminAuthHeaders() });
                if (res.ok) {
                    const json = await res.json();
                    if (json.status === 'success') {
                        accs = (json.data || []).map(normalizeAccount);
                    }
                }
            } catch (err) {
                devLog.error("Dashboard list users fetch error:", err);
            }

            // Fallback to ADMIN_API?action=accounts if list users failed
            if (!accs.length) {
                const accRes = await adminFetch(`${ADMIN_API}?action=accounts`, { headers: getAdminAuthHeaders() }).catch(() => null);
                if (accRes) {
                    const accJson = await accRes.json();
                    if (accJson.status === 'success') {
                        accs = (accJson.data || []).map(normalizeAccount)
                            .filter((acc: any) => acc.id !== 'ghl' && acc.location_id);
                    }
                }
            }
            setAccounts(accs);

            if (reqRes) {
                const reqJson = await reqRes.json();
                if (reqJson.status === 'success') setRequests(reqJson.data || []);
            }
            if (logsRes) {
                const logsJson = await logsRes.json();
                if (logsJson.status === 'success') {
                    const sortedLogs = (logsJson.data || []).sort((a: any, b: any) => {
                        const timeA = new Date(a.timestamp || a.date_created || a.created_at || 0).getTime();
                        const timeB = new Date(b.timestamp || b.date_created || b.created_at || 0).getTime();
                        return timeB - timeA;
                    });
                    setLogs(sortedLogs);
                    setTotalMessages(logsJson.total_messages ?? sortedLogs.length);
                }
            }
        } catch (err) {
            devLog.error("Dashboard poll error:", err);
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
    const activeAccounts = accounts.filter(a => a.active !== false).length;
    const recentRequests = [...requests].sort((a, b) => (b.created_at || '').localeCompare(a.created_at || '')).slice(0, 6);

    const normalizedSearch = searchQuery.trim().toLowerCase();
    const accountSearchResults = normalizedSearch
        ? accounts.filter((account: any) =>
            [
                account.full_name,
                account.name,
                account.email,
                account.phone,
                account.location_name,
                account.location_id,
                account.agency_name,
            ].some(value => String(value || '').toLowerCase().includes(normalizedSearch))
        ).slice(0, 5)
        : [];

    const getGreeting = () => {
        const hour = new Date().getHours();
        if (hour < 12) return "Good morning";
        if (hour < 18) return "Good afternoon";
        return "Good evening";
    };

    return (
        <div id="admin-dashboard" className="w-full min-h-full bg-[#f3f4f6] dark:bg-[#09090b] relative">
            <div className="absolute top-0 left-0 w-full h-[380px] bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] z-0 rounded-b-[32px] pointer-events-none" />

            <div className="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 relative z-10">
                <div className="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 mb-8">
                    <div className="flex items-start gap-3 text-white min-w-0">
                        {mobileMenuButton}
                        <div className="min-w-0">
                            <SplitText
                                text={`${getGreeting()}, Admin`}
                                className="text-2xl sm:text-3xl font-extrabold tracking-tight leading-tight"
                                delay={40}
                                duration={1.2}
                                ease="power3.out"
                                splitType="chars"
                                from={{ opacity: 0, y: 30 }}
                                to={{ opacity: 1, y: 0 }}
                                threshold={0.1}
                                rootMargin="-100px"
                                textAlign="left"
                                tag="h1"
                            />
                            <FadeContent blur={false} duration={1200} ease="ease-out" initialOpacity={0}>
                                <p className="text-white/80 font-medium mt-1">
                                    NOLA SMS PRO is ready for platform operations.
                                </p>
                            </FadeContent>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 w-full lg:w-auto">
                        <div className="relative flex-1 lg:w-72" onClick={(e) => e.stopPropagation()}>
                            <FiSearch className="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-white/70" />
                            <input
                                type="text"
                                placeholder="Search accounts..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="w-full pl-11 pr-4 py-2.5 bg-white/10 border border-white/20 rounded-xl text-[14px] font-medium text-white placeholder-white/70 focus:outline-none focus:ring-2 focus:ring-white/30 focus:bg-white/20 transition-all"
                            />
                            {searchQuery.trim() !== '' && (
                                <div className="absolute top-full left-0 right-0 mt-2 bg-white/95 dark:bg-[#1a1b1e]/95 backdrop-blur-md border border-gray-200 dark:border-white/10 rounded-2xl shadow-xl z-50 overflow-hidden py-1.5 animate-in fade-in slide-in-from-top-2 duration-200 max-h-60 overflow-y-auto custom-scrollbar">
                                    {accountSearchResults.length > 0 ? (
                                        accountSearchResults.map((account: any) => (
                                            <button
                                                key={account.id || account.location_id || account.email}
                                                onClick={() => {
                                                    setSearchQuery('');
                                                    onNavigate('accounts');
                                                }}
                                                className="w-full px-4 py-2.5 hover:bg-gray-100 dark:hover:bg-white/[0.05] transition-colors text-left flex items-center gap-3"
                                            >
                                                <div className="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-[12px] flex-shrink-0 bg-gradient-to-br from-[#2b83fa] to-[#60a5fa]">
                                                    {(account.full_name || account.name || account.email || '?').charAt(0).toUpperCase()}
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="text-[13.5px] font-bold text-[#111111] dark:text-white leading-tight truncate">
                                                        {account.full_name || account.name || account.location_name || 'Unnamed account'}
                                                    </p>
                                                    <p className="text-[11.5px] text-gray-500 dark:text-gray-400 font-medium truncate">
                                                        {account.email || account.location_id || 'No account detail'}
                                                    </p>
                                                </div>
                                            </button>
                                        ))
                                    ) : (
                                        <div className="px-4 py-3 text-center text-gray-400 dark:text-gray-500 text-[12.5px] font-medium italic">
                                            No accounts found
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => onNavigate('profile')}
                            className="w-10 h-10 rounded-full flex items-center justify-center shadow-lg shadow-black/10 border border-white/35 flex-shrink-0 text-white bg-gradient-to-br from-white/25 to-white/10 hover:bg-white/20 active:scale-95 transition-all text-[13px] font-black"
                            title="Admin Profile"
                            aria-label="Open admin profile"
                        >
                            {adminInitial}
                        </button>
                        {topControls}
                    </div>
                </div>

                {/* Stats Source / Truncation Indicator */}
                {healthStats?.subaccount_stats_source && (
                    <div className="mb-4 flex items-center justify-end gap-2 text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-[#6e6e73] dark:bg-white/5 dark:text-[#9aa0a6] border border-gray-200 dark:border-white/10">
                            Stats source: <strong className="text-[#111111] dark:text-white">{healthStats.subaccount_stats_source.replace(/_/g, ' ')}</strong>
                            {healthStats.subaccount_stats_truncated && (
                                <span className="ml-1 text-amber-600 dark:text-amber-400 font-bold">(truncated)</span>
                            )}
                        </span>
                    </div>
                )}

                {/* Stat Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                    <DashboardMetricCard
                        index={0}
                        label="Registered Users"
                        value={(healthStats?.total_subaccounts ?? totalAccounts).toLocaleString()}
                        note={`${activeAccounts.toLocaleString()} active subaccounts`}
                        icon={<FiUsers className="w-full h-full" />}
                        gradient="bg-gradient-to-br from-[#e0f2fe] via-[#60a5fa] to-[#06b6d4] dark:from-[#3b82f6] dark:via-[#2584d5] dark:to-[#14a3a1] shadow-blue-500/20 hover:shadow-blue-500/30"
                        iconClass="text-blue-700 dark:text-blue-50"
                        labelClass="text-blue-950/70 dark:text-blue-50/80"
                        valueClass="text-[#082f49] dark:text-white"
                        buttonClass="bg-blue-700 hover:bg-blue-800 dark:bg-blue-500 dark:hover:bg-blue-400"
                        actionLabel="Open accounts"
                        loading={loading}
                        onClick={() => onNavigate('accounts')}
                    />
                    <DashboardMetricCard
                        index={1}
                        label="Pending Requests"
                        value={pendingRequests.toLocaleString()}
                        note="Waiting for review"
                        icon={<FiSend className="w-full h-full" />}
                        gradient="bg-gradient-to-br from-[#fae8ff] via-[#c084fc] to-[#7c3aed] dark:from-[#8b5cf6] dark:via-[#7c3aed] dark:to-[#5b5ce2] shadow-purple-500/20 hover:shadow-purple-500/30"
                        iconClass="text-purple-700 dark:text-purple-50"
                        labelClass="text-purple-950/70 dark:text-purple-50/80"
                        valueClass="text-[#3b0764] dark:text-white"
                        buttonClass="bg-purple-700 hover:bg-purple-800 dark:bg-purple-500 dark:hover:bg-purple-400"
                        actionLabel="Review requests"
                        loading={loading}
                        onClick={() => onNavigate('requests')}
                    />
                    <DashboardMetricCard
                        index={2}
                        label="Total Messages"
                        value={(healthStats?.total_messages ?? totalMessages).toLocaleString()}
                        note="Platform activity"
                        icon={<FiMessageSquare className="w-full h-full" />}
                        gradient="bg-gradient-to-br from-[#dcfce7] via-[#86efac] to-[#2dd4bf] dark:from-[#10b981] dark:via-[#0ea56f] dark:to-[#0d9488] shadow-emerald-500/20 hover:shadow-emerald-500/30"
                        iconClass="text-emerald-700 dark:text-emerald-50"
                        labelClass="text-emerald-950/70 dark:text-emerald-50/80"
                        valueClass="text-[#022c22] dark:text-white"
                        buttonClass="bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                        actionLabel="Open activity"
                        loading={loading}
                        onClick={() => onNavigate('activity')}
                    />
                </div>

                {/* SMS Provider Balances Card */}
                <div className="mt-8 sm:mt-10 mb-10">
                    <ProviderBalanceCard
                        semaphore={balanceData?.providers?.semaphore}
                        unisms={balanceData?.providers?.unisms}
                        activeProvider={balanceData?.active_provider}
                        fetchedAt={balanceData?.fetched_at}
                        isLoading={isBalanceLoading}
                        error={balanceError}
                        summary={balanceData?.summary}
                        onRefresh={() => fetchProviderBalances(true)}
                    />
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Quick Actions */}
                <AnimatedContent delay={0.4} distance={50} direction="vertical">
                    <h3 className="text-[15px] font-bold text-[#111111] dark:text-white mb-5 flex items-center gap-2 h-8">
                        Quick Actions
                    </h3>
                    <div className="space-y-3">
                        {[
                            { tab: 'requests', label: 'Review Sender Requests', desc: `${pendingRequests} pending approval`, color: 'text-amber-500 bg-amber-50 dark:bg-amber-900/20', icon: <FiSend className="h-6 w-6" />, badge: pendingRequests, hoverBorder: 'hover:border-amber-500/30 hover:shadow-amber-500/10' },
                            { tab: 'accounts', label: 'View All Subaccounts', desc: `${totalAccounts} total installed subaccounts`, color: 'text-[#2b83fa] bg-blue-50 dark:bg-blue-900/20', icon: <FiUsers className="h-6 w-6" />, badge: 0, hoverBorder: 'hover:border-[#2b83fa]/30 hover:shadow-blue-500/10' },
                            { tab: 'agencies', label: 'View All Agencies', desc: 'Manage agency accounts and balances', color: 'text-emerald-500 bg-emerald-50 dark:bg-emerald-900/20', icon: <FiBriefcase className="h-6 w-6" />, badge: 0, hoverBorder: 'hover:border-emerald-500/30 hover:shadow-emerald-500/10' },
                        ].map(item => (
                            <button key={item.tab} onClick={() => onNavigate(item.tab as any)}
                                className={`w-full p-4 rounded-[20px] bg-white dark:bg-[#1c1e21] border border-white/70 dark:border-white/[0.06] shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 text-left flex items-center justify-between group ${item.hoverBorder}`}>
                                <div className="flex items-center gap-4">
                                    <div className={`w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 ${item.color} transition-transform group-hover:scale-110`}>
                                        {item.icon}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <h4 className="font-bold text-[#111111] dark:text-white text-[15px]">{item.label}</h4>
                                        <p className="text-[12px] text-gray-500 dark:text-gray-400 font-medium">{item.desc}</p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-3">
                                    {item.badge > 0 && (
                                        <span className="flex-shrink-0 w-6 h-6 rounded-full bg-amber-500 shadow-lg text-white text-[11px] font-black flex items-center justify-center animate-pulse">{item.badge}</span>
                                    )}
                                    <FiArrowRight className="h-5 w-5 text-gray-300 group-hover:text-gray-500 group-hover:translate-x-1 transition-all" />
                                </div>
                            </button>
                        ))}
                    </div>
                </AnimatedContent>

                {/* Recent Sender Requests */}
                <AnimatedContent delay={0.5} distance={50} direction="vertical">
                    <div className="flex items-center justify-between mb-5 h-8">
                        <h3 className="text-[15px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                            Recent Requests
                        </h3>
                        {recentRequests.length > 5 && (
                            <button onClick={() => onNavigate('requests')} className="text-[12px] font-bold text-[#2b83fa] hover:text-[#1a65d1] py-1 px-3 rounded-full hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                                See All
                            </button>
                        )}
                    </div>
                    <div className="space-y-3">
                        {loading ? (
                            [1,2,3].map(i => (
                                <div key={i} className="w-full p-4 rounded-2xl bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] flex items-center justify-between min-h-[74px]">
                                    <div className="flex items-center gap-4 w-full">
                                        <div className="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 animate-pulse flex-shrink-0" />
                                        <div className="space-y-2 w-full max-w-[150px]">
                                            <div className="h-3.5 w-3/4 bg-gray-100 dark:bg-gray-800 animate-pulse rounded" />
                                            <div className="h-2.5 w-full bg-gray-50 dark:bg-gray-900 animate-pulse rounded opacity-60" />
                                        </div>
                                    </div>
                                    <div className="w-16 h-5 bg-gray-50 dark:bg-gray-900 animate-pulse rounded-full opacity-60" />
                                </div>
                            ))
                        ) : recentRequests.length === 0 ? (
                            <div className="p-10 text-center rounded-3xl border-2 border-dashed border-[#0000000a] dark:border-[#ffffff0a]">
                                <FiSend className="w-8 h-8 mx-auto mb-2 text-gray-300 dark:text-gray-500" />
                                <p className="text-gray-400 dark:text-gray-500 text-[14px] font-medium italic">No requests yet.</p>
                            </div>
                        ) : recentRequests.slice(0, 3).map(req => (
                            <button key={req.id} onClick={() => onNavigate('requests')}
                                className="w-full p-4 rounded-[20px] bg-white dark:bg-[#1c1e21] border border-white/70 dark:border-white/[0.06] shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 text-left flex items-center justify-between group min-h-[74px]">
                                <div className="flex items-center gap-4 overflow-hidden">
                                    <div className={`w-12 h-12 rounded-xl flex-shrink-0 flex items-center justify-center text-white text-[13px] font-bold shadow-sm ${
                                        req.status === 'pending' ? 'bg-gradient-to-br from-amber-400 to-orange-500' : req.status === 'approved' ? 'bg-gradient-to-br from-emerald-400 to-teal-500' : 'bg-gradient-to-br from-red-400 to-rose-600'
                                    }`}>
                                        {req.requested_id?.charAt(0).toUpperCase() || '?'}
                                    </div>
                                    <div className="min-w-0">
                                        <h4 className="font-bold text-[#111111] dark:text-white text-[13.5px] truncate">{req.requested_id}</h4>
                                        <p className="text-[11.5px] text-gray-500 dark:text-gray-400 truncate max-w-[200px] font-medium">{req.location_name || req.location_id}</p>
                                    </div>
                                </div>
                                <div className="flex flex-col items-end gap-1 flex-shrink-0">
                                    <span className={`text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border shadow-sm ${
                                        req.status === 'pending' ? 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800/40' :
                                        req.status === 'approved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-400 dark:border-emerald-800/40' :
                                        'bg-red-50 text-red-600 border-red-200 dark:bg-red-900/20 dark:text-red-400 dark:border-red-800/40'
                                    }`}>{req.status}</span>
                                    <div className="px-1.5 py-0.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-[#2b83fa] text-[9px] font-black uppercase tracking-widest opacity-0 group-hover:opacity-100 transition-opacity">
                                        Review
                                    </div>
                                </div>
                            </button>
                        ))}
                    </div>
                </AnimatedContent>
            </div>

            {/* Recent Activity Logs */}
            <div className="mt-12">
                <div className="bg-white dark:bg-[#1c1e21] border border-white/70 dark:border-white/[0.06] rounded-[24px] p-5 sm:p-6 shadow-sm flex flex-col">
                    {/* Header Inside Container */}
                    <div className="flex items-center justify-between mb-5 h-8">
                        <h3 className="text-[14px] font-bold text-[#111111] dark:text-white uppercase tracking-wider flex items-center gap-2">
                            <FiActivity className="w-4 h-4 text-[#2b83fa]" /> Recent Activity
                        </h3>
                        <button
                            onClick={() => onNavigate('activity')}
                            className="text-[12px] font-bold text-[#2b83fa] hover:text-[#1a65d1] py-1 px-3 rounded-full hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                        >
                            See All
                        </button>
                    </div>

                    <div className="flex flex-col gap-3">
                    {loading ? (
                        [...Array(5)].map((_, i) => (
                            <div key={i} className="h-[72px] rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />
                        ))
                    ) : logs.length === 0 ? (
                        <div className="py-12 text-center rounded-2xl border-2 border-dashed border-[#0000000a] dark:border-[#ffffff0a]">
                            <FiActivity className="w-8 h-8 mx-auto mb-2 text-gray-300 dark:text-gray-600" />
                            <p className="text-[13px] text-gray-400 dark:text-gray-500 font-medium italic">No activity yet.</p>
                        </div>
                    ) : (() => {
                    const totalPages = Math.ceil(logs.length / ITEMS_PER_PAGE);
                    const currentLogs = logs.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

                    return (
                        <>
                            {currentLogs.map((log: any, idx: number) => {
                                const isNeg = typeof log.amount === 'number' && log.amount < 0;
                                const isFreeTrial = log.amount === 0;
                                const type = log.type === 'message' && log.amount === undefined ? 'message'
                                    : (isNeg || log.type === 'deduction' || log.type === 'credit_usage' || isFreeTrial) ? 'credit_usage'
                                    : log.amount !== undefined || log.type === 'top_up' || log.type === 'credit_purchase' ? 'credit_purchase'
                                    : 'message';
                                const ts = log.timestamp || log.date_created || log.created_at;
                                const time = ts ? new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                                const date = ts ? new Date(ts).toLocaleDateString([], { month: 'short', day: 'numeric' }) : '';
                                const locId = log.location_id || log.account_id;
                                const account = accounts.find((a: any) => a.id === locId || a.location_id === locId);

                                return (
                                    <div
                                        key={`log-${idx}-${log.id || log.transaction_id || 'none'}`}
                                        onClick={() => onNavigate('activity')}
                                        className="group min-h-[74px] flex items-center gap-4 p-4 rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 cursor-pointer"
                                    >
                                        <div className={`w-10 h-10 rounded-[16px] flex items-center justify-center flex-shrink-0 shadow-sm ring-1 ring-inset ring-black/5 dark:ring-white/10 group-hover:scale-110 transition-transform duration-300 ${
                                            type === 'message'         ? 'bg-blue-50 dark:bg-blue-900/20 text-[#2b83fa]' :
                                            type === 'credit_purchase' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400' :
                                                                         'bg-purple-50 dark:bg-purple-900/20 text-purple-600 dark:text-purple-400'
                                        }`}>
                                            {type === 'message'         && <FiMessageSquare className="w-5 h-5" />}
                                            {type === 'credit_purchase' && <FiCreditCard className="w-5 h-5" />}
                                            {type === 'credit_usage'    && <FiActivity className="w-5 h-5" />}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center justify-between mb-1 gap-2">
                                                <p className="text-[14px] font-bold text-[#111111] dark:text-white leading-tight">
                                                    {type === 'message'         ? `SMS to ${log.number || log.to || 'Unknown'}` :
                                                     type === 'credit_purchase' ? `+${log.amount?.toLocaleString()} credits added` :
                                                     isFreeTrial                ? 'Free trial SMS sent' :
                                                                                  `${Math.abs(log.amount)} credits used`}
                                                </p>
                                                <span className="text-[11px] uppercase font-bold text-[#9aa0a6] tracking-wider whitespace-nowrap flex-shrink-0">{time}</span>
                                            </div>
                                            <div className="flex items-center justify-between gap-3">
                                                <p className="text-[13px] text-gray-500 dark:text-gray-400 flex-1 leading-snug">
                                                    {account?.location_name || (locId ? locId.substring(0, 12) + '...' : 'System')}
                                                    {log.sender_name || log.sendername ? ` - via ${log.sender_name || log.sendername}` : ''}
                                                </p>
                                                <div className="flex items-center gap-1.5 flex-shrink-0 opacity-80">
                                                    <span className="text-[10px] font-bold text-[#111111] dark:text-white uppercase tracking-wider">{date}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                            {totalPages > 1 && (
                                <div className="flex items-center justify-between mt-3 pt-3 border-t border-[#e5e5e5] dark:border-white/5">
                                    <div className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6] uppercase font-bold tracking-wider">
                                        Showing <b className="text-[#111111] dark:text-white">{(currentPage - 1) * ITEMS_PER_PAGE + 1}</b> - <b className="text-[#111111] dark:text-white">{Math.min(currentPage * ITEMS_PER_PAGE, logs.length)}</b> of <b className="text-[#111111] dark:text-white">{logs.length}</b>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <button disabled={currentPage === 1} onClick={() => setCurrentPage(p => p - 1)} className="p-1 rounded-lg text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 disabled:opacity-30 transition-colors"><FiChevronLeft className="w-4 h-4" /></button>
                                        {Array.from({ length: Math.min(5, totalPages - Math.floor((currentPage - 1) / 5) * 5) }, (_, i) => Math.floor((currentPage - 1) / 5) * 5 + 1 + i).map(page => (
                                            <button key={page} onClick={() => setCurrentPage(page)} className={`w-6 h-6 rounded-md text-[11px] font-bold flex items-center justify-center transition-all ${currentPage === page ? 'bg-[#2b83fa] text-white shadow-sm' : 'text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-white/5'}`}>{page}</button>
                                        ))}
                                        <button disabled={currentPage === totalPages} onClick={() => setCurrentPage(p => p + 1)} className="p-1 rounded-lg text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 disabled:opacity-30 transition-colors"><FiChevronRight className="w-4 h-4" /></button>
                                    </div>
                                </div>
                            )}
                        </>
                    );
                })()}
                    </div>
                </div>
            </div>
            </div>

        </div>
    );
};
