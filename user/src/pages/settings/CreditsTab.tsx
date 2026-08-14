import React, { useState, useCallback, useEffect, useRef } from "react";
import {
    FiSend, FiCreditCard, FiCheck, FiAlertCircle,
    FiRefreshCw, FiZap, FiChevronLeft, FiChevronRight, FiGift, FiChevronDown, FiDownload, FiX
} from "react-icons/fi";
import { fetchCreditTransactions, fetchCreditPackages } from "../../api/credits";
import type { CreditTransaction, CreditPackage } from "../../api/credits";
import { generateMonthlyReport } from "../../utils/pdfGenerator";
import { getAccountSettings } from "../../utils/settingsStorage";
import { useGhlLocation } from "../../hooks/useGhlLocation";
import { useRealtimeCreditStatus } from "../../hooks/useRealtimeCreditStatus";
import { fetchAccountProfile, getCachedAccountProfile } from "../../api/account";
import type { AccountProfile } from "../../api/account";
import { useUserProfileContext } from "../../context/UserProfileContext";
import { getSession } from "../../services/authService";
import { devLog } from "../../utils/devLog";
import { sessionSafeStorage } from "../../utils/sessionSafeStorage";
import { safeStorage } from "../../utils/safeStorage";
import { apiFetch } from "../../utils/apiFetch";

const API_BASE_URL = import.meta.env.VITE_API_BASE || '';
const CHECKOUT_ALLOWED_ORIGINS = new Set(['https://sms.nolawebsolutions.com', 'https://nolasmspro.com']);
const CHECKOUT_SESSION_STORAGE_KEY = 'nola_pending_checkout';
const VALID_LOCATION_ID = /^[A-Za-z0-9_-]{8,80}$/;
type CreditTransactionWithFallbacks = CreditTransaction & { timestamp?: string };

type PendingCheckoutSession = {
    state: string;
    locationId: string;
    packageCredits: number;
    createdAt: number;
};

function formatTxDate(iso: string): string {
    try {
        const d = new Date(iso);
        const now = new Date();
        const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const yesterdayStart = new Date(todayStart.getTime() - 86400000);
        const time = d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
        if (d >= todayStart) return `Today, ${time}`;
        if (d >= yesterdayStart) return `Yesterday, ${time}`;
        return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) + ', ' + time;
    } catch {
        return iso;
    }
}

const createCheckoutState = (): string => {
    const webCrypto: Crypto | undefined = globalThis.crypto;
    if (webCrypto?.randomUUID) {
        return webCrypto.randomUUID();
    }
    const bytes = new Uint8Array(16);
    if (webCrypto?.getRandomValues) {
        webCrypto.getRandomValues(bytes);
    } else {
        bytes.forEach((_, index) => {
            bytes[index] = Math.floor(Math.random() * 256);
        });
    }
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
};

