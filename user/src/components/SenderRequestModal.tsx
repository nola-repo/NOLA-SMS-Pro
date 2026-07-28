import { devLog } from '../utils/devLog';
import { useState, useEffect, useRef, useCallback } from "react";
import { createPortal } from "react-dom";
import { FiPlus, FiX, FiCheck, FiLoader, FiAlertCircle, FiUpload, FiFile, FiTrash2 } from "react-icons/fi";
import { submitSenderRequest } from "../api/senderRequests";
import type { StoredSenderId } from "../utils/settingsStorage";

interface SenderRequestModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSuccess?: (newSender: StoredSenderId) => void;
}

interface AttachedFile {
    name: string;
    size: number;
    type: string;
    dataUrl: string; // base64 data URL
}

const SENDER_COLORS = [
    "bg-blue-500", "bg-purple-500", "bg-orange-500",
    "bg-emerald-500", "bg-rose-500", "bg-amber-500", "bg-indigo-500", "bg-cyan-500",
];

const DEFAULT_REQUEST_PROVIDER = "unisms";

const MAX_FILE_SIZE_MB = 1;
const MAX_TOTAL_BYTES = 700 * 1024; // 700 KB max total for Firestore document payload
const MAX_FILES = 3;
const ACCEPTED_TYPES = [
    "image/jpeg", "image/png", "image/webp", "image/gif",
    "application/pdf",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
];
const ACCEPTED_EXTENSIONS = ".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx";

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function fileIcon(type: string): string {
    if (type.startsWith("image/")) return "🖼️";
    if (type === "application/pdf") return "📄";
    return "📎";
}

function readFileAsDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = () => reject(new Error("Failed to read file."));
        reader.readAsDataURL(file);
    });
}

