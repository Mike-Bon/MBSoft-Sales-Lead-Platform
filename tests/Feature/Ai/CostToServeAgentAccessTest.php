<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentIdentifier;
use App\Models\AgentInteraction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * Phase 12 STEP 19/20 + Phase 12A: the Cost-to-Serve agent is
 * Manager-only AND only while the global feature switch is on —
 * verified at every layer (dropdown, explicit-selection validation,
 * and auto-routing fallback), never trusting the client to only offer
 * an eligible choice. The exhaustive access matrix and bypass vectors
 * live in Tests\Feature\CostToServe\CostToServeAccessPolicyTest.
 */
class CostToServeAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_does_not_see_cost_to_serve_in_the_assistant_dropdown(): void
    {
        $member = User::factory()->create();

        $response = $this->actingAs($member)->get('/assistant');

        $response->assertOk()->assertDontSee('Cost-to-Serve Intelligence');
    }

    public function test_a_manager_sees_cost_to_serve_in_the_assistant_dropdown(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->get('/assistant');

        $response->assertOk()->assertSee('Cost-to-Serve Intelligence');
    }

    public function test_a_team_head_does_not_see_cost_to_serve_in_the_assistant_dropdown(): void
    {
        $head = User::factory()->teamHead()->create();

        $response = $this->actingAs($head)->get('/assistant');

        $response->assertOk()->assertDontSee('Cost-to-Serve Intelligence');
    }

    public function test_a_manager_does_not_see_cost_to_serve_in_the_dropdown_when_the_feature_is_off(): void
    {
        Setting::setValue('cost_to_serve.enabled', 'false');
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->get('/assistant');

        $response->assertOk()->assertDontSee('Cost-to-Serve Intelligence');
    }

    public function test_a_team_member_explicitly_selecting_cost_to_serve_is_rejected_server_side(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->post('/assistant/messages', [
            'message' => 'Which accounts are expensive to serve?',
            'agent' => 'cost_to_serve',
        ])->assertSessionHasErrors('agent');

        $this->assertDatabaseCount('agent_interactions', 0);
    }

    public function test_a_manager_can_explicitly_select_cost_to_serve(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Revenue summary follows.')]));
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/assistant/messages', [
            'message' => 'Which accounts are expensive to serve?',
            'agent' => 'cost_to_serve',
        ])->assertRedirect(route('assistant.show'));

        $this->assertSame('cost_to_serve', AgentInteraction::firstOrFail()->agent);
    }

    public function test_auto_routing_never_lands_a_team_member_on_cost_to_serve_even_with_matching_keywords(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('ok')]));
        $member = User::factory()->create();

        $this->actingAs($member)->post('/assistant/messages', [
            'message' => 'What is our cost-to-serve for this account?',
        ]);

        $this->assertNotSame('cost_to_serve', AgentInteraction::firstOrFail()->agent);
    }

    public function test_auto_routing_lands_a_manager_on_cost_to_serve_for_matching_keywords(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('ok')]));
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/assistant/messages', [
            'message' => 'What is our cost-to-serve for this account?',
        ]);

        $this->assertSame('cost_to_serve', AgentInteraction::firstOrFail()->agent);
    }

    public function test_auto_routing_never_lands_a_manager_on_cost_to_serve_when_the_feature_is_off(): void
    {
        Setting::setValue('cost_to_serve.enabled', 'false');
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('ok')]));
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/assistant/messages', [
            'message' => 'What is our cost-to-serve for this account?',
        ]);

        $this->assertNotSame('cost_to_serve', AgentInteraction::firstOrFail()->agent);
    }

    public function test_a_manager_explicitly_selecting_cost_to_serve_is_rejected_when_the_feature_is_off(): void
    {
        Setting::setValue('cost_to_serve.enabled', 'false');
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/assistant/messages', [
            'message' => 'Which accounts are expensive to serve?',
            'agent' => 'cost_to_serve',
        ])->assertSessionHasErrors('agent');

        $this->assertDatabaseCount('agent_interactions', 0);
    }

    public function test_the_agent_identifier_enum_eligibility_rule_directly(): void
    {
        $manager = User::factory()->manager()->create();
        $head = User::factory()->teamHead()->create();
        $member = User::factory()->teamMember()->create();

        // Feature on by default: only the Manager is ever eligible.
        $this->assertTrue(AgentIdentifier::CostToServe->isAvailableTo($manager));
        $this->assertFalse(AgentIdentifier::CostToServe->isAvailableTo($head));
        $this->assertFalse(AgentIdentifier::CostToServe->isAvailableTo($member));

        // Feature off: not even the Manager.
        Setting::setValue('cost_to_serve.enabled', 'false');
        $this->assertFalse(AgentIdentifier::CostToServe->isAvailableTo($manager->fresh()));
        $this->assertFalse(AgentIdentifier::CostToServe->isAvailableTo($head->fresh()));

        // Every other agent is unaffected by any of this.
        foreach ([AgentIdentifier::Sales, AgentIdentifier::Performance, AgentIdentifier::Communication] as $agent) {
            $this->assertTrue($agent->isAvailableTo($member));
        }
    }
}
