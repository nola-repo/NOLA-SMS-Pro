import React, { useState, useEffect, useCallback } from 'react';
import { 
    FiCheck, FiX, FiClock, FiShield, FiSend, 
    FiKey, FiExternalLink, FiSearch, FiFilter 
} from 'react-icons/fi';
import { API_BASE_URL, WEBHOOK_SECRET } from '../api/config';

interface SenderRequest {
    id: string;
    location_id: string;
    requested_id: string;
    status: 'pending' | 'approved' | 'rejected';
    purpose: string;
    sample: string;
    created_at: string;
}

export const AdminDashboard: React.FC = () => {
    const [requests, setRequests] = useState<SenderRequest[]>([]);
    const [loading, setLoading] = useState(true);
    const [selectedRequest, setSelectedRequest] = useState<SenderRequest | null>(null);
    const [apiKey, setApiKey] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const fetchAllRequests = useCallback(async () => {
        try {
            // In a real admin app, this would be a different endpoint that fetches ALL pending requests
            // For this implementation, we'll use the same endpoint but without a location filter if the backend allows
            // Or a dedicated admin endpoint.
            const res = await fetch(`${API_BASE_URL}/api/admin_sender_requests.php`, {
                headers: { 'X-Webhook-Secret': WEBHOOK_SECRET }
            });
            const result = await res.json();
            if (result.status === 'success') {
                setRequests(result.data);
            }
        } catch (error) {
            console.error('Failed to fetch requests:', error);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchAllRequests();
    }, [fetchAllRequests]);

    const handleAction = async (requestId: string, status: 'approved' | 'rejected') => {
        setSubmitting(true);
        try {
            const res = await fetch(`${API_BASE_URL}/api/admin_sender_requests.php`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Webhook-Secret': WEBHOOK_SECRET 
                },
                body: JSON.stringify({
                    request_id: requestId,
                    status: status,
                    api_key: status === 'approved' ? apiKey : null
                })
            });
            const result = await res.json();
            if (result.status === 'success') {
                fetchAllRequests();
                setSelectedRequest(null);
                setApiKey('');
            } else {
                alert(result.message || 'Operation failed');
            }
        } catch (error) {
            console.error('Admin action error:', error);
            alert('Failed to process request');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="p-8 max-w-7xl mx-auto dark:bg-[#111111] min-h-screen text-[#37352f] dark:text-[#ececf1]">
            <header className="mb-8 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-black tracking-tight flex items-center gap-3">
                        <FiShield className="text-[#2b83fa]" />
                        Admin: Sender ID Management
                    </h1>
                    <p className="text-gray-500 dark:text-gray-400 mt-1">Review and provision custom Sender IDs for NOLA SMS Pro clients.</p>
                </div>
            </header>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* List Pane */}
                <div className="lg:col-span-2 space-y-4">
                    <div className="bg-white dark:bg-[#1a1b1e] rounded-2xl border border-gray-200 dark:border-white/5 overflow-hidden shadow-sm">
                        <div className="p-4 border-b border-gray-100 dark:border-white/5 flex items-center justify-between">
                            <span className="text-sm font-bold uppercase tracking-widest text-gray-400">Requests</span>
                            <div className="flex items-center gap-2">
                                <button className="p-2 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg text-gray-400"><FiFilter /></button>
                                <button className="p-2 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg text-gray-400"><FiSearch /></button>
                            </div>
                        </div>

                        {loading ? (
                            <div className="p-12 text-center text-gray-400">Loading requests...</div>
                        ) : requests.length === 0 ? (
                            <div className="p-12 text-center text-gray-400 italic">No pending requests found.</div>
                        ) : (
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-gray-50 dark:bg-black/20 text-[11px] font-black uppercase tracking-tighter text-gray-400">
                                        <th className="px-5 py-3">Location ID</th>
                                        <th className="px-5 py-3">Requested ID</th>
                                        <th className="px-5 py-3">Status</th>
                                        <th className="px-5 py-3">Date</th>
                                        <th className="px-5 py-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {requests.map(req => (
                                        <tr 
                                            key={req.id} 
                                            onClick={() => setSelectedRequest(req)}
                                            className={`cursor-pointer border-t border-gray-100 dark:border-white/5 hover:bg-gray-50 dark:hover:bg-white/[0.02] transition-colors ${selectedRequest?.id === req.id ? 'bg-blue-50/50 dark:bg-blue-900/10' : ''}`}
                                        >
                                            <td className="px-5 py-4 text-[13px] font-mono opacity-60">{req.location_id.substring(0, 12)}...</td>
                                            <td className="px-5 py-4 font-bold text-[14px]">{req.requested_id}</td>
                                            <td className="px-5 py-4">
                                                <span className={`px-2 py-0.5 rounded-full text-[11px] font-bold uppercase ${
                                                    req.status === 'approved' ? 'bg-emerald-100 text-emerald-700' : 
                                                    req.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'
                                                }`}>
                                                    {req.status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-[12px] opacity-60">{req.created_at}</td>
                                            <td className="px-5 py-4 text-right">
                                                <button className="text-blue-500 hover:underline text-[13px] font-bold">Review</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>

                {/* Detail Pane */}
                <div className="space-y-6">
                    {selectedRequest ? (
                        <div className="bg-white dark:bg-[#1a1b1e] rounded-2xl border border-gray-200 dark:border-white/5 p-6 shadow-xl sticky top-8">
                            <h2 className="text-lg font-black mb-6 flex items-center gap-2">
                                <FiExternalLink className="text-[#2b83fa]" />
                                Request Details
                            </h2>

                            <div className="space-y-5">
                                <div>
                                    <label className="text-[10px] font-black uppercase text-gray-400 block mb-1">Company / Location</label>
                                    <p className="font-bold text-[14px]">{selectedRequest.location_id}</p>
                                </div>
                                <div>
                                    <label className="text-[10px] font-black uppercase text-gray-400 block mb-1">Requested Sender ID</label>
                                    <p className="text-2xl font-black text-[#111111] dark:text-white uppercase tracking-tight">{selectedRequest.requested_id}</p>
                                </div>
                                <div>
                                    <label className="text-[10px] font-black uppercase text-gray-400 block mb-1">Purpose of Use</label>
                                    <p className="text-[13px] leading-relaxed opacity-80">{selectedRequest.purpose || 'No purpose provided.'}</p>
                                </div>
                                <div>
                                    <label className="text-[10px] font-black uppercase text-gray-400 block mb-1">Message Sample</label>
                                    <div className="p-3 bg-gray-50 dark:bg-black/20 rounded-xl border border-gray-100 dark:border-white/5 text-[12px] italic opacity-80">
                                        "{selectedRequest.sample || 'No sample message provided.'}"
                                    </div>
                                </div>

                                {selectedRequest.status === 'pending' && (
                                    <div className="pt-4 border-t border-gray-100 dark:border-white/5 space-y-4">
                                        <div>
                                            <label className="text-[10px] font-black uppercase text-[#2b83fa] block mb-1 flex items-center gap-1">
                                                <FiKey /> Semaphore API Key
                                            </label>
                                            <input 
                                                type="text" 
                                                value={apiKey}
                                                onChange={(e) => setApiKey(e.target.value)}
                                                placeholder="Paste client's Semaphore API Key"
                                                className="w-full px-4 py-2.5 rounded-xl bg-gray-50 dark:bg-black/40 border border-gray-200 dark:border-white/10 text-[13px] focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all"
                                            />
                                            <p className="text-[10px] text-gray-400 mt-1">This key will be used only for this location's messages.</p>
                                        </div>

                                        <div className="flex gap-3">
                                            <button 
                                                onClick={() => handleAction(selectedRequest.id, 'approved')}
                                                disabled={submitting || !apiKey}
                                                className="flex-1 py-3 bg-emerald-500 hover:bg-emerald-600 disabled:opacity-50 text-white rounded-xl font-bold text-[13px] flex items-center justify-center gap-2 transition-all shadow-lg shadow-emerald-500/20"
                                            >
                                                <FiCheck /> Approve & Provision
                                            </button>
                                            <button 
                                                onClick={() => handleAction(selectedRequest.id, 'rejected')}
                                                disabled={submitting}
                                                className="px-4 py-3 bg-red-50 dark:bg-red-900/10 hover:bg-red-100 text-red-600 rounded-xl font-bold text-[13px] flex items-center justify-center transition-all"
                                            >
                                                <FiX />
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="bg-gray-50 dark:bg-black/10 rounded-2xl border border-dashed border-gray-200 dark:border-white/5 p-12 text-center text-gray-400">
                            <FiSend className="w-12 h-12 mx-auto mb-4 opacity-10" />
                            <p className='text-sm italic'>Select a request from the list to preview details and approve.</p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};
