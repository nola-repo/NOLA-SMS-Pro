import React, { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { exchangeOAuthCode } from '../services/agencyAuthHelper';
import { getAgencySession } from '../services/agencyAuthHelper';
import defaultLogo from '../assets/NOLA SMS PRO Logo.png';

const AgencyOAuthCallback: React.FC = () => {
  const navigate = useNavigate();
  const [status, setStatus] = useState<'loading' | 'success' | 'error'>('loading');
  const [errorMsg, setErrorMsg] = useState('');
  const [companyName, setCompanyName] = useState('');
  const ranRef = useRef(false);

  useEffect(() => {
    if (ranRef.current) return;
    ranRef.current = true;

    const params = new URLSearchParams(window.location.search);

    // ── NEW: install_token = PHP redirected here after first marketplace install ──
    const installToken = params.get('install_token');
    if (installToken) {
      navigate(`/register-from-install?install_token=${encodeURIComponent(installToken)}`, { replace: true });
      return;
    }

    const code = params.get('code');

    if (!code) {
      setErrorMsg('No authorization code received from GoHighLevel. Please try again.');
      setStatus('error');
      return;
    }

    // Must be authenticated already (JWT exists) before we can link
    const session = getAgencySession();
    if (!session) {
      // Not logged in — save code param and send to login first
      sessionStorage.setItem('ghl_pending_code', code);
      navigate('/login', { replace: true });
      return;
    }

    exchangeOAuthCode(code)
      .then((companyId) => {
        setCompanyName(companyId);
        setStatus('success');
        // Redirect back into GHL so the agency panel opens inside the iframe.
        // navigate('/') would land outside GHL on the standalone agency URL.
        setTimeout(() => {
          window.location.href = 'https://app.leadconnectorhq.com/custom-page-link/69d3212eb3071ba8a0cd0b51';
        }, 1800);
      })
      .catch((err: Error) => {
        setErrorMsg(err.message || 'Something went wrong. Please try again.');
        setStatus('error');
      });
  }, [navigate]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-[#0a0a0b] transition-colors">
      <div className="w-full max-w-md p-8 rounded-3xl bg-white/70 dark:bg-[#1a1b1e]/70 backdrop-blur-2xl border border-white/20 dark:border-white/10 shadow-2xl text-center">
        <img src={defaultLogo} alt="NOLA SMS Pro" className="h-14 object-contain mx-auto mb-6" />

        {status === 'loading' && (
          <>
            <div className="w-12 h-12 rounded-full border-4 border-[#2b83fa]/20 border-t-[#2b83fa] animate-spin mx-auto mb-4" />
            <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-1">Connecting to GoHighLevel…</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">Exchanging your authorization code</p>
          </>
        )}

        {status === 'success' && (
          <>
            <div className="w-12 h-12 rounded-full bg-emerald-100 dark:bg-emerald-900/20 flex items-center justify-center mx-auto mb-4">
              <svg className="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-1">Connected!</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Your GHL agency has been linked. Returning you to GoHighLevel…
            </p>
          </>
        )}

        {status === 'error' && (
          <>
            <div className="w-12 h-12 rounded-full bg-red-100 dark:bg-red-900/20 flex items-center justify-center mx-auto mb-4">
              <svg className="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </div>
            <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Connection Failed</h2>
            <p className="text-sm text-red-500 dark:text-red-400 mb-6">{errorMsg}</p>
            <button
              onClick={() => navigate('/login', { replace: true })}
              className="w-full py-3 rounded-xl bg-[#2b83fa] hover:bg-[#1d6bd4] text-white text-sm font-bold transition-colors"
            >
              Back to Login
            </button>
          </>
        )}
      </div>
    </div>
  );
};

export default AgencyOAuthCallback;
