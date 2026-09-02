import { devLog } from '../utils/devLog';
import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";
import { getAuthHeaders } from "../utils/authHeaders";
import type { Template } from "../types/Template";
import { getCachedTemplates, setCachedTemplates, invalidateTemplateCache } from "../utils/templateCache";
import { apiFetch } from "../utils/apiFetch";

const API_URL = API_CONFIG.templates;
const DEFAULT_CATEGORY = "General";

const getHeaders = (explicitLocationId?: string) => {
  const accountSettings = getAccountSettings();
  const locationId = explicitLocationId || accountSettings.ghlLocationId || null;
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...getAuthHeaders(),
  };
  if (locationId) {
    headers['X-GHL-Location-ID'] = locationId;
  }
  return headers;
};

const normalizeTemplate = (raw: Record<string, unknown> | null | undefined): Template => ({
  id: String(raw?.id ?? raw?.template_id ?? `tpl-${Date.now()}`),
  location_id: String(raw?.location_id ?? raw?.locationId ?? getAccountSettings().ghlLocationId ?? ""),
  name: String(raw?.name ?? ""),
  content: String(raw?.content ?? raw?.message ?? ""),
  category: String(raw?.category ?? DEFAULT_CATEGORY),
  created_at: String(raw?.created_at ?? raw?.createdAt ?? new Date().toISOString()),
  updated_at: String(raw?.updated_at ?? raw?.updatedAt ?? new Date().toISOString()),
});

const unwrapTemplateList = (payload: unknown): Template[] => {
  if (!payload) return [];
  
  let rawList: unknown[] = [];
  if (Array.isArray(payload)) {
    rawList = payload;
  } else if (payload && typeof payload === 'object') {
    const obj = payload as Record<string, unknown>;
    if (Array.isArray(obj.data)) {
      rawList = obj.data;
    } else if (Array.isArray(obj.templates)) {
      rawList = obj.templates;
    } else if (obj.data && typeof obj.data === 'object') {
      const dataObj = obj.data as Record<string, unknown>;
      if (Array.isArray(dataObj.templates)) {
        rawList = dataObj.templates;
      }
    }
  }

  return rawList.map(item => normalizeTemplate(item as Record<string, unknown>));
};

const unwrapTemplate = (payload: unknown): Template => {
  if (!payload || typeof payload !== 'object') {
    return normalizeTemplate(undefined);
  }
  const obj = payload as Record<string, unknown>;
  const raw = obj.data && !Array.isArray(obj.data)
    ? obj.data
    : obj.template
      ? obj.template
      : obj;
  return normalizeTemplate(raw as Record<string, unknown>);
};

export const fetchTemplates = async (explicitLocationId?: string, forceRefresh = false): Promise<Template[]> => {
  const accountSettings = getAccountSettings();
  const locationId = explicitLocationId || accountSettings.ghlLocationId || undefined;
  if (!locationId) return [];

  if (!forceRefresh) {
    const cached = getCachedTemplates(locationId);
    if (cached) return cached;
  }

  try {
    let url = API_URL;
    if (locationId) {
      url += `?location_id=${encodeURIComponent(locationId)}`;
    }

    const res = await apiFetch(url, { headers: getHeaders(locationId) });
    
    // Handle mock mode for when backend doesn't exist yet
    if (res.status === 404) {
      devLog.warn('NOLA SMS: Templates backend not available yet (404)');
      // Return mock data for UI testing if the user is testing the UI before backend is ready
      const mockStr = localStorage.getItem('nola_mock_templates');
      const result = mockStr ? JSON.parse(mockStr) : [];
      setCachedTemplates(locationId, result);
      return result;
    }
    
    if (!res.ok) {
      throw new Error(`Error fetching templates: ${res.statusText}`);
    }
    const result = unwrapTemplateList(await res.json());
    setCachedTemplates(locationId, result);
    return result;
  } catch (error) {
    devLog.error('Failed to fetch templates:', error);
    const mockStr = localStorage.getItem('nola_mock_templates');
    const result = mockStr ? JSON.parse(mockStr) : [];
    setCachedTemplates(locationId, result);
    return result;
  }
};

