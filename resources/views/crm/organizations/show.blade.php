<x-layouts.app>
    <div class="w-full max-w-4xl">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">{{ $organization->name }}</flux:heading>
                <flux:subheading size="lg">
                    <flux:badge size="sm">{{ $organization->status->label() }}</flux:badge>
                    @if ($organization->industry)
                        &middot; {{ $organization->industry }}
                    @endif
                </flux:subheading>
            </div>
            @can('update', $organization)
                <flux:button href="{{ route('crm.organizations.edit', $organization) }}" wire:navigate>Edit</flux:button>
            @endcan
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 mb-8">
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Owner</div>
                <div>{{ $organization->owner?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Team</div>
                <div>{{ $organization->team?->name ?? '— organisation-wide —' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Website</div>
                <div>{{ $organization->website ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Email</div>
                <div>{{ $organization->email ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Phone</div>
                <div>{{ $organization->phone ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Location</div>
                <div>{{ collect([$organization->city, $organization->state_province, $organization->country])->filter()->implode(', ') ?: '—' }}</div>
            </div>
        </div>

        @if ($organization->notes)
            <div class="mb-8">
                <flux:heading size="lg" class="mb-1">Notes</flux:heading>
                <p class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-400">{{ $organization->notes }}</p>
            </div>
        @endif

        <div class="mb-8 flex items-center justify-between">
            <flux:heading size="lg">Contacts</flux:heading>
            <flux:button size="sm" href="{{ route('crm.contacts.create', ['organization_id' => $organization->id]) }}" wire:navigate>Add Contact</flux:button>
        </div>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Job Title</flux:table.column>
                <flux:table.column>Email</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($organization->contacts as $contact)
                    <flux:table.row>
                        <flux:table.cell><a class="underline" href="{{ route('crm.contacts.show', $contact) }}" wire:navigate>{{ $contact->fullName() }}</a></flux:table.cell>
                        <flux:table.cell>{{ $contact->job_title ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $contact->email ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="3">No contacts yet.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mb-8 flex items-center justify-between">
            <flux:heading size="lg">Leads</flux:heading>
            <flux:button size="sm" href="{{ route('crm.leads.create', ['organization_id' => $organization->id]) }}" wire:navigate>Add Lead</flux:button>
        </div>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Priority</flux:table.column>
                <flux:table.column>Owner</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($organization->leads as $lead)
                    <flux:table.row>
                        <flux:table.cell><a class="underline" href="{{ route('crm.leads.show', $lead) }}" wire:navigate>{{ $lead->status->label() }}</a></flux:table.cell>
                        <flux:table.cell>{{ $lead->priority->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $lead->owner?->name ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="3">No leads yet.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:heading size="lg" class="mb-2">Opportunities</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Stage</flux:table.column>
                <flux:table.column>Owner</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($organization->opportunities as $opportunity)
                    <flux:table.row>
                        <flux:table.cell><a class="underline" href="{{ route('crm.opportunities.show', $opportunity) }}" wire:navigate>{{ $opportunity->name }}</a></flux:table.cell>
                        <flux:table.cell>{{ $opportunity->stage->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $opportunity->owner?->name ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="3">No opportunities yet.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts.app>
