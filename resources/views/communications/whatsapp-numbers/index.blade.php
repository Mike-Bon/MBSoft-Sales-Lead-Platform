<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-start justify-between">
            <flux:heading size="xl" level="1">WhatsApp Business Numbers</flux:heading>
            @can('create', \App\Models\WhatsAppBusinessNumber::class)
                <flux:button href="{{ route('communications.whatsapp-numbers.create') }}" variant="primary" wire:navigate>Register Number</flux:button>
            @endcan
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Display Name</flux:table.column>
                <flux:table.column>Phone Number</flux:table.column>
                <flux:table.column>Team</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Registered by</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($numbers as $number)
                    <flux:table.row>
                        <flux:table.cell>{{ $number->display_name }}</flux:table.cell>
                        <flux:table.cell>{{ $number->phone_number }}</flux:table.cell>
                        <flux:table.cell>{{ $number->team?->name ?? 'Organisation-wide' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$number->status === \App\Enums\WhatsAppNumberStatus::Connected ? 'green' : 'red'">{{ $number->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $number->createdBy?->name }}</flux:table.cell>
                        <flux:table.cell>
                            @can('delete', $number)
                                <form method="POST" action="{{ route('communications.whatsapp-numbers.destroy', $number) }}" onsubmit="return confirm('Remove this WhatsApp number?');">
                                    @csrf
                                    @method('DELETE')
                                    <flux:button type="submit" size="sm" variant="danger">Remove</flux:button>
                                </form>
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No WhatsApp numbers registered yet.</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts.app>
