import React from 'react';
import { FiX, FiCopy } from 'react-icons/fi';
import type { Message } from '../../types/Sms';

export type MessageDetailsSelection =
  | {
      kind: "message";
      message: Message;
      recipient?: string;
      conversationId?: string;
    }
  | {
      kind: "bulk";
      id: string;
      text: string;
      timestamp: Date;
      rows: Message[];
      stats: { sent: number; sending: number; failed: number; total: number };
      conversationId?: string;
    };

export type BulkConfirmationState = {
  messageText: string;
  totalCount: number;
  uniqueCount: number;
  duplicateCount: number;
  duplicatePhones: string[];
  segments: number;
  estimatedCredits: number;
};

export type BulkSendSummaryState = {
  total: number;
  sent: number;
  failed: number;
  skipped: number;
};

const DetailRow: React.FC<{ label: string; value?: string | number | null; mono?: boolean }> = ({ label, value, mono }) => {
  if (value === undefined || value === null || value === "") return null;

  return (
    <div className="rounded-xl border border-[#e5ebf3] dark:border-white/10 bg-[#f8fafc] dark:bg-white/[0.04] px-3 py-2">
      <div className="text-[10px] font-bold uppercase tracking-wide text-[#98a2b3] dark:text-[#7d8491] mb-1">{label}</div>
      <div className={`text-[13px] font-medium leading-snug text-[#344054] dark:text-[#e4e7ec] break-words ${mono ? "font-mono" : ""}`}>
        {value}
      </div>
    </div>
  );
};

const formatDetailsTimestamp = (date: Date) =>
  date.toLocaleString([], {
    weekday: "long",
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });

const stringifyDiagnostic = (value: unknown) => {
  if (!value) return "";
  if (typeof value === "string") return value;
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
};

export const BulkConfirmationModal: React.FC<{
  bulkConfirmation: BulkConfirmationState;
  onCancel: () => void;
  onConfirm: () => void;
}> = ({ bulkConfirmation, onCancel, onConfirm }) => (
  <div
    className="fixed inset-0 z-[9998] flex items-center justify-center bg-[#0f172a]/35 px-4 backdrop-blur-sm"
    onClick={onCancel}
    role="dialog"
    aria-modal="true"
    aria-label="Confirm bulk SMS"
  >
    <div
      className="w-full max-w-md rounded-[24px] border border-[#d8e1ec] bg-white p-5 text-left shadow-2xl dark:border-white/10 dark:bg-[#17191f]"
      onClick={(e) => e.stopPropagation()}
    >
      <div className="mb-4 flex items-start justify-between gap-4">
        <div>
          <div className="text-[11px] font-black uppercase tracking-widest text-[#2b83fa] dark:text-[#8bbcff]">Confirm send</div>
          <h3 className="mt-1 text-[18px] font-black text-[#101828] dark:text-white">Send bulk SMS?</h3>
        </div>
        <button
          type="button"
          onClick={onCancel}
          className="flex h-9 w-9 items-center justify-center rounded-full bg-[#f2f4f7] text-[#667085] transition-colors hover:bg-[#e4e9f0] hover:text-[#101828] dark:bg-white/[0.06] dark:text-[#a7adba] dark:hover:bg-white/[0.1] dark:hover:text-white"
          aria-label="Close bulk confirmation"
        >
          <FiX className="h-4 w-4" />
        </button>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <DetailRow label="Recipients" value={`${bulkConfirmation.uniqueCount}/${bulkConfirmation.totalCount}`} />
        <DetailRow label="Segments" value={bulkConfirmation.segments} />
        <DetailRow label="Est. credits" value={bulkConfirmation.estimatedCredits} />
        <DetailRow label="Skipped duplicates" value={bulkConfirmation.duplicateCount} />
      </div>
      {bulkConfirmation.duplicateCount > 0 && (
        <div className="mt-3 rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2 text-[12px] font-semibold text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
          Duplicate phone numbers will be skipped: {bulkConfirmation.duplicatePhones.slice(0, 4).join(", ")}{bulkConfirmation.duplicatePhones.length > 4 ? "..." : ""}
        </div>
      )}
      <div className="mt-4 rounded-2xl bg-[#f8fafc] p-3 text-[13px] font-medium text-[#344054] dark:bg-white/[0.04] dark:text-[#e4e7ec]">
        {bulkConfirmation.messageText}
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <button
          type="button"
          onClick={onCancel}
          className="rounded-xl px-4 py-2 text-[13px] font-bold text-[#667085] hover:bg-[#f2f4f7] dark:text-[#a7adba] dark:hover:bg-white/[0.06]"
        >
          Cancel
        </button>
        <button
          type="button"
          onClick={onConfirm}
          className="rounded-xl bg-[#2b83fa] px-5 py-2 text-[13px] font-black text-white shadow-sm hover:bg-[#1d6bd4]"
        >
          Confirm Send
        </button>
      </div>
    </div>
  </div>
);

