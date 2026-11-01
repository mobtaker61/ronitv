<?php

namespace App\Support;

use InvalidArgumentException;

class UrlGuard
{
    /**
     * Validate that a URL is plain http(s) on a standard port and resolves
     * only to public IP addresses. Returns the parts needed to pin the
     * connection to the validated address (prevents DNS rebinding).
     *
     * @return array{host: string, port: int, ip: string}
     */
    public static function resolvePublic(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Only plain http(s) URLs are allowed.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('Only ports 80 and 443 are allowed.');
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                gethostbynamel($host) ?: [],
                array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            );

        if ($ips === []) {
            throw new InvalidArgumentException('Host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('URL points to a non-public address.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }
}
