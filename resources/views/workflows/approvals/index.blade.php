@php
    $decidedColor = fn ($status) => $status === \App\Enums\ApprovalStatus::Approved ? 'green' : 'red';
@endphp
<x-layouts.app>
    <div class="w-full max-w-3xl">
        <flux:heading size="xl" level="1">Pending Approvals</flux:heading>
        <flux:subheading size="lg">Proposals from AI workflows. Nothing here has been sent — review, edit if needed, then send, or reject.</flux:subheading>
        <flux:separator variant="subtle" class="my-6" />

        @if (session('status'))
            <flux:callout variant="success" class="mb-6" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        @forelse ($approvals as $approval)
            <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                <div class="mb-2 flex items-center justify-between">
                    <div class="text-sm font-medium">{{ $approval->channel->label() }} to {{ $approval->recipient }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Expires {{ $approval->expires_at->format('M j, Y') }}</div>
                </div>

                <div class="mb-1 text-xs text-zinc-500 dark:text-zinc-400">
                    From: <a class="underline" href="{{ route('workflows.show', $approval->workflow_execution_id) }}" wire:navigate>{{ $approval->workflowExecution->workflow->label() }}</a>
                    @if ($approval->contact) &middot; {{ $approval->contact->fullName() }} @endif
                    @if ($approval->opportunity) &middot; {{ $approval->opportunity->name }} @endif
                </div>

                @if ($approval->subject)
                    <div class="mt-2 font-medium">{{ $approval->subject }}</div>
                @endif
                <p class="mt-1 rounded border border-zinc-200 bg-white p-3 text-sm whitespace-pre-line dark:border-zinc-700 dark:bg-zinc-900">{{ $approval->body }}</p>

                <div class="mt-3 flex gap-3">
                    @if ($approval->channel === \App\Enums\CommunicationChannel::Email)
                        <flux:button
                            href="{{ route('communications.compose-email', ['workflow_approval_id' => $approval->id]) }}"
                            variant="primary"
                            size="sm"
                            wire:navigate
                        >
                            Review &amp; Send
                        </flux:button>
                    @else
                        <flux:button
                            href="{{ route('communications.compose-whatsapp', ['workflow_approval_id' => $approval->id]) }}"
                            variant="primary"
                            size="sm"
                            wire:navigate
                        >
                            Review &amp; Send
                        </flux:button>
                    @endif

                    <form method="POST" action="{{ route('workflows.approvals.reject', $approval) }}" onsubmit="return confirm('Reject this proposal? It will not be sent.');">
                        @csrf
                        <flux:button type="submit" variant="ghost" size="sm">Reject</flux:button>
                    </form>
                </div>
            </div>
        @empty
            <p class="text-sm text-zinc-500 dark:text-zinc-400">No pending approvals.</p>
        @endforelse

        @if ($decided->isNotEmpty())
            <flux:heading size="lg" class="mt-8 mb-2">Recently Decided</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Channel</flux:table.column>
                    <flux:table.column>Recipient</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Decided</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($decided as $approval)
                        <flux:table.row>
                            <flux:table.cell>{{ $approval->channel->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $approval->recipient }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$decidedColor($approval->status)">{{ $approval->status->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $approval->decided_at?->format('M j, Y g:i A') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</x-layouts.app>
