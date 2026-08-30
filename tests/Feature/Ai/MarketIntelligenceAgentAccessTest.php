<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Contracts\MarketIntelligence\SearchProvider;
use App\Enums\AgentIdentifier;
use App\Models\AgentInteraction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeLlmProvider;
use Tests\Support\FakeSearchProvider;
use Tests\TestCase;

/**
 * V2.1 (spec §11): the Market Intelligence agent is Manager + Team Head
 * only — verified at every layer (dropdown, explicit-selection
 * validation, and auto-routing fallback). A Team Member's discovery
 * question is never a 403; it falls back to the Sales agent.
 */
class MarketIntelligenceAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->app->instance(SearchProvider::class, FakeSearchProvider::withRows([]));
    }

    public function test_a_manager_sees_market_intelligence_in_the_assistant_dropdown(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/assistant')->assertOk()->assertSee('Market Intelligence');
    }

    public function test_a_team_head_sees_market_intelligence_in_the_assistant_dropdown(): void
    {
        $this->actingAs(User::factory()->teamHead()->create())
            ->get('/assistant')->assertOk()->assertSee('Market Intelligence');
    }

    public function test_a_team_member_does_not_see_market_intelligence_in_the_assistant_dropdown(): void
    {
        $this->actingAs(User::factory()->teamMember()->create())
            ->get('/assistant')->assertOk()->assertDontSee('Market Intelligence');
    }

    public function test_a_team_member_explicitly_selecting_market_intelligence_is_rejected_server_side(): void
    {
        $this->actingAs(User::factory()->teamMember()->create())->post('/assistant/messages', [
            'message' => 'Find businesses in Cebu selling cosmetics online.',
            'agent' => 'market_intelligence',
        ])->assertSessionHasErrors('agent');

        $this->assertDatabaseCount('agent_interactions', 0);
    }

    public function test_a_manager_can_explicitly_select_market_intelligence(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Here are some candidates.')]));

        $this->actingAs(User::factory()->manager()->create())->post('/assistant/messages', [
            'message' => 'Find businesses in Cebu selling cosmetics online.',
            'agent' => 'market_intelligence',
        ])->assertRedirect(route('assistant.show'));

        $this->assertSame('market_intelligence', AgentInteraction::firstOrFail()->agent);
    }

    public function test_auto_routing_lands_a_manager_on_market_intelligence_for_a_discovery_question(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('ok')]));

        $this->actingAs(User::factory()->manager()->create())->post('/assistant/messages', [
            'message' => 'Find businesses in Cebu City that sell cosmetics online.',
        ]);

        $this->assertSame('market_intelligence', AgentInteraction::firstOrFail()->agent);
    }

    public function test_a_team_members_discovery_question_falls_back_to_sales_not_a_403(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('ok')]));

        $this->actingAs(User::factory()->teamMember()->create())->post('/assistant/messages', [
            'message' => 'Find businesses in Cebu City that sell cosmetics online.',
        ])->assertRedirect(route('assistant.show'));

        $this->assertSame('sales', AgentInteraction::firstOrFail()->agent);
    }

    public function test_the_agent_identifier_eligibility_rule_directly(): void
    {
        $this->assertTrue(AgentIdentifier::MarketIntelligence->isAvailableTo(User::factory()->manager()->create()));
        $this->assertTrue(AgentIdentifier::MarketIntelligence->isAvailableTo(User::factory()->teamHead()->create()));
        $this->assertFalse(AgentIdentifier::MarketIntelligence->isAvailableTo(User::factory()->teamMember()->create()));
    }
}
