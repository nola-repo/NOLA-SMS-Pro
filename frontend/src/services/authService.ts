export const KEYS = {
  token: 'nola_auth_token',
  role:  'nola_auth_role',
  companyId: 'nola_company_id',
  locationId: 'nola_location_id',
  user: 'nola_auth_user',
};

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
    Object.values(KEYS).forEach(key => localStorage.removeItem(key));
    Object.values(MIGRATED_KEYS).forEach(key => localStorage.removeItem(key));
  },

  getSession() {
    try {
      const token = localStorage.getItem(KEYS.token) || localStorage.getItem(MIGRATED_KEYS.token);
      const userStr = localStorage.getItem(KEYS.user) || localStorage.getItem(MIGRATED_KEYS.user);
      
      if (!token || !userStr) return null;

      const user = JSON.parse(userStr);
      const role = localStorage.getItem(KEYS.role) || user.role;
      const companyId = localStorage.getItem(KEYS.companyId) || user.company_id || user.agency_id || null;
      const locationId = localStorage.getItem(KEYS.locationId) || user.location_id || null;

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
  }
};
