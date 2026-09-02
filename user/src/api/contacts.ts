import { devLog } from '../utils/devLog';
import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";
import { getSession, redirectToLogin, type AuthSession } from "../services/authService";
import type { Contact } from "../types/Contact";
import { apiFetch } from "../utils/apiFetch";
import { fetchCachedJson, readQueryCache, removeQueryCache, type QueryCacheMeta, type QueryKeyParts } from "../utils/queryCache";

// Use the GHL contacts proxy endpoint (calls GoHighLevel API directly)
const CONTACTS_API_URL = API_CONFIG.ghl_contacts;

type JsonRecord = Record<string, unknown>;
type ErrorBody = JsonRecord | string | null;

export type FetchContactsResult =
  | { ok: true; contacts: Contact[]; cacheMeta?: QueryCacheMeta }
  | {
      ok: false;
      contacts: [];
      kind: 'reconnect' | 'other';
      message?: string;
      status: number;
    };

export class GhlReconnectError extends Error {
  status: number;

  constructor(message = 'GoHighLevel connection expired', status = 401) {
    super(message);
    this.name = 'GhlReconnectError';
    this.status = status;
    Object.setPrototypeOf(this, GhlReconnectError.prototype);
  }
}

export const isGhlReconnectError = (error: unknown): error is GhlReconnectError =>
  error instanceof GhlReconnectError;

const decodeJwtPayload = (token: string): JsonRecord | null => {
  try {
    const payload = token.split('.')[1];
    if (!payload) return null;
    const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
    const decoded = atob(padded);
    return JSON.parse(decoded) as JsonRecord;
  } catch {
    return null;
  }
};

const isJwtExpired = (token: string): boolean => {
  const payload = decodeJwtPayload(token);
  const exp = typeof payload?.exp === 'number' ? payload.exp : null;
  return exp !== null && exp * 1000 <= Date.now();
};

const getContactContext = (explicitLocationId?: string): {
  session: AuthSession | null;
  locationId: string;
  headers: Record<string, string>;
} | null => {
  const session = getSession();
  const accountSettings = getAccountSettings();
  const locationId = explicitLocationId || session?.locationId || accountSettings.ghlLocationId || '';

  if (session?.token && isJwtExpired(session.token)) {
    redirectToLogin();
    return null;
  }

  if (!locationId) return null;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-GHL-Location-ID': locationId,
  };

  if (session?.token) {
    headers.Authorization = `Bearer ${session.token}`;
  }

  return {
    session,
    locationId,
    headers,
  };
};

const isRecord = (value: unknown): value is JsonRecord =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const readJsonOrText = async (res: Response): Promise<ErrorBody> => {
  try {
    return await res.clone().json();
  } catch {
    try {
      const text = await res.clone().text();
      return text || null;
    } catch {
      return null;
    }
  }
};

const getErrorMessage = (body: ErrorBody, fallback: string): string => {
  if (typeof body === 'string') return body || fallback;
  if (!body) return fallback;
  const message = body.message;
  if (Array.isArray(message)) return message.map(String).join(', ');
  return String(message || body.details || body.error || fallback);
};

const isReconnectResponse = (status: number, body: ErrorBody): boolean =>
  status === 401 && isRecord(body) && body.requires_reconnect === true;

const handleUnauthorizedResponse = (status: number, body: ErrorBody, hasAuthToken: boolean) => {
  if (status === 401 && hasAuthToken && !isReconnectResponse(status, body)) {
    redirectToLogin();
  }
};

const asString = (value: unknown): string => {
  if (typeof value === 'string') return value;
  if (value === null || value === undefined) return '';
  return String(value);
};

const pickFirstString = (...values: unknown[]): string | undefined => {
  for (const value of values) {
    const text = asString(value).trim();
    if (text) return text;
  }
  return undefined;
};

const getContactArray = (value: unknown): JsonRecord[] | null => {
  if (!Array.isArray(value)) return null;
  return value.filter(isRecord);
};

const CONTACTS_CACHE_TTL_MS = 10 * 60 * 1000;

const contactsCacheKey = (locationId: string): QueryKeyParts => ({
  role: 'user',
  locationId,
  resource: 'contacts',
  filtersHash: 'all',
});

