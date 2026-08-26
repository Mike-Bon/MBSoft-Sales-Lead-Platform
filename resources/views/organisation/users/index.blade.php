<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">Users</flux:heading>
                <flux:subheading size="lg">Manage organisation members, roles, and team assignments</flux:subheading>
            </div>
            <flux:button href="{{ route('organisation.users.create') }}" variant="primary" wire:navigate>Create User</flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Role</flux:table.column>
                <flux:table.column>Team</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($users as $user)
                    <flux:table.row>
                        <flux:table.cell>{{ $user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $user->role->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $user->team?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <a class="text-sm underline" href="{{ route('organisation.users.edit', $user) }}" wire:navigate>Edit</a>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts.app>
