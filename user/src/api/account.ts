import { devLog } from '../utils/devLog';
import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";
import { getAuthHeaders } from "../utils/authHeaders";
import { safeStorage } from "../utils/safeStorage";
import { apiFetch } from "../utils/apiFetch";

export interface AccountProfile {
    location_id: string;
    location_name: string | null;
    full_name?: string | null;
    email?: string | null;
    email_address?: string | null;
    phone?: string | null;
    phone_number?: string | null;
    name?: string | null;
    firstName?: string | null;
    lastName?: string | null;
    registration_status?: "registered" | "unregistered" | "not_installed" | string;
    is_registered?: boolean;
}

export interface UpdateAccountProfilePayload {
    name: string;
    email: string;
    phone: string;
}

interface FetchAccountProfileOptions {
    includeAuth?: boolean;
    forceRefresh?: boolean;
    cacheTtlMs?: number;
    allowStaleOnError?: boolean;
}

interface CachedAccountProfile {
    profile: AccountProfile;
    cachedAt: number;
}

const ACCOUNT_PROFILE_CACHE_PREFIX = "nola_account_profile_";
const DEFAULT_ACCOUNT_PROFILE_CACHE_TTL_MS = 5 * 60 * 1000;
const accountProfileCache = new Map<string, CachedAccountProfile>();
const accountProfileRequests = new Map<string, Promise<AccountProfile | null>>();

function resolveLocationId(explicitLocationId?: string): string {
    const { ghlLocationId } = getAccountSettings();
    return explicitLocationId || ghlLocationId;
}

function cacheIdFor(locationId: string, includeAuth: boolean): string {
    return `${includeAuth ? "auth" : "public"}:${locationId}`;
}

function storageKeyFor(cacheId: string): string {
    return `${ACCOUNT_PROFILE_CACHE_PREFIX}${encodeURIComponent(cacheId)}`;
}

function readCacheEntry(
    locationId: string,
    includeAuth: boolean,
    maxAgeMs = DEFAULT_ACCOUNT_PROFILE_CACHE_TTL_MS,
    allowExpired = false
): CachedAccountProfile | null {
    const cacheId = cacheIdFor(locationId, includeAuth);
    const now = Date.now();

    const fromMemory = accountProfileCache.get(cacheId);
    if (fromMemory && (allowExpired || now - fromMemory.cachedAt <= maxAgeMs)) {
        return fromMemory;
    }

    try {
        const raw = safeStorage.getItem(storageKeyFor(cacheId));
        if (!raw) return null;

        const parsed = JSON.parse(raw) as CachedAccountProfile;
        if (!parsed?.profile || !parsed.cachedAt) return null;

        accountProfileCache.set(cacheId, parsed);
        if (allowExpired || now - parsed.cachedAt <= maxAgeMs) {
            return parsed;
        }
    } catch {
        return null;
    }

    return null;
}

function writeCacheEntry(locationId: string, includeAuth: boolean, profile: AccountProfile): void {
    const cacheId = cacheIdFor(locationId, includeAuth);
    const entry: CachedAccountProfile = {
        profile,
        cachedAt: Date.now(),
    };

    accountProfileCache.set(cacheId, entry);
    try {
        safeStorage.setItem(storageKeyFor(cacheId), JSON.stringify(entry));
    } catch {
        // Cache writes are best-effort only.
    }
}

function splitDisplayName(name: string): { firstName: string; lastName: string } {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return {
        firstName: parts[0] || "",
        lastName: parts.slice(1).join(" "),
    };
}

function patchCachedSessionProfile(profile: AccountProfile): void {
    const patchKey = (key: string) => {
        try {
            const cached = JSON.parse(safeStorage.getItem(key) || "{}");
            safeStorage.setItem(key, JSON.stringify({
                ...cached,
                ...profile,
                email: profile.email || profile.email_address || cached.email,
                phone: profile.phone || profile.phone_number || cached.phone,
                name: profile.name || profile.full_name || cached.name,
            }));
        } catch {
            // Session cache updates are best-effort only.
        }
    };

    patchKey("nola_user");
    patchKey("nola_auth_user");
}

export const getCachedAccountProfile = (
    explicitLocationId?: string,
    options: Pick<FetchAccountProfileOptions, "includeAuth" | "cacheTtlMs"> & { allowExpired?: boolean } = {}
): AccountProfile | null => {
    const includeAuth = options.includeAuth ?? true;
    const locationId = resolveLocationId(explicitLocationId);
    if (!locationId) return null;

    return readCacheEntry(locationId, includeAuth, options.cacheTtlMs, options.allowExpired)?.profile ?? null;
};

