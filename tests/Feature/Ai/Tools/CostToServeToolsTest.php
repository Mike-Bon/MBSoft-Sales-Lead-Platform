<?php

namespace Tests\Feature\Ai\Tools;

use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Tools\CompareAccountPeriodTool;
use App\Services\Ai\Tools\GetCustomerEngagementSummaryTool;
use App\Services\Ai\Tools\GetCustomerRevenueSummaryTool;
use App\Services\Ai\Tools\GetRevenueConcentrationTool;
use App\Services\Ai\Tools\IdentifyRevenueExceptionsTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 12 + Phase 12A: the 5 Cost-to-Serve tool contracts — correct
 * data, source/data-gap fields present on every result, and
 * authorization re-derived from the actor (Manager only, feature switch
 * on) regardless of arguments, on every call.
 */
class CostToServeToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_revenue_summary_returns_curated_fields_with_source_and_data_gap(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create(['name' => 'Acme Logistics']);
        Opportunity::factory()->create([
            'organization_id' => $organization->id,
            'stage' => OpportunityStage::ClosedWon,
            'value' => 5000,
            'currency' => 'PHP',
            'closed_at' => Carbon::now(),
        ]);

        $result = app(GetCustomerRevenueSummaryTool::class)->execute($manager, ['organization_id' => $organization->id]);

        $this->assertSame(5000.0, $result['revenue']);
        $this->assertSame(1, $result['closed_deals_count']);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('data_gap', $result);
        $this->assertStringContainsString('no cost data', strtolower($result['data_gap']));
    }

    public function test_revenue_summary_resolves_by_organization_name(): void
    {
        $manager = User::factory()->manager()->create();
        Organization::factory()->create(['name' => 'Borealis Robotics']);

        $result = app(GetCustomerRevenueSummaryTool::class)->execute($manager, ['organization_name' => 'Borealis']);

        $this->assertSame('Borealis Robotics', $result['organization']);
    }

    public function test_engagement_summary_never_calls_touches_a_shipment_volume(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();
        Activity::factory()->count(2)->create(['organization_id' => $organization->id, 'occurred_at' => Carbon::now()]);

        $result = app(GetCustomerEngagementSummaryTool::class)->execute($manager, ['organization_id' => $organization->id]);

        $this->assertSame(2, $result['activity_count']);
        $this->assertArrayNotHasKey('cost', $result);
        $this->assertArrayNotHasKey('volume', $result);
    }

    public function test_revenue_concentration_ranks_by_revenue_and_carries_data_gap(): void
    {
        $manager = User::factory()->manager()->create();
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        Opportunity::factory()->create(['organization_id' => $a->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 1000, 'currency' => 'PHP', 'closed_at' => Carbon::now()]);
        Opportunity::factory()->create(['organization_id' => $b->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 9000, 'currency' => 'PHP', 'closed_at' => Carbon::now()]);

        $result = app(GetRevenueConcentrationTool::class)->execute($manager, []);

        $this->assertSame($b->id, $result['accounts'][0]['organization_id']);
        $this->assertArrayHasKey('data_gap', $result);
    }

    public function test_revenue_concentration_respects_the_configured_max_accounts(): void
    {
        config(['services.cost_to_serve.max_accounts_per_query' => 2]);
        $manager = User::factory()->manager()->create();

        foreach (range(1, 5) as $i) {
            $org = Organization::factory()->create();
            Opportunity::factory()->create(['organization_id' => $org->id, 'stage' => OpportunityStage::ClosedWon, 'value' => $i * 100, 'currency' => 'PHP', 'closed_at' => Carbon::now()]);
        }

        $result = app(GetRevenueConcentrationTool::class)->execute($manager, ['limit' => 100]);

        $this->assertCount(2, $result['accounts']);
    }

    public function test_a_team_head_is_denied_by_every_tool_regardless_of_arguments(): void
    {
        $ownTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        $ownOrg = Organization::factory()->create(['team_id' => $ownTeam->id, 'name' => 'Own Team Account']);
        Opportunity::factory()->create(['organization_id' => $ownOrg->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 100, 'currency' => 'PHP', 'closed_at' => Carbon::now()]);

        // Phase 12A: a Team Head has no Cost-to-Serve access at all —
        // not even to their own team's own account, and not even with
        // no arguments. assertAccess() rejects before any lookup runs.
        foreach ([
            fn () => app(GetRevenueConcentrationTool::class)->execute($head, []),
            fn () => app(GetRevenueConcentrationTool::class)->execute($head, ['team_id' => $ownTeam->id]),
            fn () => app(GetCustomerRevenueSummaryTool::class)->execute($head, ['organization_id' => $ownOrg->id]),
            fn () => app(GetCustomerEngagementSummaryTool::class)->execute($head, ['organization_id' => $ownOrg->id]),
            fn () => app(IdentifyRevenueExceptionsTool::class)->execute($head, []),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected AuthorizationException for a Team Head.');
            } catch (AuthorizationException $e) {
                $this->assertSame('This action is unauthorized.', $e->getMessage());
            }
        }
    }

    public function test_every_tool_is_denied_for_a_manager_when_the_feature_switch_is_off(): void
    {
        Setting::setValue('cost_to_serve.enabled', 'false');
        $manager = User::factory()->manager()->create();
        $org = Organization::factory()->create();

        foreach ([
            fn () => app(GetRevenueConcentrationTool::class)->execute($manager, []),
            fn () => app(GetCustomerRevenueSummaryTool::class)->execute($manager, ['organization_id' => $org->id]),
            fn () => app(GetCustomerEngagementSummaryTool::class)->execute($manager, ['organization_id' => $org->id]),
            fn () => app(CompareAccountPeriodTool::class)->execute($manager, ['organization_id' => $org->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30']),
            fn () => app(IdentifyRevenueExceptionsTool::class)->execute($manager, []),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected AuthorizationException while the feature is off.');
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('disabled', $e->getMessage());
            }
        }
    }

    public function test_compare_account_period_defaults_the_previous_period_to_the_preceding_window(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $result = app(CompareAccountPeriodTool::class)->execute($manager, [
            'organization_id' => $organization->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        // Current period is 29 days (Jun 1-30 inclusive); the previous
        // period is the same-length window immediately before it.
        $this->assertSame('2026-05-31', $result['previous_period']['end']);
        $this->assertSame('2026-05-02', $result['previous_period']['start']);
        $this->assertArrayHasKey('data_gap', $result);
    }

    public function test_identify_revenue_exceptions_names_the_reason_for_every_flagged_account(): void
    {
        config(['services.cost_to_serve.revenue_decline_threshold_percent' => 10.0]);
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        Opportunity::factory()->create(['organization_id' => $organization->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 1000, 'currency' => 'PHP', 'closed_at' => Carbon::parse('2026-05-15')]);
        Opportunity::factory()->create(['organization_id' => $organization->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 100, 'currency' => 'PHP', 'closed_at' => Carbon::parse('2026-06-15')]);

        $result = app(IdentifyRevenueExceptionsTool::class)->execute($manager, [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        $this->assertSame(1, $result['count']);
        $this->assertNotEmpty($result['accounts'][0]['reasons']);
    }

    public function test_an_unresolvable_organization_name_raises_a_validation_error_not_a_crash(): void
    {
        $manager = User::factory()->manager()->create();

        $this->expectException(ValidationException::class);

        app(GetCustomerRevenueSummaryTool::class)->execute($manager, ['organization_name' => 'Nonexistent Co']);
    }
}
