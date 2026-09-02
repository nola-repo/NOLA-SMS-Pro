import { safeStorage } from './safeStorage';
import { normalizeLocationCandidate } from './ghlLocationDetection';

export const ACTIVE_GHL_LOCATION_STORAGE_KEY = 'nola_active_ghl_location_id';
const ACTIVE_GHL_LOCATION_TIMESTAMP_KEY = 'nola_active_ghl_location_id_at';
const DEFAULT_MAX_AGE_MS = 12 * 60 * 60 * 1000;

export function persistActiveGhlLocation(locationId: string): void {
  const normalized = normalizeLocationCandidate(locationId);
  if (!normalized) return;

  const now = String(Date.now());
  try {
    sessionStorage.setItem(ACTIVE_GHL_LOCATION_STORAGE_KEY, normalized);
    sessionStorage.setItem(ACTIVE_GHL_LOCATION_TIMESTAMP_KEY, now);
  } catch {
    // Embedded browsers can block sessionStorage.
  }

  safeStorage.setItem(ACTIVE_GHL_LOCATION_STORAGE_KEY, normalized);
  safeStorage.setItem(ACTIVE_GHL_LOCATION_TIMESTAMP_KEY, now);
}

export function readActiveGhlLocation(maxAgeMs = DEFAULT_MAX_AGE_MS): string {
  const readPair = (storage: Storage | null): { value: string; timestamp: number } | null => {
    if (!storage) return null;
    try {
      const value = normalizeLocationCandidate(storage.getItem(ACTIVE_GHL_LOCATION_STORAGE_KEY));
      if (!value) return null;
      const timestamp = Number(storage.getItem(ACTIVE_GHL_LOCATION_TIMESTAMP_KEY) || 0);
      return { value, timestamp };
    } catch {
      return null;
    }
  };

  const fromSession = (() => {
    try {
      return readPair(sessionStorage);
    } catch {
      return null;
    }
  })();

  const fromSafeStorage = (() => {
    const value = normalizeLocationCandidate(safeStorage.getItem(ACTIVE_GHL_LOCATION_STORAGE_KEY));
    if (!value) return null;
    const timestamp = Number(safeStorage.getItem(ACTIVE_GHL_LOCATION_TIMESTAMP_KEY) || 0);
    return { value, timestamp };
  })();

  const entry = fromSession || fromSafeStorage;
  if (!entry) return '';
  if (entry.timestamp && Date.now() - entry.timestamp > maxAgeMs) return '';
  return entry.value;
}

export function clearActiveGhlLocation(): void {
  try {
    sessionStorage.removeItem(ACTIVE_GHL_LOCATION_STORAGE_KEY);
    sessionStorage.removeItem(ACTIVE_GHL_LOCATION_TIMESTAMP_KEY);
  } catch {
    // Embedded browsers can block sessionStorage.
  }

  safeStorage.removeItem(ACTIVE_GHL_LOCATION_STORAGE_KEY);
  safeStorage.removeItem(ACTIVE_GHL_LOCATION_TIMESTAMP_KEY);
}
