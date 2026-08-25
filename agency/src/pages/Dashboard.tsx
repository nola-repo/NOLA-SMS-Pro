import { devLog } from '../utils/devLog';
import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FiUsers, FiCheckCircle, FiAlertTriangle, FiSend, FiChevronLeft, FiChevronRight, FiSearch, FiPlus } from 'react-icons/fi';
import { AgencyLayout } from '../components/layout/AgencyLayout.tsx';
import { useAgency } from '../context/AgencyContext.tsx';
import { getSubaccounts } from '../services/api.ts';
import AnimatedContent from '../components/AnimatedContent.tsx';
import { collection, query, where, onSnapshot } from 'firebase/firestore';
import { ensureFirestoreAuth } from '../services/firestoreAuth.ts';
import { db } from '../services/firebaseConfig.ts';

type DashboardMetricCardProps = {
  label: string;
  value: number | string;
  note: string;
  icon: React.ReactNode;
  gradient: string;
  iconClass: string;
  labelClass: string;
  valueClass: string;
  buttonClass: string;
  actionLabel: string;
  loading: boolean;
  index?: number;
  onClick: () => void;
};

const DashboardMetricCard = ({
  label,
  value,
  note,
  icon,
  gradient,
  iconClass,
  labelClass,
  valueClass,
  buttonClass,
  actionLabel,
  loading,
  index = 0,
  onClick,
}: DashboardMetricCardProps) => (
  <AnimatedContent delay={0.1 + index * 0.1} distance={50} direction="vertical" className="h-full">
    <div className={`p-6 rounded-[24px] ${gradient} shadow-xl transition-all group overflow-hidden relative h-full min-h-[184px] border border-white/70 dark:border-white/15 hover:-translate-y-0.5`}>
      <div className="absolute inset-0 bg-white/10 dark:bg-white/[0.05] pointer-events-none" />
      <div className="absolute bottom-0 right-0 p-4 opacity-[0.13] dark:opacity-[0.16] group-hover:scale-110 transition-transform duration-500">
        <div className="w-24 h-24">{icon}</div>
      </div>
      <button
        type="button"
        onClick={onClick}
        className={`group/action absolute right-4 top-4 z-20 flex h-10 w-10 items-center justify-center rounded-full text-white shadow-[0_10px_24px_rgba(15,23,42,0.22)] ring-1 ring-white/45 hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white transition-all ${buttonClass}`}
        aria-label={actionLabel}
        title={actionLabel}
      >
        <FiPlus className="h-[18px] w-[18px] stroke-[2.4] transition-transform duration-200 group-hover/action:rotate-90" />
      </button>
      <div className="relative z-10 flex h-full flex-col justify-between">
        <div>
          <div className={`w-10 h-10 rounded-xl bg-white/70 dark:bg-white/[0.14] flex items-center justify-center mb-4 shadow-sm ring-1 ring-white/40 dark:ring-white/10 ${iconClass}`}>
            <div className="h-5 w-5">{icon}</div>
          </div>
          <p className={`text-[12px] font-bold uppercase tracking-widest mb-1 ${labelClass}`}>
            {label}
          </p>
        </div>
        <div className="mt-4">
          <h2 className={`text-3xl sm:text-4xl font-black leading-none ${valueClass}`}>
            {loading ? <span className="inline-block h-10 w-20 rounded-lg bg-white/40 dark:bg-white/15 animate-pulse" /> : value}
          </h2>
          <p className={`mt-2 text-[12px] font-bold ${labelClass}`}>{note}</p>
        </div>
      </div>
    </div>
  </AnimatedContent>
);

