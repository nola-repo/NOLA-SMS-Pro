import { useState, useEffect, useCallback, useRef } from "react";
import { fetchSmsLogs } from "../api/sms";
import type { Message, SmsLog } from "../types/Sms";

const POLL_INTERVAL = 5000; // 5 seconds

export const useMessages = (phoneNumber: string | undefined) => {
    const [messages, setMessages] = useState<Message[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const isInitialLoad = useRef(true);

    const formatLogToMessage = (log: SmsLog): Message => {
        let date: Date;
        if (!log.date_created) {
            date = new Date();
        } else if (typeof log.date_created === 'string') {
            date = new Date(log.date_created);
        } else if (typeof log.date_created === 'object' && '_seconds' in log.date_created) {
            date = new Date((log.date_created as any)._seconds * 1000);
        } else {
            date = new Date();
        }

        return {
            id: log.message_id || `msg-${Date.now()}`,
            text: log.message || '',
            timestamp: date,
            senderName: log.sender_id || 'NOLASMSPro',
            status: (() => {
                let mappedStatus = (log.status || 'sent').toLowerCase();
                // Optimistically show 'sent' (green) for messages that have reached Semaphore (pending delivery)
                // This stops the UI from flickering back to gray 'pending' while the backend syncs
                if (mappedStatus === 'pending' || mappedStatus === 'queued') {
                    mappedStatus = 'sent';
                }
                return mappedStatus as Message['status'];
            })(),
            errorReason: log.error_reason,
        };
    };

    const fetchHistory = useCallback(async (showLoading = true) => {
        if (!phoneNumber) {
            setMessages([]);
            return;
        }

        if (showLoading) setLoading(true);
        setError(null);

        try {
            const logs = await fetchSmsLogs(phoneNumber);
            const formattedMessages = logs.map(formatLogToMessage);
            formattedMessages.sort((a, b) => a.timestamp.getTime() - b.timestamp.getTime());

            // Preserve in-flight optimistic "temp-" messages not yet confirmed from API
            setMessages(prev => {
                const tempOnly = prev.filter(msg =>
                    msg.id.startsWith('temp-') &&
                    !formattedMessages.some(apiMsg =>
                        apiMsg.text === msg.text &&
                        Math.abs(apiMsg.timestamp.getTime() - msg.timestamp.getTime()) < 60000
                    )
                );
                return [...formattedMessages, ...tempOnly];
            });
        } catch (err) {
            setError(err instanceof Error ? err.message : "Failed to load history");
        } finally {
            setLoading(false);
            isInitialLoad.current = false;
        }
    }, [phoneNumber]);

    // Initial fetch — clear messages when phone number changes
    useEffect(() => {
        isInitialLoad.current = true;
        setMessages([]);
        fetchHistory(true);
    }, [fetchHistory]);

    // Background polling every 5 seconds
    useEffect(() => {
        if (!phoneNumber) return;
        const interval = setInterval(() => fetchHistory(false), POLL_INTERVAL);
        return () => clearInterval(interval);
    }, [phoneNumber, fetchHistory]);

    const addOptimisticMessage = (text: string, senderName: string): string => {
        const id = `temp-${Date.now()}`;
        const newMessage: Message = {
            id,
            text,
            timestamp: new Date(),
            senderName,
            status: 'sending',
        };
        setMessages(prev => [...prev, newMessage]);
        return id;
    };

    const updateMessageStatus = (tempId: string, status: 'sent' | 'failed', realId?: string, errorReason?: string) => {
        setMessages(prev =>
            prev.map(msg =>
                msg.id === tempId
                    ? { ...msg, status, id: realId || msg.id, errorReason }
                    : msg
            )
        );
    };

    return {
        messages,
        loading,
        error,
        addOptimisticMessage,
        updateMessageStatus,
        refresh: fetchHistory,
    };
};
