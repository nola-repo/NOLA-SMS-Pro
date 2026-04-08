import { API_CONFIG } from "../config";
import { getAccountSettings } from "../utils/settingsStorage";

// ─── Types ────────────────────────────────────────────────────────────────────
export interface CreditTransaction {
    transaction_id: string;
    account_id: string;
    type: 'deduction' | 'top_up' | 'refund' | 'manual_adjustment';
    amount: number;         // negative for deductions, positive for credits
    balance_after: number;
    reference_id?: string;
    description: string;
    created_at: string;     // ISO 8601 timestamp
}

export interface CreditPackage {
    credits: number;
    price: number;
    link: string;
}

// ─── API Calls ───────────────────────────────────────────────────────────────

export interface CreditStatus {
    credit_balance: number;
    free_usage_count: number;
    free_credits_total: number;
    currency: string;
}

/**
 * Fetch the current credit and trial status from the internal proxy.
 */
export async function fetchCreditStatus(): Promise<CreditStatus | null> {
    try {
        const accountSettings = getAccountSettings();
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
        };

        if (accountSettings.ghlLocationId) {
            headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
        }

        let url = API_CONFIG.credits;
        if (accountSettings.ghlLocationId) {
            url += `?location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
        }

        const res = await fetch(url, { headers });
        if (!res.ok) return null;
        const data = await res.json();
        
        return {
            credit_balance: data.credit_balance ?? 0,
            free_usage_count: data.free_usage_count ?? 0,
            free_credits_total: data.free_credits_total ?? 0,
            currency: data.currency ?? 'PHP'
        };
    } catch {
        return null;
    }
}

/**
 * Fetch the credit transaction ledger.
 * Returns an empty array on failure.
 */
export async function fetchCreditTransactions(
    accountId = 'default',
    limit = 50,
): Promise<CreditTransaction[]> {
    try {
        const accountSettings = getAccountSettings();
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
        };

        if (accountSettings.ghlLocationId) {
            headers['X-GHL-Location-ID'] = accountSettings.ghlLocationId;
        }

        let url = `${API_CONFIG.base}/api/get_credit_transactions?account_id=${encodeURIComponent(accountId)}&limit=${limit}`;
        if (accountSettings.ghlLocationId) {
            url += `&location_id=${encodeURIComponent(accountSettings.ghlLocationId)}`;
        }

        const res = await fetch(url, { headers });
        if (!res.ok) return [];
        const data = await res.json();

        // Accept { transactions: [...] } or a bare array
        if (Array.isArray(data)) return data as CreditTransaction[];
        if (Array.isArray(data.transactions)) return data.transactions as CreditTransaction[];
        if (Array.isArray(data.data)) return data.data as CreditTransaction[];
        return [];
    } catch {
        return [];
    }
}

/**
 * Fetch available credit packages.
 * Returns a hardcoded list for now.
 */
export async function fetchCreditPackages(): Promise<CreditPackage[]> {
    // These could be fetched from a remote config or database in the future
    return [
        { credits: 10, price: 10, link: "https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465-657955" },
        { credits: 500, price: 500, link: "https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465" },
        { credits: 1100, price: 1000, link: "https://sms.nolawebsolutions.com/nola-sms-pro---1000-credits" },
        { credits: 2750, price: 2500, link: "https://sms.nolawebsolutions.com/nola-sms-pro-2750-credits" },
        { credits: 6000, price: 5000, link: "https://sms.nolawebsolutions.com/nola-sms-pro-6000-credits" },
    ];
}
