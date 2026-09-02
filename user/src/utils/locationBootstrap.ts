import { normalizeLocationCandidate } from './ghlLocationDetection';
import { safeStorage } from './safeStorage';
import {
  getCurrentGhlContextLocationId,
  getLastGhlSessionRefreshFailure,
  getStoredAuthToken,
  refreshGhlSessionForLocation,
} from './ghlSessionReauth';

export type LocationBootstrapNextAction =
  | 'load_app'
  | 'run_autologin'
  | 'complete_registration'
  | 'show_reconnect'
  | 'show_retry'
  | 'show_not_installed'
  | 'show_blocked';

export interface LocationBootstrapResponse {
  location_id?: string;
  location_name?: string | null;
  location_detected?: boolean;
  session_status?: string;
  ownership_status?: string;
  registration_status?: string;
  token_status?: string;
  contacts_can_load?: boolean;
  requires_autologin?: boolean;
  requires_reconnect?: boolean;
  next_action?: LocationBootstrapNextAction | string;
  code?: string;
  message?: string;
  error?: string;
  request_id?: string;
  login_url?: string;
  onboarding_url?: string;
  registration_url?: string;
}

export type LocationBootstrapState =
  | { status: 'idle'; locationId?: string }
  | { status: 'checking'; locationId: string }
  | { status: 'ready'; locationId: string; response: LocationBootstrapResponse }
  | { status: 'action_required'; locationId: string; response: LocationBootstrapResponse }
  | { status: 'error'; locationId: string; message: string; response?: LocationBootstrapResponse };

const BOOTSTRAP_EVENT = 'nola-location-bootstrap-updated';
const bootstrapStateByLocation = new Map<string, LocationBootstrapState>();
const bootstrapInFlight = new Map<string, Promise<LocationBootstrapState>>();
const BOOTSTRAP_RETRY_DELAYS_MS = [500, 1000, 2000] as const;
const RETRYABLE_CODES = new Set([
  'LOCATION_INSTALL_PENDING',
  'LOCATION_BOOTSTRAP_FAILED',
  'GHL_TOKEN_TEMPORARILY_UNAVAILABLE',
]);
const STOP_RETRY_CODES = new Set([
  'LOCATION_CLEANUP_IN_PROGRESS',
  'LOCATION_REGISTRATION_REQUIRED',
  'LOCATION_ONBOARDING_EXPIRED',
  'LOCATION_NOT_INSTALLED',
  'LOCATION_UNINSTALLED',
  'LOCATION_COMPANY_MISMATCH',
  'LOCATION_SESSION_MISMATCH',
  'LOCATION_INACTIVE',
  'GHL_AUTOLOGIN_REQUIRED',
  'GHL_RECONNECT_REQUIRED',
  'INSTALL_TOKEN_EXPIRED',
  'INSTALL_TOKEN_INVALID',
  'LOCATION_ALREADY_REGISTERED',
]);
const LIFECYCLE_BLOCKING_CODE_MAP: Record<string, LocationBootstrapNextAction> = {
  LOCATION_CLEANUP_IN_PROGRESS: 'show_blocked',
  LOCATION_ONBOARDING_EXPIRED: 'complete_registration',
  LOCATION_REGISTRATION_REQUIRED: 'complete_registration',
  TOKEN_ONLY: 'complete_registration',
  LOCATION_INSTALL_PENDING: 'show_retry',
  LOCATION_NOT_INSTALLED: 'show_not_installed',
  LOCATION_UNINSTALLED: 'show_not_installed',
  LOCATION_COMPANY_MISMATCH: 'show_blocked',
  LOCATION_INACTIVE: 'show_blocked',
  GHL_RECONNECT_REQUIRED: 'show_reconnect',
  INSTALL_TOKEN_EXPIRED: 'complete_registration',
  INSTALL_TOKEN_INVALID: 'complete_registration',
  LOCATION_ALREADY_REGISTERED: 'complete_registration',
  cleanup_in_progress: 'show_blocked',
  onboarding_expired: 'complete_registration',
  install_pending: 'show_retry',
  app_uninstalled: 'show_not_installed',
  not_installed: 'show_not_installed',
};

const sleep = (ms: number): Promise<void> => new Promise((resolve) => window.setTimeout(resolve, ms));

const createRequestId = (): string => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
};

export const isValidGhlLocationId = (value: unknown): value is string => {
  const normalized = normalizeLocationCandidate(value);
  if (!normalized) return false;
  return /^[A-Za-z0-9_-]{12,80}$/.test(normalized);
};

const publishBootstrapState = (state: LocationBootstrapState): LocationBootstrapState => {
  if (state.locationId) {
    bootstrapStateByLocation.set(state.locationId, state);
    if (state.status === 'ready') {
      try {
        safeStorage.setItem(`nola_bootstrap_state_${state.locationId}`, JSON.stringify(state));
      } catch {
        // ignore storage errors
      }
    }
  }

  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(BOOTSTRAP_EVENT, { detail: state }));
  }

  return state;
};

