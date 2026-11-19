<?php

namespace App\Downloads;

use App\Downloads\Exceptions\DownloadRejected;
use Closure;

/**
 * Second SSRF barrier: before yt-dlp is started, the host of the canonical URL must resolve
 * only to public addresses. This catches a poisoned or overridden DNS answer (an internal
 * resolver or /etc/hosts entry pointing www.youtube.com to 10.0.0.5) and any future platform
 * whose host could resolve into the private network.
 */
final class NetworkGuard
{
    /** IPv4 ranges that must never be contacted (RFC 6890 special-purpose, CGNAT, multicast). */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** IPv6 ranges that must never be contacted. */
    private const BLOCKED_V6 = [
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', 'fc00::/7', 'fe80::/10',
        'fec0::/10', 'ff00::/8',
    ];

    /** IPv6 ranges embedding an IPv4 address (checked against BLOCKED_V4) => byte offset of it. */
    private const EMBEDDED_V4 = ['::ffff:0:0/96' => 12, '64:ff9b::/96' => 12, '2002::/16' => 2];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver  host => IP addresses (injectable for tests) */
    public function __construct(Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::dnsResolver(...);
    }

    public function assertPublicHost(string $host): void
    {
        $ips = ($this->resolver)($host);

        if ($ips === []) {
            throw DownloadRejected::unresolvableHost();
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw DownloadRejected::privateAddress();
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidr($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::EMBEDDED_V4 as $cidr => $offset) {
            if (self::inCidr($packed, $cidr)) {
                return self::isPublicIp((string) inet_ntop(substr($packed, $offset, 4)));
            }
        }
        foreach (self::BLOCKED_V6 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** True for anything a URL host could use to address an IP directly. */
    public static function isIpLiteral(string $host): bool
    {
        return str_starts_with($host, '[')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            // Decimal, octal and hex forms (2130706433, 0177.0.0.1, 0x7f.1) that resolvers accept.
            || preg_match('/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+))*$/i', $host) === 1;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netPacked = (string) inet_pton($net);
        if (strlen($netPacked) !== strlen($packed)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (strncmp($packed, $netPacked, $bytes) !== 0) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function dnsResolver(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
