import React, { useState, useEffect, useRef } from "react";
import {
  FiZap, FiSend, FiCreditCard, FiUser,
  FiArrowRight, FiArrowLeft, FiX, FiAlertTriangle,
  FiCheck, FiChevronRight, FiInfo,
  FiPhone, FiMessageSquare, FiCalendar,
  FiUsers, FiRepeat, FiLoader
} from "react-icons/fi";
import type { UseOnboardingReturn } from "./useOnboarding";
import { submitSenderRequest } from "../../api/senderRequests";
import { fetchCreditPackages, type CreditPackage } from "../../api/credits";
import { getAccountSettings } from "../../utils/settingsStorage";
import { useGhlLocation } from "../../hooks/useGhlLocation";

interface OnboardingModalProps {
  onboarding: UseOnboardingReturn;
}

type CtaAction = "next" | "settings-sender" | "settings-credits" | "complete";

interface Step {
  id: number;
  icon: React.ReactNode;
  title: string;
  tags: { label: string; variant: "default" | "required" | "complete" }[];
  subtitle: string;
  content: React.ReactNode;
  cta?: { label: string; action: CtaAction };
}

// ── Primitives ───────────────────────────────────────────────────────────────

const Row: React.FC<{
  icon: React.ReactNode;
  title: string;
  body?: string;
  accent?: boolean;
}> = ({ icon, title, body, accent }) => (
  <div className="group flex items-start gap-4 py-3.5 border-b border-black/[0.04] dark:border-white/[0.04] last:border-0 hover:-translate-y-[1px] transition-transform duration-300">
    <div className={`mt-0.5 w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 text-[16px] transition-all duration-300 shadow-sm ${
      accent
        ? "bg-[linear-gradient(135deg,#1d6bd4_0%,#2b83fa_50%,#1d6bd4_100%)] bg-[length:210%_210%] text-white shadow-[#2b83fa]/30"
        : "bg-black/[0.03] dark:bg-white/[0.05] text-[#6b7280] dark:text-[#9aa0a6] group-hover:bg-black/[0.06] dark:group-hover:bg-white/[0.1] group-hover:text-blue-500 dark:group-hover:text-blue-400"
    }`}>
      {icon}
    </div>
    <div className="flex-1 min-w-0 pt-0.5">
      <p className="text-[13.5px] font-bold text-[#111111] dark:text-white leading-snug tracking-tight">{title}</p>
      {body && <p className="text-[12px] text-[#6b7280] dark:text-[#9aa0a6] mt-0.5 leading-relaxed font-medium transition-colors group-hover:text-[#4b5563] dark:group-hover:text-[#d1d5db]">{body}</p>}
    </div>
  </div>
);

const Note: React.FC<{ children: React.ReactNode; variant?: "info" | "warn" }> = ({
  children,
  variant = "info",
}) => (
  <div className={`flex items-start gap-3 px-4 py-3.5 rounded-2xl text-[12px] leading-relaxed font-semibold transition-all shadow-sm ${
    variant === "warn"
      ? "bg-gradient-to-br from-amber-50 to-orange-50 dark:from-amber-900/10 dark:to-orange-900/10 text-amber-700 dark:text-amber-400 border border-amber-200/50 dark:border-amber-800/20"
      : "bg-gradient-to-br from-[#f4f6fa] to-[#eef1f6] dark:from-white/[0.03] dark:to-white/[0.01] text-[#4b5563] dark:text-[#9aa0a6] border border-black/[0.03] dark:border-white/[0.03]"
  }`}>
    {variant === "warn"
      ? <FiAlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0 animate-pulse text-amber-500" />
      : <FiInfo className="w-4 h-4 mt-0.5 flex-shrink-0 opacity-70 text-blue-500" />
    }
    <span>{children}</span>
  </div>
);

// ── Step content ─────────────────────────────────────────────────────────────

const Step1 = () => (
  <div className="space-y-5">
    {/* Credit highlight */}
    <div className="relative overflow-hidden flex items-center gap-4 px-5 py-4 rounded-2xl bg-[linear-gradient(135deg,#1d6bd4_0%,#2b83fa_50%,#1d6bd4_100%)] bg-[length:210%_210%] text-white shadow-[0_10px_30px_rgba(43,131,250,0.3)]">
      <div className="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -mr-10 -mt-10 pointer-events-none" />
      <div className="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0 backdrop-blur-md border border-white/20 shadow-inner">
        <FiCreditCard className="w-6 h-6 text-white drop-shadow-md" />
      </div>
      <div className="relative z-10">
        <p className="text-[24px] font-black leading-none drop-shadow-sm tracking-tight text-white">10 free credits</p>
        <p className="text-[12px] text-blue-100 mt-1 font-semibold tracking-wide uppercase">included on signup · for testing</p>
      </div>
    </div>

    <div className="divide-y divide-black/[0.04] dark:divide-white/[0.04] px-1">
      <Row icon={<FiMessageSquare className="w-4 h-4" />} title="Send SMS via the web app" body="Compose messages directly from this dashboard" />
      <Row icon={<FiZap className="w-4 h-4" />} title="Trigger SMS via GHL workflows" body="Automate messages in your GoHighLevel sequences" />
      <Row icon={<FiRepeat className="w-4 h-4" />} title="Track delivery & credit usage" body="Real-time reporting on all outbound messages" />
    </div>
  </div>
);

