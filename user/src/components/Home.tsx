import { devLog } from '../utils/devLog';
import { useState, useEffect } from "react";
import { FiPlus, FiSearch, FiUsers, FiSettings, FiCreditCard, FiMessageSquare, FiArrowRight, FiClock, FiUser, FiX, FiActivity, FiDownload } from "react-icons/fi";
import type { Contact } from "../types/Contact";
import { gsap } from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

gsap.registerPlugin(ScrollTrigger);
import type { BulkMessageHistoryItem, Conversation } from "../types/Sms";
import { fetchConversations } from "../api/sms";
import { fetchContacts } from "../api/contacts";
import { fetchCreditTransactions, type CreditTransaction } from "../api/credits";
import { fetchAccountProfile, getCachedAccountProfile, type AccountProfile } from "../api/account";
import { generateMonthlyReport } from "../utils/pdfGenerator";
import SplitText from "./SplitText";
import AnimatedContent from "./AnimatedContent";
import FadeContent from "./FadeContent";
import { extractBatchIdFromGroupConversationId, extractPhoneFromDirectConversationId } from "../utils/conversationId";
import { useLocationId } from "../context/LocationContext";
import { useUserProfileContext } from "../context/UserProfileContext";
import { useRealtimeCreditStatus } from "../hooks/useRealtimeCreditStatus";
import { safeStorage } from "../utils/safeStorage";
import { buildContactNameLookup, isPhoneLike, resolveContactNameByPhone, toProperCase } from "../utils/contactDisplay";
import type { ViewTab } from "./Sidebar";
import { SenderIdReminderBanner } from "./ui/SenderIdReminderBanner";

interface HomeProps {
    onTabChange: (tab: ViewTab) => void;
    onCreateContact: () => void;
    onSelectContact: (contact: Contact) => void;
    onSelectBulkMessage: (message: BulkMessageHistoryItem) => void;
    topControls?: React.ReactNode;
}

type HomeCreditTransaction = Omit<CreditTransaction, "type"> & {
    id?: string;
    type: CreditTransaction["type"] | "credit_purchase" | string;
};

type TrendPoint = {
    key: string;
    label: string;
    value: number;
};

const toDate = (value?: string | null): Date | null => {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
};

const dayKey = (date: Date): string => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
};

const createDaySeries = (anchorDate: Date, days = 7): TrendPoint[] => {
    return Array.from({ length: days }, (_, index) => {
        const date = new Date(anchorDate);
        date.setDate(anchorDate.getDate() - (days - 1 - index));
        return {
            label: date.toLocaleDateString([], { weekday: "short" }),
            key: dayKey(date),
            value: 0,
        };
    });
};

const getSeriesTotal = (series: TrendPoint[]) =>
    series.reduce((sum, point) => sum + point.value, 0);

const getProfileColor = (name: string): string => {
    if (!name) return 'hsl(217, 91%, 60%)';
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    const h = Math.abs(hash) % 360;
    return `hsl(${h}, 70%, 50%)`;
};

const getGreeting = (): string => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
};

const isUsableLocationName = (value?: string | null): value is string =>
    Boolean(value?.trim() && value.trim() !== "Unknown");

const getProfileLocationId = (profile?: { location_id?: string | null; active_location_id?: string | null } | null): string =>
    profile?.location_id || profile?.active_location_id || "";

const dashboardCardSkeletonClass = "skeleton-gleam bg-white/60 dark:bg-white/15";

const DashboardCardSkeleton = ({ valueWidth = "w-20" }: { valueWidth?: string }) => (
    <div className="relative z-10 flex h-full min-h-[128px] flex-col justify-between">
        <div>
            <div className={`mb-4 h-10 w-10 rounded-xl ${dashboardCardSkeletonClass}`} />
            <div className={`h-3 w-32 rounded-full ${dashboardCardSkeletonClass}`} />
        </div>
        <div className="mt-8 flex items-end gap-3">
            <div className={`h-10 ${valueWidth} rounded-lg ${dashboardCardSkeletonClass}`} />
            <div className={`mb-1 h-5 w-20 rounded-full ${dashboardCardSkeletonClass}`} />
        </div>
    </div>
);

