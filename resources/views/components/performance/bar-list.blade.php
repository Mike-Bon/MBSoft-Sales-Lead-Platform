{{--
    A simple horizontal bar list — used for Pipeline by Stage,
    Achievement by Team, and Lead Status Distribution (STEP 9). Every
    item's `value`/`formatted` must already be computed by the caller;
    this component only derives each bar's *visual width* as a fraction
    of the list's own maximum — a rendering detail, not a business
    calculation, and never itself displayed as a number.

    $items: iterable of ['label' => string, 'value' => float, 'formatted' => string, 'color' => ?string]
--}}
@props([
    'items',
    'emptyMessage' => 'No data yet.',
])

@php
    $items = collect($items);
    $max = $items->max('value') ?: 1;
@endphp

<div class="space-y-3">
    @forelse ($items as $item)
        <div>
            <div class="mb-1 flex items-center justify-between text-sm">
                <span>{{ $item['label'] }}</span>
                <span class="font-medium">{{ $item['formatted'] }}</span>
            </div>
            <div class="h-2 w-full rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div
                    class="h-2 rounded-full {{ $item['color'] ?? 'bg-blue-500' }}"
                    style="width: {{ $max > 0 ? min(100, ($item['value'] / $max) * 100) : 0 }}%"
                ></div>
            </div>
        </div>
    @empty
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $emptyMessage }}</p>
    @endforelse
</div>
