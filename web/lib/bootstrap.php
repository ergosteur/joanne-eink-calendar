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
require_once __DIR__ . '/throttle.php';

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

    /**
     * The client address, used for throttling and the dashboard allowlist.
     *
     * X-Forwarded-For is only honoured when the immediate peer is a configured trusted
     * proxy. Trusting it unconditionally would let anyone reset their own rate limit, or
     * forge their way past an allowlist, by sending a header.
     */
    public static function clientIp(array $config): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = $config['security']['trusted_proxies'] ?? [];

        if ($remote === '' || empty($trusted)) {
            return $remote !== '' ? $remote : '0.0.0.0';
        }

        $isTrustedPeer = false;
        foreach ((array)$trusted as $range) {
            if (self::ipMatches($remote, (string)$range)) {
                $isTrustedPeer = true;
                break;
            }
        }
        if (!$isTrustedPeer) {
            return $remote;
        }

        $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') {
            return $remote;
        }

        // Right-most entry is the one the trusted proxy itself observed; anything
        // further left was supplied by the client and cannot be believed.
        $parts = array_map('trim', explode(',', $forwarded));
        $candidate = end($parts);

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : $remote;
    }

    /**
     * Match an address against a literal address or a CIDR range, v4 or v6.
     */
    public static function ipMatches(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return false;
        }

        if (!str_contains($range, '/')) {
            return @inet_pton($ip) !== false && @inet_pton($ip) === @inet_pton($range);
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int)$bits;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($whole > 0 && strncmp($ipBin, $subnetBin, $whole) !== 0) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $remainder) & 0xFF);
        return (($ipBin[$whole] & $mask) === ($subnetBin[$whole] & $mask));
    }
}
