<?php

namespace Tests\Feature\Ai\Tools;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Models\User;
use App\Services\Ai\Tools\ScoreProspectsTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSearchProvider;
use Tests\TestCase;

/**
 * V2.3 (spec §26/§34): the score_prospects tool contract —
 * Manager/Team-Head only (re-derived from the actor), criteria
 * validated before any external call, structured deterministic output,
 * and NO parameter that lets the caller influence the number.
 */
class ScoreProspectsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'abc-beauty.test*' => Http::response(
                '<html><body>ABC Beauty in Cebu City. Skincare and cosmetics. Add to cart. We deliver nationwide.</body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
        ]);
        $this->app->instance(SearchProvider::class, FakeSearchProvider::withRows([
            ['title' => 'ABC Beauty', 'url' => 'https://abc-beauty.test/', 'description' => 'Skincare and cosmetics in Cebu City.'],
        ]));
    }

    private function tool(): ScoreProspectsTool
    {
        return app(ScoreProspectsTool::class);
    }

    private function args(array $overrides = []): array
    {
        return array_merge([
            'location' => 'Cebu City',
            'industry' => 'cosmetics',
            'product_keywords' => ['skincare'],
        ], $overrides);
    }

    public function test_a_manager_can_score(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args());

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('scored_prospects', $result);
        $this->assertArrayHasKey('scoring_model', $result);
        $this->assertArrayHasKey('priority_distribution', $result);
        $this->assertArrayHasKey('notice', $result);
    }

    public function test_a_team_head_can_score(): void
    {
        $result = $this->tool()->execute(User::factory()->teamHead()->create(), $this->args());
        $this->assertArrayHasKey('scored_prospects', $result);
    }

    public function test_a_team_member_is_denied(): void
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

    public function test_the_definition_exposes_no_weight_threshold_priority_or_score_parameter(): void
    {
        $params = $this->tool()->definition()->parameters['properties'];

        $this->assertSame('score_prospects', $this->tool()->definition()->name);
        foreach (['weight', 'weights', 'threshold', 'priority', 'score', 'points', 'bonus', 'band', 'model'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $params, "score_prospects must not expose a '{$forbidden}' parameter.");
        }
        $this->assertArrayHasKey('hard_criteria', $params);
    }

    public function test_the_scoring_model_version_is_returned(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args());

        $this->assertSame('v2.3-default-1', $result['scoring_model']['version']);
        $this->assertSame(100, $result['scoring_model']['max_score']);
    }

    public function test_the_batch_size_is_capped_by_the_application(): void
    {
        config(['services.market_intelligence.max_qualification_prospects' => 2]);

        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args(['max_results' => 50]));

        $this->assertLessThanOrEqual(2, count($result['scored_prospects']));
    }
}
