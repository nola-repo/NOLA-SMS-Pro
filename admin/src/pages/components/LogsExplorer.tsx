// @ts-nocheck
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
    FiActivity,
    FiAlertCircle,
    FiChevronLeft,
    FiChevronRight,
    FiCreditCard,
    FiRefreshCw,
    FiSearch,
    FiSend,
    FiXCircle,
} from 'react-icons/fi';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';
import { ADMIN_API_LOG_EVENT, getStoredAdminApiLogs } from '../../utils/apiFetch';

const POLL_INTERVAL = 15000;
const LOG_POLL_INTERVAL = 5000;
const ADMIN_LOGS_API = '/api/admin_sender_requests.php?action=logs';
const LOGS_PER_PAGE = 10;

const metricTone = (state: 'ok' | 'warn' | 'bad') => {
    if (state === 'ok') return 'border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#1a1b1e] hover:border-emerald-500/20 dark:hover:border-emerald-500/30';
    if (state === 'warn') return 'border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#1a1b1e] hover:border-amber-500/20 dark:hover:border-amber-500/30';
    return 'border-[#e5e5e5] dark:border-white/5 bg-white dark:bg-[#1a1b1e] hover:border-red-500/20 dark:hover:border-red-500/30';
};

const iconTone = (state: 'ok' | 'warn' | 'bad') => {
    if (state === 'ok') return 'text-emerald-500 bg-emerald-50 dark:bg-emerald-500/10';
    if (state === 'warn') return 'text-amber-500 bg-amber-50 dark:bg-amber-500/10';
    return 'text-red-500 bg-red-50 dark:bg-red-500/10';
};

export function getDisplayLogType(log: {
    type?: string;
    source?: string;
    summary?: string;
    message?: string;
    direction?: string;
}): string {
    if (log.type && log.type !== 'SMS') {
        return log.type;
    }
    const source = log.source || '';
    const content = log.summary || log.message || '';
    if (source === 'send_sms' || content.includes('Send PH SMS')) {
        return 'Send PH SMS';
    }
    if (source === 'ghl_provider') {
        return 'Conversation Provider';
    }
    if (source === 'inbound' || log.direction === 'inbound') {
        return 'Inbound SMS';
    }
    return log.type || 'SMS';
}

