{{-- A short, rule-based list of opportunities needing attention (STEP 15). --}}
@props(['opportunities', 'emptyMessage' => 'Nothing here right now.', 'showOwner' => false])

<div class="space-y-2">
    @forelse ($opportunities as $opportunity)
        <a href="{{ route('crm.opportunities.show', $opportunity) }}" wire:navigate class="block rounded-lg border border-zinc-200 p-3 text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50">
            <div class="flex items-center justify-between">
                <span class="font-medium">{{ $opportunity->name }}</span>
                <span>{{ \App\Support\Money::format((float) $opportunity->value, $opportunity->currency, 0) }}</span>
            </div>
            <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ $opportunity->stage->label() }}
                @if ($showOwner && $opportunity->owner)
                    &middot; {{ $opportunity->owner->name }}
                @endif
                @if ($opportunity->expected_close_date)
                    &middot; closing {{ $opportunity->expected_close_date->format('M j, Y') }}
                @endif
            </div>
        </a>
    @empty
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $emptyMessage }}</p>
    @endforelse
</div>