export const clearLocationBootstrapState = (locationId?: string): void => {
  const normalized = normalizeLocationCandidate(locationId);
  if (normalized) {
    bootstrapStateByLocation.delete(normalized);
    bootstrapInFlight.delete(normalized);
    safeStorage.removeItem(`nola_bootstrap_state_${normalized}`);
    return;
  }

  bootstrapStateByLocation.clear();
  bootstrapInFlight.clear();
};

export const getCachedLocationBootstrapState = (locationId?: string): LocationBootstrapState => {
  const normalized = normalizeLocationCandidate(locationId);
  if (!normalized) return { status: 'idle' };

  const inMemory = bootstrapStateByLocation.get(normalized);
  if (inMemory) return inMemory;

  try {
    const persistedRaw = safeStorage.getItem(`nola_bootstrap_state_${normalized}`);
    if (persistedRaw) {
      const parsed = JSON.parse(persistedRaw) as LocationBootstrapState;
      if (parsed && parsed.status === 'ready' && parsed.locationId === normalized) {
        bootstrapStateByLocation.set(normalized, parsed);
        return parsed;
      }
    }
  } catch {
    // ignore parse errors
  }

  return { status: 'idle', locationId: normalized };
};

export const addLocationBootstrapListener = (listener: (state: LocationBootstrapState) => void): (() => void) => {
  if (typeof window === 'undefined') return () => {};

  const handle = (event: Event) => {
    listener((event as CustomEvent<LocationBootstrapState>).detail);
  };

  window.addEventListener(BOOTSTRAP_EVENT, handle);
  return () => window.removeEventListener(BOOTSTRAP_EVENT, handle);
};

const bootstrapAllowsProtectedData = (response: LocationBootstrapResponse): boolean =>
  response.code === 'LOCATION_READY' &&
  response.next_action === 'load_app' &&
  response.contacts_can_load === true;

const bootstrapMessage = (response?: LocationBootstrapResponse): string => {
  if (!response) return 'Unable to verify this workspace right now.';
  return response.message || response.error || response.code || 'Unable to verify this workspace right now.';
};

const autologinFailureNextAction = (code?: string): LocationBootstrapNextAction | string => {
  if (code === 'DUPLICATE_LOCATION_USERS' || code === 'DUPLICATE_LOCATION_USER_IDENTITY') return 'show_admin_setup';
  if (code === 'LOCATION_USER_NOT_LINKED') return 'complete_registration';
  return 'show_retry';
};

const shouldRetryBootstrap = (response: LocationBootstrapResponse): boolean => {
  const code = response.code || '';
  if (STOP_RETRY_CODES.has(code)) return false;
  return response.next_action === 'show_retry' || RETRYABLE_CODES.has(code);
};

const normalizeLifecycleCode = (value: unknown): string | undefined => {
  if (typeof value !== 'string') return undefined;
  const trimmed = value.trim();
  return trimmed || undefined;
};

const lifecycleNextActionForCode = (code?: string): LocationBootstrapNextAction | undefined =>
  code ? LIFECYCLE_BLOCKING_CODE_MAP[code] : undefined;

export const isLifecycleBlockingCode = (value: unknown): boolean =>
  Boolean(lifecycleNextActionForCode(normalizeLifecycleCode(value)));

export const publishLifecycleBlockFromPayload = (
  locationId: string,
  payload: unknown,
  fallbackStatus = 423,
): LocationBootstrapState | null => {
  if (!payload || typeof payload !== 'object') return null;
  const record = payload as Record<string, unknown>;
  const code = normalizeLifecycleCode(record.code) || normalizeLifecycleCode(record.error);
  const nextAction = lifecycleNextActionForCode(code);
  if (!nextAction) return null;

  const response: LocationBootstrapResponse = {
    ...(record as LocationBootstrapResponse),
    location_id: normalizeLocationCandidate(record.location_id) || locationId,
    contacts_can_load: false,
    next_action: typeof record.next_action === 'string' ? record.next_action : nextAction,
    code,
    message: typeof record.message === 'string'
      ? record.message
      : typeof record.error === 'string'
        ? record.error
        : fallbackStatus === 423
          ? 'This workspace is temporarily unavailable.'
          : 'This workspace is not ready.',
  };

  return publishBootstrapState({ status: 'action_required', locationId, response });
};

const normalizeBootstrapFailure = (
  response: Response,
  data: LocationBootstrapResponse | null,
  locationId: string,
): LocationBootstrapResponse => ({
  ...(data || {}),
  location_id: data?.location_id || locationId,
  contacts_can_load: false,
  next_action: data?.next_action || lifecycleNextActionForCode(data?.code) || 'show_retry',
  code: data?.code || 'LOCATION_BOOTSTRAP_FAILED',
  message: data?.message || data?.error || response.statusText || 'Unable to verify this workspace right now.',
});

const requestBootstrap = async (path: string, locationId: string): Promise<{ response: Response; data: LocationBootstrapResponse | null }> => {
  const params = new URLSearchParams({ location_id: locationId });
  const headers = new Headers({
    'Content-Type': 'application/json',
    'X-GHL-Location-ID': locationId,
    'X-Request-ID': createRequestId(),
  });
  const token = getStoredAuthToken();
  if (token) headers.set('Authorization', `Bearer ${token}`);

  const response = await fetch(`${path}?${params.toString()}`, {
    method: 'GET',
    headers,
  });
  const data = await response.json().catch(() => null) as LocationBootstrapResponse | null;
  return { response, data };
};

