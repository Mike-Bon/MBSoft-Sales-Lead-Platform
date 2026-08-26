{{--
    STEP 53/54/55: a small, additive "AI Insights" card — not a command
    center. Shows the latest run of each workflow (if any) and the
    pending-approval count, all already scoped to the viewing user.
    Clicking through leads to the real underlying data (STEP 54's "the
    AI should not become the only way to access the information").
--}}
@props(['insights'])

<div class="mb-8 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
    <div class="mb-3 flex items-center justify-between">
        <flux:heading size="lg">AI Insights</flux:heading>
        <flux:button size="sm" href="{{ route('workflows.index') }}" wire:navigate>View All Activity</flux:button>
    </div>

    <div class="space-y-2 text-sm">
        @foreach (\App\Enums\WorkflowType::cases() as $type)
            @php($execution = $insights['latest'][$type->value] ?? null)
            <div class="flex items-start justify-between gap-4">
                <span class="text-zinc-500 dark:text-zinc-400">{{ $type->label() }}:</span>
                @if ($execution)
                    <a class="flex-1 truncate text-right underline" href="{{ route('workflows.show', $execution) }}" wire:navigate>
                        {{ \Illuminate\Support\Str::limit($execution->result ?? 'No summary available.', 80) }}
                    </a>
                @else
                    <span class="text-right text-zinc-400 dark:text-zinc-500">Not run yet</span>
                @endif
            </div>
        @endforeach
    </div>

    @if ($insights['pending_approvals'] > 0)
        <flux:separator variant="subtle" class="my-3" />
        <a class="text-sm font-medium underline" href="{{ route('workflows.approvals.index') }}" wire:navigate>
            {{ $insights['pending_approvals'] }} {{ \Illuminate\Support\Str::plural('proposal', $insights['pending_approvals']) }} awaiting your approval
        </a>
    @endif
</div>
