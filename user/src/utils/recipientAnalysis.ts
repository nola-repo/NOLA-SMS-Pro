import type { Contact } from '../types/Contact';
import { normalizePHNumber } from '../api/sms';

export type RecipientAnalysis = {
  uniqueRecipients: Contact[];
  invalidRecipients: Contact[];
  duplicateCount: number;
  duplicatePhones: string[];
  totalCount: number;
  uniqueCount: number;
};

export type ContactWithPhoneAliases = Contact & {
  phone_number?: string;
  phoneNumber?: string;
  mobileNumber?: string;
  number?: string;
};

export const resolveContactPhone = (contact: ContactWithPhoneAliases | undefined | null): string => {
  return (
    contact?.phone ||
    contact?.phone_number ||
    contact?.phoneNumber ||
    contact?.mobileNumber ||
    contact?.number ||
    ""
  ).trim();
};

export const normalizeRecipient = (contact: Contact): Contact => ({
  ...contact,
  phone: resolveContactPhone(contact),
});

export const contactPhoneKey = (phone?: string | null): string =>
  (phone || "").replace(/\D/g, "").slice(-10);

export const analyzeRecipients = (recipients: Contact[]): RecipientAnalysis => {
  const uniqueRecipients: Contact[] = [];
  const invalidRecipients: Contact[] = [];
  const duplicatePhones = new Set<string>();
  const seen = new Set<string>();

  recipients.forEach((recipient) => {
    const normalized = normalizePHNumber(resolveContactPhone(recipient));
    if (!normalized) {
      invalidRecipients.push(recipient);
      return;
    }

    if (seen.has(normalized)) {
      duplicatePhones.add(normalized);
      return;
    }

    seen.add(normalized);
    uniqueRecipients.push({ ...recipient, phone: normalized });
  });

  return {
    uniqueRecipients,
    invalidRecipients,
    duplicateCount: recipients.length - invalidRecipients.length - uniqueRecipients.length,
    duplicatePhones: Array.from(duplicatePhones),
    totalCount: recipients.length,
    uniqueCount: uniqueRecipients.length,
  };
};

export const matchesContactUpdate = (target: Contact, contact: Contact, previous?: Contact | null): boolean => {
  if (target.id === contact.id || (previous && target.id === previous.id)) return true;
  const targetPhone = contactPhoneKey(resolveContactPhone(target));
  return !!targetPhone && (
    targetPhone === contactPhoneKey(resolveContactPhone(contact)) ||
    targetPhone === contactPhoneKey(resolveContactPhone(previous))
  );
};
