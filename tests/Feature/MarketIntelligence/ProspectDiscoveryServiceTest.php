<?php

namespace Tests\Feature\MarketIntelligence;

use App\Models\User;
use App\Services\MarketIntelligence\EvidenceExtractor;
use App\Services\MarketIntelligence\ProspectDiscoveryService;
use App\Services\MarketIntelligence\WebEvidenceFetcher;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\OutboundUrlGuard;
use App\Support\MarketIntelligence\SearchProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeSearchProvider;
use Tests\TestCase;

/**
 * V2.1 (spec §5-§8, §14-§16): the one place external prospect discovery
 * is orchestrated. All searches use a FakeSearchProvider; all page
 * fetches use Http::fake with a DNS-stubbed OutboundUrlGuard. Nothing
 * here touches a live network service.
 */
class ProspectDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const HOST_MAP = [
        'acme-cosmetics.test' => ['93.184.216.34'],
        'glow-beauty.test' => ['93.184.216.35'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function service(FakeSearchProvider $search): ProspectDiscoveryService
    {
        $guard = new OutboundUrlGuard(fn (string $host) => self::HOST_MAP[$host] ?? []);

        return new ProspectDiscoveryService(
            $search,
            new WebEvidenceFetcher(app(HttpFactory::class), $guard),
            new EvidenceExtractor,
            $guard,
        );
    }

    private function criteria(array $overrides = []): DiscoveryCriteria
    {
        return DiscoveryCriteria::fromArray(array_merge([
            'location' => 'Cebu City',
            'industry' => 'cosmetics',
            'product_keywords' => ['lipstick', 'skincare'],
            'online_signals' => ['own_website', 'facebook'],
            'max_results' => 10,
        ], $overrides), 20);
    }

    private function fakeAcmePage(): void
    {
        Http::fake([
            'acme-cosmetics.test/*' => Http::response(
                '<html><head><title>Acme Cosmetics — Cebu City</title>'
                .'<meta name="description" content="Acme Cosmetics sells lipstick and skincare online. Nationwide shipping via LBC.">'
                .'</head><body><p>Welcome to Acme Cosmetics, based in Cebu City. Add to cart and check out online.</p>'
                .'<a href="https://facebook.com/acmecosmetics">Facebook</a>'
                .'<a href="https://instagram.com/acmecosmetics">Instagram</a></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
            'glow-beauty.test/*' => Http::response(
                '<html><head><title>Glow Beauty Supply</title></head>'
                .'<body><p>Wholesale beauty products.</p></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
    }

    public function test_a_normal_discovery_returns_candidates_each_with_evidence_and_a_real_source_url(): void
    {
        $this->fakeAcmePage();
        $search = FakeSearchProvider::withRows([
            ['title' => 'Acme Cosmetics', 'url' => 'https://acme-cosmetics.test/', 'description' => 'Lipstick and skincare, Cebu City.'],
            ['title' => 'Glow Beauty Supply', 'url' => 'https://glow-beauty.test/', 'description' => 'Beauty products.'],
        ]);

        $result = $this->service($search)->discover(User::factory()->manager()->create(), $this->criteria());

        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['prospects']);

        foreach ($result['prospects'] as $prospect) {
            $this->assertNotEmpty($prospect['evidence']);
            foreach ($prospect['evidence'] as $item) {
                $this->assertArrayHasKey('source_url', $item);
                $this->assertStringStartsWith('http', $item['source_url']);
                $this->assertStringContainsString('.test', $item['source_url']);
            }
        }

        $acme = collect($result['prospects'])->firstWhere('domain', 'acme-cosmetics.test');
        $this->assertNotNull($acme);
        $this->assertSame('Cebu City', $acme['location']);
        $this->assertSame('cosmetics', $acme['category']);
        $this->assertTrue($acme['online_selling_evidence']);
        $this->assertContains('lipstick', $acme['observed_products']);
        $this->assertSame('high', $acme['discovery_confidence']);
    }

    public function test_it_builds_deterministic_queries_from_the_criteria_not_raw_user_text(): void
    {
        $this->fakeAcmePage();
        $search = FakeSearchProvider::withRows([
            ['title' => 'Acme', 'url' => 'https://acme-cosmetics.test/', 'description' => 'x'],
        ]);

        $this->service($search)->discover(User::factory()->manager()->create(), $this->criteria());

        $this->assertNotEmpty($search->queries);
        $this->assertLessThanOrEqual(3, count($search->queries));
        foreach ($search->queries as $query) {
            $this->assertStringContainsString('cosmetics', $query);
            $this->assertStringContainsString('Cebu City', $query);
        }
    }

    public function test_the_result_count_is_capped_by_the_requested_maximum(): void
    {
        $this->fakeAcmePage();
        $search = FakeSearchProvider::withRows([
            ['title' => 'Acme Cosmetics', 'url' => 'https://acme-cosmetics.test/', 'description' => 'Lipstick, Cebu City. Add to cart.'],
            ['title' => 'Glow Beauty', 'url' => 'https://glow-beauty.test/', 'description' => 'Skincare, Cebu City.'],
        ]);

        $result = $this->service($search)->discover(
            User::factory()->manager()->create(),
            $this->criteria(['max_results' => 1]),
        );

        $this->assertLessThanOrEqual(1, count($result['prospects']));
    }

    public function test_facts_not_shown_by_a_source_are_reported_as_missing_not_invented(): void
    {
        $this->fakeAcmePage();
        // Glow Beauty's page shows no location, no online-selling signal,
        // no shipping — the candidate must say so, never fill the gaps.
        $search = FakeSearchProvider::withRows([
            ['title' => 'Glow Beauty Supply', 'url' => 'https://glow-beauty.test/', 'description' => 'Beauty products.'],
        ]);

        $result = $this->service($search)->discover(User::factory()->manager()->create(), $this->criteria());

        $glow = collect($result['prospects'])->firstWhere('domain', 'glow-beauty.test');
        $this->assertNotNull($glow);
        $this->assertNull($glow['location']);
        $this->assertFalse($glow['online_selling_evidence']);
        $this->assertFalse($glow['shipping_evidence']);
        $this->assertNotEmpty($glow['missing_information']);
        $this->assertTrue(collect($glow['missing_information'])->contains(fn ($m) => str_contains(strtolower($m), 'shipping')));
    }

    public function test_a_candidate_with_no_corroborating_evidence_and_no_website_is_dropped(): void
    {
        Http::fake();
        // A share/aggregator link on a social host with no usable profile
        // path — not fetched, no website, nothing matching the criteria
        // => only the description stub => thin => dropped (§7).
        $search = FakeSearchProvider::withRows([
            ['title' => 'Facebook', 'url' => 'https://facebook.com/sharer/sharer.php?u=x', 'description' => 'unrelated content'],
        ]);

        $result = $this->service($search)->discover(User::factory()->manager()->create(), $this->criteria());

        $this->assertSame('no_results', $result['status']);
        $this->assertCount(0, $result['prospects']);
    }

    public function test_a_search_that_returns_nothing_yields_a_no_results_status(): void
    {
        Http::fake();
        $result = $this->service(FakeSearchProvider::withRows([]))
            ->discover(User::factory()->manager()->create(), $this->criteria());

        $this->assertSame('no_results', $result['status']);
        $this->assertEmpty($result['prospects']);
    }

    public function test_a_provider_failure_becomes_a_safe_status_never_an_exception(): void
    {
        Http::fake();
        $result = $this->service(FakeSearchProvider::failing())
            ->discover(User::factory()->manager()->create(), $this->criteria());

        $this->assertSame('provider_unavailable', $result['status']);
        $this->assertEmpty($result['prospects']);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_a_provider_rate_limit_error_is_surfaced_safely(): void
    {
        Http::fake();
        $result = $this->service(FakeSearchProvider::failing(new SearchProviderException('rate limit')))
            ->discover(User::factory()->manager()->create(), $this->criteria());

        $this->assertSame('provider_unavailable', $result['status']);
    }

    public function test_the_per_user_hourly_limit_blocks_further_discoveries(): void
    {
        Http::fake();
        $actor = User::factory()->manager()->create();
        $key = 'market-intel:discover:'.$actor->id;

        for ($i = 0; $i < (int) config('services.market_intelligence.max_discoveries_per_hour'); $i++) {
            RateLimiter::hit($key, 3600);
        }

        $result = $this->service(FakeSearchProvider::withRows([]))->discover($actor, $this->criteria());

        $this->assertSame('rate_limited', $result['status']);
    }

    public function test_every_discovery_writes_one_audit_record(): void
    {
        $this->fakeAcmePage();
        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('market_intelligence.discovery', \Mockery::on(function ($context) {
            return isset($context['criteria'], $context['provider'], $context['result_count'], $context['status'])
                && $context['provider'] === 'fake';
        }));

        $search = FakeSearchProvider::withRows([
            ['title' => 'Acme Cosmetics', 'url' => 'https://acme-cosmetics.test/', 'description' => 'Lipstick, Cebu City.'],
        ]);

        $this->service($search)->discover(User::factory()->manager()->create(), $this->criteria());
    }

    public function test_the_result_always_carries_the_not_a_crm_record_notice(): void
    {
        Http::fake();
        $result = $this->service(FakeSearchProvider::withRows([]))
            ->discover(User::factory()->manager()->create(), $this->criteria());

        $this->assertStringContainsString('not CRM records', $result['notice']);
    }
}
