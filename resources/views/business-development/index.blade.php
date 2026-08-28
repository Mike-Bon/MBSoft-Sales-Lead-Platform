<x-layouts.app>
    <div class="w-full">
        <flux:heading size="xl" level="1">Business Development</flux:heading>
        <flux:subheading size="lg" class="mb-6">What to work on next — prioritised leads, follow-up gaps, and at-risk opportunities in your scope</flux:subheading>

        <flux:callout icon="information-circle" variant="secondary" class="mb-6">
            Every item below is scored from data already in the CRM (lead status, age, activity, follow-up dates, opportunity stage).
            The priority score is the plain sum of the listed factors — no hidden model. Nothing here changes a record or sends a
            message; use the links to act in the CRM, or ask the Business Development assistant for a call plan or a draft.
        </flux:callout>

        {{-- ── Today's Priorities ─────────────────────────────────── --}}
        <flux:heading size="lg" class="mb-2">Today's Priorities</flux:heading>
        <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">Open leads ranked by a transparent points score. Highest first.</p>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Lead</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Priority</flux:table.column>
                <flux:table.column>Why</flux:table.column>
                <flux:table.column>Recommended action</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($priorities as $lead)
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('crm.leads.show', $lead['id']) }}" wire:navigate>{{ $lead['organization'] ?? $lead['contact'] ?? 'Lead #'.$lead['id'] }}</a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $lead['owner'] }}</div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $lead['status'] }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="match ($lead['priority_band']) { 'high' => 'red', 'medium' => 'amber', default => 'zinc' }">
                                {{ ucfirst($lead['priority_band']) }} · {{ $lead['score'] }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <ul class="list-inside list-disc text-xs text-zinc-600 dark:text-zinc-300">
                                @foreach ($lead['factors'] as $factor)
                                    <li>{{ $factor['factor'] }} <span class="text-zinc-400">(+{{ $factor['points'] }})</span></li>
                                @endforeach
                            </ul>
                        </flux:table.cell>
                        <flux:table.cell class="text-sm">{{ $lead['recommended_action'] }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No open leads in your scope right now.</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{-- ── Follow-up Gaps ─────────────────────────────────────── --}}
        <flux:heading size="lg" class="mb-2">Follow-up Gaps</flux:heading>
        <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">Open leads with an overdue follow-up, or none scheduled at all.</p>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Lead</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Gap</flux:table.column>
                <flux:table.column>Follow-up due</flux:table.column>
                <flux:table.column>Recommended action</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($followUpGaps as $lead)
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('crm.leads.show', $lead['id']) }}" wire:navigate>{{ $lead['organization'] ?? $lead['contact'] ?? 'Lead #'.$lead['id'] }}</a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $lead['owner'] }}</div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $lead['status'] }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$lead['gap'] === 'follow_up_overdue' ? 'red' : 'zinc'">
                                {{ $lead['gap'] === 'follow_up_overdue' ? 'Overdue' : 'None set' }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-sm">
                            {{ $lead['follow_up_due'] ? \Illuminate\Support\Carbon::parse($lead['follow_up_due'])->toDayDateTimeString() : '—' }}
                            @if (! is_null($lead['days_overdue']) && $lead['days_overdue'] > 0)
                                <span class="text-xs text-red-600 dark:text-red-400">({{ $lead['days_overdue'] }}d)</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-sm">{{ $lead['recommended_action'] }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No follow-up gaps — every open lead has a current next step.</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{-- ── At-Risk Opportunities ──────────────────────────────── --}}
        <flux:heading size="lg" class="mb-2">At-Risk Opportunities</flux:heading>
        <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">Open opportunities that have stalled or passed their expected close date.</p>
        <div class="space-y-3">
            @forelse ($atRisk as $opportunity)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <a class="font-medium underline" href="{{ route('crm.opportunities.show', $opportunity['id']) }}" wire:navigate>{{ $opportunity['name'] }}</a>
                        <flux:badge size="sm" color="amber">Review</flux:badge>
                    </div>
                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $opportunity['organization'] ?? '—' }} · {{ $opportunity['stage'] }} · {{ $opportunity['owner'] }}
                    </div>
                    <ul class="mt-2 list-inside list-disc text-sm text-zinc-600 dark:text-zinc-300">
                        @foreach ($opportunity['reasons'] as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $opportunity['recommended_action'] }}</p>
                </div>
            @empty
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No at-risk opportunities in your scope.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