export const createTemplate = async (name: string, content: string, category: string = DEFAULT_CATEGORY): Promise<Template> => {
  const activeLocationId = getAccountSettings().ghlLocationId || undefined;
  try {
    const res = await apiFetch(API_URL, {
      method: 'POST',
      headers: getHeaders(activeLocationId),
      body: JSON.stringify({ name, content, category }),
    });
    
    invalidateTemplateCache(activeLocationId);

    if (res.status === 404) {
      // Mock logic for local testing before backend is ready
      const mockStr = localStorage.getItem('nola_mock_templates');
      const templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      const newTemp: Template = {
        id: `mock-${Date.now()}`,
        location_id: activeLocationId || '',
        name,
        content,
        category,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      };
      templates.push(newTemp);
      localStorage.setItem('nola_mock_templates', JSON.stringify(templates));
      return newTemp;
    }
    
    if (!res.ok) {
      const error = await res.json().catch(() => ({}));
      throw new Error(error.message || 'Failed to create template');
    }
    return unwrapTemplate(await res.json());
  } catch (error: unknown) {
    invalidateTemplateCache(activeLocationId);
    const errorMessage = error instanceof Error ? error.message : "";
    // Mock logic fallback
    if (errorMessage === 'Failed to fetch') {
      const mockStr = localStorage.getItem('nola_mock_templates');
      const templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      const newTemp: Template = {
        id: `mock-${Date.now()}`,
        location_id: activeLocationId || '',
        name,
        content,
        category,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      };
      templates.push(newTemp);
      localStorage.setItem('nola_mock_templates', JSON.stringify(templates));
      return newTemp;
    }
    throw error;
  }
};

export const updateTemplate = async (id: string, name: string, content: string, category: string = DEFAULT_CATEGORY): Promise<Template> => {
  const activeLocationId = getAccountSettings().ghlLocationId || undefined;
  try {
    const res = await apiFetch(API_URL, {
      method: 'PUT',
      headers: getHeaders(activeLocationId),
      body: JSON.stringify({ id, name, content, category }),
    });
    
    invalidateTemplateCache(activeLocationId);

    if (res.status === 404) {
      const mockStr = localStorage.getItem('nola_mock_templates');
      let templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      let updated: Template | null = null;
      templates = templates.map(t => {
        if (t.id === id) {
          updated = { ...t, name, content, category, updated_at: new Date().toISOString() };
          return updated;
        }
        return t;
      });
      localStorage.setItem('nola_mock_templates', JSON.stringify(templates));
      if (!updated) throw new Error('Mock template not found');
      return updated;
    }

    if (!res.ok) {
      const error = await res.json().catch(() => ({}));
      throw new Error(error.message || 'Failed to update template');
    }
    return unwrapTemplate(await res.json());
  } catch (error: unknown) {
    invalidateTemplateCache(activeLocationId);
    const errorMessage = error instanceof Error ? error.message : "";
    // Mock logic fallback
    if (errorMessage === 'Failed to fetch') {
      const mockStr = localStorage.getItem('nola_mock_templates');
      let templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      let updated: Template | null = null;
      templates = templates.map(t => {
        if (t.id === id) {
          updated = { ...t, name, content, category, updated_at: new Date().toISOString() };
          return updated;
        }
        return t;
      });
      localStorage.setItem('nola_mock_templates', JSON.stringify(templates));
      if (!updated) throw new Error('Mock template not found');
      return updated;
    }
    throw error;
  }
};

export const deleteTemplate = async (id: string): Promise<void> => {
  const activeLocationId = getAccountSettings().ghlLocationId || undefined;
  try {
    const res = await apiFetch(`${API_URL}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: getHeaders(activeLocationId),
    });
    
    invalidateTemplateCache(activeLocationId);

    if (res.status === 404) {
      const mockStr = localStorage.getItem('nola_mock_templates');
      let templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      templates = templates.filter(t => t.id !== id);
      localStorage.setItem('nola_mock_templates', JSON.stringify(templates));
      return;
    }

    if (!res.ok) {
      const error = await res.json().catch(() => ({}));
      throw new Error(error.message || 'Failed to delete template');
    }
  } catch (error: unknown) {
    invalidateTemplateCache(activeLocationId);
    const errorMessage = error instanceof Error ? error.message : "";
    // Mock logic fallback
    if (errorMessage === 'Failed to fetch') {
      const mockStr = localStorage.getItem('nola_mock_templates');
      let templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      templates = templates.filter(t => t.id !== id);
      localStorage.setItem('nola_mock_templates', JSON.stringify(templates));
      return;
    }
    throw error;
  }
};
