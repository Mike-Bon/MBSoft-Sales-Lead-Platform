<?php

namespace Tests\Feature\Ai\Tools;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Models\User;
use App\Services\Ai\Tools\QualifyProspectsTool;
use App\Support\MarketIntelligence\QualificationCriterion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSearchProvider;
use Tests\TestCase;

/**
 * V2.2 (spec §23/§32): the qualify_prospects tool contract —
 * Manager/Team-Head only (re-derived from the actor), criteria
 * validated before any external call, structured deterministic output.
 */
class QualifyProspectsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'abc-beauty.test*' => Http::response(
                '<html><body>ABC Beauty in Cebu City. Skincare and cosmetics. Add to cart.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);
        $this->app->instance(SearchProvider::class, FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare and cosmetics in Cebu City.'],
        ]));
    }

    private function tool(): QualifyProspectsTool
    {
        return app(QualifyProspectsTool::class);
    }

    private function args(array $overrides = []): array
    {
        return array_merge([
            'location' => 'Cebu City',
            'industry' => 'cosmetics',
            'product_keywords' => ['skincare'],
        ], $overrides);
    }

    public function test_a_manager_can_qualify(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args());

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('qualified_prospects', $result);
        $this->assertArrayHasKey('qualification_criteria', $result);
        $this->assertArrayHasKey('notice', $result);
    }

    public function test_a_team_head_can_qualify(): void
    {
        $result = $this->tool()->execute(User::factory()->teamHead()->create(), $this->args());
        $this->assertArrayHasKey('qualified_prospects', $result);
    }

    public function test_a_team_member_is_denied_regardless_of_arguments(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->tool()->execute(User::factory()->teamMember()->create(), $this->args());
    }

    public function test_a_plain_user_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->tool()->execute(User::factory()->create(), $this->args());
    }

    public function test_empty_criteria_are_rejected_before_any_search(): void
    {
        $search = FakeSearchProvider::withRows([]);
        $this->app->instance(SearchProvider::class, $search);

        try {
            $this->tool()->execute(User::factory()->manager()->create(), []);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            $this->assertSame([], $search->queries);
        }
    }

    public function test_location_and_industry_are_hard_criteria_by_default(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args());

        $keys = collect($result['qualification_criteria']['criteria']);
        $this->assertSame('hard', $keys->firstWhere('key', 'location')['kind']);
        $this->assertSame('hard', $keys->firstWhere('key', 'industry')['kind']);
        $this->assertSame('supporting', $keys->firstWhere('key', 'product')['kind']);
    }

    public function test_an_explicit_supporting_override_downgrades_a_default_hard_criterion(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args([
            'supporting_criteria' => ['location'],
        ]));

        $location = collect($result['qualification_criteria']['criteria'])->firstWhere('key', 'location');
        $this->assertSame('supporting', $location['kind']);
    }

    public function test_the_definition_exposes_structured_criteria_and_no_url_or_score_parameter(): void
    {
        $params = $this->tool()->definition()->parameters;

        $this->assertSame('qualify_prospects', $this->tool()->definition()->name);
        $this->assertArrayHasKey('hard_criteria', $params['properties']);
        $this->assertArrayHasKey('supporting_criteria', $params['properties']);
        $this->assertArrayNotHasKey('url', $params['properties']);
        $this->assertArrayNotHasKey('score', $params['properties']);
        $this->assertArrayNotHasKey('outcome', $params['properties']);
        $this->assertSame(QualificationCriterion::KNOWN_KEYS, $params['properties']['hard_criteria']['items']['enum']);
    }

    public function test_the_batch_size_is_capped_by_the_application(): void
    {
        config(['services.market_intelligence.max_qualification_prospects' => 2]);

        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args(['max_results' => 50]));

        $this->assertLessThanOrEqual(2, $result['qualification_criteria']['max_prospects']);
    }
}
