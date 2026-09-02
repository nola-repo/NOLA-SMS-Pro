import { devLog } from './devLog';
import {
  detectLocationFromCurrentUrl,
  detectLocationFromRecord,
  isAllowedGhlPostMessageOrigin,
  normalizeLocationCandidate,
  type LocationDetectionResult,
} from './ghlLocationDetection';

export interface GhlIframeContext {
  locationId?: string;
  companyId?: string;
  userId?: string;
  email?: string;
  sourcePath?: string;
}

const CONTEXT_STORAGE_KEY = 'nola_ghl_iframe_context';
const CONTEXT_MAX_AGE_MS = 5 * 60 * 1000;

const COMPANY_ID_KEYS = [
  'company_id',
  'companyId',
  'agency_id',
  'agencyId',
  'account_id',
  'accountId',
] as const;

const USER_ID_KEYS = [
  'ghl_user_id',
  'ghlUserId',
  'user_id',
  'userId',
  'current_user_id',
  'currentUserId',
  'staff_id',
  'staffId',
] as const;

const EMAIL_KEYS = [
  'email',
  'user_email',
  'userEmail',
  'current_user_email',
  'currentUserEmail',
  'staff_email',
  'staffEmail',
] as const;

const USER_OBJECT_KEYS = ['currentUser', 'current_user', 'user', 'staff'] as const;
const SAFE_CONTEXT_CONTAINERS = ['payload', 'data', 'context', 'detail', 'meta', 'message'] as const;
const ENCODED_CONTEXT_PARAM_KEYS = ['sessionkey', 'sessionKey', 'state', 'payload', 'context', 'data'] as const;

const firstString = (...values: unknown[]): string | undefined => {
  for (const value of values) {
    if (typeof value === 'string' && value.trim()) return value.trim();
    if (typeof value === 'number') return String(value);
  }
  return undefined;
};

const normalizeEmail = (value: unknown): string | undefined => {
  const raw = firstString(value);
  if (!raw || !raw.includes('@')) return undefined;
  return raw.toLowerCase();
};

const normalizeIdentityValue = (value: unknown, locationId?: string): string | undefined => {
  const raw = firstString(value);
  if (!raw) return undefined;
  if (raw.includes('@')) return undefined;
  if (normalizeLocationCandidate(raw) && normalizeLocationCandidate(raw) === normalizeLocationCandidate(locationId)) {
    return undefined;
  }
  return raw;
};

const mergeContext = (base: GhlIframeContext, next: GhlIframeContext): GhlIframeContext => ({
  locationId: next.locationId || base.locationId,
  companyId: next.companyId || base.companyId,
  userId: next.userId || base.userId,
  email: next.email || base.email,
  sourcePath: next.sourcePath || base.sourcePath,
});

const tryParseJson = (value: string): unknown | null => {
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
};

const decodeBase64Url = (value: string): string | null => {
  try {
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
    return atob(padded);
  } catch {
    return null;
  }
};

const contextHasIdentity = (context: GhlIframeContext): boolean =>
  Boolean(context.companyId || context.userId || context.email);

const extractContextFromParams = (
  query: string,
  sourcePath: string,
  baseLocationId?: string,
): GhlIframeContext => {
  const params = new URLSearchParams(query);
  let context: GhlIframeContext = {};

  for (const key of COMPANY_ID_KEYS) {
    const companyId = firstString(params.get(key));
    if (companyId) context = mergeContext(context, { companyId, sourcePath: `${sourcePath}.${key}` });
  }

  for (const key of USER_ID_KEYS) {
    const userId = normalizeIdentityValue(params.get(key), baseLocationId || context.locationId);
    if (userId) context = mergeContext(context, { userId, sourcePath: `${sourcePath}.${key}` });
  }

  for (const key of EMAIL_KEYS) {
    const email = normalizeEmail(params.get(key));
    if (email) context = mergeContext(context, { email, sourcePath: `${sourcePath}.${key}` });
  }

  for (const key of ENCODED_CONTEXT_PARAM_KEYS) {
    const raw = params.get(key);
    if (!raw) continue;
    const decoded = extractContextFromEncodedValue(raw, `${sourcePath}.${key}`, baseLocationId || context.locationId);
    context = mergeContext(context, decoded);
  }

  return context;
};

