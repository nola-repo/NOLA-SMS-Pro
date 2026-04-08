import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { isAuthenticated } from '../services/authService';

/**
 * ProtectedRoute
 * Guards routes with auth verification. If inside a GHL iframe (indicated by 
 * location_id or sessionkey), we bypass the login requirement.
 */
export const ProtectedRoute: React.FC = () => {
  const isAuth = isAuthenticated();

  // Check for GHL iframe parameters to bypass login
  const searchParams = new URLSearchParams(window.location.search);
  const hashString = window.location.hash;
  const isGHL = searchParams.has('location_id') || searchParams.has('locationId') || searchParams.has('location') || searchParams.has('id') ||
                hashString.includes('location_id=') || hashString.includes('locationId=') || hashString.includes('location=') || hashString.includes('id=') ||
                searchParams.has('sessionkey') || hashString.includes('sessionkey=');

  if (!isAuth && !isGHL) {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
};
