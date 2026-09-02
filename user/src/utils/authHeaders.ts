/**
 * authHeaders.ts
 * Shared helper that builds the Authorization header from the current session.
 * Import and spread into every apiFetch() options.headers instead of hand-rolling
 * the Bearer token string in every API file.
 *
 * Usage:
 *   import { getAuthHeaders } from '../utils/authHeaders';
 *   const res = await apiFetch(url, {
 *     headers: { 'Content-Type': 'application/json', ...getAuthHeaders() },
 *   });
 */

import { getSession } from '../services/authService';

/**
 * Returns { Authorization: 'Bearer <token>' } when a token is present,
 * or an empty object when no token exists (e.g. GHL iframe auto-login).
 */
export const getAuthHeaders = (): Record<string, string> => {
  const token = getSession()?.token ?? null;
  return token ? { Authorization: `Bearer ${token}` } : {};
};