const extractContextFromEncodedValue = (
  rawValue: string,
  sourcePath: string,
  baseLocationId?: string,
): GhlIframeContext => {
  const values = new Set<string>();
  const addValue = (value: string | null | undefined) => {
    const normalized = value?.trim();
    if (normalized) values.add(normalized);
  };

  addValue(rawValue);
  try {
    addValue(decodeURIComponent(rawValue));
  } catch {
    // Keep the original encoded value.
  }

  const jwtPayload = rawValue.split('.')[1];
  if (jwtPayload) addValue(decodeBase64Url(jwtPayload));
  addValue(decodeBase64Url(rawValue));

  let context: GhlIframeContext = {};
  for (const value of values) {
    if (value.includes('=') || value.includes('&')) {
      const query = value.includes('?') ? value.slice(value.indexOf('?') + 1) : value;
      context = mergeContext(context, extractContextFromParams(`?${query}`, sourcePath, baseLocationId));
    }

    const parsed = tryParseJson(value);
    context = mergeContext(context, extractContextFromRecord(parsed, sourcePath, baseLocationId));
  }

  return context;
};

const extractContextFromRecord = (
  source: unknown,
  sourcePath: string,
  baseLocationId?: string,
): GhlIframeContext => {
  if (!source || typeof source !== 'object') return {};
  const record = source as Record<string, unknown>;
  const location = detectLocationFromRecord(record, 'unknown', sourcePath);
  const locationId = location?.locationId || baseLocationId;
  let context: GhlIframeContext = locationId ? { locationId, sourcePath: location?.path || sourcePath } : {};

  for (const key of COMPANY_ID_KEYS) {
    const companyId = firstString(record[key]);
    if (companyId) context = mergeContext(context, { companyId, sourcePath: `${sourcePath}.${key}` });
  }

  for (const key of USER_ID_KEYS) {
    const userId = normalizeIdentityValue(record[key], context.locationId);
    if (userId) context = mergeContext(context, { userId, sourcePath: `${sourcePath}.${key}` });
  }

  for (const key of EMAIL_KEYS) {
    const email = normalizeEmail(record[key]);
    if (email) context = mergeContext(context, { email, sourcePath: `${sourcePath}.${key}` });
  }

  for (const key of USER_OBJECT_KEYS) {
    const nested = record[key];
    if (!nested || typeof nested !== 'object') continue;
    const nestedRecord = nested as Record<string, unknown>;
    const userId = normalizeIdentityValue(
      nestedRecord.id ?? nestedRecord.user_id ?? nestedRecord.userId ?? nestedRecord.ghl_user_id ?? nestedRecord.ghlUserId,
      context.locationId,
    );
    const email = normalizeEmail(nestedRecord.email);
    context = mergeContext(context, {
      userId,
      email,
      sourcePath: userId || email ? `${sourcePath}.${key}` : undefined,
    });
  }

  return context;
};

const readStoredContext = (locationId?: string): GhlIframeContext => {
  try {
    const parsed = JSON.parse(sessionStorage.getItem(CONTEXT_STORAGE_KEY) || 'null');
    if (!parsed || typeof parsed !== 'object') return {};
    const record = parsed as GhlIframeContext & { savedAt?: number };
    if (!record.savedAt || Date.now() - record.savedAt > CONTEXT_MAX_AGE_MS) return {};
    if (locationId && record.locationId && record.locationId !== locationId) return {};
    return {
      locationId: record.locationId,
      companyId: record.companyId,
      userId: record.userId,
      email: record.email,
      sourcePath: record.sourcePath,
    };
  } catch {
    return {};
  }
};

