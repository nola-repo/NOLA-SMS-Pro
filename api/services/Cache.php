<?php

/**
 * Cache — Simple file-based JSON cache utility.
 */
class Cache
{
    private string $cacheDir;

    public function __construct(string $subDir = 'data')
    {
        $this->cacheDir = __DIR__ . '/../cache/' . $subDir;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get data from cache if not expired.
     *
     * @param string $key  The cache key
     * @param int    $ttl  Time-to-live in seconds
     * @return mixed       Decoded JSON data or null if not found/expired
     */
    public function get(string $key, int $ttl = 300)
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            if ((time() - filemtime($file)) < $ttl) {
                $content = file_get_contents($file);
                return json_decode($content, true);
            } else {
                // Expired
                @unlink($file);
            }
        }

        return null;
    }

    /**
     * Set data to cache.
     *
     * @param string $key   The cache key
     * @param mixed  $data  The data to cache (will be JSON encoded)
     * @return bool         Success or failure
     */
    public function set(string $key, $data): bool
    {
        $file = $this->getFilePath($key);
        $content = json_encode($data);
        return file_put_contents($file, $content) !== false;
    }

    /**
     * Delete a cache key.
     *
     * @param string $key The cache key
     */
    public function delete(string $key): void
    {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    private function getFilePath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.json';
    }
}
