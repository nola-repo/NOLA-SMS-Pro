import { useState, useEffect } from "react";
import { FiHome, FiPlus, FiUsers, FiSettings, FiCreditCard, FiMessageSquare, FiArrowRight, FiClock, FiUser, FiX } from "react-icons/fi";
import type { Contact } from "../types/Contact";
import type { BulkMessageHistoryItem, Conversation } from "../types/Sms";
import { fetchConversations, type SenderId } from "../api/sms";
import { fetchContacts } from "../api/contacts";
import { fetchCreditBalance } from "../api/credits";
import SplitText from "./SplitText";
import AnimatedContent from "./AnimatedContent";
import FadeContent from "./FadeContent";
import { SenderSelector } from "./SenderSelector";
import { extractBatchIdFromGroupConversationId, extractPhoneFromDirectConversationId } from "../utils/conversationId";

interface HomeProps {
    onTabChange: (tab: any) => void;
    onSelectContact: (contact: Contact) => void;
    onSelectBulkMessage: (message: BulkMessageHistoryItem) => void;
}

export const Home: React.FC<HomeProps> = ({ onTabChange, onSelectContact, onSelectBulkMessage }) => {
    const [balance, setBalance] = useState<number | null>(null);
    const [conversations, setConversations] = useState<Conversation[]>([]);
    const [contacts, setContacts] = useState<Contact[]>([]);
    const [contactsCount, setContactsCount] = useState<number>(0);
    const [loading, setLoading] = useState(true);
    const [showAllActivity, setShowAllActivity] = useState(false);
    const [senderName, setSenderName] = useState<SenderId>("NOLASMSPro");

    useEffect(() => {
        const loadHomeData = async (isInitial = false) => {
            if (isInitial) setLoading(true);

            // Run all three fetches independently — one failure won't block the others
            const [credPrice, convs, contactsRes] = await Promise.allSettled([
                fetchCreditBalance(),
                fetchConversations().catch(() => []),
                fetchContacts().catch(() => []),
            ]);

            if (credPrice.status === 'fulfilled') {
                setBalance(credPrice.value);
            }

            if (convs.status === 'fulfilled') {
                const fetchedConvs = convs.value as Conversation[];
                setConversations(prev => {
                    // Sort the new conversations
                    const sortedNew = [...fetchedConvs].sort((a, b) => {
                        const timeA = new Date(a.last_message_at || a.updated_at || 0).getTime();
                        const timeB = new Date(b.last_message_at || b.updated_at || 0).getTime();
                        return timeB - timeA;
                    });

                    // If we have no previous conversations, just return the sorted ones
                    if (prev.length === 0) return sortedNew;

                    // If we have existing ones, React will diff them. 
                    // To ensure "smooth" updates and "add new on top" feel, 
                    // we'll just return the sorted ones, but React's reconciler 
                    // handles the DOM stability if IDs are the same.
                    return sortedNew;
                });
            }

            if (contactsRes.status === 'fulfilled') {
                const data = contactsRes.value as Contact[];
                setContacts(data);
                setContactsCount(data.length);
            }

            if (isInitial) setLoading(false);
        };

        loadHomeData(true);

        // Real-time polling: refresh dashboard data every 15 seconds
        const interval = setInterval(() => {
            loadHomeData(false);
        }, 15000);

        return () => clearInterval(interval);
    }, []);

    const getGreeting = () => {
        const hour = new Date().getHours();
        if (hour < 12) return "Good morning";
        if (hour < 18) return "Good afternoon";
        return "Good evening";
    };

    const toProperCase = (name: string): string => {
        return name.replace(/\b\w/g, (char) => char.toUpperCase());
    };

    const handleAnimationComplete = () => {
        console.log('All letters have animated!');
    };

    const isPhoneLike = (s: string): boolean => /^[\d+\-() ]+$/.test(s);

    const getDisplayName = (conv: Conversation): string => {
        const phone =
            extractPhoneFromDirectConversationId(conv.id) ||
            extractBatchIdFromGroupConversationId(conv.id) ||
            conv.id.replace(/^conv_/, '').replace(/^group_/, '');
        const cleanPhone = phone.replace(/\D/g, "");
        
        // Always try contacts first for direct conversations
        if (conv.type !== 'bulk') {
            const contact = contacts.find((c: Contact) => {
                const cp = c.phone.replace(/\D/g, "");
                return c.phone === phone || cp === cleanPhone
                    || (cleanPhone.startsWith('0') && cp === '+63' + cleanPhone.slice(1))
                    || (cleanPhone.startsWith('09') && cp === cleanPhone.slice(1));
            });
            if (contact) return toProperCase(contact.name);
        }

        // Use conv.name only if it's a real name (not a phone number)
        if (conv.name && !isPhoneLike(conv.name)) return toProperCase(conv.name);

        if (conv.type === 'bulk') {
            // For bulk, try resolving member names
            if (conv.members && conv.members.length > 0) {
                const memberNames = conv.members.slice(0, 3).map(m => {
                    const cm = m.replace(/\D/g, "");
                    const c = contacts.find((ct: Contact) => {
                        const ctp = ct.phone.replace(/\D/g, "");
                        return ct.phone === m || ctp === cm;
                    });
                    return c ? toProperCase(c.name) : null;
                }).filter(Boolean);
                if (memberNames.length > 0) {
                    const extra = conv.members.length - memberNames.length;
                    return memberNames.join(', ') + (extra > 0 ? ` +${extra}` : '');
                }
            }
            return 'Bulk Message';
        }

        return phone;
    };

    const handleRecentClick = (conv: Conversation) => {
        if (conv.type === 'bulk') {
            const batchId = extractBatchIdFromGroupConversationId(conv.id) || conv.id.replace(/^group_/, '');
            onSelectBulkMessage({
                id: `bulk-db-${batchId}`,
                message: conv.last_message || '',
                recipientCount: conv.members.length,
                recipientNumbers: conv.members,
                recipientKey: batchId,
                timestamp: conv.last_message_at || conv.updated_at || new Date().toISOString(),
                status: 'sent',
                batchId,
                fromDatabase: true,
            });
        } else {
            const phone = extractPhoneFromDirectConversationId(conv.id) || conv.id.replace(/^conv_/, '');
            const displayName = getDisplayName(conv);
            onSelectContact({
                id: conv.id,
                name: displayName,
                phone: phone,
                lastMessage: conv.last_message
            });
        }
    };

    return (
        <div className="h-full flex flex-col overflow-y-auto custom-scrollbar bg-[#f9fafb] dark:bg-[#111111]">
            <div className="max-w-5xl mx-auto w-full px-6 py-8 sm:py-12">
                {/* Header Section */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 mb-10 animate-in fade-in slide-in-from-bottom-4 duration-700">
                    <div className="flex items-center gap-4">
                        <div className="w-12 h-12 rounded-full bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex items-center justify-center shadow-[0_8px_25px_rgba(43,131,250,0.4)]">
                            <FiHome className="h-6 w-6 text-white" />
                        </div>
                        <div>
                            <SplitText
                                text={getGreeting()}
                                className="text-3xl font-extrabold text-[#111111] dark:text-white tracking-tight"
                                delay={50}
                                duration={1.25}
                                ease="power3.out"
                                splitType="chars"
                                from={{ opacity: 0, y: 40 }}
                                to={{ opacity: 1, y: 0 }}
                                threshold={0.1}
                                rootMargin="-100px"
                                textAlign="left"
                                tag="h1"
                                onLetterAnimationComplete={handleAnimationComplete}
                            />
                            <FadeContent blur={false} duration={1500} ease="ease-out" initialOpacity={0}>
                                <p className="text-[#6e6e73] dark:text-[#a0a0ab] font-medium">Welcome back to NOLA SMS PRO</p>
                            </FadeContent>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 w-full sm:w-auto">
                        <SenderSelector
                            value={senderName}
                            onChange={setSenderName}
                            align="right"
                        />
                    </div>
                </div>

                {/* Stats Grid */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                    <AnimatedContent delay={0.1} distance={50} direction="vertical">
                        <div className="p-6 rounded-3xl bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] shadow-lg shadow-blue-500/20 hover:shadow-blue-500/30 transition-all group overflow-hidden relative h-full">
                            <div className="absolute top-0 right-0 p-4 opacity-10 group-hover:scale-110 transition-transform duration-500">
                                <FiCreditCard className="w-24 h-24 text-white" />
                            </div>
                            <div className="relative z-10">
                                <div className="w-10 h-10 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white mb-4">
                                    <FiCreditCard className="h-5 w-5" />
                                </div>
                                 <p className="text-[13px] font-bold text-white/70 uppercase tracking-widest mb-1">Available Credits</p>
                                <div className="flex items-center justify-between gap-4">
                                    <h2 className="text-3xl font-black text-white">
                                        {loading ? "---" : balance?.toLocaleString()}
                                    </h2>
                                    <button
                                        onClick={() => {
                                            window.dispatchEvent(new CustomEvent('navigate-to-settings', { detail: { tab: 'credits' } }));
                                        }}
                                        className="px-3 py-1.5 rounded-xl bg-white/20 hover:bg-white/30 text-white text-[11px] font-bold transition-all flex items-center gap-1.5"
                                    >
                                        <FiPlus className="w-3 h-3" /> Top Up
                                    </button>
                                </div>
                            </div>
                        </div>
                    </AnimatedContent>

                    <AnimatedContent delay={0.2} distance={50} direction="vertical">
                        <div className="p-6 rounded-3xl bg-gradient-to-br from-purple-500 to-indigo-600 shadow-lg shadow-purple-500/20 hover:shadow-purple-500/30 transition-all group overflow-hidden relative h-full">
                            <div className="absolute top-0 right-0 p-4 opacity-10 group-hover:scale-110 transition-transform duration-500">
                                <FiMessageSquare className="w-24 h-24 text-white" />
                            </div>
                            <div className="relative z-10">
                                <div className="w-10 h-10 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white mb-4">
                                    <FiMessageSquare className="h-5 w-5" />
                                </div>
                                <p className="text-[13px] font-bold text-white/70 uppercase tracking-widest mb-1">Total Conversations</p>
                                <h2 className="text-3xl font-black text-white">
                                    {loading ? "---" : conversations.length}
                                </h2>
                            </div>
                        </div>
                    </AnimatedContent>

                    <AnimatedContent delay={0.3} distance={50} direction="vertical">
                        <div className="p-6 rounded-3xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/30 transition-all group overflow-hidden relative h-full">
                            <div className="absolute top-0 right-0 p-4 opacity-10 group-hover:scale-110 transition-transform duration-500">
                                <FiUsers className="w-24 h-24 text-white" />
                            </div>
                            <div className="relative z-10">
                                <div className="w-10 h-10 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white mb-4">
                                    <FiUsers className="h-5 w-5" />
                                </div>
                                <p className="text-[13px] font-bold text-white/70 uppercase tracking-widest mb-1">Total Contacts</p>
                                <h2 className="text-3xl font-black text-white">
                                    {loading ? "---" : contactsCount}
                                </h2>
                            </div>
                        </div>
                    </AnimatedContent>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-10">
                    {/* Quick Actions */}
                    <AnimatedContent delay={0.4} distance={50} direction="vertical">
                        <h3 className="text-[15px] font-bold text-[#111111] dark:text-white mb-5 flex items-center gap-2">
                            Quick Actions
                        </h3>
                        <div className="space-y-3">
                            <button
                                onClick={() => onTabChange('compose')}
                                className="w-full p-4 rounded-2xl bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] shadow-sm hover:shadow-indigo-500/10 hover:border-[#2b83fa]/30 transition-all text-left flex items-center justify-between group"
                            >
                                <div className="flex items-center gap-4">
                                    <div className="w-12 h-12 rounded-xl bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center text-[#2b83fa] transition-transform group-hover:scale-110">
                                        <FiPlus className="h-6 w-6" />
                                    </div>
                                    <div>
                                        <h4 className="font-bold text-[#111111] dark:text-white text-[15px]">Start New Chat</h4>
                                        <p className="text-[12px] text-gray-500 dark:text-gray-400 font-medium">Create a single or bulk message</p>
                                    </div>
                                </div>
                                <FiArrowRight className="h-5 w-5 text-gray-300 group-hover:text-[#2b83fa] group-hover:translate-x-1 transition-all" />
                            </button>

                            <button
                                onClick={() => onTabChange('contacts')}
                                className="w-full p-4 rounded-2xl bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] shadow-sm hover:shadow-emerald-500/10 hover:border-emerald-500/30 transition-all text-left flex items-center justify-between group"
                            >
                                <div className="flex items-center gap-4">
                                    <div className="w-12 h-12 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center text-emerald-500 transition-transform group-hover:scale-110">
                                        <FiUsers className="h-6 w-6" />
                                    </div>
                                    <div>
                                        <h4 className="font-bold text-[#111111] dark:text-white text-[15px]">Manage Contacts</h4>
                                        <p className="text-[12px] text-gray-500 dark:text-gray-400 font-medium">Add, edit, or remove recipients</p>
                                    </div>
                                </div>
                                <FiArrowRight className="h-5 w-5 text-gray-300 group-hover:text-emerald-500 group-hover:translate-x-1 transition-all" />
                            </button>

                            <button
                                onClick={() => onTabChange('settings')}
                                className="w-full p-4 rounded-2xl bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] shadow-sm hover:shadow-gray-500/10 hover:border-gray-500/30 transition-all text-left flex items-center justify-between group"
                            >
                                <div className="flex items-center gap-4">
                                    <div className="w-12 h-12 rounded-xl bg-gray-50 dark:bg-gray-800 flex items-center justify-center text-gray-600 dark:text-gray-400 transition-transform group-hover:scale-110">
                                        <FiSettings className="h-6 w-6" />
                                    </div>
                                    <div>
                                        <h4 className="font-bold text-[#111111] dark:text-white text-[15px]">Account Settings</h4>
                                        <p className="text-[12px] text-gray-500 dark:text-gray-400 font-medium">Profile, API keys, and more</p>
                                    </div>
                                </div>
                                <FiArrowRight className="h-5 w-5 text-gray-300 group-hover:text-gray-600 group-hover:translate-x-1 transition-all" />
                            </button>
                        </div>
                    </AnimatedContent>

                    {/* Recent Activity */}
                    <AnimatedContent delay={0.5} distance={50} direction="vertical">
                        <div className="flex items-center justify-between mb-5">
                            <h3 className="text-[15px] font-bold text-[#111111] dark:text-white flex items-center gap-2">
                                Recent Activity
                            </h3>
                            {conversations.length > 5 && (
                                <button
                                    onClick={() => setShowAllActivity(true)}
                                    className="text-[12px] font-bold text-[#2b83fa] hover:text-[#1a65d1] py-1 px-3 rounded-full hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                                >
                                    See All
                                </button>
                            )}
                        </div>
                        <div className="space-y-2">
                            {loading && conversations.length === 0 ? (
                                [1, 2, 3].map(i => (
                                    <div key={i} className="w-full p-3.5 rounded-2xl bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] flex items-center justify-between">
                                        <div className="flex items-center gap-3 w-full">
                                            <div className="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 animate-pulse flex-shrink-0" />
                                            <div className="space-y-2 w-full max-w-[150px]">
                                                <div className="h-3 w-3/4 bg-gray-100 dark:bg-gray-800 animate-pulse rounded" />
                                                <div className="h-2 w-full bg-gray-50 dark:bg-gray-900 animate-pulse rounded opacity-60" />
                                            </div>
                                        </div>
                                        <div className="w-16 h-3 bg-gray-50 dark:bg-gray-900 animate-pulse rounded opacity-60" />
                                    </div>
                                ))
                            ) : conversations.length > 0 ? (
                                conversations.slice(0, 5).map(conv => (
                                    <button
                                        key={conv.id}
                                        onClick={() => handleRecentClick(conv)}
                                        className="w-full p-3.5 rounded-2xl bg-white dark:bg-[#1c1e21] border border-[#0000000a] dark:border-[#ffffff0a] shadow-sm hover:shadow-md transition-all text-left flex items-center justify-between group"
                                    >
                                        <div className="flex items-center gap-3 overflow-hidden">
                                            <div className={`w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center text-white font-bold shadow-sm ${conv.type === 'bulk' ? 'bg-gradient-to-br from-purple-500 to-indigo-600' : 'bg-gradient-to-br from-[#2b83fa] to-[#60a5fa]'}`}>
                                                {conv.type === 'bulk' ? <FiUsers size={18} /> : (() => { const dn = getDisplayName(conv); return dn ? dn.charAt(0).toUpperCase() : <FiUser size={18} />; })()}
                                            </div>
                                            <div className="min-w-0">
                                                <h4 className="font-bold text-[#111111] dark:text-white text-[13.5px] truncate">
                                                    {getDisplayName(conv)}
                                                </h4>
                                                <p className="text-[11.5px] text-gray-500 dark:text-gray-400 truncate max-w-[200px] font-medium">
                                                    {conv.last_message || "No messages yet"}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex flex-col items-end gap-1 flex-shrink-0">
                                            <div className="flex items-center gap-1 text-[10px] text-gray-400 font-bold uppercase tracking-tighter">
                                                <FiClock size={10} />
                                                {conv.last_message_at ? new Date(conv.last_message_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : "--"}
                                            </div>
                                            <div className="px-1.5 py-0.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-[#2b83fa] text-[9px] font-black uppercase tracking-widest opacity-0 group-hover:opacity-100 transition-opacity">
                                                View
                                            </div>
                                        </div>
                                    </button>
                                ))
                            ) : (
                                <div className="p-10 text-center rounded-3xl border-2 border-dashed border-[#0000000a] dark:border-[#ffffff0a]">
                                    <p className="text-gray-400 dark:text-gray-500 text-[14px] font-medium italic">No recent activity found.</p>
                                </div>
                            )}
                        </div>
                    </AnimatedContent>
                </div>
            </div>

            {/* All Activity Popup */}
            {showAllActivity && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm animate-in fade-in duration-200">
                    <div
                        className="bg-white dark:bg-[#1c1e21] w-full max-w-md rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[85vh] animate-in zoom-in-[0.98] duration-200 border border-[#0000000a] dark:border-[#ffffff0a]"
                        onClick={e => e.stopPropagation()}
                    >
                        <div className="flex items-center justify-between p-5 border-b border-[#00000005] dark:border-[#ffffff05]">
                            <h3 className="text-[17px] font-bold text-[#111111] dark:text-white">All Recent Activity</h3>
                            <button
                                onClick={() => setShowAllActivity(false)}
                                className="p-2 -mr-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 bg-transparent hover:bg-black/5 dark:hover:bg-white/5 rounded-full transition-colors active:scale-95"
                            >
                                <FiX size={20} />
                            </button>
                        </div>
                        <div className="p-2 overflow-y-auto custom-scrollbar flex-1">
                            {conversations.map(conv => (
                                <button
                                    key={`modal-${conv.id}`}
                                    onClick={() => {
                                        handleRecentClick(conv);
                                        setShowAllActivity(false);
                                    }}
                                    className="w-full p-3.5 rounded-2xl bg-transparent hover:bg-black-[0.02] dark:hover:bg-white/[0.02] transition-all text-left flex items-center justify-between group mb-1"
                                >
                                    <div className="flex items-center gap-3 overflow-hidden">
                                        <div className={`w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center text-white font-bold shadow-sm ${conv.type === 'bulk' ? 'bg-gradient-to-br from-purple-500 to-indigo-600' : 'bg-gradient-to-br from-[#2b83fa] to-[#60a5fa]'}`}>
                                            {conv.type === 'bulk' ? <FiUsers size={18} /> : (() => { const dn = getDisplayName(conv); return dn ? dn.charAt(0).toUpperCase() : <FiUser size={18} />; })()}
                                        </div>
                                        <div className="min-w-0">
                                            <h4 className="font-bold text-[#111111] dark:text-white text-[13.5px] truncate">
                                                {getDisplayName(conv)}
                                            </h4>
                                            <p className="text-[11.5px] text-gray-500 dark:text-gray-400 truncate max-w-[200px] font-medium">
                                                {conv.last_message || "No messages yet"}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex flex-col items-end gap-1 flex-shrink-0">
                                        <div className="flex items-center gap-1 text-[10px] text-gray-400 font-bold uppercase tracking-tighter">
                                            <FiClock size={10} />
                                            {conv.last_message_at ? new Date(conv.last_message_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : "--"}
                                        </div>
                                    </div>
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
