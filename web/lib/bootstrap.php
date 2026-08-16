<?php
// web/lib/bootstrap.php — shared entry-point setup.
//
// Every public entry point loads configuration, opens the database, and sends
// no-cache headers in the same way. That preamble lived in six files; it lives here
// now so the config fallback, the cache directory, and the cache-filename scheme
// cannot drift apart.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/ical.php';

class LibreApp
{
    /** True when config.php is absent and config.sample.php is standing in. */
    public static bool $usingFallbackConfig = false;

    /**
     * Load configuration, ensure the cache directory exists, and open the database.
     *
     * @return array{0: array, 1: LibreDb}
     */
    public static function boot(): array
    {
        $configFile = __DIR__ . '/../data/config.php';
        self::$usingFallbackConfig = !file_exists($configFile);
        if (self::$usingFallbackConfig) {
            $configFile = __DIR__ . '/../data/config.sample.php';
        }

        $config = require $configFile;
        self::ensureCacheDir();

        return [$config, new LibreDb($config)];
    }

    public static function cacheDir(): string
    {
        return __DIR__ . '/../data/cache';
    }

    /**
     * The cache directory is not in version control. Without this, every cache write
     * fails silently on a fresh clone and each request refetches every feed.
     */
    public static function ensureCacheDir(): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Build a cache path. The key is salted so a cache artifact cannot be located
     * by hashing a known feed URL.
     */
    public static function cachePath(string $prefix, string $salt, string $key, string $ext): string
    {
        return self::cacheDir() . "/{$prefix}.cache." . md5($salt . $key) . ".{$ext}";
    }

    /**
     * Panels sit behind gateways and CDNs that will happily serve a stale render.
     */
    public static function noCacheHeaders(): void
    {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
    }

    public static function jsonHeaders(): void
    {
        header("Content-Type: application/json");
        self::noCacheHeaders();
    }
}
