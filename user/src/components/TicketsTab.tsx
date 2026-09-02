import React, { useState, useRef } from "react";
import { FiAlertTriangle, FiCheckCircle, FiMessageSquare, FiSend, FiUploadCloud, FiX, FiPaperclip } from "react-icons/fi";
import { API_CONFIG } from "../config";
import { useLocationId } from "../context/LocationContext";
import { apiFetch } from "../utils/apiFetch";

const ISSUE_CATEGORIES = [
    { value: "technical", label: "Technical Issue" },
    { value: "billing", label: "Billing & Credits" },
    { value: "sender_id", label: "Sender ID / Registration" },
    { value: "deliverability", label: "SMS Deliverability Issue" },
    { value: "feature", label: "Feature Request" },
    { value: "other", label: "Other" },
] as const;

const PRIORITIES = [
    { value: "normal", label: "Normal" },
    { value: "high", label: "High" },
    { value: "urgent", label: "Urgent" },
] as const;

const asErrorMessage = (payload: unknown, fallback: string): string => {
    if (payload && typeof payload === "object") {
        const record = payload as Record<string, unknown>;
        if (typeof record.message === "string" && record.message.trim()) return record.message;
        if (typeof record.error === "string" && record.error.trim()) return record.error;
    }
    return fallback;
};

