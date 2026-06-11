<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use App\Support\LegacyAuthProfile;
use App\Support\LegacyJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AuthV2Controller extends Controller
{
    public function __construct(private readonly FirestoreService $firestore)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $rememberMe = $request->boolean('remember_me') || $request->boolean('rememberMe');

        if ($email === '' || $password === '') {
            return response()->json(['error' => 'Email and password are required.'], 400);
        }

        $jwtSecret = trim((string) env('JWT_SECRET', ''));
        if ($jwtSecret === '') {
            return response()->json(['error' => 'Server misconfiguration: JWT secret missing.'], 500);
        }

        try {
            $db = $this->firestore->db();
            [$userId, $userData, $authCollection] = $this->findUserByEmail($db, $email);

            if ($userData === null || $userId === null) {
                return response()->json(['error' => 'Invalid email or password.'], 401);
            }

            $storedHash = (string) ($userData['password_hash'] ?? '');
            if (!password_verify($password, $storedHash)) {
                return response()->json(['error' => 'Invalid email or password.'], 401);
            }

            if (empty($userData['active'])) {
                return response()->json(['error' => 'Your account has been deactivated.'], 403);
            }

            $role = $userData['role'] ?? 'user';
            $companyId = $userData['company_id'] ?? null;
            $locationId = $userData['active_location_id'] ?? null;

            $tokenTtl = $rememberMe ? 60 * 60 * 24 * 30 : 28800;
            $token = LegacyJwt::sign([
                'sub' => $userId,
                'email' => $email,
                'role' => $role,
                'company_id' => $companyId,
                'location_id' => $locationId,
                'auth_collection' => $authCollection,
            ], $jwtSecret, $tokenTtl);

            return response()->json([
                'token' => $token,
                'role' => $role,
                'company_id' => $companyId,
                'location_id' => $locationId,
                'expires_in' => $tokenTtl,
                'remembered' => $rememberMe,
                'user' => LegacyAuthProfile::payloadForApi($userData, $email),
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Login failed: ' . $e->getMessage()], 500);
        }
    }

    public function me(Request $request): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return response()->json(['error' => 'Missing auth token. Provide Authorization: Bearer <token>.'], 401);
        }

        $jwtSecret = trim((string) env('JWT_SECRET', ''));
        if ($jwtSecret === '') {
            return response()->json(['error' => 'Server misconfiguration: JWT secret missing.'], 500);
        }

        $payload = LegacyJwt::verify($token, $jwtSecret);
        if (!$payload) {
            return response()->json(['error' => 'Token is invalid or expired.'], 401);
        }

        $userId = $payload['sub'] ?? null;
        if (!$userId) {
            return response()->json(['error' => 'Invalid token payload.'], 401);
        }

        try {
            $db = $this->firestore->db();
            $role = (string) ($payload['role'] ?? 'user');
            $authCollection = (string) ($payload['auth_collection'] ?? '');
            $collection = $authCollection !== '' ? $authCollection : ($role === 'agency' ? 'agency_users' : 'users');

            $snap = $db->collection($collection)->document((string) $userId)->snapshot();
            if (!$snap->exists() && $collection !== 'users') {
                $collection = 'users';
                $snap = $db->collection($collection)->document((string) $userId)->snapshot();
            }

            if (!$snap->exists()) {
                return response()->json(['error' => 'User not found.'], 404);
            }

            $doc = $snap->data();
            if ($role === 'agency' && empty($doc['company_id'])) {
                $jwtCompanyId = trim((string) ($payload['company_id'] ?? ''));
                if ($jwtCompanyId !== '') {
                    $doc['company_id'] = $jwtCompanyId;
                }
            }

            $fallbackNames = ['No Agency', 'Unnamed Agency', 'Unknown Agency', 'Unknown'];
            $existingCompanyName = trim((string) ($doc['company_name'] ?? ''));
            $needsCompanyNameResolution = $role === 'agency' && (
                $existingCompanyName === '' ||
                in_array($existingCompanyName, $fallbackNames, true)
            );
            if ($needsCompanyNameResolution) {
                $companyName = $this->resolveAgencyCompanyName($db, $doc);
                if ($companyName !== null) {
                    $doc['company_name'] = $companyName;
                }
            }

            $subaccounts = [];
            if ($collection === 'users') {
                try {
                    $subDocs = $db->collection('users')->document((string) $userId)->collection('subaccounts')->documents();
                    foreach ($subDocs as $subDoc) {
                        if (!$subDoc->exists()) {
                            continue;
                        }
                        $subData = $subDoc->data();
                        if (!isset($subData['id'])) {
                            $subData['id'] = $subDoc->id();
                        }
                        $subaccounts[] = $subData;
                    }
                } catch (Throwable) {
                    // Keep compatibility with legacy endpoint's best-effort behavior.
                }
            }

            return response()->json([
                'user' => LegacyAuthProfile::payloadForApi($doc),
                'subaccounts' => $subaccounts,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to fetch profile: ' . $e->getMessage()], 500);
        }
    }

    private function findUserByEmail($db, string $email): array
    {
        $results = $db->collection('agency_users')
            ->where('email', '=', $email)
            ->limit(1)
            ->documents();

        foreach ($results as $doc) {
            if ($doc->exists()) {
                return [$doc->id(), $doc->data(), 'agency_users'];
            }
        }

        $results = $db->collection('users')
            ->where('email', '=', $email)
            ->limit(1)
            ->documents();

        foreach ($results as $doc) {
            if ($doc->exists()) {
                return [$doc->id(), $doc->data(), 'users'];
            }
        }

        return [null, null, 'users'];
    }

    private function resolveAgencyCompanyName($db, array $doc): ?string
    {
        $companyId = trim((string) ($doc['company_id'] ?? ''));
        if ($companyId === '') {
            return null;
        }

        foreach (['ghl_agency_tokens', 'ghl_tokens'] as $collection) {
            try {
                $snap = $db->collection($collection)->document($companyId)->snapshot();
                if (!$snap->exists()) {
                    continue;
                }
                $tokenData = $snap->data();
                $companyName = $tokenData['company_name']
                    ?? $tokenData['companyName']
                    ?? $tokenData['agency_name']
                    ?? $tokenData['location_name']
                    ?? null;
                if ($companyName !== null && trim((string) $companyName) !== '') {
                    return trim((string) $companyName);
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $headerCandidates = [
            (string) $request->server('HTTP_AUTHORIZATION', ''),
            (string) $request->server('REDIRECT_HTTP_AUTHORIZATION', ''),
            (string) $request->server('Authorization', ''),
            (string) $request->server('HTTP_X_AUTHORIZATION', ''),
            (string) $request->server('HTTP_X_AUTH_TOKEN', ''),
            (string) $request->header('Authorization', ''),
            (string) $request->header('authorization', ''),
            (string) $request->header('X-Authorization', ''),
            (string) $request->header('X-Auth-Token', ''),
        ];

        foreach ($headerCandidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (preg_match('/^Bearer\s+(.+)$/i', trim($candidate), $m)) {
                return trim((string) $m[1]);
            }
            if (substr_count($candidate, '.') === 2) {
                return trim($candidate);
            }
        }

        $queryToken = trim((string) $request->query('token', ''));
        if ($queryToken !== '') {
            return $queryToken;
        }

        foreach (['nola_auth_token', 'auth_token', 'token'] as $cookieName) {
            $cookieToken = trim((string) $request->cookie($cookieName, ''));
            if ($cookieToken !== '') {
                return $cookieToken;
            }
        }

        return null;
    }
}
