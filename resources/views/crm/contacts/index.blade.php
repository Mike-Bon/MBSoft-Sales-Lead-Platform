<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">Contacts</flux:heading>
                <flux:subheading size="lg">People at your organizations</flux:subheading>
            </div>
            <flux:button href="{{ route('crm.contacts.create') }}" variant="primary" wire:navigate>New Contact</flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <form method="GET" action="{{ route('crm.contacts.index') }}" class="mb-6 flex flex-wrap items-end gap-4">
            <flux:input name="search" label="Search" placeholder="Name or email…" value="{{ $filters['search'] ?? '' }}" />

            <flux:select name="organization_id" label="Organization" placeholder="All organizations">
                @foreach ($organizations as $organization)
                    <flux:select.option value="{{ $organization->id }}" :selected="(string) ($filters['organization_id'] ?? '') === (string) $organization->id">{{ $organization->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select name="team_id" label="Team" placeholder="All teams">
                @foreach ($teams as $team)
                    <flux:select.option value="{{ $team->id }}" :selected="(string) ($filters['team_id'] ?? '') === (string) $team->id">{{ $team->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button type="submit">Filter</flux:button>
            <flux:button href="{{ route('crm.contacts.index') }}" variant="ghost" wire:navigate>Reset</flux:button>
        </form>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Organization</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Owner</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($contacts as $contact)
                    <flux:table.row>
                        <flux:table.cell><a class="underline" href="{{ route('crm.contacts.show', $contact) }}" wire:navigate>{{ $contact->fullName() }}</a></flux:table.cell>
                        <flux:table.cell>{{ $contact->organization?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $contact->email ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $contact->owner?->name ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4">No contacts found.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $contacts->links() }}</div>
    </div>
</x-layouts.app>
