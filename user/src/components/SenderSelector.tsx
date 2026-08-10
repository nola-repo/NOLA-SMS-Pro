import { useState, useRef, useEffect, useMemo } from "react";
import { FiChevronDown, FiCheck, FiGlobe, FiPlus, FiCheckCircle, FiLoader } from "react-icons/fi";
import { type SenderId } from "../api/sms";
import { SenderRequestModal } from "./SenderRequestModal";
import { fetchAccountSenderConfig, type AccountSenderConfig } from "../api/senderRequests";
import { useLocationId } from "../context/LocationContext";

interface SenderOption {
    id: SenderId;
    name: string;
    description: string;
    icon: React.ReactNode;
    color: string;
}

interface SenderSelectorProps {
    value: SenderId;
    onChange: (value: SenderId) => void;
    label?: string;
    size?: "sm" | "md";
    align?: "left" | "right";
    tone?: "default" | "onBlue";
    onRequestSettings?: () => void;
    approvedSenderId?: string;
}

// Static fallback while loading
const SYSTEM_DEFAULT: SenderOption = {
    id: "NOLASMSPro",
    name: "NOLASMSPro",
    description: "System Default (Free Tier)",
    icon: <FiGlobe />,
    color: "bg-blue-500",
};

export const SenderSelector: React.FC<SenderSelectorProps> = ({
    value,
    onChange,
    label = "From:",
    size = "md",
    align = "right",
    tone = "default",
    onRequestSettings,
    approvedSenderId
}) => {
    const { locationId } = useLocationId();
    const locationKey = locationId || "";
    const [isOpen, setIsOpen] = useState(false);
    const [isAdding, setIsAdding] = useState(false);
    const [configState, setConfigState] = useState<{
        config: AccountSenderConfig | null;
        loadedLocationKey: string | null;
    }>({ config: null, loadedLocationKey: null });
    const dropdownRef = useRef<HTMLDivElement>(null);
    const { config, loadedLocationKey } = configState;
    const configLoading = loadedLocationKey !== locationKey;

    // Fetch account sender config on mount and when location changes
    useEffect(() => {
        let cancelled = false;
        fetchAccountSenderConfig(locationId || undefined).then(cfg => {
            if (!cancelled) {
                setConfigState({ config: cfg, loadedLocationKey: locationKey });
            }
        });
        return () => { cancelled = true; };
    }, [locationId, locationKey]);

    // Build options dynamically from API config
    const allOptions = useMemo<SenderOption[]>(() => {
        const options: SenderOption[] = [];

        // NOLASMSPro is always the platform default. Approved custom senders are explicit per-send choices.
        const systemName = config?.system_default_sender || "NOLASMSPro";
        options.push({
            id: systemName,
            name: systemName,
            description: "Platform Default",
            icon: <FiGlobe />,
            color: "bg-blue-500",
        });

        // Approved sender (if any)
        const approvedId = approvedSenderId || config?.approved_sender_id;
        if (approvedId && approvedId !== systemName) {
            const providerLabel = config?.provider_preference?.startsWith("unisms")
                ? "UniSMS"
                : config?.provider_preference?.startsWith("semaphore")
                    ? "Semaphore"
                    : "Custom";
            options.push({
                id: approvedId,
                name: approvedId,
                description: `Your Approved Sender (${providerLabel})`,
                icon: <FiCheckCircle />,
                color: "bg-emerald-500",
            });
        }

        return options;
    }, [config, approvedSenderId]);

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        };
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    const selectedOption = allOptions.find(opt => opt.id === value) || allOptions[0] || SYSTEM_DEFAULT;
    const isOnBlue = tone === "onBlue";

    const handleSuccess = () => {
        // After a request is submitted, the modal will handle its own auto-close
        // The sender won't appear in the dropdown until admin approves it
        setIsOpen(false);
    };

    return (
        <div className="flex items-center gap-2" ref={dropdownRef}>
            {label && (
                <span className={`hidden sm:inline-block font-bold uppercase tracking-widest whitespace-nowrap ${isOnBlue ? "text-white/75" : "text-gray-400 dark:text-gray-500"} ${size === "sm" ? "text-[10px]" : "text-[11px]"}`}>
                    {label}
                </span>
            )}

            <div className="relative">
                {/* Trigger Button */}
                <button
                    onClick={() => setIsOpen(!isOpen)}
                    disabled={configLoading}
                    className={`
            flex items-center justify-between gap-2.5 font-bold transition-all duration-200
            border rounded-xl focus:outline-none focus:ring-2
            ${isOnBlue
                            ? "bg-white/[0.18] border-white/30 hover:bg-white/[0.24] hover:border-white/45 focus:ring-white/30"
                            : "bg-gray-50/50 dark:bg-white/5 border-gray-200/50 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/10 hover:border-gray-300 dark:hover:border-white/20 focus:ring-[#2b83fa]/20"}
            ${size === "sm" ? "px-2 py-1.5 text-[11px] uppercase tracking-wider" : "px-3 py-2 text-[13px]"}
            ${isOpen ? (isOnBlue ? "ring-2 ring-white/30 border-white/50" : "ring-2 ring-[#2b83fa]/20 border-[#2b83fa]/30") : ""}
          `}
                >
                    {configLoading ? (
                        <div className="flex items-center gap-2 min-w-0">
                            <div className={`flex-shrink-0 flex items-center justify-center rounded-lg ${size === "sm" ? "w-5 h-5" : "w-6 h-6"} ${isOnBlue ? "bg-white/20 text-white" : "bg-blue-500/20 text-[#2b83fa]"} animate-pulse`}>
                                <FiLoader className="animate-spin text-[11px]" />
                            </div>
                            <div className={`h-3.5 w-16 rounded-md animate-pulse ${isOnBlue ? "bg-white/30" : "bg-gray-300/60 dark:bg-white/20"}`} />
                        </div>
                    ) : (
                        <div className="flex items-center gap-2 min-w-0">
                            <div className={`flex-shrink-0 flex items-center justify-center rounded-lg text-white shadow-sm ${size === "sm" ? "w-5 h-5 text-[10px]" : "w-6 h-6 text-[12px]"} ${isOnBlue ? "bg-white/[0.20]" : selectedOption.color}`}>
                                {selectedOption.icon}
                            </div>
                            <span className={`${isOnBlue ? "text-white" : "text-[#37352f] dark:text-[#ececf1]"} truncate max-w-[80px] sm:max-w-[120px]`}>{selectedOption.name}</span>
                        </div>
                    )}
                    <FiChevronDown className={`flex-shrink-0 transition-transform duration-200 ${isOnBlue ? "text-white/75" : "text-gray-400"} ${isOpen ? (isOnBlue ? "rotate-180 text-white" : "rotate-180 text-[#2b83fa]") : ""}`} />
                </button>
            absolute top-full z-[60] mt-2 
            w-64 max-w-[calc(100vw-2rem)] p-1.5
            bg-white/95 dark:bg-[#1a1b1e]/95 backdrop-blur-2xl
            border border-gray-200/80 dark:border-white/10
            rounded-2xl shadow-2xl shadow-black/10 dark:shadow-black/40
            animate-in fade-in zoom-in-95 duration-200 
            ${align === "left" ? "left-0 origin-top-left" : "left-auto right-0 origin-top-right"}
          `}>
                        <div className="max-h-60 overflow-y-auto custom-scrollbar p-0.5">
                            {allOptions.map((option) => {
                                const isSelected = option.id === value;
                                return (
                                    <button
                                        key={option.id}
                                        onClick={() => {
                                            onChange(option.id);
                                            setIsOpen(false);
                                        }}
                                        className={`
                        w-full flex items-center gap-3 p-2 rounded-xl transition-all duration-150 group mb-0.5
                        ${isSelected
                                                ? "bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 text-[#2b83fa]"
                                                : "hover:bg-gray-100/80 dark:hover:bg-white/5 text-gray-700 dark:text-gray-300"}
                      `}
                                    >
                                        <div className={`flex items-center justify-center w-8 h-8 rounded-xl text-white shadow-sm flex-shrink-0 ${option.color} ${isSelected ? "ring-2 ring-white/20" : ""}`}>
                                            {option.icon}
                                        </div>
                                        <div className="flex-1 flex flex-col items-start min-w-0 text-left">
                                            <span className={`text-[13px] font-bold text-left truncate w-full ${isSelected ? "text-[#2b83fa]" : "group-hover:text-[#2b83fa]"}`}>
                                                {option.name}
                                            </span>
                                            <span className="text-[10px] font-medium text-gray-400 dark:text-gray-500 truncate w-full text-left">
                                                {option.description}
                                            </span>
                                        </div>
                                        {isSelected && (
                                            <FiCheck className="h-4 w-4 flex-shrink-0" />
                                        )}
                                    </button>
                                );
                            })}
                        </div>

                        <div className="mt-1.5 p-1 border-t border-gray-100 dark:border-white/5">
                            <button
                                onClick={() => {
                                    setIsOpen(false);
                                    setIsAdding(true);
                                    onRequestSettings?.();
                                }}
                                className="w-full flex items-center gap-3 p-2 rounded-xl hover:bg-[#2b83fa]/5 text-[#2b83fa] transition-all group"
                            >
                                <div className="flex items-center justify-center w-8 h-8 rounded-xl bg-[#2b83fa]/10 text-[#2b83fa] group-hover:bg-[#2b83fa] group-hover:text-white transition-all">
                                    <FiPlus className="h-4 w-4" />
                                </div>
                                <span className="text-[13px] font-bold">Request New Sender ID</span>
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* Shared Modal */}
            <SenderRequestModal
                isOpen={isAdding}
                onClose={() => setIsAdding(false)}
                onSuccess={handleSuccess}
            />
        </div>
    );
};
