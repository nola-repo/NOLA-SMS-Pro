import { safeStorage } from './safeStorage';
import { sessionSafeStorage } from './sessionSafeStorage';
import {
  getRequestedGhlLocationId,
  isLocationSessionMismatchPayload,
  refreshGhlSessionForLocation,
} from './ghlSessionReauth';
import {
  createBootstrapBlockedResponse,
  ensureLocationBootstrapAllowsProtectedData,
  getCachedLocationBootstrapState,
  publishLifecycleBlockFromPayload,
  resolveLocationBootstrap,
} from './locationBootstrap';

const SESSION_KEYS = {
  token: 'nola_auth_token',
  role: 'nola_auth_role',
  companyId: 'nola_company_id',
  locationId: 'nola_location_id',
  user: 'nola_auth_user',
} as const;

const PUBLIC_AUTH_PATHS = [
  '/api/auth/login.php',
  '/api/auth/register.php',
  '/api/auth/forgot_password_otp.php',
  '/api/auth/reset_password_otp.php',
  '/api/auth/ghl_autologin',
  '/api/v2/auth/ghl_autologin',
  '/api/location/bootstrap',
  '/api/v2/location/bootstrap',
  '/api/auth/me',
];

const BOOTSTRAP_GATED_PATHS = [
  '/api/account',
  '/api/account-sender',
  '/api/billing',
  '/api/bulk-campaigns',
  '/api/check_message_status.php',
  '/api/contacts',
  '/api/conversations',
  '/api/credits',
  '/api/ghl-contacts',
  '/api/ghl-conversations',
  '/api/messages',
  '/api/notification-settings',
  '/api/notifications',
  '/api/sender-requests',
  '/api/sms',
  '/api/templates',
  '/api/tickets',
];

export function createRequestId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

const getRequestPath = (input: RequestInfo | URL): string => {
  const raw = typeof input === 'string'
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url;

  try {
    return new URL(raw, window.location.origin).pathname;
  } catch {
    return raw;
  }
};

const isPublicAuthRequest = (input: RequestInfo | URL): boolean => {
  const path = getRequestPath(input);
  return PUBLIC_AUTH_PATHS.some((publicPath) => path === publicPath || path.startsWith(publicPath + '/'));
};

const isBootstrapGatedRequest = (input: RequestInfo | URL): boolean => {
  const path = getRequestPath(input);
  return BOOTSTRAP_GATED_PATHS.some((gatedPath) => path === gatedPath || path.startsWith(gatedPath + '/'));
};

const payloadRequiresReconnect = (payload: unknown): boolean => {
  return !!payload && typeof payload === 'object' && (payload as Record<string, unknown>).requires_reconnect === true;
};

const getStoredToken = (): string =>
  sessionSafeStorage.getItem(SESSION_KEYS.token) ||
  safeStorage.getItem(SESSION_KEYS.token) ||
  '';

const getStoredLocationId = (): string => getRequestedGhlLocationId();

const clearLocationProductState = (locationId: string): void => {
  safeStorage.removeItem('nola_active_contact');
  safeStorage.removeItem('nola_active_bulk_message');
  safeStorage.removeItem('nola_settings_preferred_sender');
  safeStorage.removeItem('nola_settings_notifications');
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('nola-location-product-state-reset', { detail: { locationId } }));
  }
};

const clearStoredAuthSession = (): void => {
  Object.values(SESSION_KEYS).forEach((key) => {
    safeStorage.removeItem(key);
    sessionSafeStorage.removeItem(key);
  });
  safeStorage.removeItem('nola_user');
  safeStorage.removeItem('nola_settings_account');
  safeStorage.removeItem('nola_settings_api');
  safeStorage.removeItem('nola_settings_preferred_sender');
  safeStorage.removeItem('nola_is_ghl_frame');
  try { sessionStorage.removeItem('nola_is_ghl_frame'); } catch { /* ignore */ }
  try { localStorage.removeItem('nola_is_ghl_frame'); } catch { /* ignore */ }
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('nola-auth-session-updated', { detail: { cleared: true } }));
  }
};

