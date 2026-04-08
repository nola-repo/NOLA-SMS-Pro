import { useState, useEffect, useCallback, useRef } from "react";
import { fetchBatchMessages } from "../api/sms";
import type { SmsLog } from "../types/Sms";

export const useBatchMessages = (batchId?: string) => {
    const [messages, setMessages] = useState<SmsLog[]>([]);
    const [loading, setLoading] = useState(false);
    const initialLoadDone = useRef(false);

    const refresh = useCallback(async (showLoading = false) => {
        if (!batchId) {
            setMessages([]);
            return;
        }

        if (showLoading) setLoading(true);
        try {
            const data = await fetchBatchMessages(batchId);
            const mappedData = data.map((log: SmsLog) => {
                let mappedStatus = (log.status || 'sent').toLowerCase();
                if (mappedStatus === 'pending' || mappedStatus === 'queued') {
                    mappedStatus = 'sent';
                }
                return { ...log, status: mappedStatus };
            });
            setMessages(mappedData);
        } catch (error) {
            console.error("Failed to fetch batch messages:", error);
        } finally {
            if (showLoading) setLoading(false);
            initialLoadDone.current = true;
        }
    }, [batchId]);

    useEffect(() => {
        initialLoadDone.current = false;
        refresh(true);

        if (!batchId) return;

        // Background polling every 5 seconds
        const interval = setInterval(() => {
            refresh(false);
        }, 5000);

        return () => clearInterval(interval);
    }, [batchId, refresh]);

    return {
        messages,
        loading: loading && !initialLoadDone.current,
        refresh
    };
};
