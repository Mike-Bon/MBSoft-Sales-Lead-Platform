<?php

namespace Tests\Feature\Ai\Tools;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Models\User;
use App\Services\Ai\Tools\DiscoverProspectsTool;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSearchProvider;
use Tests\TestCase;

/**
 * V2.1 (spec §11, §16): the discover_prospects tool contract —
 * Manager/Team-Head only (re-derived from the actor, never a
 * model-supplied role), criteria validated before any external call,
 * and a structured, evidence-bearing result.
 */
class DiscoverProspectsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->app->instance(SearchProvider::class, FakeSearchProvider::withRows([
            ['title' => 'Acme Cosmetics', 'url' => 'https://acme-cosmetics.test/', 'description' => 'Lipstick and skincare in Cebu City.'],
        ]));
    }

    private function tool(): DiscoverProspectsTool
    {
        return app(DiscoverProspectsTool::class);
    }

    private function args(array $overrides = []): array
    {
        return array_merge([
            'location' => 'Cebu City',
            'industry' => 'cosmetics',
            'product_keywords' => ['lipstick'],
        ], $overrides);
    }

    public function test_a_manager_can_run_discovery(): void
    {
        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args());

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('prospects', $result);
        $this->assertArrayHasKey('notice', $result);
        $this->assertArrayHasKey('searched_queries', $result);
    }

    public function test_a_team_head_can_run_discovery(): void
    {
        $result = $this->tool()->execute(User::factory()->teamHead()->create(), $this->args());

        $this->assertArrayHasKey('prospects', $result);
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
            $this->assertSame([], $search->queries, 'No search must be issued for invalid criteria.');
        }
    }

    public function test_overlong_criteria_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->tool()->execute(User::factory()->manager()->create(), [
            'location' => str_repeat('c', 120),
            'industry' => str_repeat('a', 120),
            'product_keywords' => array_map(fn (int $i) => 'product'.$i.str_repeat('x', 30), range(0, 9)),
        ]);
    }

    public function test_the_result_is_capped_by_the_application_max_even_if_the_model_asks_for_more(): void
    {
        config(['services.market_intelligence.max_results' => 5]);

        $result = $this->tool()->execute(User::factory()->manager()->create(), $this->args(['max_results' => 999]));

        $this->assertLessThanOrEqual(5, $result['criteria']['max_results']);
    }

    public function test_the_tool_definition_exposes_only_structured_criteria_no_url_parameter(): void
    {
        $params = $this->tool()->definition()->parameters;

        $this->assertSame('discover_prospects', $this->tool()->definition()->name);
        $this->assertArrayHasKey('location', $params['properties']);
        $this->assertArrayHasKey('industry', $params['properties']);
        $this->assertArrayNotHasKey('url', $params['properties']);
        $this->assertArrayNotHasKey('query', $params['properties']);
        $this->assertSame(
            DiscoveryCriteria::ALLOWED_ONLINE_SIGNALS,
            $params['properties']['online_signals']['items']['enum'],
        );
    }
}
