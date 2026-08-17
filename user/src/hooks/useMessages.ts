import { devLog } from '../utils/devLog';
import { useState, useEffect, useCallback, useRef } from "react";
import { fetchSmsLogs, normalizePHNumber } from "../api/sms";
import type { Message, SmsLog } from "../types/Sms";
import { useLocationId } from "../context/LocationContext";
import { collection, query, where, onSnapshot } from "firebase/firestore";
import { ensureFirestoreAuth } from "../services/firestoreAuth";
import { db } from "../services/firebaseConfig";

export const useMessages = (phoneNumber: string | undefined) => {
    const { locationId } = useLocationId();
    const [messages, setMessages] = useState<Message[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const isInitialLoad = useRef(true);

    const formatLogToMessage = useCallback((log: SmsLog): Message => {
        let date: Date;
        if (!log.date_created) {
            date = new Date();
        } else if (typeof log.date_created === 'string') {
            date = new Date(log.date_created);
        } else if (typeof log.date_created === 'object' && log.date_created !== null) {
            if ('toDate' in log.date_created && typeof (log.date_created as { toDate: () => Date }).toDate === 'function') {
                date = (log.date_created as { toDate: () => Date }).toDate();
            } else if ('_seconds' in log.date_created) {
                date = new Date((log.date_created as { _seconds: number })._seconds * 1000);
            } else {
                date = new Date();
            }
        } else {
            date = new Date();
        }

        return {
            id: log.message_id || `msg-${Date.now()}-${Math.random()}`,
            text: log.message || '',
            timestamp: date,
            senderName: log.sender_name || log.sender_id || 'NOLASMSPro',
            status: (() => {
                let status = (log.status || 'sending').toLowerCase();
                
                // Strictly unify statuses for UI matching the backend 3-state hierarchy
                if (['queued', 'pending', 'sending'].includes(status)) {
                    status = 'sending';
                } else if (['delivered', 'success', 'sent'].includes(status)) {
                    status = 'sent';
                } else if (['rejected', 'undelivered', 'expired', 'failed'].includes(status)) {
                    status = 'failed';
                }
                return status as Message['status'];
            })(),
            errorReason: log.error_reason,
            providerMessageId: log.provider_message_id || log.provider_reference_id,
            providerReferenceId: log.provider_reference_id,
        };
    }, []);

    const processAndMergeLogs = useCallback((logs: SmsLog[]) => {
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
    }, [formatLogToMessage]);

    const fetchHistory = useCallback(async (showLoading = true) => {
        if (!phoneNumber) {
            setMessages([]);
            return;
        }

        if (showLoading) setLoading(true);
        setError(null);

        try {
            const logs = await fetchSmsLogs(phoneNumber, locationId || undefined);
            processAndMergeLogs(logs);
        } catch (err) {
            setError(err instanceof Error ? err.message : "Failed to load history");
        } finally {
            setLoading(false);
            isInitialLoad.current = false;
        }
    }, [phoneNumber, locationId, processAndMergeLogs]);

    // Initial fetch — clear messages when phone number changes
    useEffect(() => {
        isInitialLoad.current = true;
        setMessages([]);
        fetchHistory(true);
    }, [fetchHistory]);

    // Real-time Firestore subscription on sms_logs collection
    useEffect(() => {
        if (!phoneNumber || !locationId) return;

        let unsubscribe: (() => void) | undefined;

        const setupListener = async () => {
            try {
                await ensureFirestoreAuth();

                const formattedNumber = normalizePHNumber(phoneNumber);
                if (!formattedNumber) {
                    setMessages([]);
                    return;
                }

                const q = query(
                    collection(db, 'sms_logs'),
                    where('location_id', '==', locationId),
                    where('numbers', 'array-contains', formattedNumber)
                );

                unsubscribe = onSnapshot(q, (snapshot) => {
                    const logs = snapshot.docs.map(doc => {
                        const d = doc.data();
                        return {
                            id: doc.id,
                            message_id: doc.id,
                            numbers: d.numbers ?? [],
                            message: d.message ?? '',
                            sender_id: d.sender_id ?? 'NOLASMSPro',
                            sender_name: d.sender_name ?? null,
                            status: d.status ?? 'Sending',
                            date_created: d.date_created ?? null,
                            source: d.source ?? 'semaphore',
                            batch_id: d.batch_id ?? null,
                            recipient_key: d.recipient_key ?? null,
                            credits_used: d.credits_used ?? 0,
                            conversation_id: d.conversation_id ?? null,
                            error_reason: d.error_reason ?? null,
                            provider_message_id: d.provider_message_id ?? null,
                            provider_reference_id: d.provider_reference_id ?? null,
                        } as SmsLog;
                    });
                    processAndMergeLogs(logs);
                }, (err) => {
                    devLog.error("[useMessages] Firestore subscription error:", err);
                });
            } catch (err) {
                devLog.error("[useMessages] Firestore setup error:", err);
            }
        };

        setupListener();

        return () => {
            if (unsubscribe) unsubscribe();
        };
    }, [phoneNumber, locationId, processAndMergeLogs]);

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

    const updateMessageStatus = (tempId: string, status: 'sending' | 'sent' | 'failed', realId?: string, errorReason?: string) => {
        setMessages(prev =>
            prev.map(msg =>
                msg.id === tempId || (realId && msg.id === realId)
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