const readPendingCheckoutSession = (): PendingCheckoutSession | null => {
    try {
        const raw = sessionSafeStorage.getItem(CHECKOUT_SESSION_STORAGE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
};

const buildSafeCheckoutUrl = (rawUrl: string): URL | null => {
    try {
        const checkoutUrl = new URL(rawUrl);
        if (checkoutUrl.protocol !== 'https:') return null;
        if (!CHECKOUT_ALLOWED_ORIGINS.has(checkoutUrl.origin)) return null;
        return checkoutUrl;
    } catch {
        return null;
    }
};

const SectionHeader: React.FC<{ title: string; subtitle?: string }> = ({ title, subtitle }) => (
    <div className="mb-6">
        <h2 className="text-[18px] font-bold text-[#111111] dark:text-white tracking-tight">{title}</h2>
        {subtitle && <p className="text-[13px] text-[#6e6e73] dark:text-[#94959b] mt-0.5">{subtitle}</p>}
    </div>
);

const Card: React.FC<{ children: React.ReactNode; className?: string }> = ({ children, className = "" }) => (
    <div className={`bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-5 ${className}`}>
        {children}
    </div>
);

const pickText = (...values: Array<string | null | undefined>): string =>
    values.find(value => typeof value === "string" && value.trim() !== "")?.trim() || "";

const joinProfileName = (profile?: Partial<AccountProfile> | null): string =>
    pickText(
        profile?.full_name,
        profile?.name,
        [profile?.firstName, profile?.lastName].filter(Boolean).join(" ")
    );

export const CreditsTab: React.FC = () => {
    const ghlLocationIdFromHook = useGhlLocation();
    const liveProfile = useUserProfileContext();
    const locationId = ghlLocationIdFromHook || getAccountSettings().ghlLocationId;
    const { status: creditStatus, loading: balanceLoading, refresh: refreshCreditStatus } = useRealtimeCreditStatus(locationId);
    const [transactions, setTransactions] = useState<CreditTransaction[]>([]);
    const [txLoading, setTxLoading] = useState(true);
    const [txMonth, setTxMonth] = useState(() => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    });
    const [availableMonths, setAvailableMonths] = useState<{ val: string, label: string, count: number }[]>([]);
    const [currentPage, setCurrentPage] = useState(1);
    const [reportModalOpen, setReportModalOpen] = useState(false);
    const [reportModalMonth, setReportModalMonth] = useState(txMonth);
    const [reportDownloadLoading, setReportDownloadLoading] = useState(false);
    const [reportDownloadError, setReportDownloadError] = useState<string | null>(null);
    const itemsPerPage = 5;

    const [topUpAmount, setTopUpAmount] = useState<number | null>(null);
    const [packages, setPackages] = useState<CreditPackage[]>([]);
    const [submitted, setSubmitted] = useState(false);
    const [checkoutError, setCheckoutError] = useState<string | null>(null);
    const mountedRef = useRef(true);
    const popupRef = useRef<Window | null>(null);
    const popupPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const lastLiveBalanceRef = useRef<number | null>(null);
    const accountSettings = getAccountSettings();
    const cachedReportProfile = locationId
        ? (
            getCachedAccountProfile(locationId, { includeAuth: false, allowExpired: true }) ||
            getCachedAccountProfile(locationId, { allowExpired: true })
        )
        : null;
    const activeLiveProfile = liveProfile?.location_id === locationId ? liveProfile : null;
    const liveProfileFields = (activeLiveProfile || {}) as Partial<AccountProfile> & Record<string, string | null | undefined>;
    const cachedProfileFields = (cachedReportProfile || {}) as Partial<AccountProfile> & Record<string, string | null | undefined>;
    const creditStatusFields = (creditStatus || {}) as Record<string, string | number | null | undefined>;
    const reportAccountName = pickText(
        typeof creditStatusFields.location_name === "string" ? creditStatusFields.location_name : "",
        liveProfileFields.location_name,
        cachedProfileFields.location_name,
        accountSettings.displayName,
        locationId,
        "My Account"
    );
    const reportProfile = {
        accountName: reportAccountName,
        ownerName: pickText(joinProfileName(activeLiveProfile), joinProfileName(cachedReportProfile), accountSettings.displayName),
        email: pickText(liveProfileFields.email, liveProfileFields.email_address, cachedProfileFields.email, cachedProfileFields.email_address, accountSettings.email),
        phone: pickText(liveProfileFields.phone, liveProfileFields.phone_number, cachedProfileFields.phone, cachedProfileFields.phone_number),
        locationName: pickText(liveProfileFields.location_name, cachedProfileFields.location_name, reportAccountName),
        locationId,
        agencyName: pickText(liveProfileFields.company_name, liveProfileFields.agency_name, cachedProfileFields.company_name, cachedProfileFields.agency_name),
        companyName: pickText(liveProfileFields.company_name, liveProfileFields.agency_name, cachedProfileFields.company_name, cachedProfileFields.agency_name),
        companyId: pickText(liveProfileFields.company_id, cachedProfileFields.company_id),
        reportTitle: 'SUBACCOUNT CREDIT REPORT',
        currentBalance: creditStatus?.credit_balance ?? 0,
    };

    const [reqModalOpen, setReqModalOpen] = useState(false);
    const [reqAmount, setReqAmount] = useState(100);
    const [reqNote, setReqNote] = useState('');
    const [reqLoading, setReqLoading] = useState(false);
    const [reqSuccess, setReqSuccess] = useState(false);

    const clearCheckoutTimers = useCallback(() => {
        if (popupPollRef.current) {
            clearInterval(popupPollRef.current);
            popupPollRef.current = null;
        }
        if (countdownRef.current) {
            clearInterval(countdownRef.current);
            countdownRef.current = null;
        }
    }, []);

    const resetCheckoutState = useCallback((closePopup = false) => {
        clearCheckoutTimers();
        setSubmitted(false);
        sessionSafeStorage.removeItem(CHECKOUT_SESSION_STORAGE_KEY);
        if (closePopup && popupRef.current && !popupRef.current.closed) {
            try {
                popupRef.current.close();
            } catch {
                // Cross-origin popups can reject close attempts
            }
        }
        popupRef.current = null;
    }, [clearCheckoutTimers]);

    const load = useCallback(async () => {
        setTxLoading(true);
        try {
            const [txs, pkgs] = await Promise.all([
                fetchCreditTransactions('default', 500, locationId || undefined, txMonth === 'All' ? undefined : txMonth),
                fetchCreditPackages(),
            ]);
            if (!mountedRef.current) return;
            setTransactions(txs);
            setPackages(pkgs);

            if (pkgs.length > 0) {
                setTopUpAmount(pkgs[1]?.credits || pkgs[0].credits);
            }
        } catch (error) {
            devLog.error("Failed to load credit billing data", error);
        } finally {
            if (mountedRef.current) {
                setTxLoading(false);
            }
        }
    }, [locationId, txMonth]);

    const refreshCreditsView = useCallback(() => {
        void refreshCreditStatus();
        void load();
    }, [load, refreshCreditStatus]);

    useEffect(() => {
        const fetchAvailableMonths = async () => {
            const txs = await fetchCreditTransactions('default', 1000, locationId || undefined);
            const months = new Set<string>();
            const counts = new Map<string, number>();
            months.add(new Date().toISOString().slice(0, 7));

            txs.forEach(tx => {
                const rawDate = tx.created_at || (tx as CreditTransactionWithFallbacks).timestamp;
                let monthKey = '';
                if (rawDate && typeof rawDate === 'object' && 'seconds' in rawDate) {
                    const date = new Date(Number((rawDate as { seconds?: unknown }).seconds) * 1000);
                    if (!Number.isNaN(date.getTime())) {
                        monthKey = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
                    }
                } else if (rawDate) {
                    const text = String(rawDate);
                    const parsed = new Date(text);
                    monthKey = Number.isNaN(parsed.getTime())
                        ? text.slice(0, 7)
                        : `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}`;
                }

                if (monthKey) {
                    months.add(monthKey);
                    counts.set(monthKey, (counts.get(monthKey) || 0) + 1);
                }
            });

            const sorted = Array.from(months)
                .sort((a, b) => b.localeCompare(a))
                .map(m => {
                    const [y, mm] = m.split('-');
                    const d = new Date(parseInt(y), parseInt(mm) - 1);
                    return {
                        val: m,
                        label: d.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }),
                        count: counts.get(m) || 0,
                    };
                });
            setAvailableMonths([{ val: 'All', label: 'All Transactions', count: txs.length }, ...sorted]);
        };
        fetchAvailableMonths();
    }, [locationId]);

    useEffect(() => {
        if (!locationId || !creditStatus) return;

        const nextBalance = Number(creditStatus.credit_balance ?? 0);
        if (lastLiveBalanceRef.current === null) {
            lastLiveBalanceRef.current = nextBalance;
            return;
        }
        if (lastLiveBalanceRef.current === nextBalance) return;

        lastLiveBalanceRef.current = nextBalance;
        const timer = window.setTimeout(async () => {
            try {
                const txs = await fetchCreditTransactions('default', 500, locationId || undefined, txMonth === 'All' ? undefined : txMonth);
                if (mountedRef.current) {
                    setTransactions(txs);
                }
            } catch (error) {
                devLog.error("Failed to refresh credit transactions", error);
            }
        }, 250);

        return () => window.clearTimeout(timer);
    }, [creditStatus, locationId, txMonth]);

    useEffect(() => {
        mountedRef.current = true;
        load();
        window.addEventListener('sms-sent', load);
        window.addEventListener('bulk-message-sent', load);

        const handlePaymentMessage = (event: MessageEvent) => {
            const data = event.data || {};
            if (data?.type !== 'nola-payment-success') return;
            if (!CHECKOUT_ALLOWED_ORIGINS.has(event.origin) && event.origin !== window.location.origin) return;
            if (!popupRef.current || event.source !== popupRef.current) return;

            const pending = readPendingCheckoutSession();
            if (!pending) return;

            const incomingState =
                typeof data.checkout_state === 'string' ? data.checkout_state :
                    typeof data.state === 'string' ? data.state :
                        '';
            const incomingLocationId =
                typeof data.location_id === 'string' ? data.location_id :
                    typeof data.locationId === 'string' ? data.locationId :
                        '';

            if (incomingState && incomingState !== pending.state) return;
            if (incomingLocationId && incomingLocationId !== pending.locationId) return;

            setCheckoutError(null);
            resetCheckoutState(true);
            refreshCreditsView();
            window.dispatchEvent(new Event("nola-notifications-refresh"));
        };
        window.addEventListener('message', handlePaymentMessage);

        return () => {
            mountedRef.current = false;
            window.removeEventListener('sms-sent', load);
            window.removeEventListener('bulk-message-sent', load);
            window.removeEventListener('message', handlePaymentMessage);
            clearCheckoutTimers();
        };
    }, [clearCheckoutTimers, load, refreshCreditsView, resetCheckoutState]);

    const totalReportEventCount = availableMonths.find(month => month.val === 'All')?.count ?? transactions.length;
    const reportModalOption = availableMonths.find(month => month.val === reportModalMonth);
    const reportModalEventCount = reportModalOption?.count ?? (reportModalMonth === txMonth ? transactions.length : 0);
    const reportModalLabel = reportModalOption?.label ?? (reportModalMonth === 'All' ? 'All Transactions' : reportModalMonth);

    const openReportDownloadModal = () => {
        setReportModalMonth(txMonth);
        setReportDownloadError(null);
        setReportModalOpen(true);
    };

    const confirmReportDownload = async () => {
        if (reportDownloadLoading || reportModalEventCount <= 0) return;

        setReportDownloadLoading(true);
        setReportDownloadError(null);
        try {
            const reportTransactions = await fetchCreditTransactions(
                'default',
                5000,
                locationId || undefined,
                reportModalMonth === 'All' ? undefined : reportModalMonth
            );

            if (reportTransactions.length === 0) {
                setReportDownloadError('No reportable events were found for the selected period. Choose another month or generate account activity first.');
                return;
            }

            generateMonthlyReport(reportModalMonth, reportTransactions, 'subaccount', reportAccountName, reportProfile);
            setReportModalOpen(false);
        } catch {
            setReportDownloadError('Failed to prepare the report. Please try again.');
        } finally {
            setReportDownloadLoading(false);
        }
    };

    const displayBalance = creditStatus?.credit_balance ?? 0;
    const trialUsed = creditStatus?.free_usage_count ?? 0;
    const trialTotal = creditStatus?.free_credits_total ?? 0;
    const isTrialActive = trialTotal > 0 && trialUsed < trialTotal;
    const trialLeft = trialTotal - trialUsed;
    const usagePercent = Math.min(100, (displayBalance / 1000) * 100);
    const usageColor = displayBalance < 50 ? 'bg-red-500' : displayBalance < 200 ? 'bg-amber-400' : 'bg-emerald-500';

    const sentToday = creditStatus?.stats?.sent_today ?? 0;
    const creditsUsedToday = creditStatus?.stats?.credits_used_today ?? 0;
    const creditsUsedMonth = creditStatus?.stats?.credits_used_month ?? 0;

    const submitCreditRequest = async () => {
        if (reqAmount <= 0) return;
        setReqLoading(true);
        try {
            const res = await apiFetch(`${API_BASE_URL}/api/billing/subaccount_wallet.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'request_credits', location_id: locationId, amount: reqAmount, note: reqNote }),
            });
            const data = await res.json();
            if (data.success || res.ok) {
                setReqSuccess(true);
                setTimeout(() => { setReqSuccess(false); setReqModalOpen(false); setReqNote(''); }, 2500);
            }
        } catch { /* silently handled */ } finally { setReqLoading(false); }
    };

    const handleTopUp = async (e: React.FormEvent) => {
        e.preventDefault();
        setCheckoutError(null);

        const selectedPackage = packages.find(p => p.credits === topUpAmount);
        if (!selectedPackage) return;

        const checkoutUrl = buildSafeCheckoutUrl(selectedPackage.link);
        if (!checkoutUrl) {
            setCheckoutError('This checkout link is not trusted. Please contact support before paying.');
            return;
        }

        const pick = (...vals: (string | undefined | null)[]) =>
            vals.find(v => typeof v === 'string' && v.trim() !== '')?.trim() || '';

        const session = getSession();
        if (session?.role && session.role !== 'user') {
            setCheckoutError('This payment window is only available for user accounts.');
            return;
        }

        const resolvedLocationId = pick(
            locationId,
            liveProfile?.location_id,
            session?.locationId
        );

        if (!resolvedLocationId || !VALID_LOCATION_ID.test(resolvedLocationId)) {
            setCheckoutError('Open this app from a valid subaccount before buying credits.');
            return;
        }

        if (session?.locationId && session.locationId !== resolvedLocationId) {
            setCheckoutError('Your checkout session does not match the active subaccount. Refresh and try again.');
            return;
        }

        const width = Math.max(320, Math.min(620, window.screen.availWidth - 32));
        const height = Math.max(520, Math.min(860, window.screen.availHeight - 32));
        const left = Math.max(16, (window.screen.availWidth / 2) - (width / 2));
        const top = Math.max(16, (window.screen.availHeight / 2) - (height / 2));

        const popup = window.open(
            '',
            'NolaSecureCheckout',
            `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes,popup=yes`
        );

        if (!popup) {
            setCheckoutError('Checkout window was blocked. Please allow popups for this site and try again.');
            return;
        }

        popupRef.current = popup;
        setSubmitted(true);

        try {
            popup.document.write(`
                <!doctype html>
                <title>Preparing checkout</title>
                <style>
                    body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Inter,Arial,sans-serif;background:#f7f8fc;color:#111}
                    .box{display:flex;align-items:center;gap:12px;padding:18px 20px;border:1px solid #e5e7eb;border-radius:16px;background:white;box-shadow:0 18px 45px rgba(15,23,42,.12)}
                    .spin{width:18px;height:18px;border:3px solid #dbeafe;border-top-color:#2b83fa;border-radius:999px;animation:s 1s linear infinite}
                    @keyframes s{to{transform:rotate(360deg)}}
                </style>
                <div class="box"><div class="spin"></div><strong>Preparing secure checkout...</strong></div>
            `);
        } catch {
            // Popup write fallback
        }

        let accountProfile: Awaited<ReturnType<typeof fetchAccountProfile>> = null;
        try {
            accountProfile = await fetchAccountProfile(resolvedLocationId);
        } catch {
            accountProfile = null;
        }

        if (!accountProfile?.location_id || accountProfile.location_id !== resolvedLocationId) {
            resetCheckoutState(true);
            setCheckoutError('We could not verify this subaccount for checkout. Please refresh and try again.');
            return;
        }

        const readCachedProfile = (): Record<string, string | null | undefined> => {
            try {
                const authUser = JSON.parse(safeStorage.getItem('nola_auth_user') || 'null') || {};
                const nolaUser = JSON.parse(safeStorage.getItem('nola_user') || 'null') || {};
                return { ...authUser, ...nolaUser };
            } catch {
                return {};
            }
        };
        const profileFields = accountProfile as unknown as Record<string, string | null | undefined>;
        const liveProfileFields = (liveProfile || {}) as Record<string, string | null | undefined>;
        const sessionUserFields = (session?.user || {}) as Record<string, string | null | undefined>;
        const cachedFields = readCachedProfile();
        const joinedLiveName = [liveProfile?.firstName, liveProfile?.lastName].filter(Boolean).join(' ');
        const fullName = pick(
            profileFields.full_name,
            profileFields.name,
            liveProfileFields.full_name,
            liveProfileFields.name,
            joinedLiveName,
            sessionUserFields.full_name,
            sessionUserFields.name,
            cachedFields.full_name,
            cachedFields.name
        );
        const nameParts = fullName.split(/\s+/).filter(Boolean);
        const firstName = pick(
            profileFields.firstName,
            liveProfileFields.firstName,
            sessionUserFields.firstName,
            cachedFields.firstName,
            nameParts[0]
        );
        const lastName = pick(
            profileFields.lastName,
            liveProfileFields.lastName,
            sessionUserFields.lastName,
            cachedFields.lastName,
            nameParts.length > 1 ? nameParts.slice(1).join(' ') : ''
        );
        const email = pick(
            profileFields.email,
            profileFields.email_address,
            liveProfileFields.email,
            sessionUserFields.email,
            cachedFields.email,
            cachedFields.email_address
        );
        const phone = pick(
            profileFields.phone,
            profileFields.phone_number,
            liveProfileFields.phone,
            sessionUserFields.phone,
            cachedFields.phone,
            cachedFields.phone_number
        );

        const checkoutState = createCheckoutState();
        const pending: PendingCheckoutSession = {
            state: checkoutState,
            locationId: resolvedLocationId,
            packageCredits: selectedPackage.credits,
            createdAt: Date.now(),
        };
        sessionSafeStorage.setItem(CHECKOUT_SESSION_STORAGE_KEY, JSON.stringify(pending));

        checkoutUrl.searchParams.set('location_id', resolvedLocationId);
        [
            ['name', fullName],
            ['full_name', fullName],
            ['first_name', firstName],
            ['last_name', lastName],
            ['email', email],
            ['phone', phone],
        ].forEach(([key, value]) => {
            if (value) checkoutUrl.searchParams.set(key, value);
        });
        checkoutUrl.searchParams.set('checkout_state', checkoutState);
        popup.location.href = checkoutUrl.toString();

        clearCheckoutTimers();
        popupPollRef.current = setInterval(() => {
            try {
                if (popup.closed) {
                    resetCheckoutState(false);
                    load();
                }
            } catch {
                // Ignore cross-origin DOM exceptions.
            }
        }, 750);
    };

    return (
        <div className="space-y-5">
            <SectionHeader title="Credits & Billing" subtitle="Monitor your SMS credit balance and request credits from your agency." />
            {reportModalOpen && (
                <div className="fixed inset-0 z-[1000] flex items-center justify-center overflow-y-auto p-4 bg-black/50 dark:bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/10 rounded-2xl w-full max-w-lg max-h-[92vh] shadow-2xl animate-in zoom-in-95 duration-200 overflow-hidden flex flex-col">
                        <div className="px-6 py-5 border-b border-[#e5e5e5] dark:border-white/10 flex items-start justify-between gap-4">
                            <div className="flex items-center gap-2.5 min-w-0">
                                <div className="w-9 h-9 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                                    <FiDownload className="w-4 h-4" />
                                </div>
                                <div className="min-w-0">
                                    <h3 className="text-[17px] font-bold text-[#111111] dark:text-[#ececf1] leading-tight">Download Report</h3>
                                    <p className="text-[12px] font-medium text-[#9aa0a6] mt-0.5 truncate">{reportAccountName}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReportModalOpen(false)}
                                className="p-2 text-[#6e6e73] hover:bg-[#f7f7f7] dark:hover:bg-white/5 rounded-full transition-colors"
                                aria-label="Close report download modal"
                            >
                                <FiX className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto p-4 space-y-5 sm:p-6 custom-scrollbar">
                            {totalReportEventCount === 0 ? (
                                <div className="py-8 flex flex-col items-center justify-center gap-3 bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl border border-[#e5e5e5] dark:border-white/5 text-center">
                                    <div className="w-11 h-11 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] flex items-center justify-center">
                                        <FiDownload className="w-5 h-5" />
                                    </div>
                                    <div>
                                        <p className="text-[14px] font-bold text-[#111111] dark:text-[#ececf1]">No reportable events yet</p>
                                        <p className="text-[12px] text-[#9aa0a6] mt-1">Send SMS, top up credits, or choose another period before downloading a PDF report.</p>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                                            <p className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Total Events</p>
                                            <p className="text-[22px] font-black text-[#111111] dark:text-[#ececf1] mt-1">{totalReportEventCount.toLocaleString()}</p>
                                        </div>
                                        <div className="rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 px-4 py-3">
                                            <p className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Selected Period</p>
                                            <p className="text-[13px] font-black text-[#111111] dark:text-[#ececf1] mt-1 truncate">{reportModalLabel}</p>
                                            <p className="text-[11px] font-semibold text-[#9aa0a6] mt-1">{reportModalEventCount.toLocaleString()} events</p>
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <label className="text-[11px] uppercase tracking-wider font-bold text-[#9aa0a6]">Report Period</label>
                                        <div className="relative">
                                            <select
                                                value={reportModalMonth}
                                                onChange={(event) => {
                                                    setReportModalMonth(event.target.value);
                                                    setReportDownloadError(null);
                                                }}
                                                className="w-full appearance-none pl-3.5 pr-10 py-3 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#d8dce3] dark:border-white/10 text-[13px] font-bold text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 transition-all cursor-pointer"
                                            >
                                                {availableMonths.map(month => (
                                                    <option key={month.val} value={month.val}>
                                                        {month.label} ({month.count} events)
                                                    </option>
                                                ))}
                                            </select>
                                            <FiChevronDown className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[#9aa0a6] pointer-events-none" />
                                        </div>
                                    </div>

                                    {reportDownloadError && (
                                        <div className="flex items-start gap-2 rounded-xl border border-red-200/70 bg-red-50 px-3 py-2 text-[12px] font-semibold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                                            <FiAlertCircle className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                                            {reportDownloadError}
                                        </div>
                                    )}

                                    <button
                                        type="button"
                                        onClick={confirmReportDownload}
                                        disabled={reportDownloadLoading || reportModalEventCount <= 0}
                                        className="w-full flex items-center justify-center gap-2 px-5 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] text-white rounded-xl text-[13px] font-bold transition-all shadow-md shadow-blue-500/20 active:scale-[0.98] disabled:opacity-45 disabled:cursor-not-allowed disabled:hover:shadow-none"
                                    >
                                        {reportDownloadLoading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiDownload className="w-4 h-4" />}
                                        {reportDownloadLoading ? 'Preparing PDF...' : reportModalEventCount > 0 ? 'Download PDF' : 'No events to download'}
                                    </button>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {reqModalOpen && (
                <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-[1000]" onClick={() => setReqModalOpen(false)}>
                    <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl shadow-2xl p-6 w-full max-w-[400px] mx-4" onClick={e => e.stopPropagation()}>
                        <div className="flex items-center gap-3 mb-5">
                            <div className="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-500">
                                <FiGift className="w-5 h-5" />
                            </div>
                            <div>
                                <div className="text-[15px] font-bold text-[#111111] dark:text-white">Request Credits from Agency</div>
                                <div className="text-[12px] text-[#9aa0a6]">Your agency will review and approve your request</div>
                            </div>
                        </div>
                        {reqSuccess ? (
                            <div className="flex flex-col items-center py-6 gap-2">
                                <div className="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-500"><FiCheck className="w-6 h-6" /></div>
                                <div className="text-[14px] font-bold text-[#111111] dark:text-white">Request Sent!</div>
                                <div className="text-[12px] text-[#9aa0a6] text-center">Your agency will review and fulfill your request shortly.</div>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div>
                                    <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Credits Requested</label>
                                    <div className="grid grid-cols-4 gap-2 mb-2">
                                        {[50, 100, 250, 500].map(n => (
                                            <button key={n} type="button" onClick={() => setReqAmount(n)}
                                                className={`py-2 rounded-xl text-[12px] font-bold border-2 transition-all ${reqAmount === n ? 'border-purple-500 bg-purple-500/5 text-purple-600' : 'border-[#e0e0e0] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#9aa0a9] hover:border-purple-400'}`}>
                                                {n}
                                            </button>
                                        ))}
                                    </div>
                                    <input type="number" min={1} value={reqAmount} onChange={e => setReqAmount(Math.max(1, parseInt(e.target.value) || 1))}
                                        className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-purple-500/30"
                                        placeholder="Or type a custom amount…" />
                                </div>
                                <div>
                                    <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Note (optional)</label>
                                    <input type="text" value={reqNote} onChange={e => setReqNote(e.target.value)}
                                        placeholder="E.g. Need credits for weekend campaign…"
                                        className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-purple-500/30" />
                                </div>
                                <div className="flex gap-2.5 pt-1">
                                    <button onClick={() => setReqModalOpen(false)} disabled={reqLoading}
                                        className="flex-1 px-4 py-2.5 rounded-xl text-[13px] font-semibold bg-[#f7f7f7] dark:bg-[#0d0e10] text-[#6b7280] border border-[#e0e0e0] dark:border-[#ffffff0a] hover:bg-black/5 transition-colors">
                                        Cancel
                                    </button>
                                    <button onClick={submitCreditRequest} disabled={reqLoading || reqAmount <= 0}
                                        className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-[13px] font-semibold bg-purple-600 text-white hover:bg-purple-700 transition-colors shadow-md shadow-purple-500/20 disabled:opacity-50">
                                        {reqLoading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiGift className="w-4 h-4" />}
                                        {reqLoading ? 'Sending…' : 'Send Request'}
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            <div className="rounded-2xl p-5 text-white shadow-lg transition-colors duration-500 bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] shadow-blue-500/25">
                <div className="flex items-start justify-between mb-4">
                    <div className="flex-1 min-w-0">
                        <p className="text-[12px] font-semibold text-white/70 uppercase tracking-wider mb-2">Available Credits</p>
                        {balanceLoading ? (
                            <div className="h-10 w-28 bg-white/20 rounded-lg animate-pulse" />
                        ) : (
                            <div className="flex items-center gap-3">
                                <p className="text-[42px] font-black leading-none">{displayBalance.toLocaleString()}</p>
                                {isTrialActive && (
                                    <span className="text-[11px] font-bold bg-[#ffffff30] text-white px-3 py-1 rounded-full shadow-sm whitespace-nowrap">
                                        {trialLeft} Free Trial
                                    </span>
                                )}
                            </div>
                        )}
                        <p className="text-[12px] text-white/60 mt-1">1 credit ≈ 1 SMS (160 chars)</p>
                    </div>
                    <div className="flex flex-col items-center gap-2 flex-shrink-0">
                        <div className="w-12 h-12 rounded-xl bg-white/10 flex items-center justify-center">
                            <FiCreditCard className="w-6 h-6 text-white" />
                        </div>
                        <button
                            onClick={refreshCreditsView}
                            className="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 flex items-center justify-center transition-all active:scale-90"
                            title="Refresh"
                        >
                            <FiRefreshCw className={`w-3.5 h-3.5 text-white ${balanceLoading ? 'animate-spin' : ''}`} />
                        </button>
                    </div>
                </div>

                {isTrialActive ? (
                    <>
                        <div className="mb-1">
                            <div className="h-1.5 bg-white/20 rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-white/70 rounded-full transition-all duration-700"
                                    style={{ width: `${Math.round((trialUsed / trialTotal) * 100)}%` }}
                                />
                            </div>
                        </div>
                        <div className="flex items-center justify-between text-[11px] text-white/60">
                            <span>{trialUsed} used</span>
                            <span>{trialTotal} total free</span>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="mb-1">
                            <div className="h-1.5 bg-white/20 rounded-full overflow-hidden">
                                <div className={`h-full ${usageColor} rounded-full transition-all duration-700`} style={{ width: `${usagePercent}%` }} />
                            </div>
                        </div>
                        <div className="flex items-center justify-between text-[11px] text-white/60">
                            <span>0</span>
                            <span>1,000+</span>
                        </div>
                    </>
                )}
            </div>

            <Card>
                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-500 flex-shrink-0">
                            <FiGift className="w-4.5 h-4.5" />
                        </div>
                        <div>
                            <p className="text-[13.5px] font-bold text-[#111111] dark:text-[#ececf1]">Request Credits from Agency</p>
                            <p className="text-[11.5px] text-[#9aa0a6]">Ask your agency to top up your credit balance.</p>
                        </div>
                    </div>
                    <button onClick={() => setReqModalOpen(true)}
                        className="flex-shrink-0 flex items-center gap-2 px-4 py-2 rounded-xl text-[12.5px] font-bold text-purple-600 bg-purple-500/10 hover:bg-purple-500/20 hover:shadow-[0_4px_12px_rgba(168,85,247,0.2)] transition-all">
                        <FiGift className="w-3.5 h-3.5" /> Request Credits
                    </button>
                </div>
            </Card>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {[
                    { label: 'Sent Today', value: txLoading ? '—' : String(sentToday), icon: <FiSend className="w-4 h-4" />, color: 'text-[#2b83fa]', bg: 'bg-[#2b83fa]/10' },
                    { label: 'Credits Used Today', value: txLoading ? '—' : String(creditsUsedToday), icon: <FiZap className="w-4 h-4" />, color: 'text-amber-500', bg: 'bg-amber-500/10' },
                    { label: 'This Month', value: txLoading ? '—' : creditsUsedMonth.toLocaleString(), icon: <FiRefreshCw className="w-4 h-4" />, color: 'text-purple-500', bg: 'bg-purple-500/10' },
                ].map(stat => (
                    <Card key={stat.label} className="flex items-center gap-3 !py-4">
                        <div className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${stat.bg} ${stat.color}`}>{stat.icon}</div>
                        <div>
                            <p className={`text-[20px] font-black text-[#111111] dark:text-[#ececf1] ${txLoading ? 'animate-pulse' : ''}`}>{stat.value}</p>
                            <p className="text-[11px] text-[#9aa0a6]">{stat.label}</p>
                        </div>
                    </Card>
                ))}
            </div>

            <Card>
                <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider mb-4">Top Up Credits</h3>
                <form onSubmit={handleTopUp} className="space-y-4">
                    {balanceLoading || packages.length === 0 || topUpAmount === null ? (
                        <div className="grid grid-cols-6 gap-3">
                            {Array.from({ length: 5 }).map((_, idx) => {
                                const colSpan = idx < 3 ? 'col-span-6 sm:col-span-2' : 'col-span-6 sm:col-span-3';
                                return (
                                    <div
                                        key={idx}
                                        className={`flex flex-col items-center py-4 px-3 rounded-xl border border-[#e5e5e5] dark:border-[#2a2b32] animate-pulse space-y-2.5 ${colSpan} bg-gray-50/50 dark:bg-[#1a1b1e]/30`}
                                    >
                                        <div className="h-4 bg-gray-200 dark:bg-[#2a2b32] rounded w-16" />
                                        <div className="h-3 bg-gray-100 dark:bg-[#1e1f22] rounded w-10" />
                                        <div className="h-4 bg-gray-200 dark:bg-[#2a2b32] rounded w-12 mt-1" />
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="grid grid-cols-6 gap-3">
                            {packages.map((pkg, idx) => {
                                const colSpan = idx < 3 ? 'col-span-6 sm:col-span-2' : 'col-span-6 sm:col-span-3';
                                const isSelected = topUpAmount === pkg.credits;
                                const bonusCredits = pkg.credits - pkg.price;
                                const bonusPercent = pkg.price > 0 ? Math.round((bonusCredits / pkg.price) * 100) : 0;

                                return (
                                    <button
                                        key={pkg.credits}
                                        type="button"
                                        onClick={() => setTopUpAmount(pkg.credits)}
                                        className={`relative flex flex-col items-center py-4 px-3 rounded-xl border-2 transition-all duration-300 ease-out transform hover:scale-[1.02] active:scale-[0.98] ${isSelected
                                            ? 'border-[#2b83fa] bg-gradient-to-br from-[#2b83fa]/10 to-[#2b83fa]/5 dark:from-[#2b83fa]/20 dark:to-[#2b83fa]/5 shadow-[0_0_15px_rgba(43,131,250,0.15)] dark:shadow-[0_0_20px_rgba(43,131,250,0.25)]'
                                            : 'border-[#e0e0e0] dark:border-[#2a2b32] bg-white dark:bg-[#1a1b1e]/50 hover:border-[#2b83fa]/50 hover:bg-gray-50/50 dark:hover:bg-[#2b832]/30'
                                            } ${colSpan}`}
                                    >
                                        {bonusPercent > 0 && (
                                            <span className="absolute -top-2.5 -right-1.5 bg-gradient-to-r from-amber-500 to-orange-500 text-white text-[9.5px] font-black px-2 py-0.5 rounded-full shadow-md uppercase tracking-wider">
                                                +{bonusPercent}% Bonus
                                            </span>
                                        )}
                                        <span className={`text-[17px] font-black tracking-tight ${isSelected ? 'text-[#2b83fa]' : 'text-[#111111] dark:text-[#ececf1]'}`}>
                                            {pkg.credits?.toLocaleString() || pkg.credits}
                                        </span>
                                        <span className="text-[10px] text-[#9aa0a6] font-semibold mt-0.5">credits</span>
                                        <span className={`text-[13px] font-bold mt-1.5 px-3 py-0.5 rounded-lg ${isSelected
                                            ? 'bg-[#2b83fa] text-white'
                                            : 'bg-gray-100 dark:bg-[#2a2b32] text-[#6e6e73] dark:text-[#94959b]'
                                            }`}>
                                            ₱{pkg.price?.toLocaleString() || pkg.price}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    {checkoutError && (
                        <div className="rounded-xl border border-red-200/70 bg-red-50 px-3 py-2.5 text-[12.5px] font-semibold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                            {checkoutError}
                        </div>
                    )}
                    {balanceLoading || packages.length === 0 || topUpAmount === null ? (
                        <div className="w-full h-12 bg-gray-200 dark:bg-[#2a2b32] rounded-xl animate-pulse flex items-center justify-center">
                            <div className="h-4 bg-gray-300 dark:bg-[#3a3b3f] rounded w-40" />
                        </div>
                    ) : submitted ? (
                        <div className="flex flex-col items-center justify-center gap-2 w-full">
                            <div className="w-full flex items-center justify-center gap-2 py-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl text-emerald-600 dark:text-emerald-400 font-semibold text-[13px]">
                                <FiCheck className="w-4 h-4" /> Checkout window opened
                            </div>
                            <button
                                type="button"
                                onClick={() => {
                                    resetCheckoutState(false);
                                    load();
                                }}
                                className="text-[12px] text-[#9aa0a6] hover:text-[#111111] dark:hover:text-[#ececf1] underline decoration-dashed hover:decoration-solid transition-all"
                            >
                                Window didn't open or you closed it? Click here to refresh.
                            </button>
                        </div>
                    ) : (
                        <button type="submit" className="w-full flex items-center justify-center gap-2 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-semibold text-[14px] transition-all shadow-md shadow-blue-500/20">
                            <FiZap className="w-4 h-4" /> Buy {topUpAmount.toLocaleString()} Credits
                        </button>
                    )}
                </form>
            </Card>

            <Card>
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-[13px] font-bold text-[#37352f] dark:text-[#ececf1] uppercase tracking-wider">Recent Transactions</h3>
                    <div className="flex items-center gap-3">
                        <div className="relative">
                            <select
                                value={txMonth}
                                onChange={(e) => setTxMonth(e.target.value)}
                                className="pl-3 pr-8 py-1.5 rounded-lg bg-[#f0f2f8] dark:bg-[#1c1e21] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[12.5px] font-semibold text-[#111111] dark:text-white appearance-none focus:outline-none"
                            >
                                {availableMonths.map((m) => (
                                    <option key={m.val} value={m.val}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                            <FiChevronDown className="absolute right-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-[#9aa0a6] pointer-events-none" />
                        </div>
                        <button
                            onClick={openReportDownloadModal}
                            disabled={txLoading || totalReportEventCount === 0}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-[#ffffff] border border-transparent hover:bg-[#f3f4f6] dark:hover:bg-[#1f2023] disabled:opacity-50 transition-all font-inter"
                        >
                            <FiDownload className="w-3.5 h-3.5" /> Download Report
                        </button>
                    </div>
                </div>

                {txLoading ? (
                    <div className="space-y-3">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <div key={i} className="flex items-center gap-3 py-1 animate-pulse">
                                <div className="w-7 h-7 rounded-full bg-gray-200 dark:bg-[#2a2b32] flex-shrink-0" />
                                <div className="flex-1 space-y-1.5">
                                    <div className="h-3 bg-gray-200 dark:bg-[#2a2b32] rounded w-3/4" />
                                    <div className="h-2.5 bg-gray-100 dark:bg-[#1e1f22] rounded w-1/3" />
                                </div>
                                <div className="h-3 bg-gray-200 dark:bg-[#2a2b32] rounded w-16 flex-shrink-0" />
                            </div>
                        ))}
                    </div>
                ) : transactions.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 gap-3 text-center">
                        <div className="w-12 h-12 rounded-xl bg-[#2b83fa]/10 flex items-center justify-center">
                            <FiCreditCard className="w-6 h-6 text-[#2b83fa]/60" />
                        </div>
                        <p className="text-[14px] font-semibold text-[#37352f] dark:text-[#ececf1]">No credit activity yet</p>
                        <p className="text-[12px] text-[#9aa0a6] max-w-xs">Send SMS, top up your balance, or receive an adjustment to start building this ledger.</p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-0 min-h-[250px]">
                            {transactions.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage).map((tx) => {
                                const isAdjustment = tx.type === 'manual_adjustment' || tx.type === 'admin_adjustment' || tx.type === 'agency_adjustment';
                                const isCredit = tx.type === 'top_up' || tx.type === 'refund' || tx.type === 'credit_purchase' || (isAdjustment && tx.amount >= 0);
                                const sign = isCredit ? '+' : '−';
                                const absAmount = Math.abs(tx.amount);

                                let displayDescription = tx.description;
                                if (isAdjustment) {
                                    displayDescription = `Manual credit adjustment (Applied ${tx.amount >= 0 ? '+' : '-'}${absAmount} credits)`;
                                }

                                return (
                                    <div key={tx.transaction_id} className="flex items-center gap-3 py-2.5 border-b border-[#f0f0f0] dark:border-[#2a2b32] last:border-0 hover:bg-gray-50 dark:hover:bg-white/5 px-2 rounded-xl transition-colors">
                                        <div className={`w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-[11px] font-bold ${isCredit ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400' : 'bg-red-50 dark:bg-red-900/20 text-red-500'}`}>
                                            {sign}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-[13px] font-medium text-[#111111] dark:text-[#ececf1] truncate">{displayDescription}</p>
                                            <p className="text-[11px] text-[#9aa0a6]">{formatTxDate(tx.created_at)}</p>
                                            {(tx.reference_id || tx.transaction_reference_id) && (
                                                <p className="text-[10px] font-mono text-[#9aa0a6] truncate">Ref: {tx.reference_id || tx.transaction_reference_id}</p>
                                            )}
                                        </div>
                                        <div className="flex flex-col items-end flex-shrink-0">
                                            <span className={`text-[13px] font-bold ${isCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'}`}>
                                                {absAmount === 0 && !isCredit ? '−1 free trial' : `${sign}${absAmount?.toLocaleString() || absAmount} credits`}
                                            </span>
                                            <span className="text-[10px] text-[#9aa0a6]">balance: {tx.balance_after?.toLocaleString() || tx.balance_after || 0}</span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {transactions.length > itemsPerPage && (() => {
                            const totalPages = Math.ceil(transactions.length / itemsPerPage);

                            const getPageNumbers = () => {
                                const pages = [];
                                const maxVisiblePages = 5;

                                if (totalPages <= maxVisiblePages) {
                                    for (let i = 1; i <= totalPages; i++) pages.push(i);
                                } else {
                                    if (currentPage <= 3) {
                                        for (let i = 1; i <= 4; i++) pages.push(i);
                                        pages.push('...');
                                        pages.push(totalPages);
                                    } else if (currentPage >= totalPages - 2) {
                                        pages.push(1);
                                        pages.push('...');
                                        for (let i = totalPages - 3; i <= totalPages; i++) pages.push(i);
                                    } else {
                                        pages.push(1);
                                        pages.push('...');
                                        pages.push(currentPage - 1);
                                        pages.push(currentPage);
                                        pages.push(currentPage + 1);
                                        pages.push('...');
                                        pages.push(totalPages);
                                    }
                                }
                                return pages;
                            };

                            return (
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between mt-6 pt-4 border-t border-[#f0f0f0] dark:border-[#2a2b32] gap-4">
                                    <span className="text-[12px] font-medium text-[#9aa0a6] text-center sm:text-left">
                                        Showing {(currentPage - 1) * itemsPerPage + 1} to {Math.min(currentPage * itemsPerPage, transactions.length)} of {transactions.length}
                                    </span>
                                    <div className="flex items-center justify-center gap-1.5">
                                        <button
                                            onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                                            disabled={currentPage === 1}
                                            className="p-1.5 rounded-lg border border-[#e5e5e5] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#94959b] hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                        >
                                            <FiChevronLeft className="w-4 h-4" />
                                        </button>

                                        {getPageNumbers().map((page, idx) => (
                                            page === '...' ? (
                                                <span key={`ellipsis-${idx}`} className="px-1 text-[12px] font-medium text-[#9aa0a6]">...</span>
                                            ) : (
                                                <button
                                                    key={`page-${page}`}
                                                    onClick={() => setCurrentPage(page as number)}
                                                    className={`min-w-[28px] h-7 rounded-lg text-[12px] font-bold transition-all ${currentPage === page
                                                        ? 'bg-[#2b83fa] text-[#ffffff] border border-[#2b83fa]'
                                                        : 'border border-[#e5e5e5] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#94959b] hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32]'
                                                        }`}
                                                >
                                                    {page}
                                                </button>
                                            )
                                        ))}

                                        <button
                                            onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                                            disabled={currentPage === totalPages}
                                            className="p-1.5 rounded-lg border border-[#e5e5e5] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#94959b] hover:bg-[#f7f7f7] dark:hover:bg-[#2a2b32] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                        >
                                            <FiChevronRight className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            );
                        })()}
                    </>
                )}
            </Card>
        </div>
    );
};
