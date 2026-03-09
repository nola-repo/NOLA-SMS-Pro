import { useState, useEffect, useCallback, useRef } from "react";
import { fetchMessagesByRecipientKey } from "../api/sms";
import type { Message } from "../types/Sms";

// Helper to parse date from Firestore timestamp or string
const parseDate = (dateField: unknown): number => {
    try {
        if (!dateField) return Date.now();
        if (typeof dateField === 'string') {
            return new Date(dateField).getTime();
        }
        if (dateField && typeof dateField === 'object' && '_seconds' in dateField) {
            const ts = dateField as { _seconds: number };
            return ts._seconds * 1000;
        }
        return Date.now();
    } catch {
        return Date.now();
    }
};

// Normalize phone number to 09XXXXXXXXX format for consistent comparison
const normalizePHNumber = (input: string): string | null => {
    if (!input) return null;
    
    const digits = input.replace(/\D/g, "");
    
    // 09XXXXXXXXX → valid
    if (digits.startsWith("09") && digits.length === 11) {
        return digits;
    }
    
    // 9XXXXXXXXX → 09XXXXXXXXX
    if (digits.startsWith("9") && digits.length === 10) {
        return "0" + digits;
    }
    
    // 639XXXXXXXXX → 09XXXXXXXXX
    if (digits.startsWith("639") && digits.length === 12) {
        return "0" + digits.substring(2);
    }
    
    // +639XXXXXXXXX (already digits only)
    if (digits.startsWith("639") && digits.length === 12) {
        return "0" + digits.substring(2);
    }
    
    return null;
};

export const useGroupMessages = (recipientKey?: string, recipientNumbers?: string[], batchId?: string) => {
    const [messages, setMessages] = useState<Message[]>([]);
    const [loading, setLoading] = useState(false);
    const initialLoadDone = useRef(false);
    const dataLoaded = useRef(false);

    const refresh = useCallback(async (showLoading = false) => {
        // If we have a specific batchId, fetch messages for that batch
        // OR if we have a recipientKey, fetch by recipient_key (conversation)
        if (batchId || recipientKey) {
            if (showLoading) setLoading(true);
            try {
                // Use recipient_key if available, otherwise use batchId
                const conversationKey = recipientKey || batchId;
                if (!conversationKey) {
                    setMessages([]);
                    return;
                }
                
                console.log('[useGroupMessages] Fetching by conversation key:', conversationKey);
                
                // Fetch messages by recipient_key (conversation key)
                const messagesData = await fetchMessagesByRecipientKey(conversationKey);
                console.log('[useGroupMessages] Messages received:', messagesData.length);
                
                // Debug: check what fields the messages have
                if (messagesData.length > 0) {
                    console.log('[useGroupMessages] Sample message fields:', Object.keys(messagesData[0]));
                    console.log('[useGroupMessages] Sample message:', messagesData[0]);
                }
                
                // Filter by recipient numbers if provided (for specific group members)
                let filtered = messagesData;
                if (recipientNumbers && recipientNumbers.length > 0) {
                    const normalizedRecipients = recipientNumbers
                        .map(n => normalizePHNumber(n))
                        .filter((n): n is string => n !== null);
                    console.log('[useGroupMessages] Normalized recipients:', normalizedRecipients);
                    
                    // Filter messages: check if message's number matches any recipient
                    filtered = messagesData.filter((m) => {
                        const msgNumber = m.number || '';
                        const normalizedMsgNumber = normalizePHNumber(msgNumber);
                        
                        return normalizedMsgNumber && normalizedRecipients.includes(normalizedMsgNumber);
                    });
                    console.log('[useGroupMessages] After filtering:', filtered.length);
                }
                
                // Sort by date (chronological - oldest first for chat)
                filtered = [...filtered].sort((a, b) => {
                    const dateA = a?.date_created ? parseDate(a.date_created) : Date.now();
                    const dateB = b?.date_created ? parseDate(b.date_created) : Date.now();
                    return dateA - dateB;
                });
                
                // Transform to UI format
                let transformedMessages: Message[] = [];
                try {
                    transformedMessages = filtered
                        .filter(m => m && m.message_id && m.message)
                        .map(m => ({
                            id: m.message_id,
                            text: m.message || '',
                            timestamp: new Date(parseDate(m.date_created)),
                            senderName: m.sender_id || 'NOLACRM',
                            status: (m.status || 'sent') as Message['status'],
                            batch_id: m.batch_id,
                            message: m.message,
                            date_created: m.date_created,
                        }));
                } catch (err) {
                    console.error('[useGroupMessages] Transformation error:', err);
                }
                
                setMessages(transformedMessages);
                dataLoaded.current = true;
                console.log('[useGroupMessages] Final messages set:', transformedMessages.length);
            } catch (error) {
                console.error("Failed to fetch group messages:", error);
            } finally {
                if (showLoading) setLoading(false);
                initialLoadDone.current = true;
            }
            return;
        }

        // No batchId or recipientKey - clear messages
        setMessages([]);
    }, [recipientKey, recipientNumbers, batchId]);

    useEffect(() => {
        initialLoadDone.current = false;
        refresh(true);

        if (!recipientKey && !batchId) return;

        // Background polling every 10 seconds
        const interval = setInterval(() => {
            refresh(false);
        }, 10000);

        return () => clearInterval(interval);
    }, [recipientKey, refresh, batchId]);

    return {
        messages,
        loading: loading && !initialLoadDone.current,
        refresh
    };
};
