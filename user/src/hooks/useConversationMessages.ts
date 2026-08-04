import { devLog } from '../utils/devLog';
import { useState, useEffect, useCallback, useMemo, useRef } from "react";
import { fetchMessagesByConversationId, ConversationMessagesError } from "../api/sms";
import type { Message, Conversation } from "../types/Sms";
import { useLocationId } from "../context/LocationContext";
import { collection, query, where, onSnapshot, doc } from "firebase/firestore";
import { ensureFirestoreAuth } from "../services/firestoreAuth";
import { db } from "../services/firebaseConfig";

const CACHE_TTL_MS = 60_000;
const SKELETON_DELAY_MS = 160;

const messageHistoryCache = new Map<string, { messages: Message[]; fetchedAt: number }>();

const buildMessageHistoryCacheKey = (
    locationId: string | undefined,
    conversationId: string | undefined,
    recipientKey?: string
) => conversationId ? `${locationId || "global"}::${conversationId}::${recipientKey || "all"}` : "";

/** Parse a Firestore timestamp (string, native Timestamp, or _seconds object) to a JS Date */
const parseFirestoreDate = (raw: unknown): Date => {
    if (!raw) return new Date();
    if (typeof raw === "string") return new Date(raw);
    if (typeof raw === "object" && raw !== null) {
        if ("toDate" in raw && typeof (raw as { toDate: () => Date }).toDate === "function") {
            return (raw as { toDate: () => Date }).toDate();
        }
        if ("_seconds" in raw) {
            return new Date((raw as { _seconds: number })._seconds * 1000);
        }
    }
    return new Date();
};

const STATUS_PRIORITY: Record<string, number> = {
    sending: 0,
    queued: 1,
    pending: 2,
    sent: 3,
    delivered: 4,
    failed: 4,
    rejected: 4,
    undelivered: 4,
    expired: 4,
};

const sameMessageTarget = (a: Message, b: Message) => {
    const sameConversation = !a.conversation_id || !b.conversation_id || a.conversation_id === b.conversation_id;
    const sameNumber = !a.number || !b.number || a.number === b.number;
    const sameRecipientKey = !a.recipient_key || !b.recipient_key || a.recipient_key === b.recipient_key;
    return sameConversation && sameNumber && sameRecipientKey;
};

const messagesLikelySame = (a: Message, b: Message) =>
    a.id === b.id ||
    (
        a.text.trim() === b.text.trim() &&
        sameMessageTarget(a, b) &&
        Math.abs(a.timestamp.getTime() - b.timestamp.getTime()) < 5 * 60_000
    );

const apiMatchesLocal = (api: Message, local: Message) =>
    api.id === local.id ||
    (
        api.text.trim() === local.text.trim() &&
        sameMessageTarget(api, local) &&
        Math.abs(api.timestamp.getTime() - local.timestamp.getTime()) < 60_000
    );

const apiSupersedesLocal = (api: Message, local: Message) =>
    apiMatchesLocal(api, local) ||
    (
        local.id.startsWith("temp-") &&
        api.text === local.text &&
        Math.abs(api.timestamp.getTime() - local.timestamp.getTime()) < 5 * 60_000
    );