const HomeEmptyPanel: React.FC<{
    icon: React.ReactNode;
    title: string;
    description: string;
    actionLabel?: string;
    onAction?: () => void;
}> = ({ icon, title, description, actionLabel, onAction }) => (
    <div className="rounded-[20px] border border-dashed border-[#d8e1ec] bg-[#f8fafc] px-5 py-8 text-center dark:border-white/10 dark:bg-[#0d0e10]">
        <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-[#eef6ff] text-[#2b83fa] dark:bg-white/5">
            {icon}
        </div>
        <h4 className="text-[14px] font-black text-[#111111] dark:text-white">{title}</h4>
        <p className="mx-auto mt-1 max-w-xs text-[12.5px] font-medium leading-relaxed text-[#64748b] dark:text-[#9aa0a6]">{description}</p>
        {actionLabel && onAction && (
            <button
                type="button"
                onClick={onAction}
                className="mt-4 inline-flex items-center justify-center rounded-xl bg-[#2b83fa] px-4 py-2 text-[12px] font-bold text-white transition-colors hover:bg-[#1d6bd4]"
            >
                {actionLabel}
            </button>
        )}
    </div>
);

const profileMatchesLocation = (
    profile: { location_id?: string | null; active_location_id?: string | null } | null | undefined,
    locationId: string
): boolean => {
    if (!locationId) return true;
    const profileLocationId = getProfileLocationId(profile);
    return Boolean(profileLocationId && profileLocationId === locationId);
};

const getStoredLocationName = (locationId: string): string => {
    for (const key of ["nola_auth_user", "nola_user"]) {
        try {
            const stored = JSON.parse(safeStorage.getItem(key) || "{}");
            if (
                profileMatchesLocation(stored, locationId) &&
                isUsableLocationName(stored?.location_name)
            ) {
                return stored.location_name.trim();
            }
        } catch {
            // Ignore malformed cached sessions.
        }
    }
    return "";
};

const getPersonDisplayName = (profile?: {
    full_name?: string | null;
    name?: string | null;
    firstName?: string | null;
    lastName?: string | null;
    email?: string | null;
    email_address?: string | null;
} | null): string => {
    if (!profile) return "";

    const joinedName = [profile.firstName, profile.lastName]
        .filter(Boolean)
        .join(" ")
        .trim();

    return (
        profile.full_name?.trim() ||
        profile.name?.trim() ||
        joinedName ||
        profile.email?.trim() ||
        profile.email_address?.trim() ||
        ""
    );
};

const pickReportText = (...values: Array<string | null | undefined>): string =>
    values.find(value => typeof value === "string" && value.trim() !== "")?.trim() || "";

const getStoredProfileDisplayName = (locationId: string): string => {
    for (const key of ["nola_auth_user", "nola_user"]) {
        try {
            const stored = JSON.parse(safeStorage.getItem(key) || "{}");
            if (profileMatchesLocation(stored, locationId)) {
                const displayName = getPersonDisplayName(stored);
                if (displayName) return displayName;
            }
        } catch {
            // Ignore malformed cached sessions.
        }
    }
    return "";
};

export const Home: React.FC<HomeProps> = ({ onTabChange, onCreateContact, onSelectContact, onSelectBulkMessage, topControls }) => {
    const { locationId } = useLocationId();
    const liveProfile = useUserProfileContext();
    const [accountProfile, setAccountProfile] = useState<AccountProfile | null>(() => {
        if (!locationId) return null;
        return (
            getCachedAccountProfile(locationId, { includeAuth: false, allowExpired: true }) ||
            getCachedAccountProfile(locationId, { allowExpired: true })
        );
    });
    const { status: creditStatus, loading: creditLoading } = useRealtimeCreditStatus(locationId);
    const [conversations, setConversations] = useState<Conversation[]>([]);
    const [transactions, setTransactions] = useState<CreditTransaction[]>([]);
    const [contacts, setContacts] = useState<Contact[]>([]);
    const [contactsCount, setContactsCount] = useState<number>(0);
    const [loading, setLoading] = useState(true);
    const [showAllActivity, setShowAllActivity] = useState(false);
    const [trendAnchor] = useState(() => new Date());
    const [searchQuery, setSearchQuery] = useState("");

    useEffect(() => {
        if (!locationId) {
            setAccountProfile(null);
            setLoading(false);
            return;
        }
        let cancelled = false;

        // Immediately set cached profile to avoid loading flash
        const cachedProfile =
            getCachedAccountProfile(locationId, { includeAuth: false, allowExpired: true }) ||
            getCachedAccountProfile(locationId, { allowExpired: true });
        setAccountProfile(cachedProfile);

        const loadData = async () => {
            setLoading(true);
            try {
                const [convRes, contactRes, txRes, profileRes] = await Promise.allSettled([
                    fetchConversations(locationId),
                    fetchContacts(locationId),
                    fetchCreditTransactions('default', 100, locationId),
                    fetchAccountProfile(locationId, {
                        includeAuth: false,
                        forceRefresh: true,
                        allowStaleOnError: true,
                    })
                ]);
                if (cancelled) return;
                if (convRes.status === 'fulfilled') setConversations(convRes.value || []);
                if (contactRes.status === 'fulfilled') {
                    const list = contactRes.value || [];
                    setContacts(list);
                    setContactsCount(list.length);
                }
                if (txRes.status === 'fulfilled') setTransactions(txRes.value || []);
                if (profileRes.status === 'fulfilled') {
                    setAccountProfile(profileRes.value || cachedProfile);
                }
            } catch (err) {
                devLog.error('[Home] Data fetch error:', err);
            } finally {
                if (!cancelled) setLoading(false);
            }
        };
        loadData();
        return () => { cancelled = true; };
    }, [locationId]);

    useEffect(() => {
        if (!loading) {
            // Give a short delay for the DOM to fully render with the loaded items,
            // then refresh GSAP ScrollTrigger so it recalculates all start/end offsets.
            const timer = setTimeout(() => {
                ScrollTrigger.refresh();
            }, 100);
            return () => clearTimeout(timer);
        }
    }, [loading]);

    const handleAnimationComplete = () => {};

    const contactNameLookup = buildContactNameLookup(contacts);

    const getDisplayName = (conv: Conversation): string => {
        const phone =
            extractPhoneFromDirectConversationId(conv.id) ||
            extractBatchIdFromGroupConversationId(conv.id) ||
            conv.id.replace(/^conv_/, '').replace(/^group_/, '');
        
        // Always try contacts first for direct conversations
        if (conv.type !== 'bulk') {
            const contactName = resolveContactNameByPhone(contactNameLookup, phone);
            if (contactName) return toProperCase(contactName);
        }

        // Use conv.name only if it's a real name (not a phone number)
        if (conv.name && !isPhoneLike(conv.name)) return toProperCase(conv.name);

        if (conv.type === 'bulk') {
            // For bulk, try resolving member names
            if (conv.members && conv.members.length > 0) {
                const memberNames = conv.members.slice(0, 3).map(m => {
                    const contactName = resolveContactNameByPhone(contactNameLookup, m);
                    return contactName ? toProperCase(contactName) : null;
                }).filter(Boolean);
                if (memberNames.length > 0) {
                    const extra = conv.members.length - memberNames.length;
                    return memberNames.join(', ') + (extra > 0 ? ` +${extra}` : '');
                }
            }
            return 'Bulk Message';
        }

        return phone;
    };

    const handleRecentClick = (conv: Conversation) => {
        if (conv.type === 'bulk') {
            const batchId = extractBatchIdFromGroupConversationId(conv.id) || conv.id.replace(/^group_/, '');
            onSelectBulkMessage({
                id: `bulk-db-${batchId}`,
                message: conv.last_message || '',
                recipientCount: conv.members.length,
                recipientNumbers: conv.members,
                recipientKey: batchId,
                timestamp: conv.last_message_at || conv.updated_at || new Date().toISOString(),
                status: 'sent',
                batchId,
                fromDatabase: true,
            });
        } else {
            const phone = extractPhoneFromDirectConversationId(conv.id) || conv.id.replace(/^conv_/, '');
            const displayName = getDisplayName(conv);
            onSelectContact({
                id: conv.id,
                name: displayName,
                phone: phone,
                lastMessage: conv.last_message
            });
        }
    };

    const accountProfileLocationName = isUsableLocationName(accountProfile?.location_name)
        ? accountProfile.location_name.trim()
        : "";
    const liveProfileLocationName =
        profileMatchesLocation(liveProfile, locationId) && isUsableLocationName(liveProfile?.location_name)
            ? liveProfile.location_name.trim()
            : "";
    const subaccountName =
        accountProfileLocationName ||
        liveProfileLocationName ||
        getStoredLocationName(locationId);
    const liveProfileDisplayName = profileMatchesLocation(liveProfile, locationId)
        ? getPersonDisplayName(liveProfile)
        : "";
    const profileDisplayName =
        subaccountName ||
        getPersonDisplayName(accountProfile) ||
        liveProfileDisplayName ||
        getStoredProfileDisplayName(locationId) ||
        "User";
    const liveReportProfile = profileMatchesLocation(liveProfile, locationId) ? liveProfile : null;
    const liveReportFields = (liveReportProfile || {}) as Partial<AccountProfile> & Record<string, string | null | undefined>;
    const cachedReportFields = (accountProfile || {}) as Partial<AccountProfile> & Record<string, string | null | undefined>;
    const reportAccountName = subaccountName || profileDisplayName || locationId || "My Account";
    const reportProfile = {
        accountName: reportAccountName,
        ownerName: pickReportText(getPersonDisplayName(liveReportProfile), getPersonDisplayName(accountProfile), getStoredProfileDisplayName(locationId)),
        email: pickReportText(liveReportFields.email, liveReportFields.email_address, cachedReportFields.email, cachedReportFields.email_address),
        phone: pickReportText(liveReportFields.phone, liveReportFields.phone_number, cachedReportFields.phone, cachedReportFields.phone_number),
        locationName: pickReportText(liveReportFields.location_name, cachedReportFields.location_name, subaccountName),
        locationId,
        agencyName: pickReportText(liveReportFields.company_name, liveReportFields.agency_name, cachedReportFields.company_name, cachedReportFields.agency_name),
        companyName: pickReportText(liveReportFields.company_name, liveReportFields.agency_name, cachedReportFields.company_name, cachedReportFields.agency_name),
        companyId: pickReportText(liveReportFields.company_id, cachedReportFields.company_id),
        reportTitle: "SUBACCOUNT CREDIT REPORT",
        currentBalance: creditStatus?.credit_balance ?? 0,
    };
    const profileInitial = profileDisplayName.charAt(0).toUpperCase();
    const greetingText = subaccountName ? `${getGreeting()}, ${subaccountName}` : getGreeting();
    const creditDisplayLoading = creditLoading && !creditStatus;
    const sentToday = creditStatus?.stats?.sent_today ?? 0;
    const creditsUsedMonth = creditStatus?.stats?.credits_used_month ?? 0;
    const lastActivityAt = conversations[0]?.last_message_at || conversations[0]?.updated_at;
    const trendStartDate = new Date(trendAnchor);
    trendStartDate.setDate(trendAnchor.getDate() - 13);
    const trendStartKey = dayKey(trendStartDate);
    const recentActivitySeries = createDaySeries(trendAnchor, 14);
    const recentActivityIndex = new Map(recentActivitySeries.map((point, index) => [point.key, index]));
    conversations.forEach((conv) => {
        const date = toDate(conv.last_message_at || conv.updated_at);
        if (!date) return;
        const index = recentActivityIndex.get(dayKey(date));
        if (index !== undefined && dayKey(date) >= trendStartKey) {
            recentActivitySeries[index].value += 1;
        }
    });
    const creditUsageSeries = createDaySeries(trendAnchor, 14);
    const creditUsageIndex = new Map(creditUsageSeries.map((point, index) => [point.key, index]));
    transactions.forEach((tx) => {
        const log = tx as HomeCreditTransaction;
        const date = toDate(log.created_at);
        if (!date) return;
        const index = creditUsageIndex.get(dayKey(date));
        const isAdjustment = log.type === 'manual_adjustment' || log.type === 'admin_adjustment' || log.type === 'agency_adjustment';
        const isUsage = log.type !== 'top_up' && log.type !== 'refund' && !isAdjustment && log.type !== 'credit_purchase';
        if (index !== undefined && isUsage && dayKey(date) >= trendStartKey) {
            creditUsageSeries[index].value += Math.abs(log.amount || 0);
        }
    });
    const sentTodaySeries = createDaySeries(trendAnchor, 14);
    const sentTodayIndex = new Map(sentTodaySeries.map((point, index) => [point.key, index]));
    transactions.forEach((tx) => {
        const log = tx as HomeCreditTransaction;
        const date = toDate(log.created_at);
        if (!date) return;
        const index = sentTodayIndex.get(dayKey(date));
        const isAdjustment = log.type === 'manual_adjustment' || log.type === 'admin_adjustment' || log.type === 'agency_adjustment';
        const isUsage = log.type !== 'top_up' && log.type !== 'refund' && !isAdjustment && log.type !== 'credit_purchase';
        if (index !== undefined && isUsage && dayKey(date) >= trendStartKey) {
            sentTodaySeries[index].value += 1;
        }
    });
    if (sentTodaySeries.length > 0) {
        sentTodaySeries[sentTodaySeries.length - 1].value = sentToday;
    }
    const sentMetricSeries = sentTodaySeries;
    const creditMetricSeries = creditUsageSeries;
    const latestMetricSeries = recentActivitySeries;
    const sentTrendTotal = getSeriesTotal(sentMetricSeries);
    const creditTrendTotal = getSeriesTotal(creditMetricSeries);
    const latestTrendTotal = getSeriesTotal(latestMetricSeries);
    const latestActivityDate = lastActivityAt ? new Date(lastActivityAt) : null;
    const latestActivityValue = latestActivityDate
        ? latestActivityDate.toLocaleDateString([], { month: 'short', day: 'numeric' })
        : 'No activity';
    const latestActivityTime = latestActivityDate
        ? latestActivityDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : 'Start a conversation';
    const latestActivityCaption = lastActivityAt
        ? `${latestTrendTotal.toLocaleString()} ${latestTrendTotal === 1 ? 'conversation update' : 'conversation updates'} in 14 days`
        : 'No conversation updates yet';
    const renderMiniBars = (series: TrendPoint[], color: string, isLoading: boolean, unitLabel: string) => {
        const totalValue = getSeriesTotal(series);
        const maxValue = Math.max(1, ...series.map((point) => point.value));
        const startLabel = series[0]?.label || '';
        const endLabel = series[series.length - 1]?.label || '';

        if (isLoading) {
            return (
                <div>
                    <div className="h-14 flex items-end gap-1.5">
                        {[36, 58, 44, 72, 52, 84, 64].map((height, index) => (
                            <div
                                key={`bar-skeleton-${index}`}
                                className="flex-1 rounded-t-md bg-[#edf0f3] dark:bg-white/10 skeleton-gleam"
                                style={{ height: `${height}%` }}
                            />
                        ))}
                    </div>
                    <div className="mt-2 h-3 w-full rounded-full bg-[#edf0f3] dark:bg-white/10 skeleton-gleam" />
                </div>
            );
        }

        return (
            <div>
                <div className="relative h-14 flex items-end gap-1.5" aria-label={`14 day ${unitLabel} trend`}>
                    <div className="absolute inset-x-0 bottom-0 h-px bg-slate-200 dark:bg-white/10" />
                    {series.map((point) => {
                        const height = point.value === 0 ? 10 : Math.max(28, Math.round((point.value / maxValue) * 100));
                        return (
                            <div
                                key={point.key}
                                className="group/bar relative z-10 flex-1 rounded-t-[5px] transition-all duration-300 hover:opacity-100 hover:scale-y-105 origin-bottom"
                                title={`${point.label}: ${point.value.toLocaleString()} ${unitLabel}`}
                                style={{
                                    height: `${height}%`,
                                    backgroundColor: point.value === 0 ? "rgba(148,163,184,0.18)" : color,
                                    opacity: point.value === 0 ? 1 : 0.98,
                                    boxShadow: point.value === 0 ? "none" : `0 0 0 1px ${color}1f`,
                                }}
                            />
                        );
                    })}
                    {totalValue === 0 && (
                        <span className="absolute inset-x-0 top-4 text-center text-[11px] font-bold text-slate-400 dark:text-slate-500">
                            No recent data
                        </span>
                    )}
                </div>
                <div className="mt-2 flex items-center justify-between text-[10px] font-bold uppercase tracking-wide text-[#94a3b8] dark:text-[#697386]">
                    <span>{startLabel}</span>
                    <span>14-day trend</span>
                    <span>{endLabel}</span>
                </div>
            </div>
        );
    };

    return (
        <div id="snap-main-container" className="flex-1 w-full flex flex-col overflow-y-auto custom-scrollbar bg-[#f3f4f6] dark:bg-[#09090b] relative">
            {/* Background Gradient Header */}
            <div className="absolute top-0 left-0 w-full h-[340px] bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] z-0 rounded-b-[40px] pointer-events-none" />

            <div className="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-6 sm:py-8 relative z-10">
                {/* Top Navbar */}
                <div className="flex flex-col sm:flex-row items-center justify-between gap-4 mb-8">
                    <div className="text-white min-w-0 flex-shrink-0 w-full sm:w-auto">
                        <SplitText
                            key={greetingText}
                            text={greetingText}
                            className="text-2xl sm:text-3xl font-extrabold tracking-tight leading-tight"
                            delay={50}
                            duration={1.25}
                            ease="power3.out"
                            splitType="chars"
                            from={{ opacity: 0, y: 40 }}
                            to={{ opacity: 1, y: 0 }}
                            threshold={0.1}
                            rootMargin="-100px"
                            textAlign="left"
                            tag="h1"
                            onLetterAnimationComplete={handleAnimationComplete}
                        />
                        <FadeContent blur={false} duration={1500} ease="ease-out" initialOpacity={0}>
                            <p className="text-white/80 font-medium mt-1">
                                {subaccountName ? "Your subaccount is ready for today's conversations." : "Welcome back to NOLA SMS PRO."}
                            </p>
                        </FadeContent>
                    </div>

                    <div className="flex items-center gap-3 w-full sm:w-auto">
                        <div className="relative flex-1 sm:w-64" onClick={(e) => e.stopPropagation()}>
                            <FiSearch className="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-white/70" />
                            <input
                                type="text"
                                placeholder="Search contacts..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="w-full pl-11 pr-4 py-2.5 bg-white/10 border border-white/20 rounded-xl text-[14px] font-medium text-white placeholder-white/70 focus:outline-none focus:ring-2 focus:ring-white/30 focus:bg-white/20 transition-all"
                            />
                            {searchQuery.trim() !== "" && (
                                <div className="absolute top-full left-0 right-0 mt-2 bg-white/95 dark:bg-[#1a1b1e]/95 backdrop-blur-md border border-gray-200 dark:border-white/10 rounded-2xl shadow-xl z-50 overflow-hidden py-1.5 animate-in fade-in slide-in-from-top-2 duration-200 max-h-60 overflow-y-auto custom-scrollbar">
                                    {(() => {
                                        const query = searchQuery.toLowerCase();
                                        const filtered = contacts.filter(c =>
                                            c.name.toLowerCase().includes(query) || c.phone.toLowerCase().includes(query)
                                        ).slice(0, 5);

                                        if (filtered.length > 0) {
                                            return filtered.map(contact => {
                                                const cInitial = contact.name.charAt(0).toUpperCase();
                                                const cColor = getProfileColor(contact.name);
                                                return (
                                                    <button
                                                        key={contact.id}
                                                        onClick={() => {
                                                            onSelectContact(contact);
                                                            setSearchQuery("");
                                                        }}
                                                        className="w-full px-4 py-2.5 hover:bg-gray-100 dark:hover:bg-white/[0.05] transition-colors text-left flex items-center gap-3"
                                                    >
                                                        <div
                                                            className="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-[12px] flex-shrink-0"
                                                            style={{ backgroundColor: cColor }}
                                                        >
                                                            {cInitial}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <p className="text-[13.5px] font-bold text-[#111111] dark:text-white leading-tight truncate">
                                                                {toProperCase(contact.name)}
                                                            </p>
                                                            <p className="text-[11.5px] text-gray-500 dark:text-gray-400 font-medium truncate">
                                                                {contact.phone}
                                                            </p>
                                                        </div>
                                                    </button>
                                                );
                                            });
                                        } else {
                                            return (
                                                <div className="px-4 py-3 text-center text-gray-400 dark:text-gray-500 text-[12.5px] font-medium italic">
                                                    No contacts found
                                                </div>
                                            );
                                        }
                                    })()}
                                </div>
                            )}
                        </div>
                        <div
                            onClick={() => window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'account' } }))}
                            className="w-10 h-10 rounded-full flex items-center justify-center shadow-lg shadow-black/10 border-2 border-white/20 flex-shrink-0 text-white font-bold text-[14px] overflow-hidden cursor-pointer hover:scale-105 active:scale-95 transition-all"
                            style={{ backgroundColor: getProfileColor(profileDisplayName) }}
                            title={profileDisplayName || "Profile"}
                        >
                            {profileInitial || <FiUser className="w-5 h-5" />}
                        </div>
                        {topControls}
                    </div>
                </div>

                {/* First Row: 3 Main Cards (Credits, Conversations, Contacts) */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6 mb-8">
                    <AnimatedContent delay={0.1} distance={50} direction="vertical">
                        {(() => {
                            const balance      = creditStatus?.credit_balance ?? 0;
                            const trialUsed    = creditStatus?.free_usage_count ?? 0;
                            const trialTotal   = creditStatus?.free_credits_total ?? 0;
                            const isTrialActive = trialTotal > 0 && trialUsed < trialTotal;
                            const trialLeft    = trialTotal - trialUsed;
                            return (
                                <div className="p-6 rounded-[24px] bg-gradient-to-br from-[#e0f2fe] via-[#60a5fa] to-[#06b6d4] dark:from-[#3b82f6] dark:via-[#2584d5] dark:to-[#14a3a1] shadow-xl shadow-blue-500/20 hover:shadow-blue-500/30 transition-all group overflow-hidden relative h-full border border-white/70 dark:border-blue-200/20">
                                    <div className="absolute inset-0 bg-white/10 dark:bg-white/[0.05] pointer-events-none" />
                                    <div className="absolute bottom-0 right-0 p-4 opacity-[0.13] dark:opacity-[0.16] group-hover:scale-110 transition-transform duration-500 text-blue-900 dark:text-blue-100">
                                        <FiCreditCard className="w-24 h-24" />
                                    </div>
                                    {loading || creditDisplayLoading ? (
                                        <DashboardCardSkeleton valueWidth="w-24" />
                                    ) : (
                                        <>
                                            <button
                                                onClick={() => window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'credits' } }))}
                                                className="group/action absolute right-4 top-4 z-20 flex h-10 w-10 items-center justify-center rounded-full bg-blue-700 text-white shadow-[0_10px_24px_rgba(29,78,216,0.32)] ring-1 ring-white/45 hover:bg-blue-800 hover:shadow-[0_14px_30px_rgba(29,78,216,0.42)] hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-blue-300 dark:bg-blue-500 dark:text-white dark:ring-white/30 dark:shadow-black/25 dark:hover:bg-blue-400 dark:focus-visible:ring-offset-blue-900 transition-all"
                                                aria-label="Open credits"
                                                title="Open credits"
                                            >
                                                <FiPlus className="h-[18px] w-[18px] stroke-[2.4] transition-transform duration-200 group-hover/action:rotate-90" />
                                            </button>
                                            <div className="relative z-10 flex flex-col h-full justify-between">
                                                <div>
                                                    <div className="w-10 h-10 rounded-xl bg-white/70 dark:bg-white/[0.14] flex items-center justify-center text-blue-700 dark:text-blue-50 mb-4 shadow-sm shadow-blue-900/10 ring-1 ring-white/40 dark:ring-white/10">
                                                        <FiCreditCard className="h-5 w-5" />
                                                    </div>
                                                    <p className="text-[12px] font-bold text-blue-950/70 dark:text-blue-50/80 uppercase tracking-widest mb-1">
                                                        Available Credits
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-3 mt-4">
                                                    <h2 className="text-3xl sm:text-4xl font-black text-[#082f49] dark:text-white leading-none">
                                                        {balance.toLocaleString()}
                                                    </h2>
                                                    {isTrialActive && (
                                                        <span className="text-[10px] font-bold bg-white/75 dark:bg-white/[0.16] text-blue-800 dark:text-blue-50 px-2.5 py-0.5 rounded-full whitespace-nowrap ring-1 ring-white/50 dark:ring-white/10">
                                                            {trialLeft} Free Trial
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </>
                                    )}
                                </div>
                            );
                        })()}
                    </AnimatedContent>

                    <AnimatedContent delay={0.2} distance={50} direction="vertical">
                        <div className="p-6 rounded-[24px] bg-gradient-to-br from-[#fae8ff] via-[#c084fc] to-[#7c3aed] dark:from-[#8b5cf6] dark:via-[#7c3aed] dark:to-[#5b5ce2] shadow-xl shadow-purple-500/20 hover:shadow-purple-500/30 transition-all group overflow-hidden relative h-full border border-white/70 dark:border-purple-200/20">
                            <div className="absolute inset-0 bg-white/10 dark:bg-white/[0.05] pointer-events-none" />
                            <div className="absolute bottom-0 right-0 p-4 opacity-[0.13] dark:opacity-[0.16] group-hover:scale-110 transition-transform duration-500 text-purple-900 dark:text-purple-100">
                                <FiMessageSquare className="w-24 h-24" />
                            </div>
                            {loading ? (
                                <DashboardCardSkeleton />
                            ) : (
                                <>
                                    <button
                                        onClick={() => onTabChange('compose')}
                                        className="group/action absolute right-4 top-4 z-20 flex h-10 w-10 items-center justify-center rounded-full bg-purple-700 text-white shadow-[0_10px_24px_rgba(109,40,217,0.32)] ring-1 ring-white/45 hover:bg-purple-800 hover:shadow-[0_14px_30px_rgba(109,40,217,0.42)] hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-violet-300 dark:bg-purple-500 dark:text-white dark:ring-white/30 dark:shadow-black/25 dark:hover:bg-purple-400 dark:focus-visible:ring-offset-purple-900 transition-all"
                                        aria-label="New message"
                                        title="New message"
                                    >
                                        <FiPlus className="h-[18px] w-[18px] stroke-[2.4] transition-transform duration-200 group-hover/action:rotate-90" />
                                    </button>
                                    <div className="relative z-10 flex flex-col h-full justify-between">
                                        <div>
                                            <div className="w-10 h-10 rounded-xl bg-white/70 dark:bg-white/[0.14] flex items-center justify-center text-purple-700 dark:text-purple-50 mb-4 shadow-sm shadow-purple-900/10 ring-1 ring-white/40 dark:ring-white/10">
                                                <FiMessageSquare className="h-5 w-5" />
                                            </div>
                                            <p className="text-[12px] font-bold text-purple-950/70 dark:text-purple-50/80 uppercase tracking-widest mb-1">Active Conversations</p>
                                        </div>
                                        <h2 className="text-3xl sm:text-4xl font-black text-[#3b0764] dark:text-white leading-none mt-4">
                                            {conversations.length}
                                        </h2>
                                    </div>
                                </>
                            )}
                        </div>
                    </AnimatedContent>

                    <AnimatedContent delay={0.3} distance={50} direction="vertical">
                        <div className="p-6 rounded-[24px] bg-gradient-to-br from-[#dcfce7] via-[#86efac] to-[#2dd4bf] dark:from-[#10b981] dark:via-[#0ea56f] dark:to-[#0d9488] shadow-xl shadow-emerald-500/20 hover:shadow-emerald-500/30 transition-all group overflow-hidden relative h-full border border-white/70 dark:border-emerald-200/20">
                            <div className="absolute inset-0 bg-white/10 dark:bg-white/[0.05] pointer-events-none" />
                            <div className="absolute bottom-0 right-0 p-4 opacity-[0.13] dark:opacity-[0.16] group-hover:scale-110 transition-transform duration-500 text-emerald-900 dark:text-emerald-100">
                                <FiUsers className="w-24 h-24" />
                            </div>
                            {loading ? (
                                <DashboardCardSkeleton />
                            ) : (
                                <>
                                    <button
                                        onClick={onCreateContact}
                                        className="group/action absolute right-4 top-4 z-20 flex h-10 w-10 items-center justify-center rounded-full bg-emerald-700 text-white shadow-[0_10px_24px_rgba(4,120,87,0.32)] ring-1 ring-white/45 hover:bg-emerald-800 hover:shadow-[0_14px_30px_rgba(4,120,87,0.42)] hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-emerald-300 dark:bg-emerald-500 dark:text-white dark:ring-white/30 dark:shadow-black/25 dark:hover:bg-emerald-400 dark:focus-visible:ring-offset-emerald-900 transition-all"
                                        aria-label="Add contact"
                                        title="Add contact"
                                    >
                                        <FiPlus className="h-[18px] w-[18px] stroke-[2.4] transition-transform duration-200 group-hover/action:rotate-90" />
                                    </button>
                                    <div className="relative z-10 flex flex-col h-full justify-between">
                                        <div>
                                            <div className="w-10 h-10 rounded-xl bg-white/70 dark:bg-white/[0.14] flex items-center justify-center text-emerald-700 dark:text-emerald-50 mb-4 shadow-sm shadow-emerald-900/10 ring-1 ring-white/40 dark:ring-white/10">
                                                <FiUsers className="h-5 w-5" />
                                            </div>
                                            <p className="text-[12px] font-bold text-emerald-950/70 dark:text-emerald-50/80 uppercase tracking-widest mb-1">Total Contacts</p>
                                        </div>
                                        <h2 className="text-3xl sm:text-4xl font-black text-[#022c22] dark:text-white leading-none mt-4">
                                            {contactsCount}
                                        </h2>
                                    </div>
                                </>
                            )}
                        </div>
                    </AnimatedContent>
                </div>

                {/* Sender ID Reminder Banner */}
                <SenderIdReminderBanner
                    onNavigateToSettings={() => window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'senderIds' } }))}
                />

                {/* Second Row: Grouped Stats (Sent Today, Credits Used, Latest Activity) */}
                <AnimatedContent delay={0.35} distance={40} direction="vertical">
                    <div className="bg-white dark:bg-[#1c1e21] rounded-[24px] shadow-sm mb-8 overflow-hidden border border-[#0000000a] dark:border-[#ffffff0a]">
                        <div className="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-[#0000000a] dark:divide-[#ffffff0a]">
                            {[
                                {
                                    label: 'Sent Today',
                                    value: creditDisplayLoading ? '—' : sentToday.toLocaleString(),
                                    accent: '#ef4444',
                                    series: sentMetricSeries,
                                    note: 'Messages sent today',
                                    badge: `${sentTrendTotal.toLocaleString()} in 14 days`,
                                    chartUnit: 'messages',
                                    chartCaption: sentToday > 0 ? 'Today is the final bar' : 'No messages sent today',
                                    onClick: () => onTabChange('compose'),
                                },
                                {
                                    label: 'Credits Used This Month',
                                    value: creditDisplayLoading ? '—' : creditsUsedMonth.toLocaleString(),
                                    accent: '#8b5cf6',
                                    series: creditMetricSeries,
                                    note: 'Credits used this month',
                                    badge: creditTrendTotal > 0 ? `${creditTrendTotal.toLocaleString()} in 14 days` : 'No recent log',
                                    chartUnit: 'credits',
                                    chartCaption: creditTrendTotal > 0 ? 'Recent usage from credit logs' : 'Monthly total shown above',
                                    onClick: () => window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'credits' } })),
                                },
                                {
                                    label: 'Latest Activity',
                                    value: latestActivityValue,
                                    accent: '#10b981',
                                    series: latestMetricSeries,
                                    note: latestActivityTime,
                                    badge: lastActivityAt ? 'Open latest' : 'No updates',
                                    chartUnit: 'updates',
                                    chartCaption: latestActivityCaption,
                                    onClick: () => {
                                        if (conversations[0]) handleRecentClick(conversations[0]);
                                    },
                                },
                            ].map((item, idx) => (
                                <button
                                    key={idx}
                                    type="button"
                                    onClick={item.onClick}
                                    className="p-6 flex flex-col justify-between text-left transition-colors hover:bg-black/[0.025] dark:hover:bg-white/[0.035] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#2b83fa]/50"
                                >
                                    <div>
                                        <div className="mb-3 flex items-center justify-between gap-3">
                                            <p className="text-[12px] font-black uppercase tracking-[0.08em] text-[#475569] dark:text-[#a9bdd8]">{item.label}</p>
                                            {!loading && (
                                                <span
                                                    className="h-2.5 w-2.5 rounded-full shadow-sm"
                                                    style={{ backgroundColor: item.accent, boxShadow: `0 0 0 4px ${item.accent}1f` }}
                                                />
                                            )}
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            {loading ? (
                                                <div className="h-8 w-28 bg-gray-100 dark:bg-white/5 animate-pulse rounded-lg" />
                                            ) : (
                                                <div className="flex items-end gap-2 min-w-0">
                                                    <h2 className="text-[28px] leading-none font-black text-[#111111] dark:text-white break-words" title={item.value}>
                                                        {item.value}
                                                    </h2>
                                                    <span
                                                        className="mb-0.5 rounded-full px-2 py-1 text-[10px] font-black leading-none"
                                                        style={{
                                                            color: item.accent,
                                                            backgroundColor: `${item.accent}18`,
                                                        }}
                                                    >
                                                        {item.badge}
                                                    </span>
                                                </div>
                                            )}
                                            {!loading && (
                                                <p className="text-[12px] font-semibold leading-snug text-[#64748b] dark:text-[#9aa7bb]">
                                                    {item.note}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="mt-6 rounded-2xl bg-[#f8fafc] px-3 py-3 ring-1 ring-black/[0.03] dark:bg-black/15 dark:ring-white/[0.04]">
                                        {renderMiniBars(item.series, item.accent, loading, item.chartUnit)}
                                        {!loading && (
                                            <p className="mt-2 text-[11px] font-semibold text-[#64748b] dark:text-[#8b95a7]">
                                                {item.chartCaption}
                                            </p>
                                        )}
                                    </div>
                                </button>
                            ))}
                        </div>
                    </div>
                </AnimatedContent>

                {/* Third Row: Quick Actions & Recent Activity */}
                <div className="grid grid-cols-1 lg:grid-cols-[0.95fr_1.05fr] gap-6 lg:gap-8 mb-8">
                    {/* Quick Actions Column */}
                    <div>
                        <AnimatedContent delay={0.4} distance={50} direction="vertical">
                            <h3 className="text-[15px] font-bold text-[#111111] dark:text-white mb-5 flex items-center gap-2 h-8">
                                Quick Actions
                            </h3>
                        </AnimatedContent>
                        <div className="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 gap-3">
                            <AnimatedContent delay={0.45} distance={30} direction="vertical">
                                <button
                                    onClick={() => onTabChange('compose')}
                                    className="w-full h-[80px] p-4 rounded-[20px] bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] shadow-sm hover:shadow-indigo-500/10 hover:border-[#2b83fa]/30 transition-all duration-300 text-left flex items-center justify-between group hover:-translate-y-0.5"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-11 h-11 rounded-xl bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center text-[#2b83fa] transition-transform duration-300 group-hover:scale-110 flex-shrink-0">
                                            <FiPlus className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0">
                                            <h4 className="font-bold text-[#111111] dark:text-white text-[13.5px]">Start New Chat</h4>
                                            <p className="text-[11.5px] text-gray-500 dark:text-gray-400 font-medium truncate">Create a single or bulk message</p>
                                        </div>
                                    </div>
                                    <FiArrowRight className="h-4 w-4 text-gray-300 group-hover:text-[#2b83fa] group-hover:translate-x-1 transition-all flex-shrink-0" />
                                </button>
                            </AnimatedContent>

                            <AnimatedContent delay={0.5} distance={30} direction="vertical">
                                <button
                                    onClick={() => onTabChange('contacts')}
                                    className="w-full h-[80px] p-4 rounded-[20px] bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] shadow-sm hover:shadow-emerald-500/10 hover:border-emerald-500/30 transition-all duration-300 text-left flex items-center justify-between group hover:-translate-y-0.5"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-11 h-11 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center text-emerald-500 transition-transform duration-300 group-hover:scale-110 flex-shrink-0">
                                            <FiUsers className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0">
                                            <h4 className="font-bold text-[#111111] dark:text-white text-[13.5px]">Manage Contacts</h4>
                                            <p className="text-[11.5px] text-gray-500 dark:text-gray-400 font-medium truncate">Add, edit, or remove recipients</p>
                                        </div>
                                    </div>
                                    <FiArrowRight className="h-4 w-4 text-gray-300 group-hover:text-emerald-500 group-hover:translate-x-1 transition-all flex-shrink-0" />
                                </button>
                            </AnimatedContent>

                            <AnimatedContent delay={0.55} distance={30} direction="vertical">
                                <button
                                    onClick={() => onTabChange('settings')}
                                    className="w-full h-[80px] p-4 rounded-[20px] bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] shadow-sm hover:shadow-gray-500/10 hover:border-gray-500/30 transition-all duration-300 text-left flex items-center justify-between group hover:-translate-y-0.5"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-11 h-11 rounded-xl bg-gray-50 dark:bg-gray-800 flex items-center justify-center text-gray-600 dark:text-gray-400 transition-transform duration-300 group-hover:scale-110 flex-shrink-0">
                                            <FiSettings className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0">
                                            <h4 className="font-bold text-[#111111] dark:text-white text-[13.5px]">Account Settings</h4>
                                            <p className="text-[11.5px] text-gray-500 dark:text-gray-400 font-medium truncate">Profile, API keys, and more</p>
                                        </div>
                                    </div>
                                    <FiArrowRight className="h-4 w-4 text-gray-300 group-hover:text-gray-600 group-hover:translate-x-1 transition-all flex-shrink-0" />
                                </button>
                            </AnimatedContent>
                        </div>
                    </div>

                    {/* Recent Activity Column */}
                    <div>
                        <AnimatedContent delay={0.4} distance={50} direction="vertical">
                            <div className="flex items-center justify-between mb-5 h-8">
                                <h3 className="text-[15px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                                    Recent Activity
                                </h3>
                                {conversations.length > 3 && (
                                    <button
                                        onClick={() => setShowAllActivity(true)}
                                        className="text-[12px] font-bold text-[#2b83fa] hover:text-[#1a65d1] py-1 px-3 rounded-full hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                                    >
                                        See All
                                    </button>
                                )}
                            </div>
                        </AnimatedContent>
                        
                        <div className="flex flex-col gap-3">
                            {loading && conversations.length === 0 ? (
                                [1, 2, 3].map((i, idx) => (
                                    <AnimatedContent key={`skel-${i}`} delay={0.45 + idx * 0.05} distance={30} direction="vertical">
                                        <div className="w-full h-[80px] p-4 rounded-[20px] bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] flex items-center justify-between">
                                            <div className="flex items-center gap-3 w-full">
                                                <div className="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 animate-pulse flex-shrink-0" />
                                                <div className="space-y-2 w-full max-w-[150px]">
                                                    <div className="h-3 w-3/4 bg-gray-100 dark:bg-gray-800 animate-pulse rounded" />
                                                    <div className="h-2 w-full bg-gray-50 dark:bg-gray-900 animate-pulse rounded opacity-60" />
                                                </div>
                                            </div>
                                            <div className="w-16 h-3 bg-gray-50 dark:bg-gray-900 animate-pulse rounded opacity-60" />
                                        </div>
                                    </AnimatedContent>
                                ))
                            ) : conversations.length > 0 ? (
                                conversations.slice(0, 3).map((conv, idx) => (
                                    <AnimatedContent key={conv.id} delay={0.45 + idx * 0.05} distance={30} direction="vertical">
                                        <button
                                            onClick={() => handleRecentClick(conv)}
                                            className="w-full h-[80px] p-4 rounded-[20px] bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] shadow-sm hover:border-[#2b83fa]/20 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 text-left flex items-center justify-between group"
                                        >
                                            <div className="flex items-center gap-3 overflow-hidden">
                                                <div className={`w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center text-white font-bold shadow-sm transition-transform duration-300 group-hover:rotate-12 ${conv.type === 'bulk' ? 'bg-gradient-to-br from-purple-500 to-indigo-600' : 'bg-gradient-to-br from-[#2b83fa] to-[#60a5fa]'}`}>
                                                    {conv.type === 'bulk' ? <FiUsers size={18} /> : (() => { const dn = getDisplayName(conv); return dn ? dn.charAt(0).toUpperCase() : <FiUser size={18} />; })()}
                                                </div>
                                                <div className="min-w-0">
                                                    <h4 className="font-bold text-[#111111] dark:text-white text-[13.5px] truncate">
                                                        {getDisplayName(conv)}
                                                    </h4>
                                                    <p className="max-w-[200px] truncate text-[11.5px] font-medium text-gray-500 dark:text-gray-400" title={conv.last_message || "No messages yet"}>
                                                        {conv.last_message || "No messages yet"}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex flex-col items-end gap-1 flex-shrink-0">
                                                <div className="flex items-center gap-1 text-[10px] text-gray-400 font-bold uppercase tracking-tighter">
                                                    <FiClock size={10} />
                                                    {conv.last_message_at ? new Date(conv.last_message_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : "--"}
                                                </div>
                                                <div className="px-1.5 py-0.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-[#2b83fa] text-[9px] font-black uppercase tracking-widest opacity-0 group-hover:opacity-100 transition-opacity">
                                                    View
                                                </div>
                                            </div>
                                        </button>
                                    </AnimatedContent>
                                ))
                            ) : (
                                <AnimatedContent delay={0.45} distance={30} direction="vertical">
                                    <HomeEmptyPanel
                                        icon={<FiMessageSquare className="h-5 w-5" />}
                                        title="No conversations yet"
                                        description="Start a single or bulk SMS thread and recent message activity will appear here."
                                        actionLabel="Start Message"
                                        onAction={() => onTabChange('compose')}
                                    />
                                </AnimatedContent>
                            )}
                        </div>
                    </div>
                </div>

                {/* Last Row: Recent Transactions */}
                <AnimatedContent delay={0.35} distance={40} direction="vertical">
                    <div className="mb-8 bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-3xl p-4 sm:p-6 shadow-sm">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                            <h3 className="text-[14px] font-bold text-[#111111] dark:text-white uppercase tracking-wider flex items-center gap-2">
                                <FiActivity className="w-4 h-4 text-amber-500" /> Recent Transactions
                            </h3>
                            <div className="flex items-center gap-2 flex-wrap">
                                <button
                                    onClick={async () => {
                                        const currentMonth = new Date().toISOString().slice(0, 7);
                                        const allTxs = await fetchCreditTransactions('default', 5000, locationId || undefined);
                                        generateMonthlyReport(currentMonth, allTxs, 'subaccount', reportAccountName, reportProfile);
                                    }}
                                    className="text-[12px] font-bold text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-[#ffffff] py-1 px-3 border border-transparent rounded-full hover:bg-gray-100 dark:hover:bg-white/5 transition-colors flex items-center gap-1.5"
                                >
                                    <FiDownload className="w-3.5 h-3.5" /> Download Report
                                </button>
                                <button
                                    onClick={() => window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'credits' } }))}
                                    className="text-[12px] font-bold text-[#2b83fa] hover:text-[#1a65d1] py-1 px-3 rounded-full hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                                >
                                    See All
                                </button>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3">
                            {loading ? (
                                [...Array(4)].map((_, idx) => (
                                    <div key={`tx-skel-${idx}`} className="h-[80px] flex items-center gap-4 p-4 rounded-[20px] bg-[#f7f7f7] dark:bg-[#0d0e10] border border-transparent shadow-sm">
                                        <div className="w-10 h-10 rounded-full bg-gray-200/70 dark:bg-white/10 skeleton-gleam flex-shrink-0" />
                                        <div className="flex-1 min-w-0 space-y-2">
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="h-3.5 w-44 max-w-[55%] rounded-full bg-gray-200/70 dark:bg-white/10 skeleton-gleam" />
                                                <div className="h-3 w-24 rounded-full bg-gray-200/70 dark:bg-white/10 skeleton-gleam" />
                                            </div>
                                            <div className="h-3 w-32 rounded-full bg-gray-200/70 dark:bg-white/10 skeleton-gleam" />
                                        </div>
                                    </div>
                                ))
                            ) : transactions.length > 0 ? (
                                transactions.slice(0, 4).map((tx: HomeCreditTransaction, idx) => {
                                    const isAdjustment = tx.type === 'manual_adjustment' || tx.type === 'admin_adjustment' || tx.type === 'agency_adjustment';
                                    const isCredit = tx.type === 'top_up' || tx.type === 'refund' || tx.type === 'credit_purchase' || (isAdjustment && (tx.amount || 0) >= 0);
                                    const isUsage = !isCredit;
                                    const timeString = tx.created_at ? new Date(tx.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                                    const dateString = tx.created_at ? new Date(tx.created_at).toLocaleDateString([], { month: 'short', day: 'numeric' }) : '';

                                    return (
                                        <div key={`recent-tx-${idx}-${tx.transaction_id || tx.id || 'none'}`} className="group h-[80px] flex items-center gap-4 p-4 rounded-[20px] bg-[#f7f7f7] dark:bg-[#0d0e10] border border-transparent hover:border-[#e5e5e5] dark:hover:border-white/10 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
                                            <div className={`w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 shadow-sm transition-transform duration-300 group-hover:scale-110 group-hover:-rotate-3 ${isUsage ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-500' : 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-500'}`}>
                                                {isUsage ? <FiActivity className="w-5 h-5" /> : <FiCreditCard className="w-5 h-5" />}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between mb-1 gap-2">
                                                    <p className="truncate text-[14px] font-bold leading-tight text-[#111111] dark:text-white" title={isAdjustment ? `Manual credit adjustment (Applied ${tx.amount && tx.amount >= 0 ? '+' : '-'}${Math.abs(tx.amount || 0)} credits)` : (tx.description || (isUsage ? 'Credits Used' : 'Credits Purchased'))}>
                                                        {isAdjustment
                                                            ? `Manual credit adjustment (Applied ${tx.amount && tx.amount >= 0 ? '+' : '-'}${Math.abs(tx.amount || 0)} credits)`
                                                            : (tx.description || (isUsage ? 'Credits Used' : 'Credits Purchased'))}
                                                    </p>
                                                    <span className="text-[11px] uppercase font-bold text-[#9aa0a6] tracking-wider whitespace-nowrap flex-shrink-0">
                                                        {dateString}{timeString ? ` - ${timeString}` : ''}
                                                    </span>
                                                </div>
                                                <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] leading-snug truncate">
                                                    {isUsage && Math.abs(tx.amount || 0) === 0 ? (
                                                        <span className="font-bold text-amber-500">-1 free trial</span>
                                                    ) : isUsage ? (
                                                        <span className="font-bold text-amber-500">{Math.abs(tx.amount || 0).toLocaleString()} credits used</span>
                                                    ) : (
                                                        <>Added <span className="font-bold text-emerald-500">+{Math.abs(tx.amount || 0).toLocaleString()}</span> credits</>
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })
                            ) : (
                                <HomeEmptyPanel
                                    icon={<FiCreditCard className="h-5 w-5" />}
                                    title="No credit activity yet"
                                    description="Top ups, SMS usage, refunds, and adjustments will be listed once the account has activity."
                                    actionLabel="View Credits"
                                    onAction={() => window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'credits' } }))}
                                />
                            )}
                        </div>
                    </div>
                </AnimatedContent>

                {/* All Activity Popup */}
                {showAllActivity && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm animate-in fade-in duration-200">
                        <div
                            className="bg-white dark:bg-[#1c1e21] w-full max-w-md rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[85vh] animate-in zoom-in-[0.98] duration-200 border border-[#0000000a] dark:border-[#ffffff0a]"
                            onClick={e => e.stopPropagation()}
                        >
                            <div className="flex items-center justify-between p-5 border-b border-[#00000005] dark:border-[#ffffff05]">
                                <h3 className="text-[17px] font-bold text-[#111111] dark:text-white">All Recent Activity</h3>
                                <button
                                    onClick={() => setShowAllActivity(false)}
                                    className="p-2 -mr-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 bg-transparent hover:bg-black/5 dark:hover:bg-white/5 rounded-full transition-colors active:scale-95"
                                >
                                    <FiX size={20} />
                                </button>
                            </div>
                            <div className="p-2 overflow-y-auto custom-scrollbar flex-1">
                                {conversations.map(conv => (
                                    <button
                                        key={`modal-${conv.id}`}
                                        onClick={() => {
                                            handleRecentClick(conv);
                                            setShowAllActivity(false);
                                        }}
                                        className="w-full p-3.5 rounded-2xl bg-transparent hover:bg-black-[0.02] dark:hover:bg-white/[0.02] transition-all text-left flex items-center justify-between group mb-1"
                                    >
                                        <div className="flex items-center gap-3 overflow-hidden">
                                            <div className={`w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center text-white font-bold shadow-sm ${conv.type === 'bulk' ? 'bg-gradient-to-br from-purple-500 to-indigo-600' : 'bg-gradient-to-br from-[#2b83fa] to-[#60a5fa]'}`}>
                                                {conv.type === 'bulk' ? <FiUsers size={18} /> : (() => { const dn = getDisplayName(conv); return dn ? dn.charAt(0).toUpperCase() : <FiUser size={18} />; })()}
                                            </div>
                                            <div className="min-w-0">
                                                <h4 className="font-bold text-[#111111] dark:text-white text-[13.5px] truncate">
                                                    {getDisplayName(conv)}
                                                </h4>
                                                <p className="text-[11.5px] text-gray-500 dark:text-gray-400 truncate max-w-[200px] font-medium">
                                                    {conv.last_message || "No messages yet"}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex flex-col items-end gap-1 flex-shrink-0">
                                            <div className="flex items-center gap-1 text-[10px] text-gray-400 font-bold uppercase tracking-tighter">
                                                <FiClock size={10} />
                                                {conv.last_message_at ? new Date(conv.last_message_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : "--"}
                                            </div>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};
