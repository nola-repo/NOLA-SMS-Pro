import React, { useState } from 'react';
import { FiSettings, FiCopy, FiCheck, FiUser, FiInfo } from 'react-icons/fi';
import { AgencyLayout } from '../components/layout/AgencyLayout.tsx';
import { useAgency } from '../context/AgencyContext.tsx';

export const Settings = () => {
  const { agencyId, agencySession, isGhlFrame, disconnectGhl } = useAgency();
  const [copiedId, setCopiedId] = useState(false);

  const handleCopyId = () => {
    if (!agencyId) return;
    navigator.clipboard.writeText(agencyId);
    setCopiedId(true);
    setTimeout(() => setCopiedId(false), 2000);
  };

  const userName = agencySession?.user
    ? `${agencySession.user.firstName} ${agencySession.user.lastName}`.trim()
    : 'Agency Owner';

  return (
    <AgencyLayout
      title="Settings"
      subtitle="Manage your agency account settings and view system information"
    >
      <div className="flex items-start justify-between mb-6 flex-wrap gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white tracking-tight">Account Settings</h1>
          <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">
            View your GoHighLevel integration details and profile information.
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Profile Card */}
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-6 shadow-sm">
          <div className="flex items-center gap-3 mb-6 pb-4 border-b border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)]">
            <div className="w-10 h-10 rounded-full bg-[#2b83fa]/10 flex items-center justify-center">
              <FiUser className="w-5 h-5 text-[#2b83fa]" />
            </div>
            <div>
              <h2 className="text-[15px] font-bold text-[#111111] dark:text-white">Profile Details</h2>
              <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6]">Your NOLA SMS Pro account</p>
            </div>
          </div>

          <div className="space-y-4">
            <div>
              <label className="block text-[11.5px] font-semibold uppercase tracking-widest text-[#6e6e73] dark:text-[#9aa0a6] mb-1.5">Full Name</label>
              <div className="px-4 py-2.5 rounded-xl border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] bg-[#f7f7f7] dark:bg-[#1c1e21] text-[14px] text-[#111111] dark:text-white font-medium">
                {userName}
              </div>
            </div>
            <div>
              <label className="block text-[11.5px] font-semibold uppercase tracking-widest text-[#6e6e73] dark:text-[#9aa0a6] mb-1.5">Email Address</label>
              <div className="px-4 py-2.5 rounded-xl border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] bg-[#f7f7f7] dark:bg-[#1c1e21] text-[14px] text-[#111111] dark:text-white font-medium">
                {agencySession?.user?.email || 'N/A'}
              </div>
            </div>
          </div>
        </div>

        {/* Integration Card */}
        <div className="bg-white/70 dark:bg-[#121415]/80 backdrop-blur-2xl border border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)] rounded-2xl p-6 shadow-sm">
          <div className="flex items-center gap-3 mb-6 pb-4 border-b border-[rgba(0,0,0,0.05)] dark:border-[rgba(255,255,255,0.05)]">
            <div className="w-10 h-10 rounded-full bg-emerald-500/10 flex items-center justify-center">
              <FiSettings className="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
            </div>
            <div>
              <h2 className="text-[15px] font-bold text-[#111111] dark:text-white">GoHighLevel Integration</h2>
              <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6]">Connection status and identifier</p>
            </div>
          </div>

          <div className="space-y-4">
            <div>
              <label className="block text-[11.5px] font-semibold uppercase tracking-widest text-[#6e6e73] dark:text-[#9aa0a6] mb-1.5 flex items-center gap-2">
                Agency / Company ID
              </label>
              
              <div className="flex items-center gap-2">
                <div className="flex-1 px-4 py-2.5 rounded-xl border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] bg-[#f0f2f8] dark:bg-[#1c1e21] text-[14px] text-[#111111] dark:text-[#ececf1] font-mono break-all relative group">
                  {agencyId || 'Not Connected'}
                </div>
                {agencyId && (
                  <button
                    onClick={handleCopyId}
                    className="shrink-0 p-3 rounded-xl border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] bg-white dark:bg-[#25282c] text-[#6e6e73] dark:text-[#9aa0a6] hover:text-[#2b83fa] dark:hover:text-[#2b83fa] hover:border-[#2b83fa]/30 transition-all shadow-sm"
                    title="Copy Company ID"
                  >
                    {copiedId ? <FiCheck className="w-4 h-4 text-emerald-500" /> : <FiCopy className="w-4 h-4" />}
                  </button>
                )}
              </div>
              <p className="text-[11px] text-[#9ca3af] mt-2">
                This ID uniquely identifies your GoHighLevel agency. It is required by NOLA SMS Pro to link your subaccounts.
              </p>
            </div>

            <div className="pt-4">
              <div className="flex items-start justify-between gap-4 p-4 bg-[#f0f2f8] dark:bg-white/5 rounded-xl border border-[rgba(0,0,0,0.05)] dark:border-white/5 flex-wrap">
                <div className="flex items-start gap-3">
                  <FiInfo className="w-5 h-5 text-[#2b83fa] shrink-0 mt-0.5" />
                  <div>
                    <h4 className="text-[13px] font-bold text-[#111111] dark:text-white">Environment Status</h4>
                    <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1 leading-relaxed">
                      You are currently logged in via <strong>{isGhlFrame ? 'the GoHighLevel iframe auto-login' : 'the standalone NOLA SMS Pro portal'}</strong>.
                    </p>
                  </div>
                </div>
                {!isGhlFrame && (
                  <button
                    onClick={() => {
                        if (agencyId) {
                            disconnectGhl();
                        } else {
                            window.location.href = '/login'; // Or whatever connects GHL
                        }
                    }}
                    className={`shrink-0 px-4 py-2 rounded-lg text-[13px] font-bold transition-all shadow-sm ${
                      agencyId 
                      ? 'bg-red-50 text-red-600 border border-red-200 hover:bg-red-500 hover:text-white dark:bg-red-500/10 dark:border-red-500/20' 
                      : 'bg-[#2b83fa] text-white hover:bg-[#1d6bd4]'
                    }`}
                  >
                    {agencyId ? 'Disconnect' : 'Connect GHL'}
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>
    </AgencyLayout>
  );
};
