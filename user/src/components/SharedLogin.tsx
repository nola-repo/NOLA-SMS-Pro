import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { AppLoadingScreen } from './ui/AppLoadingScreen';

export const SharedLogin: React.FC = () => {
  const { isAuthenticated } = useAuth();
  const navigate = useNavigate();
  let isGhlFrame = false;
  try {
    isGhlFrame = window.self !== window.top || sessionStorage.getItem('nola_is_ghl_frame') === 'true';
  } catch {
    isGhlFrame = true;
  }
  useEffect(() => {
    // 1. If already logged in, go to main dashboard
    if (isAuthenticated) {
      navigate('/', { replace: true });
      return;
    }
    // 2. If inside GHL iframe, let autologin/bootstrap handle session on /
    if (isGhlFrame) {
      navigate('/', { replace: true });
      return;
    }
    // 3. Standalone browser tab -> Redirect to backend PHP login page
    const baseUrl = import.meta.env.VITE_API_BASE || 'https://smspro-api.nolacrm.io';
    const targetUrl = new URL(`${baseUrl}/login`);
    const params = new URLSearchParams(window.location.search);
    params.forEach((value, key) => targetUrl.searchParams.set(key, value));
    window.location.replace(targetUrl.toString());
  }, [isAuthenticated, isGhlFrame, navigate]);
  return <AppLoadingScreen message="Authenticating..." subtext="Connecting to your session..." />;
};
export default SharedLogin;
