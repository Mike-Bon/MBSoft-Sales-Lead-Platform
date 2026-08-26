<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">Activities</flux:heading>
                <flux:subheading size="lg">Everything logged across your permitted CRM records</flux:subheading>
            </div>
            <flux:button href="{{ route('crm.activities.create') }}" variant="primary" wire:navigate>Log Activity</flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <form method="GET" action="{{ route('crm.activities.index') }}" class="mb-6 flex flex-wrap items-end gap-4">
            <flux:select name="type" label="Type" placeholder="All types">
                @foreach ($types as $type)
                    <flux:select.option value="{{ $type->value }}" :selected="($filters['type'] ?? null) === $type->value">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button type="submit">Filter</flux:button>
            <flux:button href="{{ route('crm.activities.index') }}" variant="ghost" wire:navigate>Reset</flux:button>
        </form>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>When</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Subject</flux:table.column>
                <flux:table.column>Related to</flux:table.column>
                <flux:table.column>By</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($activities as $activity)
                    <flux:table.row>
                        <flux:table.cell>{{ $activity->occurred_at->format('M j, Y g:i A') }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $activity->type->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $activity->subject ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($activity->lead)
                                <a class="underline" href="{{ route('crm.leads.show', $activity->lead) }}" wire:navigate>Lead #{{ $activity->lead->id }}</a>
                            @elseif ($activity->opportunity)
                                <a class="underline" href="{{ route('crm.opportunities.show', $activity->opportunity) }}" wire:navigate>{{ $activity->opportunity->name }}</a>
                            @elseif ($activity->organization)
                                <a class="underline" href="{{ route('crm.organizations.show', $activity->organization) }}" wire:navigate>{{ $activity->organization->name }}</a>
                            @elseif ($activity->contact)
                                <a class="underline" href="{{ route('crm.contacts.show', $activity->contact) }}" wire:navigate>{{ $activity->contact->fullName() }}</a>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $activity->user?->name ?? 'System' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">No activities found.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $activities->links() }}</div>
    </div>
</x-layouts.app>
