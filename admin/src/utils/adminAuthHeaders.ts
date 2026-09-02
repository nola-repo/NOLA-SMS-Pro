const isJwtExpired = (token: string): boolean => {
  try {
    const payload = token.split('.')[1];
    if (!payload) return false;
    const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
    const decoded = JSON.parse(atob(padded));
    return typeof decoded.exp === 'number' && decoded.exp * 1000 <= Date.now();
  } catch {
    return false;
  }
};

export const getAdminAuthHeaders = (): Record<string, string> => {
  const token =
    sessionStorage.getItem('nola_admin_token') ||
    localStorage.getItem('nola_admin_token') ||
    '';

  if (token && !sessionStorage.getItem('nola_admin_token')) {
    sessionStorage.setItem('nola_admin_token', token);
    sessionStorage.setItem('nola_admin_auth', 'true');
    const rememberedUser = localStorage.getItem('nola_admin_user');
    if (rememberedUser) {
      sessionStorage.setItem('nola_admin_user', rememberedUser);
    }
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  if (token && !isJwtExpired(token)) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  return headers;
};
