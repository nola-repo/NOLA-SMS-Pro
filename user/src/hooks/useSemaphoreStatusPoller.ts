import { useState, useRef, useEffect, useCallback } from 'react';
import { checkMessageStatus } from '../api/sms';

export interface UseSemaphoreStatusPollerOptions {
  onRefresh?: () => void;
  intervalMs?: number;
  maxAttempts?: number;
}

export function useSemaphoreStatusPoller(options?: UseSemaphoreStatusPollerOptions) {
  const [isPolling, setIsPolling] = useState(false);
  const activeTimersRef = useRef<Set<ReturnType<typeof setTimeout>>>(new Set());
  const isMountedRef = useRef(true);

  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
      // Cleanup all active timers on unmount
      activeTimersRef.current.forEach(timerId => clearTimeout(timerId));
      activeTimersRef.current.clear();
    };
  }, []);

  const cancelAll = useCallback(() => {
    activeTimersRef.current.forEach(timerId => clearTimeout(timerId));
    activeTimersRef.current.clear();
    if (isMountedRef.current) {
      setIsPolling(false);
    }
  }, []);

  const pollMessageStatus = useCallback((messageIds: string[], customMaxAttempts?: number) => {
    if (!messageIds || messageIds.length === 0) return;

    cancelAll();
    if (isMountedRef.current) {
      setIsPolling(true);
    }

    let attempts = 0;
    const maxAttempts = customMaxAttempts ?? options?.maxAttempts ?? 30; // ~60s total
    const intervalMs = options?.intervalMs ?? 2000;

    const pollStatus = async () => {
      if (!isMountedRef.current) return;
      attempts++;

      try {
        const statusMap = await checkMessageStatus(messageIds);
        if (!isMountedRef.current) return;

        const allResolved = messageIds.every(id => {
          const s = (statusMap[id] || '').toLowerCase();
          return s === 'sent' || s === 'failed' || s === 'success';
        });

        if (allResolved || attempts >= maxAttempts) {
          if (isMountedRef.current) {
            setIsPolling(false);
            options?.onRefresh?.();
          }
        } else {
          const timerId = setTimeout(pollStatus, intervalMs);
          activeTimersRef.current.add(timerId);
        }
      } catch (err) {
        if (!isMountedRef.current) return;
        if (attempts >= maxAttempts) {
          setIsPolling(false);
          options?.onRefresh?.();
        } else {
          const timerId = setTimeout(pollStatus, intervalMs);
          activeTimersRef.current.add(timerId);
        }
      }
    };

    const initialTimer = setTimeout(pollStatus, intervalMs);
    activeTimersRef.current.add(initialTimer);
  }, [cancelAll, options]);

  return {
    pollMessageStatus,
    isPolling,
    cancelAll,
  };
}
