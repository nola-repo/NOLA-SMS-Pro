<?php

/**
 * Pure response/state helpers for the location bootstrap endpoint.
 *
 * The frontend intentionally treats this contract as the single gate before
 * loading account, contacts, conversations, notifications, or templates.
 */
final class LocationBootstrapService
{
    public const ACTION_LOAD_APP = 'load_app';
    public const ACTION_RUN_AUTOLOGIN = 'run_autologin';
    public const ACTION_COMPLETE_REGISTRATION = 'complete_registration';
    public const ACTION_SHOW_NOT_INSTALLED = 'show_not_installed';
    public const ACTION_SHOW_RECONNECT = 'show_reconnect';
    public const ACTION_SHOW_RETRY = 'show_retry';

    public static function isValidLocationId(?string $locationId): bool
    {
        $locationId = trim((string)$locationId);

        return $locationId !== ''
            && preg_match('/^[A-Za-z0-9_-]{12,80}$/', $locationId) === 1;
    }

    /**
     * @param array<string,mixed> $classification
     * @param array<string,mixed> $tokenData
     * @return array{http_status:int,code:string,next_action:string,message:string}|null
     */
    public static function installBlock(array $classification, array $tokenData): ?array
    {
        $status = (string)($classification['status'] ?? '');
        $storedState = (string)($classification['install_state'] ?? ($tokenData['install_state'] ?? ''));

        if (($tokenData['cleanup_in_progress'] ?? false) === true) {
            return [
                'http_status' => 423,
                'code' => 'LOCATION_CLEANUP_IN_PROGRESS',
                'next_action' => self::ACTION_SHOW_RETRY,
                'message' => 'This test workspace is temporarily unavailable while cleanup is in progress.',
            ];
        }

        if ($storedState === 'ONBOARDING_EXPIRED') {
            return [
                'http_status' => 410,
                'code' => 'LOCATION_ONBOARDING_EXPIRED',
                'next_action' => self::ACTION_SHOW_NOT_INSTALLED,
                'message' => 'The onboarding session expired. Restart installation from the GHL Marketplace.',
            ];
        }

        if ($storedState === 'UNINSTALLED' || (array_key_exists('is_live', $tokenData) && $tokenData['is_live'] === false)) {
            return [
                'http_status' => 403,
                'code' => 'LOCATION_UNINSTALLED',
                'next_action' => self::ACTION_SHOW_NOT_INSTALLED,
                'message' => 'NOLA SMS Pro is not active for this GHL location. Reinstall the app from the Marketplace.',
            ];
        }

        if ($status === 'COMPANY_MISMATCH') {
            return [
                'http_status' => 409,
                'code' => 'LOCATION_COMPANY_MISMATCH',
                'next_action' => self::ACTION_SHOW_RETRY,
                'message' => 'This location is linked to a different GHL company.',
            ];
        }

        if ($status === 'INSTALL_PENDING') {
            return [
                'http_status' => 503,
                'code' => 'LOCATION_INSTALL_PENDING',
                'next_action' => self::ACTION_SHOW_RETRY,
                'message' => 'The GHL installation is still being finalized. Try again shortly.',
            ];
        }

        if ($status === 'TOKEN_ONLY') {
            return [
                'http_status' => 409,
                'code' => 'LOCATION_REGISTRATION_REQUIRED',
                'next_action' => self::ACTION_COMPLETE_REGISTRATION,
                'message' => 'Complete NOLA SMS Pro registration for this installed GHL location.',
            ];
        }

        if ($status === 'FRESH_INSTALL' || $status === '') {
            return [
                'http_status' => 404,
                'code' => 'LOCATION_NOT_INSTALLED',
                'next_action' => self::ACTION_SHOW_NOT_INSTALLED,
                'message' => 'NOLA SMS Pro is not installed for this GHL location.',
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function payload(
        string $locationId,
        string $code,
        string $nextAction,
        string $message,
        array $extra = []
    ): array {
        return array_merge([
            'success' => $nextAction === self::ACTION_LOAD_APP,
            'status' => $nextAction === self::ACTION_LOAD_APP ? 'ready' : 'action_required',
            'code' => $code,
            'message' => $message,
            'location_id' => $locationId,
            'contacts_can_load' => $nextAction === self::ACTION_LOAD_APP,
            'next_action' => $nextAction,
            'requires_autologin' => $nextAction === self::ACTION_RUN_AUTOLOGIN,
            'requires_reconnect' => $nextAction === self::ACTION_SHOW_RECONNECT,
        ], $extra);
    }
}
