import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { authService, KEYS } from '../services/authService';

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
}

const AuthContext = createContext<AuthContextType | null>(null);

export const AuthProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [session, setSession] = useState<Session | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const s = authService.getSession();
    setSession(s || null);
    setLoading(false);
  }, []);

  const login = (data: any) => {
    // Save to local storage
    localStorage.setItem(KEYS.token, data.token);
    localStorage.setItem(KEYS.user, JSON.stringify(data.user));
    localStorage.setItem(KEYS.role, data.role);
    if (data.company_id) localStorage.setItem(KEYS.companyId, data.company_id);
    if (data.location_id) localStorage.setItem(KEYS.locationId, data.location_id);

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
