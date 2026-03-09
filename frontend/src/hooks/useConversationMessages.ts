import { useState, useEffect, useCallback, useRef } from "react";
import { fetchMessagesByConversationId } from "../api/sms";
import type { Message } from "../types/Sms";
import { getCachedMessages, setCachedMessages, updateMessageInCache } from "../utils/storage";

const POLL_INTERVAL = 5000;

/** Parse a Firestore timestamp (string or _seconds object) to a JS Date */
const parseFirestoreDate = (raw: unknown): Date => {
    if (!raw) return new Date();
    if (typeof raw === "string") return new Date(raw);
    if (typeof raw === "object" && raw !== null && "_seconds" in raw) {
        return new Date((raw as { _seconds: number })._seconds * 1000);
    }
    return new Date();
};

/**
 * Load and poll messages for a single conversation by its conversation_id.
 *
 * Direct chat:  conversationId = "conv_09XXXXXXXXX"
 * Bulk chat:    conversationId = "group_batch_xxx"
 *
 * Replaces the old useMessages (per phone number) +
 * useGroupMessages (per recipient_key) pair.
 */
export const useConversationMessages = (conversationId: string | undefined) => {
    const [messages, setMessages] = useState<Message[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const isInitialLoad = useRef(true);

    const fetchHistory = useCallback(async (showLoading = true) => {
        // Load from cache first for instant display
        if (conversationId) {
            const cached = getCachedMessages(conversationId);
            if (cached && cached.length > 0) {
                setMessages(cached);
            }
        }

        if (!conversationId) return;

        if (showLoading) setLoading(true);
        setError(null);

        try {
            const rows = await fetchMessagesByConversationId(conversationId);

            // Sort oldest → newest for chronological display
            const sorted = [...rows].sort(
                (a, b) =>
                    parseFirestoreDate(a.created_at).getTime() -
                    parseFirestoreDate(b.created_at).getTime()
            );

            const formatted: Message[] = sorted.map((row) => ({
                id: row.id,
                text: row.message || "",
                timestamp: parseFirestoreDate(row.created_at),
                senderName: row.sender_id || "NOLACRM",
                status: (row.status as Message["status"]) || "sent",
                batch_id: row.batch_id,
                message: row.message,
            }));

            // Merge optimistic "temp-" messages that haven't been confirmed yet
            const cached = getCachedMessages(conversationId) || [];
            const tempOnly = cached.filter(
                (m) =>
                    m.id.startsWith("temp-") &&
                    !formatted.some(
                        (api) =>
                            api.text === m.text &&
                            Math.abs(api.timestamp.getTime() - m.timestamp.getTime()) < 60_000
                    )
            );

            const merged = [...formatted, ...tempOnly];
            setMessages(merged);
            setCachedMessages(conversationId, merged);
        } catch (err) {
            setError(err instanceof Error ? err.message : "Failed to load messages");
        } finally {
            setLoading(false);
            isInitialLoad.current = false;
        }
    }, [conversationId]);

    // Initial fetch
    useEffect(() => {
        isInitialLoad.current = true;
        fetchHistory(true);
    }, [fetchHistory]);

    // Background polling
    useEffect(() => {
        if (!conversationId) return;
        const interval = setInterval(() => fetchHistory(false), POLL_INTERVAL);
        return () => clearInterval(interval);
    }, [conversationId, fetchHistory]);

    const addOptimisticMessage = (text: string, senderName: string): string => {
        const id = `temp-${Date.now()}`;
        const newMsg: Message = {
            id,
            text,
            timestamp: new Date(),
            senderName,
            status: "sending",
        };
        setMessages((prev) => {
            const updated = [...prev, newMsg];
            if (conversationId) setCachedMessages(conversationId, updated);
            return updated;
        });
        return id;
    };

    const updateMessageStatus = (
        tempId: string,
        status: "sent" | "failed",
        realId?: string
    ) => {
        setMessages((prev) => {
            const updated = prev.map((m) =>
                m.id === tempId ? { ...m, status, id: realId || m.id } : m
            );
            if (conversationId) updateMessageInCache(conversationId, tempId, status, realId);
            return updated;
        });
    };

    return {
        messages,
        loading: loading && isInitialLoad.current,
        error,
        addOptimisticMessage,
        updateMessageStatus,
        refresh: fetchHistory,
    };
};
