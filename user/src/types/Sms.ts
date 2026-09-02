export type FirestoreTimestamp = string | { _seconds: number; _nanoseconds: number } | { seconds: number; nanoseconds: number } | { toDate: () => Date } | Date | null;

export interface SmsStats {
  totalSent: number;
  delivered: number;
  failed: number;
  lastSentAt: string;
}

/** Full message document contract from messages/{messageId} and sms_logs/{messageId} */
export interface NolaMessageDoc {
  message_id: string;
  location_id: string;
  number: string;
  message: string;
  direction: 'outbound' | 'inbound';
  sender_id: string;
  sender_name: string;
  status: 'Sending' | 'Pending' | 'Sent' | 'Failed' | string;
  origin?:
    | 'ghl_provider_preflight'
    | 'ghl_provider_retry_queued'
    | 'retry_worker_processing'
    | 'retry_worker_success'
    | 'retry_worker_exhausted'
    | 'ghl_provider'
    | 'ghl_provider_failed'
    | string;
  retry_doc_id?: string;
  retry_status?: 'pending_retry' | 'processing' | 'completed' | 'exhausted';
  retry_count?: number;
  retry_max_attempts?: number;
  next_retry_at?: FirestoreTimestamp;
  last_retry_at?: FirestoreTimestamp;
  ghl_message_id?: string | null;
  ghl_contact_id?: string | null;
  provider?: 'semaphore' | 'unisms' | 'ghl_provider' | string;
  provider_message_id?: string | null;
  provider_reference_id?: string | null;
  provider_error?: string | null;
  created_at: FirestoreTimestamp;
  updated_at?: FirestoreTimestamp;
}

/** Documents from sms_retry_queue/{retryDocId} */
export interface SmsRetryQueueDoc {
  retry_doc_id: string;
  message_id: string;
  ghl_message_id: string | null;
  location_id: string;
  phone: string;
  message: string;
  sender_id: string;
  api_key: string | null;
  provider_pref: 'system' | 'semaphore' | 'unisms' | string;
  provider: 'semaphore' | 'unisms' | string;
  account_id: string;
  billing_reference_id: string;
  billing_charged: boolean;
  billing_master_lock: boolean;
  agency_id: string;
  required_credits: number;
  using_free_credits: boolean;
  status: 'pending_retry' | 'processing' | 'completed' | 'exhausted';
  attempts: number;
  max_attempts: number;
  last_error: string | null;
  worker_id?: string | null;
  processing_started_at?: FirestoreTimestamp;
  lease_expires_at?: FirestoreTimestamp | null;
  created_at: FirestoreTimestamp;
  updated_at: FirestoreTimestamp;
  next_retry_at: FirestoreTimestamp;
  completed_at?: FirestoreTimestamp;
  exhausted_at?: FirestoreTimestamp;
  final_provider?: string;
  final_provider_message_id?: string | null;
}

/** One row from the `messages` Firestore collection */
export interface FirestoreMessage {
  id: string;
  conversation_id: string;
  number: string;
  from?: string;
  to?: string;
  message: string;
  direction: 'inbound' | 'outbound';
  sender_id: string;
  sender_name?: string;
  status: string;
  batch_id?: string;
  recipient_key?: string;
  created_at: FirestoreTimestamp;
  date_received?: FirestoreTimestamp;
  name?: string;
  location_id?: string;
  origin?: string;
  unisms_virtual_number_id?: string;
  unisms_txt_conversation_id?: string;
  retry_doc_id?: string;
  retry_status?: 'pending_retry' | 'processing' | 'completed' | 'exhausted';
  retry_count?: number;
  retry_max_attempts?: number;
  next_retry_at?: FirestoreTimestamp;
  last_retry_at?: FirestoreTimestamp;
  error_reason?: string;
  error_code?: string;
  provider?: string;
  provider_error?: string | null;
  provider_status?: string;
  provider_response?: string | Record<string, unknown> | null;
  provider_message_id?: string | null;
  provider_reference_id?: string | null;
  ghl_sync_queued?: boolean;
  ghl_sync_job_id?: string;
  ghl_sync_success?: boolean;
  ghl_sync_skipped?: boolean;
  ghl_sync_reason?: string;
  ghl_sync_error?: string;
  ghl_sync_http_status?: number;
  ghl_sync_updated_at?: string;
  ghl_message_id?: string | null;
}

