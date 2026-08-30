<?php

namespace Tests\Feature\MarketIntelligence;

use App\Models\User;
use App\Services\MarketIntelligence\EvidenceExtractor;
use App\Services\MarketIntelligence\ProspectDiscoveryService;
use App\Services\MarketIntelligence\ProspectQualificationService;
use App\Services\MarketIntelligence\WebEvidenceFetcher;
use App\Support\MarketIntelligence\CriterionResult;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\OutboundUrlGuard;
use App\Support\MarketIntelligence\QualificationCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeSearchProvider;
use Tests\TestCase;

/**
 * V2.2 end-to-end: discovery (V2.1 pipeline, reused) → deterministic
 * criterion evaluation → bounded additional research → deterministic
 * outcome → audit. All searches faked; all fetches Http::fake behind a
 * DNS-stubbed OutboundUrlGuard. No live network.
 */
class ProspectQualificationServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const HOSTS = [
        'abc-beauty.test' => ['93.184.216.34'],
        'abc-directory.test' => ['93.184.216.35'],
        'glow.test' => ['93.184.216.36'],
        'shine.test' => ['93.184.216.37'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function service(FakeSearchProvider $search): ProspectQualificationService
    {
        $guard = new OutboundUrlGuard(fn (string $host) => self::HOSTS[$host] ?? []);
        $fetcher = new WebEvidenceFetcher(app(HttpFactory::class), $guard);
        $discovery = new ProspectDiscoveryService($search, $fetcher, new EvidenceExtractor, $guard);

        return new ProspectQualificationService($discovery, $search, $fetcher, $guard);
    }

    private function discoveryCriteria(array $overrides = []): DiscoveryCriteria
    {
        return DiscoveryCriteria::fromArray(array_merge([
            'location' => 'Cebu City',
            'industry' => 'cosmetics',
            'product_keywords' => ['skincare'],
            'online_signals' => ['own_website'],
        ], $overrides), 20);
    }

    private function qualificationCriteria(DiscoveryCriteria $d, array $input = []): QualificationCriteria
    {
        return QualificationCriteria::fromArray($input, $d, 8);
    }

    private function actor(): User
    {
        return User::factory()->manager()->create();
    }

    public function test_a_business_meeting_every_hard_criterion_on_its_own_site_is_a_strong_match(): void
    {
        Http::fake([
            'abc-beauty.test*' => Http::response(
                '<html><head><title>ABC Beauty Store — Cebu City</title>'
                .'<meta name="description" content="ABC Beauty sells skincare and cosmetics online in Cebu City.">'
                .'</head><body>Welcome to ABC Beauty, based in Cebu City. We sell skincare and cosmetics. '
                .'Add to cart and check out online.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);
        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty Store', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare and cosmetics, Cebu City.'],
        ]);

        $d = $this->discoveryCriteria();
        $result = $this->service($search)->qualify($this->actor(), $d, $this->qualificationCriteria($d));

        $this->assertSame('ok', $result['status']);
        $prospect = $result['qualified_prospects'][0];
        $this->assertSame('strong_match', $prospect['qualification_outcome']);

        foreach ($prospect['hard_criteria'] as $entry) {
            $this->assertSame('satisfied', $entry['result']);
            $this->assertNotEmpty($entry['evidence']);
            $this->assertContains($entry['evidence_strength'], ['direct', 'corroborating']);
            $this->assertStringStartsWith('http', $entry['evidence'][0]['source_url']);
        }
        $this->assertNotEmpty($prospect['sources']);
        $this->assertStringContainsString('not a numeric rating', $result['notice']);
        $this->assertStringNotContainsString('score', strtolower(json_encode($result)));
    }

    public function test_bounded_additional_research_resolves_an_unresolved_hard_criterion(): void
    {
        Http::fake([
            'abc-beauty.test/contact*' => Http::response(
                '<html><head><title>Contact ABC Beauty</title></head><body>'
                .'We deliver nationwide via LBC. Cash on delivery available.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'abc-beauty.test*' => Http::response(
                '<html><head><title>ABC Beauty</title></head><body>Skincare and cosmetics. Add to cart.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);
        $search = FakeSearchProvider::usingResolver(function (string $query) {
            if (str_contains($query, '"ABC Beauty"')) {
                return [['title' => 'Contact', 'url' => 'https://abc-beauty.test/contact', 'description' => 'shipping']];
            }

            return [['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare shop.']];
        });

        $d = $this->discoveryCriteria(['location' => null, 'industry' => null]); // only product => no hard defaults
        $criteria = $this->qualificationCriteria($d, ['hard_criteria' => ['shipping']]);

        $result = $this->service($search)->qualify($this->actor(), $d, $criteria);

        $prospect = collect($result['qualified_prospects'])->firstWhere('domain', 'abc-beauty.test');
        $this->assertNotNull($prospect);
        $shipping = collect($prospect['hard_criteria'])->firstWhere('criterion.key', 'shipping');
        $this->assertSame('satisfied', $shipping['result']);
        $this->assertGreaterThanOrEqual(1, $result['research_budget']['additional_searches']);
        $this->assertGreaterThanOrEqual(1, $result['research_budget']['additional_fetches']);
    }

    public function test_research_that_finds_a_different_location_marks_the_hard_criterion_conflicting(): void
    {
        Http::fake([
            'abc-beauty.test/contact*' => Http::response(
                '<html><head><title>ABC Beauty flagship</title></head><body>Our flagship store is in Cebu City.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'abc-directory.test*' => Http::response(
                '<html><head><title>ABC Beauty listing</title></head><body>ABC Beauty, Davao City branch.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'abc-beauty.test*' => Http::response(
                '<html><head><title>ABC Beauty</title></head><body>Skincare and cosmetics. Add to cart.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);
        $search = FakeSearchProvider::usingResolver(function (string $query) {
            if (str_contains($query, '"ABC Beauty"')) {
                return [
                    ['title' => 'Contact', 'url' => 'https://abc-beauty.test/contact', 'description' => 'store'],
                    ['title' => 'Directory', 'url' => 'https://abc-directory.test/abc', 'description' => 'listing'],
                ];
            }

            return [['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare shop.']];
        });

        $d = $this->discoveryCriteria(['industry' => null]);
        $result = $this->service($search)->qualify($this->actor(), $d, $this->qualificationCriteria($d), ['abc-beauty.test']);

        $prospect = $result['qualified_prospects'][0];
        $location = collect($prospect['hard_criteria'])->firstWhere('criterion.key', 'location');
        $this->assertSame('conflicting', $location['result']);
        $this->assertGreaterThanOrEqual(2, count($location['evidence']), 'Both conflicting sources are retained.');
        $this->assertSame('weak_match', $prospect['qualification_outcome']);
    }

    public function test_the_research_budget_is_bounded_across_the_whole_batch(): void
    {
        config([
            'services.market_intelligence.max_qualification_searches' => 1,
            'services.market_intelligence.max_qualification_prospects' => 3,
        ]);

        Http::fake([
            'abc-beauty.test*' => Http::response('<html><body>Skincare shop.</body></html>', 200, ['Content-Type' => 'text/html']),
            'glow.test*' => Http::response('<html><body>Skincare shop.</body></html>', 200, ['Content-Type' => 'text/html']),
            'shine.test*' => Http::response('<html><body>Skincare shop.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $search = FakeSearchProvider::usingResolver(fn (string $q) => [
            ['title' => 'ABC', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare'],
            ['title' => 'Glow', 'url' => 'https://glow.test/', 'description' => 'Skincare'],
            ['title' => 'Shine', 'url' => 'https://shine.test/', 'description' => 'Skincare'],
        ]);

        $d = $this->discoveryCriteria(['location' => null, 'industry' => null, 'online_signals' => []]);
        $criteria = $this->qualificationCriteria($d, ['hard_criteria' => ['shipping', 'marketplace']]);
        $result = $this->service($search)->qualify($this->actor(), $d, $criteria);

        $this->assertLessThanOrEqual(1, $result['research_budget']['additional_searches']);
        $this->assertGreaterThanOrEqual(1, collect($result['qualified_prospects'])->where('qualification_outcome', 'insufficient_evidence')->count());
    }

    public function test_a_provider_failure_is_a_safe_status(): void
    {
        Http::fake();
        $d = $this->discoveryCriteria();
        $result = $this->service(FakeSearchProvider::failing())->qualify($this->actor(), $d, $this->qualificationCriteria($d));

        $this->assertSame('provider_unavailable', $result['status']);
        $this->assertEmpty($result['qualified_prospects']);
    }

    public function test_no_candidates_to_qualify_is_a_safe_status(): void
    {
        Http::fake();
        $d = $this->discoveryCriteria();
        $result = $this->service(FakeSearchProvider::withRows([]))->qualify($this->actor(), $d, $this->qualificationCriteria($d));

        $this->assertSame('no_prospects', $result['status']);
    }

    public function test_the_per_user_hourly_qualification_limit_is_enforced(): void
    {
        Http::fake();
        $actor = $this->actor();
        $key = 'market-intel:qualify:'.$actor->id;
        for ($i = 0; $i < (int) config('services.market_intelligence.max_qualifications_per_hour'); $i++) {
            RateLimiter::hit($key, 3600);
        }

        $d = $this->discoveryCriteria();
        $result = $this->service(FakeSearchProvider::withRows([]))->qualify($actor, $d, $this->qualificationCriteria($d));

        $this->assertSame('rate_limited', $result['status']);
    }

    public function test_every_qualification_writes_one_audit_record_without_page_bodies(): void
    {
        Http::fake([
            'abc-beauty.test*' => Http::response('<html><body>Skincare in Cebu City. Add to cart.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('market_intelligence.qualification', \Mockery::on(function ($context) {
            $blob = strtolower(json_encode($context));

            return isset($context['outcome_counts'], $context['prospect_count'], $context['research'], $context['status'])
                && $context['provider'] === 'fake'
                && ! str_contains($blob, 'add to cart')
                && ! str_contains($blob, '<html');
        }));

        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare, Cebu City.'],
        ]);
        $d = $this->discoveryCriteria();
        $this->service($search)->qualify($this->actor(), $d, $this->qualificationCriteria($d));
    }

    public function test_focus_domains_restrict_which_discovered_candidates_are_qualified(): void
    {
        Http::fake([
            'abc-beauty.test*' => Http::response('<html><body>Skincare in Cebu City. Add to cart.</body></html>', 200, ['Content-Type' => 'text/html']),
            'glow.test*' => Http::response('<html><body>Skincare in Cebu City. Add to cart.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare, Cebu City.'],
            ['title' => 'Glow', 'url' => 'https://glow.test/', 'description' => 'Skincare, Cebu City.'],
        ]);

        $d = $this->discoveryCriteria();
        $result = $this->service($search)->qualify($this->actor(), $d, $this->qualificationCriteria($d), ['abc-beauty.test']);

        $this->assertCount(1, $result['qualified_prospects']);
        $this->assertSame('abc-beauty.test', $result['qualified_prospects'][0]['domain']);
    }

    public function test_criterion_absence_stays_unknown_and_qualification_blind_spots_are_always_listed(): void
    {
        Http::fake([
            'abc-beauty.test*' => Http::response('<html><body>Skincare shop. Add to cart.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $search = FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare shop.'],
        ]);

        $d = $this->discoveryCriteria(['location' => null, 'industry' => null]);
        $result = $this->service($search)->qualify($this->actor(), $d, $this->qualificationCriteria($d, ['supporting_criteria' => ['shipping']]));

        $prospect = $result['qualified_prospects'][0];
        $shipping = collect($prospect['supporting_signals'])->firstWhere('criterion.key', 'shipping');
        $this->assertSame(CriterionResult::Unknown->value, $shipping['result']);
        $this->assertTrue(collect($prospect['missing_information'])->contains(fn ($m) => str_contains(strtolower($m), 'incumbent courier')));
    }
}
