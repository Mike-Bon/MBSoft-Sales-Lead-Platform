<?php

namespace Tests\Feature\MarketIntelligence;

use App\Services\MarketIntelligence\WebEvidenceFetcher;
use App\Support\MarketIntelligence\OutboundUrlGuard;
use App\Support\MarketIntelligence\UnsafeUrlException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * V2.6 (spec §6): attack the V2.1 fetch boundary through the real
 * WebEvidenceFetcher — a public page that redirects to a private target
 * must be blocked at the redirect hop, and the guard must never be
 * loosened to make a test pass.
 */
class V2SsrfAdversarialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** Guard whose DNS resolves `evil.test` to a public IP and `public.test` to a public IP. */
    private function fetcher(): WebEvidenceFetcher
    {
        $guard = new OutboundUrlGuard(fn (string $host) => match ($host) {
            'evil.test', 'public.test', 'redirector.test' => ['93.184.216.34'],
            default => [],
        });

        return new WebEvidenceFetcher(app(HttpFactory::class), $guard);
    }

    public function test_a_redirect_from_a_public_host_to_loopback_is_blocked_at_the_hop(): void
    {
        Http::fake([
            'redirector.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/latest/meta-data/']),
        ]);

        $this->assertNull($this->fetcher()->fetch('https://redirector.test/'));
    }

    public function test_a_redirect_to_a_private_rfc1918_address_is_blocked(): void
    {
        Http::fake([
            'redirector.test/*' => Http::response('', 301, ['Location' => 'https://10.1.2.3/internal']),
        ]);

        $this->assertNull($this->fetcher()->fetch('https://redirector.test/'));
    }

    public function test_a_redirect_to_the_cloud_metadata_endpoint_is_blocked(): void
    {
        Http::fake([
            'redirector.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
        ]);

        $this->assertNull($this->fetcher()->fetch('https://redirector.test/'));
    }

    public function test_the_guard_rejects_the_canonical_dangerous_targets_directly(): void
    {
        $guard = new OutboundUrlGuard;

        foreach ([
            'http://127.0.0.1/', 'http://localhost/', 'http://0.0.0.0/', 'http://10.0.0.1/',
            'http://192.168.1.1/', 'http://172.16.0.1/', 'http://169.254.169.254/',
            'http://100.64.0.1/', 'http://[::1]/', 'http://[fd00::1]/', 'http://[fe80::1]/',
            'http://metadata.google.internal/', 'http://db.internal/', 'http://foo.localhost/',
            'file:///etc/passwd', 'gopher://127.0.0.1/', 'http://93.184.216.34:22/', 'http://93.184.216.34:3000/',
            'ftp://example.com/', 'http://user:pass@127.0.0.1/', 'not-a-url',
        ] as $url) {
            try {
                $guard->assertSafe($url);
                $this->fail("Guard allowed {$url}");
            } catch (UnsafeUrlException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_guard_refuses_the_application_and_configured_database_hosts(): void
    {
        config([
            'app.url' => 'https://93.184.216.34',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '93.184.216.35',
        ]);
        $guard = new OutboundUrlGuard;

        $this->expectException(UnsafeUrlException::class);
        $guard->assertSafe('https://93.184.216.34/anything');
    }

    public function test_a_non_text_content_type_and_an_oversized_body_are_refused(): void
    {
        Http::fake([
            'public.test/pdf' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
            'public.test/huge' => Http::response('x', 200, ['Content-Type' => 'text/html', 'Content-Length' => (string) (5 * 1024 * 1024)]),
        ]);

        $this->assertNull($this->fetcher()->fetch('https://public.test/pdf'));
        $this->assertNull($this->fetcher()->fetch('https://public.test/huge'));
    }
}