/** One row from the `conversations` Firestore collection */
export interface Conversation {
  id: string;             // e.g. conv_09XXXXXXXXX  |  group_batch_xxx
  type: 'direct' | 'bulk' | 'group' | null;
  members: string[];      // normalised phone numbers
  last_message: string;
  last_message_at: string | null;
  last_message_direction?: 'inbound' | 'outbound';
  last_message_sender?: string;
  name: string;
  updated_at: string | null;
  location_id?: string;
  ghl_contact_id?: string;
  unread?: boolean;
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
  sender_name?: string;
  status: string;
  date_created?: FirestoreTimestamp;
  source?: string;
  direction?: 'inbound' | 'outbound';
  batch_id?: string;
  recipient_key?: string;
  location_id?: string;
  origin?: string;
  retry_doc_id?: string;
  retry_status?: 'pending_retry' | 'processing' | 'completed' | 'exhausted';
  retry_count?: number;
  retry_max_attempts?: number;
  next_retry_at?: FirestoreTimestamp;
  last_retry_at?: FirestoreTimestamp;
  error_reason?: string;
  error_code?: string;
  provider?: string;
  provider_error?: string | null;
  provider_status?: string;
  provider_response?: string | Record<string, unknown> | null;
  provider_message_id?: string | null;
  provider_reference_id?: string | null;
  ghl_sync_queued?: boolean;
  ghl_sync_job_id?: string;
  ghl_sync_success?: boolean;
  ghl_sync_skipped?: boolean;
  ghl_sync_reason?: string;
  ghl_sync_error?: string;
  ghl_sync_http_status?: number;
  ghl_sync_updated_at?: string;
  ghl_message_id?: string | null;
}

export interface Message {
  id: string;
  text: string;
  timestamp: Date;
  senderName: string;
  direction?: 'inbound' | 'outbound';
  status: 'sending' | 'sent' | 'delivered' | 'failed' | 'received' | 'pending' | string;
  from?: string;
  to?: string;
  date_received?: FirestoreTimestamp;
  unisms_virtual_number_id?: string;
  unisms_txt_conversation_id?: string;
  errorReason?: string;
  errorCode?: string;
  provider?: string;
  providerError?: string | null;
  providerStatus?: string;
  providerResponse?: string | Record<string, unknown> | null;
  providerMessageId?: string | null;
  providerReferenceId?: string | null;
  // Retry queue fields
  origin?: string;
  retryDocId?: string;
  retryStatus?: 'pending_retry' | 'processing' | 'completed' | 'exhausted';
  retryCount?: number;
  retryMaxAttempts?: number;
  nextRetryAt?: Date | null;
  lastRetryAt?: Date | null;
  retry_doc_id?: string;
  retry_status?: 'pending_retry' | 'processing' | 'completed' | 'exhausted';
  retry_count?: number;
  retry_max_attempts?: number;
  next_retry_at?: FirestoreTimestamp;
  last_retry_at?: FirestoreTimestamp;
  provider_error?: string | null;
  // GHL Sync fields
  ghlSyncQueued?: boolean;
  ghlSyncJobId?: string;
  ghlSyncSuccess?: boolean;
  ghlSyncSkipped?: boolean;
  ghlSyncReason?: string;
  ghlSyncError?: string;
  ghlSyncHttpStatus?: number;
  ghlSyncUpdatedAt?: string;
  ghlMessageId?: string | null;
  ghl_sync_queued?: boolean;
  ghl_sync_job_id?: string;
  ghl_sync_success?: boolean;
  ghl_sync_skipped?: boolean;
  ghl_sync_reason?: string;
  ghl_sync_error?: string;
  ghl_sync_http_status?: number;
  ghl_sync_updated_at?: string;
  ghl_message_id?: string | null;
  // Extra fields for compatibility
  batch_id?: string;
  conversation_id?: string;
  number?: string;
  recipient_key?: string;
  message?: string;
  date_created?: FirestoreTimestamp;
}