const makeTempId = () =>
    `temp-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

interface DatabaseMessageRow {
    id?: string;
    message_id?: string;
    conversation_id?: string;
    number?: string;
    message?: string;
    created_at?: unknown;
    date_created?: unknown;
    sender_id?: string;
    sender_name?: string;
    status?: string;
    batch_id?: string;
    recipient_key?: string;
    error_reason?: string;
    error_code?: string;
    provider_status?: string;
    provider_response?: string | Record<string, unknown> | null;
    provider_message_id?: string;
    provider_reference_id?: string;
    ghl_sync_queued?: boolean;
    ghl_sync_job_id?: string;
    ghl_sync_success?: boolean;
    ghl_sync_skipped?: boolean;
    ghl_sync_reason?: string;
    ghl_sync_error?: string;
    ghl_sync_http_status?: number;
    ghl_sync_updated_at?: string;
    ghl_message_id?: string;
}

/**
 * Load and listen to messages for a single conversation by its conversation_id.
 * No localStorage caching — always fetches fresh from the backend.
 *
 * Direct chat:  conversationId = "{locationId}_conv_09XXXXXXXXX" (or legacy "conv_09XXXXXXXXX")
 * Bulk chat:    conversationId = "group_batch_xxx"
 */
export const useConversationMessages = (conversationId: string | undefined, recipientKey?: string) => {
    const { locationId } = useLocationId();
    const [messages, setMessages] = useState<Message[]>([]);
    const [conversation, setConversation] = useState<Conversation | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errorStatus, setErrorStatus] = useState<number | undefined>(undefined);
    const isInitialLoad = useRef(true);
    const consecutiveServerErrors = useRef(0);
    const skeletonTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const requestSeq = useRef(0);
    const cacheKey = useMemo(
        () => buildMessageHistoryCacheKey(locationId || undefined, conversationId, recipientKey),
        [conversationId, locationId, recipientKey]
    );
    const cacheKeyRef = useRef(cacheKey);
    const optimisticMessageTargets = useRef(new Map<string, string>());

    useEffect(() => {
        cacheKeyRef.current = cacheKey;
    }, [cacheKey]);

    const processAndMergeMessages = useCallback((rows: DatabaseMessageRow[]) => {
        // Sort oldest → newest for chronological display
        const sorted = [...rows].sort(
            (a, b) =>
                parseFirestoreDate(a.created_at).getTime() -
                parseFirestoreDate(b.created_at).getTime()
        );

        const formatted: Message[] = sorted.map((row) => {
            let status = (row.status as string || 'sending').toLowerCase();
            
            // Strictly unify statuses for UI
            if (['queued', 'pending'].includes(status)) {
                status = 'sending';
            } else if (['delivered', 'success'].includes(status)) {
                status = 'sent';
            } else if (['rejected', 'undelivered', 'expired'].includes(status)) {
                status = 'failed';
            }

            return {
                id: row.message_id || row.id || `msg-${Date.now()}-${Math.random()}`,
                conversation_id: row.conversation_id,
                number: row.number,
                text: row.message || "",
                timestamp: parseFirestoreDate(row.created_at || row.date_created),
                senderName: row.sender_name || row.sender_id || "NOLASMSPro",
                status: status as Message["status"],

                batch_id: row.batch_id,
                recipient_key: row.recipient_key,
                message: row.message,
                errorReason: row.error_reason,
                errorCode: row.error_code,
                providerStatus: row.provider_status,
                providerResponse: row.provider_response,
                providerMessageId: row.provider_message_id || row.provider_reference_id,
                providerReferenceId: row.provider_reference_id,

                ghlSyncQueued: row.ghl_sync_queued,
                ghlSyncJobId: row.ghl_sync_job_id,
                ghlSyncSuccess: row.ghl_sync_success,
                ghlSyncSkipped: row.ghl_sync_skipped,
                ghlSyncReason: row.ghl_sync_reason,
                ghlSyncError: row.ghl_sync_error,
                ghlSyncHttpStatus: row.ghl_sync_http_status,
                ghlSyncUpdatedAt: row.ghl_sync_updated_at,
                ghlMessageId: row.ghl_message_id,
            };
        });

        // Compute apiReturnedTransientEmpty outside the state updater context
        const apiReturnedTransientEmpty =
            !isInitialLoad.current && formatted.length === 0;

        setMessages(prev => {
            const prevById = new Map(prev.map(m => [m.id, m]));

            const merged = formatted.map(apiMsg => {
                const local = prevById.get(apiMsg.id);
                if (local) {
                    // Keep local status if it has equal or higher priority than what DB returned
                    const localPriority = STATUS_PRIORITY[local.status] ?? -1;
                    const apiPriority = STATUS_PRIORITY[apiMsg.status] ?? -1;
                    if (localPriority >= apiPriority) {
                        return { ...apiMsg, status: local.status };
                    }
                    return apiMsg;
                }
                // For bulk conversations: a single optimistic temp message represents the whole batch.
                // When the Firestore real-time listener fires with per-recipient API messages (status='Sending'),
                // check if any temp message matches by text+timestamp and inherit its higher-priority status
                // (e.g. 'sent') so the UI doesn't regress to 'Sending' after a successful send.
                const matchingTemp = prev.find(m =>
                    m.id.startsWith('temp-') &&
                    m.text === apiMsg.text &&
                    Math.abs(m.timestamp.getTime() - apiMsg.timestamp.getTime()) < 5 * 60_000
                );
                if (matchingTemp) {
                    const tempPriority = STATUS_PRIORITY[matchingTemp.status] ?? -1;
                    const apiPriority = STATUS_PRIORITY[apiMsg.status] ?? -1;
                    if (tempPriority >= apiPriority) {
                        return { ...apiMsg, status: matchingTemp.status };
                    }
                }
                return apiMsg;
            });

            const currentCacheKey = cacheKeyRef.current;
            const hasPrev = prev.length > 0;
            const isTransientEmpty = apiReturnedTransientEmpty && hasPrev;

            const localOnly = prev.filter((local) => {
                if (formatted.some((api) => apiSupersedesLocal(api, local))) {
                    return false;
                }

                if (local.id.startsWith("temp-")) {
                    return true;
                }

                const isRecentlySent = Date.now() - local.timestamp.getTime() < 5 * 60_000;
                return isTransientEmpty || isRecentlySent;
            });

            const nextMessages = [...merged, ...localOnly]
                .reduce<Message[]>((acc, message) => {
                    const isDuplicate = acc.some(existing => {
                        const involvesTemp = existing.id.startsWith("temp-") || message.id.startsWith("temp-");
                        return existing.id === message.id ||
                            (involvesTemp && messagesLikelySame(existing, message)) ||
                            (message.id.startsWith("temp-") && apiSupersedesLocal(existing, message));
                    });
                    if (isDuplicate) {
                        return acc;
                    }
                    return [...acc, message];
                }, [])
                .sort((a, b) => a.timestamp.getTime() - b.timestamp.getTime());
            if (currentCacheKey) {
                messageHistoryCache.set(currentCacheKey, { messages: nextMessages, fetchedAt: Date.now() });
            }
            return nextMessages;
        });
    }, []);

    const fetchHistory = useCallback(async (showLoading = true) => {
        if (!conversationId) {
            setMessages([]);
            setLoading(false);
            return;
        }

        const requestId = ++requestSeq.current;
        if (showLoading) {
            if (skeletonTimer.current) clearTimeout(skeletonTimer.current);
            skeletonTimer.current = setTimeout(() => {
                if (requestSeq.current === requestId) {
                    setLoading(true);
                }
            }, SKELETON_DELAY_MS);
        }
        setError(null);
        setErrorStatus(undefined);

        try {
            const rows = await fetchMessagesByConversationId(conversationId, 100, recipientKey, locationId || undefined);
            if (requestSeq.current !== requestId) return;

            processAndMergeMessages(rows);

            consecutiveServerErrors.current = 0;
            setError(null);
            setErrorStatus(undefined);
        } catch (err) {
            if (requestSeq.current !== requestId) return;
            if (err instanceof ConversationMessagesError) {
                setError(err.message);
                setErrorStatus(err.status);
                if (typeof err.status === "number" && err.status >= 500) {
                    consecutiveServerErrors.current += 1;
                }
            } else if (err instanceof Error) {
                setError(err.message);
                setErrorStatus(undefined);
            } else {
                setError("Failed to load messages");
                setErrorStatus(undefined);
            }
        } finally {
            if (requestSeq.current === requestId) {
                if (skeletonTimer.current) {
                    clearTimeout(skeletonTimer.current);
                    skeletonTimer.current = null;
                }
                setLoading(false);
                isInitialLoad.current = false;
            }
        }
    }, [conversationId, recipientKey, locationId, processAndMergeMessages]);

    // Initial fetch — reset state when conversation changes
    useEffect(() => {
        if (!conversationId) {
            requestSeq.current += 1;
            if (skeletonTimer.current) {
                clearTimeout(skeletonTimer.current);
                skeletonTimer.current = null;
            }
            isInitialLoad.current = false;
            setMessages([]);
            setLoading(false);
            return;
        }

        const cached = cacheKey ? messageHistoryCache.get(cacheKey) : undefined;
        const hasFreshCache = cached && Date.now() - cached.fetchedAt < CACHE_TTL_MS;

        if (hasFreshCache) {
            isInitialLoad.current = false;
            setMessages(cached.messages);
            setLoading(false);
            fetchHistory(false);
            return;
        }

        isInitialLoad.current = true;
        setMessages([]);
        setLoading(true);
        fetchHistory(true);
    }, [cacheKey, conversationId, fetchHistory]);

    useEffect(() => {
        return () => {
            if (skeletonTimer.current) {
                clearTimeout(skeletonTimer.current);
            }
        };
    }, []);

    // Real-time Firestore message subscription
    useEffect(() => {
        if (!conversationId || !locationId) return;

        let unsubscribe: (() => void) | undefined;

        const setupListener = async () => {
            try {
                await ensureFirestoreAuth();

                let q = query(
                    collection(db, 'messages'),
                    where('location_id', '==', locationId),
                    where('conversation_id', '==', conversationId)
                );

                if (recipientKey) {
                    q = query(q, where('recipient_key', '==', recipientKey));
                }

                unsubscribe = onSnapshot(q, (snapshot) => {
                    const rows = snapshot.docs.map(doc => {
                        const d = doc.data();
                        return {
                            id: doc.id,
                            ...d
                        };
                    });
                    processAndMergeMessages(rows);
                }, (err) => {
                    devLog.error("[useConversationMessages] Firestore subscription error:", err);
                });
            } catch (err) {
                devLog.error("[useConversationMessages] Firestore setup error:", err);
            }
        };

        setupListener();

        return () => {
            if (unsubscribe) unsubscribe();
        };
    }, [conversationId, locationId, recipientKey, processAndMergeMessages]);

    // Real-time Firestore conversation document subscription
    useEffect(() => {
        if (!conversationId) {
            setConversation(null);
            return;
        }

        let unsubscribe: (() => void) | undefined;

        const setupDocListener = async () => {
            try {
                await ensureFirestoreAuth();
                const docRef = doc(db, "conversations", conversationId);
                unsubscribe = onSnapshot(docRef, (docSnap) => {
                    if (docSnap.exists()) {
                        const d = docSnap.data();
                        setConversation({
                            id: docSnap.id,
                            location_id: d.location_id ?? null,
                            type: d.type ?? null,
                            members: Array.isArray(d.members) ? d.members.map(String).filter(Boolean) : [],
                            name: d.name ?? null,
                            last_message: d.last_message ?? null,
                            last_message_at: d.last_message_at ? (typeof d.last_message_at.toDate === 'function' ? d.last_message_at.toDate().toISOString() : d.last_message_at) : null,
                            updated_at: d.updated_at ? (typeof d.updated_at.toDate === 'function' ? d.updated_at.toDate().toISOString() : d.updated_at) : null,
                            ghl_contact_id: d.ghl_contact_id ?? null,
                        } as Conversation);
                    } else {
                        setConversation(null);
                    }
                }, (err) => {
                    devLog.error("[useConversationMessages] Firestore conversation doc snapshot error:", err);
                });
            } catch (err) {
                devLog.error("[useConversationMessages] Firestore conversation doc setup error:", err);
            }
        };

        setupDocListener();

        return () => {
            if (unsubscribe) unsubscribe();
        };
    }, [conversationId]);

    // Listen for sidebar-triggered refresh events
    useEffect(() => {
        if (!conversationId) return;

        const handleConversationUpdated = (e: Event) => {
            const detail = (e as CustomEvent).detail;
            if (!detail?.conversationId || detail.conversationId === conversationId) {
                fetchHistory(false);
            }
        };

        window.addEventListener('conversation-updated', handleConversationUpdated);
        return () => window.removeEventListener('conversation-updated', handleConversationUpdated);
    }, [conversationId, fetchHistory]);

    const addOptimisticMessage = useCallback((text: string, senderName: string, targetConversationId?: string): string => {
        const id = makeTempId();
        const newMsg: Message = {
            id,
            text,
            timestamp: new Date(),
            senderName,
            status: "sending",
        };

        const targetCacheKey = buildMessageHistoryCacheKey(
            locationId || undefined,
            targetConversationId || conversationId,
            recipientKey
        );

        const currentCacheKey = cacheKeyRef.current;

        if (targetCacheKey && targetCacheKey !== currentCacheKey) {
            const cached = messageHistoryCache.get(targetCacheKey)?.messages || [];
            const nextMessages = [...cached, newMsg].sort(
                (a, b) => a.timestamp.getTime() - b.timestamp.getTime()
            );
            messageHistoryCache.set(targetCacheKey, { messages: nextMessages, fetchedAt: Date.now() });
            optimisticMessageTargets.current.set(id, targetCacheKey);
            return id;
        }

        if (targetCacheKey) {
            optimisticMessageTargets.current.set(id, targetCacheKey);
        }

        setMessages((prev) => {
            const nextMessages = [...prev, newMsg];
            if (currentCacheKey) {
                messageHistoryCache.set(currentCacheKey, { messages: nextMessages, fetchedAt: Date.now() });
            }
            return nextMessages;
        });
        return id;
    }, [locationId, conversationId, recipientKey]);

    const updateMessageStatus = useCallback((
        tempId: string,
        status: "sending" | "sent" | "failed",
        realId?: string,
        errorReason?: string
    ) => {
        const targetCacheKey = optimisticMessageTargets.current.get(tempId);
        const currentCacheKey = cacheKeyRef.current;

        if (targetCacheKey && targetCacheKey !== currentCacheKey) {
            const cached = messageHistoryCache.get(targetCacheKey)?.messages || [];
            const nextMessages = cached.map((m) =>
                m.id === tempId || (realId && m.id === realId)
                    ? { ...m, status, id: realId || m.id, errorReason }
                    : m
            );
            messageHistoryCache.set(targetCacheKey, { messages: nextMessages, fetchedAt: Date.now() });
            if (realId) optimisticMessageTargets.current.set(realId, targetCacheKey);
            return;
        }

        setMessages((prev) => {
            const nextMessages = prev.map((m) =>
                m.id === tempId || (realId && m.id === realId)
                    ? { ...m, status, id: realId || m.id, errorReason }
                    : m
            );
            if (currentCacheKey) {
                messageHistoryCache.set(currentCacheKey, { messages: nextMessages, fetchedAt: Date.now() });
            }
            return nextMessages;
        });

        if (realId && targetCacheKey) {
            optimisticMessageTargets.current.set(realId, targetCacheKey);
        }
    }, []);

    return {
        messages,
        loading: loading && isInitialLoad.current,
        error,
        errorStatus,
        addOptimisticMessage,
        updateMessageStatus,
        refresh: fetchHistory,
        conversation,
    };
};
