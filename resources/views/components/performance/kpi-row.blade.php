{{--
    The six core KPIs (STEP 8), rendered identically everywhere a
    PerformanceSnapshot is shown — Manager Dashboard, Team Head
    Dashboard, Team Member Dashboard, team performance drill-down, and
    Phase 4's verification pages. Every value is read directly off
    $snapshot; nothing here is calculated.
--}}
@props(['snapshot'])

@php($money = fn ($value) => \App\Support\Money::format($value, $snapshot->currency, 0))

<div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
    <x-performance.kpi
        label="Target"
        :undefined="! $snapshot->hasTarget"
        undefined-label="No target assigned"
        :value="$money($snapshot->target)"
    />
    <x-performance.kpi
        label="Actual"
        :value="$money($snapshot->actual)"
    />
    <x-performance.kpi
        label="Achievement"
        :undefined="$snapshot->achievementPercent === null"
        undefined-label="No target assigned"
        :value="$snapshot->achievementPercent !== null ? number_format($snapshot->achievementPercent, 1).'%' : null"
        :signal="$snapshot->managementSignal()"
    />
    <x-performance.kpi
        label="Gap"
        :undefined="! $snapshot->hasTarget"
        :value="($snapshot->gap < 0 ? '+' : '').$money(abs($snapshot->gap)).($snapshot->isOverAchieved() ? ' over' : '')"
    />
    <x-performance.kpi
        label="Open Pipeline"
        :value="$money($snapshot->pipeline)"
    />
    <x-performance.kpi
        label="Pipeline Coverage"
        :undefined="$snapshot->pipelineCoverage === null"
        :undefined-label="! $snapshot->hasTarget ? 'No target assigned' : 'Target met'"
        :value="$snapshot->pipelineCoverage !== null ? number_format($snapshot->pipelineCoverage, 2).'×' : null"
    />
</div>
