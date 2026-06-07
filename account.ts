import { getSession, SESSION_KEYS } from './services/authService';
import { safeStorage } from './utils/safeStorage';

const WEBHOOK_SECRET = import.meta.env.VITE_WEBHOOK_SECRET ?? '';

export interface AccountProfile {
  location_id?: string;
  location_name?: string;
  name?: string;
  full_name?: string;
  firstName?: string;
  lastName?: string;
  email?: string;
  phone?: string;
  company_id?: string;
  company_name?: string;
  credit_balance?: number;
}

function getAuthToken(): string | null {
  return getSession()?.token ?? safeStorage.getItem(SESSION_KEYS.token);
}

function buildHeaders(locationId?: string): HeadersInit {
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };

  const token = getAuthToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (WEBHOOK_SECRET) headers['X-Webhook-Secret'] = WEBHOOK_SECRET;
  if (locationId) headers['X-GHL-Location-ID'] = locationId;

  return headers;
}

export async function fetchAccountProfile(locationId: string): Promise<AccountProfile | null> {
  if (!locationId) return null;

  const query = new URLSearchParams({ location_id: locationId });
  const res = await fetch(`/api/account.php?${query.toString()}`, {
    headers: buildHeaders(locationId),
  });

  if (!res.ok) return null;

  const json = await res.json();
  return json.data ?? json.user ?? null;
}

export async function fetchAgencyProfile(): Promise<AccountProfile | null> {
  const token = getAuthToken();
  if (!token) return null;

  const res = await fetch('/api/agency/profile.php', {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
  });

  if (!res.ok) return null;

  const json = await res.json();
  return json.user ?? json.data ?? null;
}
