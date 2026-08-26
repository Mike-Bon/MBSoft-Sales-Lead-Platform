<x-layouts.app>
    <div class="w-full max-w-xl">
        <flux:heading size="xl" level="1">Edit User</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $targetUser->name }} &lt;{{ $targetUser->email }}&gt;</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('organisation.users.update', $targetUser) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <flux:select name="role" label="Role" required>
                @foreach ($roles as $role)
                    <flux:select.option value="{{ $role->value }}" :selected="old('role', $targetUser->role->value) === $role->value">{{ $role->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select name="team_id" label="Team" placeholder="No team (Manager only)">
                @foreach ($teams as $team)
                    <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id', $targetUser->team_id) === (string) $team->id">{{ $team->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="{{ route('organisation.users.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