export const BulkSendSummaryModal: React.FC<{
  bulkSendSummary: BulkSendSummaryState;
  onClose: () => void;
}> = ({ bulkSendSummary, onClose }) => (
  <div
    className="fixed inset-0 z-[9998] flex items-center justify-center bg-[#0f172a]/35 px-4 backdrop-blur-sm"
    onClick={onClose}
    role="dialog"
    aria-modal="true"
    aria-label="Bulk send summary"
  >
    <div
      className="w-full max-w-sm rounded-[24px] border border-[#d8e1ec] bg-white p-5 text-left shadow-2xl dark:border-white/10 dark:bg-[#17191f]"
      onClick={(e) => e.stopPropagation()}
    >
      <div className="mb-4">
        <div className="text-[11px] font-black uppercase tracking-widest text-[#2b83fa] dark:text-[#8bbcff]">Bulk complete</div>
        <h3 className="mt-1 text-[18px] font-black text-[#101828] dark:text-white">Send summary</h3>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <DetailRow label="Total" value={bulkSendSummary.total} />
        <DetailRow label="Sent" value={bulkSendSummary.sent} />
        <DetailRow label="Failed" value={bulkSendSummary.failed} />
        <DetailRow label="Skipped" value={bulkSendSummary.skipped} />
      </div>
      <div className="mt-5 flex justify-end">
        <button
          type="button"
          onClick={onClose}
          className="rounded-xl bg-[#2b83fa] px-5 py-2 text-[13px] font-black text-white shadow-sm hover:bg-[#1d6bd4]"
        >
          Done
        </button>
      </div>
    </div>
  </div>
);

