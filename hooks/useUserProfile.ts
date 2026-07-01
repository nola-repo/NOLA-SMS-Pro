import { useState, useEffect, useCallback } from 'react';
import { AUTH_SESSION_EVENT, getSession, SESSION_KEYS } from '../services/authService';
import { safeStorage } from '../utils/safeStorage';

export interface UserProfile {
  name?: string;
  full_name?: string;
  firstName?: string;
  lastName?: string;
  email?: string;
  phone?: string;
  location_id?: string | null;
  location_name?: string | null;
  company_id?: string | null;
  company_name?: string | null;
  role?: string;
}

function getToken(): string | null {
  try {
    const sessionToken = getSession()?.token;
    if (sessionToken) return sessionToken;

    const rawToken = safeStorage.getItem(SESSION_KEYS.token);
    return rawToken || null;
  } catch {
    return null;
  }
}

function getRole(): 'agency' | 'user' {
  const sessionRole = getSession()?.role;
  if (sessionRole) return sessionRole;

  const stored = safeStorage.getItem(SESSION_KEYS.role);
  return stored === 'agency' ? 'agency' : 'user';
}

async function fetchProfile(token: string, role: 'agency' | 'user'): Promise<UserProfile | null> {
  const endpoint = role === 'agency' ? '/api/agency/profile.php' : '/api/auth/me.php';

  const res = await fetch(endpoint, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
  });

  if (!res.ok) return null;

  const json = await res.json();
  const profile = json.user ?? json.data ?? null;
  if (!profile || typeof profile !== 'object') return null;

  return profile as UserProfile;
}

function cacheProfile(profile: UserProfile): void {
  try {
    const serialized = JSON.stringify(profile);
    safeStorage.setItem(SESSION_KEYS.user, serialized);
    safeStorage.setItem('nola_user', serialized);
    safeStorage.setItem('nola_auth_user', serialized);
  } catch {
    // ignore cache write failures in restricted iframe storage
  }
}

export function useUserProfile(): UserProfile | null {
  const [profile, setProfile] = useState<UserProfile | null>(() => {
    try {
      const cached = safeStorage.getItem(SESSION_KEYS.user) ?? safeStorage.getItem('nola_user');
      return cached ? JSON.parse(cached) : null;
    } catch {
      return null;
    }
  });
  const [refreshVersion, setRefreshVersion] = useState(0);

  const refresh = useCallback(async () => {
    const token = getToken();
    if (!token) return;

    const role = getRole();
    const live = await fetchProfile(token, role);
    if (!live) return;

    cacheProfile(live);
    setProfile(live);
  }, []);

  useEffect(() => {
    const queueRefresh = () => setRefreshVersion(version => version + 1);
    if (typeof window === 'undefined') return;

    window.addEventListener(AUTH_SESSION_EVENT, queueRefresh);
    window.addEventListener('storage', queueRefresh);

    return () => {
      window.removeEventListener(AUTH_SESSION_EVENT, queueRefresh);
      window.removeEventListener('storage', queueRefresh);
    };
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh, refreshVersion]);

  return profile;
}
