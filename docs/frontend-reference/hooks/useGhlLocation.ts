import { useState, useEffect } from 'react';
import { getSession, SESSION_KEYS } from '../services/authService';
import { safeStorage } from '../utils/safeStorage';
import { getAccountSettings } from '../utils/settingsStorage';

function readUrlParam(keys: string[]): string | null {
  if (typeof window === 'undefined') return null;

  const search = window.location.search;
  const hash = window.location.hash;
  const getParam = (query: string, key: string) => new URLSearchParams(query).get(key);

  for (const key of keys) {
    const val = getParam(search, key);
    if (val && val.length > 4 && !val.includes('{{')) return val;
  }

  if (hash.includes('?')) {
    const hashQuery = hash.split('?')[1];
    for (const key of keys) {
      const val = getParam('?' + hashQuery, key);
      if (val && val.length > 4 && !val.includes('{{')) return val;
    }
  }

  return null;
}

export function useGhlLocation(): string | null {
  const [locationId, setLocationId] = useState<string | null>(() => {
    return (
      readUrlParam(['location_id', 'locationId', 'location', 'activeLocation']) ||
      getSession()?.locationId ||
      safeStorage.getItem(SESSION_KEYS.locationId) ||
      getAccountSettings().ghlLocationId ||
      null
    );
  });

  useEffect(() => {
    const fromUrl = readUrlParam(['location_id', 'locationId', 'location', 'activeLocation']);
    if (fromUrl && fromUrl !== locationId) {
      setLocationId(fromUrl);
      return;
    }

    const onLocationSet = (event: Event) => {
      const detail = (event as CustomEvent<{ locationId?: string }>).detail;
      if (detail?.locationId) setLocationId(detail.locationId);
    };

    window.addEventListener('ghl-location-set', onLocationSet);
    return () => window.removeEventListener('ghl-location-set', onLocationSet);
  }, [locationId]);

  return locationId;
}

export function useGhlCompany(): string | null {
  const [companyId, setCompanyId] = useState<string | null>(() => {
    return (
      readUrlParam(['companyId', 'company_id']) ||
      getSession()?.companyId ||
      safeStorage.getItem(SESSION_KEYS.companyId) ||
      null
    );
  });

  useEffect(() => {
    const fromUrl = readUrlParam(['companyId', 'company_id']);
    if (fromUrl && fromUrl !== companyId) {
      setCompanyId(fromUrl);
    }
  }, [companyId]);

  return companyId;
}

export function useIsInGhlIframe(): boolean {
  return typeof window !== 'undefined' && window.self !== window.top;
}
