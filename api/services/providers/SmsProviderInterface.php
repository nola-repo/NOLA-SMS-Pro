<?php

interface SmsProviderInterface
{
    /**
     * Sends a single SMS message.
     *
     * @param string $number Target phone number
     * @param string $message Message body
     * @param string $senderId Registered sender name/ID
     * @param string|null $apiKey Custom provider API key
     * @return array Response payload from provider
     */
    public function sendSingle(string $number, string $message, string $senderId, ?string $apiKey = null): array;

    /**
     * Sends the same SMS message to multiple recipients in bulk.
     *
     * @param array $numbers Array of target phone numbers
     * @param string $message Message body
     * @param string $senderId Registered sender name/ID
     * @param string|null $apiKey Custom provider API key
     * @return array Normalized list of results containing 'message_id', 'status', and 'recipient'
     */
    public function sendBulk(array $numbers, string $message, string $senderId, ?string $apiKey = null): array;

    /**
     * Retrieves status of a single message ID from provider API.
     *
     * @param string $messageId Provider's message ID/reference ID
     * @param string|null $apiKey Custom provider API key
     * @return array Parsed status response containing 'status'
     */
    public function checkStatus(string $messageId, ?string $apiKey = null): array;

    /**
     * Queries provider account status and balance.
     *
     * @param string|null $apiKey Custom provider API key
     * @return array Account data containing status and credits/balance
     */
    public function checkAccount(?string $apiKey = null): array;

    /**
     * Maps raw provider status string to one of: queued|sending|sent|failed.
     *
     * @param string $rawStatus Raw status string from provider
     * @return string Normalized status
     */
    public function normalizeStatus(string $rawStatus): string;
}
