export interface SmsStats {
  totalSent: number;
  delivered: number;
  failed: number;
  lastSentAt: string;
}

/** One row from the `messages` Firestore collection */
export interface FirestoreMessage {
  id: string;
  conversation_id: string;
  number: string;
  message: string;
  direction: 'inbound' | 'outbound';
  sender_id: string;
  status: string;
  batch_id?: string;
  created_at: string | { _seconds: number; _nanoseconds: number } | null;
  name?: string;
  location_id?: string;
  error_reason?: string;
}

/** One row from the `conversations` Firestore collection */
export interface Conversation {
  id: string;             // e.g. conv_09XXXXXXXXX  |  group_batch_xxx
  type: 'direct' | 'bulk' | 'group' | null;
  members: string[];      // normalised phone numbers
  last_message: string;
  last_message_at: string | null;
  name: string;
  updated_at: string | null;
  location_id?: string;
  ghl_contact_id?: string;
}

export interface BulkMessageHistoryItem {
  id: string;
  message: string;
  recipientCount: number;
  recipientNames?: string[];
  recipientNumbers: string[];
  recipientKey: string;
  customName?: string;
  timestamp: string;
  status: string;
  batchId?: string;
  fromDatabase?: boolean;
  locationId?: string;
}

export interface SmsLog {
  message_id: string;
  number?: string;  // Single recipient number
  numbers: string[];
  message: string;
  sender_id: string;
  status: string;
  date_created?: string | { _seconds: number; _nanoseconds: number };
  source?: string;
  direction?: 'inbound' | 'outbound';
  batch_id?: string;
  recipient_key?: string;
  location_id?: string;
  error_reason?: string;
}

export interface Message {
  id: string;
  text: string;
  timestamp: Date;
  senderName: string;
  status: 'sending' | 'sent' | 'delivered' | 'failed';
  errorReason?: string;
  // Extra fields for compatibility
  batch_id?: string;
  message?: string;
  date_created?: string | { _seconds: number; _nanoseconds: number };
}