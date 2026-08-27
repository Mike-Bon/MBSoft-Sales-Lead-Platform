{{--
    A single KPI tile. Receives an already-calculated value — never
    queries the database, never computes a percentage or any other
    business metric itself (STEP 8). Pass `:undefined="true"` for a
    metric that is genuinely undefined (no target, target already met,
    etc.) rather than ever coercing it to a misleading 0/0%.
--}}
@props([
    'label',
    'value' => null,
    'undefined' => false,
    'undefinedLabel' => '—',
    'hint' => null,
    'signal' => null,
])

<div {{ $attributes->class(['rounded-lg border border-zinc-200 p-4 dark:border-zinc-700']) }}>
    <div class="flex items-start justify-between gap-2">
        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
        {{-- Phase 11A: reuses the same ManagementSignal already shown
             in the Team/Individual Performance tables — never a new,
             separately-invented threshold — so a concerning number is
             visually flagged where the user actually looks first. --}}
        @if ($signal && ! $undefined && $signal !== \App\Enums\ManagementSignal::NoTarget)
            <x-performance.signal-badge :signal="$signal" />
        @endif
    </div>
    <div class="mt-1 text-2xl font-semibold">
        {{ $undefined ? $undefinedLabel : $value }}
    </div>
    @if ($hint)
        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</div>
    @endif
</div>
