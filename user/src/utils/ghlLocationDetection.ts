export type LocationSource =
  | 'url'
  | 'path'
  | 'hash'
  | 'postMessage'
  | 'storage'
  | 'manual'
  | 'unknown';

export interface LocationDetectionResult {
  locationId: string;
  source: LocationSource;
  path: string;
}

const EXPLICIT_LOCATION_ID_KEYS = [
  'location_id',
  'locationId',
  'ghl_location_id',
  'ghlLocationId',
  'active_location_id',
  'activeLocationId',
] as const;

const EXPLICIT_LOCATION_OBJECT_KEYS = [
  'location',
  'activeLocation',
  'selectedLocation',
  'selected_location',
] as const;

const SAFE_POST_MESSAGE_CONTAINERS = [
  'payload',
  'data',
  'context',
  'detail',
  'meta',
  'message',
] as const;

const ENCODED_LOCATION_PARAM_KEYS = [
  'sessionkey',
  'sessionKey',
  'state',
  'payload',
  'context',
  'data',
] as const;

const GHL_LOCATION_ID_PATTERN = /^[A-Za-z0-9_-]{12,80}$/;

const isTemplatePlaceholder = (value: string): boolean =>
  /\{\{[^}]+\}\}/.test(value) || value.includes('${');

const detectLocationFromPathname = (pathname: string): LocationDetectionResult | null => {
  const segments = pathname.split('/').filter(Boolean).map((segment) => {
    try {
      return decodeURIComponent(segment);
    } catch {
      return segment;
    }
  });

  for (let index = 0; index < segments.length - 1; index += 1) {
    const current = segments[index]?.toLowerCase();
    const previous = index > 0 ? segments[index - 1]?.toLowerCase() : '';
    const isGhlLocationPath = current === 'location' && (index === 0 || previous === 'v2');

    if (!isGhlLocationPath) continue;

    const candidate = normalizeLocationCandidate(segments[index + 1]);
    if (candidate) {
      return { locationId: candidate, source: 'path', path: index === 0 ? 'path.location' : 'path.v2.location' };
    }
  }

  return null;
};

export function normalizeLocationCandidate(value: unknown): string | null {
  if (typeof value !== 'string' && typeof value !== 'number') return null;
  const candidate = String(value).trim();
  if (!candidate || candidate.length <= 4) return null;
  if (candidate === 'null' || candidate === 'undefined') return null;
  if (isTemplatePlaceholder(candidate)) return null;
  if (!GHL_LOCATION_ID_PATTERN.test(candidate)) return null;
  return candidate;
}

export function detectLocationFromRecord(
  source: unknown,
  sourceName: LocationSource = 'unknown',
  path: string = sourceName,
): LocationDetectionResult | null {
  if (!source || typeof source !== 'object') return null;
  const record = source as Record<string, unknown>;

  for (const key of EXPLICIT_LOCATION_ID_KEYS) {
    const candidate = normalizeLocationCandidate(record[key]);
    if (candidate) return { locationId: candidate, source: sourceName, path: `${path}.${key}` };
  }

  for (const key of EXPLICIT_LOCATION_OBJECT_KEYS) {
    const nested = record[key];
    if (!nested || typeof nested !== 'object') continue;
    const nestedRecord = nested as Record<string, unknown>;

    for (const nestedKey of EXPLICIT_LOCATION_ID_KEYS) {
      const candidate = normalizeLocationCandidate(nestedRecord[nestedKey]);
      if (candidate) {
        return { locationId: candidate, source: sourceName, path: `${path}.${key}.${nestedKey}` };
      }
    }

  }

  return null;
}

const detectLocationFromParams = (
  query: string,
  source: LocationSource,
): LocationDetectionResult | null => {
  const params = new URLSearchParams(query);

  for (const key of EXPLICIT_LOCATION_ID_KEYS) {
    const candidate = normalizeLocationCandidate(params.get(key));
    if (candidate) return { locationId: candidate, source, path: `${source}.${key}` };
  }

  for (const key of ENCODED_LOCATION_PARAM_KEYS) {
    const raw = params.get(key);
    const fromEncoded = detectLocationFromEncodedValue(raw, source, `${source}.${key}`);
    if (fromEncoded) return fromEncoded;
  }

  return null;
};

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

