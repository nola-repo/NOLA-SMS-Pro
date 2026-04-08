import { useEffect, useState, useRef } from 'react';
import { safeStorage } from '../utils/safeStorage';

export interface GhlCompanyState {
  companyId: string | null;
  isGhlFrame: boolean;
  status: 'loading' | 'success' | 'error' | 'idle';
}

/**
 * useGhlCompany
 * 1. Checks if inside iframe.
 * 2. If yes, attempts to extract companyId from URLs.
 * 3. Then fires postMessage to grab the GHL encrypted payload.
 * 4. Decrypts via /api/agency/ghl_sso_decrypt to get companyId without requiring a JWT.
 */
export function useGhlCompany(): GhlCompanyState {
  const calledRef = useRef(false);

  const [state, setState] = useState<GhlCompanyState>(() => {
    let isIframe = false;
    try {
      isIframe = window.self !== window.top;
    } catch {
      isIframe = true;
    }
    return {
      companyId: null,
      isGhlFrame: isIframe,
      status: isIframe ? 'loading' : 'idle',
    };
  });

  useEffect(() => {
    if (!state.isGhlFrame || calledRef.current) return;
    calledRef.current = true;

    // Fast-path: Check URL params first
    const companyKeys = ['companyId', 'company_id', 'agency_id', 'agencyId'];
    const searchParams = new URLSearchParams(window.location.search);
    const hashParams   = window.location.hash.includes('?') ? new URLSearchParams(window.location.hash.split('?')[1]) : null;

    let urlCompanyId: string | null = null;
    for (const key of companyKeys) {
      const vals = [...(searchParams.getAll(key) || []), ...(hashParams?.getAll(key) || [])];
      for (const val of vals) {
        if (val && val.trim() !== '' && !val.includes('{{')) {
          urlCompanyId = val;
          break;
        }
      }
      if (urlCompanyId) break;
    }

    const finalize = (cid: string) => {
      console.log(`NOLA SMS: Detected GHL Location: ${cid}`);
      safeStorage.setItem('nola_agency_id', cid);
      setState(s => ({ ...s, companyId: cid, status: 'success' }));
    };

    if (urlCompanyId) {
      finalize(urlCompanyId);
      return;
    }

    // Secondary path: postMessage SSO handshake
    let messageReceived = false;
    const handleGhlMessage = (event: MessageEvent) => {
      if (event.data?.message === 'USER_DATA_RESPONSE' && event.data?.payload) {
        messageReceived = true;
        fetch('/api/agency/ghl_sso_decrypt.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ encryptedPayload: event.data.payload }),
        })
          .then(res => res.json())
          .then(data => {
            if (data.companyId) {
              finalize(data.companyId);
            } else {
              throw new Error("Invalid decrypted payload");
            }
          })
          .catch(err => {
            console.error('SSO Decryption Error:', err);
            // Fallback to safeStorage or error out
            const stored = safeStorage.getItem('nola_agency_id');
            if (stored) finalize(stored);
            else setState(s => ({ ...s, status: 'error' }));
          });
      }
    };

    window.addEventListener('message', handleGhlMessage);
    try {
      window.parent.postMessage({ message: 'REQUEST_USER_DATA' }, '*');
    } catch {
      // not in cross-origin iframe
    }

    // Timeout fallback (after 2 seconds)
    const fallbackTimer = setTimeout(() => {
      if (!messageReceived) {
        const stored = safeStorage.getItem('nola_agency_id');
        if (stored) finalize(stored);
        else setState(s => ({ ...s, status: 'error' }));
      }
    }, 2000);

    return () => {
      window.removeEventListener('message', handleGhlMessage);
      clearTimeout(fallbackTimer);
    };
  }, [state.isGhlFrame]);

  return state;
}
