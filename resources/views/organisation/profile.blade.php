<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">My Profile</flux:heading>
        <flux:subheading size="lg" class="mb-6">Your account, role, and team context</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <div class="space-y-4">
            <div class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Name</div>
                    <div class="font-medium">{{ $profileUser->name }}</div>
                </div>
                <div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Email</div>
                    <div class="font-medium">{{ $profileUser->email }}</div>
                </div>
            </div>

            <div class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Role</div>
                    <flux:badge>{{ $profileUser->role->label() }}</flux:badge>
                </div>
                <div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Team</div>
                    @if ($profileUser->team)
                        <div class="font-medium">{{ $profileUser->team->name }}</div>
                    @else
                        <div class="text-zinc-500 dark:text-zinc-400">
                            {{ $profileUser->isManager() ? 'Organisation-wide' : 'Not yet assigned' }}
                        </div>
                    @endif
                </div>
            </div>

            @if ($profileUser->headedTeam)
                <flux:callout icon="users" heading="You head {{ $profileUser->headedTeam->name }}">
                    You are the Team Head for this team's members.
                </flux:callout>
            @endif
        </div>

        <div class="mt-8">
            <flux:heading size="lg">Available management options</flux:heading>
            <ul class="mt-2 list-inside list-disc text-sm text-zinc-600 dark:text-zinc-400">
                @can('viewAny', App\Models\Team::class)
                    <li><a class="underline" href="{{ route('organisation.teams.index') }}" wire:navigate>Manage teams</a></li>
                @endcan
                @can('viewAny', App\Models\User::class)
                    <li><a class="underline" href="{{ route('organisation.users.index') }}" wire:navigate>Manage users</a></li>
                @endcan
                @if (! $profileUser->isManager())
                    <li>None &mdash; organisational management is restricted to the Manager.</li>
                @endif
            </ul>
        </div>

        <div class="mt-8">
            <a class="text-sm underline" href="/settings/profile" wire:navigate>Edit name, email, or password</a>
        </div>
    </div>
</x-layouts.app>
