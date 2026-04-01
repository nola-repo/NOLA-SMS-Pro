import React from 'react';
import { Outlet } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

export const AgencyProtectedRoute: React.FC<{ children?: React.ReactNode }> = ({ children }) => {
  const { isAuthenticated, role } = useAuth();

  if (!isAuthenticated || role !== 'agency') {
    window.location.href = '/login';
    return null;
  }

  return <>{children || <Outlet />}</>;
};
