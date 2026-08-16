<?php
// web/lib/http.php — outbound fetches with SSRF validation.
//
// Feed URLs are attacker-influenced: an administrator can be talked into adding one,
// and personal views accept ?cal= directly. Validating the URL once before handing it
// to file_get_contents was not enough, because that function follows redirects by
// default — a URL on a public host could answer 302 and send the fetch to 127.0.0.1
// or a metadata endpoint, with the response body returned to the caller.
//
// Redirects are therefore followed here, one hop at a time, revalidating before each.
//
// Residual risk: a host can still resolve to a public address at validation time and a
// private one microseconds later at connection time (DNS rebinding). Closing that
// needs connection-level address pinning, which the stream wrappers cannot express.

class LibreHttp
{
    public const MAX_REDIRECTS = 3;
    public const USER_AGENT = 'LibreJoanne/1.0';

    /**
     * True when the URL is http(s) and every address its host resolves to is public.
     */
    public static function isPublicUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }
        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }
        return self::hostIsPublic($parsed['host']);
    }

    /**
     * Fetch a URL, following redirects only to destinations that also validate.
     *
     * @return string|null Response body, or null on failure or a blocked destination.
     */
    public static function get(string $url, int $timeout = 10): ?string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!self::isPublicUrl($url)) {
                return null;
            }

            $context = stream_context_create([
                'http' => [
                    'method'          => 'GET',
                    'header'          => 'User-Agent: ' . self::USER_AGENT . "\r\n",
                    'timeout'         => $timeout,
                    // Redirects are followed by hand so each destination is revalidated.
                    'follow_location' => 0,
                    'max_redirects'   => 1,
                    'ignore_errors'   => true,
                ],
            ]);

            $body = @file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];
            $status = self::statusFrom($headers);

            if ($status >= 300 && $status < 400) {
                $location = self::headerValue($headers, 'location');
                if ($location === null) {
                    return null;
                }
                $url = self::absolutise($url, $location);
                continue;
            }

            if ($body === false || $status >= 400) {
                return null;
            }

            return $body;
        }

        return null; // Too many redirects.
    }

    // ----------------------------------------------------------------- internals

    private static function hostIsPublic(string $host): bool
    {
        $host = trim($host, '[]'); // IPv6 literals arrive bracketed.

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }

        $addresses = [];

        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        // dns_get_record can be unavailable or blocked; fall back to the resolver.
        if ($addresses === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $addresses[] = $resolved;
            }
        }

        if ($addresses === []) {
            return false; // Unresolvable, so nothing to allow.
        }

        // Every address must be public: one private answer is enough to reach inside.
        foreach ($addresses as $address) {
            if (!self::isPublicIp($address)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @param string[] $headers */
    private static function statusFrom(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int)$m[1]; // Keep the last status line, after any redirects.
            }
        }
        return $status ?? 0;
    }

    /** @param string[] $headers */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name) . ':';
        $value = null;
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), $needle)) {
                $value = trim(substr($header, strlen($needle)));
            }
        }
        return $value;
    }

    private static function absolutise(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return $location;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $path = substr($path, 0, (int)strrpos($path, '/') + 1);

        return $origin . $path . $location;
    }
}