const Step2 = () => {
  const path = [
    "Settings",
    "Phone System",
    "Additional Settings",
    "Telephony Provider",
  ];
  return (
    <div className="space-y-5">
      {/* Path indicator */}
      <div className="p-4 rounded-2xl bg-gradient-to-br from-[#f4f6fa] to-[#eef1f6] dark:from-white/[0.03] dark:to-white/[0.01] border border-black/[0.03] dark:border-white/[0.03]">
        <p className="text-[11px] font-black uppercase tracking-widest text-[#9aa0a6] mb-3">Menu Path</p>
        <div className="flex items-center gap-1.5 flex-wrap">
          {path.map((label, i) => (
            <React.Fragment key={i}>
              <span className={`px-3 py-1.5 rounded-lg text-[12.5px] font-bold shadow-sm transition-colors flex items-center gap-1 ${
                i === path.length - 1
                  ? "bg-[linear-gradient(135deg,#1d6bd4_0%,#2b83fa_50%,#1d6bd4_100%)] bg-[length:210%_210%] text-white shadow-blue-500/20"
                  : "bg-white dark:bg-black/40 border border-black/5 dark:border-white/5 text-[#37352f] dark:text-[#d1d5db]"
              }`}>
                {label}
              </span>
              {i < path.length - 1 && (
                <FiChevronRight className="w-3.5 h-3.5 text-[#9aa0a6] flex-shrink-0 opacity-50" />
              )}
            </React.Fragment>
          ))}
        </div>
      </div>

      {/* Final action */}
      <div className="flex items-center gap-4 px-5 py-4 rounded-2xl border border-black/[0.05] dark:border-white/[0.05] bg-gradient-to-b from-white to-gray-50 dark:from-white/[0.03] dark:to-transparent shadow-sm">
        <div className="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center flex-shrink-0 text-[#2b83fa] border border-[#2b83fa]/20">
          <FiCheck className="w-5 h-5 drop-shadow-sm" />
        </div>
        <div>
          <p className="text-[14px] font-extrabold text-[#111111] dark:text-white tracking-tight">Choose NOLA SMS Pro</p>
          <p className="text-[12px] font-medium text-[#9aa0a6] mt-0.5">Select and confirm it is your active default provider</p>
        </div>
      </div>

      <Note variant="warn">
        This setting is only visible to <strong>Agency Owners and Admins.</strong> Contact your agency owner if you don't have access.
      </Note>
    </div>
  );
};

const Step3 = () => (
  <div className="space-y-5">
    {/* Sender ID banner */}
    <div className="relative overflow-hidden flex flex-col justify-center px-6 py-5 rounded-2xl bg-[linear-gradient(135deg,#1d6bd4_0%,#2b83fa_50%,#1d6bd4_100%)] bg-[length:210%_210%] text-white shadow-[0_10px_30px_rgba(43,131,250,0.3)]">
      <div className="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -mr-10 -mt-10 pointer-events-none" />
      <p className="relative z-10 text-[11px] font-black uppercase tracking-[0.2em] text-blue-200 mb-2 opacity-90 drop-shadow-sm">Your default Sender ID</p>
      <div className="relative z-10 flex items-center justify-between">
          <code className="text-[28px] font-black tracking-tight font-mono drop-shadow-md text-white">NOLASMSPro</code>
          <div className="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0 backdrop-blur-md border border-white/20 shadow-inner">
            <FiCheck className="w-5 h-5 text-white drop-shadow-md" />
          </div>
      </div>
      <p className="relative z-10 text-[12.5px] text-blue-100 font-semibold mt-3 tracking-wide">Active automatically — no configuration needed</p>
    </div>

    <div className="divide-y divide-black/[0.04] dark:divide-white/[0.04]">
      <Row icon={<FiSend className="w-4 h-4" />} title="Works in the web app" body="Compose & send from the dashboard right now" />
      <Row icon={<FiZap className="w-4 h-4" />} title="Works in GHL automations" body="Use in any SMS action inside GoHighLevel" />
    </div>

    <Note variant="warn">
      The default ID is for <strong>testing only.</strong> Register a custom Sender ID for production use.
    </Note>
  </div>
);

