import { describe, it, expect } from 'vitest';
import {
  isPhoneLike,
  toProperCase,
  getPhoneLookupKeys,
  buildContactNameLookup,
  resolveContactNameByPhone,
} from '../contactDisplay';
import type { Contact } from '../../types/Contact';

describe('contactDisplay utils', () => {
  describe('isPhoneLike', () => {
    it('identifies phone-like strings', () => {
      expect(isPhoneLike('09171234567')).toBe(true);
      expect(isPhoneLike('+63 917 123 4567')).toBe(true);
      expect(isPhoneLike('(0917) 123-4567')).toBe(true);
    });

    it('rejects non-phone strings', () => {
      expect(isPhoneLike('Juan Dela Cruz')).toBe(false);
      expect(isPhoneLike('juan@example.com')).toBe(false);
    });
  });

  describe('toProperCase', () => {
    it('converts names to proper title case', () => {
      expect(toProperCase('juan dela cruz')).toBe('Juan Dela Cruz');
      expect(toProperCase('MARIA CLARA')).toBe('MARIA CLARA');
    });
  });

  describe('getPhoneLookupKeys', () => {
    it('returns empty array for empty inputs', () => {
      expect(getPhoneLookupKeys('')).toEqual([]);
      expect(getPhoneLookupKeys(null)).toEqual([]);
      expect(getPhoneLookupKeys(undefined)).toEqual([]);
    });

    it('generates normalized keys for Philippine phone 09XXXXXXXXX format', () => {
      const keys = getPhoneLookupKeys('09171234567');
      expect(keys).toContain('09171234567');
      expect(keys).toContain('639171234567');
      expect(keys).toContain('9171234567');
    });

    it('generates normalized keys for +639XXXXXXXXX format', () => {
      const keys = getPhoneLookupKeys('+639171234567');
      expect(keys).toContain('639171234567');
      expect(keys).toContain('09171234567');
    });

    it('generates normalized keys for 9XXXXXXXXX format', () => {
      const keys = getPhoneLookupKeys('9171234567');
      expect(keys).toContain('09171234567');
    });
  });

  describe('buildContactNameLookup & resolveContactNameByPhone', () => {
    const mockContacts: Contact[] = [
      { id: '1', name: 'Juan Dela Cruz', phone: '09171234567' },
      { id: '2', name: 'Maria Santos', phone: '+639189876543' },
    ];

    it('builds lookup map and resolves contact name regardless of phone format variation', () => {
      const lookup = buildContactNameLookup(mockContacts);

      // Search Juan with different formats
      expect(resolveContactNameByPhone(lookup, '09171234567')).toBe('Juan Dela Cruz');
      expect(resolveContactNameByPhone(lookup, '+639171234567')).toBe('Juan Dela Cruz');
      expect(resolveContactNameByPhone(lookup, '639171234567')).toBe('Juan Dela Cruz');
      expect(resolveContactNameByPhone(lookup, '9171234567')).toBe('Juan Dela Cruz');

      // Search Maria
      expect(resolveContactNameByPhone(lookup, '09189876543')).toBe('Maria Santos');
      expect(resolveContactNameByPhone(lookup, '+639189876543')).toBe('Maria Santos');
    });

    it('returns undefined for unknown phone number', () => {
      const lookup = buildContactNameLookup(mockContacts);
      expect(resolveContactNameByPhone(lookup, '09990000000')).toBeUndefined();
    });
  });
});
