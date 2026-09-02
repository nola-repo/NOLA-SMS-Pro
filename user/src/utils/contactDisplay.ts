import type { Contact } from "../types/Contact";

export const isPhoneLike = (value: string): boolean => /^[\d+\-() ]+$/.test(value);

export const toProperCase = (name: string): string =>
  name.replace(/\b\w/g, (char) => char.toUpperCase());

export const getPhoneLookupKeys = (value: string | undefined | null): string[] => {
  const raw = (value || "").trim();
  if (!raw) return [];

  const digits = raw.replace(/\D/g, "");
  const keys = new Set<string>([raw]);

  if (digits) {
    keys.add(digits);

    if (/^639\d{9}$/.test(digits)) {
      keys.add(`0${digits.slice(2)}`);
    }
    if (/^9\d{9}$/.test(digits)) {
      keys.add(`0${digits}`);
    }
    if (/^09\d{9}$/.test(digits)) {
      keys.add(`63${digits.slice(1)}`);
      keys.add(digits.slice(1));
    }
    if (digits.length >= 10) keys.add(digits.slice(-10));
    if (digits.length >= 9) keys.add(digits.slice(-9));
  }

  return Array.from(keys);
};

export const buildContactNameLookup = (contacts: Contact[]): Map<string, string> => {
  const map = new Map<string, string>();

  contacts.forEach((contact) => {
    const name = (contact.name || "").trim();
    if (!name) return;

    getPhoneLookupKeys(contact.phone).forEach((key) => {
      if (!map.has(key)) map.set(key, name);
    });
  });

  return map;
};

export const resolveContactNameByPhone = (
  contactLookup: Map<string, string>,
  phone: string | undefined | null
): string | undefined => {
  for (const key of getPhoneLookupKeys(phone)) {
    const match = contactLookup.get(key);
    if (match) return match;
  }

  return undefined;
};
