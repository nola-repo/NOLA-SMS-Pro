import React, { useState, useEffect, useCallback } from 'react';
import { FiSearch, FiRefreshCw, FiCheckCircle, FiAlertCircle, FiGrid, FiList } from 'react-icons/fi';
import { AgencySubaccount, fetchAgencySubaccounts, syncGhlLocations } from '../../api/agency';
import { SIMControlCard } from './SIMControlCard';

export const SubaccountManager: React.FC = () => {
    const [subaccounts, setSubaccounts] = useState<AgencySubaccount[]>([]);
    const [loading, setLoading] = useState(true);
    const [syncing, setSyncing] = useState(false);
    const [search, setSearch] = useState('');
    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');

    const loadSubaccounts = useCallback(async () => {
        setLoading(true);
        try {
            const data = await fetchAgencySubaccounts();
            setSubaccounts(data);
        } catch (e) {
            console.error('Failed to load subaccounts:', e);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadSubaccounts();
    }, [loadSubaccounts]);

    const handleSync = async () => {
        setSyncing(true);
        try {
            await syncGhlLocations();
            await loadSubaccounts();
        } catch (e) {
            alert(e instanceof Error ? e.message : 'Sync failed');
        } finally {
            setSyncing(false);
        }
    };

    const filtered = subaccounts.filter(s => 
        s.name.toLowerCase().includes(search.toLowerCase()) || 
        s.id.toLowerCase().includes(search.toLowerCase())
    );

    const stats = {
        total: subaccounts.length,
        active: subaccounts.filter(s => s.toggle_enabled).length,
        disabled: subaccounts.filter(s => !s.toggle_enabled).length,
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 className="text-[20px] font-extrabold text-[#111111] dark:text-white tracking-tight">Agency Sub-account Management</h2>
                    <p className="text-[14px] text-[#6e6e73] dark:text-[#94959b] mt-1">SIM control, rate limits, and status monitoring for all connected locations.</p>
                </div>
                <button
                    onClick={handleSync}
                    disabled={syncing}
                    className="flex items-center justify-center gap-2 px-5 py-2.5 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-bold text-[13px] transition-all shadow-md shadow-blue-500/20 disabled:opacity-50"
                >
                    <FiRefreshCw className={`w-4 h-4 ${syncing ? 'animate-spin' : ''}`} />
                    {syncing ? 'Syncing Locations...' : 'Sync from GoHighLevel'}
                </button>
            </div>

            {/* Stats Bar */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {[
                    { label: "Total Subaccounts", value: stats.total, icon: <FiGrid className="w-4 h-4" />, color: "text-[#2b83fa]", bg: "bg-[#2b83fa]/10" },
                    { label: "Active SIM Routing", value: stats.active, icon: <FiCheckCircle className="w-4 h-4" />, color: "text-emerald-500", bg: "bg-emerald-500/10" },
                    { label: "Disabled Routing", value: stats.disabled, icon: <FiAlertCircle className="w-4 h-4" />, color: "text-amber-500", bg: "bg-amber-500/10" },
                ].map(stat => (
                    <div key={stat.label} className="bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 rounded-2xl p-4 flex items-center gap-4 shadow-sm">
                        <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${stat.bg} ${stat.color}`}>{stat.icon}</div>
                        <div>
                            <p className="text-[22px] font-black text-[#111111] dark:text-[#ececf1] leading-none mb-1">{stat.value}</p>
                            <p className="text-[11px] font-bold text-[#9aa0a6] uppercase tracking-wider">{stat.label}</p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="flex items-center justify-between gap-4">
                <div className="flex-1 relative max-w-md">
                    <input
                        type="text"
                        placeholder="Search by name or location ID..."
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="w-full pl-10 pr-4 py-2.5 rounded-xl text-[14px] border bg-white dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] focus:ring-2 focus:ring-[#2b83fa]/25 focus:border-[#2b83fa]/40 transition-all outline-none"
                    />
                    <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                </div>
                <div className="flex border border-[#e0e0e0] dark:border-[#ffffff0a] rounded-xl overflow-hidden shadow-sm">
                    <button 
                        onClick={() => setViewMode('grid')}
                        className={`p-2.5 transition-colors ${viewMode === 'grid' ? 'bg-[#2b83fa]/10 text-[#2b83fa]' : 'bg-white dark:bg-[#1a1b1e] text-[#6e6e73]'}`}
                    >
                        <FiGrid className="w-4 h-4" />
                    </button>
                    <button 
                        onClick={() => setViewMode('list')}
                        className={`p-2.5 transition-colors ${viewMode === 'list' ? 'bg-[#2b83fa]/10 text-[#2b83fa]' : 'bg-white dark:bg-[#1a1b1e] text-[#6e6e73]'}`}
                    >
                        <FiList className="w-4 h-4" />
                    </button>
                </div>
            </div>

            {loading ? (
                <div className="flex justify-center items-center py-20">
                    <FiRefreshCw className="w-8 h-8 text-[#2b83fa] animate-spin" />
                </div>
            ) : filtered.length > 0 ? (
                <div className={viewMode === 'grid' ? "grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" : "space-y-4"}>
                    {filtered.map(s => (
                        <SIMControlCard key={s.id} subaccount={s} onUpdate={loadSubaccounts} />
                    ))}
                </div>
            ) : (
                <div className="bg-[#f7f7f7] dark:bg-[#0d0e10] border border-dashed border-[#e0e0e0] dark:border-[#ffffff0a] rounded-2xl py-20 text-center">
                    <div className="w-16 h-16 rounded-full bg-white dark:bg-[#1a1b1e] flex items-center justify-center mx-auto mb-4 text-[#9aa0a6] shadow-sm">
                        <FiSearch className="w-6 h-6" />
                    </div>
                    <h3 className="text-[15px] font-bold text-[#3c4043] dark:text-[#e8eaed]">No subaccounts found</h3>
                    <p className="text-[13px] text-[#9aa0a6] mt-1">Try syncing from GoHighLevel if your locations are missing.</p>
                </div>
            )}
        </div>
    );
};