export const TicketsTab: React.FC = () => {
    const { locationId } = useLocationId();
    const [issueCategory, setIssueCategory] = useState<string>("technical");
    const [subject, setSubject] = useState("");
    const [message, setMessage] = useState("");
    const [priority, setPriority] = useState<typeof PRIORITIES[number]["value"]>("normal");
    const [attachment, setAttachment] = useState<File | null>(null);
    const [attachmentPreview, setAttachmentPreview] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [successMessage, setSuccessMessage] = useState("");
    const [errorMessage, setErrorMessage] = useState("");
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;

        if (file.size > 5 * 1024 * 1024) {
            setErrorMessage("Attachment size must be under 5MB.");
            return;
        }

        setErrorMessage("");
        setAttachment(file);

        if (file.type.startsWith("image/")) {
            const reader = new FileReader();
            reader.onloadend = () => {
                setAttachmentPreview(reader.result as string);
            };
            reader.readAsDataURL(file);
        } else {
            setAttachmentPreview(null);
        }
    };

    const removeAttachment = () => {
        setAttachment(null);
        setAttachmentPreview(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = "";
        }
    };

    const submitTicket = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const trimmedSubject = subject.trim();
        const trimmedMessage = message.trim();

        setSuccessMessage("");
        setErrorMessage("");

        if (!locationId) {
            setErrorMessage("GHL location is not available yet. Please wait for the app to finish loading.");
            return;
        }

        if (!trimmedSubject || !trimmedMessage) {
            setErrorMessage("Subject and message are required.");
            return;
        }

        setIsSubmitting(true);
        try {
            const categoryLabel = ISSUE_CATEGORIES.find(c => c.value === issueCategory)?.label || issueCategory;
            const fullSubject = `[${categoryLabel}] ${trimmedSubject}`;

            let base64Attachment = null;
            if (attachment) {
                base64Attachment = await new Promise<string>((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onloadend = () => resolve(reader.result as string);
                    reader.onerror = reject;
                    reader.readAsDataURL(attachment);
                }).catch(() => null);
            }

            const res = await apiFetch(API_CONFIG.tickets, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-GHL-Location-ID": locationId,
                },
                body: JSON.stringify({
                    subject: fullSubject,
                    message: trimmedMessage,
                    priority,
                    category: issueCategory,
                    attachment: base64Attachment,
                }),
            });

            const payload = await res.json().catch(() => null);
            if (!res.ok || (payload?.status && payload.status !== "success")) {
                throw new Error(asErrorMessage(payload, "Failed to submit support ticket."));
            }

            setSubject("");
            setMessage("");
            setPriority("normal");
            setIssueCategory("technical");
            removeAttachment();
            setSuccessMessage("Support ticket submitted successfully.");
            window.dispatchEvent(new Event("nola-notifications-refresh"));
        } catch (error) {
            setErrorMessage(error instanceof Error ? error.message : "Failed to submit support ticket.");
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <div className="h-full flex flex-col overflow-hidden bg-[#f3f4f6] dark:bg-[#09090b]">
            <div className="flex-shrink-0 border-b border-[#e5e5e5] bg-white/85 dark:border-white/10 dark:bg-[#151618]/85">
                <div className="max-w-5xl mx-auto px-4 py-5 md:px-6">
                    <div className="flex items-center gap-3 pr-12">
                        <div className="w-10 h-10 rounded-2xl bg-black/[0.04] dark:bg-white/[0.06] border border-black/[0.04] dark:border-white/[0.08] flex items-center justify-center text-[#111111] dark:text-white">
                            <FiMessageSquare className="h-5 w-5" />
                        </div>
                        <div className="min-w-0">
                            <h1 className="text-[22px] font-extrabold text-[#111111] dark:text-white tracking-tight">Support Tickets</h1>
                            <p className="text-[12px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">Send a request to the NOLA support team</p>
                        </div>
                    </div>
                </div>
            </div>

            <main className="flex-1 overflow-y-auto px-4 pb-4 pt-5 md:px-6 md:pb-6 md:pt-6 lg:px-8 lg:pb-8 lg:pt-6">
                <div className="max-w-3xl mx-auto">
                    <form onSubmit={submitTicket} className="rounded-2xl border border-[#e5e5e5] bg-white p-5 shadow-sm dark:border-white/5 dark:bg-[#1a1b1e] md:p-6">
                        <div className="grid gap-5">
                            {/* Issue Category */}
                            <label className="grid gap-2">
                                <span className="text-[12px] font-bold uppercase tracking-wide text-[#6e6e73] dark:text-[#9aa0a6]">Issue Category *</span>
                                <select
                                    value={issueCategory}
                                    onChange={(event) => setIssueCategory(event.target.value)}
                                    className="h-11 rounded-xl border border-[#d8d8dc] bg-white px-3 text-[14px] font-medium text-[#111111] outline-none transition focus:border-[#2b83fa] focus:ring-4 focus:ring-[#2b83fa]/10 dark:border-white/10 dark:bg-[#111214] dark:text-white"
                                    disabled={isSubmitting}
                                >
                                    {ISSUE_CATEGORIES.map((item) => (
                                        <option key={item.value} value={item.value}>{item.label}</option>
                                    ))}
                                </select>
                            </label>

                            {/* Subject */}
                            <label className="grid gap-2">
                                <span className="text-[12px] font-bold uppercase tracking-wide text-[#6e6e73] dark:text-[#9aa0a6]">Subject *</span>
                                <input
                                    value={subject}
                                    onChange={(event) => setSubject(event.target.value)}
                                    className="h-11 rounded-xl border border-[#d8d8dc] bg-white px-3 text-[14px] font-medium text-[#111111] outline-none transition focus:border-[#2b83fa] focus:ring-4 focus:ring-[#2b83fa]/10 dark:border-white/10 dark:bg-[#111214] dark:text-white"
                                    placeholder="Brief summary of your issue"
                                    maxLength={140}
                                    disabled={isSubmitting}
                                />
                            </label>

                            {/* Priority */}
                            <label className="grid gap-2">
                                <span className="text-[12px] font-bold uppercase tracking-wide text-[#6e6e73] dark:text-[#9aa0a6]">Priority</span>
                                <select
                                    value={priority}
                                    onChange={(event) => setPriority(event.target.value as typeof PRIORITIES[number]["value"])}
                                    className="h-11 rounded-xl border border-[#d8d8dc] bg-white px-3 text-[14px] font-medium text-[#111111] outline-none transition focus:border-[#2b83fa] focus:ring-4 focus:ring-[#2b83fa]/10 dark:border-white/10 dark:bg-[#111214] dark:text-white"
                                    disabled={isSubmitting}
                                >
                                    {PRIORITIES.map((item) => (
                                        <option key={item.value} value={item.value}>{item.label}</option>
                                    ))}
                                </select>
                            </label>

                            {/* Issue Description */}
                            <label className="grid gap-2">
                                <span className="text-[12px] font-bold uppercase tracking-wide text-[#6e6e73] dark:text-[#9aa0a6]">Issue Description *</span>
                                <textarea
                                    value={message}
                                    onChange={(event) => setMessage(event.target.value)}
                                    className="min-h-[160px] resize-y rounded-xl border border-[#d8d8dc] bg-white px-3 py-3 text-[14px] font-medium leading-relaxed text-[#111111] outline-none transition focus:border-[#2b83fa] focus:ring-4 focus:ring-[#2b83fa]/10 dark:border-white/10 dark:bg-[#111214] dark:text-white"
                                    placeholder="Type details about your issue here..."
                                    maxLength={3000}
                                    disabled={isSubmitting}
                                />
                            </label>

                            {/* Attachment / Screenshot */}
                            <div className="grid gap-2">
                                <span className="text-[12px] font-bold uppercase tracking-wide text-[#6e6e73] dark:text-[#9aa0a6]">Attachment / Screenshot</span>
                                <input
                                    type="file"
                                    ref={fileInputRef}
                                    onChange={handleFileChange}
                                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.zip"
                                    className="hidden"
                                    disabled={isSubmitting}
                                />
                                {!attachment ? (
                                    <div
                                        onClick={() => fileInputRef.current?.click()}
                                        className="flex flex-col items-center justify-center p-6 border-2 border-dashed border-[#d8d8dc] dark:border-white/10 rounded-xl bg-[#fafafa] dark:bg-[#111214] hover:bg-[#f3f4f6] dark:hover:bg-[#16171a] cursor-pointer transition text-center"
                                    >
                                        <div className="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-[#2b83fa] flex items-center justify-center mb-2">
                                            <FiUploadCloud className="h-5 w-5" />
                                        </div>
                                        <p className="text-[13px] font-semibold text-[#111111] dark:text-white">Click to upload file or screenshot</p>
                                        <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">PNG, JPG, PDF, DOCX, XLSX (max 5MB)</p>
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-3 p-3 rounded-xl border border-[#d8d8dc] dark:border-white/10 bg-[#fafafa] dark:bg-[#111214]">
                                        {attachmentPreview ? (
                                            <img src={attachmentPreview} alt="Preview" className="w-12 h-12 object-cover rounded-lg border border-black/10 dark:border-white/10" />
                                        ) : (
                                            <div className="w-10 h-10 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-[#2b83fa] flex items-center justify-center">
                                                <FiPaperclip className="h-5 w-5" />
                                            </div>
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <p className="text-[13px] font-semibold text-[#111111] dark:text-white truncate">{attachment.name}</p>
                                            <p className="text-[11px] text-[#6e6e73] dark:text-[#9aa0a6]">{(attachment.size / 1024).toFixed(1)} KB</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={removeAttachment}
                                            className="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 transition"
                                            title="Remove attachment"
                                        >
                                            <FiX className="h-5 w-5" />
                                        </button>
                                    </div>
                                )}
                            </div>

                            {errorMessage && (
                                <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-[12.5px] font-semibold text-red-700 dark:border-red-900/40 dark:bg-red-950/20 dark:text-red-300">
                                    <FiAlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                                    <span>{errorMessage}</span>
                                </div>
                            )}

                            {successMessage && (
                                <div className="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[12.5px] font-semibold text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/20 dark:text-emerald-300">
                                    <FiCheckCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                                    <span>{successMessage}</span>
                                </div>
                            )}

                            <button
                                type="submit"
                                disabled={isSubmitting}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#2b83fa] px-4 text-[13px] font-bold text-white shadow-sm transition hover:bg-[#1d6bd4] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <FiSend className="h-4 w-4" />
                                {isSubmitting ? "Submitting..." : "Submit ticket"}
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    );
};

