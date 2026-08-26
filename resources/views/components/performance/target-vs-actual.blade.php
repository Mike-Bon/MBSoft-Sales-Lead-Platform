{{--
    Target vs Actual progress bar (STEP 9 — "how much of the target is
    covered"). The labeled percentage is always
    $snapshot->achievementPercent, computed by PerformanceService — the
    only arithmetic done here is the bar's own visual fill width, which
    is never displayed as a number and is not a business calculation.
--}}
@props(['snapshot'])

@php
    $fillPercent = ($snapshot->hasTarget && $snapshot->target > 0)
        ? min(100, ($snapshot->actual / $snapshot->target) * 100)
        : 0;
@endphp

<div>
    <div class="mb-1 flex items-center justify-between text-sm">
        <span>Target vs Actual</span>
        <span class="font-medium">
            @if ($snapshot->achievementPercent !== null)
                {{ number_format($snapshot->achievementPercent, 1) }}%
            @else
                No target assigned
            @endif
        </span>
    </div>
    <div class="h-3 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
        <div
            class="h-3 {{ $snapshot->isOverAchieved() ? 'bg-green-500' : 'bg-blue-500' }}"
            style="width: {{ $fillPercent }}%"
        ></div>
    </div>
    <div class="mt-1 flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
        <span>{{ $snapshot->currency }} {{ number_format($snapshot->actual, 0) }} actual</span>
        <span>{{ $snapshot->hasTarget ? $snapshot->currency.' '.number_format($snapshot->target, 0).' target' : 'no target' }}</span>
    </div>
</div>
