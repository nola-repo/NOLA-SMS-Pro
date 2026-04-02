import React, { useState } from 'react';
import { FiZap, FiRefreshCw, FiSettings } from 'react-icons/fi';
import { AgencySubaccount, toggleSubaccountSim, setSubaccountRateLimit, resetSubaccountAttempts } from '../../api/agency';

interface SIMControlCardProps {
    subaccount: AgencySubaccount;
    onUpdate: () => void;
}

export const SIMControlCard: React.FC<SIMControlCardProps> = ({ subaccount, onUpdate }) => {
    const [isUpdating, setIsUpdating] = useState(false);
    const [rateLimit, setRateLimit] = useState(subaccount.rate_limit);
    const [showSettings, setShowSettings] = useState(false);

    const handleToggle = async () => {
        setIsUpdating(true);
        try {
            await toggleSubaccountSim(subaccount.id, !subaccount.toggle_enabled);
            onUpdate();
        } catch (e) {
            alert(e instanceof Error ? e.message : 'Toggle failed');
        } finally {
            setIsUpdating(false);
        }
    };

    const handleSaveRateLimit = async () => {
        setIsUpdating(true);
        try {
            await setSubaccountRateLimit(subaccount.id, rateLimit);
            setShowSettings(false);
            onUpdate();
        } catch (e) {
            alert(e instanceof Error ? e.message : 'Update failed');
        } finally {
            setIsUpdating(false);
        }
    };

    const handleResetAttempts = async () => {
        if (!confirm('Reset attempt count for this location?')) return;
        setIsUpdating(true);
        try {
            await resetSubaccountAttempts(subaccount.id);
            onUpdate();
        } catch (e) {
            alert(e instanceof Error ? e.message : 'Reset failed');
        } finally {
            setIsUpdating(false);
        }
    };

    return (
        <div className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-5 shadow-sm hover:shadow-md transition-all duration-300">
            <div className="flex items-start justify-between mb-4">
                <div className="flex-1 min-w-0">
                    <h3 className="text-[15px] font-bold text-[#111111] dark:text-white truncate">{subaccount.name}</h3>
                    <p className="text-[12px] text-[#9aa0a6] truncate">{subaccount.id.replace('ghl_', '')}</p>
                </div>
                <button
                    onClick={handleToggle}
                    disabled={isUpdating}
                    className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 ${
                        subaccount.toggle_enabled ? "bg-[#2b83fa]" : "bg-gray-200 dark:bg-[#3a3b3f]"
                    }`}
                >
                    <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform duration-200 ${subaccount.toggle_enabled ? "translate-x-6" : "translate-x-1"}`} />
                </button>
            </div>

            <div className="grid grid-cols-2 gap-4 mb-4">
                <div className="bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl p-3">
                    <p className="text-[10px] font-semibold text-[#9aa0a6] uppercase mb-1">Status</p>
                    <div className="flex items-center gap-2">
                        <div className={`w-2 h-2 rounded-full ${subaccount.toggle_enabled ? 'bg-emerald-500' : 'bg-gray-400'}`} />
                        <span className={`text-[12px] font-bold ${subaccount.toggle_enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-[#6e6e73]'}`}>
                            {subaccount.toggle_enabled ? 'ACTIVE' : 'DISABLED'}
                        </span>
                    </div>
                </div>
                <div className="bg-[#f7f7f7] dark:bg-[#0d0e10] rounded-xl p-3">
                    <p className="text-[10px] font-semibold text-[#9aa0a6] uppercase mb-1">Subaccount Rate Limit</p>
                    <div className="flex items-center gap-1.5">
                        <FiZap className="w-3.5 h-3.5 text-amber-500" />
                        <span className="text-[12px] font-bold text-[#111111] dark:text-[#ececf1]">{subaccount.rate_limit} SMS</span>
                    </div>
                </div>
            </div>

            <div className="flex items-center justify-between pt-2 border-t border-[#f0f0f0] dark:border-[#2a2b32]">
                <div className="flex items-center gap-1">
                  <span className="text-[11px] text-[#9aa0a6]">Attempts: </span>
                  <span className={`text-[11px] font-bold ${subaccount.attempt_count > 5 ? 'text-red-500' : 'text-[#6e6e73]'}`}>
                    {subaccount.attempt_count}
                  </span>
                  <button 
                    onClick={handleResetAttempts}
                    className="p-1 hover:text-[#2b83fa] transition-colors"
                    title="Reset attempts"
                  >
                    <FiRefreshCw className="w-3 h-3" />
                  </button>
                </div>
                <button
                    onClick={() => setShowSettings(!showSettings)}
                    className="flex items-center gap-1.5 text-[12px] font-bold text-[#2b83fa] hover:bg-[#2b83fa]/5 px-2 py-1 rounded-lg transition-all"
                >
                    <FiSettings className="w-3.5 h-3.5" /> Configure
                </button>
            </div>

            {showSettings && (
                <div className="mt-4 pt-4 border-t border-[#f0f0f0] dark:border-[#2a2b32] space-y-4">
                    <div>
                        <label className="block text-[11px] font-semibold text-[#5f6368] dark:text-[#9aa0a6] uppercase mb-2">Max Send Rate (SMS/Month)</label>
                        <div className="flex items-center gap-3">
                            <input
                                type="range"
                                min={10} max={5000} step={50}
                                value={rateLimit}
                                onChange={e => setRateLimit(Number(e.target.value))}
                                className="flex-1 accent-[#2b83fa]"
                            />
                            <span className="text-[14px] font-bold text-[#2b83fa] min-w-[70px] text-right">{rateLimit}</span>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={handleSaveRateLimit}
                            disabled={isUpdating}
                            className="flex-1 bg-[#2b83fa] text-white py-2 rounded-xl text-[12px] font-bold hover:bg-[#1d6bd4] transition-all shadow-sm"
                        >
                            Save Limit
                        </button>
                        <button
                            onClick={() => setShowSettings(false)}
                            className="px-4 py-2 bg-gray-100 dark:bg-[#2a2b32] text-[#6e6e73] dark:text-[#9aa0a6] rounded-xl text-[12px] font-bold hover:bg-gray-200 transition-all"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};
