<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">Targets</flux:heading>
                <flux:subheading size="lg">Manager, team, and individual sales targets</flux:subheading>
            </div>
            @can('create', App\Models\Target::class)
                <flux:button href="{{ route('performance.targets.create') }}" variant="primary" wire:navigate>New Target</flux:button>
            @endcan
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Owner / Team</flux:table.column>
                <flux:table.column>Period</flux:table.column>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($targets as $target)
                    <flux:table.row>
                        <flux:table.cell><flux:badge size="sm">{{ $target->target_type->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('performance.targets.show', $target) }}" wire:navigate>
                                {{ $target->owner?->name ?? $target->team?->name ?? '—' }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $target->period_start->format('M j, Y') }} – {{ $target->period_end->format('M j, Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $target->currency }} {{ number_format((float) $target->target_amount, 2) }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $target->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">No targets found.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $targets->links() }}</div>
    </div>
</x-layouts.app>
