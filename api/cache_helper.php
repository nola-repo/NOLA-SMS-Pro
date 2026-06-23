<?php

/**
 * cache_helper.php
 *
 * Lightweight caching helper utility for NOLA SMS Pro backend.
 * Integrates Redis if configured and extension is available, otherwise
 * falls back to a highly compatible file-based cache.
 */

class NolaCache
{
    private static ?Redis $redis = null;
    private static ?string $fileCacheDir = null;
    private static bool $initialized = false;

    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // 1. Attempt to initialize Redis if extension exists
        if (class_exists('Redis')) {
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            $password = getenv('REDIS_PASSWORD') ?: null;

            try {
                $redis = new Redis();
                // Set short timeout to avoid blocking if Redis is down
                if ($redis->connect($host, $port, 1.0)) {
                    if ($password) {
                        $redis->auth($password);
                    }
                    self::$redis = $redis;
                }
            } catch (\Throwable $e) {
                error_log("[NolaCache] Redis connection failed, falling back to file cache: " . $e->getMessage());
            }
        }

        // 2. Set up file cache directory as fallback
        self::$fileCacheDir = __DIR__ . '/cache/data';
        if (!is_dir(self::$fileCacheDir)) {
            @mkdir(self::$fileCacheDir, 0777, true);
        }

        self::$initialized = true;
    }

    /**
     * Get a cached value by key.
     */
    public static function get(string $key): mixed
    {
        self::init();

        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);

        if (self::$redis) {
            try {
                $val = self::$redis->get($safeKey);
                return $val !== false ? unserialize($val) : null;
            } catch (\Throwable $e) {
                error_log("[NolaCache] Redis GET error: " . $e->getMessage());
            }
        }

        // File cache fallback
        $file = self::$fileCacheDir . '/' . md5($safeKey) . '.cache';
        if (!file_exists($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = @unserialize($content);
        if (!$data || !is_array($data) || !isset($data['expire']) || !isset($data['value'])) {
            @unlink($file);
            return null;
        }

        if (time() > $data['expire']) {
            @unlink($file);
            return null;
        }

        return $data['value'];
    }

    /**
     * Send conservative HTTP cache headers for API responses backed by NolaCache.
     */
    public static function sendApiCacheHeaders(int $ttl, bool|string $hit = false): void
    {
        if (headers_sent()) {
            return;
        }

        $status = self::cacheStatus($hit);
        header('Cache-Control: private, max-age=' . max(0, $ttl) . ', stale-while-revalidate=30');
        header('X-Nola-Cache: ' . $status);
        header('Vary: Origin, Authorization, X-GHL-Location-ID, X-GHL-LocationID, X-Agency-ID', false);
    }

    /**
     * Add a consistent cache metadata envelope to JSON response payloads.
     */
    public static function withCacheMeta(
        array $payload,
        int $ttl,
        bool|string $hit = false,
        ?string $scope = null,
        bool $stale = false,
        ?string $generatedAt = null
    ): array {
        $status = self::cacheStatus($hit);
        $isCached = in_array($status, ['HIT', 'STALE'], true) || $stale;

        $payload['cached'] = $isCached;
        if ($stale) {
            $payload['stale'] = true;
        }

        $existingMeta = isset($payload['meta']) && is_array($payload['meta'])
            ? $payload['meta']
            : [];

        $existingCacheMeta = isset($existingMeta['cache']) && is_array($existingMeta['cache'])
            ? $existingMeta['cache']
            : [];

        $existingMeta['cache'] = array_filter([
            'status' => $stale ? 'STALE' : $status,
            'cached' => $isCached,
            'stale' => $stale,
            'generated_at' => $generatedAt ?: ($existingCacheMeta['generated_at'] ?? gmdate('c')),
            'cache_ttl' => max(0, $ttl),
            'cache_key_scope' => $scope,
        ], static fn($value) => $value !== null);

        $payload['meta'] = $existingMeta;
        return $payload;
    }

    private static function cacheStatus(bool|string $hit): string
    {
        if (is_string($hit)) {
            $status = strtoupper(trim($hit));
            return $status !== '' ? $status : 'MISS';
        }

        return $hit ? 'HIT' : 'MISS';
    }

    /**
     * Set a cached value by key.
     */
    public static function set(string $key, mixed $value, int $ttl = 300): bool
    {
        self::init();

        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        $serialized = serialize($value);

        if (self::$redis) {
            try {
                return self::$redis->set($safeKey, $serialized, $ttl);
            } catch (\Throwable $e) {
                error_log("[NolaCache] Redis SET error: " . $e->getMessage());
            }
        }

        // File cache fallback
        $file = self::$fileCacheDir . '/' . md5($safeKey) . '.cache';
        $data = [
            'expire' => time() + $ttl,
            'value' => $value
        ];

        return @file_put_contents($file, serialize($data)) !== false;
    }

    /**
     * Delete/Invalidate a cached value.
     */
    public static function delete(string $key): bool
    {
        self::init();

        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);

        if (self::$redis) {
            try {
                self::$redis->del($safeKey);
                return true;
            } catch (\Throwable $e) {
                error_log("[NolaCache] Redis DEL error: " . $e->getMessage());
            }
        }

        // File cache fallback
        $file = self::$fileCacheDir . '/' . md5($safeKey) . '.cache';
        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * Set a cached value and register it under a registry key (e.g. for a location ID)
     * so that all keys in the registry can be flushed together later.
     */
    public static function setWithRegistry(string $registryKey, string $key, mixed $value, int $ttl = 300): bool
    {
        $setOk = self::set($key, $value, $ttl);
        if (!$setOk) {
            return false;
        }

        $registry = self::get($registryKey) ?: [];
        if (!is_array($registry)) {
            $registry = [];
        }

        if (!in_array($key, $registry, true)) {
            $registry[] = $key;
            self::set($registryKey, $registry, 86400); // Registry lives up to 24 hours
        }

        return true;
    }

    /**
     * Flush all cached keys registered under the given registry key.
     */
    public static function deleteRegistry(string $registryKey): bool
    {
        $registry = self::get($registryKey);
        if (is_array($registry)) {
            foreach ($registry as $key) {
                self::delete($key);
            }
        }
        self::delete($registryKey);
        return true;
    }

    /**
     * Flush all cached keys associated with the admin dashboard.
     */
    public static function invalidateAdminDashboard(): void
    {
        self::delete('admin_users_list');
        self::delete('admin_accounts_list');
        self::delete('admin_dashboard_logs');
        self::delete('admin_sender_requests_list');
        self::delete('admin_agencies_list');
        self::delete('admin_agencies_page');
        self::delete('admin_agency_users_list');
        self::delete('admin_admins_list');
        self::delete('admin_settings');
    }

    /**
     * Flush cached agency-dashboard payloads for a single agency/company.
     */
    public static function invalidateAgencyDashboard(string $agencyId): void
    {
        $agencyId = trim($agencyId);
        if ($agencyId === '') {
            return;
        }

        self::delete('subaccounts_' . $agencyId);
        self::delete('agency_locations_' . $agencyId);
        self::delete('agency_check_installs_' . $agencyId);
        self::delete('agency_wallet_' . $agencyId);
        self::delete('agency_all_active_subaccounts');
        self::deleteRegistry('agency_transactions_registry_' . $agencyId);
        self::deleteRegistry('credit_requests_registry_' . $agencyId);
    }
}

