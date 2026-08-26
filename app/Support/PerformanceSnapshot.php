<?php

namespace App\Support;

use App\Enums\ManagementSignal;
use App\Enums\PerformancePeriodState;
use Illuminate\Support\Carbon;

/**
 * The result of one App\Services\PerformanceService calculation. Every
 * field here has a precise, documented meaning — see docs/PERFORMANCE.md
 * for the formulas. This is a plain, immutable value object: no
 * behaviour beyond exposing the numbers a view or a future API endpoint
 * needs, so Phase 5's dashboards can consume exactly this and nothing
 * has to be recalculated differently for a different screen.
 *
 * Nullable fields are nullable because the underlying metric is
 * genuinely undefined for that snapshot (STEP 10/13/14) — never coerce
 * them to 0 for display; render "N/A"/"—" instead.
 */
final readonly class PerformanceSnapshot
{
    public function __construct(
        public bool $hasTarget,
        public float $target,
        public string $currency,
        public float $actual,
        public ?float $achievementPercent,
        public float $gap,
        public float $remainingTarget,
        public float $pipeline,
        public ?float $pipelineCoverage,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public PerformancePeriodState $periodState,
        public int $totalDays,
        public int $elapsedDays,
        public int $remainingDays,
        public ?float $runRate,
        public ?float $requiredRunRate,
    ) {}

    public function isOverAchieved(): bool
    {
        return $this->hasTarget && $this->gap < 0;
    }

    /**
     * See App\Enums\ManagementSignal for the exact documented rules.
     * Pure classification of already-computed fields — never a new
     * calculation.
     */
    public function managementSignal(): ManagementSignal
    {
        if (! $this->hasTarget || $this->periodState === PerformancePeriodState::Future) {
            return ManagementSignal::NoTarget;
        }

        if ($this->gap <= 0) {
            return ManagementSignal::TargetAchieved;
        }

        if ($this->periodState === PerformancePeriodState::Completed) {
            return ManagementSignal::Behind;
        }

        $expectedPace = $this->totalDays > 0 ? ($this->elapsedDays / $this->totalDays) * 100 : 0.0;

        if ($expectedPace <= 0) {
            return ManagementSignal::NoTarget;
        }

        $paceRatio = ($this->achievementPercent ?? 0.0) / $expectedPace;

        return match (true) {
            $paceRatio >= 1.0 => ManagementSignal::OnTrack,
            $paceRatio >= 0.8 => ManagementSignal::AtRisk,
            default => ManagementSignal::Behind,
        };
    }
}
