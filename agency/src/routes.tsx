import React, { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { AgencyProtectedRoute } from './components/auth/AgencyProtectedRoute.tsx';
import { ErrorBoundary } from './components/ui/ErrorBoundary.tsx';

// Static imports (small, auth-critical — no lazy needed)
import AgencyLogin from './pages/AgencyLogin.tsx';
import AgencyForgotPassword from './pages/AgencyForgotPassword.tsx';
import AgencyOAuthCallback from './pages/AgencyOAuthCallback.tsx';
import AgencyRegisterFromInstall from './pages/AgencyRegisterFromInstall.tsx';

// Route-level lazy chunks — each becomes its own JS chunk
const Dashboard    = lazy(() => import('./pages/Dashboard.tsx'));
const Subaccounts  = lazy(() => import('./pages/Subaccounts.tsx'));
const Billing      = lazy(() => import('./pages/Billing.tsx'));
const Subscription = lazy(() => import('./pages/Subscription.tsx'));
const Settings     = lazy(() => import('./pages/Settings.tsx'));

const RouteSpinner = () => (
  <div className="min-h-screen flex items-center justify-center bg-[#f7f8fc] dark:bg-[#0a0a0b]">
    <div className="w-10 h-10 rounded-full border-4 border-[#2b83fa]/20 border-t-[#2b83fa] animate-spin" />
  </div>
);

export const AppRoutes = () => (
  <ErrorBoundary appName="NOLA SMS Pro Agency">
    <Suspense fallback={<RouteSpinner />}>
      <Routes>
        <Route path="/login"                 element={<AgencyLogin />} />
        <Route path="/forgot-password"       element={<AgencyForgotPassword />} />
        <Route path="/oauth/callback"        element={<AgencyOAuthCallback />} />
        <Route path="/register-from-install" element={<AgencyRegisterFromInstall />} />

        {/* All agency routes require a valid agency-role token */}
        <Route element={<AgencyProtectedRoute />}>
          <Route path="/"             element={<Dashboard />} />
          <Route path="/subaccounts"  element={<Subaccounts />} />
          <Route path="/billing"      element={<Billing />} />
          <Route path="/subscription" element={<Subscription />} />
          <Route path="/settings"     element={<Settings />} />
        </Route>
        {/* Catch-all → dashboard (the guard will redirect to /login if not authed) */}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  </ErrorBoundary>
);