const Step4 = () => {
  const [newId, setNewId] = useState("");
  const [newPurpose, setNewPurpose] = useState("");
  const [newSample, setNewSample] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmedId = newId.trim();
    if (!trimmedId || isSubmitting) return;

    if (trimmedId.length < 3 || trimmedId.length > 11) {
      setError("Sender name must be 3-11 characters.");
      return;
    }
    if (!/^[a-zA-Z0-9]+$/.test(trimmedId)) {
      setError("Letters and numbers only.");
      return;
    }
    setIsSubmitting(true);
    setError(null);
    try {
      await submitSenderRequest(trimmedId, newPurpose.trim(), newSample.trim());
      setIsSubmitted(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to submit request.");
    } finally {
      setIsSubmitting(false);
    }
  };

  if (isSubmitted) {
    return (
      <div className="flex flex-col items-center justify-center py-10 text-center animate-in fade-in zoom-in-95 duration-500">
        <div className="relative mb-5">
           <div className="absolute inset-0 bg-emerald-500/20 rounded-full blur-xl animate-pulse" />
           <div className="relative w-16 h-16 rounded-full bg-[linear-gradient(135deg,#10b981_0%,#34d399_50%,#10b981_100%)] flex items-center justify-center text-white shadow-lg border border-emerald-400">
             <FiCheck className="w-8 h-8 drop-shadow-md" />
           </div>
        </div>
        <h4 className="text-[19px] font-black text-[#111111] dark:text-white mb-2 tracking-tight">Registration Submitted</h4>
        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] max-w-[85%] leading-relaxed font-medium">
          Your sender name is now pending approval. It typically takes 5–7 business days. You can continue with the onboarding in the meantime!
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <Note variant="warn">
        Sender ID registration is <strong>required</strong> before going live. Submit below to begin approval.
      </Note>
      <form onSubmit={handleSubmit} className="space-y-3.5">
        {error && (
          <div className="p-3 rounded-xl bg-red-50 border border-red-100 dark:bg-red-900/10 dark:border-red-900/30">
            <p className="text-[12px] text-red-600 dark:text-red-400 font-bold">{error}</p>
          </div>
        )}

        <div className="group">
          <label className="block text-[11px] font-black text-[#9aa0a6] uppercase tracking-widest mb-1.5 ml-1 transition-colors group-focus-within:text-[#2b83fa]">Sender Name</label>
          <input
            value={newId}
            onChange={e => setNewId(e.target.value.replace(/[^a-zA-Z0-9]/g, ''))}
            placeholder="ex. NOLASMSPro"
            maxLength={11}
            required
            disabled={isSubmitting}
            className="w-full px-4 py-3 rounded-xl text-[14px] font-black tracking-tight border bg-[#fcfcfd] dark:bg-black/20 border-black/[0.06] dark:border-white/[0.08] text-[#111111] dark:text-white placeholder-gray-400 focus:outline-none focus:border-[#2b83fa] focus:ring-4 focus:ring-[#2b83fa]/10 transition-all shadow-sm"
          />
        </div>
        <div className="group">
          <label className="block text-[11px] font-black text-[#9aa0a6] uppercase tracking-widest mb-1.5 ml-1 transition-colors group-focus-within:text-[#2b83fa]">Business Purpose</label>
          <textarea
            value={newPurpose}
            onChange={e => setNewPurpose(e.target.value)}
            placeholder="What will you be using this for?"
            required rows={2} disabled={isSubmitting}
            className="w-full px-4 py-3 rounded-xl text-[13px] font-medium border bg-[#fcfcfd] dark:bg-black/20 border-black/[0.06] dark:border-white/[0.08] text-[#111111] dark:text-white placeholder-gray-400 focus:outline-none focus:border-[#2b83fa] focus:ring-4 focus:ring-[#2b83fa]/10 transition-all shadow-sm resize-none"
          />
        </div>
        <div className="group">
          <label className="block text-[11px] font-black text-[#9aa0a6] uppercase tracking-widest mb-1.5 ml-1 transition-colors group-focus-within:text-[#2b83fa]">Sample Message</label>
          <textarea
            value={newSample}
            onChange={e => setNewSample(e.target.value)}
            placeholder="Provide a specific message template example."
            required rows={2} disabled={isSubmitting}
            className="w-full px-4 py-3 rounded-xl text-[13px] font-medium border bg-[#fcfcfd] dark:bg-black/20 border-black/[0.06] dark:border-white/[0.08] text-[#111111] dark:text-white placeholder-gray-400 focus:outline-none focus:border-[#2b83fa] focus:ring-4 focus:ring-[#2b83fa]/10 transition-all shadow-sm resize-none"
          />
        </div>

        <button type="submit" disabled={isSubmitting} className="w-full mt-4 flex items-center justify-center gap-2 py-3.5 btn-new-message text-white rounded-xl font-bold text-[14px]">
          {isSubmitting ? <FiLoader className="w-5 h-5 animate-spin relative z-10" /> : <span className="relative z-10 block tracking-wide">Submit Request</span>}
        </button>
      </form>
    </div>
  );
};

