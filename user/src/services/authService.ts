import { safeStorage } from '../utils/safeStorage';
/**
 * authService.ts
 * Centralized auth API calls and localStorage session management.
 * Used by both the user app and agency app (via token reads).
 */

const BASE = '/api/auth';

// ── Keys ────────────────────────────────────────────────────────────────────
export const SESSION_KEYS = {
  token:      'nola_auth_token',
  role:       'nola_auth_role',
  companyId:  'nola_company_id',
  locationId: 'nola_location_id',
  user:       'nola_auth_user',
} as const;

// ── Types ───────────────────────────────────────────────────────────────────
export interface AuthUser {
  firstName: string;
  lastName:  string;
  email:     string;
}

export interface AuthSession {
  token:      string;
  role:       'agency' | 'user';
  companyId:  string | null;
  locationId: string | null;
  user:       AuthUser | null;
}

export interface LoginResponse extends AuthSession {
  company_id:  string | null;
  location_id: string | null;
}

export interface RegisterPayload {
  firstName: string;
  lastName:  string;
  email:     string;
  phone:     string;
  password:  string;
  role:      'agency' | 'user';
}

// ── Session helpers ─────────────────────────────────────────────────────────
export const getSession = (): AuthSession | null => {
  const token = safeStorage.getItem(SESSION_KEYS.token);
  if (!token) return null;
  return {
    token,
    role:       (safeStorage.getItem(SESSION_KEYS.role) as 'agency' | 'user') ?? 'user',
    companyId:  safeStorage.getItem(SESSION_KEYS.companyId),
    locationId: safeStorage.getItem(SESSION_KEYS.locationId),
    user:       JSON.parse(safeStorage.getItem(SESSION_KEYS.user) ?? 'null'),
  };
};

export const isAuthenticated = (): boolean =>
  !!safeStorage.getItem(SESSION_KEYS.token);

export const saveSession = (data: LoginResponse): void => {
  safeStorage.setItem(SESSION_KEYS.token, data.token);
  safeStorage.setItem(SESSION_KEYS.role, data.role);
  if (data.company_id)  safeStorage.setItem(SESSION_KEYS.companyId,  data.company_id);
  if (data.location_id) safeStorage.setItem(SESSION_KEYS.locationId, data.location_id);
  if (data.user)        safeStorage.setItem(SESSION_KEYS.user, JSON.stringify(data.user));
};

export const clearSession = (): void => {
  Object.values(SESSION_KEYS).forEach(k => safeStorage.removeItem(k));
};

// ── API calls ───────────────────────────────────────────────────────────────
export const login = async (email: string, password: string): Promise<LoginResponse> => {
  const res = await fetch(`${BASE}/login.php`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ email, password }),
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.error ?? json.message ?? 'Login failed');
  saveSession(json);
  return json;
};

export const register = async (payload: RegisterPayload): Promise<{ status: string; message: string }> => {
  const res = await fetch(`${BASE}/register.php`, {
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

  const res = await fetch('/api/agency/link_company.php', {
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
    } catch (e) {}
    throw new Error(errorMsg);
  }

  // If successful, save it locally too
  safeStorage.setItem(SESSION_KEYS.companyId, companyId);
  safeStorage.setItem('nola_agency_id', companyId);
};

export const logout = (): void => {
  clearSession();
};
