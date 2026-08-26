<?php

namespace App\Support;

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
}