const Step5 = () => {
  const rows = [
    { range: "1–160 characters", credits: "1 credit", highlight: true },
    { range: "161–306 characters", credits: "2 credits", highlight: false },
    { range: "307–459 characters", credits: "3 credits", highlight: false },
  ];
  return (
    <div className="space-y-5">
      {/* Formula */}
      <div className="flex items-center justify-between px-6 py-5 rounded-2xl bg-gradient-to-br from-[#f4f6fa] to-[#eef1f6] dark:from-white/[0.04] dark:to-transparent border border-black/[0.04] dark:border-white/[0.05] shadow-inner">
        <div className="flex flex-col sm:flex-row items-center sm:gap-2 text-[#2b83fa] dark:text-blue-400 text-center sm:text-left">
             <div className="flex items-center gap-1.5"><FiZap className="w-5 h-5" /><span className="text-[15px] font-black whitespace-nowrap">1 Credit</span></div>
        </div>
        <span className="text-[#9aa0a6] text-lg font-black opacity-40 mx-2">=</span>
        <div className="flex flex-col sm:flex-row items-center sm:gap-2 text-[#111] dark:text-white text-center sm:text-left">
             <div className="flex items-center gap-1.5"><FiMessageSquare className="w-5 h-5 opacity-70" /><span className="text-[15px] font-black whitespace-nowrap">1 Segment</span></div>
        </div>
        <span className="text-[#9aa0a6] text-lg font-black opacity-40 mx-2">=</span>
        <div className="flex flex-col items-center">
             <span className="text-[15px] font-black text-[#111] dark:text-white whitespace-nowrap">160 chars</span>
             <span className="text-[9px] font-bold text-[#9aa0a6] uppercase tracking-widest -mt-0.5">max</span>
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl border border-black/[0.06] dark:border-white/[0.08] overflow-hidden shadow-sm">
        {rows.map((row, i) => (
          <div key={i} className={`flex items-center justify-between px-5 py-3.5 border-b border-black/[0.04] dark:border-white/[0.04] last:border-0 ${
            row.highlight ? "bg-blue-50/50 dark:bg-[#2b83fa]/10" : "bg-white dark:bg-black/20"
          }`}>
            <span className="text-[13px] text-[#4b5563] dark:text-[#9aa0a6] font-semibold">{row.range}</span>
            <span className={`text-[13.5px] font-black tabular-nums ${row.highlight ? "text-[#2b83fa] dark:text-blue-400" : "text-[#111111] dark:text-[#ececf1]"}`}>
              {row.credits}
            </span>
          </div>
        ))}
      </div>

      <Note>
        The message composer shows a live <strong>character count and credit estimator</strong> as you type.
      </Note>
    </div>
  );
};

