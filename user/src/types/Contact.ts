export interface Contact {
  id: string;
  name: string;
  phone: string;
  ghl_contact_id?: string;
  lastMessage?: string;
  lastSentAt?: string;
  tags?: string[];
}