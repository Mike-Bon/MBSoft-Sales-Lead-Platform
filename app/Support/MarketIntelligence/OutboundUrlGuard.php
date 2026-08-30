<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.1 SSRF defence (spec §5). Every URL the application is about to
 * fetch on behalf of prospect discovery passes through assertSafe()
 * first — and again for every redirect hop. It is deliberately strict:
 *
 *   - http/https only; port 80/443/none only.
 *   - The host, and EVERY IP address the host resolves to, must be a
 *     public unicast address. This defeats "evil.example → 127.0.0.1"
 *     and internal DNS names.
 *   - Explicit blocks for loopback, RFC1918 private ranges, CGNAT
 *     (100.64/10), link-local (169.254/16, fe80::/10), unique-local
 *     IPv6 (fc00::/7), multicast/reserved, 0.0.0.0/8, and the well-known
 *     cloud metadata IPs.
 *   - The application's own web host and configured database host are
 *     blocked by name and by resolved IP.
 *
 * Pure/stateless — no network calls of its own except DNS resolution
 * of the target host (unavoidable, and the whole point).
 */
final class OutboundUrlGuard
{
    /** @var list<string> lowercase host suffixes/exact names that are always rejected */
    private const BLOCKED_HOST_NAMES = [
        'localhost', 'ip6-localhost', 'ip6-loopback',
        'metadata.google.internal', 'metadata.goog',
    ];

    /** @var list<string> lowercase suffixes that are always rejected */
    private const BLOCKED_HOST_SUFFIXES = [
        '.localhost', '.local', '.internal', '.intranet', '.corp', '.home', '.lan', '.home.arpa',
    ];

    /** @var list<string> cloud metadata + well-known infra IPs */
    private const BLOCKED_IPS = [
        '169.254.169.254', '169.254.170.2', '100.100.100.200',
        'fd00:ec2::254',
    ];

    /**
     * @param  (\Closure(string): list<string>)|null  $resolver  host -> resolved IPs.
     *                                                           Production leaves this null and uses real DNS;
     *                                                           a test can inject a deterministic map so the
     *                                                           page-fetch path needs no live lookup.
     */
    public function __construct(private readonly ?\Closure $resolver = null) {}

    /**
     * @return string the validated URL (unchanged)
     *
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('Not an absolute http(s) URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException("Disallowed URL scheme: {$scheme}.");
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new UnsafeUrlException("Disallowed port: {$port}.");
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '') {
            throw new UnsafeUrlException('Empty host.');
        }

        $this->assertHostNameAllowed($host);

        // Collect every address to check: an IP literal as-is, or all
        // A/AAAA records the hostname resolves to.
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolve($host);

        if ($addresses === []) {
            throw new UnsafeUrlException("Host does not resolve: {$host}.");
        }

        foreach ($addresses as $ip) {
            $this->assertIpAllowed($ip);
        }

        // Block the app's own web host and the configured database host,
        // by name and by resolved address.
        foreach ($this->infraHosts() as $infraHost) {
            if ($host === $infraHost) {
                throw new UnsafeUrlException('Refusing to fetch an application infrastructure host.');
            }
        }
        foreach ($this->infraAddresses() as $infraIp) {
            if (in_array($infraIp, $addresses, true)) {
                throw new UnsafeUrlException('Refusing to fetch an application infrastructure host.');
            }
        }

        return $url;
    }

    /**
     * A cheap, DNS-free "obviously not a public website" check —
     * reserved hostnames/suffixes, and IP literals in a private or
     * reserved range. Used by ProspectDiscoveryService to discard junk
     * search hits before they are ever grouped, fetched, or surfaced;
     * assertSafe() (with DNS) is still the authority for anything fetched.
     */
    public function isObviouslyUnsafeHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === '' || in_array($host, self::BLOCKED_HOST_NAMES, true) || in_array($host, self::BLOCKED_IPS, true)) {
            return true;
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            try {
                $this->assertIpAllowed($host);
            } catch (UnsafeUrlException) {
                return true;
            }
        }

        return false;
    }

    private function assertHostNameAllowed(string $host): void
    {
        if (in_array($host, self::BLOCKED_HOST_NAMES, true)) {
            throw new UnsafeUrlException("Blocked host: {$host}.");
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new UnsafeUrlException("Blocked host suffix: {$suffix}.");
            }
        }
    }

    private function assertIpAllowed(string $ip): void
    {
        if (in_array($ip, self::BLOCKED_IPS, true)) {
            throw new UnsafeUrlException("Blocked infrastructure address: {$ip}.");
        }

        // Rejects RFC1918 private and IANA reserved (loopback, link-local,
        // 0.0.0.0/8, ::1, fc00::/7, fe80::/10, 240.0.0.0/4, ...).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new UnsafeUrlException("Non-public address: {$ip}.");
        }

        // CGNAT 100.64.0.0/10 — not covered by PHP's filter flags.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                throw new UnsafeUrlException("Carrier-grade NAT address: {$ip}.");
            }
        }

        // IPv4-mapped / NAT64-style IPv6 that embeds a private v4 address.
        if (str_contains($ip, ':') && preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', $ip, $m)) {
            $this->assertIpAllowed($m[1]);
        }
    }

    /**
     * Resolve a hostname to every A/AAAA address it points at. An
     * injected resolver (tests only) short-circuits real DNS.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(($this->resolver)($host));
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return list<string>
     */
    private function infraHosts(): array
    {
        return array_values(array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            strtolower((string) config('database.connections.'.config('database.default').'.host')),
        ]));
    }

    /**
     * @return list<string>
     */
    private function infraAddresses(): array
    {
        $ips = [];

        foreach ($this->infraHosts() as $host) {
            if ($host === '') {
                continue;
            }

            $ips = array_merge($ips, filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host));
        }

        return array_values(array_unique($ips));
    }
}
