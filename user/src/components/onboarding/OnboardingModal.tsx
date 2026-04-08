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

interface OnboardingModalProps {
  onboarding: UseOnboardingReturn;
}

type CtaAction = "next" | "settings-sender" | "settings-credits" | "complete";

interface Step {
  id: number;
  icon: React.ReactNode;
  title: string;
  tag: string;
  tagVariant: "default" | "required" | "complete";
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
  <div className="flex items-start gap-3.5 py-3 border-b border-black/[0.04] dark:border-white/[0.04] last:border-0">
    <div className={`mt-0.5 w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-[15px] ${
      accent
        ? "bg-[#2b83fa] text-white"
        : "bg-black/[0.04] dark:bg-white/[0.06] text-[#6b7280] dark:text-[#9aa0a6]"
    }`}>
      {icon}
    </div>
    <div className="flex-1 min-w-0 pt-0.5">
      <p className="text-[13.5px] font-semibold text-[#111111] dark:text-[#ececf1] leading-snug">{title}</p>
      {body && <p className="text-[12px] text-[#6b7280] dark:text-[#9aa0a6] mt-0.5 leading-relaxed">{body}</p>}
    </div>
  </div>
);

const Note: React.FC<{ children: React.ReactNode; variant?: "info" | "warn" }> = ({
  children,
  variant = "info",
}) => (
  <div className={`flex items-start gap-2.5 px-3.5 py-3 rounded-xl text-[12px] leading-relaxed font-medium ${
    variant === "warn"
      ? "bg-amber-50 dark:bg-amber-900/10 text-amber-700 dark:text-amber-400 border border-amber-200/60 dark:border-amber-800/30"
      : "bg-[#f4f6fa] dark:bg-white/[0.04] text-[#4b5563] dark:text-[#9aa0a6] border border-black/[0.04] dark:border-white/[0.05]"
  }`}>
    {variant === "warn"
      ? <FiAlertTriangle className="w-3.5 h-3.5 mt-0.5 flex-shrink-0 opacity-70" />
      : <FiInfo className="w-3.5 h-3.5 mt-0.5 flex-shrink-0 opacity-50" />
    }
    <span>{children}</span>
  </div>
);

// ── Step content ─────────────────────────────────────────────────────────────

const Step1 = () => (
  <div className="space-y-4">
    {/* Credit highlight */}
    <div className="flex items-center gap-4 px-4 py-4 rounded-xl bg-[#2b83fa] text-white">
      <div className="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center flex-shrink-0">
        <FiCreditCard className="w-5 h-5" />
      </div>
      <div>
        <p className="text-[22px] font-black leading-none">10 free credits</p>
        <p className="text-[12px] text-white/70 mt-0.5 font-medium">included on signup · for testing</p>
      </div>
    </div>

    <div className="divide-y divide-black/[0.04] dark:divide-white/[0.04]">
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
    <div className="space-y-4">
      {/* Path indicator */}
      <div className="px-1">
        <p className="text-[11px] font-bold uppercase tracking-widest text-[#9aa0a6] mb-3">Navigate to</p>
        <div className="flex items-center gap-1.5 flex-wrap">
          {path.map((label, i) => (
            <React.Fragment key={i}>
              <span className={`px-2.5 py-1 rounded-md text-[12.5px] font-semibold ${
                i === path.length - 1
                  ? "bg-[#2b83fa] text-white"
                  : "bg-black/[0.04] dark:bg-white/[0.06] text-[#37352f] dark:text-[#d1d5db]"
              }`}>
                {label}
              </span>
              {i < path.length - 1 && (
                <FiChevronRight className="w-3.5 h-3.5 text-[#9aa0a6] flex-shrink-0" />
              )}
            </React.Fragment>
          ))}
        </div>
      </div>

      {/* Final action */}
      <div className="flex items-center gap-3 px-3.5 py-3.5 rounded-xl border border-black/[0.06] dark:border-white/[0.06] bg-white dark:bg-white/[0.03]">
        <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 flex items-center justify-center flex-shrink-0 text-[#2b83fa]">
          <FiCheck className="w-4 h-4" />
        </div>
        <div>
          <p className="text-[13px] font-bold text-[#111111] dark:text-[#ececf1]">Choose NOLA SMS Pro – Default Provider</p>
          <p className="text-[11.5px] text-[#9aa0a6] mt-0.5">Select and confirm it is active</p>
        </div>
      </div>

      <Note variant="warn">
        This setting is only visible to <strong>Agency Owners and Admins.</strong> Contact your agency owner if you don't have access.
      </Note>
    </div>
  );
};

