<?php

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../install_helpers.php';
require_once __DIR__ . '/../services/GhlClient.php';
require_once __DIR__ . '/../services/LocationBootstrapService.php';

/** @param array<string,mixed> $payload */
function location_bootstrap_respond(int $status, array $payload): void
{
    http_response_code($status);
    Logger::response($status, [
        'code' => $payload['code'] ?? null,
        'location_id' => $payload['location_id'] ?? null,
        'next_action' => $payload['next_action'] ?? null,
    ]);
    echo json_encode($payload);
    exit;
}

/** @param array<string,mixed> $extra */
function location_bootstrap_error(
    int $status,
    string $locationId,
    string $code,
    string $nextAction,
    string $message,
    array $extra = []
): void {
    location_bootstrap_respond(
        $status,
        LocationBootstrapService::payload($locationId, $code, $nextAction, $message, $extra)
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    location_bootstrap_error(
        405,
        '',
        'METHOD_NOT_ALLOWED',
        LocationBootstrapService::ACTION_SHOW_RETRY,
        'Only GET is supported for location bootstrap.'
    );
}

$locationId = trim((string)(get_ghl_location_id() ?? ''));
if (!LocationBootstrapService::isValidLocationId($locationId)) {
    location_bootstrap_error(
        422,
        $locationId,
        'INVALID_GHL_LOCATION_ID',
        LocationBootstrapService::ACTION_SHOW_RETRY,
        'A valid GHL location_id is required.'
    );
}

try {
    $db = get_firestore();
    $lookup = auth_lookup_installed_location($db, $locationId);
    $tokenData = is_array($lookup['token_data'] ?? null) ? $lookup['token_data'] : [];
    $integrationData = is_array($lookup['integration_data'] ?? null) ? $lookup['integration_data'] : [];

    if (empty($lookup['installed'])) {
        location_bootstrap_error(
            404,
            $locationId,
            'LOCATION_NOT_INSTALLED',
            LocationBootstrapService::ACTION_SHOW_NOT_INSTALLED,
            'NOLA SMS Pro is not installed for this GHL location.',
            ['install_status' => 'not_installed']
        );
    }

    $companyId = trim((string)(
        $tokenData['companyId']
        ?? $tokenData['company_id']
        ?? $integrationData['companyId']
        ?? $integrationData['company_id']
        ?? ''
    ));
    $classification = install_classify_location(
        $db,
        $locationId,
        $companyId !== '' ? $companyId : null
    );
    $installBlock = LocationBootstrapService::installBlock($classification, $tokenData);

    if ($installBlock !== null) {
        $extra = [
            'install_status' => strtolower((string)($classification['status'] ?? 'unknown')),
            'install_state' => $classification['install_state'] ?? null,
            'token_exists' => !empty($classification['token_exists']),
        ];

        if ($installBlock['next_action'] === LocationBootstrapService::ACTION_COMPLETE_REGISTRATION) {
            // Do not mint install JWTs from an unauthenticated location lookup.
            // The Marketplace callback remains the authority that creates the
            // signed registration URL after OAuth/location selection.
            $extra['registration_required'] = true;
        }

        location_bootstrap_error(
            $installBlock['http_status'],
            $locationId,
            $installBlock['code'],
            $installBlock['next_action'],
            $installBlock['message'],
            $extra
        );
    }

    // Invalid, expired, absent, or location-stale sessions all converge here.
    // The frontend will call the existing location autologin once, save the new
    // JWT, then repeat this bootstrap request.
    $jwtCtx = auth_get_optional_jwt_context($db, false);
    if ($jwtCtx === null) {
        location_bootstrap_error(
            401,
            $locationId,
            'GHL_AUTOLOGIN_REQUIRED',
            LocationBootstrapService::ACTION_RUN_AUTOLOGIN,
            'A location-scoped session is required.',
            [
                'install_status' => 'registered',
                'ownership_source' => $classification['linked_account']['source'] ?? null,
            ]
        );
    }

    $profile = is_array($jwtCtx['profile'] ?? null) ? $jwtCtx['profile'] : [];
    if (array_key_exists('active', $profile) && empty($profile['active'])) {
        location_bootstrap_error(
            403,
            $locationId,
            'LOCATION_INACTIVE',
            LocationBootstrapService::ACTION_SHOW_RETRY,
            'This NOLA SMS Pro account is inactive.'
        );
    }

    $role = strtolower(trim((string)(
        $jwtCtx['payload']['role']
        ?? $profile['role']
        ?? 'user'
    )));
    $sessionAllowed = false;

    if ($role === 'agency' || ($jwtCtx['firestore_collection'] ?? '') === 'agency_users') {
        $sessionCompanyId = trim((string)(
            $profile['company_id']
            ?? $jwtCtx['payload']['company_id']
            ?? ''
        ));
        $sessionAllowed = $sessionCompanyId !== ''
            && auth_location_belongs_to_company($db, $locationId, $sessionCompanyId);
    } else {
        $email = trim((string)($profile['email'] ?? $jwtCtx['payload']['email'] ?? ''));
        $sessionAllowed = auth_user_linked_to_location(
            $db,
            $jwtCtx,
            $locationId
        );

        // auth_user_linked_to_location deliberately centralizes profile fields,
        // canonical ownership, and additional linked-user membership checks.
        if (!$sessionAllowed && $email !== '') {
            $sessionAllowed = install_user_linked_to_location(
                $db,
                (string)($jwtCtx['uid'] ?? ''),
                $locationId,
                $email
            );
        }
    }

    if (!$sessionAllowed) {
        location_bootstrap_error(
            401,
            $locationId,
            'LOCATION_SESSION_MISMATCH',
            LocationBootstrapService::ACTION_RUN_AUTOLOGIN,
            'The current session belongs to a different GHL location.',
            ['requires_reauth' => true]
        );
    }

    try {
        // Always initialize against the requested location registry. If only a
        // company token or a legacy integration exists, GhlTokenProvider repairs
        // and backfills ghl_tokens/{locationId} before protected data is opened.
        $client = new GhlClient($db, $locationId, $locationId);
        $tokenHealth = $client->ensureReady();
    } catch (GhlOAuthRefreshException $e) {
        if ($e->shouldPromptReconnect()) {
            location_bootstrap_error(
                409,
                $locationId,
                'GHL_RECONNECT_REQUIRED',
                LocationBootstrapService::ACTION_SHOW_RECONNECT,
                'The GHL connection for this location must be refreshed.',
                ['requires_reconnect' => true]
            );
        }

        location_bootstrap_error(
            503,
            $locationId,
            'GHL_TOKEN_TEMPORARILY_UNAVAILABLE',
            LocationBootstrapService::ACTION_SHOW_RETRY,
            'The GHL token could not be refreshed yet. Try again shortly.'
        );
    } catch (RuntimeException $e) {
        error_log('[location/bootstrap] token initialization failed for ' . $locationId . ': ' . $e->getMessage());
        location_bootstrap_error(
            409,
            $locationId,
            'GHL_RECONNECT_REQUIRED',
            LocationBootstrapService::ACTION_SHOW_RECONNECT,
            'No usable GHL connection was found for this installed location.',
            ['requires_reconnect' => true]
        );
    }

    location_bootstrap_respond(200, LocationBootstrapService::payload(
        $locationId,
        'LOCATION_READY',
        LocationBootstrapService::ACTION_LOAD_APP,
        'Workspace verified.',
        [
            'install_status' => 'registered',
            'role' => $role,
            'company_id' => $companyId !== '' ? $companyId : null,
            'ownership_source' => $classification['linked_account']['source'] ?? null,
            'token_ready' => true,
            'token_refreshed' => !empty($tokenHealth['refreshed']),
        ]
    ));
} catch (Throwable $e) {
    error_log('[location/bootstrap] unexpected failure for ' . $locationId . ': ' . $e->getMessage());
    location_bootstrap_error(
        503,
        $locationId,
        'LOCATION_BOOTSTRAP_FAILED',
        LocationBootstrapService::ACTION_SHOW_RETRY,
        'Workspace verification is temporarily unavailable.'
    );
}
