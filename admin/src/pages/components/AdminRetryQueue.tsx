import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  FiRefreshCw,
  FiClock,
  FiAlertCircle,
  FiCheckCircle,
  FiSearch,
  FiCopy,
  FiCheck,
  FiLayers,
} from 'react-icons/fi';
import { adminFetch } from '../../utils/adminApi';
import { getAdminAuthHeaders } from '../../utils/adminAuthHeaders';
import type { SmsRetryQueueDoc } from '../../types/Sms';

const RETRY_QUEUE_API = '/api/admin_sender_requests.php?action=retry_queue';
const POLL_INTERVAL = 10000;

export const AdminRetryQueue: React.FC = () => {
  const [items, setItems] = useState<SmsRetryQueueDoc[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('active');
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

  const fetchQueue = useCallback(async (isInitial = false) => {
    if (isInitial) setLoading(true);
    setError(null);

    try {
      const res = await adminFetch(RETRY_QUEUE_API, { headers: getAdminAuthHeaders() });
      const json = await res.json().catch(() => ({}));

      if (res.ok && json.status === 'success') {
        setItems(Array.isArray(json.data) ? json.data : []);
      } else {
        // Fallback: If endpoint returns 404/unsupported, show graceful message or empty queue
        if (res.status === 404 || res.status === 400) {
          setItems([]);
        } else {
          setError(json.message || 'Failed to load SMS retry queue.');
        }
      }
      setLastRefreshed(new Date());
    } catch {
      setError('Network error. Could not connect to retry queue endpoint.');
    } finally {
      if (isInitial) setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchQueue(true);
    const timer = setInterval(() => fetchQueue(false), POLL_INTERVAL);
    return () => clearInterval(timer);
  }, [fetchQueue]);

  const copyToClipboard = (text: string, id: string) => {
    navigator.clipboard.writeText(text);
    setCopiedId(id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  const parseDate = (val: unknown): Date | null => {
    if (!val) return null;
    if (typeof val === 'string' || typeof val === 'number') {
      const d = new Date(val);
      return isNaN(d.getTime()) ? null : d;
    }
    if (typeof val === 'object' && val !== null) {
      if ('toDate' in val && typeof (val as { toDate: () => Date }).toDate === 'function') {
        return (val as { toDate: () => Date }).toDate();
      }
      if ('_seconds' in val) {
        return new Date((val as { _seconds: number })._seconds * 1000);
      }
      if ('seconds' in val) {
        return new Date((val as { seconds: number }).seconds * 1000);
      }
    }
    return null;
  };

  const formatCountdown = (date: Date | null) => {
    if (!date) return '-';
    const now = Date.now();
    const diffMs = date.getTime() - now;
    if (diffMs <= 0) return 'Due now';
    const diffSec = Math.floor(diffMs / 1000);
    if (diffSec < 60) return `in ${diffSec}s`;
    const diffMin = Math.floor(diffSec / 60);
    return `in ${diffMin}m ${diffSec % 60}s`;
  };

  const activeItems = useMemo(() => {
    return items.filter(item => ['pending_retry', 'processing'].includes(item.status));
  }, [items]);

  const filteredItems = useMemo(() => {
    return items.filter(item => {
      if (statusFilter === 'active') {
        if (!['pending_retry', 'processing'].includes(item.status)) return false;
      } else if (statusFilter !== 'all') {
        if (item.status !== statusFilter) return false;
      }

      if (!search.trim()) return true;
      const q = search.toLowerCase();
      const match = [
        item.phone,
        item.sender_id,
        item.provider,
        item.message_id,
        item.ghl_message_id,
        item.last_error,
        item.status,
      ].filter(Boolean).join(' ').toLowerCase();

      return match.includes(q);
    });
  }, [items, statusFilter, search]);

  return (
    <div className="space-y-4">
      {/* Header Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl border bg-white dark:bg-[#1a1b1e] border-[#e5e5e5] dark:border-white/5 shadow-sm">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 dark:bg-amber-500/10 text-amber-500">
            <FiLayers className="h-5 w-5" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-[15px] font-bold text-[#111111] dark:text-white">SMS Retry Queue</h3>
              <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-black bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                {activeItems.length} active
              </span>
            </div>
            <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">
              Provider timeouts from Semaphore & UniSMS are retried in the background every 5 minutes. Last checked: {lastRefreshed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={() => fetchQueue(true)}
            className="inline-flex items-center gap-2 rounded-xl border border-[#e5e5e5] bg-white px-4 py-2 text-[12px] font-bold text-[#6e6e73] shadow-sm transition-all hover:bg-[#f7f7f7] hover:text-[#111111] dark:border-white/10 dark:bg-[#1a1b1e] dark:text-[#9aa0a6] dark:hover:bg-white/5 dark:hover:text-white"
          >
            <FiRefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh Queue
          </button>
        </div>
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-[12px] font-semibold text-red-600 dark:border-red-900/20 dark:bg-red-900/10 dark:text-red-400">
          <FiAlertCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      {/* Filter and search controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setStatusFilter('active')}
            className={`px-3 py-1.5 rounded-xl text-[12px] font-bold transition-all ${
              statusFilter === 'active'
                ? 'bg-[#2b83fa] text-white shadow-sm'
                : 'bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7]'
            }`}
          >
            Active ({activeItems.length})
          </button>
          <button
            type="button"
            onClick={() => setStatusFilter('all')}
            className={`px-3 py-1.5 rounded-xl text-[12px] font-bold transition-all ${
              statusFilter === 'all'
                ? 'bg-[#2b83fa] text-white shadow-sm'
                : 'bg-white dark:bg-[#1a1b1e] border border-[#e5e5e5] dark:border-white/5 text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7]'
            }`}
          >
            All Records ({items.length})
          </button>
        </div>

        <label className="relative block w-full sm:w-[280px]">
          <FiSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#9aa0a6]" />
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search queue by phone, sender, ID..."
            className="h-9 w-full rounded-xl border border-[#e5e5e5] bg-white pl-9 pr-3 text-[12px] font-semibold text-[#111111] outline-none transition focus:border-[#2b83fa]/40 focus:ring-2 focus:ring-[#2b83fa]/10 dark:border-white/5 dark:bg-[#1a1b1e] dark:text-white"
          />
        </label>
      </div>

      {/* Queue Table */}
      <div className="overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white shadow-sm dark:border-white/5 dark:bg-[#1a1b1e]">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[880px] table-fixed border-collapse">
            <thead className="bg-[#f7f7f7] dark:bg-[#0d0e10]">
              <tr className="border-b border-[#e5e5e5] dark:border-white/5">
                <th className="w-[140px] px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Recipient</th>
                <th className="w-[120px] px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Sender ID</th>
                <th className="w-[110px] px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Provider</th>
                <th className="w-[120px] px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Status</th>
                <th className="w-[90px] px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Attempts</th>
                <th className="w-[130px] px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Next Retry</th>
                <th className="px-4 py-3 text-left text-[10px] font-black uppercase tracking-wider text-[#6e6e73] dark:text-[#9aa0a6]">Last Error / Detail</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#e5e5e5] dark:divide-white/5">
              {loading && items.length === 0 ? (
                [...Array(3)].map((_, i) => (
                  <tr key={i}>
                    <td colSpan={7} className="px-4 py-3">
                      <div className="h-8 rounded-lg bg-[#f7f7f7] dark:bg-[#0d0e10] animate-pulse" />
                    </td>
                  </tr>
                ))
              ) : filteredItems.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-12 text-center text-[13px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <FiCheckCircle className="h-8 w-8 text-emerald-500 opacity-80" />
                      <span>{statusFilter === 'active' ? 'No active messages in retry queue.' : 'No retry queue records found.'}</span>
                    </div>
                  </td>
                </tr>
              ) : (
                filteredItems.map((item) => {
                  const nextRetryDate = parseDate(item.next_retry_at);
                  const isProcessing = item.status === 'processing';
                  const isExhausted = item.status === 'exhausted';
                  const isCompleted = item.status === 'completed';

                  return (
                    <tr key={item.retry_doc_id || item.message_id} className="transition-colors hover:bg-[#f7f7f7] dark:hover:bg-white/[0.03]">
                      {/* Recipient Phone */}
                      <td className="px-4 py-3 align-middle font-mono text-[12px] font-bold text-[#111111] dark:text-white">
                        {item.phone || '-'}
                      </td>

                      {/* Sender ID */}
                      <td className="px-4 py-3 align-middle text-[12px] font-semibold text-[#111111] dark:text-white">
                        {item.sender_id || 'System'}
                      </td>

                      {/* Provider */}
                      <td className="px-4 py-3 align-middle">
                        <span className="inline-flex rounded-lg bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wider text-[#344054] dark:text-[#e4e7ec]">
                          {item.provider || 'default'}
                        </span>
                      </td>

                      {/* Status */}
                      <td className="px-4 py-3 align-middle">
                        {isProcessing ? (
                          <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-[10px] font-black uppercase text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                            <FiRefreshCw className="h-3 w-3 animate-spin" />
                            Processing
                          </span>
                        ) : isExhausted ? (
                          <span className="inline-flex items-center gap-1.5 rounded-full border border-red-200 bg-red-50 px-2.5 py-0.5 text-[10px] font-black uppercase text-red-600 dark:border-red-900/30 dark:bg-red-900/10 dark:text-red-400">
                            Exhausted
                          </span>
                        ) : isCompleted ? (
                          <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-[10px] font-black uppercase text-emerald-600 dark:border-emerald-900/30 dark:bg-emerald-900/10 dark:text-emerald-400">
                            Completed
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-[10px] font-black uppercase text-amber-600 dark:border-amber-900/30 dark:bg-amber-900/10 dark:text-amber-400">
                            <FiClock className="h-3 w-3" />
                            Queued
                          </span>
                        )}
                      </td>

                      {/* Attempts */}
                      <td className="px-4 py-3 align-middle text-[12px] font-bold text-[#111111] dark:text-white">
                        {item.attempts ?? 0} / {item.max_attempts ?? 3}
                      </td>

                      {/* Next Retry */}
                      <td className="px-4 py-3 align-middle text-[11px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6]">
                        {formatCountdown(nextRetryDate)}
                        {nextRetryDate && (
                          <div className="text-[10px] opacity-75 font-mono">
                            {nextRetryDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                          </div>
                        )}
                      </td>

                      {/* Error & ID */}
                      <td className="px-4 py-3 align-middle text-[12px] text-[#6e6e73] dark:text-[#9aa0a6]">
                        <div className="truncate font-medium text-red-600 dark:text-red-400" title={item.last_error || 'Timeout during dispatch'}>
                          {item.last_error || 'Provider timeout recorded; awaiting retry.'}
                        </div>
                        <div className="flex items-center gap-2 mt-0.5">
                          <span className="font-mono text-[10px] text-[#98a2b3]">ID: {item.message_id}</span>
                          <button
                            type="button"
                            onClick={() => copyToClipboard(item.message_id, item.message_id)}
                            className="text-[#98a2b3] hover:text-[#111111] dark:hover:text-white transition-colors"
                            title="Copy message ID"
                          >
                            {copiedId === item.message_id ? <FiCheck className="h-3 w-3 text-emerald-500" /> : <FiCopy className="h-3 w-3" />}
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};
