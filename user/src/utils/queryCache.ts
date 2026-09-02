import { safeStorage } from './safeStorage';
import { apiFetch } from './apiFetch';

export type QuerySurface = 'ghl-iframe' | 'external' | 'agency' | 'admin';
export type QueryRole = 'user' | 'agency' | 'admin';
export type QueryStatus = 'fresh' | 'stale' | 'refreshing' | 'failed';

export interface QueryKeyParts {
  surface?: QuerySurface;
  role: QueryRole;
  companyId?: string | null;
  locationId?: string | null;
  resource: string;
  filtersHash?: string | null;
}

export interface QueryCacheMeta {
  cached: boolean;
  stale: boolean;
  status: QueryStatus;
  cachedAt: number;
  generatedAt?: string;
  cacheTtlMs: number;
  requestId?: string;
}

export interface QueryCacheEntry<T> {
  data: T;
  meta: QueryCacheMeta;
}

export interface CachedJsonOptions<T> {
  key: QueryKeyParts;
  url: string;
  init?: RequestInit;
  ttlMs: number;
  forceRefresh?: boolean;
  allowStaleOnError?: boolean;
  parse?: (payload: unknown) => T;
}

const STORAGE_PREFIX = 'nola_query_cache_v1:';
const memoryCache = new Map<string, QueryCacheEntry<unknown>>();
const inflight = new Map<string, Promise<QueryCacheEntry<unknown>>>();

const stableText = (value: unknown): string => {
  if (value === null || value === undefined || value === '') return 'global';
  return String(value);
};

export const detectQuerySurface = (): QuerySurface => {
  if (typeof window === 'undefined') return 'external';
  try {
    if (window.self !== window.top) return 'ghl-iframe';
    if (sessionStorage.getItem('nola_is_ghl_frame') === 'true') return 'ghl-iframe';
  } catch {
    return 'ghl-iframe';
  }
  return 'external';
};

export const makeQueryCacheKey = (parts: QueryKeyParts): string => {
  const raw = [
    'nola',
    parts.surface || detectQuerySurface(),
    parts.role,
    stableText(parts.companyId),
    stableText(parts.locationId),
    parts.resource,
    stableText(parts.filtersHash),
  ].join('|');
  return encodeURIComponent(raw);
};

const storageKey = (parts: QueryKeyParts): string => `${STORAGE_PREFIX}${makeQueryCacheKey(parts)}`;

const readHeaderInt = (headers: Headers, name: string): number | null => {
  const raw = headers.get(name);
  if (!raw) return null;
  const match = raw.match(/max-age=(\d+)/i);
  const numeric = match ? Number(match[1]) : Number(raw);
  return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
};

const getTtlFromResponse = (payload: unknown, headers: Headers, fallbackMs: number): number => {
  const record = payload && typeof payload === 'object' && !Array.isArray(payload)
    ? payload as Record<string, unknown>
    : null;
  const payloadTtl = Number(record?.cache_ttl);
  if (Number.isFinite(payloadTtl) && payloadTtl > 0) return payloadTtl * 1000;
  const headerSeconds = readHeaderInt(headers, 'Cache-Control');
  return headerSeconds ? headerSeconds * 1000 : fallbackMs;
};

export const readQueryCache = <T>(parts: QueryKeyParts, ttlMs: number, allowExpired = true): QueryCacheEntry<T> | null => {
  const key = makeQueryCacheKey(parts);
  const fromMemory = memoryCache.get(key) as QueryCacheEntry<T> | undefined;
  const now = Date.now();
  if (fromMemory) {
    const stale = now - fromMemory.meta.cachedAt > fromMemory.meta.cacheTtlMs;
    if (!stale || allowExpired) {
      return { ...fromMemory, meta: { ...fromMemory.meta, stale, status: stale ? 'stale' : 'fresh' } };
    }
  }

  try {
    const raw = safeStorage.getItem(storageKey(parts));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as QueryCacheEntry<T>;
    const cacheTtlMs = parsed.meta?.cacheTtlMs || ttlMs;
    const stale = now - parsed.meta.cachedAt > cacheTtlMs;
    if (stale && !allowExpired) return null;
    const entry = {
      data: parsed.data,
      meta: {
        ...parsed.meta,
        cacheTtlMs,
        stale,
        status: stale ? 'stale' as QueryStatus : 'fresh' as QueryStatus,
      },
    };
    memoryCache.set(key, entry as QueryCacheEntry<unknown>);
    return entry;
  } catch {
    return null;
  }
};

export const writeQueryCache = <T>(parts: QueryKeyParts, data: T, meta: Partial<QueryCacheMeta> = {}): QueryCacheEntry<T> => {
  const entry: QueryCacheEntry<T> = {
    data,
    meta: {
      cached: true,
      stale: false,
      status: 'fresh',
      cachedAt: Date.now(),
      cacheTtlMs: meta.cacheTtlMs || 60_000,
      generatedAt: meta.generatedAt,
      requestId: meta.requestId,
    },
  };
  const key = makeQueryCacheKey(parts);
  memoryCache.set(key, entry as QueryCacheEntry<unknown>);
  try {
    safeStorage.setItem(storageKey(parts), JSON.stringify(entry));
  } catch {
    // Best effort only. The in-memory cache still covers iframe/private-mode sessions.
  }
  return entry;
};

export const removeQueryCache = (parts: QueryKeyParts): void => {
  const key = makeQueryCacheKey(parts);
  memoryCache.delete(key);
  safeStorage.removeItem(storageKey(parts));
};

export const invalidateQueryCache = (match: Partial<QueryKeyParts>): void => {
  const token = [
    'nola',
    match.surface || '',
    match.role || '',
    stableText(match.companyId || ''),
    stableText(match.locationId || ''),
    match.resource || '',
  ].filter(Boolean).join('|');

  for (const key of Array.from(memoryCache.keys())) {
    const decoded = decodeURIComponent(key);
    if (!token || decoded.includes(token)) memoryCache.delete(key);
  }

  // SafeStorage intentionally does not expose key iteration. Persistent entries expire by TTL.
};

export const fetchCachedJson = async <T>(options: CachedJsonOptions<T>): Promise<QueryCacheEntry<T>> => {
  const cached = readQueryCache<T>(options.key, options.ttlMs, true);
  if (cached && !cached.meta.stale && !options.forceRefresh) return cached;

  const key = makeQueryCacheKey(options.key);
  const existing = inflight.get(key) as Promise<QueryCacheEntry<T>> | undefined;
  if (existing && !options.forceRefresh) return existing;

  const request: Promise<QueryCacheEntry<T>> = (async () => {
    try {
      const res = await apiFetch(options.url, options.init || {});
      if (!res.ok) throw new Error(`Request failed (${res.status})`);
      const payload = await res.json();
      const data = options.parse ? options.parse(payload) : payload as T;
      const record = payload && typeof payload === 'object' && !Array.isArray(payload)
        ? payload as Record<string, unknown>
        : null;
      return writeQueryCache(options.key, data, {
        cacheTtlMs: getTtlFromResponse(payload, res.headers, options.ttlMs),
        generatedAt: typeof record?.generated_at === 'string' ? record.generated_at : undefined,
        requestId: res.headers.get('X-Request-ID') || undefined,
      });
    } catch (error) {
      if (cached && options.allowStaleOnError !== false) {
        return {
          data: cached.data,
          meta: { ...cached.meta, cached: true, stale: true, status: 'failed' as QueryStatus },
        };
      }
      throw error;
    } finally {
      inflight.delete(key);
    }
  })();

  inflight.set(key, request as Promise<QueryCacheEntry<unknown>>);
  return request;
};
