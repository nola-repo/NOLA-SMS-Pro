import { devLog } from '../utils/devLog';
import { useCallback, useEffect, useState } from 'react';
import { getSession, SESSION_KEYS, redirectToLogin } from '../services/authService';
import { safeStorage } from '../utils/safeStorage';
import { getAccountSettings, saveAccountSettings } from '../utils/settingsStorage';
import { apiFetch } from '../utils/apiFetch';
import { detectLocationFromCurrentUrl } from '../utils/ghlLocationDetection';
import { persistActiveGhlLocation, readActiveGhlLocation } from '../utils/ghlLocationStorage';
import { useLocationId } from '../context/LocationContext';

const API_BASE = import.meta.env.VITE_API_BASE || '';

/**
 * Matches the Firestore `users/{uid}` document shape.
 * 1:1 mapping - one user account per GHL sub-account.
 * `location_memberships` is intentionally excluded.
 */
export interface UserProfile {
  // Personal info
  name: string;
  firstName?: string;
  lastName?: string;
  email: string;
  phone?: string;
  role?: string;
  active?: boolean;
  source?: string;
  created_at?: string;
  updated_at?: string;

  // Workspace / GHL info
  location_id?: string;        // normalised from active_location_id OR location_id
  active_location_id?: string; // raw Firestore field
  company_id?: string;
  location_name?: string;
  company_name?: string;
}

/**
 * Normalise a raw API/cache response to a clean UserProfile.
 * - Maps `active_location_id` to `location_id` when `location_id` is absent.
 * - Strips `location_memberships` (account is 1:1 with sub-account).
 */
function normalizeProfile(raw: Record<string, unknown>): UserProfile {
  const locationId =
    (raw.location_id as string | undefined) ||
    (raw.active_location_id as string | undefined) ||
    undefined;

  return {
    name:               (raw.name               as string) || '',
    firstName:          (raw.firstName           as string) || undefined,
    lastName:           (raw.lastName            as string) || undefined,
    email:              (raw.email               as string) || '',
    phone:              (raw.phone               as string) || undefined,
    role:               (raw.role                as string) || undefined,
    active:             raw.active !== undefined ? Boolean(raw.active) : undefined,
    source:             (raw.source              as string) || undefined,
    created_at:         (raw.created_at          as string) || undefined,
    updated_at:         (raw.updated_at          as string) || undefined,
    location_id:        locationId,
    active_location_id: (raw.active_location_id  as string) || undefined,
    company_id:         (raw.company_id          as string) || undefined,
    location_name:      (raw.location_name       as string) || undefined,
    company_name:       (raw.company_name        as string) || undefined,
  };
}

/**
 * Read the best available cached user from safeStorage.
 * Checks both keys: 'nola_auth_user' (written by authService/me endpoint)
 * and 'nola_user' (written by auth-handoff.html after GHL install).
 */
function getCachedUser(): UserProfile | null {
  try {
    const fromAuthUser = JSON.parse(safeStorage.getItem('nola_auth_user') || 'null');
    if (fromAuthUser?.email) {
      return normalizeProfile(fromAuthUser);
    }
    const fromNolaUser = JSON.parse(safeStorage.getItem('nola_user') || 'null');
    if (fromNolaUser?.email) {
      return normalizeProfile(fromNolaUser);
    }
  } catch (e) {
    devLog.error("[useUserProfile] Error parsing cached user:", e);
  }
  return null;
}

/**
 * Get the auth token from any known storage key.
 * auth-handoff.html writes 'nola_auth_token' directly to localStorage.
 * getSession() reads via safeStorage which also checks localStorage.
 */
function getToken(): string | null {
  try {
    const sessionToken = getSession()?.token;
    if (sessionToken) {
      return sessionToken;
    }
    // Fallback: safeStorage (works even when localStorage is blocked in GHL iframe)
    const rawToken = safeStorage.getItem(SESSION_KEYS.token);
    if (rawToken) {
      return rawToken;
    }
    return null;
  } catch (e) {
    devLog.error("[useUserProfile] Error getting token:", e);
    return null;
  }
}

