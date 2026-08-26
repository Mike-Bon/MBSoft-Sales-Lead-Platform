<?php

namespace Tests\Feature\Performance;

use App\Enums\PerformancePeriodState;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pure arithmetic tests of PerformanceService::compute() — the single
 * authoritative implementation of every formula in docs/PERFORMANCE.md.
 * No database access is required for compute() itself; RefreshDatabase
 * is used only for app bootstrap consistency with the rest of the suite.
 */
class PerformanceCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PerformanceService
    {
        return app(PerformanceService::class);
    }

    public function test_50_percent_achievement_calculates_correctly(): void
    {
        $snapshot = $this->service()->compute(true, 100000, 'USD', 50000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(50.0, $snapshot->achievementPercent);
    }

    public function test_100_percent_achievement_calculates_correctly(): void
    {
        $snapshot = $this->service()->compute(true, 100000, 'USD', 100000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(100.0, $snapshot->achievementPercent);
    }

    public function test_120_percent_achievement_calculates_correctly(): void
    {
        $snapshot = $this->service()->compute(true, 1000000, 'PHP', 1200000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(120.0, $snapshot->achievementPercent);
        $this->assertSame(-200000.0, $snapshot->gap);
        $this->assertSame(0.0, $snapshot->remainingTarget);
        $this->assertTrue($snapshot->isOverAchieved());
    }

    public function test_zero_target_achievement_is_undefined_not_zero_percent(): void
    {
        $snapshot = $this->service()->compute(true, 0, 'USD', 5000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertNull($snapshot->achievementPercent);
    }

    public function test_no_target_at_all_is_undefined(): void
    {
        $snapshot = $this->service()->compute(false, 0, 'USD', 5000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertFalse($snapshot->hasTarget);
        $this->assertNull($snapshot->achievementPercent);
    }

    public function test_positive_gap_calculates_correctly(): void
    {
        $snapshot = $this->service()->compute(true, 100000, 'USD', 40000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(60000.0, $snapshot->gap);
        $this->assertSame(60000.0, $snapshot->remainingTarget);
    }

    public function test_negative_gap_represents_overachievement_and_is_not_hidden(): void
    {
        $snapshot = $this->service()->compute(true, 100000, 'USD', 150000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(-50000.0, $snapshot->gap);
    }

    public function test_remaining_target_cannot_become_negative(): void
    {
        $snapshot = $this->service()->compute(true, 100000, 'USD', 999999, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(0.0, $snapshot->remainingTarget);
    }

    public function test_pipeline_coverage_calculates_correctly(): void
    {
        // remaining target = 40000, pipeline = 80000 -> coverage = 2.0x
        $snapshot = $this->service()->compute(true, 100000, 'USD', 60000, 80000, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(2.0, $snapshot->pipelineCoverage);
    }

    public function test_zero_remaining_target_makes_coverage_undefined_not_infinite(): void
    {
        $snapshot = $this->service()->compute(true, 100000, 'USD', 150000, 80000, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(0.0, $snapshot->remainingTarget);
        $this->assertNull($snapshot->pipelineCoverage);
    }

    public function test_current_period_calculations_work(): void
    {
        // 31-day January, "now" = Jan 15 -> elapsed 15, remaining 16.
        $snapshot = $this->service()->compute(true, 31000, 'USD', 15000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(PerformancePeriodState::Current, $snapshot->periodState);
        $this->assertSame(31, $snapshot->totalDays);
        $this->assertSame(15, $snapshot->elapsedDays);
        $this->assertSame(16, $snapshot->remainingDays);
    }

    public function test_future_period_run_rate_is_undefined_not_a_misleading_zero(): void
    {
        $snapshot = $this->service()->compute(true, 100000, 'USD', 0, 0, Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(PerformancePeriodState::Future, $snapshot->periodState);
        $this->assertSame(0, $snapshot->elapsedDays);
        $this->assertSame(31, $snapshot->remainingDays);
        $this->assertNull($snapshot->runRate);
    }

    public function test_completed_period_required_run_rate_is_not_applicable(): void
    {
        $snapshot = $this->service()->compute(true, 100000, 'USD', 40000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-03-15'));

        $this->assertSame(PerformancePeriodState::Completed, $snapshot->periodState);
        $this->assertSame(31, $snapshot->elapsedDays);
        $this->assertSame(0, $snapshot->remainingDays);
        $this->assertNull($snapshot->requiredRunRate);
        // Run rate IS still defined for a completed period (average pace
        // actually achieved over the whole period).
        $this->assertNotNull($snapshot->runRate);
    }

    public function test_completed_period_that_met_target_has_zero_required_run_rate(): void
    {
        $snapshot = $this->service()->compute(true, 40000, 'USD', 50000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-03-15'));

        $this->assertSame(0.0, $snapshot->requiredRunRate);
    }

    public function test_required_run_rate_is_correct_for_a_current_period(): void
    {
        // remaining target = 32000 over 16 remaining days = 2000/day.
        $snapshot = $this->service()->compute(true, 31000 + 32000, 'USD', 31000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(16, $snapshot->remainingDays);
        $this->assertSame(2000.0, $snapshot->requiredRunRate);
    }

    public function test_run_rate_is_correct(): void
    {
        // 15 elapsed days, actual 15000 -> 1000/day.
        $snapshot = $this->service()->compute(true, 31000, 'USD', 15000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(1000.0, $snapshot->runRate);
    }
}
