import { detectLocationFromCurrentUrl, normalizeLocationCandidate } from './ghlLocationDetection';
import { buildGhlAutologinPayload } from './ghlIframeContext';
import { persistActiveGhlLocation, readActiveGhlLocation } from './ghlLocationStorage';
import { safeStorage } from './safeStorage';
import { sessionSafeStorage } from './sessionSafeStorage';
import { getAccountSettings, saveAccountSettings } from './settingsStorage';

const SESSION_KEYS = {
  token: 'nola_auth_token',
  role: 'nola_auth_role',
  companyId: 'nola_company_id',
  locationId: 'nola_location_id',
  user: 'nola_auth_user',
} as const;

const AUTH_SESSION_UPDATED_EVENT = 'nola-auth-session-updated';
const AUTH_SESSION_REFRESH_FAILED_EVENT = 'nola-auth-session-refresh-failed';

type StoredUser = Record<string, unknown> | null;

export interface SessionRefreshFailure {
  locationId: string;
  status: number;
  code?: string;
  error?: string;
  message?: string;
  requires_reauth?: boolean;
  requires_login?: boolean;
  requires_account_link?: boolean;
}

interface SessionRefreshResponse {
  token?: string;
  role?: string;
  companyId?: string | null;
  company_id?: string | null;
  locationId?: string | null;
  location_id?: string | null;
  active_location_id?: string | null;
  user?: Record<string, unknown> | null;
  code?: string;
  error?: string;
  message?: string;
  requires_reauth?: boolean;
  requires_login?: boolean;
  requires_account_link?: boolean;
}

const reauthInFlight = new Map<string, Promise<boolean>>();
const reauthFailureByLocation = new Map<string, SessionRefreshFailure>();
const autologinAttemptedThisLoad = new Set<string>();

const decodeJwtPayload = (token: string): Record<string, unknown> | null => {
  try {
    const payload = token.split('.')[1];
    if (!payload) return null;
    const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
    return JSON.parse(atob(padded)) as Record<string, unknown>;
  } catch {
    return null;
  }
};

const firstNonEmptyString = (...values: unknown[]): string | null => {
  for (const value of values) {
    if (typeof value === 'string' && value.trim()) return value.trim();
    if (typeof value === 'number') return String(value);
  }
  return null;
};

const firstLocationString = (...values: unknown[]): string | null => {
  for (const value of values) {
    const normalized = normalizeLocationCandidate(value);
    if (normalized) return normalized;
  }
  return null;
};

const parseStoredUser = (): StoredUser => {
  for (const key of [SESSION_KEYS.user, 'nola_user']) {
    try {
      const parsed = JSON.parse(safeStorage.getItem(key) || 'null');
      if (parsed && typeof parsed === 'object') return parsed as Record<string, unknown>;
    } catch {
      // Ignore bad cached profile payloads.
    }
  }

  return null;
};

export const getStoredAuthToken = (): string =>
  sessionSafeStorage.getItem(SESSION_KEYS.token) ||
  safeStorage.getItem(SESSION_KEYS.token) ||
  '';

export const getCurrentGhlContextLocationId = (): string => {
  const fromCurrentUrl = detectLocationFromCurrentUrl()?.locationId;
  if (fromCurrentUrl) return fromCurrentUrl;

  return readActiveGhlLocation();
};

export const getRequestedGhlLocationId = (): string => {
  const fromCurrentGhlContext = getCurrentGhlContextLocationId();
  if (fromCurrentGhlContext) return fromCurrentGhlContext;

  try {
    return (
      normalizeLocationCandidate(safeStorage.getItem(SESSION_KEYS.locationId)) ||
      normalizeLocationCandidate(getAccountSettings().ghlLocationId) ||
      ''
    );
  } catch {
    return normalizeLocationCandidate(safeStorage.getItem(SESSION_KEYS.locationId)) || '';
  }
};

export const getStoredSessionLocationId = (): string => {
  const token = getStoredAuthToken();
  const payload = token ? decodeJwtPayload(token) : null;
  const storedUser = parseStoredUser();

  return (
    firstLocationString(
      storedUser?.active_location_id,
      storedUser?.location_id,
      payload?.active_location_id,
      payload?.location_id,
      payload?.locationId
    ) || ''
  );
};

export const storedSessionMatchesLocation = (locationId: string): boolean => {
  const requestedLocationId = normalizeLocationCandidate(locationId);
  if (!requestedLocationId) return true;
  return getStoredSessionLocationId() === requestedLocationId;
};

const clearStaleAuthSessionForLocation = (locationId: string): void => {
  const requestedLocationId = normalizeLocationCandidate(locationId);
  if (!requestedLocationId || !getStoredAuthToken() || storedSessionMatchesLocation(requestedLocationId)) return;

  Object.values(SESSION_KEYS).forEach((key) => {
    safeStorage.removeItem(key);
    sessionSafeStorage.removeItem(key);
  });
  safeStorage.removeItem('nola_user');
  safeStorage.removeItem('nola_settings_account');

  safeStorage.setItem(SESSION_KEYS.locationId, requestedLocationId);
  persistActiveGhlLocation(requestedLocationId);
};

const createRequestId = (): string => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

