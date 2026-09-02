import { useState } from "react";
import { FiCreditCard, FiRefreshCw, FiZap, FiPlus, FiGift } from "react-icons/fi";
import { useRealtimeCreditStatus } from "../hooks/useRealtimeCreditStatus";

interface CreditBadgeProps {
    tone?: "default" | "onBlue";
}

export const CreditBadge = ({ tone = "default" }: CreditBadgeProps) => {
    const [showInfo, setShowInfo] = useState(false);
    const { status, loading, refresh } = useRealtimeCreditStatus();

    const navigateToCredits = () => {
        window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'credits' } }));
    };

    // ── Derived billing state ─────────────────────────────────────────────────
    const balance      = status?.credit_balance ?? 0;
    const trialUsed    = status?.free_usage_count ?? 0;
    const trialTotal   = status?.free_credits_total ?? 0;
    const isTrialActive = trialTotal > 0 && trialUsed < trialTotal;
    const trialLeft    = trialTotal - trialUsed;
    const displayValue = isTrialActive ? trialLeft : balance;

    // ── Theme colours (trial = green, paid = blue) ─────────────────────────
    const accent = isTrialActive
        ? { ring: "#22c55e", bg: "from-[#22c55e]/10 to-[#22c55e]/5 dark:from-[#22c55e]/20 dark:to-[#22c55e]/5", border: "border-[#22c55e]/20 dark:border-[#22c55e]/30 hover:border-[#22c55e]/40", icon: "bg-[#22c55e]/10 dark:bg-[#22c55e]/20 text-[#22c55e]", label: "text-[#22c55e]" }
        : { ring: "#2b83fa", bg: "from-[#2b83fa]/10 to-[#2b83fa]/5 dark:from-[#2b83fa]/20 dark:to-[#2b83fa]/5", border: "border-[#2b83fa]/20 dark:border-[#2b83fa]/30 hover:border-[#2b83fa]/40", icon: "bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 text-[#2b83fa]", label: "text-[#2b83fa]" };
    const isOnBlue = tone === "onBlue";

    return (
        <div className="relative group">
            <div
                className={`
                    flex items-center gap-1.5 px-2.5 py-1 sm:py-1.5 transition-all duration-300
                    border rounded-full cursor-pointer active:scale-95 select-none
                    ${isOnBlue
                        ? "bg-white/[0.18] border-white/30 text-white shadow-sm shadow-blue-950/10 hover:bg-white/[0.24] hover:border-white/45"
                        : `bg-gradient-to-br ${accent.bg} ${accent.border} hover:shadow-lg`}
                `}
                onClick={() => void refresh()}
                onMouseEnter={() => setShowInfo(true)}
                onMouseLeave={() => setShowInfo(false)}
            >
                {/* Icon */}
                <div className={`w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0 ${isOnBlue ? "bg-white/[0.18] text-white" : accent.icon}`}>
                    {isTrialActive
                        ? <FiGift className="w-2.5 h-2.5" />
                        : <FiCreditCard className="w-2.5 h-2.5" />
                    }
                </div>

                {/* Value + label */}
                <div className="flex items-baseline gap-1">
                    <span className={`text-[13px] sm:text-[14px] font-black ${isOnBlue ? "text-white" : accent.label} leading-none`}>
                        {loading
                            ? <FiRefreshCw className="w-3 h-3 animate-spin" />
                            : displayValue.toLocaleString()
                        }
                    </span>
                    <span className={`text-[9px] sm:text-[10px] font-black uppercase tracking-tighter leading-none ${isOnBlue ? "text-white/70" : `${accent.label} opacity-50`}`}>
                        {isTrialActive ? "Trial" : "Credits"}
                    </span>
                </div>

                {/* Buy / top-up button */}
                <button
                    onClick={(e) => { e.stopPropagation(); navigateToCredits(); }}
                    className={`ml-1 w-4 h-4 flex items-center justify-center rounded-full transition-all ${isOnBlue ? "bg-white/[0.18] text-white hover:bg-white/[0.28]" : `${accent.icon} hover:opacity-80`}`}
                    title={isTrialActive ? "View Trial & Credits" : "Buy Credits"}
                >
                    <FiPlus className="w-2.5 h-2.5" />
                </button>
            </div>

            {/* Tooltip */}
            <div className={`
                absolute top-full left-1/2 -translate-x-1/2 mt-3 z-50
                px-4 py-3 bg-white/90 dark:bg-[#1a1b1e]/90 backdrop-blur-xl
                border border-gray-200/50 dark:border-white/10 rounded-2xl shadow-2xl
                transition-all duration-300 whitespace-nowrap pointer-events-none
                ${showInfo ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-2'}
            `}>
                {isTrialActive && (
                    <div className="flex items-center gap-2 mb-2">
                        <div className="w-5 h-5 rounded-md bg-green-500/10 flex items-center justify-center text-green-500 flex-shrink-0">
                            <FiGift className="w-3 h-3" />
                        </div>
                        <p className="text-[12px] font-bold text-gray-700 dark:text-[#ececf1]">
                            <span className="text-green-500">{trialLeft}</span> free trial message{trialLeft !== 1 ? "s" : ""} left
                        </p>
                    </div>
                )}
                <div className="flex items-center gap-2">
                    <div className="w-5 h-5 rounded-md bg-blue-500/10 flex items-center justify-center text-blue-500 flex-shrink-0">
                        <FiZap className="w-3 h-3" />
                    </div>
                    <p className="text-[12px] font-bold text-gray-700 dark:text-[#ececf1]">
                        <span className="text-[#2b83fa]">{balance.toLocaleString()}</span> paid credit{balance !== 1 ? "s" : ""} available
                    </p>
                </div>
                {/* Arrow */}
                <div className="absolute -top-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-white/90 dark:bg-[#1a1b1e]/90 border-t border-l border-gray-200/50 dark:border-white/10 rotate-45" />
            </div>
        </div>
    );
};
