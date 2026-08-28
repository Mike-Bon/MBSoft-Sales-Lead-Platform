<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentIdentifier;
use App\Models\AgentInteraction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * Phase 13: the Business Development agent is Manager + Team Head only —
 * verified at every layer (dropdown, explicit-selection validation, and
 * auto-routing fallback). A Team Member's business-development question
 * is never a 403 — it falls back to the Sales agent, which already
 * covers lead prioritisation.
 */
class BusinessDevelopmentAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_sees_business_development_in_the_assistant_dropdown(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/assistant')->assertOk()->assertSee('Business Development');
    }

    public function test_a_team_head_sees_business_development_in_the_assistant_dropdown(): void
    {
        $head = User::factory()->teamHead()->create();

        $this->actingAs($head)->get('/assistant')->assertOk()->assertSee('Business Development');
    }

    public function test_a_team_member_does_not_see_business_development_in_the_assistant_dropdown(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/assistant')->assertOk()->assertDontSee('Business Development');
    }

    public function test_a_team_member_explicitly_selecting_business_development_is_rejected_server_side(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->post('/assistant/messages', [
            'message' => 'Which leads should I prioritize today?',
            'agent' => 'business_development',
        ])->assertSessionHasErrors('agent');

        $this->assertDatabaseCount('agent_interactions', 0);
    }

    public function test_a_manager_can_explicitly_select_business_development(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Here are your priorities.')]));
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/assistant/messages', [
            'message' => 'Which leads should I prioritize today?',
            'agent' => 'business_development',
        ])->assertRedirect(route('assistant.show'));

        $this->assertSame('business_development', AgentInteraction::firstOrFail()->agent);
    }

    public function test_auto_routing_lands_a_manager_on_business_development_for_a_prioritisation_question(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('ok')]));
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/assistant/messages', [
            'message' => 'Which leads should I prioritize today and why?',
        ]);

        $this->assertSame('business_development', AgentInteraction::firstOrFail()->agent);
    }

    public function test_a_team_members_prioritisation_question_falls_back_to_sales_not_a_403(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('ok')]));
        $member = User::factory()->create();

        $this->actingAs($member)->post('/assistant/messages', [
            'message' => 'Which leads should I prioritize today?',
        ])->assertRedirect(route('assistant.show'));

        $this->assertSame('sales', AgentInteraction::firstOrFail()->agent);
    }

    public function test_the_agent_identifier_eligibility_rule_directly(): void
    {
        $manager = User::factory()->manager()->create();
        $head = User::factory()->teamHead()->create();
        $member = User::factory()->create();

        $this->assertTrue(AgentIdentifier::BusinessDevelopment->isAvailableTo($manager));
        $this->assertTrue(AgentIdentifier::BusinessDevelopment->isAvailableTo($head));
        $this->assertFalse(AgentIdentifier::BusinessDevelopment->isAvailableTo($member));
    }
}