const normalizeContacts = (data: unknown): Contact[] => {
  // Handle various response formats:
  // - Array of contacts
  // - { data: [...] }
  // - { contacts: [...] }
  // - { data: { contacts: [...] } }
  // Detect degraded backend responses — throw so fetchCachedJson preserves stale cache
  if (isRecord(data) && (data.temporarily_unavailable === true || data.status === 'temporarily_unavailable' || data.success === false)) {
    throw new Error(asString(data.warning) || asString(data.error) || 'Contacts temporarily unavailable');
  }

  let contacts: JsonRecord[] = [];
  const directContacts = getContactArray(data);
  if (directContacts) {
    contacts = directContacts;
  } else if (isRecord(data)) {
    const contactsField = getContactArray(data.contacts);
    const dataField = getContactArray(data.data);
    const nestedContacts = isRecord(data.data) ? getContactArray(data.data.contacts) : null;
    contacts = contactsField || dataField || nestedContacts || [];
  }

  // Normalize GHL raw contact format -> our Contact shape
  // GHL returns: contactName, firstName, lastName, phone, email, id
  // We need:     name, phone, email, id
  const normalized = contacts.map((c) => {
    const firstName = asString(c.firstName);
    const lastName = asString(c.lastName);
    const name = asString(c.name)
      || asString(c.contactName)
      || [firstName, lastName].filter(Boolean).join(' ').trim()
      || asString(c.firstNameRaw)
      || asString(c.phone)
      || 'Unknown';

    // Normalize phone to 09XXXXXXXXX format (aligned with send_sms.php clean_numbers)
    let phone = asString(c.phone || c.mobileNumber);
    if (phone) {
      const digits = phone.replace(/\D/g, '');
      if (/^639\d{9}$/.test(digits)) phone = '0' + digits.slice(2);
      else if (/^9\d{9}$/.test(digits)) phone = '0' + digits;
      else if (/^09\d{9}$/.test(digits)) phone = digits;
      else phone = digits || phone;
    }

    return {
      id: c.id !== null && c.id !== undefined ? String(c.id) : String(Math.random()),
      name,
      phone,
      email: asString(c.email),
      ghl_contact_id: c.ghl_contact_id !== null && c.ghl_contact_id !== undefined ? String(c.ghl_contact_id) : undefined,
      lastMessage: pickFirstString(c.lastMessage, c.last_message),
      lastSentAt: pickFirstString(c.lastSentAt, c.last_messaged_at, c.last_message_at, c.last_sms_at),
      source: pickFirstString(c.source, c.contact_source, c.origin),
      tags: Array.isArray(c.tags) ? c.tags.map(String) : []
    };
  });

  return normalized;
};

export const getCachedContactsMeta = (explicitLocationId?: string): FetchContactsResult | null => {
  const contactContext = getContactContext(explicitLocationId);
  if (!contactContext) return null;

  const cached = readQueryCache<Contact[]>(contactsCacheKey(contactContext.locationId), CONTACTS_CACHE_TTL_MS, true);
  return cached ? { ok: true, contacts: cached.data, cacheMeta: cached.meta } : null;
};

export const fetchContactsMeta = async (
  explicitLocationId?: string,
  options: { forceRefresh?: boolean; signal?: AbortSignal } = {}
): Promise<FetchContactsResult> => {
  try {
    const contactContext = getContactContext(explicitLocationId);

    if (!contactContext) {
      devLog.warn('NOLA SMS: Contacts fetch skipped until location is available.');
      return { ok: false, contacts: [], kind: 'other', message: 'Location is not ready', status: 400 };
    }

    const cacheKey = contactsCacheKey(contactContext.locationId);
    const cached = readQueryCache<Contact[]>(cacheKey, CONTACTS_CACHE_TTL_MS, true);
    if (cached && !cached.meta.stale && !options.forceRefresh) {
      return { ok: true, contacts: cached.data, cacheMeta: cached.meta };
    }

    const params = new URLSearchParams({ location_id: contactContext.locationId });
    if (options.forceRefresh) params.set('refresh', '1');
    const url = `${CONTACTS_API_URL}?${params.toString()}`;

    try {
      const entry = await fetchCachedJson<Contact[]>({
        key: cacheKey,
        url,
        init: { headers: contactContext.headers, signal: options.signal },
        ttlMs: CONTACTS_CACHE_TTL_MS,
        forceRefresh: options.forceRefresh,
        parse: normalizeContacts,
      });
      return { ok: true, contacts: entry.data, cacheMeta: entry.meta };
    } catch (requestError: any) {
      if (requestError?.name === 'AbortError' || options.signal?.aborted) {
        throw requestError;
      }
      if (cached) {
        return { ok: true, contacts: cached.data, cacheMeta: { ...cached.meta, stale: true, status: 'failed' } };
      }
      const message = requestError instanceof Error ? requestError.message : 'Failed to load contacts';
      return { ok: false, contacts: [], kind: 'other', message, status: 0 };
    }
  } catch (error: any) {
    if (error?.name === 'AbortError' || options.signal?.aborted) {
      throw error;
    }
    devLog.error('Failed to fetch contacts:', error);
    return {
      ok: false,
      contacts: [],
      kind: 'other',
      message: error instanceof Error ? error.message : 'Failed to load contacts',
      status: 0,
    };
  }
};

