<?php

namespace Tests\Feature\Performance;

use App\Enums\OpportunityStage;
use App\Enums\PerformancePeriodState;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The explicit edge-case list from STEP 25.
 */
class PerformanceEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PerformanceService
    {
        return app(PerformanceService::class);
    }

    public function test_no_target_produces_undefined_achievement_not_a_misleading_number(): void
    {
        $team = Team::factory()->create();

        $snapshot = $this->service()->forTeam($team, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertFalse($snapshot->hasTarget);
        $this->assertNull($snapshot->achievementPercent);
        $this->assertNull($snapshot->pipelineCoverage);
    }

    public function test_no_sales_produces_zero_actual_and_zero_percent_achievement(): void
    {
        $manager = User::factory()->manager()->create();
        $target = Target::factory()->manager($manager)->monthly(Carbon::parse('2026-01-15'))->amount(10000)->create();

        $snapshot = $this->service()->forTarget($target);

        $this->assertSame(0.0, $snapshot->actual);
        $this->assertSame(0.0, $snapshot->achievementPercent);
        $this->assertSame(10000.0, $snapshot->remainingTarget);
    }

    public function test_no_pipeline_produces_zero_coverage_not_undefined(): void
    {
        $manager = User::factory()->manager()->create();
        $target = Target::factory()->manager($manager)->monthly(Carbon::parse('2026-01-15'))->amount(10000)->create();

        $snapshot = $this->service()->forTarget($target);

        // Remaining target > 0 and pipeline = 0 is well-defined (0
        // coverage), unlike remaining target = 0 (undefined).
        $this->assertSame(0.0, $snapshot->pipeline);
        $this->assertSame(0.0, $snapshot->pipelineCoverage);
    }

    public function test_target_exceeded_shows_overachievement_correctly(): void
    {
        $manager = User::factory()->manager()->create();
        $target = Target::factory()->manager($manager)->monthly(Carbon::parse('2026-01-15'))->amount(1000000)->create();

        Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::ClosedWon)->create(['value' => 1200000, 'closed_at' => '2026-01-10']);

        $snapshot = $this->service()->forTarget($target);

        $this->assertSame(120.0, $snapshot->achievementPercent);
        $this->assertSame(-200000.0, $snapshot->gap);
        $this->assertSame(0.0, $snapshot->remainingTarget);
    }

    public function test_zero_target_is_handled_without_a_division_by_zero_error(): void
    {
        $manager = User::factory()->manager()->create();
        $target = Target::factory()->manager($manager)->monthly(Carbon::parse('2026-01-15'))->amount(0)->create();

        $snapshot = $this->service()->forTarget($target);

        $this->assertNull($snapshot->achievementPercent);
    }

    public function test_future_target_is_handled(): void
    {
        $manager = User::factory()->manager()->create();
        $target = Target::factory()->manager($manager)->monthly(Carbon::parse('2026-06-15'))->amount(10000)->create();

        $snapshot = $this->service()->compute(true, 10000, 'USD', 0, 0, $target->period_start, $target->period_end, Carbon::parse('2026-01-01'));

        $this->assertSame(PerformancePeriodState::Future, $snapshot->periodState);
        $this->assertNull($snapshot->runRate);
    }

    public function test_completed_target_is_handled(): void
    {
        $manager = User::factory()->manager()->create();
        $target = Target::factory()->manager($manager)->monthly(Carbon::parse('2026-01-15'))->amount(10000)->create();

        $snapshot = $this->service()->compute(true, 10000, 'USD', 4000, 0, $target->period_start, $target->period_end, Carbon::parse('2026-06-01'));

        $this->assertSame(PerformancePeriodState::Completed, $snapshot->periodState);
        $this->assertNull($snapshot->requiredRunRate);
    }

    public function test_an_opportunity_only_counts_toward_its_own_team_never_another(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $headA = User::factory()->teamHead($teamA)->create();

        $opportunity = Opportunity::factory()->ownedBy($headA)->stage(OpportunityStage::ClosedWon)->create(['value' => 5000, 'closed_at' => '2026-01-10']);

        $this->assertSame($teamA->id, $opportunity->team_id);

        $pipelineB = $this->service()->openPipeline(Opportunity::query()->where('team_id', $teamB->id));
        $actualB = $this->service()->actualSales(Opportunity::query()->where('team_id', $teamB->id), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame(0.0, $pipelineB);
        $this->assertSame(0.0, $actualB);
    }

    public function test_a_team_less_personal_opportunity_still_counts_for_its_owners_individual_performance(): void
    {
        $manager = User::factory()->manager()->create();
        $opportunity = Opportunity::factory()->create([
            'owner_id' => $manager->id,
            'team_id' => null,
            'stage' => OpportunityStage::ClosedWon,
            'value' => 3000,
            'closed_at' => '2026-01-10',
        ]);

        $this->assertNull($opportunity->team_id);

        $actual = $this->service()->actualSales(
            Opportunity::query()->where('owner_id', $manager->id),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame(3000.0, $actual);
    }

    public function test_currency_mismatch_excludes_the_opportunity_rather_than_mixing_totals(): void
    {
        $manager = User::factory()->manager()->create();
        $target = Target::factory()->manager($manager)->monthly(Carbon::parse('2026-01-15'))->amount(10000)->create(['currency' => 'USD']);

        Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::ClosedWon)->create(['value' => 5000, 'currency' => 'USD', 'closed_at' => '2026-01-10']);
        Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::ClosedWon)->create(['value' => 5000, 'currency' => 'EUR', 'closed_at' => '2026-01-10']);

        $snapshot = $this->service()->forTarget($target);

        // Only the USD opportunity is counted — EUR is excluded rather
        // than incorrectly summed into a USD total.
        $this->assertSame(5000.0, $snapshot->actual);
    }

    public function test_closed_won_without_a_close_date_does_not_count_as_actual(): void
    {
        $manager = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($manager)->create([
            'stage' => OpportunityStage::ClosedWon,
            'value' => 5000,
            'closed_at' => null,
        ]);

        $actual = $this->service()->actualSales(
            Opportunity::query()->where('owner_id', $manager->id),
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        );

        $this->assertSame(0.0, $actual);
    }
}
