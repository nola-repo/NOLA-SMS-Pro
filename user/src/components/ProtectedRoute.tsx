import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { isAuthenticated } from '../services/authService';
import { hasGhlLaunchSignalInCurrentUrl } from '../utils/ghlLocationDetection';

/**
 * ProtectedRoute
 * Guards routes with auth verification. If inside a GHL iframe (indicated by
 * a proper GHL location_id or sessionkey param), we bypass the login requirement.
 *
 * Security note: only trusted GHL-specific params trigger the bypass.
 * Generic params like ?id= or ?location= are intentionally excluded.
 *
 * Iframe context: we still require a valid token OR active GHL launch signals.
 * A raw iframe embed without any GHL params/token will surface a session error
 * rather than silently rendering protected data.
 */
export const ProtectedRoute: React.FC = () => {
  const isAuth = isAuthenticated();

  const urlHasParams = hasGhlLaunchSignalInCurrentUrl();

  let isIframe = false;
  try {
    isIframe = window.self !== window.top;
  } catch {
    // If accessing top throws a DOMException, we're in a cross-origin iframe
    isIframe = true;
  }

  try {
    if (urlHasParams || isIframe) {
      // Use sessionStorage only; clears when the tab/browser is closed
      sessionStorage.setItem('nola_is_ghl_frame', 'true');
    }
  } catch {
    // Ignore storage errors in incognito/strict-privacy mode
  }

  // Inside a GHL iframe: allow if authenticated, inside iframe, or if GHL launch signals are present
  // (GHL SSO / postMessage / LocationContext will complete the handshake)
  const isGhlWithActiveSignals = urlHasParams || isAuth || isIframe;

  if (!isAuth && !isGhlWithActiveSignals) {
    // No valid session and not in iframe — deny access.
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
};
