<x-layouts.app>
    <div class="w-full max-w-3xl">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">{{ $contact->fullName() }}</flux:heading>
                <flux:subheading size="lg">
                    <flux:badge size="sm">{{ $contact->status->label() }}</flux:badge>
                    @if ($contact->job_title)
                        &middot; {{ $contact->job_title }}
                    @endif
                </flux:subheading>
            </div>
            @can('update', $contact)
                <flux:button href="{{ route('crm.contacts.edit', $contact) }}" wire:navigate>Edit</flux:button>
            @endcan
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 mb-8">
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Organization</div>
                <div>
                    @if ($contact->organization)
                        <a class="underline" href="{{ route('crm.organizations.show', $contact->organization) }}" wire:navigate>{{ $contact->organization->name }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Owner</div>
                <div>{{ $contact->owner?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Email</div>
                <div>{{ $contact->email ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Phone / Mobile</div>
                <div>{{ collect([$contact->phone, $contact->mobile])->filter()->implode(' / ') ?: '—' }}</div>
            </div>
        </div>

        @if ($contact->notes)
            <div class="mb-8">
                <flux:heading size="lg" class="mb-1">Notes</flux:heading>
                <p class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-400">{{ $contact->notes }}</p>
            </div>
        @endif

        <flux:heading size="lg" class="mb-2">Leads</flux:heading>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Owner</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($contact->leads as $lead)
                    <flux:table.row>
                        <flux:table.cell><a class="underline" href="{{ route('crm.leads.show', $lead) }}" wire:navigate>{{ $lead->status->label() }}</a></flux:table.cell>
                        <flux:table.cell>{{ $lead->owner?->name ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="2">No leads yet.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:heading size="lg" class="mb-2">Opportunities</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Stage</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($contact->opportunities as $opportunity)
                    <flux:table.row>
                        <flux:table.cell><a class="underline" href="{{ route('crm.opportunities.show', $opportunity) }}" wire:navigate>{{ $opportunity->name }}</a></flux:table.cell>
                        <flux:table.cell>{{ $opportunity->stage->label() }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="2">No opportunities yet.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts.app>
