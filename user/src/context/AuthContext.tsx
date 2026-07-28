import { devLog } from '../utils/devLog';
import React, { createContext, useState, useCallback, useEffect } from 'react';
import {
  getSession,
  saveSession,
  clearSession,
  SESSION_KEYS,
  type AuthSession,
  type LoginResponse,
} from '../services/authService';
import { safeStorage } from '../utils/safeStorage';
import { sessionSafeStorage } from '../utils/sessionSafeStorage';
import { persistActiveGhlLocation } from '../utils/ghlLocationStorage';
import { normalizeLocationCandidate } from '../utils/ghlLocationDetection';
import { GHL_CUSTOM_MENU_LINK_ID } from '../config';

// ── Types ────────────────────────────────────────────────────────────────────
interface AuthContextValue extends Partial<AuthSession> {
  isAuthenticated: boolean;
  isAgency:  boolean;
  isUser:    boolean;
  login:     (data: LoginResponse) => void;
  logout:    () => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

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

const persistSessionHintsFromToken = (token: string) => {
  const payload = decodeJwtPayload(token);
  if (!payload) return;

  const role = typeof payload.role === 'string' ? payload.role : 'user';
  const companyId =
    typeof payload.company_id === 'string' ? payload.company_id :
    typeof payload.companyId === 'string' ? payload.companyId :
    null;
  const locationId =
    typeof payload.location_id === 'string' ? payload.location_id :
    typeof payload.locationId === 'string' ? payload.locationId :
    typeof payload.active_location_id === 'string' ? payload.active_location_id :
    null;

  safeStorage.setItem(SESSION_KEYS.role, role);

  if (companyId) {
    safeStorage.setItem(SESSION_KEYS.companyId, companyId);
  }

  if (locationId) {
    safeStorage.setItem(SESSION_KEYS.locationId, locationId);
    safeStorage.setItem('nola_settings_account', JSON.stringify({
      ghlLocationId: locationId,
      displayName: '',
    }));
  }
};

const persistTokenForNewTabs = (token: string) => {
  sessionSafeStorage.setItem(SESSION_KEYS.token, token);
  safeStorage.setItem(SESSION_KEYS.token, token);
  try { sessionStorage.setItem(SESSION_KEYS.token, token); } catch { /* storage may be blocked in embedded views */ }
  try { localStorage.setItem(SESSION_KEYS.token, token); } catch { /* storage may be blocked in embedded views */ }
  persistSessionHintsFromToken(token);
};

// ── Provider ─────────────────────────────────────────────────────────────────
export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [session, setSession] = useState<AuthSession | null>(() => {
    // Check URL for token first to bypass iframe localStorage restrictions
    try {
      const isInIframe = typeof window !== 'undefined' && window.self !== window.top;

      // Sanitize URL on Top-Level Load (window.self === window.top)
      if (!isInIframe && typeof window !== 'undefined') {
        const urlParams = new URLSearchParams(window.location.search);
        let pathName = window.location.pathname;
        let urlModified = false;

        if (urlParams.has('post_auth_redirect')) {
          urlParams.delete('post_auth_redirect');
          urlModified = true;
        }

        if (pathName.includes('/v2/location/') || pathName.startsWith('/v2/')) {
          // ── Extract locationId from path BEFORE wiping it ─────────────────
          // Pattern: /v2/location/{locationId}/custom-page-link/{linkId}
          // The locationId must be extracted and persisted here because
          // replaceState('/') runs synchronously, making it unreadable by
          // ghlLocationDetection later in the bootstrap flow.
          try {
            const v2LocationMatch = pathName.match(/\/v2\/location\/([^/]+)/);
            if (v2LocationMatch) {
              const rawSegment = decodeURIComponent(v2LocationMatch[1]);
              // Exclude the custom menu link ID itself from being treated as a location ID
              if (rawSegment !== GHL_CUSTOM_MENU_LINK_ID) {
                const extractedLocationId = normalizeLocationCandidate(rawSegment);
                if (extractedLocationId) {
                  safeStorage.setItem(SESSION_KEYS.locationId, extractedLocationId);
                  persistActiveGhlLocation(extractedLocationId);
                  devLog.log('[AuthContext] Extracted locationId from /v2/location/ path:', extractedLocationId);
                }
              }
            }
          } catch (extractErr) {
            devLog.error('[AuthContext] Failed to extract locationId from path:', extractErr);
          }

          pathName = '/';
          urlModified = true;
        }

        if (urlModified) {
          const newSearch = urlParams.toString();
          const cleanUrl = `${pathName}${newSearch ? `?${newSearch}` : ''}${window.location.hash}`;
          window.history.replaceState({}, document.title, cleanUrl);
        }
      }

      const params = new URLSearchParams(window.location.search);
      const urlToken = params.get('token');

      if (urlToken) {
        Object.values(SESSION_KEYS).forEach(k => {
          safeStorage.removeItem(k);
          sessionSafeStorage.removeItem(k);
        });
        safeStorage.removeItem('nola_user');
        safeStorage.removeItem('nola_settings_account');
        // Store token in every available browser scope so new tabs can restore the session.
        persistTokenForNewTabs(urlToken);

        // Clean up token + post_auth_redirect from URL so they don't leak.
        const postAuthRedirect = params.get('post_auth_redirect') || '';
        params.delete('token');
        params.delete('post_auth_redirect');
        const nextQuery = params.toString();
        const nextUrl = `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash}`;
        window.history.replaceState({}, document.title, nextUrl);

        // If there's a post-auth redirect destination (e.g. GHL embedded page or standalone dashboard),
        // check iframe status before redirecting to GHL.
        if (postAuthRedirect) {
          if (isInIframe) {
            // Embedded in GHL -> Deep-link inside GHL
            try {
              if (window.top) {
                window.top.location.href = postAuthRedirect;
              } else {
                window.location.href = postAuthRedirect;
              }
            } catch {
              window.location.href = postAuthRedirect;
            }
          } else {
            // Standalone browser tab -> Stay in standalone dashboard
          }
        }
      }
    } catch (e) {
      devLog.error("[AuthContext] Error parsing URL token:", e);
    }

    const initialSession = getSession();
    return initialSession;
  });

  useEffect(() => {
    if (!session?.token) return;
    persistTokenForNewTabs(session.token);
  }, [session?.token]);

  const login = useCallback((data: LoginResponse) => {
    saveSession(data);
    setSession(getSession());
  }, []);

  const logout = useCallback(() => {
    clearSession();
    setSession(null);
    window.location.href = '/login';
  }, []);

  const value: AuthContextValue = {
    ...session,
    isAuthenticated: !!session?.token,
    isAgency:  session?.role === 'agency',
    isUser:    session?.role === 'user',
    login,
    logout,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};

// ── Hook ─────────────────────────────────────────────────────────────────────
export const useAuth = () => {
  const context = React.useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};
