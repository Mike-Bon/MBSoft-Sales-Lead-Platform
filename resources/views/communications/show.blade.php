@php
    $statusColor = match ($communication->status) {
        \App\Enums\CommunicationStatus::Sent, \App\Enums\CommunicationStatus::Delivered, \App\Enums\CommunicationStatus::Read => 'green',
        \App\Enums\CommunicationStatus::Failed => 'red',
        default => 'zinc',
    };
@endphp
<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">
            {{ $communication->channel->label() }} {{ strtolower($communication->direction->label()) }} message
        </flux:heading>
        <flux:subheading size="lg">
            <flux:badge size="sm" :color="$statusColor">{{ $communication->status->label() }}</flux:badge>
        </flux:subheading>
        <flux:separator variant="subtle" class="my-6" />

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        @if ($communication->status === \App\Enums\CommunicationStatus::Failed)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
                <flux:callout.heading>Delivery failed</flux:callout.heading>
                <flux:callout.text>{{ $communication->failure_code?->label() ?? $communication->failure_reason }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-6 text-sm">
            <div>
                <div class="text-zinc-500 dark:text-zinc-400">Recipient</div>
                <div>{{ $communication->recipient }}</div>
            </div>
            <div>
                <div class="text-zinc-500 dark:text-zinc-400">Sender</div>
                <div>{{ $communication->sender }}</div>
            </div>
            <div>
                <div class="text-zinc-500 dark:text-zinc-400">Sent by</div>
                <div>{{ $communication->user?->name ?? '— unmatched inbound —' }}</div>
            </div>
            <div>
                <div class="text-zinc-500 dark:text-zinc-400">When</div>
                <div>{{ $communication->created_at->format('M j, Y g:i A') }}</div>
            </div>
            @if ($communication->contact)
                <div>
                    <div class="text-zinc-500 dark:text-zinc-400">Contact</div>
                    <div><a class="underline" href="{{ route('crm.contacts.show', $communication->contact) }}" wire:navigate>{{ $communication->contact->fullName() }}</a></div>
                </div>
            @endif
            @if ($communication->lead)
                <div>
                    <div class="text-zinc-500 dark:text-zinc-400">Lead</div>
                    <div><a class="underline" href="{{ route('crm.leads.show', $communication->lead) }}" wire:navigate>Lead #{{ $communication->lead->id }}</a></div>
                </div>
            @endif
            @if ($communication->opportunity)
                <div>
                    <div class="text-zinc-500 dark:text-zinc-400">Opportunity</div>
                    <div><a class="underline" href="{{ route('crm.opportunities.show', $communication->opportunity) }}" wire:navigate>{{ $communication->opportunity->name }}</a></div>
                </div>
            @endif
        </div>

        @if ($communication->subject)
            <div class="mb-2 font-medium">{{ $communication->subject }}</div>
        @endif
        <div class="rounded-lg border border-zinc-200 p-4 whitespace-pre-line text-sm dark:border-zinc-700">{{ $communication->body }}</div>
    </div>
</x-layouts.app>
