import type { Template } from "../types/Template";

const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes

interface CachedTemplates {
  templates: Template[];
  fetchedAt: number;
}

const cache = new Map<string, CachedTemplates>();

export const getCachedTemplates = (locationId: string | undefined): Template[] | null => {
  const key = locationId || "global";
  const cached = cache.get(key);
  if (!cached) return null;

  const isExpired = Date.now() - cached.fetchedAt > CACHE_TTL_MS;
  if (isExpired) {
    cache.delete(key);
    return null;
  }

  return cached.templates;
};

export const setCachedTemplates = (locationId: string | undefined, templates: Template[]): void => {
  const key = locationId || "global";
  cache.set(key, {
    templates,
    fetchedAt: Date.now()
  });
};

export const invalidateTemplateCache = (locationId: string | undefined): void => {
  const key = locationId || "global";
  cache.delete(key);
};
