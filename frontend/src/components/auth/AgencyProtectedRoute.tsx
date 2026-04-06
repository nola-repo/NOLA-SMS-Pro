import React from 'react';
import { Outlet } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

export const AgencyProtectedRoute: React.FC<{ children?: React.ReactNode }> = ({ children }) => {
  const { isAuthenticated, role, isGhlFrame, autoLoginStatus } = useAuth();

  // GHL iframe: auto-login in progress → show spinner
  if (isGhlFrame && autoLoginStatus === 'loading') {
    return (
      <div style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        height: '100vh',
        background: '#0b0c0d',
        color: '#e2e2e6',
        fontFamily: 'Inter, system-ui, sans-serif',
        gap: '16px',
      }}>
        <div style={{
          width: '40px',
          height: '40px',
          border: '3px solid rgba(43,131,250,0.2)',
          borderTopColor: '#2b83fa',
          borderRadius: '50%',
          animation: 'spin 0.8s linear infinite',
        }} />
        <p style={{ fontSize: '14px', fontWeight: 600, opacity: 0.8 }}>Connecting to GHL…</p>
        <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
      </div>
    );
  }

  // GHL iframe: auto-login failed → show error card
  if (isGhlFrame && autoLoginStatus === 'error') {
    return (
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        height: '100vh',
        background: '#0b0c0d',
        fontFamily: 'Inter, system-ui, sans-serif',
      }}>
        <div style={{
          background: '#1c1e21',
          borderRadius: '16px',
          padding: '32px 40px',
          maxWidth: '420px',
          textAlign: 'center',
          border: '1px solid rgba(255,255,255,0.06)',
        }}>
          <div style={{ fontSize: '32px', marginBottom: '12px' }}>⚠️</div>
          <h2 style={{ color: '#e2e2e6', fontSize: '18px', fontWeight: 700, margin: '0 0 8px' }}>
            Connection Failed
          </h2>
          <p style={{ color: '#9aa0a6', fontSize: '14px', lineHeight: 1.5, margin: 0 }}>
            Unable to authenticate with GoHighLevel. Please ensure your agency account is linked to this Company ID, then refresh the page.
          </p>
        </div>
      </div>
    );
  }

  // Normal flow: not authenticated → redirect to login
  if (!isAuthenticated || role !== 'agency') {
    window.location.href = '/login';
    return null;
  }

  return <>{children || <Outlet />}</>;
};
