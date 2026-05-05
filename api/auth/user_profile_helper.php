<?php

/**
 * Shared user profile shaping for JSON APIs (login, register-from-install, /me).
 * Frontend expects name + optional firstName/lastName (agency app, Settings self-heal).
 */

/**
 * Split full name on first whitespace; single token → lastName empty.
 *
 * @return array{firstName: string, lastName: string}
 */
function auth_split_full_name(string $full): array
{
    $full = trim($full);
    if ($full === '') {
        return ['firstName' => '', 'lastName' => ''];
    }
    if (!preg_match('/\s/u', $full)) {
        return ['firstName' => $full, 'lastName' => ''];
    }
    $parts = preg_split('/\s+/u', $full, 2);
    return [
        'firstName' => $parts[0] ?? '',
        'lastName'  => $parts[1] ?? '',
    ];
}

function auth_resolve_display_name(array $d, string $fallbackEmail = ''): string
{
    $n = $d['name'] ?? null;
    if ($n !== null && trim((string) $n) !== '') {
        return trim((string) $n);
    }
    $joined = trim(($d['firstName'] ?? '') . ' ' . ($d['lastName'] ?? ''));
    if ($joined !== '') {
        return $joined;
    }
    return $fallbackEmail !== '' ? $fallbackEmail : '';
}

/**
 * Prefer stored firstName/lastName; otherwise derive from resolved display name.
 *
 * @return array{firstName: string, lastName: string}
 */
function auth_first_last_from_doc(array $d, string $resolvedName): array
{
    $fn = isset($d['firstName']) ? trim((string) $d['firstName']) : '';
    $ln = isset($d['lastName']) ? trim((string) $d['lastName']) : '';
    if ($fn !== '' || $ln !== '') {
        return ['firstName' => $fn, 'lastName' => $ln];
    }
    return auth_split_full_name($resolvedName);
}

/**
 * Normalized `user` object for API responses.
 * Firestore docs use active_location_id; API uses location_id.
 *
 * @param array $d Firestore user fields (may include active_location_id, name, firstName, …)
 */
function auth_user_payload_for_api(array $d, string $emailFallback = ''): array
{
    $email = isset($d['email']) ? (string) $d['email'] : $emailFallback;
    $resolvedName = auth_resolve_display_name($d, $email);
    $fl = auth_first_last_from_doc($d, $resolvedName);

    return [
        'name'                 => $resolvedName,
        'firstName'            => $fl['firstName'],
        'lastName'             => $fl['lastName'],
        'email'                => $email,
        'phone'                => isset($d['phone']) ? (string) $d['phone'] : '',
        'location_id'          => $d['active_location_id'] ?? null,
        'company_id'           => $d['company_id'] ?? null,
        'location_name'        => $d['location_name'] ?? null,
        'company_name'         => $d['company_name'] ?? null,
        'location_memberships' => $d['location_memberships'] ?? [],
    ];
}
