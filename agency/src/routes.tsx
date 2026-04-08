import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { Dashboard } from './pages/Dashboard.tsx';
import { Subaccounts } from './pages/Subaccounts.tsx';
import { Settings } from './pages/Settings.tsx';
import { AgencyProtectedRoute } from './components/auth/AgencyProtectedRoute.tsx';
import AgencyLogin from './pages/AgencyLogin.tsx';
import AgencyOAuthCallback from './pages/AgencyOAuthCallback.tsx';

export const AppRoutes = () => (
  <Routes>
    <Route path="/login" element={<AgencyLogin />} />
    <Route path="/oauth/callback" element={<AgencyOAuthCallback />} />

    {/* All agency routes require a valid agency-role token */}
    <Route element={<AgencyProtectedRoute />}>
      <Route path="/"             element={<Dashboard />} />
      <Route path="/subaccounts"  element={<Subaccounts />} />
      <Route path="/settings"     element={<Settings />} />
    </Route>
    {/* Catch-all → dashboard (the guard will redirect to /login if not authed) */}
    <Route path="*" element={<Navigate to="/" replace />} />
  </Routes>
);