export const MessageDetailsModal: React.FC<{
  messageDetails: MessageDetailsSelection;
  onClose: () => void;
  onCopy: (label: string, text: string) => void;
}> = ({ messageDetails, onClose, onCopy }) => {
  const messageDetailsText = messageDetails.kind === "bulk"
    ? messageDetails.text
    : messageDetails.message.text || messageDetails.message.message || "";
  const messageDetailsRecipient = messageDetails.kind === "message"
    ? messageDetails.recipient || messageDetails.message.number || ""
    : "";

  return (
    <div
      className="fixed inset-0 z-[9998] flex items-center justify-center bg-[#0f172a]/35 px-4 backdrop-blur-sm"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-label="Message details"
    >
      <div
        className="w-full max-w-lg rounded-[24px] border border-[#d8e1ec] dark:border-white/10 bg-white dark:bg-[#17191f] p-5 text-left shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-start justify-between gap-4">
          <div>
            <div className="text-[11px] font-bold uppercase tracking-widest text-[#2b83fa] dark:text-[#8bbcff]">
              Message details
            </div>
            <h3 className="mt-1 text-[18px] font-bold text-[#101828] dark:text-white">
              {messageDetails.kind === "bulk" ? "Bulk send event" : "Outbound message"}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="flex h-9 w-9 items-center justify-center rounded-full bg-[#f2f4f7] text-[#667085] transition-colors hover:bg-[#e4e9f0] hover:text-[#101828] dark:bg-white/[0.06] dark:text-[#a7adba] dark:hover:bg-white/[0.1] dark:hover:text-white"
            aria-label="Close message details"
          >
            <FiX className="h-4 w-4" />
          </button>
        </div>

        <div className="mb-4 rounded-2xl bg-gradient-to-br from-[#2b83fa] via-[#2563eb] to-[#1d4ed8] p-4 text-white shadow-lg shadow-blue-900/10">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 flex-1">
              <div className="mb-2 text-[10px] font-bold uppercase tracking-widest text-white/70">Message</div>
              <p className="whitespace-pre-wrap break-words text-[14px] font-medium leading-relaxed">
                {messageDetailsText}
              </p>
            </div>
            <button
              type="button"
              onClick={() => onCopy("Message", messageDetailsText)}
              className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/20 transition-colors hover:bg-white/25"
              title="Copy message"
              aria-label="Copy message"
            >
              <FiCopy className="h-4 w-4" />
            </button>
          </div>
        </div>

        {messageDetails.kind === "message" ? (
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <DetailRow label="SMS Status" value={messageDetails.message.status} />
            <DetailRow label="Sender" value={messageDetails.message.senderName} />
            <DetailRow label="Sent at" value={formatDetailsTimestamp(messageDetails.message.timestamp)} />
            {messageDetailsRecipient && (
              <div className="rounded-xl border border-[#e5ebf3] bg-[#f8fafc] px-3 py-2 dark:border-white/10 dark:bg-white/[0.04]">
                <div className="mb-1 flex items-center justify-between gap-2">
                  <div className="text-[10px] font-bold uppercase tracking-wide text-[#98a2b3] dark:text-[#7d8491]">Recipient</div>
                  <button
                    type="button"
                    onClick={() => onCopy("Phone number", messageDetailsRecipient)}
                    className="flex h-6 w-6 items-center justify-center rounded-full text-[#667085] transition-colors hover:bg-white hover:text-[#1d6bd4] dark:text-[#a7adba] dark:hover:bg-white/[0.08] dark:hover:text-[#8bbcff]"
                    title="Copy phone number"
                    aria-label="Copy phone number"
                  >
                    <FiCopy className="h-3.5 w-3.5" />
                  </button>
                </div>
                <div className="break-words font-mono text-[13px] font-medium leading-snug text-[#344054] dark:text-[#e4e7ec]">
                  {messageDetailsRecipient}
                </div>
              </div>
            )}
            <DetailRow label="Message ID" value={messageDetails.message.id} mono />
            <DetailRow label="Provider ID" value={messageDetails.message.providerMessageId} mono />
            <DetailRow label="Provider reference" value={messageDetails.message.providerReferenceId} mono />
            <DetailRow label="Provider status" value={messageDetails.message.providerStatus} />
            <DetailRow label="Conversation ID" value={messageDetails.conversationId} mono />
            <DetailRow label="Batch ID" value={messageDetails.message.batch_id} mono />
            <DetailRow label="Error code" value={messageDetails.message.errorCode} mono />
            <DetailRow label="Failure reason" value={messageDetails.message.errorReason || (messageDetails.message.status === "failed" ? "Provider rejected or did not confirm delivery." : undefined)} />
            
            {/* CRM Sync Details */}
            <DetailRow
              label="CRM Sync Status"
              value={(() => {
                const m = messageDetails.message;
                const isSuccess = m.ghlSyncSuccess ?? m.ghl_sync_success;
                const isSkipped = m.ghlSyncSkipped ?? m.ghl_sync_skipped;
                const isQueued = m.ghlSyncQueued ?? m.ghl_sync_queued ?? !!(m.ghlSyncJobId || m.ghl_sync_job_id);
                const error = m.ghlSyncError || m.ghl_sync_error;
                if (isSuccess === true) return "CRM synced";
                if (isSkipped === true) return `CRM sync skipped${(m.ghlSyncReason || m.ghl_sync_reason) ? ` (${m.ghlSyncReason || m.ghl_sync_reason})` : ''}`;
                if (error || isSuccess === false) return `CRM sync failed${error ? `: ${error}` : ''}`;
                if (isQueued) return "CRM sync pending";
                return undefined;
              })()}
            />
            <DetailRow label="GHL Sync Job ID" value={messageDetails.message.ghlSyncJobId || messageDetails.message.ghl_sync_job_id} mono />
            <DetailRow label="GHL Message ID" value={messageDetails.message.ghlMessageId || messageDetails.message.ghl_message_id} mono />
            <DetailRow label="GHL Sync Reason" value={messageDetails.message.ghlSyncReason || messageDetails.message.ghl_sync_reason} />
            <DetailRow label="GHL Sync Error" value={messageDetails.message.ghlSyncError || messageDetails.message.ghl_sync_error} />

            <DetailRow label="Provider response" value={stringifyDiagnostic(messageDetails.message.providerResponse)} mono />
          </div>
        ) : (
          <>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <DetailRow label="Total" value={messageDetails.stats.total} />
              <DetailRow label="Sent" value={messageDetails.stats.sent} />
              <DetailRow label="Sending" value={messageDetails.stats.sending} />
              <DetailRow label="Failed" value={messageDetails.stats.failed} />
            </div>
            <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
              <DetailRow label="Sent at" value={formatDetailsTimestamp(messageDetails.timestamp)} />
              <DetailRow label="Conversation ID" value={messageDetails.conversationId} mono />
              <DetailRow label="Event ID" value={messageDetails.id} mono />
              <DetailRow label="Batch ID" value={messageDetails.rows.find(row => row.batch_id)?.batch_id} mono />
            </div>
            <div className="mt-3 max-h-40 overflow-y-auto rounded-2xl border border-[#e5ebf3] dark:border-white/10 custom-scrollbar">
              {messageDetails.rows.map((row) => (
                <div key={row.id} className="flex items-center justify-between gap-3 border-b border-[#edf1f6] px-3 py-2 last:border-b-0 dark:border-white/[0.06]">
                  <div className="min-w-0">
                    <div className="truncate text-[12px] font-medium text-[#344054] dark:text-[#e4e7ec]">{row.senderName}</div>
                    <div className="truncate font-mono text-[10px] font-medium text-[#98a2b3] dark:text-[#7d8491]">{row.id}</div>
                    {row.providerMessageId && (
                      <div className="truncate font-mono text-[10px] text-[#98a2b3] dark:text-[#7d8491]">Provider: {row.providerMessageId}</div>
                    )}
                  </div>
                  <span className="rounded-full bg-[#eef6ff] px-2 py-1 text-[10px] font-black uppercase text-[#1d6bd4] dark:bg-white/[0.07] dark:text-[#8bbcff]">
                    {row.status}
                  </span>
                </div>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
};
