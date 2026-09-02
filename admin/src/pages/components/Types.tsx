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

export type ProviderFetchedVia =
    | 'live_api'
    | 'redis_cache_after_timeout'
    | 'firestore_lkg'
    | 'subaccount_firestore_field'
    | 'redis_cache'
    | 'none'
    | string;

export interface ProviderDataQualityEntry {
    fetched_via: ProviderFetchedVia;
    is_live: boolean;
}

export interface ProviderBalance {
    name: string;
    status: 'active' | 'inactive' | 'error';
    credits: number;
    /** Aggregated total credits across all connected API keys for this provider */
    total_credits?: number;
    /** Number of connected API key accounts */
    connected_accounts?: number;
    /** Total API key accounts discovered (connected + unconfigured) */
    total_accounts?: number;
    configured: boolean;
    is_active: boolean;
    warning: boolean;
    critical: boolean;
    error: string | null;
}

export interface UniSmsBalance extends ProviderBalance {
    name: 'UniSMS';
    email: string | null;
    sid_tokens: number | null;
}

export interface ProviderSummaryEntry {
    name: string;
    status: 'active' | 'inactive' | 'error';
    credits?: number;
    total_credits?: number;
    connected_accounts?: number;
    total_accounts?: number;
    is_active: boolean;
    warning: boolean;
    critical: boolean;
}

export interface ProviderBalancesResponse {
    status: 'success';
    fetched_at: string;
    active_provider: 'semaphore' | 'unisms' | 'auto_failover';
    /** true when any provider balance is from cache/LKG rather than a live API call */
    is_stale?: boolean;
    /** Per-provider data quality metadata */
    data_quality?: {
        semaphore?: ProviderDataQualityEntry;
        unisms?: ProviderDataQualityEntry;
    };
    providers: {
        semaphore: ProviderBalance;
        unisms: UniSmsBalance;
    };
    /** Aggregated summary across all connected API keys per provider */
    summary?: {
        semaphore?: ProviderSummaryEntry;
        unisms?: ProviderSummaryEntry;
    };
}
