<?php

namespace Tests\Feature\MarketIntelligence;

use App\Models\User;
use App\Services\MarketIntelligence\EvidenceExtractor;
use App\Services\MarketIntelligence\ProspectDiscoveryService;
use App\Services\MarketIntelligence\ProspectQualificationService;
use App\Services\MarketIntelligence\ProspectScoringService;
use App\Services\MarketIntelligence\WebEvidenceFetcher;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\OutboundUrlGuard;
use App\Support\MarketIntelligence\QualificationCriteria;
use App\Support\MarketIntelligence\ScoringModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeSearchProvider;
use Tests\TestCase;

/**
 * V2.3 end-to-end: the score() shell re-runs the V2.2 qualification
 * pipeline (bounded web work) then applies the pure scoring core, ranks,
 * audits, and formats. All searches faked; all fetches Http::fake behind
 * a DNS-stubbed OutboundUrlGuard.
 */
class ProspectScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const HOSTS = [
        'abc-beauty.test' => ['93.184.216.34'],
        'glow.test' => ['93.184.216.35'],
        'shine.test' => ['93.184.216.36'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function service(FakeSearchProvider $search): ProspectScoringService
    {
        $guard = new OutboundUrlGuard(fn (string $host) => self::HOSTS[$host] ?? []);
        $fetcher = new WebEvidenceFetcher(app(HttpFactory::class), $guard);
        $discovery = new ProspectDiscoveryService($search, $fetcher, new EvidenceExtractor, $guard);
        $qualification = new ProspectQualificationService($discovery, $search, $fetcher, $guard);

        return new ProspectScoringService($qualification);
    }

    private function criteria(array $overrides = []): array
    {
        $d = DiscoveryCriteria::fromArray(array_merge([
            'location' => 'Cebu City',
            'industry' => 'cosmetics',
            'product_keywords' => ['skincare'],
            'online_signals' => ['own_website'],
        ], $overrides), 20);

        return [$d, QualificationCriteria::fromArray([], $d, 8)];
    }

    private function actor(): User
    {
        return User::factory()->manager()->create();
    }

    public function test_a_strong_prospect_is_scored_ranked_and_broken_down(): void
    {
        Http::fake([
            'abc-beauty.test*' => Http::response(
                '<html><head><title>ABC Beauty Store — Cebu City</title>'
                .'<meta name="description" content="ABC Beauty sells skincare and cosmetics online in Cebu City with nationwide delivery.">'
                .'</head><body>Welcome to ABC Beauty, based in Cebu City. We sell skincare and cosmetics. '
                .'Add to cart and check out online. We deliver nationwide via LBC. '
                .'<a href="https://facebook.com/abcbeauty">Facebook</a></body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);
        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty Store', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare and cosmetics, Cebu City.'],
        ]);

        [$d, $q] = $this->criteria();
        $result = $this->service($search)->score($this->actor(), $d, $q, ScoringModel::default());

        $this->assertSame('ok', $result['status']);
        $prospect = $result['scored_prospects'][0];

        $this->assertGreaterThanOrEqual(70, $prospect['total_score']);
        $this->assertLessThanOrEqual(100, $prospect['total_score']);
        $this->assertContains($prospect['priority'], ['high', 'medium']);
        $this->assertSame('v2.3-default-1', $prospect['scoring_model']);
        $this->assertCount(7, $prospect['breakdown']);
        $this->assertSame(100, $prospect['max_score']);
        $this->assertArrayHasKey('identity', $prospect);
        $this->assertStringContainsString('not a conversion probability', $result['notice']);
        $this->assertStringNotContainsString('add to cart', strtolower(json_encode($result['scoring_model'])));
    }

    public function test_prospects_are_ranked_highest_score_first(): void
    {
        Http::fake([
            'abc-beauty.test*' => Http::response(
                '<html><body>ABC Beauty in Cebu City. Skincare and cosmetics. Add to cart. We deliver nationwide.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'glow.test*' => Http::response(
                '<html><body>Glow salon. Beauty treatments.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);
        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare and cosmetics, Cebu City, online store.'],
            ['title' => 'Glow Salon', 'url' => 'https://glow.test/', 'description' => 'Beauty salon.'],
        ]);

        [$d, $q] = $this->criteria();
        $result = $this->service($search)->score($this->actor(), $d, $q, ScoringModel::default());

        $scores = array_column($result['scored_prospects'], 'total_score');
        $this->assertSame($scores, collect($scores)->sortDesc()->values()->all());
        $this->assertSame('abc-beauty.test', $result['scored_prospects'][0]['domain']);
    }

    public function test_a_provider_failure_is_a_safe_status(): void
    {
        Http::fake();
        [$d, $q] = $this->criteria();
        $result = $this->service(FakeSearchProvider::failing())->score($this->actor(), $d, $q, ScoringModel::default());

        $this->assertSame('provider_unavailable', $result['status']);
        $this->assertEmpty($result['scored_prospects']);
    }

    public function test_no_prospects_is_a_safe_status(): void
    {
        Http::fake();
        [$d, $q] = $this->criteria();
        $result = $this->service(FakeSearchProvider::withRows([]))->score($this->actor(), $d, $q, ScoringModel::default());

        $this->assertSame('no_prospects', $result['status']);
    }

    public function test_the_per_user_hourly_scoring_limit_is_enforced(): void
    {
        Http::fake();
        $actor = $this->actor();
        $key = 'market-intel:score:'.$actor->id;
        for ($i = 0; $i < (int) config('services.market_intelligence.scoring.max_scorings_per_hour'); $i++) {
            RateLimiter::hit($key, 3600);
        }

        [$d, $q] = $this->criteria();
        $result = $this->service(FakeSearchProvider::withRows([]))->score($actor, $d, $q, ScoringModel::default());

        $this->assertSame('rate_limited', $result['status']);
    }

    public function test_scoring_writes_one_audit_record_with_distributions_and_no_page_bodies(): void
    {
        Http::fake([
            'abc-beauty.test*' => Http::response('<html><body>Skincare in Cebu City. Add to cart.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('market_intelligence.scoring', \Mockery::on(function ($context) {
            $blob = strtolower(json_encode($context));

            return isset($context['scoring_model'], $context['priority_distribution'], $context['prospect_count'], $context['status'])
                && $context['scoring_model'] === 'v2.3-default-1'
                && ! str_contains($blob, 'add to cart')
                && ! str_contains($blob, '<html');
        }));

        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare, Cebu City.'],
        ]);
        [$d, $q] = $this->criteria();
        $this->service($search)->score($this->actor(), $d, $q, ScoringModel::default());
    }

    public function test_an_invalid_configured_model_is_reported_and_scoring_still_runs_on_defaults(): void
    {
        config(['services.market_intelligence.scoring.weights.industry_fit' => 999]);

        Http::fake([
            'abc-beauty.test*' => Http::response('<html><body>Skincare in Cebu City. Add to cart.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare, Cebu City.'],
        ]);

        [$d, $q] = $this->criteria();
        $result = $this->service($search)->score($this->actor(), $d, $q, ScoringModel::fromConfig());

        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['scoring_model']['config_valid']);
        $this->assertSame(100, $result['scoring_model']['max_score']);
    }

    public function test_focus_domains_restrict_which_prospects_are_scored(): void
    {
        Http::fake([
            'abc-beauty.test*' => Http::response('<html><body>Skincare in Cebu City. Add to cart.</body></html>', 200, ['Content-Type' => 'text/html']),
            'glow.test*' => Http::response('<html><body>Skincare in Cebu City. Add to cart.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare, Cebu City.'],
            ['title' => 'Glow', 'url' => 'https://glow.test/', 'description' => 'Skincare, Cebu City.'],
        ]);

        [$d, $q] = $this->criteria();
        $result = $this->service($search)->score($this->actor(), $d, $q, ScoringModel::default(), ['abc-beauty.test']);

        $this->assertCount(1, $result['scored_prospects']);
        $this->assertSame('abc-beauty.test', $result['scored_prospects'][0]['domain']);
    }
}
