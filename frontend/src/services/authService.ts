export const KEYS = {
  token: 'nola_auth_token',
  role:  'nola_auth_role',
  companyId: 'nola_company_id',
  locationId: 'nola_location_id',
  user: 'nola_auth_user',
};

import { safeStorage } from '../utils/safeStorage';

// Also support legacy keys for backward compatibility briefly if they exist
const MIGRATED_KEYS = {
  token: 'auth_token',
  user: 'auth_user'
};

const API_BASE = import.meta.env.VITE_API_BASE || '';

export const authService = {
  async register(payload: any) {
    const res = await fetch(`${API_BASE}/api/auth/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    return res.json();
  },

  async login(email: string, password: string) {
    const res = await fetch(`${API_BASE}/api/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password })
    });
    const data = await res.json();
    return data;
  },

  logout() {
    Object.values(KEYS).forEach(key => safeStorage.removeItem(key));
    Object.values(MIGRATED_KEYS).forEach(key => safeStorage.removeItem(key));
  },

  getSession() {
    try {
      const token = safeStorage.getItem(KEYS.token) || safeStorage.getItem(MIGRATED_KEYS.token);
      const userStr = safeStorage.getItem(KEYS.user) || safeStorage.getItem(MIGRATED_KEYS.user);
      
      if (!token || !userStr) return null;

      const user = JSON.parse(userStr);
      const role = safeStorage.getItem(KEYS.role) || user.role;
      const companyId = safeStorage.getItem(KEYS.companyId) || user.company_id || user.agency_id || null;
      const locationId = safeStorage.getItem(KEYS.locationId) || user.location_id || null;

      // Check token expiry
      const payloadB64 = token.split(".")[0];
      const payload = JSON.parse(atob(payloadB64.replace(/-/g, "+").replace(/_/g, "/")));
      if (payload.exp && payload.exp < Date.now() / 1000) {
        this.logout();
        return null;
      }

      return { token, user, role, companyId, locationId };
    } catch {
      return null;
    }
  },

  isAuthenticated() {
    return this.getSession() !== null;
  },

  async ghlAutoLogin(companyId: string) {
    const API_BASE_URL = import.meta.env.VITE_API_BASE || '';
    const res = await fetch(`${API_BASE_URL}/api/agency/ghl_autologin`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ company_id: companyId }),
    });
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}));
      throw new Error(errData.message || errData.error || `Auto-login failed (${res.status})`);
    }
    const data = await res.json();

    // Persist session — same keys as normal login
    safeStorage.setItem(KEYS.token, data.token);
    safeStorage.setItem(KEYS.role, data.role);
    if (data.company_id) safeStorage.setItem(KEYS.companyId, data.company_id);
    if (data.user) safeStorage.setItem(KEYS.user, JSON.stringify(data.user));

    return data;
  }
};
