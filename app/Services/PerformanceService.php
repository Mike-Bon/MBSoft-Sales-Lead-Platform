<?php

namespace App\Services;

use App\Enums\OpportunityStage;
use App\Enums\PerformancePeriodState;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use App\Support\PerformanceSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The single authoritative implementation of every target/performance
 * calculation in the application (STEP 9). Every screen — the Target
 * detail page today, Phase 5's dashboards later — must go through this
 * service rather than recomputing a formula itself. See
 * docs/PERFORMANCE.md for the exact definition of every metric below.
 *
 * The LLM is never involved in any of this and never will be: this
 * service has no dependency on any AI/LLM component, by design.
 */
class PerformanceService
{
    /**
     * Actual sales: the sum of Closed Won opportunity value whose
     * closed_at falls within the given period. Only Closed Won counts —
     * see docs/PERFORMANCE.md "Actual sales definition".
     *
     * $opportunities must already be scoped to the correct
     * owner/team/organisation by the caller — this method only applies
     * the stage/date/currency filter and aggregates in SQL (never loads
     * rows into PHP).
     */
    public function actualSales(Builder $opportunities, Carbon $periodStart, Carbon $periodEnd, ?string $currency = null): float
    {
        $query = (clone $opportunities)
            ->where('stage', OpportunityStage::ClosedWon->value)
            ->whereBetween('closed_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()]);

        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        return (float) $query->sum('value');
    }

    /**
     * Open pipeline: the sum of value across every open (not Closed Won,
     * not Closed Lost) opportunity — no probability weighting, no date
     * constraint. See docs/PERFORMANCE.md "Pipeline definition".
     */
    public function openPipeline(Builder $opportunities, ?string $currency = null): float
    {
        $query = (clone $opportunities)
            ->whereNotIn('stage', [OpportunityStage::ClosedWon->value, OpportunityStage::ClosedLost->value]);

        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        return (float) $query->sum('value');
    }

    /**
     * The one authoritative formula implementation: achievement, gap,
     * remaining target, pipeline coverage, run rate, required run rate,
     * and the period's temporal state — all pure arithmetic over already-
     * computed numbers, no database access, fully unit-testable.
     */
    public function compute(
        bool $hasTarget,
        float $target,
        string $currency,
        float $actual,
        float $pipeline,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?Carbon $now = null,
    ): PerformanceSnapshot {
        $now = ($now ?? Carbon::now())->copy();
        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->startOfDay();

        $totalDays = $periodStart->diffInDays($periodEnd) + 1;

        if ($now->startOfDay()->lt($periodStart)) {
            $periodState = PerformancePeriodState::Future;
            $elapsedDays = 0;
            $remainingDays = $totalDays;
        } elseif ($now->startOfDay()->gt($periodEnd)) {
            $periodState = PerformancePeriodState::Completed;
            $elapsedDays = $totalDays;
            $remainingDays = 0;
        } else {
            $periodState = PerformancePeriodState::Current;
            $elapsedDays = $periodStart->diffInDays($now) + 1;
            $remainingDays = $totalDays - $elapsedDays;
        }

        $gap = $target - $actual;
        $remainingTarget = max($gap, 0.0);

        // Undefined (not 0%) when there is no target at all, or the
        // target is exactly zero — division by zero is never performed.
        $achievementPercent = ($hasTarget && $target > 0)
            ? round(($actual / $target) * 100, 2)
            : null;

        // Undefined once the target is already met (nothing left to
        // cover) — never a misleading number, never a literal Infinity.
        $pipelineCoverage = $remainingTarget > 0
            ? round($pipeline / $remainingTarget, 2)
            : null;

        // A future period has no elapsed time to average over — this is
        // "no context yet", not a real 0/day pace, so it stays undefined
        // rather than displaying a misleading zero (STEP 14).
        $runRate = $periodState !== PerformancePeriodState::Future && $elapsedDays > 0
            ? round($actual / $elapsedDays, 2)
            : null;

        $requiredRunRate = $this->requiredRunRate($remainingTarget, $remainingDays, $periodState);

        return new PerformanceSnapshot(
            hasTarget: $hasTarget,
            target: $target,
            currency: $currency,
            actual: $actual,
            achievementPercent: $achievementPercent,
            gap: $gap,
            remainingTarget: $remainingTarget,
            pipeline: $pipeline,
            pipelineCoverage: $pipelineCoverage,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            periodState: $periodState,
            totalDays: $totalDays,
            elapsedDays: $elapsedDays,
            remainingDays: $remainingDays,
            runRate: $runRate,
            requiredRunRate: $requiredRunRate,
        );
    }

    private function requiredRunRate(float $remainingTarget, int $remainingDays, PerformancePeriodState $periodState): ?float
    {
        if ($remainingTarget <= 0) {
            // Target already met or exceeded: nothing more is required,
            // regardless of how much time is left.
            return 0.0;
        }

        if ($periodState === PerformancePeriodState::Completed) {
            // The period is over and the target was not met: there is no
            // time left to pace against. Not applicable, not zero.
            return null;
        }

        if ($remainingDays <= 0) {
            // Today is the last day and the target isn't met: no
            // meaningful daily pace can be computed.
            return null;
        }

        return round($remainingTarget / $remainingDays, 2);
    }

    /**
     * Performance for one specific Target record — the primary way the
     * Target detail page (and Phase 5's dashboards) get a snapshot.
     */
    public function forTarget(Target $target): PerformanceSnapshot
    {
        $opportunities = $this->opportunitiesFor($target);

        $actual = $this->actualSales($opportunities, $target->period_start, $target->period_end, $target->currency);
        $pipeline = $this->openPipeline($opportunities, $target->currency);

        return $this->compute(
            hasTarget: true,
            target: (float) $target->target_amount,
            currency: $target->currency,
            actual: $actual,
            pipeline: $pipeline,
            periodStart: $target->period_start,
            periodEnd: $target->period_end,
        );
    }

    /**
     * An individual's performance for an arbitrary period, using their
     * active Individual target for that exact period if one exists
     * (hasTarget = false otherwise — STEP 25's "no target" edge case).
     */
    public function forIndividual(User $user, Carbon $periodStart, Carbon $periodEnd): PerformanceSnapshot
    {
        $target = $this->findTarget(TargetType::Individual, $periodStart, $periodEnd, ownerId: $user->id);

        return $this->snapshotFor(
            Opportunity::query()->where('owner_id', $user->id),
            $target,
            $periodStart,
            $periodEnd,
        );
    }

    /**
     * A team's aggregated performance for an arbitrary period, using its
     * active Team target for that exact period if one exists.
     */
    public function forTeam(Team $team, Carbon $periodStart, Carbon $periodEnd): PerformanceSnapshot
    {
        $target = $this->findTarget(TargetType::Team, $periodStart, $periodEnd, teamId: $team->id);

        return $this->snapshotFor(
            Opportunity::query()->where('team_id', $team->id),
            $target,
            $periodStart,
            $periodEnd,
        );
    }

    /**
     * Organisation-wide performance for an arbitrary period — every
     * opportunity, regardless of team, using the active Manager target
     * for that exact period if one exists.
     */
    public function forOrganisation(Carbon $periodStart, Carbon $periodEnd): PerformanceSnapshot
    {
        $target = $this->findTarget(TargetType::Manager, $periodStart, $periodEnd);

        return $this->snapshotFor(
            Opportunity::query(),
            $target,
            $periodStart,
            $periodEnd,
        );
    }

    private function snapshotFor(Builder $opportunities, ?Target $target, Carbon $periodStart, Carbon $periodEnd): PerformanceSnapshot
    {
        $currency = $target->currency ?? 'USD';

        $actual = $this->actualSales($opportunities, $periodStart, $periodEnd, $currency);
        $pipeline = $this->openPipeline($opportunities, $currency);

        return $this->compute(
            hasTarget: $target !== null,
            target: (float) ($target->target_amount ?? 0),
            currency: $currency,
            actual: $actual,
            pipeline: $pipeline,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );
    }

    private function findTarget(TargetType $type, Carbon $periodStart, Carbon $periodEnd, ?int $ownerId = null, ?int $teamId = null): ?Target
    {
        // whereDate(), not where(): Eloquent's plain 'date' cast only
        // truncates to date-only on *read*, not on storage — the column
        // can hold a full "Y-m-d H:i:s" string underneath, so an exact
        // string match against toDateString() would silently never hit.
        return Target::query()
            ->where('target_type', $type->value)
            ->where('status', TargetStatus::Active->value)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->when($ownerId !== null, fn (Builder $q) => $q->where('owner_id', $ownerId))
            ->when($teamId !== null, fn (Builder $q) => $q->where('team_id', $teamId))
            ->first();
    }

    private function opportunitiesFor(Target $target): Builder
    {
        return match ($target->target_type) {
            TargetType::Manager => Opportunity::query(),
            TargetType::Team => Opportunity::query()->where('team_id', $target->team_id),
            TargetType::Individual => Opportunity::query()->where('owner_id', $target->owner_id),
        };
    }
}