export const fetchContacts = async (explicitLocationId?: string): Promise<Contact[]> => {
  const result = await fetchContactsMeta(explicitLocationId);
  return result.ok ? result.contacts : [];
};

export interface AddContactParams {
  name: string;
  phone: string;
  email?: string;
}

export const addContact = async (params: AddContactParams): Promise<Contact | null> => {
  try {
    const contactContext = getContactContext();
    if (!contactContext) {
      throw new Error('Authentication or location is not ready.');
    }

    // Strip empty optional fields; GHL rejects email: "" with "email must be an email"
    const body: Record<string, string> = { name: params.name, phone: params.phone };
    if (params.email) body.email = params.email;

    const url = `${CONTACTS_API_URL}?${new URLSearchParams({ location_id: contactContext.locationId }).toString()}`;
    const res = await apiFetch(url, {
      method: 'POST',
      headers: contactContext.headers,
      body: JSON.stringify(body),
    });

    if (!res.ok) {
      const error = await readJsonOrText(res);
      devLog.error('Failed to add contact:', error);
      handleUnauthorizedResponse(res.status, error, Boolean(contactContext.session?.token));
      if (isReconnectResponse(res.status, error)) {
        throw new GhlReconnectError(getErrorMessage(error, 'GoHighLevel connection expired'), res.status);
      }
      throw new Error(getErrorMessage(error, 'Failed to add contact'));
    }

    const contact = await res.json();
    removeQueryCache(contactsCacheKey(contactContext.locationId));
    return contact;
  } catch (error) {
    devLog.error('Failed to add contact:', error);
    throw error;
  }
};

export interface UpdateContactParams {
  id: string;
  name: string;
  phone: string;
  email?: string;
  previousPhone?: string;
  previousName?: string;
}

export const updateContact = async (params: UpdateContactParams): Promise<Contact | null> => {
  try {
    const contactContext = getContactContext();
    if (!contactContext) {
      throw new Error('Authentication or location is not ready.');
    }

    // Strip empty optional fields; GHL rejects email: "" with "email must be an email"
    const body: Record<string, string> = { id: params.id, name: params.name, phone: params.phone };
    if (params.email) body.email = params.email;
    if (params.previousPhone) body.previous_phone = params.previousPhone;
    if (params.previousName) body.previous_name = params.previousName;

    const url = `${CONTACTS_API_URL}?${new URLSearchParams({ location_id: contactContext.locationId }).toString()}`;
    const res = await apiFetch(url, {
      method: 'PUT',
      headers: contactContext.headers,
      body: JSON.stringify(body),
    });

    if (!res.ok) {
      const error = await readJsonOrText(res);
      devLog.error('Failed to update contact:', error);
      handleUnauthorizedResponse(res.status, error, Boolean(contactContext.session?.token));
      if (isReconnectResponse(res.status, error)) {
        throw new GhlReconnectError(getErrorMessage(error, 'GoHighLevel connection expired'), res.status);
      }
      throw new Error(getErrorMessage(error, 'Failed to update contact'));
    }

    const contact = await res.json();
    removeQueryCache(contactsCacheKey(contactContext.locationId));
    return contact;
  } catch (error) {
    devLog.error('Failed to update contact:', error);
    throw error;
  }
};

export const deleteContact = async (id: string): Promise<boolean> => {
  try {
    const contactContext = getContactContext();
    if (!contactContext) {
      throw new Error('Authentication or location is not ready.');
    }

    const url = `${CONTACTS_API_URL}?${new URLSearchParams({
      id,
      location_id: contactContext.locationId,
    }).toString()}`;

    const res = await apiFetch(url, {
      method: 'DELETE',
      headers: contactContext.headers,
    });

    if (!res.ok) {
      const error = await readJsonOrText(res);
      devLog.error('Failed to delete contact:', error);
      handleUnauthorizedResponse(res.status, error, Boolean(contactContext.session?.token));
      if (isReconnectResponse(res.status, error)) {
        throw new GhlReconnectError(getErrorMessage(error, 'GoHighLevel connection expired'), res.status);
      }
      throw new Error(getErrorMessage(error, 'Failed to delete contact'));
    }

    removeQueryCache(contactsCacheKey(contactContext.locationId));
    return true;
  } catch (error) {
    devLog.error('Failed to delete contact:', error);
    throw error;
  }
};
