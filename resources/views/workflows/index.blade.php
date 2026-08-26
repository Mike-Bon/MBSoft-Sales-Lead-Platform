@php
    $statusColor = fn ($status) => match ($status) {
        \App\Enums\WorkflowStatus::Completed => 'green',
        \App\Enums\WorkflowStatus::Failed => 'red',
        \App\Enums\WorkflowStatus::Running => 'amber',
        default => 'zinc',
    };
@endphp
<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">AI Activity</flux:heading>
                <flux:subheading size="lg">What ran, when, and what it found — for your own permitted data only.</flux:subheading>
            </div>
            <flux:button href="{{ route('workflows.approvals.index') }}" wire:navigate>Pending Approvals</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Workflow</flux:table.column>
                <flux:table.column>Scope</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Ran</flux:table.column>
                <flux:table.column>Proposals</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($executions as $execution)
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('workflows.show', $execution) }}" wire:navigate>{{ $execution->workflow->label() }}</a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $execution->scope_type->label() }}{{ $execution->scopeTeam ? ' — '.$execution->scopeTeam->name : '' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$statusColor($execution->status)">{{ $execution->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $execution->created_at->format('M j, Y g:i A') }}</flux:table.cell>
                        <flux:table.cell>{{ $execution->approvals->count() }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No workflow runs yet.</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $executions->links() }}</div>
    </div>
</x-layouts.app>
