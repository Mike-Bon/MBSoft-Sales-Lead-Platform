<?php

namespace Tests\Feature\Ai;

use App\Enums\AgentIdentifier;
use App\Enums\WorkflowType;
use App\Services\Ai\AgentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * STEP 48/24: the registry resolves exactly the six approved agents
 * (Sales, Performance, Communication, Phase 12's Cost-to-Serve, Phase
 * 13's Business Development, and V2.1's Market Intelligence), each with
 * its own explicit, non-overlapping-beyond-design tool permission
 * matrix — no agent receives a tool it isn't listed for.
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

    public function test_the_registry_lists_exactly_six_agents(): void
    {
        // Phase 12: Cost-to-Serve is the fourth; Phase 13: Business
        // Development is the fifth; V2.1: Market Intelligence is the
        // sixth — each a deliberate, explicitly-scoped expansion of the
        // closed set, and each still one instance of the same single
        // Agent engine.
        $this->assertCount(6, app(AgentRegistry::class)->all());
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

    /**
     * Phase 12: the fourth agent's own tool set — revenue/engagement
     * analysis only, never a CRM search/draft/performance-calculation
     * tool.
     */
    public function test_cost_to_serve_agent_has_the_documented_tool_set_and_no_others(): void
    {
        $tools = app(AgentRegistry::class)->get(AgentIdentifier::CostToServe)->tools;

        foreach (['get_customer_revenue_summary', 'get_customer_engagement_summary', 'get_revenue_concentration', 'compare_account_period', 'identify_revenue_exceptions'] as $tool) {
            $this->assertNotNull($tools->find($tool), "Cost-to-Serve Agent is missing {$tool}.");
        }

        foreach (['search_leads', 'get_lead', 'search_opportunities', 'get_opportunity', 'get_my_performance', 'get_team_performance', 'draft_email', 'draft_whatsapp', 'get_followups', 'get_communication_history'] as $tool) {
            $this->assertNull($tools->find($tool), "Cost-to-Serve Agent must not have {$tool}.");
        }
    }

    /**
     * Phase 13: the fifth agent's own tool set — the six read-only
     * Business Development analytical tools plus reused read + draft-only
     * tools. Never a create/update/assign/send/close tool of any kind,
     * and never a Cost-to-Serve tool.
     */
    public function test_business_development_agent_has_the_documented_tool_set_and_no_others(): void
    {
        $tools = app(AgentRegistry::class)->get(AgentIdentifier::BusinessDevelopment)->tools;

        foreach ([
            'prioritize_leads', 'identify_stale_leads', 'identify_follow_up_gaps',
            'identify_at_risk_opportunities', 'analyze_account', 'identify_missing_information',
            'search_leads', 'get_lead', 'search_opportunities', 'get_opportunity',
            'get_followups', 'get_pipeline_summary', 'get_communication_history',
            'get_team_performance', 'draft_email', 'draft_whatsapp', 'search_knowledge',
        ] as $tool) {
            $this->assertNotNull($tools->find($tool), "Business Development Agent is missing {$tool}.");
        }

        // No write/send/close tool, and no Cost-to-Serve tool.
        foreach ([
            'create_lead', 'update_lead', 'assign_lead', 'send_email', 'send_whatsapp',
            'close_opportunity', 'update_opportunity', 'set_lead_status',
            'get_customer_revenue_summary', 'get_revenue_concentration', 'identify_revenue_exceptions',
            'get_my_performance',
        ] as $tool) {
            $this->assertNull($tools->find($tool), "Business Development Agent must not have {$tool}.");
        }
    }

    /**
     * V2.1: the sixth agent's own tool set — external prospect discovery
     * only. discover_prospects + a scoped search_knowledge, and NOTHING
     * that touches the CRM, communications, performance, or
     * Cost-to-Serve. The absent tools below are the structural boundary
     * between hostile external web content and every internal capability.
     */
    public function test_market_intelligence_agent_has_the_documented_tool_set_and_no_others(): void
    {
        $tools = app(AgentRegistry::class)->get(AgentIdentifier::MarketIntelligence)->tools;

        foreach (['discover_prospects', 'search_knowledge'] as $tool) {
            $this->assertNotNull($tools->find($tool), "Market Intelligence Agent is missing {$tool}.");
        }

        $this->assertCount(2, $tools->definitions(), 'Market Intelligence Agent must have exactly two tools.');

        foreach ([
            'search_leads', 'get_lead', 'search_opportunities', 'get_opportunity',
            'get_followups', 'get_communication_history', 'get_pipeline_summary',
            'get_my_performance', 'get_team_performance',
            'draft_email', 'draft_whatsapp', 'send_email', 'send_whatsapp',
            'prioritize_leads', 'identify_stale_leads', 'analyze_account',
            'get_customer_revenue_summary', 'get_customer_engagement_summary',
            'get_revenue_concentration', 'compare_account_period', 'identify_revenue_exceptions',
            'create_lead', 'update_lead', 'assign_lead',
        ] as $tool) {
            $this->assertNull($tools->find($tool), "Market Intelligence Agent must not have {$tool}.");
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

        // Six genuinely distinct prompts, not the same text repeated.
        $this->assertCount(6, array_unique($prompts));
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
        // Phase 12, Phase 13, and V2.1 all add no scheduled workflow.
        $this->assertSame([], $registry->get(AgentIdentifier::CostToServe)->allowedWorkflows);
        $this->assertSame([], $registry->get(AgentIdentifier::BusinessDevelopment)->allowedWorkflows);
        $this->assertSame([], $registry->get(AgentIdentifier::MarketIntelligence)->allowedWorkflows);
    }

    /**
     * Phase 10 STEP 24: every agent gets its own search_knowledge
     * instance — never no knowledge tool, and never every agent sharing
     * one unrestricted instance.
     */
    public function test_every_agent_has_its_own_search_knowledge_tool(): void
    {
        $registry = app(AgentRegistry::class);

        foreach (AgentIdentifier::cases() as $id) {
            $this->assertNotNull($registry->get($id)->tools->find('search_knowledge'), "{$id->value} is missing search_knowledge.");
        }
    }

    /**
     * Phase 10 STEP 24/25: the knowledge-type permission matrix — each
     * agent's search_knowledge only ever names its own allowed types.
     */
    public function test_each_agents_search_knowledge_tool_names_only_its_own_allowed_knowledge_types(): void
    {
        $registry = app(AgentRegistry::class);

        $salesDescription = $registry->get(AgentIdentifier::Sales)->tools->find('search_knowledge')->definition()->description;
        $this->assertStringContainsString('Sales Playbook', $salesDescription);
        $this->assertStringContainsString('Product Guide', $salesDescription);
        $this->assertStringNotContainsString('Training', $salesDescription);

        $performanceDescription = $registry->get(AgentIdentifier::Performance)->tools->find('search_knowledge')->definition()->description;
        $this->assertStringContainsString('Policy', $performanceDescription);
        $this->assertStringContainsString('Training', $performanceDescription);
        $this->assertStringNotContainsString('Sales Playbook', $performanceDescription);

        $communicationDescription = $registry->get(AgentIdentifier::Communication)->tools->find('search_knowledge')->definition()->description;
        $this->assertStringContainsString('FAQ', $communicationDescription);
        $this->assertStringContainsString('Reference', $communicationDescription);
        $this->assertStringNotContainsString('Product Guide', $communicationDescription);

        $costToServeDescription = $registry->get(AgentIdentifier::CostToServe)->tools->find('search_knowledge')->definition()->description;
        $this->assertStringContainsString('Policy', $costToServeDescription);
        $this->assertStringNotContainsString('Sales Playbook', $costToServeDescription);
        $this->assertStringNotContainsString('Training', $costToServeDescription);

        $marketIntelligenceDescription = $registry->get(AgentIdentifier::MarketIntelligence)->tools->find('search_knowledge')->definition()->description;
        $this->assertStringContainsString('Sales Playbook', $marketIntelligenceDescription);
        $this->assertStringContainsString('Product Guide', $marketIntelligenceDescription);
        $this->assertStringNotContainsString('Policy', $marketIntelligenceDescription);
        $this->assertStringNotContainsString('Training', $marketIntelligenceDescription);
    }
}
