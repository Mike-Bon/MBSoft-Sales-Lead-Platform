@php
    $followUpBadge = fn ($lead) => match ($lead->followUpStatus()) {
        \App\Enums\FollowUpStatus::Overdue => 'red',
        \App\Enums\FollowUpStatus::DueToday => 'amber',
        \App\Enums\FollowUpStatus::Upcoming => null,
        \App\Enums\FollowUpStatus::NotSet => null,
    };
@endphp
<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">Leads</flux:heading>
                <flux:subheading size="lg">The sales pipeline's entry point</flux:subheading>
            </div>
            <flux:button href="{{ route('crm.leads.create') }}" variant="primary" wire:navigate>New Lead</flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <form method="GET" action="{{ route('crm.leads.index') }}" class="mb-6 flex flex-wrap items-end gap-4">
            <flux:select name="status" label="Status" placeholder="All statuses">
                @foreach ($statuses as $status)
                    <flux:select.option value="{{ $status->value }}" :selected="($filters['status'] ?? null) === $status->value">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select name="priority" label="Priority" placeholder="All priorities">
                @foreach ($priorities as $priority)
                    <flux:select.option value="{{ $priority->value }}" :selected="($filters['priority'] ?? null) === $priority->value">{{ $priority->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select name="follow_up" label="Follow-up" placeholder="Any">
                <flux:select.option value="overdue" :selected="($filters['follow_up'] ?? null) === 'overdue'">Overdue</flux:select.option>
                <flux:select.option value="due_today" :selected="($filters['follow_up'] ?? null) === 'due_today'">Due Today</flux:select.option>
                <flux:select.option value="upcoming" :selected="($filters['follow_up'] ?? null) === 'upcoming'">Upcoming</flux:select.option>
                <flux:select.option value="not_set" :selected="($filters['follow_up'] ?? null) === 'not_set'">No Follow-up Set</flux:select.option>
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

            <flux:input name="source" label="Source" value="{{ $filters['source'] ?? '' }}" />

            <flux:button type="submit">Filter</flux:button>
            <flux:button href="{{ route('crm.leads.index') }}" variant="ghost" wire:navigate>Reset</flux:button>
        </form>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Organization / Contact</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Priority</flux:table.column>
                <flux:table.column>Owner</flux:table.column>
                <flux:table.column>Team</flux:table.column>
                <flux:table.column>Est. Value</flux:table.column>
                <flux:table.column>Follow-up</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($leads as $lead)
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('crm.leads.show', $lead) }}" wire:navigate>
                                {{ $lead->organization?->name ?? $lead->contact?->fullName() ?? "Lead #{$lead->id}" }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ $lead->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $lead->priority->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $lead->owner?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $lead->team?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $lead->estimated_value !== null ? $lead->currency.' '.number_format((float) $lead->estimated_value, 2) : '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$followUpBadge($lead)">{{ $lead->followUpStatus()->label() }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="7">No leads found.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $leads->links() }}</div>
    </div>
</x-layouts.app>
