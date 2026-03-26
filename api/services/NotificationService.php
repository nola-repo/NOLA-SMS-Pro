<?php

/**
 * NotificationService — Dispatch logic for system notifications.
 *
 * Handles:
 * - Low balance alerts (with 24-hour circuit breaker)
 * - Delivery report notifications (Failed/Delivered)
 * - Email dispatch (placeholder — swap for SendGrid/Mailgun when ready)
 */
class NotificationService
{
    /**
     * Check if a low-balance alert should be sent after a credit deduction.
     *
     * Circuit breaker: only sends once per 24 hours while balance remains
     * at or below the threshold.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param int    $currentBalance  Balance AFTER the deduction
     */
    public static function checkLowBalance($db, string $locationId, int $currentBalance): void
    {
        $prefs = self::getPreferences($db, $locationId);

        if (!$prefs['low_balance_alert_enabled']) {
            return;
        }

        $threshold = $prefs['low_balance_threshold'];

        if ($currentBalance > $threshold) {
            return; // Balance is healthy — nothing to do
        }

        // ── Circuit breaker: check last notification time ───────────────
        $settingsRef = $db->collection('notification_settings')->document($locationId);
        $snap = $settingsRef->snapshot();

        if ($snap->exists()) {
            $lastNotified = $snap->data()['last_low_balance_notified_at'] ?? null;
            if ($lastNotified) {
                $lastTs = $lastNotified instanceof \Google\Cloud\Core\Timestamp
                    ? $lastNotified->get()->getTimestamp()
                    : (int) $lastNotified;

                // Suppress if notified within the last 24 hours
                if (time() - $lastTs < 86400) {
                    return;
                }
            }
        }

        // ── Send the alert ──────────────────────────────────────────────
        $email = self::getAccountEmail($db, $locationId);
        if (!$email) {
            error_log("[LowBalanceAlert] No email found for location {$locationId}, skipping notification.");
            return;
        }

        $subject = 'NOLA SMS Pro — Low Credit Balance Alert';
        $body = "Your SMS credit balance has dropped to {$currentBalance} credits, "
              . "which is at or below your alert threshold of {$threshold} credits.\n\n"
              . "Please top up your credits to avoid service interruption.\n\n"
              . "Location ID: {$locationId}";

        self::sendEmail($email, $subject, $body);

        // Update circuit breaker timestamp
        $settingsRef->set([
            'last_low_balance_notified_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
        ], ['merge' => true]);

        error_log("[LowBalanceAlert] Sent low balance alert for location {$locationId} (balance: {$currentBalance}, threshold: {$threshold})");
    }

    /**
     * Notify about a terminal message delivery status (Delivered/Failed).
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId
     * @param string $messageId   Semaphore message ID
     * @param string $status      Terminal status (Delivered, Failed)
     * @param string $recipient   Phone number of the recipient
     */
    public static function notifyDeliveryStatus($db, string $locationId, string $messageId, string $status, string $recipient): void
    {
        $prefs = self::getPreferences($db, $locationId);

        if (!$prefs['delivery_reports_enabled']) {
            return;
        }

        $email = self::getAccountEmail($db, $locationId);
        if (!$email) {
            return;
        }

        $statusLabel = ucfirst(strtolower($status));
        $subject = "NOLA SMS Pro — Message {$statusLabel}: {$recipient}";
        $body = "Delivery Report\n"
              . "───────────────\n"
              . "Recipient:  {$recipient}\n"
              . "Status:     {$statusLabel}\n"
              . "Message ID: {$messageId}\n"
              . "Location:   {$locationId}\n\n"
              . "This is an automated delivery notification from NOLA SMS Pro.";

        self::sendEmail($email, $subject, $body);

        error_log("[DeliveryReport] Sent {$statusLabel} report for message {$messageId} to {$email}");
    }

    /**
     * Load notification preferences from Firestore with defaults.
     *
     * @return array{delivery_reports_enabled: bool, low_balance_alert_enabled: bool, low_balance_threshold: int, marketing_emails_enabled: bool}
     */
    public static function getPreferences($db, string $locationId): array
    {
        $defaults = [
            'delivery_reports_enabled'  => false,
            'low_balance_alert_enabled' => true,
            'low_balance_threshold'     => 50,
            'marketing_emails_enabled'  => false,
        ];

        try {
            $snap = $db->collection('notification_settings')->document($locationId)->snapshot();
            if ($snap->exists()) {
                $d = $snap->data();
                return [
                    'delivery_reports_enabled'  => (bool) ($d['delivery_reports_enabled'] ?? $defaults['delivery_reports_enabled']),
                    'low_balance_alert_enabled' => (bool) ($d['low_balance_alert_enabled'] ?? $defaults['low_balance_alert_enabled']),
                    'low_balance_threshold'     => (int)  ($d['low_balance_threshold'] ?? $defaults['low_balance_threshold']),
                    'marketing_emails_enabled'  => (bool) ($d['marketing_emails_enabled'] ?? $defaults['marketing_emails_enabled']),
                ];
            }
        } catch (\Throwable $e) {
            error_log("[NotificationService] Failed to load preferences for {$locationId}: " . $e->getMessage());
        }

        return $defaults;
    }

    /**
     * Get the email address associated with this location.
     * Checks ghl_tokens first, then integrations collection.
     *
     * @return string|null
     */
    public static function getAccountEmail($db, string $locationId): ?string
    {
        // 1. Check ghl_tokens
        try {
            $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
            if ($tokenSnap->exists()) {
                $email = $tokenSnap->data()['email'] ?? $tokenSnap->data()['user_email'] ?? null;
                if ($email) return $email;
            }
        } catch (\Throwable $e) {}

        // 2. Fallback to integrations
        try {
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
            $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
            if ($intSnap->exists()) {
                $email = $intSnap->data()['email'] ?? $intSnap->data()['user_email'] ?? null;
                if ($email) return $email;
            }
        } catch (\Throwable $e) {}

        return null;
    }

    /**
     * Send an email notification.
     *
     * PLACEHOLDER: Currently logs inline. Replace this method body with
     * SendGrid/Mailgun/SES when an email provider is configured.
     *
     * @param string $to      Recipient email
     * @param string $subject Email subject
     * @param string $body    Plain-text body
     */
    public static function sendEmail(string $to, string $subject, string $body): void
    {
        // TODO: Replace with actual email provider (SendGrid, Mailgun, etc.)
        // For now, log the email so it appears in Cloud Run logs.
        error_log("[NotificationService::sendEmail] TO: {$to} | SUBJECT: {$subject}");
        error_log("[NotificationService::sendEmail] BODY: {$body}");
    }
}