function detectLocationFromEncodedValue(
  rawValue: string | null,
  source: LocationSource,
  path: string,
): LocationDetectionResult | null {
  const raw = normalizeLocationCandidate(rawValue);
  if (!raw) return null;

  const values = new Set<string>();
  const addValue = (value: string | null | undefined) => {
    const normalized = value?.trim();
    if (normalized) values.add(normalized);
  };

  addValue(raw);
  try {
    addValue(decodeURIComponent(raw));
  } catch {
    // Keep the original value when decoding fails.
  }

  const jwtPayload = raw.split('.')[1];
  if (jwtPayload) addValue(decodeBase64Url(jwtPayload));
  addValue(decodeBase64Url(raw));

  for (const value of values) {
    if (value.includes('=') || value.includes('&')) {
      const query = value.includes('?') ? value.slice(value.indexOf('?') + 1) : value;
      const nestedParams = new URLSearchParams(`?${query}`);
      for (const key of EXPLICIT_LOCATION_ID_KEYS) {
        const candidate = normalizeLocationCandidate(nestedParams.get(key));
        if (candidate) return { locationId: candidate, source, path: `${path}.${key}` };
      }
    }

    const parsed = tryParseJson(value);
    const fromRecord = detectLocationFromRecord(parsed, source, path);
    if (fromRecord) return fromRecord;
  }

  return null;
}

export function detectLocationFromCurrentUrl(): LocationDetectionResult | null {
  if (typeof window === 'undefined') return null;

  const fromSearch = detectLocationFromParams(window.location.search, 'url');
  if (fromSearch) return fromSearch;

  const fromPath = detectLocationFromPathname(window.location.pathname);
  if (fromPath) return fromPath;

  const hash = window.location.hash || '';
  const hashQuery = hash.includes('?')
    ? hash.slice(hash.indexOf('?') + 1)
    : hash.replace(/^#/, '');

  if (!hashQuery.includes('=')) return null;
  return detectLocationFromParams(`?${hashQuery}`, 'hash');
}

export function hasGhlLaunchSignalInCurrentUrl(): boolean {
  if (typeof window === 'undefined') return false;
  if (detectLocationFromCurrentUrl()) return true;

  const hasLaunchParam = (query: string): boolean => {
    const params = new URLSearchParams(query);
    return ENCODED_LOCATION_PARAM_KEYS.some((key) => params.has(key));
  };

  if (hasLaunchParam(window.location.search)) return true;

  const hash = window.location.hash || '';
  const hashQuery = hash.includes('?')
    ? hash.slice(hash.indexOf('?') + 1)
    : hash.replace(/^#/, '');

  return hashQuery.includes('=') && hasLaunchParam(`?${hashQuery}`);
}

export function isAllowedGhlPostMessageOrigin(event: MessageEvent): boolean {
  if (event.origin === window.location.origin) return true;

  let host = '';
  try {
    host = new URL(event.origin).hostname.toLowerCase();
  } catch {
    return false;
  }

  if (import.meta.env.DEV && (host === 'localhost' || host === '127.0.0.1')) {
    return true;
  }

  return [
    'gohighlevel.com',
    'leadconnectorhq.com',
    'leadconnector.com',
    'msgsndr.com',
  ].some((allowedHost) => host === allowedHost || host.endsWith(`.${allowedHost}`));
}

export function detectLocationFromPostMessage(event: MessageEvent): LocationDetectionResult | null {
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
    const rawMessage = data.trim();
    if (!rawMessage) return null;

    try {
      data = JSON.parse(rawMessage);
    } catch {
      if (!rawMessage.includes('location')) return null;
      const query = rawMessage.includes('?') ? rawMessage.slice(rawMessage.indexOf('?') + 1) : rawMessage;
      return detectLocationFromParams(`?${query}`, 'postMessage');
    }
  }

  const queue: Array<{ value: unknown; path: string }> = [{ value: data, path: 'postMessage' }];
  const visited = new Set<unknown>();

  for (let index = 0; index < queue.length && index < 20; index += 1) {
    const item = queue[index];
    if (!item.value || typeof item.value !== 'object' || visited.has(item.value)) continue;
    visited.add(item.value);

    const direct = detectLocationFromRecord(item.value, 'postMessage', item.path);
    if (direct) return direct;

    const record = item.value as Record<string, unknown>;
    for (const key of SAFE_POST_MESSAGE_CONTAINERS) {
      const nested = record[key];
      if (nested && typeof nested === 'object') queue.push({ value: nested, path: `${item.path}.${key}` });
    }

  }

  return null;
}
