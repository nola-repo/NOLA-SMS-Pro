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
}
