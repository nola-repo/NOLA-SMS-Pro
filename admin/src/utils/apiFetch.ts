export function createRequestId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function withRequestId(headers?: HeadersInit): Headers {
  const merged = new Headers(headers);

  if (!merged.has('X-Request-ID')) {
    merged.set('X-Request-ID', createRequestId());
  }

  return merged;
}

export type AdminApiDebugLog = {
  id: string;
  timestamp: string;
  type: 'admin_login' | 'api_connection';
  level: 'INFO' | 'WARN' | 'ERROR';
  event: string;
  method: string;
  endpoint: string;
  status: number;
  status_text?: string;
  duration_ms: number;
  request_id?: string;
  summary: string;
};

export const ADMIN_API_LOG_EVENT = 'nola-admin-api-log';
export const ADMIN_API_LOG_STORAGE_KEY = 'nola_admin_api_debug_logs';
const MAX_STORED_API_LOGS = 200;

const viteEnv = (import.meta as ImportMeta & { env?: Record<string, string | undefined> }).env;
const API_BASE = (viteEnv?.VITE_API_BASE || '').replace(/\/$/, '');
const USE_DIRECT_API_BASE = viteEnv?.VITE_ADMIN_USE_DIRECT_API === 'true';

const resolveApiInput = (input: RequestInfo | URL): RequestInfo | URL => {
  if (typeof input !== 'string') return input;
  if (!input.startsWith('/api/')) return input;
  if (!USE_DIRECT_API_BASE || !API_BASE) return input;
  return `${API_BASE}${input}`;
};

const requestInfoToString = (input: RequestInfo | URL): string => {
  if (typeof input === 'string') return input;
  if (input instanceof URL) return input.toString();
  return input.url;
};

const requestMethodFor = (input: RequestInfo | URL, init: RequestInit): string => {
  const initMethod = typeof init.method === 'string' ? init.method : '';
  if (initMethod) return initMethod.toUpperCase();
  if (typeof input !== 'string' && !(input instanceof URL) && input.method) {
    return input.method.toUpperCase();
  }
  return 'GET';
};

const endpointFor = (input: RequestInfo | URL): string => {
  const raw = requestInfoToString(input);
  try {
    const base = typeof window !== 'undefined' ? window.location.origin : 'https://app.nolasmspro.com';
    const parsed = new URL(raw, base);
    return `${parsed.pathname}${parsed.search}`;
  } catch {
    return raw;
  }
};

const classifyApiLog = (endpoint: string, method: string): AdminApiDebugLog['type'] => {
  if (endpoint.includes('/api/admin_auth.php') && method === 'POST') return 'admin_login';
  return 'api_connection';
};

const levelForStatus = (status: number): AdminApiDebugLog['level'] => {
  if (status >= 500 || status === 0) return 'ERROR';
  if (status >= 400) return 'WARN';
  return 'INFO';
};

const summaryForLog = (log: Omit<AdminApiDebugLog, 'id' | 'timestamp' | 'summary'>): string => {
  const statusText = log.status === 0 ? 'network error' : `${log.status}${log.status_text ? ` ${log.status_text}` : ''}`;
  const base = `${log.method} ${log.endpoint} -> ${statusText} (${log.duration_ms}ms)`;

  if (log.type === 'admin_login') {
    return log.status >= 200 && log.status < 300
      ? `Admin login API succeeded | ${base}`
      : `Admin login API failed | ${base}`;
  }

  return `API connection ${log.status >= 200 && log.status < 400 ? 'ok' : 'failed'} | ${base}`;
};

export const getStoredAdminApiLogs = (): AdminApiDebugLog[] => {
  if (typeof window === 'undefined') return [];

  try {
    const raw = window.sessionStorage.getItem(ADMIN_API_LOG_STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
};

const emitAdminApiLog = (log: Omit<AdminApiDebugLog, 'id' | 'timestamp' | 'summary'>): void => {
  if (typeof window === 'undefined') return;

  const entry: AdminApiDebugLog = {
    ...log,
    id: `${log.request_id || createRequestId()}-${Date.now()}`,
    timestamp: new Date().toISOString(),
    summary: summaryForLog(log),
  };

  try {
    const next = [entry, ...getStoredAdminApiLogs()].slice(0, MAX_STORED_API_LOGS);
    window.sessionStorage.setItem(ADMIN_API_LOG_STORAGE_KEY, JSON.stringify(next));
  } catch {
    // Debug logging must never block the request path.
  }

  window.dispatchEvent(new CustomEvent(ADMIN_API_LOG_EVENT, { detail: entry }));
};

const nowMs = () => (typeof performance !== 'undefined' ? performance.now() : Date.now());

export async function apiFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const headers = withRequestId(init.headers);
  const resolvedInput = resolveApiInput(input);
  const method = requestMethodFor(input, init);
  const endpoint = endpointFor(input);
  const requestId = headers.get('X-Request-ID') || undefined;
  const startedAt = nowMs();

  try {
    const response = await fetch(resolvedInput, {
      ...init,
      headers,
    });

    const durationMs = Math.max(0, Math.round(nowMs() - startedAt));
    emitAdminApiLog({
      type: classifyApiLog(endpoint, method),
      level: levelForStatus(response.status),
      event: 'HTTP_RESPONSE',
      method,
      endpoint,
      status: response.status,
      status_text: response.statusText,
      duration_ms: durationMs,
      request_id: requestId,
    });

    return response;
  } catch (error) {
    const durationMs = Math.max(0, Math.round(nowMs() - startedAt));
    emitAdminApiLog({
      type: classifyApiLog(endpoint, method),
      level: 'ERROR',
      event: 'HTTP_ERROR',
      method,
      endpoint,
      status: 0,
      status_text: error instanceof Error ? error.message : 'Network error',
      duration_ms: durationMs,
      request_id: requestId,
    });
    throw error;
  }
}