function getLocationHeaders(explicitLocationId?: string, includeAuth = true): { headers: Record<string, string>; locationId: string } {
    const locationId = resolveLocationId(explicitLocationId);
    const headers: Record<string, string> = { "Content-Type": "application/json" };
    if (locationId) {
        headers["X-GHL-Location-ID"] = locationId;
    }
    if (includeAuth) {
        Object.assign(headers, getAuthHeaders());
    }
    return { headers, locationId };
}

/**
 * Fetch the basic account profile (including location_name).
 */
export const fetchAccountProfile = async (
    explicitLocationId?: string,
    options: FetchAccountProfileOptions = {}
): Promise<AccountProfile | null> => {
    const includeAuth = options.includeAuth ?? true;
    const { headers, locationId } = getLocationHeaders(explicitLocationId, includeAuth);

    if (!locationId) return null;

    const cacheTtlMs = options.cacheTtlMs ?? DEFAULT_ACCOUNT_PROFILE_CACHE_TTL_MS;
    const requestCacheId = cacheIdFor(locationId, includeAuth);
    const cached = readCacheEntry(locationId, includeAuth, cacheTtlMs);
    if (cached && !options.forceRefresh) {
        return cached.profile;
    }

    const existingRequest = accountProfileRequests.get(requestCacheId);
    if (existingRequest && !options.forceRefresh) {
        return existingRequest;
    }

    let url = API_CONFIG.account;
    url += `?location_id=${encodeURIComponent(locationId)}`;

    const request = (async () => {
        const res = await apiFetch(url, { headers });
        if (!res.ok) {
            devLog.error("[fetchAccountProfile] Error:", res.status);
            if (options.allowStaleOnError) {
                return readCacheEntry(locationId, includeAuth, cacheTtlMs, true)?.profile ?? null;
            }
            return null;
        }
        const data = await res.json();
        const profile = data.data || null;
        if (profile) {
            writeCacheEntry(locationId, includeAuth, profile);
        }
        return profile;
    })()
        .catch((error) => {
            devLog.error("[fetchAccountProfile] Network error:", error);
            if (options.allowStaleOnError) {
                return readCacheEntry(locationId, includeAuth, cacheTtlMs, true)?.profile ?? null;
            }
            return null;
        })
        .finally(() => {
            if (accountProfileRequests.get(requestCacheId) === request) {
                accountProfileRequests.delete(requestCacheId);
            }
        });

    accountProfileRequests.set(requestCacheId, request);
    return request;
};

export const updateAccountProfile = async (
    payload: UpdateAccountProfilePayload,
    explicitLocationId?: string
): Promise<AccountProfile> => {
    const { headers, locationId } = getLocationHeaders(explicitLocationId, true);
    const trimmedName = payload.name.trim();
    const trimmedEmail = payload.email.trim();
    const trimmedPhone = payload.phone.trim();
    const { firstName, lastName } = splitDisplayName(trimmedName);

    const res = await apiFetch(API_CONFIG.account, {
        method: "POST",
        headers,
        body: JSON.stringify({
            action: "update_profile",
            name: trimmedName,
            email: trimmedEmail,
            phone: trimmedPhone,
        }),
    });

    interface UpdateProfileResponse {
        status?: string;
        message?: string;
        error?: string;
        data?: Partial<AccountProfile>;
    }

    let data: UpdateProfileResponse | null = null;
    try {
        data = (await res.json()) as UpdateProfileResponse;
    } catch {
        data = null;
    }

    if (!res.ok || (data?.status && data.status !== "success")) {
        throw new Error(data?.message || data?.error || "Failed to update profile.");
    }

    const updatedProfile: AccountProfile = {
        ...(data?.data || {}),
        location_id: data?.data?.location_id || locationId,
        location_name: data?.data?.location_name || null,
        full_name: data?.data?.full_name || trimmedName,
        name: data?.data?.name || trimmedName,
        firstName: data?.data?.firstName || firstName,
        lastName: data?.data?.lastName || lastName,
        email: data?.data?.email || trimmedEmail,
        email_address: data?.data?.email_address || trimmedEmail,
        phone: data?.data?.phone || trimmedPhone,
        phone_number: data?.data?.phone_number || trimmedPhone,
    };

    if (updatedProfile.location_id) {
        writeCacheEntry(updatedProfile.location_id, true, updatedProfile);
        writeCacheEntry(updatedProfile.location_id, false, updatedProfile);
    }
    patchCachedSessionProfile(updatedProfile);

    return updatedProfile;
};
