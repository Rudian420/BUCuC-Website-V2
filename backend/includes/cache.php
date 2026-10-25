<?php
/**
 * Server-side cache for high-traffic public queries (Homepage database results).
 * Keeps responses fast and reduces database load.
 * Cleared immediately whenever an admin modifies content (events, tenures, videos, ads, stats).
 */

defined('CACHE_DIR') || define('CACHE_DIR', __DIR__ . '/../cache');
defined('HOMEPAGE_CACHE_TTL') || define('HOMEPAGE_CACHE_TTL', 300); // 5 minutes

function cache_ensure_dir()
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
        @file_put_contents(CACHE_DIR . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
    }
}

/** Get a cached value by key if not expired, or null */
function site_cache_get($key)
{
    cache_ensure_dir();
    $file = CACHE_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
    if (!is_file($file)) return null;

    $content = @file_get_contents($file);
    if (!$content) return null;

    $bundle = @json_decode($content, true);
    if (!is_array($bundle) || empty($bundle['expires_at']) || $bundle['expires_at'] < time()) {
        @unlink($file);
        return null;
    }

    return $bundle['data'] ?? null;
}

/** Save a value to the cache with a TTL in seconds */
function site_cache_set($key, $data, $ttl = HOMEPAGE_CACHE_TTL)
{
    cache_ensure_dir();
    $file = CACHE_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
    $tmp = $file . '.' . uniqid('tmp_', true);

    $bundle = [
        'created_at' => time(),
        'expires_at' => time() + max(1, (int) $ttl),
        'data'       => $data,
    ];

    $payload = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload !== false && @file_put_contents($tmp, $payload, LOCK_EX) !== false) {
        @rename($tmp, $file);
        return true;
    }
    @unlink($tmp);
    return false;
}

/** Clear a specific cache key or all cache */
function site_cache_clear($key = null)
{
    cache_ensure_dir();
    if ($key !== null) {
        $file = CACHE_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
        if (is_file($file)) @unlink($file);
        return;
    }

    // Clear all json cache files in CACHE_DIR
    $files = @glob(CACHE_DIR . '/*.json');
    if (is_array($files)) {
        foreach ($files as $f) {
            if (is_file($f)) @unlink($f);
        }
    }
}
