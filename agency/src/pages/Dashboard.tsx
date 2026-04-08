import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FiToggleLeft, FiUsers, FiCheckCircle, FiAlertTriangle, FiArrowRight, FiSend, FiChevronLeft, FiChevronRight } from 'react-icons/fi';
import { AgencyLayout } from '../components/layout/AgencyLayout.tsx';
import { useAgency } from '../context/AgencyContext.tsx';
import { getSubaccounts } from '../services/api.ts';

const POLL_MS = 10000;

export const Dashboard = () => {
  const { agencyId } = useAgency();
  const navigate = useNavigate();

  const [subaccounts, setSubaccounts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]   = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const ITEMS_PER_PAGE = 10;

  const fetchData = async () => {
    if (!agencyId) return;
    try {
      const data = await getSubaccounts(agencyId);
      setSubaccounts(data.subaccounts || []);
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
    const id = setInterval(fetchData, POLL_MS);
    return () => clearInterval(id);
  }, [agencyId]);

  const total   = subaccounts.length;
  const active  = subaccounts.filter(s => s.toggle_enabled).length;
  const atLimit = subaccounts.filter(s => s.attempt_count >= s.rate_limit).length;
  const totalSends = subaccounts.reduce((sum, s) => sum + (s.attempt_count || 0), 0);

  const totalPages = Math.ceil(total / ITEMS_PER_PAGE);
  const paginatedSubaccounts = subaccounts.slice((currentPage - 1) * ITEMS_PER_PAGE, currentPage * ITEMS_PER_PAGE);

  useEffect(() => {
    if (currentPage > totalPages && totalPages > 0) setCurrentPage(totalPages);
  }, [total, currentPage, totalPages]);

  return (
    <AgencyLayout title="Dashboard" subtitle="Agency overview and quick stats">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white tracking-tight">Dashboard</h1>
          <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">Real-time overview of your subaccounts and SMS activity.</p>
        </div>
      </div>

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

      {/* Stats - Top Layer */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        {[
          { label: 'Total Subaccounts', value: total, sub: 'registered under your agency', color: 'from-[#2b83fa] to-[#60a5fa]', icon: <FiUsers className="w-full h-full" /> },
          { label: 'Total Sends', value: totalSends, sub: 'across all subaccounts', color: 'from-indigo-500 to-purple-600', icon: <FiSend className="w-full h-full" /> },
        ].map((stat, idx) => (
          <div key={`top-${idx}`} className={`relative p-5 rounded-3xl bg-gradient-to-br ${stat.color} shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 overflow-hidden group`}>
            <div className="absolute top-0 right-0 p-4 opacity-10 group-hover:scale-110 transition-transform duration-500 text-white">
                <div className="w-20 h-20">{stat.icon}</div>
            </div>
            <div className="relative z-10">
                <div className="w-10 h-10 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white mb-3 group-hover:rotate-6 transition-transform duration-300 shadow-inner">
                    {stat.icon}
                </div>
                <div className="text-[12px] font-bold text-white/80 uppercase tracking-widest mb-1.5">{stat.label}</div>
                <div className="text-4xl font-black text-white tracking-tight drop-shadow-sm leading-none">
                  {loading ? '—' : stat.value}
                </div>
                <div className="text-[12px] text-white/70 mt-2 line-clamp-1">{stat.sub}</div>
            </div>
          </div>
        ))}
      </div>

      {/* Stats - Bottom Layer */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        {[
          { label: 'Active (SMS ON)', value: active, sub: 'webhook enabled', color: 'from-emerald-500 to-teal-600', icon: <FiCheckCircle className="w-full h-full" /> },
          { label: 'Inactive (SMS OFF)', value: total - active, sub: 'hidden from main admin', color: 'from-slate-400 to-slate-500', icon: <FiUsers className="w-full h-full" /> },
          { label: 'At Rate Limit', value: atLimit, sub: 'blocked until reset', color: atLimit > 0 ? 'from-amber-500 to-orange-500' : 'from-slate-400 to-slate-500', icon: <FiAlertTriangle className="w-full h-full" /> },
        ].map((stat, idx) => (
          <div key={`bottom-${idx}`} className={`relative p-5 rounded-3xl bg-gradient-to-br ${stat.color} shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300 overflow-hidden group`}>
            <div className="absolute top-0 right-0 p-4 opacity-10 group-hover:scale-110 transition-transform duration-500 text-white">
                <div className="w-16 h-16">{stat.icon}</div>
            </div>
            <div className="relative z-10">
                <div className="w-10 h-10 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white mb-3 group-hover:rotate-6 transition-transform duration-300 shadow-inner">
                    {stat.icon}
                </div>
                <div className="text-[11.5px] font-bold text-white/80 uppercase tracking-widest mb-1.5">{stat.label}</div>
                <div className="text-3xl font-black text-white tracking-tight drop-shadow-sm leading-none">
                  {loading ? '—' : stat.value}
                </div>
                <div className="text-[11.5px] text-white/70 mt-1.5 line-clamp-1">{stat.sub}</div>
            </div>
          </div>
        ))}
      </div>

      {/* Recent subaccounts at limit */}
      {!loading && atLimit > 0 && (
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-red-500/50 rounded-2xl shadow-sm overflow-hidden mb-6">
          <div className="p-6 border-b border-[#00000005] dark:border-[#ffffff05] flex items-start justify-between flex-wrap gap-4">
            <div>
              <div className="text-[15px] font-bold text-red-500">
                ⚠ Subaccounts at Rate Limit ({atLimit})
              </div>
              <div className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">These subaccounts are blocked. Reset their counters to re-enable sending.</div>
            </div>
            <button 
              className="flex items-center gap-2 px-3 py-1.5 bg-red-50 dark:bg-red-500/10 text-red-500 text-[12.5px] font-bold rounded-lg border border-red-200 dark:border-red-500/20 hover:bg-red-500 hover:text-white transition-all"
              onClick={() => navigate('/subaccounts')}
            >
              Review &amp; Reset <FiArrowRight className="w-3.5 h-3.5" />
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="border-b border-[#00000005] dark:border-[#ffffff05]">
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b]">Subaccount</th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b]">Attempts</th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b]">Limit</th>
                </tr>
              </thead>
              <tbody>
                {subaccounts
                  .filter(s => s.attempt_count >= s.rate_limit)
                  .map((s, i) => (
                    <tr key={s.location_id || i} className="border-b border-[#00000005] dark:border-[#ffffff05] hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                      <td className="px-6 py-4 align-middle">
                        <div className="flex flex-col">
                          <span className="text-[13.5px] font-semibold text-[#111111] dark:text-[#ececf1]">{s.location_name || s.company_name || 'Unnamed'}</span>
                          <span className="text-[11px] font-mono text-[#6e6e73] dark:text-[#94959b] mt-0.5">{s.location_id}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 align-middle text-[13.5px] font-bold text-red-500">{s.attempt_count}</td>
                      <td className="px-6 py-4 align-middle text-[13.5px] text-[#6e6e73] dark:text-[#94959b]">{s.rate_limit}</td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* All subaccounts summary */}
      {!loading && total > 0 && (
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[#0000000a] dark:border-[#ffffff0a] rounded-2xl shadow-sm overflow-hidden mb-6">
          <div className="p-6 border-b border-[#0000000a] dark:border-[#ffffff0a]">
            <div className="text-[15px] font-bold text-[#111111] dark:text-white tracking-tight">All Subaccounts</div>
            <div className="text-[13px] text-[#6e6e73] dark:text-[#94959b] mt-1">Summary of all subaccounts under your agency.</div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="border-b border-[#0000000a] dark:border-[#ffffff0a]">
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b]">Subaccount</th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b]">Status</th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b]">Sends Used</th>
                  <th className="px-6 py-3 text-[11px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#94959b]">Limit</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[rgba(0,0,0,0.05)] dark:divide-[rgba(255,255,255,0.05)]">
                {paginatedSubaccounts.map((s, i) => {
                  const isAtLimit = s.attempt_count >= s.rate_limit;
                  const isNearLimit = s.attempt_count >= s.rate_limit * 0.8;
                  
                  return (
                    <tr key={s.location_id || i} className="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                      <td className="px-6 py-4 align-middle">
                        <div className="flex flex-col">
                          <span className="text-[13.5px] font-semibold text-[#111111] dark:text-[#ececf1]">{s.location_name || s.company_name || 'Unnamed'}</span>
                          <span className="text-[11px] font-mono text-[#6e6e73] dark:text-[#94959b] mt-0.5">{s.location_id}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 align-middle">
                        <div className="flex items-center gap-2">
                          <div className={`w-2 h-2 rounded-full ${s.toggle_enabled ? 'bg-[#22c55e] shadow-[0_0_0_3px_rgba(34,197,94,0.1)]' : 'bg-[#9ca3af]'}`} />
                          <span className={`text-[12px] font-semibold ${s.toggle_enabled ? 'text-[#22c55e]' : 'text-[#9ca3af]'}`}>
                            {s.toggle_enabled ? 'Active' : 'Inactive'}
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4 align-middle">
                        <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[12px] font-semibold ${isAtLimit ? 'bg-red-50 dark:bg-red-500/10 text-red-500' : isNearLimit ? 'bg-[#f59e0b]/10 text-[#f59e0b]' : 'bg-[#f0f2f8] dark:bg-white/5 text-[#6b7280] dark:text-[#94959b]'}`}>
                          {s.attempt_count} / {s.rate_limit}
                        </span>
                      </td>
                      <td className="px-6 py-4 align-middle text-[13.5px] text-[#6e6e73] dark:text-[#94959b]">{s.rate_limit}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {totalPages > 1 && (
            <div className="flex items-center justify-between px-6 py-4 border-t border-[rgba(0,0,0,0.05)] dark:border-[#ffffff0a]">
                <div className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] font-medium">
                    Showing <span className="font-bold text-[#111111] dark:text-white">{(currentPage - 1) * ITEMS_PER_PAGE + 1}</span> to <span className="font-bold text-[#111111] dark:text-white">{Math.min(currentPage * ITEMS_PER_PAGE, total)}</span> of <span className="font-bold text-[#111111] dark:text-white">{total}</span> entries
                </div>
                <div className="flex items-center gap-1.5">
                    <button
                        disabled={currentPage === 1}
                        onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                        className="p-1.5 rounded-lg text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f0f0f0] dark:hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
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
                                        ? 'bg-[#2b83fa] text-white shadow-sm'
                                        : 'text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f0f0f0] dark:hover:bg-white/5'
                                }`}
                            >
                                {page}
                            </button>
                        ))}
                    </div>

                    <button
                        disabled={currentPage === totalPages}
                        onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                        className="p-1.5 rounded-lg text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f0f0f0] dark:hover:bg-white/5 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                    >
                        <FiChevronRight className="w-4 h-4" />
                    </button>
                </div>
            </div>
          )}
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
          <div className="text-[15px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6]">No subaccounts found</div>
          <div className="text-[13px] text-[#9ca3af] mt-1">No subaccounts are registered under your agency ID yet.</div>
        </div>
      )}
    </AgencyLayout>
  );
};

