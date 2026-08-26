@php
    $statusColor = match ($execution->status) {
        \App\Enums\WorkflowStatus::Completed => 'green',
        \App\Enums\WorkflowStatus::Failed => 'red',
        \App\Enums\WorkflowStatus::Running => 'amber',
        default => 'zinc',
    };
@endphp
<x-layouts.app>
    <div class="w-full max-w-3xl">
        <flux:heading size="xl" level="1">{{ $execution->workflow->label() }}</flux:heading>
        <flux:subheading size="lg">
            <flux:badge size="sm" :color="$statusColor">{{ $execution->status->label() }}</flux:badge>
            {{ $execution->scope_type->label() }}{{ $execution->scopeTeam ? ' — '.$execution->scopeTeam->name : '' }}
            &middot; ran {{ $execution->created_at->format('M j, Y g:i A') }} ({{ ucfirst($execution->trigger) }})
        </flux:subheading>
        <flux:separator variant="subtle" class="my-6" />

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        @if ($execution->status === \App\Enums\WorkflowStatus::Failed)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
                <flux:callout.heading>This run did not complete</flux:callout.heading>
                <flux:callout.text>{{ $execution->error_summary ?? 'The workflow could not complete.' }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($execution->result)
            <flux:heading size="lg" class="mb-2">Summary</flux:heading>
            <p class="mb-6 rounded-lg border border-zinc-200 p-4 whitespace-pre-line text-sm dark:border-zinc-700">{{ $execution->result }}</p>
        @endif

        @if (! empty($execution->findings))
            <flux:heading size="lg" class="mb-2">What Was Found</flux:heading>
            <p class="mb-6 text-xs text-zinc-500 dark:text-zinc-400">The underlying facts this summary was based on — deterministic CRM data, not AI-generated.</p>
            <pre class="mb-6 overflow-x-auto rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-xs dark:border-zinc-700 dark:bg-zinc-900">{{ json_encode($execution->findings, JSON_PRETTY_PRINT) }}</pre>
        @endif

        @if ($execution->approvals->isNotEmpty())
            <flux:heading size="lg" class="mb-2">Proposed Communications</flux:heading>
            <div class="mb-6 space-y-3">
                @foreach ($execution->approvals as $approval)
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex items-center justify-between">
                            <div class="text-sm">{{ ucfirst($approval->channel->value) }} to {{ $approval->recipient }}</div>
                            <flux:badge size="sm">{{ $approval->status->label() }}</flux:badge>
                        </div>
                        @if ($approval->status === \App\Enums\ApprovalStatus::Pending)
                            <div class="mt-2">
                                <flux:button size="sm" href="{{ route('workflows.approvals.index') }}" wire:navigate>Review in Approval Queue</flux:button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
