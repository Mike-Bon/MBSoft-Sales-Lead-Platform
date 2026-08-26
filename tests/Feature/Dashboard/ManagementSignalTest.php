<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ManagementSignal;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * STEP 10: deterministic management signal rules — see
 * App\Enums\ManagementSignal for the documented thresholds.
 */
class ManagementSignalTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PerformanceService
    {
        return app(PerformanceService::class);
    }

    public function test_no_target_signal(): void
    {
        $snapshot = $this->service()->compute(false, 0, 'USD', 0, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(ManagementSignal::NoTarget, $snapshot->managementSignal());
    }

    public function test_future_period_is_no_target_signal_even_with_a_target(): void
    {
        $snapshot = $this->service()->compute(true, 10000, 'USD', 0, 0, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), Carbon::parse('2026-01-01'));

        $this->assertSame(ManagementSignal::NoTarget, $snapshot->managementSignal());
    }

    public function test_target_achieved_signal(): void
    {
        $snapshot = $this->service()->compute(true, 10000, 'USD', 10000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(ManagementSignal::TargetAchieved, $snapshot->managementSignal());
    }

    public function test_on_track_signal_when_pace_meets_expected_progress(): void
    {
        // Halfway through January (day 15/31 ~ 48%), achieved ~48%+.
        $snapshot = $this->service()->compute(true, 31000, 'USD', 16000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(ManagementSignal::OnTrack, $snapshot->managementSignal());
    }

    public function test_at_risk_signal_when_pace_is_somewhat_behind(): void
    {
        // Expected pace ~48%; achieved ~40% (0.8-1.0x of expected).
        $snapshot = $this->service()->compute(true, 31000, 'USD', 12500, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(ManagementSignal::AtRisk, $snapshot->managementSignal());
    }

    public function test_behind_signal_when_pace_is_far_below_expected(): void
    {
        $snapshot = $this->service()->compute(true, 31000, 'USD', 1000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-01-15'));

        $this->assertSame(ManagementSignal::Behind, $snapshot->managementSignal());
    }

    public function test_completed_period_that_missed_target_is_behind(): void
    {
        $snapshot = $this->service()->compute(true, 10000, 'USD', 5000, 0, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), Carbon::parse('2026-03-01'));

        $this->assertSame(ManagementSignal::Behind, $snapshot->managementSignal());
    }
}
