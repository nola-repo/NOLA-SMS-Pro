import { useState } from "react";
import { FiPlus, FiX, FiCheck } from "react-icons/fi";
import { addSenderId, type StoredSenderId } from "../utils/settingsStorage";

interface SenderRequestModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSuccess?: (newSender: StoredSenderId) => void;
}

const SENDER_COLORS = [
    "bg-blue-500", "bg-purple-500", "bg-orange-500",
    "bg-emerald-500", "bg-rose-500", "bg-amber-500", "bg-indigo-500", "bg-cyan-500",
];

export const SenderRequestModal: React.FC<SenderRequestModalProps> = ({ isOpen, onClose, onSuccess }) => {
    const [newId, setNewId] = useState("");
    const [newPurpose, setNewPurpose] = useState("");
    const [newSample, setNewSample] = useState("");
    const [isSubmitted, setIsSubmitted] = useState(false);

    if (!isOpen) return null;

    const handleAdd = (e: React.FormEvent) => {
        e.preventDefault();
        if (!newId.trim()) return;

        const fullDesc = `Purpose: ${newPurpose} | Sample: ${newSample}`;
        // Note: In real app, color index might need better management
        const created = addSenderId(newId, fullDesc, SENDER_COLORS[Math.floor(Math.random() * SENDER_COLORS.length)]);

        if (onSuccess) onSuccess(created);
        setIsSubmitted(true);

        setTimeout(() => {
            setNewId("");
            setNewPurpose("");
            setNewSample("");
            setIsSubmitted(false);
            onClose();
        }, 3000);
    };

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm animate-in fade-in duration-300" onClick={onClose} />
            <div className="relative w-full max-w-md bg-white dark:bg-[#18191d] rounded-2xl shadow-2xl p-6 animate-in zoom-in-95 duration-200 overflow-hidden">
                <div className="flex items-center justify-between mb-5">
                    <div className="flex items-center gap-2.5">
                        <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 flex items-center justify-center text-[#2b83fa]">
                            <FiPlus />
                        </div>
                        <h3 className="text-[17px] font-bold text-[#111111] dark:text-[#ececf1]">Add a Sender Name</h3>
                    </div>
                    <button onClick={onClose} className="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 text-gray-500 transition-colors">
                        <FiX />
                    </button>
                </div>

                {isSubmitted ? (
                    <div className="py-8 flex flex-col items-center text-center animate-in fade-in zoom-in-95">
                        <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-500 mb-4">
                            <FiCheck className="w-8 h-8" />
                        </div>
                        <h4 className="text-[18px] font-bold text-[#111111] dark:text-[#ececf1] mb-2">Request Submitted</h4>
                        <p className="text-[14px] text-[#6e6e73] dark:text-[#94959b] max-w-xs leading-relaxed">
                            Your sender name has been submitted and will be processed within 5 business days. For the requested Sender Names, credits will only be deducted upon approval.
                        </p>
                    </div>
                ) : (
                    <form onSubmit={handleAdd} className="space-y-4">
                        <div>
                            <label className="block text-[12px] font-semibold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Sender Name</label>
                            <input
                                autoFocus
                                value={newId}
                                onChange={e => setNewId(e.target.value.toUpperCase())}
                                placeholder="ex. NOLACRM"
                                maxLength={11}
                                required
                                className="w-full px-4 py-2.5 rounded-xl text-[14px] font-bold border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/25"
                            />
                            <p className="text-[11px] text-[#9aa0a6] mt-1">Max 11 characters. Alphanumeric only.</p>
                        </div>
                        <div>
                            <label className="block text-[12px] font-semibold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Purpose</label>
                            <textarea
                                value={newPurpose}
                                onChange={e => setNewPurpose(e.target.value)}
                                placeholder="What will you be using the sender name for?"
                                required
                                rows={3}
                                className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/25 resize-none"
                            />
                            <p className="text-[11px] text-[#9aa0a6] mt-1">If your Sender Name does not clearly reflect your business name or brand it will not be approved. Please be specific.</p>
                        </div>
                        <div>
                            <label className="block text-[12px] font-semibold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider mb-1.5">Sample Message</label>
                            <textarea
                                value={newSample}
                                onChange={e => setNewSample(e.target.value)}
                                placeholder="Please provide a specific example that accurately reflects the type of messages you will send."
                                required
                                rows={3}
                                className="w-full px-4 py-2.5 rounded-xl text-[14px] border bg-[#f7f7f7] dark:bg-[#0d0e10] border-[#e0e0e0] dark:border-[#ffffff0a] text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/25 resize-none"
                            />
                        </div>
                        <div className="flex flex-col-reverse sm:flex-row gap-3 pt-2">
                            <button type="button" onClick={onClose} className="flex-1 py-2.5 text-[13px] font-semibold text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors">Cancel</button>
                            <button type="submit" className="flex-1 flex items-center justify-center gap-2 py-2.5 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-xl font-semibold text-[13px] transition-all shadow-md shadow-blue-500/20">
                                Submit Request
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
};
