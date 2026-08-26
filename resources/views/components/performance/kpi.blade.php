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
])

<div {{ $attributes->class(['rounded-lg border border-zinc-200 p-4 dark:border-zinc-700']) }}>
    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
    <div class="mt-1 text-2xl font-semibold">
        {{ $undefined ? $undefinedLabel : $value }}
    </div>
    @if ($hint)
        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</div>
    @endif
</div>