export const persistGhlIframeContext = (context: GhlIframeContext): void => {
  if (!context.locationId && !contextHasIdentity(context)) return;
  const payload = { ...context, savedAt: Date.now() };
  try {
    sessionStorage.setItem(CONTEXT_STORAGE_KEY, JSON.stringify(payload));
  } catch {
    // Embedded storage can be blocked. Autologin still works with live URL data.
  }
};

export const clearStoredGhlIframeContext = (): void => {
  try {
    sessionStorage.removeItem(CONTEXT_STORAGE_KEY);
  } catch {
    // ignore
  }
};

export const detectGhlContextFromPostMessage = (event: MessageEvent): GhlIframeContext | null => {
  if (!isAllowedGhlPostMessageOrigin(event)) return null;
  try {
    if (window.parent && window.parent !== window && event.source !== window.parent) {
      return null;
    }
  } catch {
    return null;
  }

  let data: unknown = event.data;
  if (typeof data === 'string') {
    const raw = data.trim();
    if (!raw) return null;
    data = tryParseJson(raw);
    if (!data && (raw.includes('=') || raw.includes('&'))) {
      return extractContextFromParams(`?${raw.includes('?') ? raw.slice(raw.indexOf('?') + 1) : raw}`, 'postMessage');
    }
    if (!data) return null;
  }

  const queue: Array<{ value: unknown; path: string }> = [{ value: data, path: 'postMessage' }];
  const visited = new Set<unknown>();
  let context: GhlIframeContext = {};

  for (let index = 0; index < queue.length && index < 20; index += 1) {
    const item = queue[index];
    if (!item.value || typeof item.value !== 'object' || visited.has(item.value)) continue;
    visited.add(item.value);

    context = mergeContext(context, extractContextFromRecord(item.value, item.path, context.locationId));
    const record = item.value as Record<string, unknown>;
    for (const key of SAFE_CONTEXT_CONTAINERS) {
      const nested = record[key];
      if (nested && typeof nested === 'object') queue.push({ value: nested, path: `${item.path}.${key}` });
    }
  }

  if (!context.locationId && !contextHasIdentity(context)) return null;
  return context;
};

export const resolveGhlIframeContext = (locationId?: string): GhlIframeContext => {
  const fromUrlLocation: LocationDetectionResult | null = detectLocationFromCurrentUrl();
  const resolvedLocationId = normalizeLocationCandidate(locationId) || fromUrlLocation?.locationId || undefined;
  let context: GhlIframeContext = resolvedLocationId
    ? { locationId: resolvedLocationId, sourcePath: fromUrlLocation?.path }
    : {};

  if (typeof window !== 'undefined') {
    context = mergeContext(context, extractContextFromParams(window.location.search, 'url', context.locationId));

    const hash = window.location.hash || '';
    const hashQuery = hash.includes('?')
      ? hash.slice(hash.indexOf('?') + 1)
      : hash.replace(/^#/, '');
    if (hashQuery.includes('=')) {
      context = mergeContext(context, extractContextFromParams(`?${hashQuery}`, 'hash', context.locationId));
    }
  }

  context = mergeContext(context, readStoredContext(context.locationId));

  if (context.locationId || contextHasIdentity(context)) {
    persistGhlIframeContext(context);
  }

  if (import.meta.env.DEV && context.sourcePath) {
    devLog.log('[GHL Context] Resolved iframe context from', context.sourcePath);
  }

  return context;
};

export const buildGhlAutologinPayload = (
  locationId: string,
  options: { includeIdentity?: boolean } = {},
): Record<string, string> => {
  const context = resolveGhlIframeContext(locationId);
  const payload: Record<string, string> = {
    location_id: locationId,
    locationId,
    active_location_id: locationId,
  };

  if (context.companyId) {
    payload.company_id = context.companyId;
    payload.companyId = context.companyId;
  }

  if (options.includeIdentity !== false && context.userId) {
    payload.ghl_user_id = context.userId;
    payload.ghlUserId = context.userId;
  }

  if (options.includeIdentity !== false && context.email) {
    payload.email = context.email;
  }

  return payload;
};