const Step6 = () => {
  const ghlLocationIdFromHook = useGhlLocation();
  const [packages, setPackages] = useState<CreditPackage[]>([]);
  const [topUpAmount, setTopUpAmount] = useState<number>(0);
  const [submitted, setSubmitted] = useState(false);
  const popupRef = useRef<Window | null>(null);
  const popupPollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    fetchCreditPackages().then(pkgs => {
      setPackages(pkgs);
      if (pkgs.length > 0) {
        setTopUpAmount(pkgs[1]?.credits || pkgs[0].credits);
      }
    });

    const handlePaymentMessage = (event: MessageEvent) => {
      if (
        (event.origin === 'https://sms.nolawebsolutions.com' || event.origin === 'https://checkout.nolasmspro.com' || event.origin === window.location.origin) &&
        event.data?.type === 'nola-payment-success'
      ) {
        if (popupPollRef.current) clearInterval(popupPollRef.current);
        setSubmitted(false);
        if (popupRef.current) popupRef.current.close();
      }
    };
    window.addEventListener('message', handlePaymentMessage);

    return () => {
      window.removeEventListener('message', handlePaymentMessage);
      if (popupPollRef.current) clearInterval(popupPollRef.current);
    };
  }, []);

  const handleTopUp = (e: React.FormEvent) => {
      e.preventDefault();
      const selectedPackage = packages.find(p => p.credits === topUpAmount);
      if (!selectedPackage) return;

      const locationId = ghlLocationIdFromHook || getAccountSettings().ghlLocationId;
      const baseUrl = selectedPackage.link;
      const separator = baseUrl.includes('?') ? '&' : '?';

      // Resolve location_id: prefer GHL hook/settings, fall back to nola_user localStorage
      let resolvedLocationId = locationId;
      try {
          const storedUser = JSON.parse(localStorage.getItem('nola_user') || 'null');
          if (!resolvedLocationId && storedUser?.location_id) {
              resolvedLocationId = storedUser.location_id;
          }
      } catch { /* ignore */ }

      let checkoutUrl = resolvedLocationId
          ? `${baseUrl}${separator}location_id=${encodeURIComponent(resolvedLocationId)}`
          : baseUrl;

      try {
          const stored = localStorage.getItem('nola_user');
          if (stored) {
              const profile = JSON.parse(stored) as {
                firstName?: string;
                lastName?: string;
                email?: string;
                phone?: string;
              };
              const p = new URLSearchParams();
              const fullName = [profile.firstName, profile.lastName].filter(Boolean).join(' ');
              if (fullName) {
                  p.set('name', fullName);
                  p.set('full_name', fullName);
              }
              if (profile.firstName) p.set('first_name', profile.firstName);
              if (profile.lastName)  p.set('last_name',  profile.lastName);
              if (profile.email)     p.set('email',      profile.email);
              // Phone is already stored clean (spaces stripped at login/register)
              if (profile.phone) p.set('phone', profile.phone);
              const qs = p.toString();
              if (qs) checkoutUrl += (checkoutUrl.includes('?') ? '&' : '?') + qs;
          }
      } catch { /* ignore parsing errors */ }

      const width = 600;
      const height = 850;
      const left = (window.screen.width / 2) - (width / 2);
      const top = (window.screen.height / 2) - (height / 2);

      const popup = window.open(
          checkoutUrl,
          "Checkout",
          `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes`
      );
      popupRef.current = popup;

      if (!popup) {
          alert("Checkout window blocked! Please allow popups for this site or use a different browser.");
          return;
      }

      setSubmitted(true);

      if (popupPollRef.current) clearInterval(popupPollRef.current);
      popupPollRef.current = setInterval(() => {
          try {
              if (popup && popup.closed) {
                  if (popupPollRef.current) clearInterval(popupPollRef.current);
                  popupPollRef.current = null;
                  setSubmitted(false);
              }
          } catch {
              // Ignore cross-origin errors
          }
      }, 500);
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-col items-center justify-center p-4 rounded-2xl bg-gradient-to-br from-indigo-50 to-blue-50 dark:from-[rgba(43,131,250,0.1)] dark:to-[rgba(43,131,250,0.05)] border border-indigo-100 dark:border-[#2b83fa]/20 text-center">
        <FiZap className="w-5 h-5 mb-2 text-[#2b83fa] drop-shadow-sm" />
        <p className="text-[12.5px] text-[#2b83fa]/90 dark:text-blue-200/80 font-bold leading-relaxed tracking-wide px-2">
          Credits never expire and are added instantly. You can buy now or proceed with the free 10 Credits to test!
        </p>
      </div>

      {packages.length === 0 ? (
        <div className="flex justify-center items-center py-6">
          <FiLoader className="w-6 h-6 animate-spin text-[#2b83fa]" />
        </div>
      ) : (
        <form onSubmit={handleTopUp} className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
              {packages.map(pkg => (
                  <button
                      key={pkg.credits}
                      type="button"
                      onClick={() => setTopUpAmount(pkg.credits)}
                      className={`relative flex flex-col items-center py-4 rounded-2xl border-2 transition-all duration-300 ${topUpAmount === pkg.credits
                          ? 'border-[#2b83fa] bg-[#2b83fa]/5 dark:bg-[#2b83fa]/10 shadow-[0_4px_15px_rgba(43,131,250,0.15)] -translate-y-[2px]'
                          : 'border-black/[0.06] dark:border-white/[0.08] hover:border-[#2b83fa]/40 bg-white dark:bg-black/20 hover:bg-gray-50 dark:hover:bg-white/[0.02]'
                          }`}
                  >
                      {topUpAmount === pkg.credits && <div className="absolute top-2 right-2 w-2 h-2 rounded-full bg-[#2b83fa] shadow-[0_0_8px_rgba(43,131,250,1)]" />}
                      <span className={`text-[19px] font-black tracking-tight drop-shadow-sm ${topUpAmount === pkg.credits ? 'text-[#2b83fa] dark:text-blue-400' : 'text-[#111111] dark:text-white'}`}>{pkg.credits.toLocaleString()}</span>
                      <span className="text-[10px] text-[#9aa0a6] uppercase tracking-widest font-black mt-0.5">credits</span>
                      <span className={`text-[13px] font-bold mt-1.5 py-0.5 px-2.5 rounded-full ${topUpAmount === pkg.credits ? 'bg-blue-100 dark:bg-[#2b83fa]/40 text-[#2b83fa] dark:text-blue-300' : 'bg-gray-100 dark:bg-white/10 text-[#6e6e73] dark:text-[#d1d5db]'}`}>₱{pkg.price}</span>
                  </button>
              ))}
          </div>
          
          {submitted ? (
              <div className="w-full flex items-center justify-center gap-2 py-3.5 bg-emerald-500/10 rounded-xl text-emerald-600 dark:text-emerald-400 font-bold text-[14px] border border-emerald-500/20 backdrop-blur-sm">
                  <FiCheck className="w-5 h-5 drop-shadow-sm" /> Checkout window opened
              </div>
          ) : (
              <button type="submit" className="w-full flex items-center justify-center gap-2 py-3.5 btn-new-message text-white rounded-xl font-bold text-[14px]">
                   <FiCreditCard className="w-4 h-4 relative z-10" /> 
                   <span className="relative z-10 tracking-wide">Buy {topUpAmount.toLocaleString()} Credits</span>
              </button>
          )}
        </form>
      )}
    </div>
  );
};

