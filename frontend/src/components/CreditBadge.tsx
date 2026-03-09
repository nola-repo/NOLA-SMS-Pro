import { useState, useEffect } from "react";
import { FiCreditCard, FiRefreshCw, FiZap, FiPlus } from "react-icons/fi";

export const CreditBadge = () => {
    const [balance, setBalance] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [showInfo, setShowInfo] = useState(false);

    const navigateToCredits = () => {
        // Dispatch custom event to navigate to settings > credits tab
        window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'credits' } }));
    };

    const fetchBalance = async () => {
        setLoading(true);
        try {
            const res = await fetch('/api/credits', {
                headers: {
                    'X-Webhook-Secret': 'f7RkQ2pL9zV3tX8cB1nS4yW6',
                    'Content-Type': 'application/json',
                }
            });
            const data = await res.json();
            setBalance(data.balance || data.data?.balance || 0);
        } catch (error) {
            console.error("Failed to fetch balance", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchBalance();
        const interval = setInterval(fetchBalance, 5 * 60 * 1000);
        window.addEventListener('sms-sent', fetchBalance);
        window.addEventListener('bulk-message-sent', fetchBalance);
        return () => {
            clearInterval(interval);
            window.removeEventListener('sms-sent', fetchBalance);
            window.removeEventListener('bulk-message-sent', fetchBalance);
        };
    }, []);

    return (
        <div className="relative group">
            <div
                className={`
                    flex items-center gap-1.5 px-2.5 py-1 sm:py-1.5 transition-all duration-300
                    bg-gradient-to-br from-emerald-500/10 to-emerald-600/5 dark:from-emerald-500/20 dark:to-emerald-500/5
                    border border-emerald-500/20 dark:border-emerald-500/30 rounded-full cursor-pointer
                    hover:border-emerald-500/40 hover:shadow-lg hover:shadow-emerald-500/5
                    active:scale-95 select-none
                `}
                onClick={fetchBalance}
                onMouseEnter={() => setShowInfo(true)}
                onMouseLeave={() => setShowInfo(false)}
            >
                <div className="w-5 h-5 flex items-center justify-center bg-emerald-500/10 dark:bg-emerald-500/20 rounded-full text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                    <FiCreditCard className="w-2.5 h-2.5" />
                </div>

                <div className="flex items-baseline gap-1">
                    <span className="text-[13px] sm:text-[14px] font-black text-emerald-600 dark:text-emerald-400 leading-none">
                        {loading ? (
                            <FiRefreshCw className="w-3 h-3 animate-spin" />
                        ) : (
                            balance?.toLocaleString() ?? "---"
                        )}
                    </span>
                    <span className="text-[9px] sm:text-[10px] font-black text-emerald-600/50 dark:text-emerald-400/50 uppercase tracking-tighter leading-none">Credits</span>
                </div>
                <button
                    onClick={(e) => { e.stopPropagation(); navigateToCredits(); }}
                    className="ml-1 w-4 h-4 flex items-center justify-center bg-emerald-500/20 dark:bg-emerald-500/30 rounded-full text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500 hover:text-white transition-all"
                    title="Buy Credits"
                >
                    <FiPlus className="w-2.5 h-2.5" />
                </button>
            </div>

            {/* Glossy Tooltip */}
            <div className={`
                absolute top-full left-1/2 -translate-x-1/2 mt-3 z-50
                px-4 py-2.5 bg-white/90 dark:bg-[#1a1b1e]/90 backdrop-blur-xl
                border border-gray-200/50 dark:border-white/10 rounded-2xl shadow-2xl
                transition-all duration-300 whitespace-nowrap pointer-events-none
                ${showInfo ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-2'}
            `}>
                <div className="flex items-center gap-2.5">
                    <div className="w-6 h-6 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-500">
                        <FiZap className="w-3 h-3" />
                    </div>
                    <p className="text-[12px] font-bold text-gray-700 dark:text-[#ececf1]">
                        1 credit <span className="mx-1 text-gray-400 font-medium">≈</span> 1 SMS <span className="ml-1 text-[10px] text-gray-400 font-medium">(160 chars)</span>
                    </p>
                </div>
                {/* Tiny Arrow */}
                <div className="absolute -top-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-white/90 dark:bg-[#1a1b1e]/90 border-t border-l border-gray-200/50 dark:border-white/10 rotate-45" />
            </div>
        </div>
    );
};


