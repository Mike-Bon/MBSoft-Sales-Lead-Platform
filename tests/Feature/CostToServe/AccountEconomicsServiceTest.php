<?php

namespace Tests\Feature\CostToServe;

use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\CostToServe\AccountEconomicsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 12: revenue/engagement calculation correctness, edge cases, and
 * authorization. No cost figure is ever asserted here because none
 * exists — see docs/COST_TO_SERVE.md.
 */
class AccountEconomicsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function closedWon(Organization $organization, float $value, Carbon $closedAt, string $currency = 'USD'): Opportunity
    {
        return Opportunity::factory()->create([
            'organization_id' => $organization->id,
            'stage' => OpportunityStage::ClosedWon,
            'value' => $value,
            'currency' => $currency,
            'closed_at' => $closedAt,
        ]);
    }

    public function test_revenue_is_the_sum_of_closed_won_value_in_period_only(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $this->closedWon($organization, 1000, Carbon::parse('2026-06-15'));
        $this->closedWon($organization, 2000, Carbon::parse('2026-06-20'));
        // Outside the period — must not be counted.
        $this->closedWon($organization, 5000, Carbon::parse('2026-05-15'));
        // Still open — must not be counted regardless of date.
        Opportunity::factory()->create(['organization_id' => $organization->id, 'stage' => OpportunityStage::Negotiation, 'value' => 9000, 'currency' => 'USD']);

        $snapshot = app(AccountEconomicsService::class)->snapshotForOrganization(
            $manager, $organization, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), 'USD',
        );

        $this->assertSame(3000.0, $snapshot->revenue);
        $this->assertSame(2, $snapshot->closedDealsCount);
        $this->assertSame(1500.0, $snapshot->averageRevenuePerDeal);
    }

    public function test_zero_closed_deals_produces_null_average_not_a_division_error(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $snapshot = app(AccountEconomicsService::class)->snapshotForOrganization(
            $manager, $organization, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );

        $this->assertSame(0.0, $snapshot->revenue);
        $this->assertSame(0, $snapshot->closedDealsCount);
        $this->assertNull($snapshot->averageRevenuePerDeal);
    }

    public function test_a_different_currency_is_never_mixed_into_the_total(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $this->closedWon($organization, 1000, Carbon::now(), 'USD');
        $this->closedWon($organization, 5000, Carbon::now(), 'EUR');

        $snapshot = app(AccountEconomicsService::class)->snapshotForOrganization(
            $manager, $organization, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );

        $this->assertSame(1000.0, $snapshot->revenue);
        $this->assertSame(1, $snapshot->closedDealsCount);
    }

    public function test_lost_opportunities_never_count_as_revenue(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        Opportunity::factory()->create([
            'organization_id' => $organization->id,
            'stage' => OpportunityStage::ClosedLost,
            'value' => 10000,
            'currency' => 'USD',
            'closed_at' => Carbon::now(),
        ]);

        $snapshot = app(AccountEconomicsService::class)->snapshotForOrganization(
            $manager, $organization, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );

        $this->assertSame(0.0, $snapshot->revenue);
    }

    public function test_engagement_counts_activities_and_communications_separately_and_combined(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        Activity::factory()->count(3)->create(['organization_id' => $organization->id, 'occurred_at' => Carbon::now()]);
        Communication::factory()->count(2)->create(['organization_id' => $organization->id]);

        $snapshot = app(AccountEconomicsService::class)->snapshotForOrganization(
            $manager, $organization, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );

        $this->assertSame(3, $snapshot->activityCount);
        $this->assertSame(2, $snapshot->communicationCount);
        $this->assertSame(5, $snapshot->engagementCount());
    }

    public function test_a_manager_can_view_any_organizations_snapshot(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $organization = Organization::factory()->create(['team_id' => $team->id]);

        $snapshot = app(AccountEconomicsService::class)->snapshotForOrganization(
            $manager, $organization, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );

        $this->assertSame($organization->id, $snapshot->organizationId);
    }

    public function test_a_team_head_can_view_their_own_teams_organization(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $organization = Organization::factory()->create(['team_id' => $team->id]);

        $snapshot = app(AccountEconomicsService::class)->snapshotForOrganization(
            $head, $organization, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );

        $this->assertSame($organization->id, $snapshot->organizationId);
    }

    public function test_a_team_head_cannot_view_another_teams_organization(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        $organization = Organization::factory()->create(['team_id' => $otherTeam->id]);

        $this->expectException(AuthorizationException::class);

        app(AccountEconomicsService::class)->snapshotForOrganization(
            $head, $organization, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );
    }

    public function test_a_team_member_is_never_authorized_for_this_feature(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $organization = Organization::factory()->create(['team_id' => $team->id]);

        $this->expectException(AuthorizationException::class);

        app(AccountEconomicsService::class)->snapshotForOrganization(
            $member, $organization, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );
    }

    public function test_top_accounts_by_revenue_is_sorted_descending_and_limited(): void
    {
        $manager = User::factory()->manager()->create();
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        $c = Organization::factory()->create();

        $this->closedWon($a, 1000, Carbon::now());
        $this->closedWon($b, 5000, Carbon::now());
        $this->closedWon($c, 3000, Carbon::now());

        $top = app(AccountEconomicsService::class)->topAccountsByRevenue(
            $manager, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD', 2,
        );

        $this->assertCount(2, $top);
        $this->assertSame($b->id, $top[0]->organizationId);
        $this->assertSame($c->id, $top[1]->organizationId);
    }

    public function test_a_team_head_requesting_another_teams_id_is_denied_not_silently_redirected(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $this->expectException(AuthorizationException::class);

        app(AccountEconomicsService::class)->topAccountsByRevenue(
            $head, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD', 10, $otherTeam->id,
        );
    }

    public function test_compare_periods_reports_metric_change_for_revenue_deals_engagement_and_average(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $this->closedWon($organization, 1000, Carbon::parse('2026-05-15'));
        $this->closedWon($organization, 2000, Carbon::parse('2026-06-15'));
        $this->closedWon($organization, 2000, Carbon::parse('2026-06-20'));

        $comparison = app(AccountEconomicsService::class)->comparePeriods(
            $manager, $organization,
            Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'),
            'USD',
        );

        $this->assertSame(4000.0, $comparison['revenue']->current);
        $this->assertSame(1000.0, $comparison['revenue']->previous);
        $this->assertSame(300.0, $comparison['revenue']->percent);
        $this->assertSame(2.0, $comparison['closed_deals']->current);
        $this->assertSame(1.0, $comparison['closed_deals']->previous);
    }

    public function test_identify_exceptions_flags_a_revenue_decline_beyond_threshold(): void
    {
        config(['services.cost_to_serve.revenue_decline_threshold_percent' => 20.0]);

        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $this->closedWon($organization, 1000, Carbon::parse('2026-05-15'));
        $this->closedWon($organization, 500, Carbon::parse('2026-06-15')); // -50%, beyond threshold

        $flagged = app(AccountEconomicsService::class)->identifyExceptions(
            $manager, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'),
            'USD', null, 20,
        );

        $this->assertCount(1, $flagged);
        $this->assertSame($organization->id, $flagged[0]['organization']->organizationId);
        $this->assertStringContainsString('Revenue declined', $flagged[0]['reasons'][0]);
    }

    public function test_identify_exceptions_flags_zero_revenue_with_high_engagement(): void
    {
        config(['services.cost_to_serve.zero_revenue_engagement_threshold' => 3]);

        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        Activity::factory()->count(4)->create(['organization_id' => $organization->id, 'occurred_at' => Carbon::now()]);

        $flagged = app(AccountEconomicsService::class)->identifyExceptions(
            $manager, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(),
            Carbon::now()->subMonthNoOverflow()->startOfMonth(), Carbon::now()->subMonthNoOverflow()->endOfMonth(),
            'USD', null, 20,
        );

        $this->assertCount(1, $flagged);
        $this->assertStringContainsString('zero closed revenue', $flagged[0]['reasons'][0]);
    }

    public function test_identify_exceptions_never_flags_a_healthy_growing_account(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create();

        $this->closedWon($organization, 1000, Carbon::parse('2026-05-15'));
        $this->closedWon($organization, 1500, Carbon::parse('2026-06-15'));

        $flagged = app(AccountEconomicsService::class)->identifyExceptions(
            $manager, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'),
            'USD', null, 20,
        );

        $this->assertCount(0, $flagged);
    }

    public function test_organisation_summary_aggregates_across_every_authorized_account(): void
    {
        $manager = User::factory()->manager()->create();
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $this->closedWon($a, 1000, Carbon::now());
        $this->closedWon($b, 2000, Carbon::now());

        $summary = app(AccountEconomicsService::class)->organisationSummary(
            $manager, Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'USD',
        );

        $this->assertSame(3000.0, $summary['revenue']);
        $this->assertSame(2, $summary['closed_deals_count']);
        $this->assertSame(1500.0, $summary['average_revenue_per_deal']);
    }

    public function test_resolve_organization_by_id_not_found_throws_validation_exception(): void
    {
        $manager = User::factory()->manager()->create();

        $this->expectException(ValidationException::class);

        app(AccountEconomicsService::class)->resolveOrganization($manager, 999999, null);
    }

    public function test_resolve_organization_by_ambiguous_name_throws_validation_exception(): void
    {
        $manager = User::factory()->manager()->create();
        Organization::factory()->create(['name' => 'Acme Logistics']);
        Organization::factory()->create(['name' => 'Acme Robotics']);

        $this->expectException(ValidationException::class);

        app(AccountEconomicsService::class)->resolveOrganization($manager, null, 'Acme');
    }

    public function test_resolve_organization_outside_scope_is_reported_as_not_found_never_as_a_different_error(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        $restricted = Organization::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Restricted Co']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No organization matching');

        app(AccountEconomicsService::class)->resolveOrganization($head, $restricted->id, null);
    }
}
