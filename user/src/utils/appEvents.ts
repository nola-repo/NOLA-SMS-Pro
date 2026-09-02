import type { Contact } from '../types/Contact';

export interface AppEventMap {
  'sms-sent': { messageId?: string; recipientPhone?: string; locationId?: string; timestamp?: string };
  'nola-contact-updated': { contact: Contact; previous?: Contact | null; locationId?: string };
  'nola-bulk-message-created': {
    id: string;
    message: string;
    recipientCount: number;
    recipientNumbers: string[];
    timestamp: string;
    batchId?: string;
    locationId?: string;
  };
  'open-onboarding': { step?: number };
  'nola-auth-session-expired': { reason?: string };
  'navigate-to-settings': { tab?: string; reconnect?: boolean };
  'conversation-updated': { id?: string; lastMessage?: string; timestamp?: string };
  'nola-auth-session-updated': { locationId?: string; role?: string; cleared?: boolean };
  'nola-location-product-state-reset': { locationId?: string };
  'ghl-location-set': { locationId: string };
  'ghl-location-changed': { locationId: string };
  'open-sender-id-modal': { senderId?: string };
}

export function publishAppEvent<K extends keyof AppEventMap>(
  eventName: K,
  ...[detail]: AppEventMap[K] extends undefined | void ? [detail?: undefined] : [detail: AppEventMap[K]]
): void {
  if (typeof window === 'undefined') return;
  const event = new CustomEvent(eventName, { detail });
  window.dispatchEvent(event);
}

export function subscribeAppEvent<K extends keyof AppEventMap>(
  eventName: K,
  handler: (detail: AppEventMap[K]) => void
): () => void {
  if (typeof window === 'undefined') return () => {};

  const listener = (event: Event) => {
    const customEvent = event as CustomEvent<AppEventMap[K]>;
    handler(customEvent.detail);
  };

  window.addEventListener(eventName, listener);
  return () => {
    window.removeEventListener(eventName, listener);
  };
}
