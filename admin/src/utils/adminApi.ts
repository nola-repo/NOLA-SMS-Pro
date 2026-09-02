import { getAdminAuthHeaders } from './adminAuthHeaders';
import { apiFetch, withRequestId } from './apiFetch';

export const ADMIN_AUTH_REQUIRED_EVENT = 'nola-admin-auth-required';

const AUTH_401_MESSAGES = [
  'admin token missing',
  'admin token invalid or expired',
];

const INACTIVE_403_MESSAGES = [
  'admin account is inactive or no longer exists',
];

const normalizeMessage = (message: unknown) =>
  String(message ?? '').trim().toLowerCase();

const responseMessage = async (response: Response): Promise<string> => {
  const clone = response.clone();

  try {
    const body = await clone.json();
    return normalizeMessage(body?.message ?? body?.error ?? body?.status);
  } catch {
    try {
      return normalizeMessage(await response.clone().text());
    } catch {
      return '';
    }
  }
};

const getAdminAuthRequiredReason = async (response: Response) => {
  if (response.status !== 401 && response.status !== 403) return null;

  const message = await responseMessage(response);

  if (response.status === 401 && AUTH_401_MESSAGES.some(expected => message.includes(expected))) {
    return 'Your admin session expired or could not be verified. Please sign in again to continue.';
  }

  if (response.status === 403 && INACTIVE_403_MESSAGES.some(expected => message.includes(expected))) {
    return 'Your admin account is inactive or no longer has access. Contact a super admin if this looks wrong.';
  }

  return null;
};

const emitAdminAuthRequired = (message: string) => {
  window.dispatchEvent(new CustomEvent(ADMIN_AUTH_REQUIRED_EVENT, { detail: { message } }));
};

const mergeAdminHeaders = (headers?: HeadersInit) => {
  const merged = withRequestId(headers);

  Object.entries(getAdminAuthHeaders()).forEach(([key, value]) => {
    if (!merged.has(key)) merged.set(key, value);
  });

  return merged;
};

export const adminFetch = async (input: RequestInfo | URL, init: RequestInit = {}) => {
  const response = await apiFetch(input, {
    ...init,
    headers: mergeAdminHeaders(init.headers),
  });

  const authRequiredReason = await getAdminAuthRequiredReason(response);
  if (authRequiredReason) {
    emitAdminAuthRequired(authRequiredReason);
  }

  return response;
};
