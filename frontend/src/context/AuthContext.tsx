import React, { createContext, useContext, useState, useEffect, useCallback, ReactNode } from 'react';
import { authService, KEYS } from '../services/authService';
import { safeStorage } from '../utils/safeStorage';
import { useGhlCompany, type AutoLoginStatus } from '../hooks/useGhlCompany';

interface Session {
  token: string;
  user: any;
  role: string | null;
  companyId: string | null;
  locationId: string | null;
}

interface AuthContextType extends Session {
  login: (data: any) => void;
  logout: () => void;
  isAuthenticated: boolean;
  isGhlFrame: boolean;
  autoLoginStatus: AutoLoginStatus;
}

const AuthContext = createContext<AuthContextType | null>(null);

export const AuthProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [session, setSession] = useState<Session | null>(null);
  const [loading, setLoading] = useState(true);

  const refreshSession = useCallback(() => {
    setSession(authService.getSession() || null);
  }, []);

  useEffect(() => {
    refreshSession();
    setLoading(false);
  }, [refreshSession]);

  // GHL auto-login hook — re-reads session on success
  const { isGhlFrame, autoLoginStatus } = useGhlCompany(
    useCallback((_data: any) => {
      // Session was saved to localStorage by authService.ghlAutoLogin — just refresh state
      refreshSession();
    }, [refreshSession])
  );

  const login = (data: any) => {
    // Save to local storage
    safeStorage.setItem(KEYS.token, data.token);
    safeStorage.setItem(KEYS.user, JSON.stringify(data.user));
    safeStorage.setItem(KEYS.role, data.role);
    if (data.company_id) safeStorage.setItem(KEYS.companyId, data.company_id);
    if (data.location_id) safeStorage.setItem(KEYS.locationId, data.location_id);

    // Update state
    setSession(authService.getSession());
  };

  const logout = () => {
    authService.logout();
    setSession(null);
    window.location.href = '/login';
  };

  // We could display a full screen loader but returning null or a generic loader is fine for now
  if (loading) {
    return null;
  }

  return (
    <AuthContext.Provider
      value={{
        token: session?.token || '',
        user: session?.user || null,
        role: session?.role || null,
        companyId: session?.companyId || null,
        locationId: session?.locationId || null,
        login,
        logout,
        isAuthenticated: !!session,
        isGhlFrame,
        autoLoginStatus,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};
