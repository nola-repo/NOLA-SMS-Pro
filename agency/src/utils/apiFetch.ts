export function createRequestId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function withRequestId(headers?: HeadersInit): Headers {
  const merged = new Headers(headers);

  if (!merged.has('X-Request-ID')) {
    merged.set('X-Request-ID', createRequestId());
  }

  return merged;
}

const PUBLIC_AUTH_PATHS = [
  '/api/auth/login.php',
  '/api/auth/register.php',
  '/api/auth/forgot_password_otp.php',
  '/api/auth/reset_password_otp.php',
  '/api/agency/ghl_autologin',
  '/api/v2/agency/ghl_autologin',
  '/api/public/whitelabel',
  '/api/whitelabel.php',
];

const getRequestPath = (input: RequestInfo | URL): string => {
  const raw = typeof input === 'string'
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url;

  try {
    return new URL(raw, typeof window !== 'undefined' ? window.location.origin : 'http://localhost').pathname;
  } catch {
    return raw;
  }
};

const isPublicAuthRequest = (input: RequestInfo | URL): boolean => {
  const path = getRequestPath(input);
  return PUBLIC_AUTH_PATHS.some((publicPath) => path === publicPath || path.startsWith(publicPath + '/'));
};

const clearStoredAgencyAuthSession = (): void => {
  if (typeof window === 'undefined') return;
  const keys = [
    'nola_auth_token',
    'nola_auth_role',
    'nola_company_id',
    'nola_location_id',
    'nola_auth_user',
    'agency_token',
  ];
  keys.forEach((key) => {
    try { localStorage.removeItem(key); } catch {}
    try { sessionStorage.removeItem(key); } catch {}
  });
  try { localStorage.removeItem('nola_is_ghl_frame'); } catch {}
  try { sessionStorage.removeItem('nola_is_ghl_frame'); } catch {}
};

export async function apiFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const response = await fetch(input, {
    ...init,
    headers: withRequestId(init.headers),
  });

  if (response.status === 401 && !isPublicAuthRequest(input)) {
    let isInsideIframe = false;
    if (typeof window !== 'undefined') {
      try {
        isInsideIframe = window.self !== window.top || Boolean(sessionStorage.getItem('nola_is_ghl_frame'));
      } catch {
        isInsideIframe = true;
      }

      if (isInsideIframe) {
        window.dispatchEvent(new CustomEvent('nola-auth-session-expired', { detail: { reason: '401' } }));
      } else if (window.location.pathname !== '/login') {
        clearStoredAgencyAuthSession();
        window.location.assign('/login');
      }
    }
  }

  return response;
}