export const SenderRequestModal: React.FC<SenderRequestModalProps> = ({ isOpen, onClose, onSuccess }) => {
    const [newId, setNewId] = useState("");
    const [newPurpose, setNewPurpose] = useState("");
    const [newSample, setNewSample] = useState("");
    const [attachedFiles, setAttachedFiles] = useState<AttachedFile[]>([]);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isSubmitted, setIsSubmitted] = useState(false);
    const [countdown, setCountdown] = useState(3);
    const [error, setError] = useState<string | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const dropZoneRef = useRef<HTMLDivElement>(null);

    const normalizedSenderName = newId.trim();

    useEffect(() => {
        let timer: NodeJS.Timeout;
        if (isSubmitted) {
            setCountdown(3);
            timer = setInterval(() => {
                setCountdown((prev) => Math.max(0, prev - 1));
            }, 1000);
        }
        return () => {
            if (timer) clearInterval(timer);
        };
    }, [isSubmitted]);

    // Reset state when modal closes
    useEffect(() => {
        if (!isOpen) {
            setNewId("");
            setNewPurpose("");
            setNewSample("");
            setAttachedFiles([]);
            setError(null);
            setFileError(null);
            setIsSubmitted(false);
            setIsDragging(false);
        }
    }, [isOpen]);

    const processFiles = useCallback(async (rawFiles: FileList | File[]) => {
        setFileError(null);
        const fileArray = Array.from(rawFiles);
        const remaining = MAX_FILES - attachedFiles.length;
        if (remaining <= 0) {
            setFileError(`You can only attach up to ${MAX_FILES} files.`);
            return;
        }
        const toProcess = fileArray.slice(0, remaining);
        const newAttachments: AttachedFile[] = [];

        let currentTotal = attachedFiles.reduce((acc, f) => acc + f.size, 0);

        for (const file of toProcess) {
            if (!ACCEPTED_TYPES.includes(file.type)) {
                setFileError(`"${file.name}" is not a supported file type. Use JPG, PNG, PDF, or DOCX.`);
                continue;
            }
            if (file.size > MAX_FILE_SIZE_MB * 1024 * 1024) {
                setFileError(`"${file.name}" exceeds the ${MAX_FILE_SIZE_MB}MB limit per file.`);
                continue;
            }
            if (currentTotal + file.size > MAX_TOTAL_BYTES) {
                setFileError(`Total size of attached files cannot exceed 700 KB.`);
                continue;
            }
            // Check for duplicate name
            const alreadyAttached = attachedFiles.some(f => f.name === file.name && f.size === file.size);
            if (alreadyAttached) continue;

            try {
                const dataUrl = await readFileAsDataUrl(file);
                newAttachments.push({ name: file.name, size: file.size, type: file.type, dataUrl });
                currentTotal += file.size;
            } catch {
                setFileError(`Failed to read "${file.name}". Please try again.`);
            }
        }

        if (newAttachments.length > 0) {
            setAttachedFiles(prev => [...prev, ...newAttachments].slice(0, MAX_FILES));
        }
    }, [attachedFiles]);

    const handleFileInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files.length > 0) {
            void processFiles(e.target.files);
        }
        // Reset so the same file can be re-selected if removed
        e.target.value = "";
    };

    const handleDragOver = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = (e: React.DragEvent) => {
        if (!dropZoneRef.current?.contains(e.relatedTarget as Node)) {
            setIsDragging(false);
        }
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            void processFiles(e.dataTransfer.files);
        }
    };

    const removeFile = (index: number) => {
        setAttachedFiles(prev => prev.filter((_, i) => i !== index));
        setFileError(null);
    };

    if (!isOpen) return null;

    const handleAdd = async (e: React.FormEvent) => {
        e.preventDefault();
        const trimmedId = normalizedSenderName;
        if (!trimmedId || isSubmitting) return;

        if (trimmedId.length < 3 || trimmedId.length > 11) {
            setError("Sender name must be between 3 and 11 characters.");
            return;
        }

        if (!/^[a-zA-Z0-9]+$/.test(trimmedId)) {
            setError("Sender name can only contain letters and numbers.");
            return;
        }

        if (!newPurpose.trim()) {
            setError("Business purpose is required.");
            return;
        }

        if (!newSample.trim()) {
            setError("Sample message is required.");
            return;
        }

        setIsSubmitting(true);
        setError(null);

        try {
            await submitSenderRequest(
                trimmedId,
                newPurpose.trim(),
                newSample.trim(),
                DEFAULT_REQUEST_PROVIDER,
                attachedFiles.length > 0 ? attachedFiles.map(f => ({
                    name: f.name,
                    size: f.size,
                    type: f.type,
                    dataUrl: f.dataUrl,
                })) : undefined,
            );

            const created: StoredSenderId = {
                id: trimmedId,
                name: trimmedId,
                description: newPurpose.trim(),
                color: SENDER_COLORS[Math.floor(Math.random() * SENDER_COLORS.length)],
                status: "pending",
                provider: DEFAULT_REQUEST_PROVIDER,
            };

            if (onSuccess) onSuccess(created);
            setIsSubmitted(true);

            setTimeout(() => {
                setNewId("");
                setNewPurpose("");
                setNewSample("");
                setAttachedFiles([]);
                setIsSubmitted(false);
                onClose();
            }, 3000);
        } catch (err) {
            devLog.error("[SenderRequestModal] Submit error:", err);
            setError(err instanceof Error ? err.message : "Failed to submit request. Please try again.");
        } finally {
            setIsSubmitting(false);
        }
    };

    return createPortal(
        <div className="fixed inset-0 z-[100] grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm animate-in fade-in duration-300" onClick={onClose} />
            <div className="relative w-full max-w-md bg-white dark:bg-[#18191d] rounded-2xl shadow-2xl animate-in zoom-in-95 duration-200 overflow-hidden flex flex-col max-h-[92vh]">

                {/* Header */}
                <div className="flex items-start justify-between p-6 pb-4 flex-shrink-0">
                    <div className="flex items-center gap-2.5">
                        <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 flex items-center justify-center text-[#2b83fa]">
                            <FiPlus />
                        </div>
                        <div>
                            <h3 className="text-[17px] font-bold text-[#111111] dark:text-[#ececf1]">Add a Sender Name</h3>
                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-0.5">
                                Request a branded SMS sender name for your account.
                            </p>
                        </div>
                    </div>
                    <button onClick={onClose} className="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 text-gray-500 transition-colors flex-shrink-0">
                        <FiX />
                    </button>
                </div>

                {/* Scrollable body */}
                <div className="overflow-y-auto flex-1 px-6 pb-6 custom-scrollbar">
                    {isSubmitted ? (
                        <div className="py-8 flex flex-col items-center text-center animate-in fade-in zoom-in-95">
                            <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-500 mb-4">
                                <FiCheck className="w-8 h-8" />
                            </div>
                            <h4 className="text-[18px] font-bold text-[#111111] dark:text-[#ececf1] mb-2">Request Submitted</h4>
                            <p className="text-[14px] text-[#6e6e73] dark:text-[#94959b] max-w-xs leading-relaxed">
                                Your sender name has been submitted for review.
                            </p>
                            <p className="text-[13px] text-gray-500 dark:text-gray-400 mt-6 font-medium bg-gray-50 dark:bg-white/5 py-1.5 px-4 rounded-full">
                                Auto-closing in {countdown}s...
                            </p>
                        </div>
                    ) : (
                        <form onSubmit={handleAdd} className="space-y-4">
                            {error && (
                                <div className="flex items-start gap-2 p-3 rounded-xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/20">
                                    <FiAlertCircle className="w-4 h-4 mt-0.5 text-red-600 dark:text-red-400 flex-shrink-0" />
                                    <p className="text-[12px] text-red-600 dark:text-red-400 font-medium">{error}</p>
                                </div>
                            )}

                            {/* Sender Name */}
                            <div>
                                <label className="block text-[11px] font-black text-[#6e6e73] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">
                                    Sender Name <span className="text-red-500">*</span>
                                </label>
                                <p className="mt-1.5 text-[11px] text-[#9aa0a6]">Use 3-11 letters or numbers only. No spaces or symbols.</p>
                                <input
                                    autoFocus
                                    value={newId}
                                    onChange={e => {
                                        setError(null);
                                        setNewId(e.target.value.replace(/[^a-zA-Z0-9]/g, ''));
                                    }}
                                    placeholder="ex. NOLASMS"
                                    maxLength={11}
                                    required
                                    aria-required="true"
                                    disabled={isSubmitting}
                                    className="w-full px-4 py-3 rounded-xl text-[14px] font-bold border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/25 disabled:opacity-50 mt-2"
                                />
                            </div>

                            {/* Business Purpose */}
                            <div>
                                <label className="block text-[11px] font-black text-[#6e6e73] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">
                                    Business Purpose <span className="text-red-500">*</span>
                                </label>
                                <p className="mt-1.5 text-[11px] text-[#9aa0a6]">Briefly describe the use case, such as reminders, promos, or updates.</p>
                                <textarea
                                    value={newPurpose}
                                    onChange={e => {
                                        setError(null);
                                        setNewPurpose(e.target.value);
                                    }}
                                    placeholder="What will you be using this for?"
                                    required
                                    aria-required="true"
                                    rows={2}
                                    disabled={isSubmitting}
                                    className="w-full px-4 py-3 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/25 resize-none disabled:opacity-50 mt-2"
                                />
                            </div>

                            {/* Sample Message */}
                            <div>
                                <label className="block text-[11px] font-black text-[#6e6e73] dark:text-[#9aa0a6] uppercase tracking-wider mb-2">
                                    Sample Message <span className="text-red-500">*</span>
                                </label>
                                <p className="mt-1.5 text-[11px] text-[#9aa0a6]">Add one real example your customers may receive.</p>
                                <textarea
                                    value={newSample}
                                    onChange={e => {
                                        setError(null);
                                        setNewSample(e.target.value);
                                    }}
                                    placeholder="Provide a specific message template example."
                                    required
                                    aria-required="true"
                                    rows={2}
                                    disabled={isSubmitting}
                                    className="w-full px-4 py-3 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/25 resize-none disabled:opacity-50 mt-2"
                                />
                            </div>

                            {/* ── Supporting Documents ────────────────────────── */}
                            <div>
                                <label className="block text-[11px] font-black text-[#6e6e73] dark:text-[#9aa0a6] uppercase tracking-wider mb-1">
                                    Supporting Documents
                                    <span className="ml-1.5 text-[10px] font-semibold text-[#9aa0a6] normal-case tracking-normal">(Optional)</span>
                                </label>
                                <p className="text-[11px] text-[#9aa0a6] mb-2.5">
                                    Attach up to {MAX_FILES} files (JPG, PNG, PDF, DOCX) — max {MAX_FILE_SIZE_MB}MB each. E.g. business permit, DTI registration, or brand logo.
                                </p>

                                {/* Drop zone */}
                                {attachedFiles.length < MAX_FILES && (
                                    <div
                                        ref={dropZoneRef}
                                        onDragOver={handleDragOver}
                                        onDragLeave={handleDragLeave}
                                        onDrop={handleDrop}
                                        onClick={() => !isSubmitting && fileInputRef.current?.click()}
                                        role="button"
                                        tabIndex={0}
                                        onKeyDown={e => e.key === 'Enter' && !isSubmitting && fileInputRef.current?.click()}
                                        aria-label="Upload supporting documents"
                                        className={`
                                            relative flex flex-col items-center justify-center gap-2 px-4 py-5 rounded-xl border-2 border-dashed transition-all cursor-pointer
                                            ${isDragging
                                                ? "border-[#2b83fa] bg-[#2b83fa]/5 scale-[1.01]"
                                                : "border-[#e0e0e0] dark:border-[#ffffff15] hover:border-[#2b83fa]/60 hover:bg-[#2b83fa]/3 dark:hover:bg-[#2b83fa]/5"
                                            }
                                            ${isSubmitting ? "opacity-50 cursor-not-allowed" : ""}
                                        `}
                                    >
                                        <div className={`w-9 h-9 rounded-xl flex items-center justify-center transition-colors ${isDragging ? "bg-[#2b83fa]/15 text-[#2b83fa]" : "bg-[#f0f0f0] dark:bg-white/5 text-[#9aa0a6]"}`}>
                                            <FiUpload className="w-4 h-4" />
                                        </div>
                                        <div className="text-center">
                                            <p className="text-[13px] font-semibold text-[#111111] dark:text-[#ececf1]">
                                                {isDragging ? "Drop files here" : "Click or drag files here"}
                                            </p>
                                            <p className="text-[11px] text-[#9aa0a6] mt-0.5">
                                                {MAX_FILES - attachedFiles.length} slot{MAX_FILES - attachedFiles.length !== 1 ? "s" : ""} remaining
                                            </p>
                                        </div>
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            multiple
                                            accept={ACCEPTED_EXTENSIONS}
                                            onChange={handleFileInputChange}
                                            disabled={isSubmitting}
                                            className="sr-only"
                                            aria-hidden="true"
                                            id="sender-doc-upload"
                                        />
                                    </div>
                                )}

                                {/* File error */}
                                {fileError && (
                                    <div className="flex items-start gap-2 mt-2 p-2.5 rounded-lg bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-800/20">
                                        <FiAlertCircle className="w-3.5 h-3.5 mt-0.5 text-amber-600 dark:text-amber-400 flex-shrink-0" />
                                        <p className="text-[11px] text-amber-700 dark:text-amber-400 font-medium">{fileError}</p>
                                    </div>
                                )}

                                {/* Attached file list */}
                                {attachedFiles.length > 0 && (
                                    <ul className="mt-2.5 space-y-1.5">
                                        {attachedFiles.map((file, idx) => (
                                            <li
                                                key={`${file.name}-${idx}`}
                                                className="flex items-center gap-2.5 p-2.5 rounded-xl bg-[#f7f7f7] dark:bg-[#0d0e10] border border-[#e5e5e5] dark:border-white/5 group"
                                            >
                                                <span className="text-[18px] flex-shrink-0 select-none">{fileIcon(file.type)}</span>
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-[12px] font-semibold text-[#111111] dark:text-[#ececf1] truncate leading-tight">{file.name}</p>
                                                    <p className="text-[10px] text-[#9aa0a6] mt-0.5">{formatBytes(file.size)}</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => removeFile(idx)}
                                                    disabled={isSubmitting}
                                                    title="Remove file"
                                                    className="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity p-1.5 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 disabled:opacity-30"
                                                    aria-label={`Remove ${file.name}`}
                                                >
                                                    <FiTrash2 className="w-3.5 h-3.5" />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {/* Slot indicator when full */}
                                {attachedFiles.length >= MAX_FILES && (
                                    <div className="mt-2 flex items-center gap-1.5 text-[11px] text-[#9aa0a6]">
                                        <FiFile className="w-3.5 h-3.5" />
                                        <span>Maximum {MAX_FILES} files attached. Remove one to add another.</span>
                                    </div>
                                )}
                            </div>
                            {/* ──────────────────────────────────────────────── */}

                            <div className="p-3 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/20">
                                <p className="text-[11px] text-amber-700 dark:text-amber-400 leading-normal text-center font-medium">
                                    <strong>Note:</strong> You will receive an email when this request is received and when it is approved or needs changes. This usually takes 2-5 business days.
                                </p>
                            </div>

                            <button type="submit" disabled={isSubmitting} className="w-full flex items-center justify-center gap-2 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-bold text-[13px] transition-all shadow-md shadow-blue-500/20 disabled:opacity-70">
                                {isSubmitting ? (
                                    <>
                                        <FiLoader className="w-4 h-4 animate-spin" />
                                        Submitting...
                                    </>
                                ) : (
                                    "Submit Request"
                                )}
                            </button>
                        </form>
                    )}
                </div>
            </div>
        </div>,
        document.body
    );
};
