import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export const ProtectedRoute: React.FC<{ children?: React.ReactNode }> = ({ children }) => {
  const { isAuthenticated, role } = useAuth();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  // If the user is an agency, hard-redirect to the separate agency app context
  if (role === 'agency') {
    window.location.href = '/agency/';
    return null;
  }

  return <>{children || <Outlet />}</>;
};
