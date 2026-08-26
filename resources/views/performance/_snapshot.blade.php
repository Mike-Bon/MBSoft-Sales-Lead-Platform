{{-- Renders one App\Support\PerformanceSnapshot. Shared by the target
     show page and the performance verification pages so there is a
     single place the numbers are displayed, matching the single place
     (PerformanceService) they are calculated. --}}
<div class="grid grid-cols-2 gap-6 sm:grid-cols-4">
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Target</div>
        <div class="text-lg font-semibold">{{ $snapshot->hasTarget ? $snapshot->currency.' '.number_format($snapshot->target, 2) : 'No target set' }}</div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Actual</div>
        <div class="text-lg font-semibold">{{ $snapshot->currency }} {{ number_format($snapshot->actual, 2) }}</div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Achievement</div>
        <div class="text-lg font-semibold">{{ $snapshot->achievementPercent !== null ? number_format($snapshot->achievementPercent, 1).'%' : '—' }}</div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Gap</div>
        <div class="text-lg font-semibold @if ($snapshot->isOverAchieved()) text-green-600 dark:text-green-400 @endif">
            @if (! $snapshot->hasTarget)
                —
            @elseif ($snapshot->gap < 0)
                +{{ $snapshot->currency }} {{ number_format(abs($snapshot->gap), 2) }} over
            @else
                {{ $snapshot->currency }} {{ number_format($snapshot->gap, 2) }}
            @endif
        </div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Remaining Target</div>
        <div class="text-lg font-semibold">{{ $snapshot->hasTarget ? $snapshot->currency.' '.number_format($snapshot->remainingTarget, 2) : '—' }}</div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Open Pipeline</div>
        <div class="text-lg font-semibold">{{ $snapshot->currency }} {{ number_format($snapshot->pipeline, 2) }}</div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Pipeline Coverage</div>
        <div class="text-lg font-semibold">
            @if ($snapshot->pipelineCoverage !== null)
                {{ number_format($snapshot->pipelineCoverage, 2) }}×
            @elseif ($snapshot->hasTarget && $snapshot->remainingTarget <= 0)
                Target met
            @else
                —
            @endif
        </div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Period</div>
        <div class="text-lg font-semibold"><flux:badge size="sm">{{ $snapshot->periodState->label() }}</flux:badge></div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Run Rate / day</div>
        <div class="text-lg font-semibold">{{ $snapshot->runRate !== null ? $snapshot->currency.' '.number_format($snapshot->runRate, 2) : 'N/A' }}</div>
    </div>
    <div>
        <div class="text-sm text-zinc-500 dark:text-zinc-400">Required Run Rate / day</div>
        <div class="text-lg font-semibold">{{ $snapshot->requiredRunRate !== null ? $snapshot->currency.' '.number_format($snapshot->requiredRunRate, 2) : 'N/A' }}</div>
    </div>
</div>
<div class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
    {{ $snapshot->periodStart->format('M j, Y') }} – {{ $snapshot->periodEnd->format('M j, Y') }}
    ({{ $snapshot->elapsedDays }} of {{ $snapshot->totalDays }} days elapsed, {{ $snapshot->remainingDays }} remaining)
</div>
