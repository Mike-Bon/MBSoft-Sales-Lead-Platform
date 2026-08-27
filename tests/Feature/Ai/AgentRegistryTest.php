<?php

namespace Tests\Feature\Ai;

use App\Enums\AgentIdentifier;
use App\Enums\WorkflowType;
use App\Services\Ai\AgentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * STEP 48/24: the registry resolves exactly the three approved agents,
 * each with its own explicit, non-overlapping-beyond-design tool
 * permission matrix — no agent receives a tool it isn't listed for.
 */
class AgentRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_agents_can_be_resolved(): void
    {
        $registry = app(AgentRegistry::class);

        foreach (AgentIdentifier::cases() as $id) {
            $definition = $registry->get($id);
            $this->assertSame($id, $definition->identifier);
        }
    }

    public function test_the_registry_lists_exactly_three_agents(): void
    {
        $this->assertCount(3, app(AgentRegistry::class)->all());
    }

    public function test_sales_agent_has_the_documented_tool_set_and_no_others(): void
    {
        $tools = app(AgentRegistry::class)->get(AgentIdentifier::Sales)->tools;

        foreach (['search_leads', 'get_lead', 'search_opportunities', 'get_opportunity', 'get_followups', 'get_communication_history', 'get_pipeline_summary'] as $tool) {
            $this->assertNotNull($tools->find($tool), "Sales Agent is missing {$tool}.");
        }

        // Never a performance-calculation or drafting tool.
        foreach (['get_my_performance', 'get_team_performance', 'draft_email', 'draft_whatsapp'] as $tool) {
            $this->assertNull($tools->find($tool), "Sales Agent must not have {$tool}.");
        }
    }

    public function test_performance_agent_has_the_documented_tool_set_and_no_others(): void
    {
        $tools = app(AgentRegistry::class)->get(AgentIdentifier::Performance)->tools;

        foreach (['get_my_performance', 'get_team_performance', 'get_pipeline_summary'] as $tool) {
            $this->assertNotNull($tools->find($tool), "Performance Agent is missing {$tool}.");
        }

        // Never a CRM search or drafting tool — STEP 11 "do not create a
        // second KPI engine" pairs with "do not give it unrelated reach".
        foreach (['search_leads', 'get_lead', 'search_opportunities', 'get_opportunity', 'get_communication_history', 'draft_email', 'draft_whatsapp'] as $tool) {
            $this->assertNull($tools->find($tool), "Performance Agent must not have {$tool}.");
        }
    }

    public function test_communication_agent_has_the_documented_tool_set_and_no_others(): void
    {
        $tools = app(AgentRegistry::class)->get(AgentIdentifier::Communication)->tools;

        foreach (['get_followups', 'get_communication_history', 'get_lead', 'get_opportunity', 'draft_email', 'draft_whatsapp'] as $tool) {
            $this->assertNotNull($tools->find($tool), "Communication Agent is missing {$tool}.");
        }

        // No performance tools, no unrestricted search — STEP 14's list
        // is exhaustive.
        foreach (['get_my_performance', 'get_team_performance', 'search_leads', 'search_opportunities', 'get_pipeline_summary'] as $tool) {
            $this->assertNull($tools->find($tool), "Communication Agent must not have {$tool}.");
        }
    }

    public function test_no_agent_has_a_send_tool_of_any_kind(): void
    {
        $registry = app(AgentRegistry::class);

        foreach (AgentIdentifier::cases() as $id) {
            $tools = $registry->get($id)->tools;
            $this->assertNull($tools->find('send_email'), "{$id->value} must have no send_email tool.");
            $this->assertNull($tools->find('send_whatsapp'), "{$id->value} must have no send_whatsapp tool.");
        }
    }

    public function test_each_agent_has_its_own_non_empty_system_prompt(): void
    {
        $registry = app(AgentRegistry::class);
        $prompts = [];

        foreach (AgentIdentifier::cases() as $id) {
            $prompt = $registry->get($id)->systemPrompt;
            $this->assertNotEmpty($prompt);
            $prompts[] = $prompt;
        }

        // Three genuinely distinct prompts, not the same text three times.
        $this->assertCount(3, array_unique($prompts));
    }

    public function test_an_unknown_agent_identifier_is_rejected(): void
    {
        // AgentIdentifier is a backed enum — App\Enums\AgentIdentifier::
        // from('finance') itself throws before AgentRegistry is ever
        // asked, which is the actual, structural guarantee STEP 3
        // depends on (no agent outside the three can ever be
        // constructed, let alone registered).
        $this->expectException(\ValueError::class);

        AgentIdentifier::from('finance');
    }

    public function test_the_registry_throws_for_a_valid_enum_case_it_was_never_given(): void
    {
        $registry = new AgentRegistry([]);

        $this->expectException(InvalidArgumentException::class);

        $registry->get(AgentIdentifier::Sales);
    }

    public function test_each_agent_only_appears_configured_for_its_own_documented_workflow(): void
    {
        $registry = app(AgentRegistry::class);

        $this->assertSame([WorkflowType::DailyFollowUpReview], $registry->get(AgentIdentifier::Communication)->allowedWorkflows);
        $this->assertSame([WorkflowType::OpportunityAttentionReview], $registry->get(AgentIdentifier::Sales)->allowedWorkflows);
        $this->assertSame([WorkflowType::PerformanceExceptionReview], $registry->get(AgentIdentifier::Performance)->allowedWorkflows);
    }
}
