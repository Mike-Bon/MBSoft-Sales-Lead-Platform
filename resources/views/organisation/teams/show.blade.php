<x-layouts.app>
    <div class="w-full max-w-2xl">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">{{ $team->name }}</flux:heading>
                <flux:subheading size="lg">
                    <flux:badge size="sm">{{ $team->status->label() }}</flux:badge>
                    @if ($team->code)
                        &middot; {{ $team->code }}
                    @endif
                </flux:subheading>
            </div>
            @can('update', $team)
                <flux:button href="{{ route('organisation.teams.edit', $team) }}" wire:navigate>Edit</flux:button>
            @endcan
        </div>
        <flux:separator variant="subtle" class="mb-6" />

        <div class="mb-6">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Team Head</div>
            <div class="font-medium">{{ $team->teamHead?->name ?? '— unassigned —' }}</div>
        </div>

        <flux:heading size="lg" class="mb-2">Members</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Role</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($team->members as $member)
                    <flux:table.row>
                        <flux:table.cell>{{ $member->name }}</flux:table.cell>
                        <flux:table.cell>{{ $member->email }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $member->role->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3">No members yet.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts.app>
