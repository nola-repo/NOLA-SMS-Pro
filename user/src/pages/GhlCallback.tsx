import { devLog } from '../utils/devLog';
import React, { useEffect, useState } from 'react';
import { FiLoader, FiCheckCircle, FiXCircle } from 'react-icons/fi';
import {
    API_CONFIG,
    GHL_OAUTH_RETURN_VIEW_STORAGE_KEY,
    GHL_RECONNECT_REQUIRED_STORAGE_KEY
} from '../config';
import { getAccountSettings, saveAccountSettings } from '../utils/settingsStorage';
import { safeStorage } from '../utils/safeStorage';
import { apiFetch } from '../utils/apiFetch';

const REDIRECT_URI = window.location.origin + window.location.pathname;

const getPostConnectPath = (): string => {
    const returnView = safeStorage.getItem(GHL_OAUTH_RETURN_VIEW_STORAGE_KEY);
    safeStorage.removeItem(GHL_OAUTH_RETURN_VIEW_STORAGE_KEY);

    if (returnView === 'contacts') return '/contacts';
    if (returnView === 'settings') return '/settings';
    return '/settings';
};

export const GhlCallback: React.FC = () => {
    const [status, setStatus] = useState<'loading' | 'success' | 'error'>('loading');
    const [message, setMessage] = useState('Verifying with GoHighLevel...');

    useEffect(() => {
        const verifyCode = async () => {
            const params = new URLSearchParams(window.location.search);
            const code = params.get('code');
            const error = params.get('error');
            const errorDescription = params.get('error_description');

            if (error) {
                devLog.warn('GHL authorization returned an error', { error, errorDescription });
                setStatus('error');
                setMessage('GoHighLevel authorization was cancelled or could not be completed. Please try reconnecting.');
                return;
            }

            if (!code) {
                setStatus('error');
                setMessage('No authorization code found in URL.');
                return;
            }

            try {
                const accountSettings = getAccountSettings();

                const response = await apiFetch(`${API_CONFIG.base}/api/ghl_oauth`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code, redirectUri: REDIRECT_URI })
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    devLog.warn('GHL token exchange failed', { status: response.status, error: errorData?.error || errorData?.message });
                    throw new Error('token_exchange_failed');
                }

                const data = await response.json();
                const locationName = data.locationName || data.name;

                // Save success state locally
                saveAccountSettings({
                    ...accountSettings,
                    ghlOAuthConnected: true,
                    displayName: locationName || accountSettings.displayName,
                    ghlLocationId: data.locationId || accountSettings.ghlLocationId
                });
                safeStorage.removeItem(GHL_RECONNECT_REQUIRED_STORAGE_KEY);
                safeStorage.setItem('nola_active_tab', safeStorage.getItem(GHL_OAUTH_RETURN_VIEW_STORAGE_KEY) || 'settings');

                setStatus('success');
                setMessage(locationName 
                    ? `Successfully connected to ${locationName}!` 
                    : 'Successfully connected to GoHighLevel! You can safely close this page or return to the app.');

                const postConnectPath = getPostConnectPath();

                // Redirect back into the app instead of leaving the user on the callback route.
                setTimeout(() => {
                    window.location.href = postConnectPath;
                }, 3000);

            } catch (err: unknown) {
                devLog.error('OAuth Error:', err);
                setStatus('error');
                setMessage('Could not connect GoHighLevel right now. Please try reconnecting from Settings.');
            }
        };

        verifyCode();
    }, []);

    return (
        <div className="min-h-screen bg-[#f7f7f7] dark:bg-[#111111] flex flex-col items-center justify-center p-4">
            <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-8 max-w-md w-full text-center shadow-lg">
                <div className="mb-6 flex justify-center">
                    {status === 'loading' && (
                        <div className="w-16 h-16 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center text-[#2b83fa]">
                            <FiLoader className="w-8 h-8 animate-spin" />
                        </div>
                    )}
                    {status === 'success' && (
                        <div className="w-16 h-16 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center text-emerald-500">
                            <FiCheckCircle className="w-8 h-8" />
                        </div>
                    )}
                    {status === 'error' && (
                        <div className="w-16 h-16 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center text-red-500">
                            <FiXCircle className="w-8 h-8" />
                        </div>
                    )}
                </div>

                <h2 className="text-xl font-bold text-[#111111] dark:text-white mb-2">
                    {status === 'loading' ? 'Connecting...' : status === 'success' ? 'Connected!' : 'Connection Failed'}
                </h2>
                <p className="text-[14px] text-[#6e6e73] dark:text-[#9aa0a6] mb-8">
                    {message}
                </p>

                {status !== 'loading' && (
                    <button
                        onClick={() => window.location.href = getPostConnectPath()}
                        className="px-6 py-2.5 bg-gray-100 hover:bg-gray-200 dark:bg-[#2a2b32] dark:hover:bg-[#3a3b3f] text-[#37352f] dark:text-[#ececf1] rounded-xl font-semibold text-[14px] transition-colors"
                    >
                        Return to App
                    </button>
                )}
            </div>
        </div>
    );
};

export default GhlCallback;
