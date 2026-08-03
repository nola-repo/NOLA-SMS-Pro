import React, { useState } from 'react';
import { FiGift, FiX, FiChevronDown, FiAlertTriangle, FiRefreshCw } from 'react-icons/fi';
import { agencyFetch } from '../../services/agencyApi';
import type { Subaccount } from './types';

const API_BASE = import.meta.env.VITE_API_BASE || '';

export const GiftCreditsModal: React.FC<{
  subaccounts: Subaccount[];
  agencyId: string;
  agencyBalance: number;
  onClose: () => void;
  onSuccess: (locationId: string, amount: number) => void;
}> = ({ subaccounts, agencyId, agencyBalance, onClose, onSuccess }) => {
  const [selectedId, setSelectedId] = useState('');
  const [amount, setAmount] = useState(100);
  const [note, setNote] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedId) { setError('Please select a subaccount'); return; }
    if (amount <= 0) { setError('Amount must be greater than 0'); return; }
    if (amount > agencyBalance) { setError('Insufficient agency balance'); return; }
    setLoading(true);
    setError('');
    try {
      const res = await agencyFetch(`${API_BASE}/api/billing/agency_wallet.php`, {
        method: 'POST',
        credentials: 'include',
        body: JSON.stringify({ action: 'gift', location_id: selectedId, amount, note, agency_id: agencyId }),
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Gift failed');
      onSuccess(selectedId, amount);
    } catch (e: any) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-[1000] animate-[fadeIn_0.15s_ease]" onClick={onClose}>
      <div className="bg-white dark:bg-[#141618] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] rounded-2xl shadow-2xl p-7 w-full max-w-[440px] mx-4 animate-[scaleIn_0.2s_ease]" onClick={e => e.stopPropagation()}>
        <div className="flex items-center gap-3 mb-5">
          <div className="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-500">
            <FiGift className="w-5 h-5" />
          </div>
          <div>
            <div className="text-[16px] font-bold text-[#111111] dark:text-white">Gift Credits</div>
            <div className="text-[12px] text-[#6b7280] dark:text-[#9aa0a9]">Transfer credits to a subaccount wallet</div>
          </div>
          <button onClick={onClose} className="ml-auto p-1.5 rounded-lg text-[#9aa0a9] hover:text-[#111111] dark:hover:text-white hover:bg-black/5 dark:hover:bg-white/5">
            <FiX className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Subaccount</label>
            <div className="relative">
              <select
                value={selectedId}
                onChange={e => setSelectedId(e.target.value)}
                className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-purple-500/30 appearance-none pr-9"
              >
                <option value="">Select a subaccount…</option>
                {subaccounts.map(s => (
                  <option key={s.location_id} value={s.location_id}>
                    {s.location_name} ({(s.credit_balance || 0).toLocaleString()} credits)
                  </option>
                ))}
              </select>
              <FiChevronDown className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#9aa0a6] pointer-events-none" />
            </div>
          </div>

          <div>
            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Credits to Gift</label>
            <div className="grid grid-cols-4 gap-2 mb-2">
              {[50, 100, 250, 500].map(n => (
                <button key={n} type="button" onClick={() => setAmount(n)}
                  className={`py-2 rounded-xl text-[12px] font-bold border-2 transition-all ${amount === n ? 'border-purple-500 bg-purple-500/5 text-purple-600' : 'border-[#e0e0e0] dark:border-[#2a2b32] text-[#6e6e73] dark:text-[#9aa0a9] hover:border-purple-400'}`}>
                  {n.toLocaleString()}
                </button>
              ))}
            </div>
            <input
              type="number"
              min={1}
              max={agencyBalance}
              value={amount}
              onChange={e => setAmount(Math.max(1, parseInt(e.target.value) || 1))}
              className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-purple-500/30"
              placeholder="Custom amount…"
            />
            <div className="flex justify-between text-[11px] text-[#9aa0a6] mt-1">
              <span>Agency balance after: <strong className="text-[#111111] dark:text-white">{Math.max(0, agencyBalance - amount).toLocaleString()}</strong></span>
            </div>
          </div>

          <div>
            <label className="block text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider mb-1.5">Note (optional)</label>
            <input
              type="text"
              value={note}
              onChange={e => setNote(e.target.value)}
              placeholder="E.g. Monthly allocation…"
              className="w-full px-4 py-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e0e0e0] dark:border-[#ffffff0a] text-[13px] text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-purple-500/30"
            />
          </div>

          {error && (
            <div className="flex items-center gap-2 p-3 rounded-xl bg-red-50 dark:bg-red-900/10 text-red-500 text-[12.5px]">
              <FiAlertTriangle className="w-4 h-4 flex-shrink-0" /> {error}
            </div>
          )}

          <div className="flex gap-2.5 pt-1">
            <button type="button" onClick={onClose} disabled={loading}
              className="flex-1 px-4 py-2.5 rounded-xl text-[13px] font-semibold bg-[#f0f2f8] dark:bg-[#1c1e21] text-[#6b7280] dark:text-[#9aa0a9] border border-[rgba(0,0,0,0.07)] dark:border-[rgba(255,255,255,0.07)] hover:bg-black/5 transition-colors">
              Cancel
            </button>
            <button type="submit" disabled={loading || !selectedId || amount <= 0}
              className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-[13px] font-semibold bg-purple-600 text-white hover:bg-purple-700 transition-colors shadow-md shadow-purple-500/20 disabled:opacity-50 disabled:cursor-not-allowed">
              {loading ? <FiRefreshCw className="w-4 h-4 animate-spin" /> : <FiGift className="w-4 h-4" />}
              {loading ? 'Sending…' : 'Gift Credits'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
