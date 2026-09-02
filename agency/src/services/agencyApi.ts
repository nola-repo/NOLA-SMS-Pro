import { SESSION_KEYS } from './agencyAuthHelper';
import { safeStorage } from '../utils/safeStorage';
import { sessionSafeStorage } from '../utils/sessionSafeStorage';
import { apiFetch, withRequestId } from '../utils/apiFetch';

export const getAgencyToken = (): string => (
  sessionSafeStorage.getItem(SESSION_KEYS.token) ||
  safeStorage.getItem(SESSION_KEYS.token) ||
  sessionSafeStorage.getItem('agency_token') ||
  safeStorage.getItem('agency_token') ||
  ''
);

export const getAgencyAuthHeaders = (includeJson = false): Record<string, string> => {
  const token = getAgencyToken();
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };

  if (includeJson) {
    headers['Content-Type'] = 'application/json';
  }

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  return headers;
};

export const agencyFetch = (input: RequestInfo | URL, init: RequestInit = {}) => {
  const incomingHeaders = withRequestId(init.headers);
  const authHeaders = getAgencyAuthHeaders(
    Boolean(init.body) || incomingHeaders.has('Content-Type')
  );

  Object.entries(authHeaders).forEach(([key, value]) => {
    if (!incomingHeaders.has(key)) incomingHeaders.set(key, value);
  });

  return apiFetch(input, {
    ...init,
    headers: incomingHeaders,
  });
};