const Step7 = () => (
  <div className="space-y-5">
    
    <div className="relative overflow-hidden flex items-center gap-4 px-5 py-5 rounded-2xl bg-[linear-gradient(135deg,#6366f1_0%,#a855f7_50%,#ec4899_100%)] bg-[length:210%_210%] text-white shadow-[0_10px_30px_rgba(168,85,247,0.3)]">
      <div className="absolute top-0 right-0 w-32 h-32 bg-white/20 rounded-full blur-2xl -mr-10 -mt-10 pointer-events-none" />
      <div className="w-12 h-12 rounded-xl bg-white/30 flex items-center justify-center flex-shrink-0 backdrop-blur-md border border-white/20 shadow-inner">
        <FiZap className="w-6 h-6 text-white drop-shadow-md" />
      </div>
      <div className="relative z-10">
        <p className="text-[17px] font-black leading-tight drop-shadow-sm tracking-tight text-white mb-0.5">Full Automation Included</p>
        <p className="text-[12px] text-white/90 font-medium tracking-wide">Connect GHL workflows directly to SMS actions.</p>
      </div>
    </div>

    <div className="divide-y divide-black/[0.04] dark:divide-white/[0.04] px-1">
      <Row icon={<FiZap className="w-4 h-4" />} title="Trigger Anywhere" body="Add SMS actions to any automation step" />
      <Row icon={<FiCalendar className="w-4 h-4" />} title="Appointment Reminders" body="Send automated alerts before appointments" />
      <Row icon={<FiUsers className="w-4 h-4" />} title="Bulk Announcements" body="Message your entire contact list instantly" />
      <Row icon={<FiMessageSquare className="w-4 h-4" />} title="Follow-Up Sequences" body="Nurture leads with timed message sequences" />
    </div>
  </div>
);

// ── Step definitions ─────────────────────────────────────────────────────────
function buildSteps(): Step[] {
  return [
    {
      id: 0,
      icon: <FiCheck className="w-5 h-5" />,
      title: "Installation Complete",
      tags: [{ label: "Step 1", variant: "default" }],
      subtitle: "Your account is active. You have 10 free credits to get started.",
      content: <Step1 />,
    },
    {
      id: 1,
      icon: <FiPhone className="w-5 h-5" />,
      title: "Enable Telephony Provider",
      tags: [{ label: "Step 2", variant: "default" }],
      subtitle: "Connect NOLA SMS Pro in your GoHighLevel settings.",
      content: <Step2 />,
    },
    {
      id: 2,
      icon: <FiSend className="w-5 h-5" />,
      title: "Start Sending",
      tags: [{ label: "Step 3", variant: "default" }],
      subtitle: "Send immediately using the default Sender ID — no setup needed.",
      content: <Step3 />,
    },
    {
      id: 3,
      icon: <FiUser className="w-5 h-5" />,
      title: "Register Your Sender ID",
      tags: [{ label: "Step 4", variant: "default" }, { label: "Required", variant: "required" }],
      subtitle: "A custom Sender ID is required for production. Submit the form to begin approval.",
      content: <Step4 />,
    },
    {
      id: 4,
      icon: <FiInfo className="w-5 h-5" />,
      title: "Credit Usage",
      tags: [{ label: "Step 5", variant: "default" }],
      subtitle: "1 credit = 1 SMS segment = up to 160 characters.",
      content: <Step5 />,
    },
    {
      id: 5,
      icon: <FiCreditCard className="w-5 h-5" />,
      title: "Top Up Credits",
      tags: [{ label: "Step 6", variant: "default" }],
      subtitle: "Pay as you go — no subscription, credits never expire.",
      content: <Step6 />,
    },
    {
      id: 6,
      icon: <FiZap className="w-5 h-5" />,
      title: "Use in Automation",
      tags: [{ label: "Step 7", variant: "default" }, { label: "Final Step", variant: "complete" }],
      subtitle: "Integrate SMS into workflows, reminders, and campaigns.",
      content: <Step7 />,
      cta: { label: "Get started", action: "complete" },
    },
  ];
}

// ── Tag component ────────────────────────────────────────────────────────────
const StepTag: React.FC<{ label: string; variant: "default" | "required" | "complete" }> = ({ label, variant }) => {
  const styles = {
    default:   "bg-gradient-to-r from-gray-100 to-gray-50 dark:from-white/10 dark:to-white/5 text-[#6b7280] dark:text-[#a1a1aa] border border-black/5 dark:border-white/5",
    required:  "bg-gradient-to-r from-amber-500 to-orange-500 text-white border border-amber-600/50 shadow-[0_2px_10px_rgba(245,158,11,0.2)]",
    complete:  "bg-gradient-to-r from-emerald-500 to-teal-500 text-white border border-emerald-600/50 shadow-[0_2px_10px_rgba(16,185,129,0.2)]",
  };
  return (
    <span className={`inline-flex px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-[0.15em] ${styles[variant]} backdrop-blur-md`}>
      {label}
    </span>
  );
};

