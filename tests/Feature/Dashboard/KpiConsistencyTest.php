<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 21 items 18-23: every KPI the dashboard displays must be exactly
 * what PerformanceService itself computes for the same scope/period —
 * never a second, dashboard-local calculation.
 */
class KpiConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_kpis_equal_performance_service_output_for_organisation(): void
    {
        $manager = User::factory()->manager()->create();
        $target = Target::factory()->manager($manager)->create(['target_amount' => 75000]);

        Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::ClosedWon)->create(['value' => 20000, 'closed_at' => now()]);
        Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::Proposal)->create(['value' => 5000]);

        $expected = app(PerformanceService::class)->forTarget($target);

        $response = $this->actingAs($manager)->get('/dashboard');

        $response->assertViewHas('organisation', function ($actual) use ($expected) {
            return $actual->target === $expected->target
                && $actual->actual === $expected->actual
                && $actual->achievementPercent === $expected->achievementPercent
                && $actual->gap === $expected->gap
                && $actual->pipeline === $expected->pipeline
                && $actual->pipelineCoverage === $expected->pipelineCoverage;
        });
    }

    public function test_dashboard_kpis_equal_performance_service_output_for_team(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $target = Target::factory()->team($team)->create(['target_amount' => 40000]);

        Opportunity::factory()->ownedBy($head)->stage(OpportunityStage::ClosedWon)->create(['value' => 15000, 'closed_at' => now()]);
        Opportunity::factory()->ownedBy($head)->stage(OpportunityStage::Negotiation)->create(['value' => 8000]);

        $expected = app(PerformanceService::class)->forTarget($target);

        $response = $this->actingAs($head)->get('/dashboard');

        $response->assertViewHas('snapshot', function ($actual) use ($expected) {
            return $actual->target === $expected->target
                && $actual->actual === $expected->actual
                && $actual->achievementPercent === $expected->achievementPercent
                && $actual->gap === $expected->gap
                && $actual->pipeline === $expected->pipeline
                && $actual->pipelineCoverage === $expected->pipelineCoverage;
        });
    }

    public function test_dashboard_kpis_equal_performance_service_output_for_individual(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $target = Target::factory()->individual($member)->create(['target_amount' => 10000]);

        Opportunity::factory()->ownedBy($member)->stage(OpportunityStage::ClosedWon)->create(['value' => 12000, 'closed_at' => now()]);

        $expected = app(PerformanceService::class)->forTarget($target);

        $response = $this->actingAs($member)->get('/dashboard');

        $response->assertViewHas('snapshot', function ($actual) use ($expected) {
            return $actual->target === $expected->target
                && $actual->actual === $expected->actual
                && $actual->achievementPercent === $expected->achievementPercent
                && $actual->gap === $expected->gap
                && $actual->pipeline === $expected->pipeline
                && $actual->pipelineCoverage === $expected->pipelineCoverage;
        });

        // Also confirms overachievement (actual > target) surfaces
        // correctly through the dashboard, not just the raw service.
        $this->assertTrue($expected->isOverAchieved());
    }

    public function test_team_performance_drill_down_matches_performance_service(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $target = Target::factory()->team($team)->create(['target_amount' => 30000]);
        Opportunity::factory()->ownedBy($head)->stage(OpportunityStage::ClosedWon)->create(['value' => 10000, 'closed_at' => now()]);

        $expected = app(PerformanceService::class)->forTarget($target);

        $response = $this->actingAs($head)->get("/teams/{$team->id}/performance");

        $response->assertViewHas('snapshot', fn ($actual) => $actual->actual === $expected->actual && $actual->target === $expected->target);
    }
}
