<?php

namespace Tests\Feature\Performance;

use App\Models\PerformanceActualLine;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Services\FiscalPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Worked-example reconciliation against the FY2026 workbook structure.
 *
 * Fixture (per reporting unit, per fiscal month ordinal k = 1..12):
 *   plan   revenue = 100_000 * k     units = 10 * k
 *   actual revenue =  90_000 * k     units =  9 * k     (only for the months created)
 *
 * So one unit's full FY plan = 100_000 * (1+…+12) = 7_800_000 revenue, 780 units.
 * CEC has 3 units, CBE has 2.
 */
class FiscalPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Team $cec;

    private Team $cbe;

    /** @var array<string, ReportingUnit> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cec = Team::factory()->create(['name' => 'CEC Team', 'code' => 'CEC']);
        $this->cbe = Team::factory()->create(['name' => 'CBE Team', 'code' => 'CBE']);

        foreach ([['CEC', 'TABUN'], ['CEC', 'GSOUTH'], ['CEC', 'EMALL'], ['CBE', 'MAYALA'], ['CBE', 'GORORDO']] as [$teamCode, $code]) {
            $team = $teamCode === 'CEC' ? $this->cec : $this->cbe;
            $this->units[$code] = ReportingUnit::factory()->for($team)->create(['code' => $code, 'name' => $code]);
        }
    }

    private function service(): FiscalPerformanceService
    {
        return app(FiscalPerformanceService::class);
    }

    private function seedPlan(int $throughMonth = 12): void
    {
        foreach ($this->units as $unit) {
            for ($k = 1; $k <= $throughMonth; $k++) {
                PerformancePlanLine::factory()->create([
                    'fiscal_year' => 2026, 'period_month' => $k,
                    'team_id' => $unit->team_id, 'reporting_unit_id' => $unit->id,
                    'target_revenue' => 100_000 * $k, 'target_units' => 10 * $k,
                ]);
            }
        }
    }

    private function seedActuals(int $throughMonth): void
    {
        foreach ($this->units as $unit) {
            for ($k = 1; $k <= $throughMonth; $k++) {
                PerformanceActualLine::factory()->create([
                    'fiscal_year' => 2026, 'period_month' => $k,
                    'team_id' => $unit->team_id, 'reporting_unit_id' => $unit->id,
                    'actual_revenue' => 90_000 * $k, 'actual_units' => 9 * $k,
                ]);
            }
        }
    }

    // ── reconciliation ──────────────────────────────────────────────

    public function test_sum_of_reporting_units_equals_team_total_equals_organisation_total(): void
    {
        $this->seedPlan();
        $this->seedActuals(9);
        $asOf = Carbon::parse('2026-08-31');

        $cec = $this->service()->forTeam($this->cec, 2026, $asOf, withPriorYear: false);
        $cbe = $this->service()->forTeam($this->cbe, 2026, $asOf, withPriorYear: false);
        $org = $this->service()->forOrganisation(2026, $asOf, withPriorYear: false);

        // team = sum of its units
        $cecUnits = collect(['TABUN', 'GSOUTH', 'EMALL'])->map(
            fn ($c) => $this->service()->forReportingUnit($this->units[$c], 2026, $asOf, withPriorYear: false)
        );
        $this->assertSame($cec->fyTargetRevenue, round($cecUnits->sum('fyTargetRevenue'), 2));
        $this->assertSame($cec->ytdActualRevenue, round($cecUnits->sum('ytdActualRevenue'), 2));
        $this->assertSame($cec->fyTargetUnits, round($cecUnits->sum('fyTargetUnits'), 2));
        $this->assertSame($cec->ytdActualUnits, round($cecUnits->sum('ytdActualUnits'), 2));

        // org = sum of teams
        $this->assertSame($org->fyTargetRevenue, round($cec->fyTargetRevenue + $cbe->fyTargetRevenue, 2));
        $this->assertSame($org->ytdActualRevenue, round($cec->ytdActualRevenue + $cbe->ytdActualRevenue, 2));
        $this->assertSame($org->fyTargetUnits, round($cec->fyTargetUnits + $cbe->fyTargetUnits, 2));

        // absolute numbers
        $this->assertSame(23_400_000.0, $cec->fyTargetRevenue);   // 7.8M * 3 units
        $this->assertSame(39_000_000.0, $org->fyTargetRevenue);   // + 7.8M * 2 units
    }

    public function test_sum_of_monthly_phased_targets_equals_the_full_fy_target(): void
    {
        $this->seedPlan();
        $snap = $this->service()->forTeam($this->cec, 2026, Carbon::parse('2026-11-30'), withPriorYear: false);

        $this->assertCount(12, $snap->monthlyTrend);
        $this->assertSame($snap->fyTargetRevenue, round(collect($snap->monthlyTrend)->sum('target_revenue'), 2));
        $this->assertSame($snap->fyTargetUnits, round(collect($snap->monthlyTrend)->sum('target_units'), 2));
    }

    // ── YTD horizon ─────────────────────────────────────────────────

    public function test_ytd_as_of_august_covers_fiscal_months_december_through_august_only(): void
    {
        $this->seedPlan();
        $this->seedActuals(9);

        $snap = $this->service()->forTeam($this->cec, 2026, Carbon::parse('2026-08-31'), withPriorYear: false);

        $this->assertSame(9, $snap->throughFiscalMonth);
        // YTD phased target (Dec..Aug = ordinals 1..9): 100k*(1..9)=4.5M per unit * 3 = 13.5M
        $this->assertSame(13_500_000.0, $snap->ytdPhasedTargetRevenue);
        // Sep/Oct/Nov excluded
        $this->assertSame(3, $snap->remainingFiscalMonths);
    }

    public function test_the_two_attainment_metrics_are_distinct_and_named(): void
    {
        $this->seedPlan();
        $this->seedActuals(9);

        $snap = $this->service()->forTeam($this->cec, 2026, Carbon::parse('2026-08-31'), withPriorYear: false);

        // YTD actual = 90k*(1..9)=4.05M per unit * 3 = 12.15M
        $this->assertSame(12_150_000.0, $snap->ytdActualRevenue);

        // YTD Target Attainment = 12.15M / 13.5M
        $this->assertSame(90.0, $snap->ytdTargetAttainmentPct);
        // FY Attainment to Date = 12.15M / 23.4M
        $this->assertSame(51.92, $snap->fyAttainmentToDatePct);

        $this->assertNotSame($snap->ytdTargetAttainmentPct, $snap->fyAttainmentToDatePct);

        $this->assertSame(-1_350_000.0, $snap->ytdRevenueVariance);
        $this->assertSame(11_250_000.0, $snap->remainingFyRevenueTarget);
        $this->assertSame(3_750_000.0, $snap->requiredMonthlyRevenue); // 11.25M / 3
        $this->assertSame(-135.0, $snap->ytdUnitVariance); // (9-10)*45*3
        $this->assertSame(10_000.0, $snap->revenuePerUnitActual);
    }

    public function test_fractional_weighted_units_flow_through_every_calculation(): void
    {
        // one unit, binary-exact fractional figures
        $tabun = $this->units['TABUN'];
        for ($k = 1; $k <= 12; $k++) {
            PerformancePlanLine::factory()->create([
                'fiscal_year' => 2026, 'period_month' => $k,
                'team_id' => $tabun->team_id, 'reporting_unit_id' => $tabun->id,
                'target_revenue' => 100_000, 'target_units' => 100.25,
            ]);
        }
        for ($k = 1; $k <= 9; $k++) {
            PerformanceActualLine::factory()->create([
                'fiscal_year' => 2026, 'period_month' => $k,
                'team_id' => $tabun->team_id, 'reporting_unit_id' => $tabun->id,
                'actual_revenue' => 90_000, 'actual_units' => 12.5,
            ]);
        }

        $snap = $this->service()->forReportingUnit($tabun, 2026, Carbon::parse('2026-08-31'), withPriorYear: false);

        $this->assertSame(1203.0, $snap->fyTargetUnits);          // 100.25 * 12
        $this->assertSame(902.25, $snap->ytdPhasedTargetUnits);   // 100.25 * 9
        $this->assertSame(112.5, $snap->ytdActualUnits);          // 12.5 * 9
        $this->assertSame(-789.75, $snap->ytdUnitVariance);       // 112.5 - 902.25
        $this->assertSame(1090.5, $snap->remainingFyUnitTarget);  // 1203 - 112.5
        $this->assertSame(363.5, $snap->requiredMonthlyUnits);    // 1090.5 / 3 remaining months — NOT rounded up
        $this->assertSame(7200.0, $snap->revenuePerUnitActual);   // 810_000 / 112.5

        // fractional units survive the monthly trend too
        $this->assertSame(100.25, $snap->monthlyTrend[0]['target_units']);
        $this->assertSame(12.5, $snap->monthlyTrend[0]['actual_units']);
    }

    public function test_missing_actual_months_are_not_invented(): void
    {
        $this->seedPlan();
        $this->seedActuals(8); // actuals only through July (ordinal 8)

        $snap = $this->service()->forTeam($this->cec, 2026, Carbon::parse('2026-08-31'), withPriorYear: false);

        $this->assertSame(9, $snap->throughFiscalMonth);         // horizon is still Dec..Aug
        $this->assertSame(13_500_000.0, $snap->ytdPhasedTargetRevenue); // phased target = 9 months
        // actual = 90k*(1..8)=3.24M per unit * 3 = 9.72M — August NOT invented
        $this->assertSame(9_720_000.0, $snap->ytdActualRevenue);
        $this->assertSame(8, $snap->lastActualPeriodMonth);
        $this->assertSame(8, $snap->actualMonthsLoaded);
        $this->assertFalse($snap->actualsComplete);
    }

    public function test_before_the_fiscal_year_starts_ytd_is_zero_and_attainment_is_null(): void
    {
        $this->seedPlan();

        $snap = $this->service()->forOrganisation(2026, Carbon::parse('2025-11-01'), withPriorYear: false);

        $this->assertSame(0, $snap->throughFiscalMonth);
        $this->assertSame(0.0, $snap->ytdActualRevenue);
        $this->assertSame(0.0, $snap->ytdPhasedTargetRevenue);
        // YTD-target attainment is UNDEFINED (no phased target through month 0);
        // FY-attainment-to-date is a real 0% (nothing attained yet).
        $this->assertNull($snap->ytdTargetAttainmentPct);
        $this->assertSame(0.0, $snap->fyAttainmentToDatePct);
        $this->assertSame(39_000_000.0, $snap->fyTargetRevenue);
        $this->assertSame(12, $snap->remainingFiscalMonths);
    }

    public function test_zero_and_missing_targets_are_handled_without_division_errors(): void
    {
        // No plan lines at all, only actuals.
        $this->seedActuals(3);

        $snap = $this->service()->forTeam($this->cec, 2026, Carbon::parse('2026-02-28'), withPriorYear: false);

        $this->assertSame(0.0, $snap->fyTargetRevenue);
        $this->assertNull($snap->ytdTargetAttainmentPct);
        $this->assertNull($snap->fyAttainmentToDatePct);
        $this->assertSame(0.0, $snap->remainingFyRevenueTarget);
        // No target → ₱0 still required per remaining month (not "undefined").
        $this->assertSame(0.0, $snap->requiredMonthlyRevenue);
    }

    // ── roll-up ordering / breakdown ────────────────────────────────

    public function test_team_totals_list_the_most_behind_team_first(): void
    {
        $this->seedPlan();
        // CEC over-performs, CBE under-performs
        foreach ($this->units as $code => $unit) {
            $mult = $unit->team_id === $this->cec->id ? 120_000 : 60_000;
            for ($k = 1; $k <= 6; $k++) {
                PerformanceActualLine::factory()->create([
                    'fiscal_year' => 2026, 'period_month' => $k,
                    'team_id' => $unit->team_id, 'reporting_unit_id' => $unit->id,
                    'actual_revenue' => $mult * $k, 'actual_units' => 8 * $k,
                ]);
            }
        }

        $org = $this->service()->forOrganisation(2026, Carbon::parse('2026-05-31'), withPriorYear: false);

        $this->assertSame($this->cbe->id, $org->teamTotals[0]['team_id']);
        $this->assertLessThan(0, $org->teamTotals[0]['ytd_revenue_variance']);
        $this->assertGreaterThan(0, $org->teamTotals[1]['ytd_revenue_variance']);
    }

    public function test_reporting_unit_breakdown_flags_units_below_their_phased_target(): void
    {
        $this->seedPlan();
        // Only TABUN gets (under-)actuals
        for ($k = 1; $k <= 3; $k++) {
            PerformanceActualLine::factory()->create([
                'fiscal_year' => 2026, 'period_month' => $k,
                'team_id' => $this->cec->id, 'reporting_unit_id' => $this->units['TABUN']->id,
                'actual_revenue' => 50_000 * $k, 'actual_units' => 5 * $k,
            ]);
        }

        $snap = $this->service()->forTeam($this->cec, 2026, Carbon::parse('2026-02-28'), withPriorYear: false);

        $tabun = collect($snap->reportingUnitBreakdown)->firstWhere('reporting_unit_code', 'TABUN');
        $this->assertTrue($tabun['below_phased_target']);
        $this->assertLessThan(0, $tabun['ytd_revenue_variance']);
    }

    public function test_prior_year_comparison_is_null_when_no_prior_data_exists(): void
    {
        $this->seedPlan();
        $this->seedActuals(9);

        $snap = $this->service()->forOrganisation(2026, Carbon::parse('2026-08-31'));

        $this->assertNull($snap->priorYear);
    }

    public function test_prior_year_comparison_is_populated_when_prior_data_exists(): void
    {
        $this->seedPlan();
        $this->seedActuals(9);

        // FY2025 data for one unit
        for ($k = 1; $k <= 12; $k++) {
            PerformancePlanLine::factory()->create([
                'fiscal_year' => 2025, 'period_month' => $k,
                'team_id' => $this->cec->id, 'reporting_unit_id' => $this->units['TABUN']->id,
                'target_revenue' => 80_000 * $k, 'target_units' => 8 * $k,
            ]);
            PerformanceActualLine::factory()->create([
                'fiscal_year' => 2025, 'period_month' => $k,
                'team_id' => $this->cec->id, 'reporting_unit_id' => $this->units['TABUN']->id,
                'actual_revenue' => 75_000 * $k, 'actual_units' => 7 * $k,
            ]);
        }

        $snap = $this->service()->forOrganisation(2026, Carbon::parse('2026-08-31'));

        $this->assertNotNull($snap->priorYear);
        $this->assertSame(2025, $snap->priorYear->fiscalYear);
        $this->assertSame(9, $snap->priorYear->throughFiscalMonth); // same horizon a year earlier
        $this->assertNull($snap->priorYear->priorYear);             // not recursive
    }
}
