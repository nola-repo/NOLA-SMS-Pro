import { safeStorage } from '../utils/safeStorage';
import { sessionSafeStorage } from '../utils/sessionSafeStorage';
import { getAccountSettings, saveAccountSettings } from '../utils/settingsStorage';
import { apiFetch } from '../utils/apiFetch';
import { clearActiveGhlLocation, persistActiveGhlLocation } from '../utils/ghlLocationStorage';
/**
 * authService.ts
 * Centralized auth API calls and localStorage session management.
 * Used by both the user app and agency app (via token reads).
 */

const BASE = '/api/auth';

// Keys
export const SESSION_KEYS = {
  token:      'nola_auth_token',
  role:       'nola_auth_role',
  companyId:  'nola_company_id',
  locationId: 'nola_location_id',
  user:       'nola_auth_user',
} as const;

// Types
export interface AuthUser {
  name:  string;
  email: string;
  phone?: string;
  location_id?: string;
  active_location_id?: string;
  company_id?: string;
  location_name?: string;
  company_name?:  string;
}

export interface AuthSession {
  token:      string;
  role:       'agency' | 'user';
  companyId:  string | null;
  locationId: string | null;
  user:       AuthUser | null;
}

export interface LoginResponse extends AuthSession {
  company_id?:  string | null;
  location_id?: string | null;
  active_location_id?: string | null;
}

export interface RegisterPayload {
  firstName: string;
  lastName:  string;
  email:     string;
  phone:     string;
  password:  string;
  role:      'agency' | 'user';
}

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

const isTokenExpired = (token: string): boolean => {
  const payload = decodeJwtPayload(token);
  const exp = typeof payload?.exp === 'number' ? payload.exp : null;
  return exp !== null && exp * 1000 <= Date.now();
};

const firstString = (...values: unknown[]): string | null => {
  for (const value of values) {
    if (typeof value === 'string' && value.trim()) return value;
  }
  return null;
};

// Session helpers
export const getSession = (): AuthSession | null => {
  // Try getting token from sessionStorage first, then fall back to localStorage (safeStorage)
  let token = sessionSafeStorage.getItem(SESSION_KEYS.token);
  if (!token) {
    token = safeStorage.getItem(SESSION_KEYS.token);
    if (token) {
      sessionSafeStorage.setItem(SESSION_KEYS.token, token);
    }
  }
  if (!token || isTokenExpired(token)) return null;

  const payload = decodeJwtPayload(token);
  const storedUser = JSON.parse(safeStorage.getItem(SESSION_KEYS.user) ?? 'null');
  const role = safeStorage.getItem(SESSION_KEYS.role) || (typeof payload?.role === 'string' ? payload.role : 'user');
  const companyId = firstString(
    safeStorage.getItem(SESSION_KEYS.companyId),
    payload?.company_id,
    payload?.companyId,
    storedUser?.company_id
  );
  const locationId = firstString(
    safeStorage.getItem(SESSION_KEYS.locationId),
    payload?.location_id,
    payload?.locationId,
    payload?.active_location_id,
    storedUser?.location_id,
    storedUser?.active_location_id
  );

  if (role) safeStorage.setItem(SESSION_KEYS.role, role);
  if (companyId) safeStorage.setItem(SESSION_KEYS.companyId, companyId);
  if (locationId) safeStorage.setItem(SESSION_KEYS.locationId, locationId);

  return {
    token,
    role:       (role as 'agency' | 'user') ?? 'user',
    companyId,
    locationId,
    user:       storedUser,
  };
};

export const isAuthenticated = (): boolean =>
  Boolean(getSession()?.token);

export const saveSession = (data: LoginResponse): void => {
  // Remove all prior session keys
  Object.values(SESSION_KEYS).forEach(k => {
    safeStorage.removeItem(k);
    sessionSafeStorage.removeItem(k);
  });
  safeStorage.removeItem('nola_user');
  safeStorage.removeItem('nola_settings_account');

  // Token goes into both sessionStorage and localStorage (safeStorage)
  sessionSafeStorage.setItem(SESSION_KEYS.token, data.token);
  safeStorage.setItem(SESSION_KEYS.token, data.token);
  const payload = decodeJwtPayload(data.token);
  const role = firstString(data.role, payload?.role) || 'user';
  const companyId = firstString(data.company_id, data.companyId, payload?.company_id, payload?.companyId, data.user?.company_id);
  const locationId = firstString(
    data.location_id,
    data.locationId,
    data.active_location_id,
    payload?.location_id,
    payload?.locationId,
    payload?.active_location_id,
    data.user?.location_id,
    data.user?.active_location_id
  );

  safeStorage.setItem(SESSION_KEYS.role, role);

  if (companyId) {
    safeStorage.setItem(SESSION_KEYS.companyId, companyId);
  } else {
    safeStorage.removeItem(SESSION_KEYS.companyId);
  }

  if (locationId) {
    safeStorage.setItem(SESSION_KEYS.locationId, locationId);
  } else {
    safeStorage.removeItem(SESSION_KEYS.locationId);
  }

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
      displayName: data.user?.location_name || current.displayName,
    });
  }
  window.dispatchEvent(new CustomEvent('nola-auth-session-updated', { detail: { locationId, role } }));
};

export const clearAuthSession = (): void => {
  Object.values(SESSION_KEYS).forEach(k => {
    safeStorage.removeItem(k);
    sessionSafeStorage.removeItem(k);
  });
  safeStorage.removeItem('nola_user');
  safeStorage.removeItem('nola_settings_account');
  safeStorage.removeItem('nola_settings_api');
  safeStorage.removeItem('nola_settings_preferred_sender');
  clearActiveGhlLocation();
  // Clear GHL iframe flag so it doesn't bypass login on future visits (#11)
  safeStorage.removeItem('nola_is_ghl_frame');
  try { sessionStorage.removeItem('nola_is_ghl_frame'); } catch { /* ignore */ }
  try { localStorage.removeItem('nola_is_ghl_frame'); } catch { /* ignore */ }
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('nola-auth-session-updated', { detail: { cleared: true } }));
  }
};

export const clearSession = clearAuthSession;

export const redirectToLogin = (): void => {
  clearAuthSession();
  if (window.location.pathname !== '/login') {
    window.location.href = '/login';
  }
};

// API calls
export const login = async (email: string, password: string): Promise<LoginResponse> => {
  const res = await apiFetch(`${BASE}/login.php`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ email, password, remember_me: true }),
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.error ?? json.message ?? 'Login failed');
  saveSession(json);
  return json;
};

export const register = async (payload: RegisterPayload): Promise<{ status: string; message: string }> => {
  const res = await apiFetch(`${BASE}/register.php`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(payload),
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.error ?? json.message ?? 'Registration failed');
  return json;
};

export const linkCompany = async (companyId: string): Promise<void> => {
  const token = safeStorage.getItem(SESSION_KEYS.token);
  if (!token) throw new Error('Not authenticated');

  const res = await apiFetch('/api/agency/link_company.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ company_id: companyId }),
  });
  
  if (!res.ok) {
    let errorMsg = 'Failed to link Company ID';
    try {
      const data = await res.json();
      if (data.error) errorMsg = data.error;
    } catch {
      // Keep the generic message when the backend does not return JSON.
    }
    throw new Error(errorMsg);
  }

  // If successful, save it locally too
  safeStorage.setItem(SESSION_KEYS.companyId, companyId);
  safeStorage.setItem('nola_agency_id', companyId);
};

export const logout = (): void => {
  clearSession();
};
