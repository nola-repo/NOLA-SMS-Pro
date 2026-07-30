import React, { useState, useEffect } from "react";
import { FiSend, FiX, FiArrowRight } from "react-icons/fi";
import { fetchSenderRequests, fetchAccountSenderConfig, type SenderRequest, type AccountSenderConfig } from "../../api/senderRequests";
import { useLocationId } from "../../context/LocationContext";
import { safeStorage } from "../../utils/safeStorage";

interface SenderIdReminderBannerProps {
  onOpenRequestModal?: () => void;
  onNavigateToSettings?: () => void;
  ignoreDismiss?: boolean;
}

export const SenderIdReminderBanner: React.FC<SenderIdReminderBannerProps> = ({
  onOpenRequestModal,
  onNavigateToSettings,
  ignoreDismiss = false,
}) => {
  const { locationId } = useLocationId();
  const [hasRequest, setHasRequest] = useState<boolean | null>(null);
  const [dismissed, setDismissed] = useState<boolean>(false);
  const [loading, setLoading] = useState<boolean>(true);

  useEffect(() => {
    if (!locationId) {
      setHasRequest(true);
      setLoading(false);
      return;
    }

    const dismissKey = `nola_sender_reminder_dismissed_${locationId}`;
    if (!ignoreDismiss && safeStorage.getItem(dismissKey) === "true") {
      setDismissed(true);
      setLoading(false);
      return;
    }

    let isMounted = true;
    const checkRequests = async () => {
      try {
        const [requests, cfg] = await Promise.all([
          fetchSenderRequests(locationId).catch(() => [] as SenderRequest[]),
          fetchAccountSenderConfig(locationId).catch(() => null as AccountSenderConfig | null),
        ]);
        if (isMounted) {
          const hasRequests = Array.isArray(requests) && requests.length > 0;
          const hasApprovedConfig = Boolean(cfg?.approved_sender_id?.trim());
          setHasRequest(hasRequests || hasApprovedConfig);
        }
      } catch {
        if (isMounted) setHasRequest(true); // Default to hiding on error
      } finally {
        if (isMounted) setLoading(false);
      }
    };

    checkRequests();

    const handleRefresh = () => {
      checkRequests();
    };

    window.addEventListener("nola-sender-requests-changed", handleRefresh);
    return () => {
      isMounted = false;
      window.removeEventListener("nola-sender-requests-changed", handleRefresh);
    };
  }, [locationId, ignoreDismiss]);

  const handleDismiss = () => {
    setDismissed(true);
    if (locationId) {
      safeStorage.setItem(`nola_sender_reminder_dismissed_${locationId}`, "true");
    }
  };

  const handleAction = () => {
    if (onOpenRequestModal) {
      onOpenRequestModal();
    } else {
      window.dispatchEvent(new CustomEvent("open-sender-id-modal"));
    }
    if (onNavigateToSettings) {
      onNavigateToSettings();
    }
  };

  if (loading || hasRequest === true || hasRequest === null || dismissed) {
    return null;
  }

  return (
    <div className="relative mb-6 overflow-hidden rounded-2xl border border-blue-200/80 bg-gradient-to-r from-blue-50/95 via-indigo-50/80 to-purple-50/90 p-4 sm:p-5 shadow-sm backdrop-blur-md dark:border-blue-500/20 dark:from-blue-950/40 dark:via-indigo-950/30 dark:to-purple-950/30 transition-all duration-300 animate-in fade-in slide-in-from-top-3">
      {/* Decorative ambient background blur element */}
      <div className="pointer-events-none absolute -right-12 -top-12 h-36 w-36 rounded-full bg-blue-500/10 blur-2xl dark:bg-blue-400/10" />

      <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3.5 sm:items-center">
          <div className="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] text-white shadow-md shadow-blue-500/20 dark:shadow-blue-500/10">
            <FiSend className="h-5 w-5 animate-pulse" />
          </div>

          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <span className="rounded-full bg-blue-600/10 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-blue-600 dark:bg-blue-400/20 dark:text-blue-300">
                Action Recommended
              </span>
              <span className="text-[11px] font-medium text-slate-500 dark:text-slate-400">
                Subaccount Setup
              </span>
            </div>
            <h3 className="mt-0.5 text-[14px] font-extrabold text-slate-900 dark:text-white leading-snug">
              Set Up Your Custom Sender ID
            </h3>
            <p className="mt-0.5 text-[12.5px] font-medium text-slate-600 dark:text-slate-300 leading-relaxed max-w-2xl">
              You haven't requested a custom Sender ID yet. Apply for your branded Sender ID to build customer trust and ensure optimal message delivery.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2.5 pt-1 sm:pt-0 self-end sm:self-center flex-shrink-0">
          <button
            type="button"
            onClick={handleAction}
            className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#2b83fa] px-4 py-2.5 text-[12.5px] font-bold text-white shadow-md shadow-blue-500/20 hover:bg-[#1d6bd4] hover:shadow-lg active:scale-95 transition-all"
          >
            <span>Request Sender ID</span>
            <FiArrowRight className="h-3.5 w-3.5" />
          </button>

          <button
            type="button"
            onClick={handleDismiss}
            className="rounded-xl p-2 text-slate-400 hover:bg-slate-200/50 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-slate-200 transition-colors"
            title="Dismiss reminder"
            aria-label="Dismiss reminder"
          >
            <FiX className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  );
};
