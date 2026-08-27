<?php

/**
 * Public request URL helpers.
 *
 * Cloud Run terminates TLS in front of Apache, so HTTPS and the public host
 * must come from forwarded headers or APP_BASE_URL — never from an assumed
 * production domain.
 */

function nola_request_host(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
    if (is_string($forwarded) && trim($forwarded) !== '') {
        $host = strtolower(trim(explode(',', $forwarded)[0]));
        if ($host !== '') {
            return $host;
        }
    }

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return $host !== '' ? $host : 'smspro-api.nolacrm.io';
}

function nola_request_scheme(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwarded) && trim($forwarded) !== '') {
        $proto = strtolower(trim(explode(',', $forwarded)[0]));
        if ($proto === 'http' || $proto === 'https') {
            return $proto;
        }
    }

    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return 'https';
    }

    // Cloud Run and this image listen on HTTP internally; the edge is HTTPS.
    if (getenv('K_SERVICE') || getenv('PORT')) {
        return 'https';
    }

    return 'http';
}

function nola_public_base_url(): string
{
    $configured = trim((string)(getenv('APP_BASE_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return rtrim(nola_request_scheme() . '://' . nola_request_host(), '/');
}

function nola_is_staging(): bool
{
    $service = strtolower((string)(getenv('K_SERVICE') ?: getenv('CLOUD_RUN_SERVICE') ?: ''));
    if ($service !== '') {
        return str_contains($service, 'staging');
    }

    $appEnv = strtolower((string)(getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: ''));
    if (in_array($appEnv, ['staging', 'stage'], true)) {
        return true;
    }

    return str_contains(nola_request_host(), 'staging');
}

function nola_frontend_app_url(): string
{
    $configured = trim((string)(getenv('FRONTEND_APP_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (nola_is_staging()) {
        return 'https://nolasmspro-frontend-staging-116662437564.asia-southeast1.run.app';
    }

    return 'https://app.nolasmspro.com';
}

function nola_agency_app_url(): string
{
    $configured = trim((string)(getenv('AGENCY_APP_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (nola_is_staging()) {
        return 'https://nolasmspro-agency-staging-116662437564.asia-southeast1.run.app';
    }

    return 'https://agency.nolasmspro.com';
}

function nola_ghl_oauth_redirect_uri(): string
{
    $configured = trim((string)(getenv('GHL_REDIRECT_URI') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return nola_public_base_url() . '/oauth/callback';
}
