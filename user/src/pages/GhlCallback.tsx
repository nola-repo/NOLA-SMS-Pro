import React, { useEffect, useState } from 'react';
import { FiLoader, FiCheckCircle, FiXCircle } from 'react-icons/fi';
import { API_CONFIG } from '../config';
import { getAccountSettings, saveAccountSettings } from '../utils/settingsStorage';

// Change this to match the Redirect URI you set in GHL Marketplace
const REDIRECT_URI = window.location.origin + window.location.pathname; // If they go to /?code=...

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
                setStatus('error');
                setMessage(`Authorization failed: ${errorDescription || error}`);
                return;
            }

            if (!code) {
                setStatus('error');
                setMessage('No authorization code found in URL.');
                return;
            }

            try {
                const accountSettings = getAccountSettings();

                // Call our PHP backend to exchange code for token
                const response = await fetch(`${API_CONFIG.base}/api/ghl_oauth`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code, redirectUri: REDIRECT_URI })
                });

                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.error || 'Failed to exchange token');
                }

                const data = await response.json();
                const locationName = data.locationName || data.name;

                // Save success state locally
                saveAccountSettings({
                    ...accountSettings,
                    ghlOAuthConnected: true,
                    // Store the sub-account name if returned by backend
                    displayName: locationName || accountSettings.displayName,
                    ghlLocationId: data.locationId || accountSettings.ghlLocationId
                });

                setStatus('success');
                setMessage(locationName 
                    ? `Successfully connected to ${locationName}!` 
                    : 'Successfully connected to GoHighLevel! You can safely close this page or return to the app.');

                // Optionally redirect back to Settings after a few seconds
                setTimeout(() => {
                    // removing the "?code=" from URL
                    window.location.href = window.location.pathname;
                }, 3000);

            } catch (err: any) {
                console.error('OAuth Error:', err);
                setStatus('error');
                setMessage(`Failed to connect: ${err.message}`);
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
                        onClick={() => window.location.href = window.location.pathname}
                        className="px-6 py-2.5 bg-gray-100 hover:bg-gray-200 dark:bg-[#2a2b32] dark:hover:bg-[#3a3b3f] text-[#37352f] dark:text-[#ececf1] rounded-xl font-semibold text-[14px] transition-colors"
                    >
                        Return to Dashboard
                    </button>
                )}
            </div>
        </div>
    );
};
