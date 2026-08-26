@php
    $statusColor = fn ($status) => match ($status) {
        \App\Enums\CommunicationStatus::Sent, \App\Enums\CommunicationStatus::Delivered, \App\Enums\CommunicationStatus::Read => 'green',
        \App\Enums\CommunicationStatus::Failed => 'red',
        default => 'zinc',
    };
@endphp
<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-start justify-between">
            <flux:heading size="xl" level="1">Communications</flux:heading>
            <div class="flex gap-2">
                @can('create', \App\Models\MessageTemplate::class)
                    <flux:button href="{{ route('communications.templates.index') }}" wire:navigate>Templates</flux:button>
                @endcan
                @can('viewAny', \App\Models\WhatsAppBusinessNumber::class)
                    <flux:button href="{{ route('communications.whatsapp-numbers.index') }}" wire:navigate>WhatsApp Numbers</flux:button>
                @endcan
                <flux:button href="{{ route('communications.compose-email') }}" variant="primary" wire:navigate>Send Email</flux:button>
                <flux:button href="{{ route('communications.compose-whatsapp') }}" variant="primary" wire:navigate>Send WhatsApp</flux:button>
            </div>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <form method="GET" class="mb-6 flex flex-wrap items-end gap-4">
            <flux:select name="channel" label="Channel" placeholder="All">
                @foreach (\App\Enums\CommunicationChannel::cases() as $channel)
                    <flux:select.option value="{{ $channel->value }}" :selected="($filters['channel'] ?? null) === $channel->value">{{ $channel->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select name="status" label="Status" placeholder="All">
                @foreach (\App\Enums\CommunicationStatus::cases() as $status)
                    <flux:select.option value="{{ $status->value }}" :selected="($filters['status'] ?? null) === $status->value">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit">Filter</flux:button>
        </form>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>When</flux:table.column>
                <flux:table.column>Channel</flux:table.column>
                <flux:table.column>Direction</flux:table.column>
                <flux:table.column>Recipient / Sender</flux:table.column>
                <flux:table.column>Subject</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Sent by</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($communications as $communication)
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('communications.show', $communication) }}" wire:navigate>
                                {{ $communication->created_at->format('M j, Y g:i A') }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $communication->channel->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $communication->direction->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $communication->direction === \App\Enums\CommunicationDirection::Outbound ? $communication->recipient : $communication->sender }}</flux:table.cell>
                        <flux:table.cell>{{ $communication->subject ?? \Illuminate\Support\Str::limit($communication->body, 40) }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$statusColor($communication->status)">{{ $communication->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $communication->user?->name ?? '— unmatched —' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No communications yet.</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $communications->links() }}</div>
    </div>
</x-layouts.app>