export function withRequestId(headers?: HeadersInit): Headers {
  const merged = new Headers(headers);

  if (!merged.has('X-Request-ID')) {
    merged.set('X-Request-ID', createRequestId());
  }

  return merged;
}

export function withApiHeaders(headers?: HeadersInit): Headers {
  const merged = withRequestId(headers);
  const token = getStoredToken();
  const locationId = getStoredLocationId();

  if (token && !merged.has('Authorization')) {
    merged.set('Authorization', `Bearer ${token}`);
  }

  if (locationId && !merged.has('X-GHL-Location-ID')) {
    merged.set('X-GHL-Location-ID', locationId);
  }

  return merged;
}

export async function apiFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const isPublicAuthPath = isPublicAuthRequest(input);

  const execute = async (hasRetriedMismatch = false): Promise<Response> => {
    const locationIdAtRequestStart = getStoredLocationId();
    if (!isPublicAuthPath && isBootstrapGatedRequest(input)) {
      const ready = await ensureLocationBootstrapAllowsProtectedData(locationIdAtRequestStart);
      if (!ready) {
        return createBootstrapBlockedResponse(getCachedLocationBootstrapState(locationIdAtRequestStart));
      }
    }

    const tokenAtRequestStart = getStoredToken();
    const response = await fetch(input, {
      ...init,
      headers: withApiHeaders(init.headers),
    });

    if (!isPublicAuthPath && locationIdAtRequestStart && getStoredLocationId() !== locationIdAtRequestStart) {
      return new Response(
        JSON.stringify({
          error: 'Ignoring stale response for a previous GHL location.',
          code: 'STALE_LOCATION_RESPONSE',
          contacts_can_load: false,
        }),
        { status: 409, headers: { 'Content-Type': 'application/json' } }
      );
    }

    if (!isPublicAuthPath && isBootstrapGatedRequest(input) && !response.ok) {
      const lifecyclePayload = await response.clone().json().catch(() => null);
      const activeLocationId = getStoredLocationId();
      if (activeLocationId && publishLifecycleBlockFromPayload(activeLocationId, lifecyclePayload, response.status)) {
        clearLocationProductState(activeLocationId);
      }
    }

    if (!isPublicAuthPath && !hasRetriedMismatch && response.status === 403) {
      const mismatchPayload = await response.clone().json().catch(() => null);
      if (isLocationSessionMismatchPayload(mismatchPayload)) {
        const locationId = getStoredLocationId();
        const refreshed = await refreshGhlSessionForLocation(locationId);
        if (refreshed) {
          await resolveLocationBootstrap(locationId, { force: true, allowAutologin: false });
          return execute(true);
        }
      }
    }

    if (!isPublicAuthPath && isBootstrapGatedRequest(input) && !response.ok) {
      const reconnectPayload = await response.clone().json().catch(() => null);
      if (payloadRequiresReconnect(reconnectPayload)) {
        await resolveLocationBootstrap(getStoredLocationId(), { force: true, allowAutologin: false });
      }
    }

    if (response.status === 401 && tokenAtRequestStart && !isPublicAuthPath) {
      clearStoredAuthSession();
      if (typeof window !== 'undefined') {
        // Detect iframe: navigating top-level inside GHL iframe breaks the host frame.
        // Instead, dispatch an event that the app can intercept to show a re-auth notice.
        let isInsideIframe = false;
        try {
          isInsideIframe = window.self !== window.top;
        } catch {
          isInsideIframe = true;
        }

        if (isInsideIframe) {
          window.dispatchEvent(new CustomEvent('nola-auth-session-expired', { detail: { reason: '401' } }));
        } else if (window.location.pathname !== '/login') {
          window.location.assign('/login');
        }
      }
    }

    if (response.status === 503 && typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('nola-api-maintenance', { detail: { input: String(input) } }));
    }

    return response;
  };

  return execute();
}