// ── Main Modal ───────────────────────────────────────────────────────────────
export const OnboardingModal: React.FC<OnboardingModalProps> = ({ onboarding }) => {
  const { isOpen, currentStep, totalSteps, next, back, close, complete, goToStep } = onboarding;
  const [animDir, setAnimDir] = useState<"forward" | "back">("forward");
  const [animating, setAnimating] = useState(false);
  const [visibleStep, setVisibleStep] = useState(currentStep);
  const [isCelebrating, setIsCelebrating] = useState(false);
  const prevStepRef = useRef(currentStep);
  const STEPS = buildSteps();
  const step = STEPS[visibleStep];

  const handleFinish = () => {
    setIsCelebrating(true);
  };

  const handleComplete = () => {
    setIsCelebrating(false);
    complete();
  };

  useEffect(() => {
    if (currentStep === prevStepRef.current) return;
    const start = window.requestAnimationFrame(() => {
      setAnimDir(currentStep > prevStepRef.current ? "forward" : "back");
      setAnimating(true);
    });
    const finish = setTimeout(() => {
      setVisibleStep(currentStep);
      setAnimating(false);
      prevStepRef.current = currentStep;
    }, 280);
    return () => {
      window.cancelAnimationFrame(start);
      clearTimeout(finish);
    };
  }, [currentStep]);

  const handleCta = (action: CtaAction) => {
    if (action === "complete") {
      complete();
    } else if (action === "settings-sender") {
      close();
      setTimeout(() => {
        window.dispatchEvent(new CustomEvent("navigate-to-settings", {
          detail: { tab: "senderIds", autoOpenAdd: true },
        }));
      }, 100);
    } else if (action === "settings-credits") {
      close();
      setTimeout(() => {
        window.dispatchEvent(new CustomEvent("navigate-to-settings", {
          detail: { tab: "credits" },
        }));
      }, 100);
    } else {
      next();
    }
  };

  if (!isOpen) {
    return null;
  }

  const isFirst = visibleStep === 0;
  const isLast = visibleStep === totalSteps - 1;

  if (isCelebrating) {
    return (
      <div className="fixed inset-0 z-[999] flex items-center justify-center p-4">
        <div className="absolute inset-0 bg-black/60 backdrop-blur-md transition-opacity" onClick={handleComplete} />
        <div className="relative w-full max-w-[360px] bg-white/90 dark:bg-[#0a0a0b]/90 border border-white/20 dark:border-white/10 rounded-[32px] shadow-[0_30px_100px_rgba(0,0,0,0.4)] p-10 flex flex-col items-center text-center animate-in zoom-in-95 fade-in duration-500 overflow-hidden">
          
          <div className="relative mb-6">
            <div className="absolute inset-0 bg-[#2b83fa]/30 rounded-full blur-2xl animate-pulse" />
            <div className="w-20 h-20 rounded-full bg-[linear-gradient(135deg,#1d6bd4_0%,#2b83fa_50%,#1d6bd4_100%)] bg-[length:210%_210%] flex items-center justify-center shadow-xl border border-white/20 relative z-10 transition-transform hover:scale-105 duration-500">
              <FiCheck className="w-10 h-10 text-white drop-shadow-md" />
            </div>
          </div>
          
          <h2 className="text-[24px] font-black text-[#111111] dark:text-white tracking-tight mb-3 relative z-10">You're all set!</h2>
          <p className="text-[13.5px] text-[#6b7280] dark:text-[#9aa0a6] leading-relaxed mb-8 font-medium relative z-10 px-2">
            NOLA SMS Pro is configured and ready to supercharge your daily workflow.
          </p>
          <button
            onClick={handleComplete}
            className="w-full flex items-center justify-center py-4 text-white rounded-2xl font-black text-[15px] z-10 btn-new-message text-center cursor-pointer"
          >
            <span className="relative z-10 tracking-wide block w-full">Go to Dashboard</span>
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 z-[999] flex items-center justify-center p-4">
      <div
        className="absolute inset-0 bg-black/60 dark:bg-black/80 backdrop-blur-sm transition-opacity duration-300"
        onClick={close}
      />

      <div className="relative flex h-[min(760px,calc(100dvh-2rem))] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white shadow-2xl animate-in zoom-in-[0.98] fade-in duration-300 dark:border-white/10 dark:bg-[#111213]">
        
        {/* Subtle Ambient Glow inside Modal */}
        <div className="absolute top-0 right-0 w-[300px] h-[300px] bg-[#2b83fa]/10 dark:bg-[#2b83fa]/5 rounded-full blur-3xl -mr-[100px] -mt-[100px] pointer-events-none" />

        {/* ── Header ── */}
        <div className="relative z-10 px-6 pt-6 pb-5 flex-shrink-0 sm:px-8">
          <div className="flex items-start justify-between gap-4 mb-5">
            <div className="flex items-center gap-1.5 flex-wrap">
              {step.tags.map((tagObj, idx) => (
                <StepTag key={idx} label={tagObj.label} variant={tagObj.variant} />
              ))}
            </div>
            
            <button
              onClick={close}
              className="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center bg-black/5 dark:bg-white/5 text-[#6b7280] dark:text-[#a1a1aa] hover:text-[#111] dark:hover:text-white hover:bg-black/10 dark:hover:bg-white/10 transition-all active:scale-95 cursor-pointer"
              aria-label="Close"
            >
              <FiX className="w-4 h-4" />
            </button>
          </div>

          <div className="flex items-center gap-4 pr-10">
            <div className="relative w-14 h-14 flex-shrink-0">
               <div className="absolute inset-0 bg-[#2b83fa]/30 rounded-2xl blur-md" />
               <div className="relative w-full h-full rounded-2xl bg-[linear-gradient(135deg,#1d6bd4_0%,#2b83fa_50%,#1d6bd4_100%)] bg-[length:210%_210%] flex items-center justify-center text-white shadow-lg border border-white/20">
                 <div className="drop-shadow-sm">{step.icon}</div>
               </div>
            </div>
            <div className="min-w-0">
              <h2 className="text-[22px] sm:text-[26px] font-black text-[#111111] dark:text-white tracking-tight leading-tight">
                {step.title}
              </h2>
              <p className="text-[13px] sm:text-[14px] text-[#6b7280] dark:text-[#a1a1aa] mt-1 leading-snug font-semibold pr-2">
                {step.subtitle}
              </p>
            </div>
          </div>
        </div>

        {/* ── Segmented Progress ── */}
        <div className="relative z-10 px-6 pb-5 flex-shrink-0 sm:px-8">
          <div className="flex items-center gap-2 mb-2">
            {STEPS.map((_, i) => (
              <button
                key={i}
                onClick={() => goToStep(i)}
                className={`relative h-1.5 rounded-full transition-all duration-500 cursor-pointer overflow-hidden ${
                  i < currentStep
                    ? "bg-[#2b83fa]/40 flex-1 hover:bg-[#2b83fa]/60"
                    : i === currentStep
                    ? "bg-[#2b83fa] flex-[3] shadow-[0_0_10px_rgba(43,131,250,0.5)]"
                    : "bg-black/[0.06] dark:bg-white/[0.06] flex-1 hover:bg-black/10 dark:hover:bg-white/10"
                }`}
              >
                 {i <= currentStep && (
                   <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent w-[200%] animate-[shimmer_2s_infinite]" />
                 )}
              </button>
            ))}
          </div>
          <div className="flex justify-between items-center px-0.5">
             <p className="text-[10px] font-bold uppercase tracking-widest text-[#9aa0a6] tabular-nums">Step {currentStep + 1} of {totalSteps}</p>
             <p className="text-[10px] font-bold uppercase tracking-widest text-[#2b83fa] tabular-nums animate-pulse">{Math.round(((currentStep + 1) / totalSteps) * 100)}%</p>
          </div>
        </div>

        <div className="h-px bg-gradient-to-r from-transparent via-black/5 dark:via-white/10 to-transparent flex-shrink-0" />

        {/* ── Content ── */}
        <div className="relative z-10 flex-1 min-h-0 overflow-y-auto px-6 py-6 sm:px-8 custom-scrollbar" style={{ scrollbarWidth: "thin" }}>
          <div
            className={`mx-auto w-full max-w-3xl transition-all duration-300 ease-out fill-mode-forwards ${
              animating
                ? animDir === "forward"
                  ? "opacity-0 translate-x-6 scale-[0.98]"
                  : "opacity-0 -translate-x-6 scale-[0.98]"
                : "opacity-100 translate-x-0 scale-100"
            }`}
          >
            {step.content}
          </div>
        </div>

        <div className="h-px bg-gradient-to-r from-transparent via-black/5 dark:via-white/10 to-transparent flex-shrink-0" />

        {/* ── Footer ── */}
        <div className="relative z-10 px-6 py-4 sm:px-8 flex items-center justify-between border-t border-transparent flex-shrink-0 bg-gray-50/70 dark:bg-black/20">
          <div className="flex-1 flex justify-start">
            <button
              onClick={() => back()}
              className={`flex items-center gap-1.5 text-[13px] font-bold text-[#6b7280] dark:text-[#a1a1aa] hover:text-[#111111] dark:hover:text-white transition-all px-3 py-2 rounded-xl hover:bg-black/[0.05] dark:hover:bg-white/[0.06] active:scale-95 cursor-pointer ${
                isFirst ? 'opacity-0 pointer-events-none' : 'opacity-100'
              }`}
              aria-hidden={isFirst}
            >
              <FiArrowLeft className="w-4 h-4" />
              Back
            </button>
          </div>

          <div className="flex-1 flex items-center justify-end gap-2">
            {!isLast && (
              <button
                onClick={() => next()}
                className="text-[12.5px] font-bold text-[#9aa0a6] hover:text-[#4b5563] dark:hover:text-white transition-colors px-3 py-2 cursor-pointer"
              >
                {step.cta ? "Skip for now" : "Skip"}
              </button>
            )}

            <button
              onClick={() => {
                if (isLast) {
                  handleFinish();
                } else if (step.cta) {
                  handleCta(step.cta.action);
                } else {
                  next();
                }
              }}
              className="flex items-center gap-2 px-6 py-2.5 text-white text-[13.5px] font-black rounded-xl whitespace-nowrap btn-new-message cursor-pointer"
            >
              <span className="relative z-10 tracking-wide block w-full">{isLast ? "Complete Setup" : (step.cta ? step.cta.label : "Continue")}</span>
              {!isLast && <FiArrowRight className="w-4 h-4 ml-0.5 relative z-10" />}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};
