import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";
import type { Contact } from "../types/Contact";

// Use the GHL contacts proxy endpoint (calls GoHighLevel API directly)
const CONTACTS_API_URL = API_CONFIG.ghl_contacts;

export const fetchContacts = async (): Promise<Contact[]> => {
  try {
    // 1. Detect locationId from URL params (GHL may send either key)
    const urlParams = new URLSearchParams(window.location.search);
    const urlLocationId =
      urlParams.get('locationId') ||
      urlParams.get('location_id');

    // 2. Fall back to saved settings if URL doesn't have it
    const accountSettings = getAccountSettings();
    const locationId = urlLocationId || accountSettings.ghlLocationId || null;

    console.log('NOLA SMS: Detected GHL Location:', locationId);

    if (!locationId) {
      console.warn('NOLA SMS: No locationId detected in URL.');
      return [];
    }

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'X-GHL-Location-ID': locationId,
    };

    // 3. Send locationId as query param (primary) AND location_id as fallback, plus header
    const url = `${CONTACTS_API_URL}?locationId=${encodeURIComponent(locationId)}&location_id=${encodeURIComponent(locationId)}`;
    const res = await fetch(url, { headers });

    if (!res.ok) {
      console.error('NOLA SMS: Contacts API error:', res.status, res.statusText);
      return [];
    }

    const data = await res.json();
    console.log('NOLA SMS: Contacts API response:', data);

    // Handle various response formats: 
    // - Array of contacts
    // - { data: [...] }
    // - { contacts: [...] }
    // - { data: { contacts: [...] } }
    let contacts: any[] = [];
    if (Array.isArray(data)) {
      contacts = data;
    } else if (data.contacts && Array.isArray(data.contacts)) {
      contacts = data.contacts;
    } else if (data.data && Array.isArray(data.data)) {
      contacts = data.data;
    } else if (data.data?.contacts && Array.isArray(data.data.contacts)) {
      contacts = data.data.contacts;
    }

    console.log('Contacts fetched:', contacts.length);

    // Normalize GHL raw contact format → our Contact shape
    // GHL returns: contactName, firstName, lastName, phone, email, id
    // We need:     name, phone, email, id
    contacts = contacts.map((c: any) => {
      const name = c.name
        || c.contactName
        || [c.firstName, c.lastName].filter(Boolean).join(' ').trim()
        || c.firstNameRaw
        || c.phone
        || 'Unknown';

      // Normalize phone to 09XXXXXXXXX format (aligned with send_sms.php clean_numbers)
      let phone = c.phone ?? c.mobileNumber ?? '';
      if (phone) {
        const digits = phone.replace(/\D/g, '');
        if (/^639\d{9}$/.test(digits)) phone = '0' + digits.slice(2);        // 639XXXXXXXXX → 09XXXXXXXXX
        else if (/^9\d{9}$/.test(digits)) phone = '0' + digits;              // 9XXXXXXXXX   → 09XXXXXXXXX
        else if (/^09\d{9}$/.test(digits)) phone = digits;                   // Already correct
        else phone = digits || phone;  // fallback
      }

      return {
        id: c.id ?? String(Math.random()),
        name,
        phone,
        email: c.email ?? '',
        ghl_contact_id: c.ghl_contact_id ?? undefined,
        lastMessage: c.lastMessage ?? undefined,
        lastSentAt: c.lastSentAt ?? undefined,
        tags: Array.isArray(c.tags) ? c.tags : []
      };
    });

    if (contacts.length > 0) {
      console.log('NOLA SMS: First contact sample:', JSON.stringify(contacts[0]));
      console.log('NOLA SMS: All contact names/phones:', contacts.map((c: any) => `${c.name} (${c.phone})`));
    }
    return contacts;

  } catch (error) {
    console.error('Failed to fetch contacts:', error);
    return [];
  }
};

export interface AddContactParams {
  name: string;
  phone: string;
  email?: string;
}

export const addContact = async (params: AddContactParams): Promise<Contact | null> => {
  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    };

    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    const url = accountSettings.ghlLocationId 
      ? `${CONTACTS_API_URL}?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`
      : CONTACTS_API_URL;

    // Strip empty optional fields — GHL rejects email: "" with "email must be an email"
    const body: Record<string, string> = { name: params.name, phone: params.phone };
    if (params.email) body.email = params.email;

    const res = await fetch(url, {
      method: 'POST',
      headers,
      body: JSON.stringify(body),
    });

    if (!res.ok) {
      const error = await res.json();
      console.error('Failed to add contact:', error);
      // GHL returns { message: [...], error: 'Unprocessable Entity' }
      const msg = Array.isArray(error.message) ? error.message.join(', ') : (error.message || error.details || error.error || 'Failed to add contact');
      throw new Error(msg);
    }

    const contact = await res.json();
    console.log('Contact added:', contact);
    return contact;
  } catch (error) {
    console.error('Failed to add contact:', error);
    throw error;
  }
};

export interface UpdateContactParams {
  id: string;
  name: string;
  phone: string;
  email?: string;
}

export const updateContact = async (params: UpdateContactParams): Promise<Contact | null> => {
  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    };

    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    const url = accountSettings.ghlLocationId 
      ? `${CONTACTS_API_URL}?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`
      : CONTACTS_API_URL;

    // Strip empty optional fields — GHL rejects email: "" with "email must be an email"
    const body: Record<string, string> = { id: params.id, name: params.name, phone: params.phone };
    if (params.email) body.email = params.email;

    const res = await fetch(url, {
      method: 'PUT',
      headers,
      body: JSON.stringify(body),
    });

    if (!res.ok) {
      const error = await res.json();
      console.error('Failed to update contact:', error);
      const msg = Array.isArray(error.message) ? error.message.join(', ') : (error.message || error.details || error.error || 'Failed to update contact');
      throw new Error(msg);
    }

    const contact = await res.json();
    console.log('Contact updated:', contact);
    return contact;
  } catch (error) {
    console.error('Failed to update contact:', error);
    throw error;
  }
};

export const deleteContact = async (id: string): Promise<boolean> => {
  try {
    const accountSettings = getAccountSettings();
    const headers: Record<string, string> = {};

    if (accountSettings.ghlLocationId) {
      headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
    }

    let url = `${CONTACTS_API_URL}?id=${encodeURIComponent(id)}`;
    if (accountSettings.ghlLocationId) {
      url += `&location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
    }

    const res = await fetch(url, {
      method: 'DELETE',
      headers,
    });

    if (!res.ok) {
      const error = await res.json();
      console.error('Failed to delete contact:', error);
      throw new Error(error.details || error.error || 'Failed to delete contact');
    }

    console.log('Contact deleted:', id);
    return true;
  } catch (error) {
    console.error('Failed to delete contact:', error);
    throw error;
  }
};