const Step3 = () => (
  <div className="space-y-4">
    {/* Sender ID display */}
    <div className="px-4 py-5 rounded-xl border border-black/[0.06] dark:border-white/[0.06] bg-white dark:bg-white/[0.03] text-center">
      <p className="text-[11px] font-bold uppercase tracking-widest text-[#9aa0a6] mb-3">Your default Sender ID</p>
      <code className="text-[24px] font-black text-[#111111] dark:text-white tracking-tight font-mono">NOLASMSPro</code>
      <p className="text-[11.5px] text-[#9aa0a6] font-medium mt-2">Active automatically — no configuration needed</p>
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
      <div className="flex flex-col items-center justify-center py-10 text-center animate-in fade-in zoom-in-95">
        <div className="w-14 h-14 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-500 mb-4">
          <FiCheck className="w-7 h-7" />
        </div>
        <h4 className="text-[17px] font-bold text-[#111111] dark:text-[#ececf1] mb-2">Registration Submitted</h4>
        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] max-w-sm leading-relaxed">
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
          <div className="p-2.5 rounded-xl bg-red-50 border border-red-100 dark:bg-red-900/10 dark:border-red-900/20">
            <p className="text-[12px] text-red-600 dark:text-red-400 font-medium">{error}</p>
          </div>
        )}
        <div>
          <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Sender Name</label>
          <input
            value={newId}
            onChange={e => setNewId(e.target.value.replace(/\s/g, ''))}
            placeholder="ex. NOLASMSPro"
            maxLength={11}
            required
            disabled={isSubmitting}
            className="w-full px-3.5 py-2 rounded-xl text-[13px] font-bold border bg-[#f4f6fa] dark:bg-white/[0.04] border-black/[0.06] dark:border-white/[0.06] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:border-[#2b83fa]"
          />
        </div>
        <div>
          <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Business Purpose</label>
          <textarea
            value={newPurpose}
            onChange={e => setNewPurpose(e.target.value)}
            placeholder="What will you be using this for?"
            required rows={2} disabled={isSubmitting}
            className="w-full px-3.5 py-2 rounded-xl text-[13px] border bg-[#f4f6fa] dark:bg-white/[0.04] border-black/[0.06] dark:border-white/[0.06] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:border-[#2b83fa] resize-none"
          />
        </div>
        <div>
          <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Sample Message</label>
          <textarea
            value={newSample}
            onChange={e => setNewSample(e.target.value)}
            placeholder="Provide a specific message template example."
            required rows={2} disabled={isSubmitting}
            className="w-full px-3.5 py-2 rounded-xl text-[13px] border bg-[#f4f6fa] dark:bg-white/[0.04] border-black/[0.06] dark:border-white/[0.06] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:border-[#2b83fa] resize-none"
          />
        </div>

        <button type="submit" disabled={isSubmitting} className="w-full mt-2 flex items-center justify-center gap-2 py-2.5 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white rounded-xl font-bold text-[13px] transition-all shadow-md shadow-blue-500/20">
          {isSubmitting ? <FiLoader className="w-4 h-4 animate-spin" /> : "Submit Request"}
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
    <div className="space-y-4">
      {/* Formula */}
      <div className="flex items-center gap-3 px-4 py-4 rounded-xl bg-[#f4f6fa] dark:bg-white/[0.04] border border-black/[0.04] dark:border-white/[0.05]">
        {[
          { val: "1 Credit", sub: null },
          { val: "=", sub: null },
          { val: "1 Segment", sub: null },
          { val: "=", sub: null },
          { val: "160 chars", sub: "max" },
        ].map((item, i) => (
          <div key={i} className={`${item.val === "=" ? "text-[#9aa0a6] text-base font-semibold" : "text-center"}`}>
            {item.val === "=" ? (
              <span>{item.val}</span>
            ) : (
              <>
                <p className="text-[13px] font-black text-[#111111] dark:text-[#ececf1] leading-tight">{item.val}</p>
                {item.sub && <p className="text-[10px] text-[#9aa0a6] font-medium">{item.sub}</p>}
              </>
            )}
          </div>
        ))}
      </div>

      {/* Table */}
      <div className="rounded-xl border border-black/[0.06] dark:border-white/[0.06] overflow-hidden">
        {rows.map((row, i) => (
          <div key={i} className={`flex items-center justify-between px-4 py-3 border-b border-black/[0.04] dark:border-white/[0.04] last:border-0 ${
            row.highlight ? "bg-[#2b83fa]/[0.04]" : "bg-white dark:bg-white/[0.02]"
          }`}>
            <span className="text-[13px] text-[#4b5563] dark:text-[#9aa0a6] font-medium">{row.range}</span>
            <span className={`text-[13px] font-bold tabular-nums ${row.highlight ? "text-[#2b83fa]" : "text-[#111111] dark:text-[#ececf1]"}`}>
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
  const [topUpAmount, setTopUpAmount] = useState<number>(500);
  const [submitted, setSubmitted] = useState(false);
  const packages = [
      { credits: 500, price: 500 },
      { credits: 1100, price: 1000 },
      { credits: 2750, price: 2500 },
      { credits: 6000, price: 5000 },
  ];

  const handleTopUp = (e: React.FormEvent) => {
      e.preventDefault();
      setSubmitted(true);
      const url = `https://nolasms.pro/checkout?amount=${topUpAmount}&pkg=credits`;
      window.open(url, "stripeCheckout", "width=800,height=600,left=200,top=200");
  };

  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3 px-3.5 py-3 rounded-xl bg-[#f4f6fa] dark:bg-white/[0.04] border border-black/[0.04] dark:border-white/[0.05]">
        <FiZap className="w-4 h-4 mt-0.5 text-amber-500 flex-shrink-0" />
        <p className="text-[12px] text-[#4b5563] dark:text-[#9aa0a6] font-medium leading-relaxed">
          Credits never expire and are added instantly. You can buy now or proceed with the free 10 Credits just to test!
        </p>
      </div>

      <form onSubmit={handleTopUp} className="space-y-4">
        <div className="grid grid-cols-2 gap-2.5">
            {packages.map(pkg => (
                <button
                    key={pkg.credits}
                    type="button"
                    onClick={() => setTopUpAmount(pkg.credits)}
                    className={`flex flex-col items-center py-2.5 rounded-xl border-2 transition-all ${topUpAmount === pkg.credits
                        ? 'border-[#2b83fa] bg-[#2b83fa]/5 dark:bg-[#2b83fa]/10'
                        : 'border-black/[0.06] dark:border-white/[0.06] hover:border-[#2b83fa]/40 bg-white dark:bg-white/[0.02]'
                        }`}
                >
                    <span className={`text-[16px] font-black tracking-tight ${topUpAmount === pkg.credits ? 'text-[#2b83fa]' : 'text-[#111111] dark:text-[#ececf1]'}`}>{pkg.credits.toLocaleString()}</span>
                    <span className="text-[10px] text-[#9aa0a6] uppercase tracking-wider font-bold">credits</span>
                    <span className={`text-[12px] font-bold mt-0.5 ${topUpAmount === pkg.credits ? 'text-[#2b83fa]' : 'text-[#6e6e73] dark:text-[#94959b]'}`}>₱{pkg.price}</span>
                </button>
            ))}
        </div>
        
        {submitted ? (
            <div className="w-full flex items-center justify-center gap-2 py-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl text-emerald-600 dark:text-emerald-400 font-semibold text-[13px] border border-emerald-100 dark:border-emerald-900/30">
                <FiCheck className="w-4 h-4" /> Checkout window opened
            </div>
        ) : (
            <button type="submit" className="w-full flex items-center justify-center gap-2 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white rounded-xl font-bold text-[13px] transition-all shadow-md shadow-blue-500/20 hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] active:scale-[0.98]">
                <FiCreditCard className="w-4 h-4" /> Buy {topUpAmount.toLocaleString()} Credits
            </button>
        )}
      </form>
    </div>
  );
};

const Step7 = () => (
  <div className="space-y-4">
    <div className="divide-y divide-black/[0.04] dark:divide-white/[0.04]">
      <Row icon={<FiZap className="w-4 h-4" />} title="GHL Workflows" body="Add SMS actions to any automation step" />
      <Row icon={<FiCalendar className="w-4 h-4" />} title="Appointment Reminders" body="Send automated reminders before appointments" />
      <Row icon={<FiUsers className="w-4 h-4" />} title="Bulk Announcements" body="Message your entire contact list at once" />
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
      tag: "Step 1",
      tagVariant: "default",
      subtitle: "Your account is active. You have 10 free credits to get started.",
      content: <Step1 />,
    },
    {
      id: 1,
      icon: <FiPhone className="w-5 h-5" />,
      title: "Enable Telephony Provider",
      tag: "Step 2",
      tagVariant: "default",
      subtitle: "Connect NOLA SMS Pro in your GoHighLevel settings.",
      content: <Step2 />,
    },
    {
      id: 2,
      icon: <FiSend className="w-5 h-5" />,
      title: "Start Sending",
      tag: "Step 3",
      tagVariant: "default",
      subtitle: "Send immediately using the default Sender ID — no setup needed.",
      content: <Step3 />,
    },
    {
      id: 3,
      icon: <FiUser className="w-5 h-5" />,
      title: "Register Your Sender ID",
      tag: "Required",
      tagVariant: "required",
      subtitle: "A custom Sender ID is required for production. Submit the form to begin approval.",
      content: <Step4 />,
    },
    {
      id: 4,
      icon: <FiInfo className="w-5 h-5" />,
      title: "Credit Usage",
      tag: "Step 5",
      tagVariant: "default",
      subtitle: "1 credit = 1 SMS segment = up to 160 characters.",
      content: <Step5 />,
    },
    {
      id: 5,
      icon: <FiCreditCard className="w-5 h-5" />,
      title: "Top Up Credits",
      tag: "Step 6",
      tagVariant: "default",
      subtitle: "Pay as you go — no subscription, credits never expire.",
      content: <Step6 />,
    },
    {
      id: 6,
      icon: <FiZap className="w-5 h-5" />,
      title: "Use in Automation",
      tag: "Final step",
      tagVariant: "complete",
      subtitle: "Integrate SMS into workflows, reminders, and campaigns.",
      content: <Step7 />,
      cta: { label: "Get started", action: "complete" },
    },
  ];
}

