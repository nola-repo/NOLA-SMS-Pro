import { useEffect, useState, useRef } from 'react';
import { authService } from '../services/authService';
import { safeStorage } from '../utils/safeStorage';

export type AutoLoginStatus = 'idle' | 'loading' | 'success' | 'error';

const AGENCY_ID_KEY = 'nola_agency_id';

export function useGhlCompany(onLoginSuccess?: (data: any) => void) {
    const [companyId, setCompanyId] = useState<string | null>(
        safeStorage.getItem(AGENCY_ID_KEY) || null
    );
    const [autoLoginStatus, setAutoLoginStatus] = useState<AutoLoginStatus>('idle');
    const [error, setError] = useState<string | null>(null);
    const attempted = useRef(false);

    // Detect companyId from URL
    useEffect(() => {
        let urlCompanyId: string | null = null;
        const possibleKeys = ['companyId', 'company_id', 'agency_id', 'locationId'];

        // 1. Parse standard URL search parameters
        const searchParams = new URLSearchParams(window.location.search);
        for (const key of possibleKeys) {
            const val = searchParams.get(key);
            if (val) {
                urlCompanyId = val;
                break;
            }
        }

        // 2. Parse hash params (GHL can use either format)
        if (!urlCompanyId && window.location.hash.includes('?')) {
            const hashString = window.location.hash.split('?')[1];
            if (hashString) {
                const hashParams = new URLSearchParams(hashString);
                for (const key of possibleKeys) {
                    const val = hashParams.get(key);
                    if (val) {
                        urlCompanyId = val;
                        break;
                    }
                }
            }
        }

        // 3. Fallback: full URL parse
        if (!urlCompanyId) {
            try {
                const url = new URL(window.location.href);
                for (const key of possibleKeys) {
                    const val = url.searchParams.get(key);
                    if (val) {
                        urlCompanyId = val;
                        break;
                    }
                }
            } catch {
                // ignore
            }
        }

        console.log('NOLA SMS: Detected GHL Company URL Param:', urlCompanyId, 'Full URL:', window.location.href);

        if (urlCompanyId) {
            setCompanyId(urlCompanyId);
            safeStorage.setItem(AGENCY_ID_KEY, urlCompanyId);
        }
    }, []);

    // Auto-login when companyId is detected and no valid session exists
    useEffect(() => {
        if (!companyId || attempted.current) return;

        const existingSession = authService.getSession();
        if (existingSession && existingSession.token) {
            // Already authenticated — skip auto-login
            setAutoLoginStatus('success');
            return;
        }

        attempted.current = true;
        setAutoLoginStatus('loading');
        setError(null);

        authService.ghlAutoLogin(companyId)
            .then((data) => {
                setAutoLoginStatus('success');
                onLoginSuccess?.(data);
            })
            .catch((err) => {
                console.error('GHL auto-login failed:', err);
                setAutoLoginStatus('error');
                setError(err instanceof Error ? err.message : 'Auto-login failed');
            });
    }, [companyId, onLoginSuccess]);

    const isGhlFrame = !!companyId;

    return { companyId, isGhlFrame, autoLoginStatus, error };
}
