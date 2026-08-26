<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">Teams</flux:heading>
                <flux:subheading size="lg">Manage teams and their Team Heads</flux:subheading>
            </div>
            <flux:button href="{{ route('organisation.teams.create') }}" variant="primary" wire:navigate>Create Team</flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Code</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Team Head</flux:table.column>
                <flux:table.column>Members</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($teams as $team)
                    <flux:table.row>
                        <flux:table.cell>{{ $team->name }}</flux:table.cell>
                        <flux:table.cell>{{ $team->code ?? '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $team->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $team->teamHead?->name ?? '— unassigned —' }}</flux:table.cell>
                        <flux:table.cell>{{ $team->members_count }}</flux:table.cell>
                        <flux:table.cell>
                            <a class="text-sm underline" href="{{ route('organisation.teams.show', $team) }}" wire:navigate>View</a>
                            &middot;
                            <a class="text-sm underline" href="{{ route('organisation.teams.edit', $team) }}" wire:navigate>Edit</a>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts.app>
