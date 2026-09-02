import React, { useState, useRef } from 'react';
import { FiZap, FiX, FiCheck } from 'react-icons/fi';
import type { CreditPackage } from './types';

export const TopUpModal: React.FC<{
  agencyId: string;
  onClose: () => void;
  onSuccess: () => void;
}> = ({ agencyId, onClose, onSuccess }) => {
  const [topUpAmount, setTopUpAmount] = useState(500);
  const [submitted, setSubmitted] = useState(false);
  const [blockedUrl, setBlockedUrl] = useState<string | null>(null);
  const popupPollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const packages: CreditPackage[] = [
    { credits: 10, price: 10, link: "https://checkout.nolasmspro.com/nola-sms-pro-10-credits" },
    { credits: 500, price: 500, link: "https://checkout.nolasmspro.com/nola-sms-pro-500-credits" },
    { credits: 1100, price: 1000, link: "https://checkout.nolasmspro.com/nola-sms-pro-1100-credits" },
    { credits: 2750, price: 2500, link: "https://checkout.nolasmspro.com/nola-sms-pro-2750-credits" },
    { credits: 6000, price: 5000, link: "https://checkout.nolasmspro.com/nola-sms-pro-6000-credits" },
  ];

  const handleTopUpSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const pkg = packages.find(p => p.credits === topUpAmount);
    if (!pkg) return;

    const separator = pkg.link.includes('?') ? '&' : '?';
    const checkoutUrl = `${pkg.link}${separator}agency_id=${encodeURIComponent(agencyId)}&scope=agency`;

    const width = 600, height = 850;
    const left = (window.screen.width / 2) - (width / 2);
    const top = (window.screen.height / 2) - (height / 2);

    const popup = window.open(
      checkoutUrl, 'AgencyTopUp',
      `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes`
    );

    if (!popup) {
      setBlockedUrl(checkoutUrl);
      setSubmitted(true);
      return;
    }

    setBlockedUrl(null);
    setSubmitted(true);
    if (popupPollRef.current) clearInterval(popupPollRef.current);

    popupPollRef.current = setInterval(() => {
      try {
        if (popup && popup.closed) {
          if (popupPollRef.current) clearInterval(popupPollRef.current);
          setSubmitted(false);
          onSuccess();
          onClose();
        }
      } catch (e) {
        // cross-origin DOM exception logic
      }
    }, 500);
  };

  return (
    <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-[1000]" onClick={onClose}>
      <div className="bg-white dark:bg-[#141618] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] rounded-2xl shadow-2xl p-7 w-full max-w-[480px] mx-4" onClick={e => e.stopPropagation()}>
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-500">
            <FiZap className="w-5 h-5" />
          </div>
          <div>
            <div className="text-[16px] font-bold text-[#111111] dark:text-white">Top Up Agency Balance</div>
            <div className="text-[12px] text-[#6b7280] dark:text-[#9aa0a9]">Select a package and proceed to checkout</div>
          </div>
          <button onClick={onClose} className="ml-auto p-1.5 rounded-lg text-[#9aa0a9] hover:text-[#111111] dark:hover:text-white hover:bg-black/5 dark:hover:bg-white/5">
            <FiX className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleTopUpSubmit} className="space-y-4">
          <div className="grid grid-cols-2 gap-2">
            {packages.map(pkg => (
              <button
                key={pkg.credits}
                type="button"
                onClick={() => setTopUpAmount(pkg.credits)}
                className={`flex flex-col items-center py-3 rounded-xl border-2 transition-all ${topUpAmount === pkg.credits
                  ? 'border-[#2b83fa] bg-[#2b83fa]/5'
                  : 'border-[#e0e0e0] dark:border-[#2a2b32] hover:border-[#2b83fa]/40'
                }`}
              >
                <span className={`text-[16px] font-black ${topUpAmount === pkg.credits ? 'text-[#2b83fa]' : 'text-[#111111] dark:text-white'}`}>
                  {pkg.credits.toLocaleString()}
                </span>
                <span className="text-[11px] text-[#9aa0a6]">credits</span>
                <span className={`text-[12px] font-bold mt-1 ${topUpAmount === pkg.credits ? 'text-[#2b83fa]' : 'text-[#6e6e73]'}`}>
                  ₱{pkg.price}
                </span>
              </button>
            ))}
          </div>
          
          {submitted ? (
             <div className="flex flex-col items-center justify-center gap-2 py-4">
               {blockedUrl ? (
                 <div className="w-full flex flex-col items-center gap-2 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200/50 dark:border-amber-500/20 rounded-xl text-amber-800 dark:text-amber-300 text-[12.5px]">
                   <span className="font-semibold">Popup window was blocked by your browser.</span>
                   <a href={blockedUrl} target="_blank" rel="noopener noreferrer" className="px-4 py-2 bg-amber-600 text-white rounded-lg font-bold hover:bg-amber-700 transition-colors shadow-sm">
                     Click to Open Checkout Page
                   </a>
                 </div>
               ) : (
                 <div className="w-full flex items-center justify-center gap-2 py-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl text-emerald-600 dark:text-emerald-400 font-semibold text-[13px]">
                     <FiCheck className="w-4 h-4" /> Checkout window opened
                 </div>
               )}
               <button type="button" onClick={() => { setSubmitted(false); setBlockedUrl(null); }} className="text-[12px] text-[#9aa0a6] underline decoration-dashed hover:text-[#111111]">
                   Window didn't open or you closed it? Refresh.
               </button>
             </div>
          ) : (
            <div className="flex gap-2.5 pt-2">
              <button type="button" onClick={onClose}
                className="flex-1 px-4 py-2.5 rounded-xl text-[13px] font-semibold bg-[#f0f2f8] dark:bg-[#1c1e21] text-[#6b7280] dark:text-[#9aa0a9] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] hover:bg-black/5 transition-colors">
                Cancel
              </button>
              <button type="submit"
                className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-[13px] font-semibold bg-[#2b83fa] hover:bg-[#1d6bd4] text-white transition-colors shadow-md shadow-blue-500/20">
                <FiZap className="w-4 h-4" /> Buy {topUpAmount.toLocaleString()} Credits
              </button>
            </div>
          )}
        </form>
      </div>
    </div>
  );
};
