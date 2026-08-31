<?php

namespace Tests\Feature\Performance;

use App\Models\PerformanceActualLine;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The additive FY-performance tables and their PostgreSQL-safe
 * idempotency keys. reporting_unit_id is nullable on plan lines, so a
 * plain composite UNIQUE would let two team-level lines through under
 * PG NULL semantics — hence the two PARTIAL unique indexes.
 */
class FiscalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reporting_unit_belongs_to_one_team_and_a_team_may_not_repeat_a_code(): void
    {
        $team = Team::factory()->create();
        ReportingUnit::factory()->for($team)->create(['code' => 'TABUN']);

        $this->expectException(QueryException::class);
        ReportingUnit::factory()->for($team)->create(['code' => 'TABUN']);
    }

    public function test_two_different_teams_may_share_a_reporting_unit_code(): void
    {
        $a = Team::factory()->create();
        $b = Team::factory()->create();

        ReportingUnit::factory()->for($a)->create(['code' => 'MAIN']);
        $second = ReportingUnit::factory()->for($b)->create(['code' => 'MAIN']);

        $this->assertTrue($second->exists);
        $this->assertSame(2, ReportingUnit::where('code', 'MAIN')->count());
    }

    public function test_a_branch_level_plan_line_is_unique_per_fiscal_month_team_and_unit(): void
    {
        $team = Team::factory()->create();
        $unit = ReportingUnit::factory()->for($team)->create();

        PerformancePlanLine::factory()->create(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id, 'reporting_unit_id' => $unit->id]);

        $this->expectException(QueryException::class);
        PerformancePlanLine::factory()->create(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id, 'reporting_unit_id' => $unit->id]);
    }

    public function test_a_team_level_plan_line_with_null_unit_is_still_unique_per_fiscal_month_and_team(): void
    {
        $team = Team::factory()->create();

        PerformancePlanLine::factory()->teamLevel()->create(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id]);

        // Under a naive PG composite UNIQUE this second NULL row would
        // slip through. The partial index catches it.
        $this->expectException(QueryException::class);
        PerformancePlanLine::factory()->teamLevel()->create(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id]);
    }

    public function test_a_team_level_and_a_branch_level_plan_line_can_coexist_for_the_same_month(): void
    {
        $team = Team::factory()->create();
        $unit = ReportingUnit::factory()->for($team)->create();

        PerformancePlanLine::factory()->teamLevel()->create(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id]);
        $branch = PerformancePlanLine::factory()->create(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id, 'reporting_unit_id' => $unit->id]);

        $this->assertTrue($branch->exists);
        $this->assertSame(2, PerformancePlanLine::where(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id])->count());
    }

    public function test_an_actual_line_is_unique_per_fiscal_month_team_and_unit(): void
    {
        $team = Team::factory()->create();
        $unit = ReportingUnit::factory()->for($team)->create();

        PerformanceActualLine::factory()->create(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id, 'reporting_unit_id' => $unit->id]);

        $this->expectException(QueryException::class);
        PerformanceActualLine::factory()->create(['fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $team->id, 'reporting_unit_id' => $unit->id]);
    }

    public function test_line_models_are_fully_guarded_against_mass_assignment(): void
    {
        $this->assertSame(['id'], (new PerformancePlanLine)->getGuarded());
        $this->assertSame(['id'], (new PerformanceActualLine)->getGuarded());
    }
}
