import { useState, useEffect } from "react";
import { FiCreditCard, FiRefreshCw, FiZap, FiPlus, FiGift } from "react-icons/fi";
import { fetchCreditStatus, type CreditStatus } from "../api/credits";

export const CreditBadge = () => {
    const [status, setStatus] = useState<CreditStatus | null>(null);
    const [loading, setLoading] = useState(false);
    const [showInfo, setShowInfo] = useState(false);

    const navigateToCredits = () => {
        // Dispatch custom event to navigate to settings > credits tab
        window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'credits' } }));
    };

    const refreshStatus = async () => {
        setLoading(true);
        try {
            const data = await fetchCreditStatus();
            setStatus(data);
        } catch (error) {
            console.error("Failed to fetch balance", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        refreshStatus();
        const interval = setInterval(refreshStatus, 5 * 60 * 1000);
        window.addEventListener('sms-sent', refreshStatus);
        window.addEventListener('bulk-message-sent', refreshStatus);
        return () => {
            clearInterval(interval);
            window.removeEventListener('sms-sent', refreshStatus);
            window.removeEventListener('bulk-message-sent', refreshStatus);
        };
    }, []);

    const balance = status?.credit_balance ?? 0;
    const trialUsed = status?.free_usage_count ?? 0;
    const trialTotal = status?.free_credits_total ?? 0;
    const isTrialActive = trialTotal > 0 && trialUsed < trialTotal;

    return (
        <div className="relative group">
            <div
                className={`
                    flex items-center gap-1.5 px-2.5 py-1 sm:py-1.5 transition-all duration-300
                    ${isTrialActive 
                        ? 'bg-gradient-to-br from-green-500/10 to-green-500/5 dark:from-green-500/20 dark:to-green-500/5 border-green-500/20 dark:border-green-500/30'
                        : 'bg-gradient-to-br from-[#2b83fa]/10 to-[#2b83fa]/5 dark:from-[#2b83fa]/20 dark:to-[#2b83fa]/5 border-[#2b83fa]/20 dark:border-[#2b83fa]/30'
                    }
                    border rounded-full cursor-pointer
                    hover:shadow-lg hover:shadow-blue-500/5
                    active:scale-95 select-none
                `}
                onClick={refreshStatus}
                onMouseEnter={() => setShowInfo(true)}
                onMouseLeave={() => setShowInfo(false)}
            >
                <div className={`w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0 ${isTrialActive ? 'bg-green-500/10 dark:bg-green-500/20 text-green-500' : 'bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 text-[#2b83fa]'}`}>
                    {isTrialActive ? <FiGift className="w-2.5 h-2.5" /> : <FiCreditCard className="w-2.5 h-2.5" />}
                </div>

                <div className="flex items-baseline gap-1">
                    <span className={`text-[13px] sm:text-[14px] font-black leading-none ${isTrialActive ? 'text-green-600' : 'text-[#2b83fa]'}`}>
                        {loading ? (
                            <FiRefreshCw className="w-3 h-3 animate-spin" />
                        ) : (
                            isTrialActive ? (trialTotal - trialUsed) : balance.toLocaleString()
                        )}
                    </span>
                    <span className={`text-[9px] sm:text-[10px] font-black uppercase tracking-tighter leading-none ${isTrialActive ? 'text-green-600/50' : 'text-[#2b83fa]/50'}`}>
                        {isTrialActive ? 'Trial' : 'Credits'}
                    </span>
                </div>
                {!isTrialActive && (
                    <button
                        onClick={(e) => { e.stopPropagation(); navigateToCredits(); }}
                        className="ml-1 w-4 h-4 flex items-center justify-center bg-[#2b83fa]/20 dark:bg-[#2b83fa]/30 rounded-full text-[#2b83fa] hover:bg-[#2b83fa] hover:text-white transition-all"
                        title="Buy Credits"
                    >
                        <FiPlus className="w-2.5 h-2.5" />
                    </button>
                )}
            </div>

            {/* Glossy Tooltip */}
            <div className={`
                absolute top-full left-1/2 -translate-x-1/2 mt-3 z-50
                px-4 py-2.5 bg-white/90 dark:bg-[#1a1b1e]/90 backdrop-blur-xl
                border border-gray-200/50 dark:border-white/10 rounded-2xl shadow-2xl
                transition-all duration-300 whitespace-nowrap pointer-events-none
                ${showInfo ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-2'}
            `}>
                <div className="flex flex-col gap-1">
                    {isTrialActive && (
                        <div className="flex items-center gap-2.5 mb-1">
                            <div className="w-6 h-6 rounded-lg bg-green-500/10 flex items-center justify-center text-green-500">
                                <FiGift className="w-3 h-3" />
                            </div>
                            <p className="text-[12px] font-bold text-gray-700 dark:text-[#ececf1]">
                                {trialTotal - trialUsed} Trial Messages left
                            </p>
                        </div>
                    )}
                    <div className="flex items-center gap-2.5">
                        <div className="w-6 h-6 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-500">
                            <FiZap className="w-3 h-3" />
                        </div>
                        <p className="text-[12px] font-bold text-gray-700 dark:text-[#ececf1]">
                            {balance} Paid Credits available
                        </p>
                    </div>
                </div>
                {/* Tiny Arrow */}
                <div className="absolute -top-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-white/90 dark:bg-[#1a1b1e]/90 border-t border-l border-gray-200/50 dark:border-white/10 rotate-45" />
            </div>
        </div>
    );
};


