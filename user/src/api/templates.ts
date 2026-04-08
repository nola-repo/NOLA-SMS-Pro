import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";
import type { Template } from "../types/Template";

const API_URL = API_CONFIG.templates;

const getHeaders = () => {
  const accountSettings = getAccountSettings();
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  };
  if (accountSettings.ghlLocationId) {
    headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
  }
  return headers;
};

export const fetchTemplates = async (): Promise<Template[]> => {
  try {
    const accountSettings = getAccountSettings();
    if (!accountSettings.ghlLocationId) return [];

    const res = await fetch(API_URL, { headers: getHeaders() });
    
    // Handle mock mode for when backend doesn't exist yet
    if (res.status === 404) {
      console.warn('NOLA SMS: Templates backend not available yet (404)');
      // Return mock data for UI testing if the user is testing the UI before backend is ready
      const mockStr = localStorage.getItem('nola_mock_templates');
      return mockStr ? JSON.parse(mockStr) : [];
    }
    
    if (!res.ok) {
      throw new Error(`Error fetching templates: ${res.statusText}`);
    }
    return await res.json();
  } catch (error) {
    console.error('Failed to fetch templates:', error);
    const mockStr = localStorage.getItem('nola_mock_templates');
    return mockStr ? JSON.parse(mockStr) : [];
  }
};

export const createTemplate = async (name: string, content: string): Promise<Template> => {
  try {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({ name, content }),
    });
    
    if (res.status === 404) {
      // Mock logic for local testing before backend is ready
      const mockStr = localStorage.getItem('nola_mock_templates');
      const templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      const newTemp: Template = {
        id: `mock-${Date.now()}`,
        location_id: getAccountSettings().ghlLocationId || '',
        name,
        content,
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
    return await res.json();
  } catch (error: any) {
    // Mock logic fallback
    if (error.message === 'Failed to fetch') {
      const mockStr = localStorage.getItem('nola_mock_templates');
      const templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      const newTemp: Template = {
        id: `mock-${Date.now()}`,
        location_id: getAccountSettings().ghlLocationId || '',
        name,
        content,
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

export const updateTemplate = async (id: string, name: string, content: string): Promise<Template> => {
  try {
    const res = await fetch(API_URL, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ id, name, content }),
    });
    
    if (res.status === 404) {
      const mockStr = localStorage.getItem('nola_mock_templates');
      let templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      let updated: Template | null = null;
      templates = templates.map(t => {
        if (t.id === id) {
          updated = { ...t, name, content, updated_at: new Date().toISOString() };
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
    return await res.json();
  } catch (error: any) {
    // Mock logic fallback
    if (error.message === 'Failed to fetch') {
      const mockStr = localStorage.getItem('nola_mock_templates');
      let templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      let updated: Template | null = null;
      templates = templates.map(t => {
        if (t.id === id) {
          updated = { ...t, name, content, updated_at: new Date().toISOString() };
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
  try {
    const res = await fetch(`${API_URL}?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: getHeaders(),
    });
    
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
  } catch (error: any) {
    // Mock logic fallback
    if (error.message === 'Failed to fetch') {
      const mockStr = localStorage.getItem('nola_mock_templates');
      let templates: Template[] = mockStr ? JSON.parse(mockStr) : [];
      templates = templates.filter(t => t.id !== id);
      localStorage.setItem('nola_mock_templates', JSON.stringify(templates));
      return;
    }
    throw error;
  }
};
