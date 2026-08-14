import type { Message, FirestoreMessage, NolaMessageDoc } from '../types/Sms';

export type StatusInput =
  | Partial<Message>
  | Partial<FirestoreMessage>
  | Partial<NolaMessageDoc>
  | {
      status?: string;
      retry_status?: string;
      retryStatus?: string;
      retry_count?: number;
      retryCount?: number;
      retry_max_attempts?: number;
      retryMaxAttempts?: number;
      origin?: string;
      provider_error?: string | null;
      providerError?: string | null;
    };

export interface RetryBadgeInfo {
  label: string;
  tone: 'neutral' | 'amber' | 'amber_pulse' | 'green' | 'red';
  isRetrying: boolean;
  tooltip?: string;
}

/**
 * Returns user-facing status label taking retry state into account.
 */
export function smsStatusLabel(msg: StatusInput): string {
  const normStatus = String(msg.status || '').toLowerCase();
  const retryStatus = msg.retry_status || (msg as Partial<Message>).retryStatus;

  if (normStatus === 'sent' || normStatus === 'delivered' || normStatus === 'success') {
    return 'Sent';
  }

  if (normStatus === 'failed' || normStatus === 'rejected' || normStatus === 'undelivered') {
    return 'Failed';
  }

  if (retryStatus === 'processing') {
    return 'Retrying now...';
  }

  if (retryStatus === 'pending_retry') {
    const count = msg.retry_count ?? (msg as Partial<Message>).retryCount ?? 0;
    const max = msg.retry_max_attempts ?? (msg as Partial<Message>).retryMaxAttempts ?? 3;
    return `Retrying... Attempt ${Math.min(count + 1, max)}/${max}`;
  }

  if (normStatus === 'sending' || normStatus === 'pending' || normStatus === 'queued') {
    return 'Sending...';
  }

  return msg.status || 'Sending';
}

/**
 * Checks if a message is currently in an active retry loop (pending_retry or processing).
 */
export function isMessageRetrying(msg: StatusInput): boolean {
  const normStatus = String(msg.status || '').toLowerCase();
  const retryStatus = msg.retry_status || (msg as Partial<Message>).retryStatus || '';
  return (
    ['pending', 'sending', 'queued'].includes(normStatus) &&
    ['pending_retry', 'processing'].includes(retryStatus)
  );
}

/**
 * Formats retry attempt count e.g. "Attempt 1/3".
 */
export function getRetryAttemptLabel(msg: StatusInput): string | null {
  const max = msg.retry_max_attempts ?? (msg as Partial<Message>).retryMaxAttempts;
  const count = msg.retry_count ?? (msg as Partial<Message>).retryCount;
  if (max && typeof count === 'number') {
    return `Attempt ${Math.min(count + 1, max)}/${max}`;
  }
  return null;
}

/**
 * Returns structured badge display info including visual tone and contextual tooltip.
 */
export function getRetryBadgeInfo(msg: StatusInput): RetryBadgeInfo {
  const normStatus = String(msg.status || '').toLowerCase();
  const retryStatus = msg.retry_status || (msg as Partial<Message>).retryStatus;
  const origin = msg.origin;

  if (normStatus === 'sent' || normStatus === 'delivered' || normStatus === 'success') {
    return { label: 'Sent', tone: 'green', isRetrying: false };
  }

  if (normStatus === 'failed' || normStatus === 'rejected' || normStatus === 'undelivered') {
    if (origin === 'retry_worker_exhausted') {
      return {
        label: 'Failed',
        tone: 'red',
        isRetrying: false,
        tooltip: 'Retries exhausted. Credits were restored if billing was charged.',
      };
    }
    return { label: 'Failed', tone: 'red', isRetrying: false };
  }

  if (retryStatus === 'processing') {
    return {
      label: 'Retrying now...',
      tone: 'amber_pulse',
      isRetrying: true,
      tooltip: 'Retry worker is actively attempting delivery with provider.',
    };
  }

  if (retryStatus === 'pending_retry') {
    const count = msg.retry_count ?? (msg as Partial<Message>).retryCount ?? 0;
    const max = msg.retry_max_attempts ?? (msg as Partial<Message>).retryMaxAttempts ?? 3;
    const attemptLabel = `Attempt ${Math.min(count + 1, max)}/${max}`;
    return {
      label: `Retrying... ${attemptLabel}`,
      tone: 'amber',
      isRetrying: true,
      tooltip: `Provider timed out. Queued for background retry (${attemptLabel}).`,
    };
  }

  if (normStatus === 'sending' || normStatus === 'pending' || normStatus === 'queued') {
    return { label: 'Sending...', tone: 'neutral', isRetrying: false };
  }

  return { label: msg.status || 'Sending', tone: 'neutral', isRetrying: false };
}
