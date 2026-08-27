{{--
    STEP 53/54/55: a small, additive "AI Insights" card — not a command
    center. Shows the latest run of each workflow (if any) and the
    pending-approval count, all already scoped to the viewing user.
    Clicking through leads to the real underlying data (STEP 54's "the
    AI should not become the only way to access the information").

    Phase 11A: when nothing has run yet and nothing is pending, this
    collapses to one compact line rather than three "Not run yet" rows
    — an empty state shouldn't claim the same visual weight as a card
    full of real findings (visual-hierarchy pass).
--}}
@props(['insights'])

@php
    $hasAnyResult = collect(\App\Enums\WorkflowType::cases())->contains(fn ($type) => ($insights['latest'][$type->value] ?? null) !== null);
    $hasPending = $insights['pending_approvals'] > 0;
@endphp

<div class="mb-8 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
    <div class="flex items-center justify-between {{ $hasAnyResult || $hasPending ? 'mb-3' : '' }}">
        <flux:heading size="lg">AI Insights</flux:heading>
        <flux:button size="sm" href="{{ route('workflows.index') }}" wire:navigate>View All Activity</flux:button>
    </div>

    @if (! $hasAnyResult && ! $hasPending)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">No workflow reviews have run yet — insights will appear here once the daily review runs.</p>
    @else
        <div class="space-y-2 text-sm">
            @foreach (\App\Enums\WorkflowType::cases() as $type)
                @php($execution = $insights['latest'][$type->value] ?? null)
                @if ($execution)
                    <div class="flex items-start justify-between gap-4">
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $type->label() }}:</span>
                        <a class="flex-1 truncate text-right underline" href="{{ route('workflows.show', $execution) }}" wire:navigate>
                            {{ \Illuminate\Support\Str::limit($execution->result ?? 'No summary available.', 80) }}
                        </a>
                    </div>
                @endif
            @endforeach
        </div>

        @if ($hasPending)
            <flux:separator variant="subtle" class="my-3" />
            <a class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-700 underline dark:text-amber-400" href="{{ route('workflows.approvals.index') }}" wire:navigate>
                <flux:icon name="exclamation-circle" class="size-4" />
                {{ $insights['pending_approvals'] }} {{ \Illuminate\Support\Str::plural('proposal', $insights['pending_approvals']) }} awaiting your approval
            </a>
        @endif
    @endif
</div>
