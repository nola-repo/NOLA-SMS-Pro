export interface Contact {
  id: string;
  name: string;
  phone: string;
  email?: string;
  ghl_contact_id?: string;
  lastMessage?: string;
  lastSentAt?: string;
  lastMessageDirection?: 'inbound' | 'outbound';
  unread?: boolean;
  source?: string;
  tags?: string[];
}
