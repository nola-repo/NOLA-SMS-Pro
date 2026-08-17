export type FirestoreTimestamp =
  | string
  | { seconds: number; nanoseconds: number }
  | { _seconds: number; _nanoseconds: number }
  | { toDate: () => Date }
  | Date;

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
}
