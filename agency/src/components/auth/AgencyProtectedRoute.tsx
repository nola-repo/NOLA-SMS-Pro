import { safeStorage } from '../../utils/safeStorage';
import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { SESSION_KEYS } from '../../services/agencyAuthHelper.ts';
import { useAgency } from '../../context/AgencyContext.tsx';

/**
 * AgencyProtectedRoute
 * 
 * Simply bypasses login if embedded in a GHL iframe environment.
 * The dashboard will load directly and show an 'Agency ID missing' 
 * state if the companyId couldn't be detected via url/postMessage.
 */
export const AgencyProtectedRoute: React.FC = () => {
  const { isGhlFrame } = useAgency();

  // If inside ANY iframe context (GHL), directly show the dashboard block without a login flash.
  if (isGhlFrame) {
    return <Outlet />;
  }

  // Standalone logic
  const token = safeStorage.getItem(SESSION_KEYS.token);
  const role  = safeStorage.getItem(SESSION_KEYS.role);

  if (!token || role !== 'agency') {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
};
