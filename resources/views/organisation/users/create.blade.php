<x-layouts.app>
    <div class="w-full max-w-xl">
        <flux:heading size="xl" level="1">Create User</flux:heading>
        <flux:subheading size="lg" class="mb-6">Add a new organisation member and assign their role and team</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('organisation.users.store') }}" class="space-y-6">
            @csrf

            <flux:input name="name" label="Name" type="text" value="{{ old('name') }}" required autofocus />
            <flux:input name="email" label="Email" type="email" value="{{ old('email') }}" required />
            <flux:input name="password" label="Temporary password" type="password" required description="At least 8 characters. Share this with the user privately; they can change it after logging in." />

            <flux:select name="role" label="Role" placeholder="Select a role…" required>
                @foreach ($roles as $role)
                    <flux:select.option value="{{ $role->value }}" :selected="old('role') === $role->value">{{ $role->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select name="team_id" label="Team" placeholder="No team (Manager only)">
                @foreach ($teams as $team)
                    <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id') === (string) $team->id">{{ $team->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Create User</flux:button>
                <flux:button href="{{ route('organisation.users.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
