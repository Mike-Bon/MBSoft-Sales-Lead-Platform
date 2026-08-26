{{-- A short, rule-based list of leads needing attention (STEP 15). --}}
@props(['leads', 'emptyMessage' => 'Nothing here right now.', 'showOwner' => false])

<div class="space-y-2">
    @forelse ($leads as $lead)
        <a href="{{ route('crm.leads.show', $lead) }}" wire:navigate class="block rounded-lg border border-zinc-200 p-3 text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50">
            <div class="flex items-center justify-between">
                <span class="font-medium">{{ $lead->organization?->name ?? $lead->contact?->fullName() ?? "Lead #{$lead->id}" }}</span>
                <flux:badge size="sm">{{ $lead->priority->label() }}</flux:badge>
            </div>
            <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ $lead->status->label() }}
                @if ($showOwner && $lead->owner)
                    &middot; {{ $lead->owner->name }}
                @endif
                @if ($lead->next_follow_up_at)
                    &middot; follow-up {{ $lead->next_follow_up_at->format('M j, Y') }}
                @endif
            </div>
        </a>
    @empty
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $emptyMessage }}</p>
    @endforelse
</div>
