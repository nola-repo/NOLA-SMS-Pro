import { useState, useEffect, useCallback, useRef } from 'react';
import { adminFetch } from '../utils/adminApi';
import { getAdminAuthHeaders } from '../utils/adminAuthHeaders';

const ADMIN_NOTIFICATIONS_API = '/api/admin_notifications.php';
const POLL_INTERVAL = 60_000; // 60 seconds

export type AdminNotification = {
    id: string;
    type: 'low_balance' | 'zero_balance';
    location_id: string;
    location_name: string;
    email: string;
    balance: number;
    threshold: number;
    created_at: string;
    read: boolean;
};

export function useAdminNotifications() {
    const [notifications, setNotifications] = useState<AdminNotification[]>([]);
    const [loading, setLoading] = useState(true);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const fetchNotifications = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        try {
            const res = await adminFetch(`${ADMIN_NOTIFICATIONS_API}?limit=30`, {
                headers: getAdminAuthHeaders(),
            });
            if (!res.ok) return;
            const json = await res.json();
            if (json.status === 'success') {
                setNotifications(json.data || []);
            }
        } catch {
            // Silently fail – bell just shows stale data
        } finally {
            if (isInitial) setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchNotifications(true);
        timerRef.current = setInterval(() => fetchNotifications(false), POLL_INTERVAL);
        return () => {
            if (timerRef.current) clearInterval(timerRef.current);
        };
    }, [fetchNotifications]);

    const markRead = useCallback(async (notificationId: string) => {
        // Optimistic update
        setNotifications(prev =>
            prev.map(n => (n.id === notificationId ? { ...n, read: true } : n))
        );
        try {
            const res = await adminFetch(ADMIN_NOTIFICATIONS_API, {
                method: 'POST',
                headers: { ...getAdminAuthHeaders(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_read', notification_id: notificationId }),
            });
            if (!res.ok) throw new Error('mark_read failed');
        } catch {
            // Revert the optimistic update on failure
            setNotifications(prev =>
                prev.map(n => (n.id === notificationId ? { ...n, read: false } : n))
            );
        }
    }, []);

    const markAllRead = useCallback(async () => {
        const previous = await new Promise<AdminNotification[]>(resolve =>
            setNotifications(prev => { resolve(prev); return prev.map(n => ({ ...n, read: true })); })
        );
        try {
            const res = await adminFetch(ADMIN_NOTIFICATIONS_API, {
                method: 'POST',
                headers: { ...getAdminAuthHeaders(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' }),
            });
            if (!res.ok) throw new Error('mark_all_read failed');
        } catch {
            // Revert the optimistic update on failure
            setNotifications(previous);
        }
    }, []);

    const unreadCount = notifications.filter(n => !n.read).length;

    return { notifications, loading, unreadCount, markRead, markAllRead, refetch: fetchNotifications };
}