export const LogsExplorer: React.FC = () => {
    const [dbConnected, setDbConnected] = useState<boolean>(true);
    const [stats, setStats] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [logsLoading, setLogsLoading] = useState(true);
    const [error, setError] = useState('');
    const [logsError, setLogsError] = useState('');
    const [logs, setLogs] = useState<any[]>([]);
    const [diagnosticLogs, setDiagnosticLogs] = useState<any[]>([]);
    const [apiDebugLogs, setApiDebugLogs] = useState<any[]>(() => getStoredAdminApiLogs());
    const [currentLogPage, setCurrentLogPage] = useState(1);
    const [logSearch, setLogSearch] = useState('');
    const [logTypeFilter, setLogTypeFilter] = useState('ALL');
    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    const fetchLogsOverview = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        setError('');

        try {
            const res = await adminFetch('/api/v2/admin_health', { headers: getAdminAuthHeaders() });
            const json = await res.json().catch(() => ({}));

            if (res.ok && json.status === 'success') {
                const healthData = json.data;
                setDbConnected(healthData.database_connected !== false);
                setStats(healthData.stats || null);
                setDiagnosticLogs(Array.isArray(healthData.logs) ? healthData.logs : []);
            } else {
                setError(json.message || 'Failed to fetch logs diagnostics.');
            }
            setLastRefreshed(new Date());
        } catch (e) {
            setError('Network error. Could not refresh logs diagnostics.');
        } finally {
            if (isInitial) setLoading(false);
        }
    }, []);

    const fetchLogs = useCallback(async (isInitial = false) => {
        if (isInitial) setLogsLoading(true);
        setLogsError('');

        try {
            const res = await adminFetch(ADMIN_LOGS_API, { headers: getAdminAuthHeaders() });
            const json = await res.json().catch(() => ({}));

            if (res.ok && json.status === 'success') {
                setLogs(Array.isArray(json.data) ? json.data : []);
            } else {
                setLogsError(json.message || 'Failed to load live system logs.');
            }
        } catch (e) {
            setLogsError('Network error. Could not refresh live system logs.');
        } finally {
            if (isInitial) setLogsLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchLogsOverview(true);
        const timer = setInterval(() => fetchLogsOverview(false), POLL_INTERVAL);
        return () => clearInterval(timer);
    }, [fetchLogsOverview]);

    useEffect(() => {
        fetchLogs(true);
        const timer = setInterval(() => fetchLogs(false), LOG_POLL_INTERVAL);
        return () => clearInterval(timer);
    }, [fetchLogs]);

    useEffect(() => {
        const refreshStoredLogs = () => setApiDebugLogs(getStoredAdminApiLogs());
        refreshStoredLogs();

        const handleApiLog = (event: Event) => {
            const detail = (event as CustomEvent).detail;
            if (!detail) return;
            setApiDebugLogs((current) => {
                const next = [detail, ...current.filter((item) => item.id !== detail.id)];
                return next.slice(0, 200);
            });
        };

        window.addEventListener(ADMIN_API_LOG_EVENT, handleApiLog as EventListener);
        window.addEventListener('storage', refreshStoredLogs);
        return () => {
            window.removeEventListener(ADMIN_API_LOG_EVENT, handleApiLog as EventListener);
            window.removeEventListener('storage', refreshStoredLogs);
        };
    }, []);

    const logsStatus = useMemo(() => {
        if (!dbConnected) return { label: 'System Offline', style: 'from-red-500/10 to-rose-500/10 border-red-500/20 text-red-600 dark:text-red-400', dot: 'bg-red-500' };
        if ((stats?.failed_messages ?? 0) >= 10) return { label: 'Attention Needed (Failed Sends)', style: 'from-red-500/10 to-rose-500/10 border-red-500/20 text-red-600 dark:text-red-400', dot: 'bg-red-500' };
        if (stats?.delivery_rate !== undefined && stats.delivery_rate < 85) return { label: 'Degraded Performance (Low Success Rate)', style: 'from-amber-500/10 to-orange-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400', dot: 'bg-amber-500' };
        if ((stats?.low_balance_subaccounts ?? 0) > 0) return { label: 'Attention Needed (Low Credits)', style: 'from-amber-500/10 to-orange-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400', dot: 'bg-amber-500' };
        return { label: 'All Systems Operational', style: 'from-emerald-500/10 to-teal-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400', dot: 'bg-emerald-500' };
    }, [dbConnected, stats]);

    const metricCards = [
        {
            label: 'SMS Success Rate',
            value: `${stats?.delivery_rate ?? 100}%`,
            detail: `${stats?.sent_messages ?? 0} sent of ${stats?.total_messages ?? 0} messages`,
            icon: <FiSend />,
            state: (stats?.delivery_rate ?? 100) >= 95 ? 'ok' : (stats?.delivery_rate ?? 100) >= 80 ? 'warn' : 'bad',
        },
        {
            label: 'Failed Sends',
            value: (stats?.failed_messages ?? 0).toLocaleString(),
            detail: 'Failed messages in window',
            icon: <FiXCircle />,
            state: (stats?.failed_messages ?? 0) === 0 ? 'ok' : (stats?.failed_messages ?? 0) < 10 ? 'warn' : 'bad',
        },
        {
            label: 'Billing Health',
            value: stats?.low_balance_subaccounts ? `${stats.low_balance_subaccounts} low` : 'Healthy',
            detail: `${stats?.total_subaccounts ?? 0} subaccounts monitored`,
            icon: <FiCreditCard />,
            state: (stats?.low_balance_subaccounts ?? 0) === 0 ? 'ok' : (stats?.low_balance_subaccounts ?? 0) < 5 ? 'warn' : 'bad',
        },
    ];

    const logRows = useMemo(() => {
        const timestampOf = (log: any): string => (
            log.timestamp
            || log.ts
            || log.date_created
            || log.created_at
            || log.updated_at
            || ''
        );

        const normalizeStatus = (value: any): string => String(value || '').toLowerCase();
        const titleCase = (value: string): string => value
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (char) => char.toUpperCase());

        const typeOf = (log: any): string => {
            const rawType = String(log.type || log.event || '').toLowerCase();
            if (rawType === 'admin_login') return 'Admin Login';
            if (rawType === 'api_connection' || rawType === 'http_response' || rawType === 'http_error') return 'API Connection';
            if (rawType === 'auth') return 'Auth';
            if (rawType === 'request') return 'Request';
            if (rawType === 'response') return 'Response';

            const resolvedDisplayType = getDisplayLogType(log);
            if (['Send PH SMS', 'Conversation Provider', 'Inbound SMS'].includes(resolvedDisplayType)) {
                return resolvedDisplayType;
            }

            if (rawType === 'message' && (log.amount === undefined || log.amount === null)) return resolvedDisplayType;

            const amount = log.amount;
            const isNegative = (typeof amount === 'number' && amount < 0) || (typeof amount === 'string' && amount.startsWith('-'));
            if (isNegative || rawType === 'deduction' || rawType === 'credit_usage' || amount === 0) return 'Credits Used';
            if (amount !== undefined || rawType === 'top_up' || rawType === 'credit_purchase' || rawType === 'admin_adjustment') return 'Credits';
            if (rawType === 'sender_request') return 'Sender Request';
            if (rawType === 'error') return 'Error';
            if (rawType === 'info') return 'System';
            return rawType ? titleCase(rawType) : resolvedDisplayType;
        };

        const severityOf = (log: any): 'INFO' | 'WARN' | 'ERROR' => {
            const level = String(log.level || log.severity || '').toUpperCase();
            if (['ERROR', 'CRITICAL', 'FATAL'].includes(level)) return 'ERROR';
            if (['WARN', 'WARNING'].includes(level)) return 'WARN';
            if (['INFO', 'NOTICE', 'DEBUG'].includes(level)) return 'INFO';

            const numericStatus = Number(log.status ?? log.status_code ?? log.http_status ?? 0);
            if (numericStatus >= 500 || numericStatus === 0 && (log.type === 'api_connection' || log.event === 'HTTP_ERROR')) return 'ERROR';
            if (numericStatus >= 400) return 'WARN';

            const status = normalizeStatus(log.status || log.delivery_status);
            if (['failed', 'rejected', 'revoked', 'error', 'denied', 'undelivered'].includes(status)) return 'ERROR';
            if (['pending', 'queued', 'processing', 'requested'].includes(status)) return 'WARN';
            return 'INFO';
        };

        const summaryOf = (log: any): string => {
            if (log.summary) return String(log.summary);

            const type = typeOf(log);
            const status = normalizeStatus(log.status || log.delivery_status) || 'recorded';
            const loc = log.location_id || log.locationId || log.account_id || log.company_id || 'system';
            const requestId = log.request_id ? ` | request_id=${log.request_id}` : '';

            if (type === 'Admin Login' || type === 'API Connection' || type === 'Request' || type === 'Response') {
                const method = log.method || 'GET';
                const endpoint = log.endpoint || log.uri || log.path || 'unknown endpoint';
                const httpStatus = log.status !== undefined ? ` -> ${log.status}${log.status_text ? ` ${log.status_text}` : ''}` : '';
                const duration = log.duration_ms !== undefined ? ` (${log.duration_ms}ms)` : '';
                return `${type} | ${method} ${endpoint}${httpStatus}${duration}${requestId}`;
            }

            if (type === 'Auth') {
                const outcome = log.outcome || status;
                const method = log.method ? ` via ${log.method}` : '';
                return `Auth ${outcome}${method} | ${loc}${requestId}`;
            }

            if (type === 'Error') {
                return `${log.message || 'Backend error'} | ${log.file || loc}${log.line ? `:${log.line}` : ''}${requestId}`;
            }

            if (type === 'SMS' || type === 'Send PH SMS' || type === 'Conversation Provider' || type === 'Inbound SMS') {
                const target = log.number || log.to || log.phone || 'unknown recipient';
                const body = String(log.message || log.body || 'No message content').replace(/\s+/g, ' ').trim();
                return `${type} ${status} to ${target} | ${body}`;
            }

            if (type === 'Sender Request') {
                return `Sender request ${status} | ${log.requested_id || log.sender_id || log.sendername || 'unknown sender'} | ${loc}`;
            }

            if (type === 'Credits' || type === 'Credits Used') {
                const amount = log.amount ?? 0;
                const balance = log.balance_after !== undefined ? ` | balance=${log.balance_after}` : '';
                return `${type} amount=${amount}${balance} | ${loc}`;
            }

            if (log.message) return `${log.message} | ${loc}${requestId}`;
            return `${type} ${status} | ${loc}${requestId}`;
        };

        const failureReasonOf = (log: any): string => {
            if (severityOf(log) !== 'ERROR') return '';

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

        const allLogs = [...apiDebugLogs, ...diagnosticLogs, ...logs];
        const seen = new Set<string>();

        return allLogs
            .filter((log) => typeOf(log) !== 'API Connection')
            .filter((log) => {
                const key = String(log.id || log.request_id || `${timestampOf(log)}-${typeOf(log)}-${summaryOf(log)}`);
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            })
            .sort((a, b) => {
                const timeA = Date.parse(timestampOf(a) || '') || 0;
                const timeB = Date.parse(timestampOf(b) || '') || 0;
                return timeB - timeA;
            })
            .map((log, index) => {
                const rawTime = timestampOf(log);
                const parsed = rawTime ? new Date(rawTime) : null;
                return {
                    id: log.id || log.request_id || `${rawTime}-${index}`,
                    severity: severityOf(log),
                    date: parsed && !Number.isNaN(parsed.getTime())
                        ? parsed.toLocaleDateString([], { year: 'numeric', month: 'short', day: '2-digit' })
                        : 'Today',
                    time: parsed && !Number.isNaN(parsed.getTime())
                        ? parsed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
                        : 'Just now',
                    type: typeOf(log),
                    summary: summaryOf(log),
                    failureReason: failureReasonOf(log),
                };
            });
    }, [apiDebugLogs, diagnosticLogs, logs]);

    const normalizedLogSearch = logSearch.trim().toLowerCase();
    const filteredLogRows = useMemo(() => {
        let rows = logRows;

        if (logTypeFilter !== 'ALL') {
            rows = rows.filter((row) => row.type === logTypeFilter);
        }

        if (!normalizedLogSearch) return rows;

        return rows.filter((row) => [
            row.severity,
            row.date,
            row.time,
            row.type,
            row.summary,
            row.failureReason,
        ].join(' ').toLowerCase().includes(normalizedLogSearch));
    }, [logRows, logTypeFilter, normalizedLogSearch]);

    const totalLogPages = Math.max(1, Math.ceil(filteredLogRows.length / LOGS_PER_PAGE));
    const paginatedLogRows = useMemo(() => {
        const start = (currentLogPage - 1) * LOGS_PER_PAGE;
        return filteredLogRows.slice(start, start + LOGS_PER_PAGE);
    }, [currentLogPage, filteredLogRows]);

    useEffect(() => {
        setCurrentLogPage(1);
    }, [normalizedLogSearch, logTypeFilter]);

    useEffect(() => {
        if (currentLogPage > totalLogPages) setCurrentLogPage(totalLogPages);
    }, [currentLogPage, totalLogPages]);

    return (
        <div className="space-y-5 text-[#111111] dark:text-white">
            {/* Header section with real-time logs indicator */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl border bg-white dark:bg-[#1a1b1e] border-[#e5e5e5] dark:border-white/5 shadow-sm transition-all duration-300">
                <div className="flex items-center gap-3">
                    <div className={`relative flex h-3 w-3`}>
                        <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${logsStatus.dot}`}></span>
                        <span className={`relative inline-flex rounded-full h-3 w-3 ${logsStatus.dot}`}></span>
                    </div>
                    <div>
                        <h2 className="text-[15px] font-bold tracking-tight">{logsStatus.label}</h2>
                        <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5 font-medium">
                            Last checked: {lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    <button
                        onClick={() => fetchLogsOverview(true)}
                        className="inline-flex items-center gap-2 rounded-xl border border-[#e5e5e5] bg-white px-4 py-2 text-[12px] font-bold text-[#6e6e73] shadow-sm transition-all hover:bg-[#f7f7f7] hover:text-[#111111] dark:border-white/10 dark:bg-[#1a1b1e] dark:text-[#9aa0a6] dark:hover:bg-white/5 dark:hover:text-white"
                    >
                        <FiRefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                        Refresh Diagnostics
                    </button>
                </div>
            </div>

            {error && (
                <div className="flex items-center gap-2.5 rounded-xl border border-red-100 bg-red-50/70 dark:border-red-900/20 dark:bg-red-900/10 px-4 py-3 text-[12px] font-semibold text-red-600 dark:text-red-400">
                    <FiAlertCircle className="h-4 w-4 flex-shrink-0" />
                    {error}
                </div>
            )}

            {/* Logs metric cards grid */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {loading && !stats ? [...Array(3)].map((_, index) => (
                    <div key={index} className="rounded-2xl border border-[#e5e5e5] bg-white p-5 shadow-sm dark:border-white/5 dark:bg-[#1a1b1e]">
                        <div className="flex items-start justify-between gap-3">
                            <div className="space-y-3">
                                <div className="h-3 w-28 rounded bg-[#f0f0f0] dark:bg-white/10 animate-pulse" />
                                <div className="h-8 w-20 rounded bg-[#f0f0f0] dark:bg-white/10 animate-pulse" />
                                <div className="h-3 w-44 rounded bg-[#f0f0f0] dark:bg-white/10 animate-pulse" />
                            </div>
                            <div className="h-11 w-11 rounded-xl bg-[#f0f0f0] dark:bg-white/10 animate-pulse" />
                        </div>
                    </div>
                )) : metricCards.map((card) => (
                    <div key={card.label} className={`rounded-2xl border p-5 shadow-sm hover:shadow-md transition-all duration-300 ${metricTone(card.state)}`}>
                        <div className="flex items-start justify-between gap-3">
                            <div className="space-y-1.5">
                                <div className="text-[10px] font-bold uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">{card.label}</div>
                                <div className="text-[26px] font-extrabold tracking-tight text-[#111111] dark:text-white">{card.value}</div>
                                <div className="text-[11px] font-medium text-[#6e6e73] dark:text-[#9aa0a6] leading-relaxed">{card.detail}</div>
                            </div>
                            <div className={`flex h-11 w-11 items-center justify-center rounded-xl shadow-sm text-[20px] border border-black/5 dark:border-white/5 ${iconTone(card.state)}`}>
                                {card.icon}
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            <section className="overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white shadow-sm dark:border-white/5 dark:bg-[#1a1b1e]">
                <div className="flex flex-col gap-3 border-b border-[#e5e5e5] px-5 py-4 dark:border-white/5 lg:flex-row lg:items-center lg:justify-between">
                    <h3 className="flex items-center gap-2 text-[14px] font-bold text-[#111111] dark:text-white">
                        <FiActivity className="h-4 w-4 text-[#2b83fa]" />
                        Logs Explorer
                    </h3>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <select
                            value={logTypeFilter}
                            onChange={(event) => setLogTypeFilter(event.target.value)}
                            className="h-9 rounded-xl border border-[#e5e5e5] bg-[#f7f7f7] px-3 text-[12px] font-semibold text-[#111111] outline-none transition focus:border-[#2b83fa]/40 focus:bg-white focus:ring-2 focus:ring-[#2b83fa]/10 dark:border-white/5 dark:bg-[#0d0e10] dark:text-white"
                        >
                            <option value="ALL">All Types</option>
                            <option value="Send PH SMS">Send PH SMS</option>
                            <option value="Conversation Provider">Conversation Provider</option>
                            <option value="Inbound SMS">Inbound SMS</option>
                            <option value="SMS">SMS</option>
                        </select>
                        <label className="relative block w-full sm:w-[320px]">
                            <FiSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#9aa0a6]" />
                            <input
                                type="search"
                                value={logSearch}
                                onChange={(event) => setLogSearch(event.target.value)}
                                placeholder="Search logs"
                                className="h-9 w-full rounded-xl border border-[#e5e5e5] bg-[#f7f7f7] pl-9 pr-3 text-[12px] font-semibold text-[#111111] outline-none transition focus:border-[#2b83fa]/40 focus:bg-white focus:ring-2 focus:ring-[#2b83fa]/10 dark:border-white/5 dark:bg-[#0d0e10] dark:text-white dark:placeholder:text-[#6e6e73] dark:focus:bg-[#111216]"
                            />
                        </label>
                        <button
                            onClick={() => fetchLogs(true)}
                            className="inline-flex items-center justify-center gap-2 rounded-xl border border-[#e5e5e5] bg-[#f7f7f7] px-3.5 py-2 text-[12px] font-bold text-[#6e6e73] transition-all hover:text-[#2b83fa] dark:border-white/5 dark:bg-[#0d0e10] dark:text-[#9aa0a6] dark:hover:text-white"
                        >
                            <FiRefreshCw className={`h-3.5 w-3.5 ${logsLoading ? 'animate-spin' : ''}`} />
                            Refresh
                        </button>
                    </div>
                </div>

                {logsError && (
                    <div className="mx-5 mt-4 flex items-center gap-2 rounded-xl border border-red-100 bg-red-50 px-3 py-2 text-[12px] font-semibold text-red-600 dark:border-red-900/20 dark:bg-red-900/10 dark:text-red-400">
                        <FiAlertCircle className="h-4 w-4" />
                        {logsError}
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[980px] table-fixed border-collapse">
                        <thead className="bg-[#f7f7f7] dark:bg-[#0d0e10]">
                            <tr className="border-b border-[#e5e5e5] dark:border-white/5">
                                <th className="w-[90px] px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Severity</th>
                                <th className="w-[130px] px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Date</th>
                                <th className="w-[115px] px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Time</th>
                                <th className="w-[150px] px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Type</th>
                                <th className="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Summary</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#e5e5e5] dark:divide-white/5">
                            {logsLoading && logRows.length === 0 ? (
                                [...Array(LOGS_PER_PAGE)].map((_, index) => (
                                    <tr key={index}>
                                        <td colSpan={5} className="px-5 py-3">
                                            <div className="h-8 rounded-lg bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />
                                        </td>
                                    </tr>
                                ))
                            ) : paginatedLogRows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-5 py-10 text-center text-[13px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6]">
                                        {normalizedLogSearch || logTypeFilter !== 'ALL' ? 'No logs match your filter criteria.' : 'No logs found.'}
                                    </td>
                                </tr>
                            ) : paginatedLogRows.map((row) => (
                                <tr key={row.id} className="transition-colors hover:bg-[#f7f7f7] dark:hover:bg-white/[0.03]">
                                    <td className="px-5 py-3 align-middle">
                                        <span className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-black ${row.severity === 'ERROR' ? 'border-red-200 bg-red-50 text-red-600 dark:border-red-900/30 dark:bg-red-900/10 dark:text-red-400' : row.severity === 'WARN' ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/30 dark:bg-amber-900/10 dark:text-amber-400' : 'border-blue-200 bg-blue-50 text-[#2b83fa] dark:border-blue-900/30 dark:bg-blue-900/10 dark:text-blue-400'}`}>
                                            {row.severity}
                                        </span>
                                    </td>
                                    <td className="px-5 py-3 align-middle font-mono text-[11px] font-bold text-[#6e6e73] dark:text-[#9aa0a6]">{row.date}</td>
                                    <td className="px-5 py-3 align-middle font-mono text-[11px] font-bold text-[#6e6e73] dark:text-[#9aa0a6]">{row.time}</td>
                                    <td className="px-4 py-3 font-semibold text-[#37352f] dark:text-[#ececf1] text-[11px] uppercase tracking-wider">
                                        {row.type}
                                    </td>
                                    <td className="px-5 py-3 align-middle text-[12px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                                        <div className="truncate" title={row.summary}>{row.summary}</div>
                                        {row.failureReason && (
                                            <div className="mt-0.5 truncate text-[11px] font-semibold text-red-600 dark:text-red-400" title={row.failureReason}>
                                                Reason: {row.failureReason}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-col gap-3 border-t border-[#e5e5e5] px-5 py-3 dark:border-white/5 sm:flex-row sm:items-center sm:justify-between">
                    <span className="text-[12px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6]">
                        Showing {filteredLogRows.length === 0 ? 0 : ((currentLogPage - 1) * LOGS_PER_PAGE) + 1}-{Math.min(currentLogPage * LOGS_PER_PAGE, filteredLogRows.length)} of {filteredLogRows.length.toLocaleString()}
                    </span>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            disabled={currentLogPage <= 1}
                            onClick={() => setCurrentLogPage((page) => Math.max(1, page - 1))}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[#e5e5e5] bg-[#f7f7f7] text-[#6e6e73] transition hover:text-[#111111] disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/5 dark:bg-[#0d0e10] dark:text-[#9aa0a6] dark:hover:text-white"
                        >
                            <FiChevronLeft className="h-4 w-4" />
                        </button>
                        <span className="min-w-[88px] text-center text-[12px] font-bold text-[#111111] dark:text-white">
                            Page {currentLogPage} of {totalLogPages}
                        </span>
                        <button
                            type="button"
                            disabled={currentLogPage >= totalLogPages}
                            onClick={() => setCurrentLogPage((page) => Math.min(totalLogPages, page + 1))}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[#e5e5e5] bg-[#f7f7f7] text-[#6e6e73] transition hover:text-[#111111] disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/5 dark:bg-[#0d0e10] dark:text-[#9aa0a6] dark:hover:text-white"
                        >
                            <FiChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </section>
        </div>
    );
};
