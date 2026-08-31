<?php

namespace Tests\Feature\MarketIntelligence;

use App\Enums\AgentIdentifier;
use App\Models\User;
use App\Services\Ai\AgentRegistry;
use App\Services\Ai\ToolRegistry;
use App\Support\MarketIntelligence\DuplicateMatchPolicy;
use App\Support\MarketIntelligence\ScoringModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * V2.6 (spec §8/§43): the frozen shape of the Market Intelligence
 * capability. If any of these fail, the V2 freeze is broken.
 */
class V2FreezeInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private function miTools(): ToolRegistry
    {
        return app(AgentRegistry::class)->get(AgentIdentifier::MarketIntelligence)->tools;
    }

    public function test_the_market_intelligence_agent_has_exactly_the_six_frozen_tools(): void
    {
        $names = collect($this->miTools()->definitions())->pluck('name')->sort()->values()->all();

        $this->assertSame([
            'check_prospect_duplicates',
            'discover_prospects',
            'prepare_prospect_for_crm',
            'qualify_prospects',
            'score_prospects',
            'search_knowledge',
        ], $names);
    }

    public function test_the_market_intelligence_agent_has_no_dangerous_tool(): void
    {
        $tools = $this->miTools();

        foreach ([
            // CRM writes / confirmation
            'create_lead', 'update_lead', 'delete_lead', 'assign_lead', 'set_lead_status',
            'create_organization', 'update_organization', 'delete_organization',
            'create_contact', 'create_opportunity', 'create_activity',
            'confirm_prospect', 'confirm_prospect_lead', 'confirm_lead', 'approve_proposal',
            // unrestricted CRM read
            'search_leads', 'get_lead', 'search_opportunities', 'get_opportunity',
            'search_contacts', 'get_contact', 'search_accounts', 'search_organizations',
            // outreach
            'send_email', 'send_whatsapp', 'draft_email', 'draft_whatsapp',
            // cost-to-serve
            'get_customer_revenue_summary', 'get_customer_engagement_summary',
            'get_revenue_concentration', 'compare_account_period', 'identify_revenue_exceptions',
            // performance
            'get_my_performance', 'get_team_performance',
        ] as $forbidden) {
            $this->assertNull($tools->find($forbidden), "MI agent must not have {$forbidden}.");
        }
    }

    public function test_no_market_intelligence_tool_name_hints_at_a_write(): void
    {
        foreach ($this->miTools()->definitions() as $tool) {
            foreach (['sql', 'query', 'raw', 'create', 'update', 'delete', 'assign', 'send', 'draft', 'confirm', 'approve', 'merge'] as $verb) {
                $this->assertStringNotContainsStringIgnoringCase($verb, $tool->name);
            }
        }
    }

    public function test_the_scoring_model_and_duplicate_policy_are_valid_out_of_the_box(): void
    {
        $model = ScoringModel::fromConfig();
        $this->assertTrue($model->configValid);
        $this->assertSame(100, $model->maxScore());

        $policy = DuplicateMatchPolicy::fromConfig();
        $this->assertTrue($policy->configValid);
        $this->assertTrue($policy->isValid());
    }

    public function test_only_managers_and_team_heads_are_eligible_for_market_intelligence(): void
    {
        $this->assertTrue(AgentIdentifier::MarketIntelligence->isAvailableTo(User::factory()->manager()->create()));
        $this->assertTrue(AgentIdentifier::MarketIntelligence->isAvailableTo(User::factory()->teamHead()->create()));
        $this->assertFalse(AgentIdentifier::MarketIntelligence->isAvailableTo(User::factory()->teamMember()->create()));
    }

    public function test_every_market_intelligence_config_key_has_a_safe_default_and_no_secret(): void
    {
        $mi = config('services.market_intelligence');

        $this->assertSame(20, $mi['max_results']);
        $this->assertSame(100, array_sum($mi['scoring']['weights']));
        $this->assertSame('v2.4-default-1', $mi['duplicate_check']['policy_version']);
        $this->assertSame('v2.5-default-1', $mi['lead_creation']['policy_version']);
        $this->assertSame('Market Intelligence', $mi['lead_creation']['default_lead_source']);

        // The search provider defaults to unconfigured (no live calls),
        // and no API key is baked into config.
        $this->assertNull(config('services.search.provider'));
        $this->assertSame('', config('services.search.brave.api_key'));
    }

    public function test_the_prospect_lead_proposals_migration_is_the_last_pending_v2_migration(): void
    {
        // The full test suite runs every migration on a fresh database
        // via RefreshDatabase, so ordering + syntax are already proven.
        // This just pins that the V2.5 table exists with its columns.
        $columns = Schema::getColumnListing('prospect_lead_proposals');

        foreach ([
            'id', 'user_id', 'status', 'eligibility', 'policy_version', 'fingerprint',
            'business_name', 'website', 'domain', 'prospect_snapshot', 'proposed_organization',
            'proposed_lead', 'duplicate_check_status', 'duplicate_status', 'duplicate_ack_required',
            'organization_id', 'lead_id', 'expires_at', 'decided_at', 'decided_by',
        ] as $column) {
            $this->assertContains($column, $columns, "prospect_lead_proposals is missing {$column}.");
        }
    }
}
