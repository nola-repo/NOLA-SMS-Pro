import { safeStorage } from '../../utils/safeStorage';
import React, { useEffect, useState } from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { SESSION_KEYS } from '../../services/agencyAuthHelper.ts';
import { useAgency } from '../../context/AgencyContext.tsx';
import { sessionSafeStorage } from '../../utils/sessionSafeStorage.ts';

/**
 * AgencyProtectedRoute
 * 
 * Simply bypasses login if embedded in a GHL iframe environment.
 * The dashboard will load directly and show an 'Agency ID missing' 
 * state if the companyId couldn't be detected via url/postMessage.
 */
export const AgencyProtectedRoute: React.FC = () => {
  const { isGhlFrame, agencySession, autoLoginLoading, autoLoginError, agencyId } = useAgency();

  // Hard timeout escape: never show spinner for more than 5 seconds.
  // This prevents infinite loading if GHL postMessage never responds.
  const [timedOut, setTimedOut] = useState(false);
  useEffect(() => {
    if (!isGhlFrame) return;
    const t = setTimeout(() => setTimedOut(true), 5000);
    return () => clearTimeout(t);
  }, [isGhlFrame]);

  // If inside ANY iframe context (GHL), wait for autoLogin OR timeout
  if (isGhlFrame) {
    // Still loading AND haven't timed out yet AND don't have a session
    const stillLoading = (autoLoginLoading || (!agencySession && !autoLoginError && !agencyId)) && !timedOut;
    if (stillLoading) {
      return (
        <div className="flex items-center justify-center min-h-screen bg-gray-50 dark:bg-[#0a0a0b] transition-colors duration-300">
           <svg className="animate-spin h-8 w-8 text-[#2b83fa]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
             <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
             <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
           </svg>
        </div>
      );
    }
    
    if (autoLoginError) {
      return (
        <div className="flex flex-col items-center justify-center min-h-screen bg-gray-50 dark:bg-[#0a0a0b] p-6 text-center transition-colors duration-300">
          <div className="w-16 h-16 bg-red-100 dark:bg-red-900/30 text-red-500 rounded-2xl flex items-center justify-center mb-4 shadow-sm">
             <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
               <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
             </svg>
          </div>
          <h2 className="text-xl font-bold text-gray-900 dark:text-white mb-2">Authentication Error</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-md leading-relaxed">
            Failed to connect your GoHighLevel session. Please try refreshing the page or reinstalling the app. 
            <br/><br/>
            <span className="text-xs opacity-70">Error: {autoLoginError}</span>
          </p>
        </div>
      );
    }

    return <Outlet />;
  }

  // Standalone logic
  const token = sessionSafeStorage.getItem(SESSION_KEYS.token) || safeStorage.getItem(SESSION_KEYS.token);
  const role  = safeStorage.getItem(SESSION_KEYS.role);

  if (!token || role !== 'agency') {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
};