export const Dashboard = () => {
  const { agencyId, agencySession } = useAgency();
  const navigate = useNavigate();

  const [subaccounts, setSubaccounts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]   = useState(null);
  const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());
  const [currentPage, setCurrentPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState('');
  const ITEMS_PER_PAGE = 10;

  const fetchData = async () => {
    if (!agencyId) return;
    try {
      const data = await getSubaccounts(agencyId);
      setSubaccounts(data.subaccounts || []);
      setLastRefreshed(new Date());
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, [agencyId]);

  useEffect(() => {
    if (!agencyId) return;

    let unsubscribe: (() => void) | undefined;

    const setup = async () => {
      try {
        await ensureFirestoreAuth();

        const q = query(
          collection(db, 'ghl_tokens'),
          where('companyId', '==', agencyId)
        );

        unsubscribe = onSnapshot(
          q,
          (snapshot) => {
            const liveStates = new Map<string, { toggle_enabled: boolean; attempt_count: number; rate_limit: number; toggle_activation_count: number }>();
            snapshot.docs.forEach(doc => {
              const d = doc.data();
              const id = d.location_id ?? doc.id;
              liveStates.set(id, {
                toggle_enabled: typeof d.toggle_enabled === 'boolean' ? d.toggle_enabled : true,
                attempt_count:  Number(d.attempt_count ?? 0),
                rate_limit: Number(d.rate_limit ?? 5),
                toggle_activation_count: Number(d.toggle_activation_count ?? 0),
              });
            });

            setSubaccounts(prev =>
              prev.map(s => {
                const live = liveStates.get(s.location_id);
                return live ? { ...s, ...live } : s;
              })
            );
            setLastRefreshed(new Date());
          },
          (err) => {
            devLog.error('[Dashboard] onSnapshot error:', err);
          }
        );
      } catch (err: any) {
        devLog.error('[Dashboard] Firebase setup error:', err);
      }
    };

    setup();

    return () => {
      if (unsubscribe) unsubscribe();
    };
  }, [agencyId]);

  const total   = subaccounts.length;
  const active  = subaccounts.filter(s => s.toggle_enabled).length;
  const totalSends = subaccounts.reduce((sum, s) => sum + (s.attempt_count || 0), 0);

  const totalPages = Math.ceil(total / ITEMS_PER_PAGE);
  const paginatedSubaccounts = subaccounts.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

  useEffect(() => {
    if (currentPage > totalPages && totalPages > 0) setCurrentPage(totalPages);
  }, [total, currentPage, totalPages]);

  const getGreeting = () => {
    const hour = new Date().getHours();
    if (hour < 12) return "Good morning";
    if (hour < 18) return "Good afternoon";
    return "Good evening";
  };

  const sessionAgencyName = (() => {
    const user = agencySession?.user;
    if (!user) return '';
    return (
      user.company_name ||
      user.agency_name ||
      user.name ||
      [user.firstName, user.lastName].filter(Boolean).join(' ').trim()
    );
  })();

  const agencyName = sessionAgencyName ||
    ((subaccounts.length > 0 && (subaccounts[0].agency_name || subaccounts[0].company_name))
      ? (subaccounts[0].agency_name || subaccounts[0].company_name)
      : 'Agency');

  const accountSearchResults = searchQuery.trim()
    ? subaccounts
        .filter((s: any) =>
          [
            s.location_name,
            s.company_name,
            s.agency_name,
            s.location_id,
          ].some(value => String(value || '').toLowerCase().includes(searchQuery.trim().toLowerCase()))
        )
        .slice(0, 5)
    : [];

  const dashboardTopActions = (
    <div
      className="relative hidden sm:block w-72"
      onClick={(event) => event.stopPropagation()}
      title={!loading ? `Last checked: ${lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}` : undefined}
    >
      <FiSearch className="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-white/70" />
      <input
        type="text"
        placeholder="Search accounts..."
        value={searchQuery}
        onChange={(event) => setSearchQuery(event.target.value)}
        className="w-full pl-11 pr-4 py-2.5 bg-white/10 border border-white/20 rounded-xl text-[14px] font-medium text-white placeholder-white/70 focus:outline-none focus:ring-2 focus:ring-white/30 focus:bg-white/20 transition-all"
      />
      {searchQuery.trim() !== '' && (
        <div className="absolute top-full left-0 right-0 mt-2 bg-white/95 dark:bg-[#1a1b1e]/95 backdrop-blur-md border border-gray-200 dark:border-white/10 rounded-2xl shadow-xl z-50 overflow-hidden py-1.5 animate-in fade-in slide-in-from-top-2 duration-200 max-h-60 overflow-y-auto custom-scrollbar">
          {accountSearchResults.length > 0 ? (
            accountSearchResults.map((subaccount: any) => (
              <button
                key={subaccount.location_id || subaccount.id}
                type="button"
                onClick={() => {
                  setSearchQuery('');
                  navigate('/subaccounts');
                }}
                className="w-full px-4 py-2.5 hover:bg-gray-100 dark:hover:bg-white/[0.05] transition-colors text-left flex items-center gap-3"
              >
                <div className="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-[12px] flex-shrink-0 bg-gradient-to-br from-[#2b83fa] to-[#60a5fa]">
                  {(subaccount.location_name || subaccount.company_name || '?').charAt(0).toUpperCase()}
                </div>
                <div className="min-w-0">
                  <p className="text-[13.5px] font-bold text-[#111111] dark:text-white leading-tight truncate">
                    {subaccount.location_name || subaccount.company_name || 'Unnamed account'}
                  </p>
                  <p className="text-[11.5px] text-gray-500 dark:text-gray-400 font-medium truncate">
                    {subaccount.location_id || 'No location id'}
                  </p>
                </div>
              </button>
            ))
          ) : (
            <div className="px-4 py-3 text-center text-gray-400 dark:text-gray-500 text-[12.5px] font-medium italic">
              No accounts found
            </div>
          )}
        </div>
      )}
    </div>
  );

  return (
    <AgencyLayout
      title={`${getGreeting()}, ${agencyName}`}
      subtitle="NOLA SMS PRO is ready for agency operations."
      topActions={dashboardTopActions}
      variant="dashboard"
    >
      {!agencyId && (
        <div className="bg-[#f59e0b]/[0.05] border border-[#f59e0b]/30 rounded-xl p-4 mb-6 shadow-sm flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div className="flex items-center gap-2 text-[#f59e0b]">
              <FiAlertTriangle className="w-[18px] h-[18px]" />
              <strong className="text-[13px]">No GHL Company ID linked.</strong>
            </div>
            <p className="text-[12.5px] mt-1.5 text-[#6e6e73] dark:text-[#94959b]">
              Your account is not connected to a GoHighLevel agency yet. Log in again to link your GHL Company ID.
            </p>
          </div>
          <button
            onClick={() => navigate('/login')}
            className="shrink-0 px-4 py-2 bg-[#f59e0b] text-white text-[12.5px] font-bold rounded-lg hover:bg-[#d97706] transition-colors"
          >
            Connect Now →
          </button>
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6 mb-14">
        <DashboardMetricCard
          index={0}
          label="Total Subaccounts"
          value={total.toLocaleString()}
          note="Registered under your agency"
          icon={<FiUsers className="w-full h-full" />}
          gradient="bg-gradient-to-br from-[#e0f2fe] via-[#60a5fa] to-[#06b6d4] dark:from-[#3b82f6] dark:via-[#2584d5] dark:to-[#14a3a1] shadow-blue-500/20 hover:shadow-blue-500/30"
          iconClass="text-blue-700 dark:text-blue-50"
          labelClass="text-blue-950/70 dark:text-blue-50/80"
          valueClass="text-[#082f49] dark:text-white"
          buttonClass="bg-blue-700 hover:bg-blue-800 dark:bg-blue-500 dark:hover:bg-blue-400"
          actionLabel="Open subaccounts"
          loading={loading}
          onClick={() => navigate('/subaccounts')}
        />
        <DashboardMetricCard
          index={1}
          label="Total Sends"
          value={totalSends.toLocaleString()}
          note="Across all subaccounts"
          icon={<FiSend className="w-full h-full" />}
          gradient="bg-gradient-to-br from-[#fae8ff] via-[#c084fc] to-[#7c3aed] dark:from-[#8b5cf6] dark:via-[#7c3aed] dark:to-[#5b5ce2] shadow-purple-500/20 hover:shadow-purple-500/30"
          iconClass="text-purple-700 dark:text-purple-50"
          labelClass="text-purple-950/70 dark:text-purple-50/80"
          valueClass="text-[#3b0764] dark:text-white"
          buttonClass="bg-purple-700 hover:bg-purple-800 dark:bg-purple-500 dark:hover:bg-purple-400"
          actionLabel="Open subaccounts"
          loading={loading}
          onClick={() => navigate('/subaccounts')}
        />
        <DashboardMetricCard
          index={2}
          label="Active (SMS ON)"
          value={active.toLocaleString()}
          note="Webhook enabled"
          icon={<FiCheckCircle className="w-full h-full" />}
          gradient="bg-gradient-to-br from-[#dcfce7] via-[#86efac] to-[#2dd4bf] dark:from-[#10b981] dark:via-[#0ea56f] dark:to-[#0d9488] shadow-emerald-500/20 hover:shadow-emerald-500/30"
          iconClass="text-emerald-700 dark:text-emerald-50"
          labelClass="text-emerald-950/70 dark:text-emerald-50/80"
          valueClass="text-[#022c22] dark:text-white"
          buttonClass="bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-500 dark:hover:bg-emerald-400"
          actionLabel="Open active subaccounts"
          loading={loading}
          onClick={() => navigate('/subaccounts')}
        />
      </div>

      {/* All subaccounts summary */}
      {!loading && total > 0 && (
        <div>
          <AnimatedContent delay={0.4} distance={50} direction="vertical">
            <div className="flex items-center justify-between mb-5">
                <h3 className="text-[15px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                    All Subaccounts
                </h3>
                {subaccounts.length > ITEMS_PER_PAGE && (
                    <button
                        onClick={() => navigate('/subaccounts')}
                        className="text-[12px] font-bold text-[#2b83fa] hover:text-[#1a65d1] py-1 px-3 rounded-full hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                    >
                        See All
                    </button>
                )}
            </div>
            
            <div className="bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] rounded-3xl shadow-sm overflow-hidden mb-6">
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse">
                  <thead>
                    <tr className="border-b border-[#00000005] dark:border-[#ffffff05] bg-gray-50/50 dark:bg-gray-800/20">
                      <th className="px-6 py-4 text-[11px] font-bold uppercase tracking-widest text-gray-500 dark:text-gray-400">Subaccount</th>
                      <th className="px-6 py-4 text-[11px] font-bold uppercase tracking-widest text-gray-500 dark:text-gray-400">Status</th>
                      <th className="px-6 py-4 text-[11px] font-bold uppercase tracking-widest text-gray-500 dark:text-gray-400">Sends Used</th>
                      <th className="px-6 py-4 text-[11px] font-bold uppercase tracking-widest text-gray-500 dark:text-gray-400">Limit</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#00000005] dark:divide-[#ffffff05]">
                    {paginatedSubaccounts.map((s, i) => {
                      const isAtLimit = s.attempt_count >= s.rate_limit;
                      const isNearLimit = s.attempt_count >= s.rate_limit * 0.8;
                      
                      return (
                        <tr key={s.location_id || i} className="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors group">
                          <td className="px-6 py-4 align-middle">
                            <div className="flex flex-col">
                              <span className="text-[13.5px] font-bold text-[#111111] dark:text-white group-hover:text-[#2b83fa] transition-colors">{s.location_name || s.company_name || 'Unnamed'}</span>
                              <span className="text-[11px] font-medium text-gray-500 dark:text-gray-400 mt-0.5">{s.location_id}</span>
                            </div>
                          </td>
                          <td className="px-6 py-4 align-middle">
                            <div className="flex items-center gap-2">
                              <div className={`w-2 h-2 rounded-full ${s.toggle_enabled ? 'bg-[#22c55e] shadow-[0_0_0_3px_rgba(34,197,94,0.1)]' : 'bg-gray-400'}`} />
                              <span className={`text-[12px] font-bold ${s.toggle_enabled ? 'text-[#22c55e]' : 'text-gray-500 dark:text-gray-400'}`}>
                                {s.toggle_enabled ? 'ACTIVE' : 'INACTIVE'}
                              </span>
                            </div>
                          </td>
                          <td className="px-6 py-4 align-middle">
                            <span className="text-[13.5px] font-bold text-[#111111] dark:text-white">{s.attempt_count.toLocaleString()}</span>
                          </td>
                          <td className="px-6 py-4 align-middle">
                            <div className="flex items-center gap-3">
                              <span className="text-[13.5px] font-bold text-gray-500 dark:text-gray-400">{s.rate_limit.toLocaleString()}</span>
                              {isAtLimit ? (
                                <span className="bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full">Blocked</span>
                              ) : isNearLimit ? (
                                <span className="bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-full">Warning</span>
                              ) : null}
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {totalPages > 1 && (
                <div className="flex items-center justify-between px-6 py-4 border-t border-[rgba(0,0,0,0.05)] dark:border-[#ffffff05] bg-gray-50/50 dark:bg-gray-800/20">
                    <div className="text-[12px] text-gray-500 dark:text-gray-400 font-medium tracking-wide">
                        Showing <span className="font-bold text-[#111111] dark:text-white">{(currentPage - 1) * ITEMS_PER_PAGE + 1}</span> to <span className="font-bold text-[#111111] dark:text-white">{Math.min(currentPage * ITEMS_PER_PAGE, total)}</span> of <span className="font-bold text-[#111111] dark:text-white">{total}</span>
                    </div>
                    <div className="flex items-center gap-1.5">
                        <button
                            disabled={currentPage === 1}
                            onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                            className="p-1.5 rounded-lg text-gray-500 hover:text-[#111111] dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent transition-colors shadow-sm bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a]"
                        >
                            <FiChevronLeft className="w-4 h-4" />
                        </button>
                        
                        <div className="flex items-center gap-1">
                            {Array.from({ length: Math.min(5, totalPages - Math.floor((currentPage - 1) / 5) * 5) }, (_, i) => Math.floor((currentPage - 1) / 5) * 5 + 1 + i).map(page => (
                                <button
                                    key={page}
                                    onClick={() => setCurrentPage(page)}
                                    className={`w-7 h-7 rounded-lg text-[12px] font-bold flex items-center justify-center transition-all ${
                                        currentPage === page
                                            ? 'bg-[#2b83fa] text-white shadow-sm ring-1 ring-[#2b83fa]/50'
                                             : 'text-gray-500 hover:text-[#111111] dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/10 bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a]'
                                    }`}
                                >
                                    {page}
                                </button>
                            ))}
                        </div>

                        <button
                            disabled={currentPage === totalPages}
                            onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                            className="p-1.5 rounded-lg text-gray-500 hover:text-[#111111] dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent transition-colors shadow-sm bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a]"
                        >
                            <FiChevronRight className="w-4 h-4" />
                        </button>
                    </div>
                </div>
              )}
            </div>
          </AnimatedContent>
        </div>
      )}

      {loading && (
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-6 shadow-sm">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="flex items-center gap-4 py-3 border-b border-[#00000005] dark:border-[#ffffff05] last:border-0">
              <div className="h-4 rounded-md skeleton w-40" />
              <div className="h-4 rounded-md skeleton w-16" />
              <div className="h-4 rounded-md skeleton w-20" />
            </div>
          ))}
        </div>
      )}

      {!loading && total === 0 && !error && (
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-16 shadow-sm flex flex-col items-center justify-center text-center">
          <FiUsers className="w-10 h-10 text-[#9ca3af] opacity-50 mb-3" />
          <div className="text-[15px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6]">No subaccounts yet</div>
          <div className="text-[13px] text-[#9ca3af] mt-1">Registered subaccounts will appear here after they are linked to your agency.</div>
        </div>
      )}
    </AgencyLayout>
  );
};

export default Dashboard;
