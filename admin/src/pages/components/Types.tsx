export interface SenderRequest {
    id: string;
    location_id: string;
    requested_id: string;
    purpose?: string;
    sample_message?: string;
    status: 'pending' | 'approved' | 'rejected' | 'revoked';
    rejection_note?: string;
    provider?: 'system' | 'semaphore' | 'unisms';
    provider_preference?: 'system' | 'semaphore' | 'semaphore_custom' | 'unisms' | 'unisms_custom';
    unisms_sender_id?: string;
    created_at?: string;
    createdAt?: string;
    updated_at?: string;
    location_name?: string;
    admin_notes?: string;
    agency_name?: string;
    company_id?: string;
    documents?: any[];
}

export interface Account {
    id: string;
    location_id: string;
    location_name?: string;
    approved_sender_id?: string;
    nola_pro_api_key?: string;
    nola_pro_api_key_masked?: string;
    nola_pro_api_key_configured?: boolean;
    api_key?: string;
    semaphore_api_key?: string;
    semaphore_api_key_masked?: string;
    semaphore_api_key_configured?: boolean;
    unisms_api_key_masked?: string;
    unisms_api_key_configured?: boolean;
    unisms_sender_id?: string;
    provider?: 'system' | 'semaphore' | 'unisms';
    approved_provider?: 'system' | 'semaphore' | 'unisms';
    provider_preference?: 'system' | 'semaphore' | 'semaphore_custom' | 'unisms' | 'unisms_custom';
    credits?: number;
    credit_balance?: number;
    free_usage_count?: number;
    free_credits_total?: number;
    /** Display name of the resolved SMS provider e.g. "Semaphore" or "UniSMS" */
    sms_provider?: string;
    /** Live available credit balance from the SMS provider API */
    provider_credit_balance?: number;
    /** Whether the provider balance came from the account's own custom API key or the shared system key */
    provider_balance_source?: 'custom' | 'system';
    active?: boolean;
    monthly_reset_enabled?: boolean;
}

export interface AdminLayoutProps {
    darkMode: boolean;
    toggleDarkMode: () => void;
}
