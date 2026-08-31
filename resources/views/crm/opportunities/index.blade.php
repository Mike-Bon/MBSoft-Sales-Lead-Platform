@php
    $stageColor = fn ($stage) => match ($stage) {
        \App\Enums\OpportunityStage::ClosedWon => 'green',
        \App\Enums\OpportunityStage::ClosedLost => 'red',
        default => null,
    };
@endphp
<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">Opportunities</flux:heading>
                <flux:subheading size="lg">The sales pipeline</flux:subheading>
            </div>
            <flux:button href="{{ route('crm.opportunities.create') }}" variant="primary" wire:navigate>New Opportunity</flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <form method="GET" action="{{ route('crm.opportunities.index') }}" class="mb-6 flex flex-wrap items-end gap-4">
            <flux:select name="stage" label="Stage" placeholder="All stages">
                @foreach ($stages as $stage)
                    <flux:select.option value="{{ $stage->value }}" :selected="($filters['stage'] ?? null) === $stage->value">{{ $stage->label() }}</flux:select.option>
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
            <flux:button href="{{ route('crm.opportunities.index') }}" variant="ghost" wire:navigate>Reset</flux:button>
        </form>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Stage</flux:table.column>
                <flux:table.column>Owner</flux:table.column>
                <flux:table.column>Team</flux:table.column>
                <flux:table.column>Value</flux:table.column>
                <flux:table.column>Probability</flux:table.column>
                <flux:table.column>Expected Close</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($opportunities as $opportunity)
                    <flux:table.row>
                        <flux:table.cell><a class="underline" href="{{ route('crm.opportunities.show', $opportunity) }}" wire:navigate>{{ $opportunity->name }}</a></flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$stageColor($opportunity->stage)">{{ $opportunity->stage->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $opportunity->owner?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $opportunity->team?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $opportunity->value !== null ? \App\Support\Money::format((float) $opportunity->value, $opportunity->currency, 2) : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $opportunity->probability !== null ? $opportunity->probability.'%' : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ optional($opportunity->expected_close_date)->format('M j, Y') ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="7">No opportunities found.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $opportunities->links() }}</div>
    </div>
</x-layouts.app>
