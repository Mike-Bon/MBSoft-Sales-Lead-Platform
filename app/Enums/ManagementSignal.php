<?php

namespace App\Enums;

/**
 * A deterministic classification of a PerformanceSnapshot for dashboard
 * display (STEP 10). Rules — evaluated in this order:
 *
 *   NoTarget      hasTarget = false, or the period hasn't started yet
 *                 (nothing to compare pace against).
 *   TargetAchieved  actual >= target (gap <= 0).
 *   Behind        the period has ended and the target was not met, OR
 *                 the achieved pace is below 80% of the expected pace
 *                 for how much of the period has elapsed.
 *   AtRisk        achieved pace is between 80% and 100% of the expected
 *                 pace.
 *   OnTrack       achieved pace is at or above the expected pace.
 *
 * "Expected pace" = elapsedDays / totalDays × 100 — the achievement %
 * you'd have if progress were exactly linear across the period. This
 * mirrors runRate/requiredRunRate's own reasoning (Phase 4) rather than
 * introducing a new formula: everything here is derived from fields
 * PerformanceService already computed, never recalculated.
 *
 * These thresholds (80% / 100% of expected pace) are a defensible,
 * simple V1 default — not tied to any specific business input — and are
 * documented here precisely so they can be revisited deliberately later.
 */
enum ManagementSignal: string
{
    case NoTarget = 'no_target';
    case Behind = 'behind';
    case AtRisk = 'at_risk';
    case OnTrack = 'on_track';
    case TargetAchieved = 'target_achieved';

    public function label(): string
    {
        return match ($this) {
            self::NoTarget => 'No Target',
            self::Behind => 'Behind',
            self::AtRisk => 'At Risk',
            self::OnTrack => 'On Track',
            self::TargetAchieved => 'Target Achieved',
        };
    }

    /**
     * A Tailwind/Flux color name for badges — kept here rather than in
     * every view that renders a signal, so there is one place the
     * color-to-meaning mapping lives.
     */
    public function color(): string
    {
        return match ($this) {
            self::NoTarget => 'zinc',
            self::Behind => 'red',
            self::AtRisk => 'amber',
            self::OnTrack => 'blue',
            self::TargetAchieved => 'green',
        };
    }
}
