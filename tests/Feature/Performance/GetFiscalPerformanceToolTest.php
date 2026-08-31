<?php

namespace Tests\Feature\Performance;

use App\Models\PerformanceActualLine;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Tools\GetFiscalPerformanceTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetFiscalPerformanceToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $cec;

    private Team $cbe;

    private ReportingUnit $tabun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cec = Team::factory()->create(['name' => 'CEC Team', 'code' => 'CEC']);
        $this->cbe = Team::factory()->create(['name' => 'CBE Team', 'code' => 'CBE']);
        $this->tabun = ReportingUnit::factory()->for($this->cec)->create(['code' => 'TABUN']);
        $gs = ReportingUnit::factory()->for($this->cbe)->create(['code' => 'GORORDO']);

        foreach ([$this->tabun, $gs] as $unit) {
            for ($k = 1; $k <= 9; $k++) {
                PerformancePlanLine::factory()->create(['fiscal_year' => 2026, 'period_month' => $k, 'team_id' => $unit->team_id, 'reporting_unit_id' => $unit->id, 'target_revenue' => 100_000 * $k, 'target_units' => 10 * $k]);
                PerformanceActualLine::factory()->create(['fiscal_year' => 2026, 'period_month' => $k, 'team_id' => $unit->team_id, 'reporting_unit_id' => $unit->id, 'actual_revenue' => 90_000 * $k, 'actual_units' => 9 * $k]);
            }
            // remaining plan months so a full FY target exists
            for ($k = 10; $k <= 12; $k++) {
                PerformancePlanLine::factory()->create(['fiscal_year' => 2026, 'period_month' => $k, 'team_id' => $unit->team_id, 'reporting_unit_id' => $unit->id, 'target_revenue' => 100_000 * $k, 'target_units' => 10 * $k]);
            }
        }
    }

    private function tool(): GetFiscalPerformanceTool
    {
        return app(GetFiscalPerformanceTool::class);
    }

    public function test_a_manager_gets_the_organisation_wide_figure(): void
    {
        $out = $this->tool()->execute(User::factory()->manager()->create(), ['fiscal_year' => 2026, 'as_of' => '2026-08-31']);

        $this->assertSame('organisation', $out['scope_type']);
        $this->assertSame(9, $out['through_fiscal_month']);
        // one unit per team, 100k*(1..12) FY = 7.8M, ×2 teams = 15.6M
        $this->assertSame(15_600_000.0, $out['fy_target_revenue']);
        // YTD phased Dec..Aug: 100k*(1..9)=4.5M ×2 = 9.0M
        $this->assertSame(9_000_000.0, $out['ytd_phased_target_revenue']);
        // YTD actual 90k*(1..9)=4.05M ×2 = 8.1M
        $this->assertSame(8_100_000.0, $out['ytd_actual_revenue']);
        $this->assertSame(90.0, $out['ytd_target_attainment_pct']);
        $this->assertSame(51.92, $out['fy_attainment_to_date_pct']);
        $this->assertNotSame($out['ytd_target_attainment_pct'], $out['fy_attainment_to_date_pct']);
        $this->assertArrayHasKey('team_totals', $out);
        $this->assertArrayHasKey('monthly_trend', $out);
    }

    public function test_a_team_head_omitting_team_id_gets_their_own_team_only(): void
    {
        $head = User::factory()->teamHead($this->cec)->create();

        $out = $this->tool()->execute($head, ['fiscal_year' => 2026, 'as_of' => '2026-08-31']);

        $this->assertSame('team', $out['scope_type']);
        $this->assertSame('CEC Team', $out['scope_name']);
        $this->assertSame(7_800_000.0, $out['fy_target_revenue']);
    }

    public function test_a_team_member_gets_their_own_team_operational_performance(): void
    {
        $member = User::factory()->teamMember($this->cec)->create();

        $out = $this->tool()->execute($member, ['fiscal_year' => 2026, 'as_of' => '2026-08-31']);

        $this->assertSame('team', $out['scope_type']);
        $this->assertSame('CEC Team', $out['scope_name']);
    }

    public function test_a_team_head_requesting_another_team_is_denied(): void
    {
        $head = User::factory()->teamHead($this->cec)->create();

        $this->expectException(AuthorizationException::class);
        $this->tool()->execute($head, ['team_id' => $this->cbe->id, 'fiscal_year' => 2026]);
    }

    public function test_a_team_member_cannot_request_the_organisation_wide_figure(): void
    {
        $member = User::factory()->teamMember($this->cec)->create();

        // No team_id + not a manager → falls back to own team, never org-wide.
        $out = $this->tool()->execute($member, ['fiscal_year' => 2026, 'as_of' => '2026-08-31']);
        $this->assertSame('team', $out['scope_type']);
    }

    public function test_a_team_member_requesting_a_foreign_reporting_unit_is_denied(): void
    {
        $foreignUnit = ReportingUnit::factory()->for($this->cbe)->create();
        $member = User::factory()->teamMember($this->cec)->create();

        $this->expectException(AuthorizationException::class);
        $this->tool()->execute($member, ['reporting_unit_id' => $foreignUnit->id, 'fiscal_year' => 2026]);
    }

    public function test_a_reporting_unit_scope_returns_just_that_branch(): void
    {
        $out = $this->tool()->execute(User::factory()->manager()->create(), ['reporting_unit_id' => $this->tabun->id, 'fiscal_year' => 2026, 'as_of' => '2026-08-31']);

        $this->assertSame('reporting_unit', $out['scope_type']);
        $this->assertSame(7_800_000.0, $out['fy_target_revenue']); // one unit
        $this->assertSame([], $out['reporting_unit_breakdown']);   // no nested breakdown for a single unit
    }

    public function test_the_result_explicitly_distinguishes_the_two_attainment_metrics(): void
    {
        $out = $this->tool()->execute(User::factory()->manager()->create(), ['fiscal_year' => 2026, 'as_of' => '2026-08-31']);

        $this->assertArrayHasKey('ytd_target_attainment_pct', $out);
        $this->assertArrayHasKey('fy_attainment_to_date_pct', $out);
        $this->assertStringContainsString('NOT CRM pipeline', $out['note']);
        $this->assertStringContainsString('phased target', $out['note']);
        $this->assertStringContainsString('full-year target', $out['note']);
    }

    public function test_the_fiscal_year_defaults_from_the_as_of_date(): void
    {
        $out = $this->tool()->execute(User::factory()->manager()->create(), ['as_of' => '2026-03-15']);

        $this->assertSame(2026, $out['fiscal_year']);
        $this->assertSame('FY2026', $out['fiscal_year_label']);
    }

    public function test_it_never_reads_from_crm_pipeline_tables(): void
    {
        // No opportunities exist; the fiscal figure is still fully populated.
        $out = $this->tool()->execute(User::factory()->manager()->create(), ['fiscal_year' => 2026, 'as_of' => '2026-08-31']);

        $this->assertDatabaseCount('opportunities', 0);
        $this->assertSame(8_100_000.0, $out['ytd_actual_revenue']);
    }
}