// ── Tag component ────────────────────────────────────────────────────────────
const StepTag: React.FC<{ label: string; variant: Step["tagVariant"] }> = ({ label, variant }) => {
  const styles = {
    default:   "bg-black/[0.04] dark:bg-white/[0.08] text-[#6b7280] dark:text-[#9aa0a6]",
    required:  "bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400",
    complete:  "bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400",
  };
  return (
    <span className={`inline-flex px-2 py-0.5 rounded-md text-[10.5px] font-bold uppercase tracking-widest ${styles[variant]}`}>
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

  useEffect(() => {
    if (currentStep === prevStepRef.current) return;
    setAnimDir(currentStep > prevStepRef.current ? "forward" : "back");
    setAnimating(true);
    const t = setTimeout(() => {
      setVisibleStep(currentStep);
      setAnimating(false);
      prevStepRef.current = currentStep;
    }, 180);
    return () => clearTimeout(t);
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
    if (isCelebrating) setIsCelebrating(false);
    return null;
  }

  const isFirst = visibleStep === 0;
  const isLast = visibleStep === totalSteps - 1;

  if (isCelebrating) {
    return (
      <div className="fixed inset-0 z-[999] flex items-center justify-center p-4">
        <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={complete} />
        <div className="relative w-full max-w-[340px] bg-white dark:bg-[#111213] rounded-3xl shadow-2xl p-8 flex flex-col items-center text-center animate-in zoom-in-95 fade-in duration-300">
          <div className="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mb-5 shadow-inner">
            <FiCheck className="w-8 h-8 text-blue-600 dark:text-blue-400" />
          </div>
          <h2 className="text-[20px] font-black text-[#111111] dark:text-white tracking-tight mb-2">You're all set!</h2>
          <p className="text-[13px] text-[#6b7280] dark:text-[#9aa0a6] leading-relaxed mb-8">
            NOLA SMS Pro is configured and ready to use in your daily workflow.
          </p>
          <button
            onClick={complete}
            className="w-full flex items-center justify-center py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white rounded-xl font-bold text-[14px] transition-all shadow-md shadow-blue-500/20 hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] active:scale-[0.98]"
          >
            Go to Dashboard
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 z-[999] flex items-center justify-center p-4">
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-black/50 backdrop-blur-sm"
        onClick={close}
      />

      {/* Modal card */}
      <div className="relative w-full max-w-[420px] bg-white dark:bg-[#111213] rounded-2xl shadow-2xl shadow-black/20 border border-black/[0.06] dark:border-white/[0.06] flex flex-col max-h-[90vh] animate-in zoom-in-95 fade-in duration-200">

        {/* ── Header ── */}
        <div className="px-5 pt-5 pb-4 flex-shrink-0">
          <div className="flex items-start justify-between gap-3 mb-4">
            {/* Step tag */}
            <StepTag label={step.tag} variant={step.tagVariant} />
            {/* Close */}
            <button
              onClick={close}
              className="flex-shrink-0 w-6 h-6 rounded-md flex items-center justify-center text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white hover:bg-black/[0.05] dark:hover:bg-white/[0.05] transition-all"
              aria-label="Close"
            >
              <FiX className="w-3.5 h-3.5" />
            </button>
          </div>

          {/* Icon + Title */}
          <div className="flex items-center gap-3.5">
            <div className="w-10 h-10 rounded-xl bg-[#2b83fa] flex items-center justify-center text-white flex-shrink-0 shadow-lg shadow-blue-500/25">
              {step.icon}
            </div>
            <div>
              <h2 className="text-[16.5px] font-bold text-[#111111] dark:text-white tracking-tight leading-snug">
                {step.title}
              </h2>
              <p className="text-[12px] text-[#6b7280] dark:text-[#9aa0a6] mt-0.5 leading-snug font-medium">
                {step.subtitle}
              </p>
            </div>
          </div>
        </div>

        {/* ── Progress bar ── */}
        <div className="px-5 pb-4 flex-shrink-0">
          <div className="flex items-center gap-1.5 mb-1.5">
            {STEPS.map((_, i) => (
              <button
                key={i}
                onClick={() => goToStep(i)}
                className={`h-[3px] rounded-full transition-all duration-300 cursor-pointer ${
                  i < currentStep
                    ? "bg-[#2b83fa] flex-1"
                    : i === currentStep
                    ? "bg-[#2b83fa] flex-[2]"
                    : "bg-black/[0.08] dark:bg-white/[0.08] flex-1"
                }`}
              />
            ))}
          </div>
          <p className="text-[10.5px] text-[#9aa0a6] font-medium tabular-nums">
            {currentStep + 1} of {totalSteps}
          </p>
        </div>

        {/* Divider */}
        <div className="h-px bg-black/[0.05] dark:bg-white/[0.05] flex-shrink-0" />

        {/* ── Content ── */}
        <div className="px-5 py-5 overflow-y-auto h-[320px] sm:h-[360px]" style={{ scrollbarWidth: "thin" }}>
          <div
            className={`transition-all duration-200 ${
              animating
                ? animDir === "forward"
                  ? "opacity-0 translate-x-3"
                  : "opacity-0 -translate-x-3"
                : "opacity-100 translate-x-0"
            }`}
          >
            {step.content}
          </div>
        </div>

        {/* Divider */}
        <div className="h-px bg-black/[0.05] dark:bg-white/[0.05] flex-shrink-0" />

        {/* ── Footer ── */}
        <div className="px-5 py-4 flex items-center justify-between border-t border-black/[0.05] dark:border-white/[0.05] flex-shrink-0 bg-gray-50/50 dark:bg-white/[0.02] rounded-b-2xl">
          {/* Left - Back Button (Hidden on first step to maintain alignment) */}
          <div className="flex-1 flex justify-start">
            <button
              onClick={() => back()}
              className={`flex items-center gap-1.5 text-[13px] font-semibold text-[#6b7280] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-white transition-colors px-3 py-2 rounded-xl hover:bg-black/[0.05] dark:hover:bg-white/[0.06] ${
                isFirst ? 'opacity-0 pointer-events-none' : 'opacity-100'
              }`}
              aria-hidden={isFirst}
            >
              <FiArrowLeft className="w-4 h-4" />
              Back
            </button>
          </div>

          {/* Right - Skip and Next/CTA */}
          <div className="flex-1 flex items-center justify-end gap-1 sm:gap-2">
            {!isLast && (
              <button
                onClick={step.cta ? next : handleFinish}
                className="text-[13px] font-semibold text-[#6b7280] dark:text-[#9aa0a6] hover:text-[#111111] dark:hover:text-[#d1d5db] transition-colors pl-3 pr-2 py-2"
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
              className="flex items-center gap-2 px-5 py-2.5 bg-[#2b83fa] text-white text-[13px] font-bold rounded-xl hover:bg-[#1d6ee6] transition-all active:scale-[0.98] shadow-md shadow-blue-500/20 whitespace-nowrap"
            >
              {isLast ? "Complete Setup" : (step.cta ? step.cta.label : "Continue")}
              {!isLast && <FiArrowRight className="w-4 h-4 ml-0.5" />}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};