const saveRefreshedSession = (data: SessionRefreshResponse, requestedLocationId: string): void => {
  if (!data.token) return;

  Object.values(SESSION_KEYS).forEach((key) => {
    safeStorage.removeItem(key);
    sessionSafeStorage.removeItem(key);
  });
  safeStorage.removeItem('nola_user');
  safeStorage.removeItem('nola_settings_account');

  sessionSafeStorage.setItem(SESSION_KEYS.token, data.token);
  safeStorage.setItem(SESSION_KEYS.token, data.token);

  const payload = decodeJwtPayload(data.token);
  const role = firstNonEmptyString(data.role, payload?.role) || 'user';
  const companyId = firstNonEmptyString(
    data.company_id,
    data.companyId,
    payload?.company_id,
    payload?.companyId,
    data.user?.company_id
  );
  const locationId =
    firstLocationString(
      data.location_id,
      data.locationId,
      data.active_location_id,
      payload?.active_location_id,
      payload?.location_id,
      payload?.locationId,
      data.user?.active_location_id,
      data.user?.location_id
    ) || requestedLocationId;

  safeStorage.setItem(SESSION_KEYS.role, role);
  if (companyId) safeStorage.setItem(SESSION_KEYS.companyId, companyId);
  if (locationId) safeStorage.setItem(SESSION_KEYS.locationId, locationId);

  if (data.user) {
    const user = {
      ...data.user,
      ...(companyId ? { company_id: companyId } : {}),
      ...(locationId ? { location_id: locationId, active_location_id: data.user.active_location_id || locationId } : {}),
    };
    safeStorage.setItem(SESSION_KEYS.user, JSON.stringify(user));
    safeStorage.setItem('nola_user', JSON.stringify(user));
  } else {
    safeStorage.removeItem(SESSION_KEYS.user);
  }

  if (locationId) {
    persistActiveGhlLocation(locationId);
    const current = getAccountSettings();
    saveAccountSettings({
      ...current,
      ghlLocationId: locationId,
      displayName: (data.user?.location_name as string | undefined) || current.displayName,
    });
  }

  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(AUTH_SESSION_UPDATED_EVENT, { detail: { locationId, role } }));
  }
};

const fetchGhlAutologin = async (locationId: string): Promise<Response> => {
  const payload = buildGhlAutologinPayload(locationId);
  const params = new URLSearchParams(payload);
  const requestHeaders = {
    'Content-Type': 'application/json',
    'X-GHL-Location-ID': locationId,
    'X-Request-ID': createRequestId(),
  };

  const v2Response = await fetch(`/api/v2/auth/ghl_autologin?${params.toString()}`, {
    method: 'GET',
    headers: requestHeaders,
  });

  if (v2Response.status !== 404 && v2Response.status !== 405) {
    return v2Response;
  }

  const legacyParams = new URLSearchParams({ location_id: locationId });
  return fetch(`/api/auth/ghl_autologin?${legacyParams.toString()}`, {
    method: 'POST',
    headers: requestHeaders,
    body: JSON.stringify(payload),
  });
};

const runGhlAutologin = async (locationId: string): Promise<boolean> => {
  if (autologinAttemptedThisLoad.has(locationId)) {
    const failure: SessionRefreshFailure = {
      locationId,
      status: 429,
      code: 'GHL_AUTOLOGIN_ATTEMPTED',
      message: 'Automatic GHL login has already been attempted for this location on this page load.',
      requires_reauth: true,
    };
    reauthFailureByLocation.set(locationId, failure);
    return false;
  }

  autologinAttemptedThisLoad.add(locationId);
  const response = await fetchGhlAutologin(locationId);

  const data = await response.json().catch(() => null) as SessionRefreshResponse | null;
  if (response.ok && data?.token) {
    reauthFailureByLocation.delete(locationId);
    saveRefreshedSession(data, locationId);
    return true;
  }

  const failure: SessionRefreshFailure = {
    locationId,
    status: response.status,
    code: data?.code,
    error: data?.error,
    message: data?.message,
    requires_reauth: Boolean(data && 'requires_reauth' in data ? data.requires_reauth : false),
    requires_login: Boolean(data && 'requires_login' in data ? data.requires_login : false),
    requires_account_link: Boolean(data && 'requires_account_link' in data ? data.requires_account_link : false),
  };
  reauthFailureByLocation.set(locationId, failure);

  if (typeof window !== 'undefined') {
    window.dispatchEvent(
      new CustomEvent(AUTH_SESSION_REFRESH_FAILED_EVENT, {
        detail: failure,
      })
    );
  }

  return false;
};

export const getLastGhlSessionRefreshFailure = (locationId: string): SessionRefreshFailure | null => {
  const requestedLocationId = normalizeLocationCandidate(locationId);
  if (!requestedLocationId) return null;
  return reauthFailureByLocation.get(requestedLocationId) || null;
};

export const refreshGhlSessionForLocation = async (locationId: string): Promise<boolean> => {
  const requestedLocationId = normalizeLocationCandidate(locationId);
  if (!requestedLocationId) return false;
  clearStaleAuthSessionForLocation(requestedLocationId);
  return runGhlAutologin(requestedLocationId);
};

export const ensureGhlSessionForLocation = async (
  locationId = getCurrentGhlContextLocationId(),
  options: { force?: boolean } = {},
): Promise<boolean> => {
  const requestedLocationId = normalizeLocationCandidate(locationId);
  if (!requestedLocationId) return true;

  if (!options.force && getStoredAuthToken() && storedSessionMatchesLocation(requestedLocationId)) {
    return true;
  }

  const key = requestedLocationId;
  const existing = reauthInFlight.get(key);
  if (existing) return existing;

  clearStaleAuthSessionForLocation(requestedLocationId);

  const promise = runGhlAutologin(requestedLocationId)
    .catch(() => false)
    .finally(() => {
      reauthInFlight.delete(key);
    });

  reauthInFlight.set(key, promise);
  return promise;
};

export const isLocationSessionMismatchPayload = (payload: unknown): boolean => {
  if (!payload || typeof payload !== 'object') return false;
  const record = payload as Record<string, unknown>;
  return record.code === 'LOCATION_SESSION_MISMATCH' || record.requires_reauth === true;
};
