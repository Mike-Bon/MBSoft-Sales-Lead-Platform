<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">Organizations</flux:heading>
                <flux:subheading size="lg">Companies and prospects</flux:subheading>
            </div>
            <flux:button href="{{ route('crm.organizations.create') }}" variant="primary" wire:navigate>New Organization</flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <form method="GET" action="{{ route('crm.organizations.index') }}" class="mb-6 flex flex-wrap items-end gap-4">
            <flux:input name="search" label="Search" placeholder="Name…" value="{{ $filters['search'] ?? '' }}" />

            <flux:select name="industry" label="Industry" placeholder="All industries">
                @foreach ($industries as $industry)
                    <flux:select.option value="{{ $industry }}" :selected="($filters['industry'] ?? null) === $industry">{{ $industry }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select name="team_id" label="Team" placeholder="All teams">
                @foreach ($teams as $team)
                    <flux:select.option value="{{ $team->id }}" :selected="(string) ($filters['team_id'] ?? '') === (string) $team->id">{{ $team->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select name="owner_id" label="Owner" placeholder="All owners">
                @foreach ($users as $user)
                    <flux:select.option value="{{ $user->id }}" :selected="(string) ($filters['owner_id'] ?? '') === (string) $user->id">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button type="submit">Filter</flux:button>
            <flux:button href="{{ route('crm.organizations.index') }}" variant="ghost" wire:navigate>Reset</flux:button>
        </form>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Industry</flux:table.column>
                <flux:table.column>Owner</flux:table.column>
                <flux:table.column>Team</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($organizations as $organization)
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('crm.organizations.show', $organization) }}" wire:navigate>{{ $organization->name }}</a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $organization->industry ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $organization->owner?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $organization->team?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $organization->status->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">No organizations found.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $organizations->links() }}</div>
    </div>
</x-layouts.app>