const fetchLocationBootstrapOnce = async (locationId: string): Promise<LocationBootstrapResponse> => {
  let result = await requestBootstrap('/api/v2/location/bootstrap', locationId);
  if (result.response.status === 404 || result.response.status === 405) {
    result = await requestBootstrap('/api/location/bootstrap', locationId);
  }

  if (!result.response.ok) {
    return normalizeBootstrapFailure(result.response, result.data, locationId);
  }

  return {
    ...(result.data || {}),
    location_id: result.data?.location_id || locationId,
  };
};

const fetchLocationBootstrap = async (locationId: string): Promise<LocationBootstrapResponse> => {
  let response = await fetchLocationBootstrapOnce(locationId);

  for (const delay of BOOTSTRAP_RETRY_DELAYS_MS) {
    if (!shouldRetryBootstrap(response)) break;
    await sleep(delay);
    response = await fetchLocationBootstrapOnce(locationId);
  }

  return response;
};

const runBootstrapFlow = async (
  locationId: string,
  options: { allowAutologin?: boolean } = {},
): Promise<LocationBootstrapState> => {
  publishBootstrapState({ status: 'checking', locationId });

  try {
    let response = await fetchLocationBootstrap(locationId);

    if (
      options.allowAutologin !== false &&
      (response.next_action === 'run_autologin' || response.code === 'GHL_AUTOLOGIN_REQUIRED' || response.code === 'LOCATION_SESSION_MISMATCH')
    ) {
      const refreshed = await refreshGhlSessionForLocation(locationId);
      if (refreshed) {
        response = await fetchLocationBootstrap(locationId);
      } else {
        const failure = getLastGhlSessionRefreshFailure(locationId);
        if (failure?.code) {
          response = {
            ...response,
            location_id: locationId,
            contacts_can_load: false,
            requires_autologin: false,
            next_action: autologinFailureNextAction(failure.code),
            code: failure.code,
            message: failure.message || failure.error || response.message,
            error: failure.error || failure.message || response.error,
          };
        }
      }
    }

    if (bootstrapAllowsProtectedData(response)) {
      return publishBootstrapState({ status: 'ready', locationId, response });
    }

    return publishBootstrapState({ status: 'action_required', locationId, response });
  } catch (error) {
    return publishBootstrapState({
      status: 'error',
      locationId,
      message: error instanceof Error ? error.message : 'Unable to verify this workspace right now.',
      response: {
        location_id: locationId,
        contacts_can_load: false,
        next_action: 'show_retry',
        code: 'LOCATION_BOOTSTRAP_FAILED',
      },
    });
  }
};

export const resolveLocationBootstrap = async (
  locationId: string,
  options: { force?: boolean; allowAutologin?: boolean } = {},
): Promise<LocationBootstrapState> => {
  const normalized = normalizeLocationCandidate(locationId);
  if (!normalized || !isValidGhlLocationId(normalized)) {
    return publishBootstrapState({
      status: 'error',
      locationId: normalized || '',
      message: 'Invalid GHL location id.',
      response: {
        location_id: normalized || '',
        contacts_can_load: false,
        next_action: 'show_retry',
        code: 'INVALID_GHL_LOCATION_ID',
      },
    });
  }

  const cached = bootstrapStateByLocation.get(normalized);
  if (!options.force && cached?.status === 'ready') return cached;

  const existing = bootstrapInFlight.get(normalized);
  if (existing && !options.force) return existing;

  const promise = runBootstrapFlow(normalized, options).finally(() => {
    bootstrapInFlight.delete(normalized);
  });

  bootstrapInFlight.set(normalized, promise);
  return promise;
};

export const ensureLocationBootstrapAllowsProtectedData = async (
  locationId = getCurrentGhlContextLocationId(),
  options: { force?: boolean; allowAutologin?: boolean } = {},
): Promise<boolean> => {
  const normalized = normalizeLocationCandidate(locationId);
  if (!normalized) return false;

  const state = await resolveLocationBootstrap(normalized, options);
  return state.status === 'ready';
};

export const createBootstrapBlockedResponse = (state: LocationBootstrapState): Response => {
  const response = state.status === 'ready' ? state.response : 'response' in state ? state.response : undefined;
  return new Response(
    JSON.stringify({
      error: bootstrapMessage(response),
      code: response?.code || 'LOCATION_BOOTSTRAP_REQUIRED',
      next_action: response?.next_action || 'show_retry',
      requires_reconnect: response?.requires_reconnect === true,
      requires_reauth: response?.requires_autologin === true,
      contacts_can_load: false,
      request_id: response?.request_id || null,
      location_id: response?.location_id || state.locationId || null,
    }),
    { status: response?.requires_reconnect ? 409 : 423, headers: { 'Content-Type': 'application/json' } }
  );
};