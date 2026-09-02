/**
 * Agency Panel — API Service
 * All fetch calls to the PHP backend go through this module.
 * The nginx proxy forwards /api/* → https://smspro-api.nolacrm.io/api/*
 */

import { agencyFetch, getAgencyAuthHeaders } from './agencyApi';

const BASE = '/api/agency';
const SUBACCOUNTS_CACHE_TTL_MS = 30_000;
const SUBACCOUNTS_STALE_TTL_MS = 5 * 60_000;

type SubaccountsResponse = {
  status?: string;
  subaccounts?: any[];
  [key: string]: any;
};

type SubaccountsCacheEntry = {
  data?: SubaccountsResponse;
  fetchedAt: number;
  inFlight?: Promise<SubaccountsResponse>;
};

type GetSubaccountsOptions = {
  force?: boolean;
  allowStale?: boolean;
  signal?: AbortSignal;
};

const subaccountsCache = new Map<string, SubaccountsCacheEntry>();
const defaultHeaders = (agencyId, extra = {}) => ({
  ...getAgencyAuthHeaders(true),
  'X-Agency-ID': agencyId || '',
  ...extra,
});

class APIError extends Error {
  status?: number;
}

const handleResponse = async (res: Response) => {
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch { json = { status: 'error', message: text }; }
  if (!res.ok) {
    const error = new APIError(json?.message || `HTTP ${res.status}`);
    error.status = res.status;
    throw error;
  }
  return json;
};
const normalizeSubaccountsResponse = (json: any): SubaccountsResponse => {
  if (json.status === 'success' && Array.isArray(json.data)) {
    return {
      status: 'success',
      subaccounts: json.data.map((item: any) => ({
        ...item.data,
        id: item.id
      }))
    };
  }
  return json;
};

const setSubaccountsCache = (agencyId: string, data: SubaccountsResponse) => {
  subaccountsCache.set(agencyId, {
    ...(subaccountsCache.get(agencyId) ?? { fetchedAt: Date.now() }),
    data,
    fetchedAt: Date.now(),
    inFlight: undefined,
  });
};

const updateCachedSubaccount = (agencyId: string, locationId: string, patch: Record<string, unknown>) => {
  const entry = subaccountsCache.get(agencyId);
  if (!entry?.data?.subaccounts || !locationId) return;
  setSubaccountsCache(agencyId, {
    ...entry.data,
    subaccounts: entry.data.subaccounts.map((subaccount: any) => {
      const id = subaccount.location_id ?? subaccount.locationId ?? subaccount.id;
      return id === locationId ? { ...subaccount, ...patch } : subaccount;
    }),
  });
};

export const invalidateSubaccountsCache = (agencyId?: string) => {
  if (agencyId) {
    subaccountsCache.delete(agencyId);
    return;
  }
  subaccountsCache.clear();
};

// ── GET all subaccounts for this agency (standardized endpoint) ───────────────
export const getSubaccounts = async (agencyId, options: GetSubaccountsOptions = {}) => {
  const key = String(agencyId || '');
  const now = Date.now();
  const cached = subaccountsCache.get(key);
  const allowStale = options.allowStale === true;

  if (!options.force && cached?.data && now - cached.fetchedAt < SUBACCOUNTS_CACHE_TTL_MS) {
    return cached.data;
  }

  if (!options.force && cached?.inFlight) {
    return cached.inFlight;
  }

  const fetchFresh = async () => {
    const res = await agencyFetch(`${BASE}/get_subaccounts.php?agency_id=${encodeURIComponent(key)}`, {
      method: 'GET',
      signal: options.signal,
    });

    const json = normalizeSubaccountsResponse(await handleResponse(res));
    setSubaccountsCache(key, json);
    return json;
  };

  const inFlight = fetchFresh().finally(() => {
    const latest = subaccountsCache.get(key);
    if (latest?.inFlight === inFlight) {
      subaccountsCache.set(key, { ...latest, inFlight: undefined });
    }
  });

  subaccountsCache.set(key, {
    ...(cached ?? { fetchedAt: 0 }),
    inFlight,
  });

  if (!options.force && allowStale && cached?.data && now - cached.fetchedAt < SUBACCOUNTS_STALE_TTL_MS) {
    inFlight.catch(() => undefined);
    return cached.data;
  }

  return inFlight;
};

// ── POST toggle a single subaccount ON/OFF ────────────────────────────────────
// Calls update_subaccount.php which writes toggle_enabled to ghl_tokens.
export const toggleSubaccount = async (agencyId: string, payload: {
  subaccount_id: string;
  enabled: boolean;
}) => {
  const res = await agencyFetch(`${BASE}/update_subaccount.php`, {
    method: 'POST',
    headers: defaultHeaders(agencyId),
    body: JSON.stringify({
      location_id: payload.subaccount_id,
      toggle_enabled: payload.enabled
    }),
  });

  // Check for a 403 limit_reached before the generic response handler.
  // NOTE: res.json() consumes the body, so we clone first to leave res intact
  // for handleResponse. The throw must be OUTSIDE the try/catch to avoid being
  // swallowed by the catch that only intends to handle JSON parse failures.
  if (res.status === 403) {
    let limitReached = false;
    let limitMessage = 'Activation limit reached. Please upgrade in the Subscription tab.';
    try {
      const data = await res.clone().json();
      limitReached = data?.status === 'limit_reached';
      limitMessage = data?.message || data?.error || limitMessage;
    } catch {
      // JSON parse failed ? fall through to generic handleResponse error
    }
    if (limitReached) {
      const err = new APIError(limitMessage);
      err.status = 403;
      throw err;
    }
  }

  const result = await handleResponse(res);
  updateCachedSubaccount(agencyId, payload.subaccount_id, { toggle_enabled: payload.enabled });
  return result;
};

// ── POST update subaccount settings ──────────────────────────────────────────
export const updateSubaccountSettings = async (agencyId, payload: {
  location_id: string;
  toggle_enabled: boolean;
  rate_limit: number;
  reset_counter: boolean;
}) => {
  const res = await agencyFetch(`${BASE}/update_subaccount.php`, {
    method: 'POST',
    headers: defaultHeaders(agencyId),
    body: JSON.stringify(payload),
  });
  const result = await handleResponse(res);
  updateCachedSubaccount(agencyId, payload.location_id, {
    toggle_enabled: payload.toggle_enabled,
    rate_limit: payload.rate_limit,
    ...(payload.reset_counter ? { attempt_count: 0 } : {}),
  });
  return result;
};

// ── GET all toggle-ON subaccounts (used by main admin panel) ──────────────────
export const getAllActiveSubaccounts = async () => {
  const res = await agencyFetch(`${BASE}/get_all_active.php`, {
    method: 'GET',
  });
  return handleResponse(res); // { status, active_subaccounts: [] }
};

// ── GET which locations have the NOLA SMS Pro user app installed ───────────────
// Returns string[] of locationIds with a valid ghl_token in the backend.
// Used to gate the enable/disable toggle per sub-account.
export const checkInstallStatus = async (agencyId: string): Promise<string[]> => {
  const res = await agencyFetch(`${BASE}/check_installs.php?company_id=${encodeURIComponent(agencyId)}`, {
    method: 'GET',
    headers: defaultHeaders(agencyId),
  });
  const data = await handleResponse(res); // { installed_locations: string[] }
  return data.installed_locations ?? [];
};

