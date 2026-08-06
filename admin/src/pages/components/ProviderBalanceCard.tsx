import React from 'react';
import { FiRefreshCw, FiServer, FiAlertTriangle, FiXCircle, FiGlobe, FiRadio, FiMail, FiKey, FiLink } from 'react-icons/fi';

export interface ProviderBalance {
    name: string;
    status: 'active' | 'inactive' | 'error';
    credits: number;
    /** Aggregated total credits across all connected API keys for this provider */
    total_credits?: number;
    /** Number of connected API key accounts */
    connected_accounts?: number;
    /** Total API key accounts discovered (connected + unconfigured) */
    total_accounts?: number;
    configured: boolean;
    is_active: boolean;
    warning: boolean;
    critical: boolean;
    error: string | null;
}

export interface UniSmsBalance extends ProviderBalance {
    name: 'UniSMS';
    email: string | null;
    sid_tokens: number | null;
}

export interface ProviderSummaryEntry {
    name: string;
    status: 'active' | 'inactive' | 'error';
    credits?: number;
    total_credits?: number;
    connected_accounts?: number;
    total_accounts?: number;
    is_active: boolean;
    warning: boolean;
    critical: boolean;
}

export interface ProviderBalancesResponse {
    status: 'success';
    fetched_at: string;
    active_provider: 'semaphore' | 'unisms' | 'auto_failover';
    providers: {
        semaphore: ProviderBalance;
        unisms: UniSmsBalance;
    };
    /** Aggregated summary across all connected API keys per provider */
    summary?: {
        semaphore?: ProviderSummaryEntry;
        unisms?: ProviderSummaryEntry;
    };
}

export interface ProviderBalanceCardProps {
    semaphore?: ProviderBalance | null;
    unisms?: UniSmsBalance | null;
    activeProvider?: string | null;
    fetchedAt?: string | null;
    isLoading?: boolean;
    error?: string | null;
    onRefresh?: () => void;
    /** Aggregated summary from /api/admin/provider-balances response */
    summary?: ProviderBalancesResponse['summary'];
}

function isProviderConfigured(provider?: Partial<ProviderBalance & ProviderSummaryEntry> | null): boolean {
    if (!provider) return false;
    if (typeof provider.configured === 'boolean') return provider.configured;
    if (typeof provider.connected_accounts === 'number') return provider.connected_accounts > 0;
    return provider.status === 'active';
}

function getProviderStatusColor(provider?: Partial<ProviderBalance & ProviderSummaryEntry> | null): 'green' | 'yellow' | 'red' | 'gray' {
    if (!provider || provider.status === 'error' || !isProviderConfigured(provider)) return 'gray';
    if (provider.critical) return 'red';
    if (provider.warning) return 'yellow';
    return 'green';
}

function getProviderStatusLabel(provider?: Partial<ProviderBalance & ProviderSummaryEntry> | null): string | null {
    if (!provider) return null;
    if (provider.status === 'error') return 'Unreachable';
    if (!isProviderConfigured(provider)) return 'Not Configured';
    if (provider.critical) return 'Critical — Top Up Now';
    if (provider.warning) return 'Low Balance';
    return null; // Don't display Healthy badge per design
}

function formatLastUpdated(fetchedAt?: string | null): string {
    if (!fetchedAt) return 'Never';
    const date = new Date(fetchedAt);
    if (Number.isNaN(date.getTime())) return 'Just now';
    const diffMs = Date.now() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1) return 'Just now';
    if (diffMin === 1) return '1 minute ago';
    return `${diffMin} minutes ago`;
}

