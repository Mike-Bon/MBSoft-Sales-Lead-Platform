<?php

namespace Tests\Feature\MarketIntelligence;

use App\Support\MarketIntelligence\OutboundUrlGuard;
use App\Support\MarketIntelligence\UnsafeUrlException;
use Tests\TestCase;

/**
 * V2.1 SSRF defence (spec §5). Every URL prospect discovery is about to
 * fetch — and every redirect hop — passes assertSafe() first. These
 * tests are deterministic: they use IP literals and reserved hostnames,
 * never a live DNS lookup of an attacker-controlled domain.
 */
class OutboundUrlGuardTest extends TestCase
{
    private function guard(): OutboundUrlGuard
    {
        return new OutboundUrlGuard;
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'loopback IPv4' => ['http://127.0.0.1/'],
            'loopback IPv4 alt' => ['http://127.9.9.9/admin'],
            'all-zeros' => ['http://0.0.0.0/'],
            'localhost by name' => ['http://localhost/'],
            'localhost subdomain' => ['http://api.localhost/'],
            'RFC1918 10/8' => ['http://10.1.2.3/'],
            'RFC1918 172.16/12' => ['http://172.16.5.4/'],
            'RFC1918 192.168/16' => ['http://192.168.0.1/'],
            'link-local' => ['http://169.254.1.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ECS metadata' => ['http://169.254.170.2/v2/credentials'],
            'GCE metadata by name' => ['http://metadata.google.internal/computeMetadata/v1/'],
            'CGNAT 100.64/10' => ['http://100.64.10.10/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv6 unique-local' => ['http://[fd00::1]/'],
            'internal TLD' => ['http://db.internal/'],
            'corp TLD' => ['http://wiki.corp/'],
            'home.arpa' => ['http://router.home.arpa/'],
            'non-http scheme file' => ['file:///etc/passwd'],
            'non-http scheme gopher' => ['gopher://127.0.0.1/'],
            'non-http scheme ftp' => ['ftp://example.com/'],
            'disallowed port 8080' => ['http://93.184.216.34:8080/'],
            'disallowed port 22' => ['http://93.184.216.34:22/'],
            'disallowed port 5432' => ['http://93.184.216.34:5432/'],
            'not absolute' => ['/relative/path'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider unsafeUrls
     */
    public function test_it_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);

        $this->guard()->assertSafe($url);
    }

    public function test_it_allows_a_plain_public_https_url(): void
    {
        // A public IP literal — no DNS, fully deterministic. 93.184.216.34
        // is in the reserved-for-documentation-adjacent public space used
        // by example.com historically; any routable public address works.
        $url = 'https://93.184.216.34/about';

        $this->assertSame($url, $this->guard()->assertSafe($url));
    }

    public function test_it_allows_default_ports_only(): void
    {
        $this->assertSame('http://93.184.216.34:80/', $this->guard()->assertSafe('http://93.184.216.34:80/'));
        $this->assertSame('https://93.184.216.34:443/', $this->guard()->assertSafe('https://93.184.216.34:443/'));
    }

    public function test_it_refuses_the_configured_database_host_by_address(): void
    {
        // The app's DB host, resolved, must never be fetchable — even
        // though the address itself is a routable public one.
        config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '93.184.216.34']);

        $this->expectException(UnsafeUrlException::class);

        $this->guard()->assertSafe('https://93.184.216.34/');
    }

    public function test_it_refuses_the_applications_own_web_host_by_name(): void
    {
        config(['app.url' => 'https://93.184.216.34']);

        $this->expectException(UnsafeUrlException::class);

        $this->guard()->assertSafe('https://93.184.216.34/anything');
    }

    public function test_a_reserved_hostname_is_rejected_before_any_dns_lookup(): void
    {
        // "localhost" (and the other reserved names/suffixes) are refused
        // by name — an attacker cannot even force a resolution attempt.
        $this->expectException(UnsafeUrlException::class);

        $this->guard()->assertSafe('http://localhost:80/');
    }
}