export const useUserProfile = () => {
  const [user, setUser] = useState<UserProfile | null>(getCachedUser);
  const { locationId, isLocationResolving } = useLocationId();

  const appIsRunningInGhl = (() => {
    try {
      return window.self !== window.top || sessionStorage.getItem('nola_is_ghl_frame') === 'true';
    } catch {
      return true;
    }
  })();

  const fetchFreshProfile = useCallback(async () => {
    try {
      const token = getToken();
      if (!token) {
        if (appIsRunningInGhl && (isLocationResolving || locationId)) {
          devLog.warn('[useUserProfile] Waiting for GHL auto-login before fetching profile.');
        } else {
          devLog.warn('[useUserProfile] Cannot fetch profile, no token available.');
        }
        return;
      }

      const res = await apiFetch(`${API_BASE}/api/auth/me`, {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      });

      if (res.status === 401) {
        if (appIsRunningInGhl && (isLocationResolving || locationId)) {
          devLog.warn('[useUserProfile] /api/auth/me returned 401 while GHL session is still resolving. Waiting for auto-login retry.');
          return;
        }

        devLog.warn('[useUserProfile] Auth token expired or invalid. Clearing session.');
        redirectToLogin();
        return;
      }

      if (res.ok) {
        const data = await res.json();
        if (data.user) {
          const profile = normalizeProfile(data.user);
          setUser(profile);
          safeStorage.setItem('nola_auth_user', JSON.stringify(profile));
          safeStorage.setItem('nola_user', JSON.stringify(profile));

          if (profile.location_id) {
            const settings = getAccountSettings();
            const cachedUser = getCachedUser();
            const userChanged = cachedUser && cachedUser.email !== profile.email;
            const isStandardUser = profile.role === 'user';
            const activeGhlLocationId = detectLocationFromCurrentUrl()?.locationId || readActiveGhlLocation();
            const profileMatchesActiveGhl = !!activeGhlLocationId && activeGhlLocationId === profile.location_id;
            const canUseProfileLocation = !appIsRunningInGhl || profileMatchesActiveGhl;

            const shouldUseProfileLocation = canUseProfileLocation && (
              isStandardUser ||
              userChanged ||
              !settings.ghlLocationId ||
              settings.ghlLocationId === profile.location_id ||
              !appIsRunningInGhl
            );

            if (shouldUseProfileLocation) {
              persistActiveGhlLocation(profile.location_id);
              safeStorage.setItem('nola_location_id', profile.location_id);
              saveAccountSettings({
                ...settings,
                ghlLocationId: profile.location_id
              });

              window.dispatchEvent(
                new CustomEvent('ghl-location-set', { detail: { locationId: profile.location_id } })
              );
            } else if (appIsRunningInGhl && activeGhlLocationId && activeGhlLocationId !== profile.location_id) {
              devLog.warn('[useUserProfile] Skipped stale profile location while running in GHL.', {
                profileLocationId: profile.location_id,
                activeGhlLocationId,
              });
            }
          }
        }
      } else {
        devLog.error('[useUserProfile] Fetch failed. Status:', res.status, 'Text:', await res.text().catch(() => ''));
      }
    } catch (err) {
      devLog.error('[useUserProfile] Failed to fetch fresh user profile', err);
    } finally {
      window.dispatchEvent(new Event('nola-profile-sync-complete'));
    }
  }, [appIsRunningInGhl, isLocationResolving, locationId]);

  useEffect(() => {
    fetchFreshProfile();

    const handleSessionUpdated = () => {
      fetchFreshProfile();
    };

    window.addEventListener('nola-auth-session-updated', handleSessionUpdated);
    return () => window.removeEventListener('nola-auth-session-updated', handleSessionUpdated);
  }, [fetchFreshProfile]);

  return user;
};