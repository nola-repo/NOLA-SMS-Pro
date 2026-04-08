import { safeStorage } from '../utils/safeStorage';
import React, { createContext, useContext, useState, useEffect } from 'react';
import { getAgencySession, clearAgencySession, type AgencySession } from '../services/agencyAuthHelper.ts';
import { useGhlCompany } from '../hooks/useGhlCompany.ts';

interface AgencyContextValue {
  agencySession: AgencySession | null;
  agencyId:      string | null;
  logout:        () => void;
  disconnectGhl: () => void;
  isGhlFrame:    boolean;
  darkMode:      boolean;
  toggleDarkMode: () => void;
}

const AgencyContext = createContext<AgencyContextValue | null>(null);

export const AgencyProvider = ({ children }) => {
  const { companyId: ghlCompanyId, isGhlFrame } = useGhlCompany();

  const [agencySession] = useState<AgencySession | null>(() => getAgencySession());

  // Priority: 1. GHL URL param  2. JWT  3. env  4. localStorage
  const [agencyId, setAgencyId] = useState<string | null>(() => {
    if (ghlCompanyId) return ghlCompanyId;
    const session = getAgencySession();
    if (session?.companyId) return session.companyId;
    const envId = import.meta.env.VITE_AGENCY_ID;
    if (envId && envId !== 'your_agency_id_here') return envId;
    const params = new URLSearchParams(window.location.search);
    const qpId = params.get('agency_id') || params.get('locationId');
    if (qpId) return qpId;
    return safeStorage.getItem('nola_agency_id') || null;
  });

  useEffect(() => {
    if (ghlCompanyId && ghlCompanyId !== agencyId) {
      setAgencyId(ghlCompanyId);
      safeStorage.setItem('nola_agency_id', ghlCompanyId);
    }
  }, [ghlCompanyId]);

  const logout = () => {
    clearAgencySession();
    safeStorage.removeItem('nola_agency_id');
    window.location.href = '/login';
  };

  const disconnectGhl = () => {
    setAgencyId(null);
    safeStorage.removeItem('nola_agency_id');
  };

  const [darkMode, setDarkMode] = useState(() => {
    const saved = safeStorage.getItem('agency_darkMode');
    return saved !== null ? JSON.parse(saved) : false;
  });

  useEffect(() => {
    const root = document.documentElement;
    if (darkMode) root.classList.add('dark');
    else root.classList.remove('dark');
    safeStorage.setItem('agency_darkMode', JSON.stringify(darkMode));
  }, [darkMode]);

  const toggleDarkMode = () => setDarkMode((prev: boolean) => !prev);

  return (
    <AgencyContext.Provider value={{
      agencySession,
      agencyId,
      logout,
      disconnectGhl,
      isGhlFrame,
      darkMode,
      toggleDarkMode,
    }}>
      {children}
    </AgencyContext.Provider>
  );
};

export const useAgency = () => {
  const ctx = useContext(AgencyContext);
  if (!ctx) throw new Error('useAgency must be used within <AgencyProvider>');
  return ctx;
};