export const ProviderBalanceCard: React.FC<ProviderBalanceCardProps> = ({
    semaphore,
    unisms,
    fetchedAt,
    isLoading = false,
    error = null,
    onRefresh,
    summary,
}) => {
    const isInitialLoading = isLoading && !semaphore && !unisms;

    const renderProviderCol = (
        providerKey: 'semaphore' | 'unisms',
        providerData?: ProviderBalance | UniSmsBalance | null
    ) => {
        if (!providerData && isInitialLoading) {
            return (
                <div className="p-5 rounded-2xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-transparent animate-pulse space-y-4 min-h-[160px]">
                    <div className="h-4 w-28 rounded bg-gray-200 dark:bg-white/10" />
                    <div className="h-8 w-36 rounded bg-gray-200 dark:bg-white/10" />
                </div>
            );
        }

        const isSemaphore = providerKey === 'semaphore';
        const name = providerData?.name || (isSemaphore ? 'Semaphore' : 'UniSMS');
        const summaryEntry = summary?.[providerKey];
        const mergedProvider: Partial<ProviderBalance & ProviderSummaryEntry> = {
            ...providerData,
            ...summaryEntry,
            configured: providerData?.configured ?? (summaryEntry?.connected_accounts ? summaryEntry.connected_accounts > 0 : summaryEntry?.status === 'active'),
        };
        // Prefer aggregated total_credits from summary; fall back to per-record credits
        const credits = summaryEntry?.total_credits ?? (typeof providerData?.credits === 'number' ? providerData.credits : 0);
        const connectedAccounts = summaryEntry?.connected_accounts ?? providerData?.connected_accounts;
        const totalAccounts = summaryEntry?.total_accounts ?? providerData?.total_accounts;
        const statusColor = getProviderStatusColor(mergedProvider);
        const statusLabel = getProviderStatusLabel(mergedProvider);
        const uniData = !isSemaphore ? (providerData as UniSmsBalance | undefined) : undefined;

        // Container borders & backgrounds based on health state
        let containerStyle = 'bg-[#f7f7f7] dark:bg-[#0d0e10] border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10';
        if (mergedProvider.status === 'error' || !isProviderConfigured(mergedProvider)) {
            containerStyle = 'bg-[#f7f7f7]/70 dark:bg-[#0d0e10]/70 border-gray-200 dark:border-white/5 opacity-90';
        } else if (statusColor === 'red') {
            containerStyle = 'bg-red-500/[0.03] border-red-500/40 dark:border-red-500/50 shadow-sm shadow-red-500/10 animate-pulse';
        } else if (statusColor === 'yellow') {
            containerStyle = 'bg-amber-500/[0.03] border-amber-500/40 dark:border-amber-500/40 shadow-sm shadow-amber-500/10';
        }

        // Status badge style (for Warning, Critical, Unreachable, Unconfigured)
        let badgeStyle = '';
        let statusIcon = null;
        if (statusColor === 'red') {
            badgeStyle = 'bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/30';
            statusIcon = <FiXCircle className="w-3.5 h-3.5 text-red-500" />;
        } else if (statusColor === 'yellow') {
            badgeStyle = 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30';
            statusIcon = <FiAlertTriangle className="w-3.5 h-3.5 text-amber-500" />;
        } else if (statusColor === 'gray') {
            badgeStyle = 'bg-gray-500/10 text-gray-600 dark:text-gray-400 border-gray-500/20';
            statusIcon = <FiAlertTriangle className="w-3.5 h-3.5 text-gray-400" />;
        }

        return (
            <div className={`p-5 rounded-2xl border transition-all duration-300 flex flex-col justify-between group relative overflow-hidden min-h-[160px] ${containerStyle}`}>
                <div>
                    {/* Header: Provider Name */}
                    <div className="flex items-center justify-between gap-2 mb-3">
                        <div className="flex items-center gap-2">
                            <div className="w-7 h-7 rounded-lg bg-white dark:bg-white/10 flex items-center justify-center text-xs shadow-sm border border-black/5 dark:border-white/10">
                                {isSemaphore ? <FiGlobe className="w-4 h-4 text-emerald-500" /> : <FiRadio className="w-4 h-4 text-purple-500" />}
                            </div>
                            <h4 className="font-extrabold text-[#111111] dark:text-white text-[14.5px] tracking-wide uppercase">
                                {name}
                            </h4>
                        </div>
                    </div>

                    {/* Credits Counter */}
                    <div className="my-2">
                        <div className="flex items-baseline gap-2">
                            <span className="text-3xl sm:text-4xl font-black text-[#111111] dark:text-white tracking-tight leading-none">
                                {credits.toLocaleString()}
                            </span>
                            <span className="text-[13px] font-bold text-gray-500 dark:text-gray-400">
                                credits
                            </span>
                        </div>
                        {typeof connectedAccounts === 'number' && (
                            <div className="mt-1 flex items-center gap-1 text-[11px] font-semibold text-gray-400 dark:text-gray-500">
                                <FiLink className="w-3 h-3" />
                                {typeof totalAccounts === 'number' && totalAccounts > connectedAccounts
                                    ? `${connectedAccounts} of ${totalAccounts} accounts connected`
                                    : `${connectedAccounts} connected account${connectedAccounts !== 1 ? 's' : ''}`
                                }
                            </div>
                        )}
                    </div>
                </div>

                {/* Status Callout & Provider Details (Only rendered when relevant) */}
                {(statusLabel || (uniData && (uniData.email || typeof uniData.sid_tokens === 'number')) || providerData?.error) && (
                    <div className="mt-2 space-y-2 pt-2 border-t border-black/5 dark:border-white/5">
                        <div className="flex items-center justify-between gap-2 flex-wrap">
                            {statusLabel && (
                                <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-bold border ${badgeStyle}`}>
                                    {statusIcon}
                                    {statusLabel}
                                </span>
                            )}

                            {/* Extra metadata for UniSMS */}
                            {uniData && (uniData.email || typeof uniData.sid_tokens === 'number') && (
                                <div className="flex items-center gap-2 text-[11px] font-medium text-gray-500 dark:text-gray-400">
                                    {uniData.email && (
                                        <span className="flex items-center gap-1 bg-white/60 dark:bg-white/5 px-2 py-0.5 rounded border border-black/5 dark:border-white/5" title="Billing Email">
                                            <FiMail className="w-3 h-3 text-purple-400" />
                                            <span className="truncate max-w-[130px]">{uniData.email}</span>
                                        </span>
                                    )}
                                    {typeof uniData.sid_tokens === 'number' && (
                                        <span className="flex items-center gap-1 bg-white/60 dark:bg-white/5 px-2 py-0.5 rounded border border-black/5 dark:border-white/5" title="Sender ID Tokens">
                                            <FiKey className="w-3 h-3 text-amber-400" />
                                            <span>SID Tokens: <b>{uniData.sid_tokens}</b></span>
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Provider Error Message Callout */}
                        {providerData?.error && (
                            <div className="p-2.5 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/40 text-red-600 dark:text-red-400 text-[11.5px] font-semibold flex items-start gap-2">
                                <FiAlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
                                <span className="break-all">{providerData.error}</span>
                            </div>
                        )}
                    </div>
                )}
            </div>
        );
    };

    return (
        <div className="bg-white dark:bg-[#1c1e21] border border-white/70 dark:border-white/[0.06] rounded-[24px] p-5 sm:p-6 shadow-sm flex flex-col">
            {/* Header matching Recent Activity */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                <h3 className="text-[14px] font-bold text-[#111111] dark:text-white uppercase tracking-wider flex items-center gap-2">
                    <FiServer className="w-4 h-4 text-[#2b83fa]" />
                    SMS Provider Balances
                </h3>

                <div className="flex items-center gap-3">
                    <span className="text-[11.5px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6]">
                        Last updated: <b className="text-[#111111] dark:text-white font-bold">{formatLastUpdated(fetchedAt)}</b>
                    </span>
                    {onRefresh && (
                        <button
                            type="button"
                            onClick={onRefresh}
                            disabled={isLoading}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-[#e5e5e5] dark:border-white/10 bg-[#f7f7f7] dark:bg-[#0d0e10] text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-all hover:bg-white dark:hover:bg-white/5 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm"
                            title="Refresh Provider Balances"
                        >
                            <FiRefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin text-[#2b83fa]' : ''}`} />
                            Refresh
                        </button>
                    )}
                </div>
            </div>

            {/* Network Fetch Error Toast/Banner */}
            {error && (
                <div className="mb-4 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/40 text-amber-700 dark:text-amber-300 text-[12px] font-semibold flex items-center gap-2.5">
                    <FiAlertTriangle className="w-4 h-4 flex-shrink-0 text-amber-500" />
                    <span>{error}</span>
                </div>
            )}

            {/* Provider Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                {renderProviderCol('semaphore', semaphore)}
                {renderProviderCol('unisms', unisms)}
            </div>
        </div>
    );
};
