@php
    $followUpColor = match ($lead->followUpStatus()) {
        \App\Enums\FollowUpStatus::Overdue => 'red',
        \App\Enums\FollowUpStatus::DueToday => 'amber',
        default => null,
    };
@endphp
<x-layouts.app>
    <div class="w-full max-w-4xl">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">
                    {{ $lead->organization?->name ?? $lead->contact?->fullName() ?? "Lead #{$lead->id}" }}
                </flux:heading>
                <flux:subheading size="lg">
                    <flux:badge size="sm">{{ $lead->status->label() }}</flux:badge>
                    <flux:badge size="sm">{{ $lead->priority->label() }} priority</flux:badge>
                    <flux:badge size="sm" :color="$followUpColor">{{ $lead->followUpStatus()->label() }}</flux:badge>
                </flux:subheading>
            </div>
            <div class="flex gap-2">
                @can('create', \App\Models\Communication::class)
                    <flux:button href="{{ route('communications.compose-email', ['lead_id' => $lead->id]) }}" wire:navigate>Send Email</flux:button>
                    <flux:button href="{{ route('communications.compose-whatsapp', ['lead_id' => $lead->id]) }}" wire:navigate>Send WhatsApp</flux:button>
                @endcan
                @can('update', $lead)
                    <flux:button href="{{ route('crm.leads.edit', $lead) }}" wire:navigate>Edit</flux:button>
                @endcan
                @can('create', \App\Models\Opportunity::class)
                    <flux:button href="{{ route('crm.opportunities.create', ['lead_id' => $lead->id]) }}" variant="primary" wire:navigate>Create Opportunity</flux:button>
                @endcan
            </div>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-3 mb-8">
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Organization</div>
                <div>
                    @if ($lead->organization)
                        <a class="underline" href="{{ route('crm.organizations.show', $lead->organization) }}" wire:navigate>{{ $lead->organization->name }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Contact</div>
                <div>
                    @if ($lead->contact)
                        <a class="underline" href="{{ route('crm.contacts.show', $lead->contact) }}" wire:navigate>{{ $lead->contact->fullName() }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Source</div>
                <div>{{ $lead->source ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Owner</div>
                <div>{{ $lead->owner?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Team</div>
                <div>{{ $lead->team?->name ?? '— organisation-wide —' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Estimated Value</div>
                <div>{{ $lead->estimated_value !== null ? $lead->currency.' '.number_format((float) $lead->estimated_value, 2) : '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Expected Close</div>
                <div>{{ optional($lead->expected_close_date)->format('M j, Y') ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Next Follow-up</div>
                <div>{{ optional($lead->next_follow_up_at)->format('M j, Y g:i A') ?? '—' }}</div>
            </div>
        </div>

        @if ($lead->description)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-1">Description</flux:heading>
                <p class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-400">{{ $lead->description }}</p>
            </div>
        @endif

        @if ($lead->notes)
            <div class="mb-8">
                <flux:heading size="lg" class="mb-1">Notes</flux:heading>
                <p class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-400">{{ $lead->notes }}</p>
            </div>
        @endif

        @if ($lead->opportunities->isNotEmpty())
            <div class="mb-8">
                <flux:heading size="lg" class="mb-2">Related Opportunities</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Stage</flux:table.column>
                        <flux:table.column>Value</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($lead->opportunities as $opportunity)
                            <flux:table.row>
                                <flux:table.cell><a class="underline" href="{{ route('crm.opportunities.show', $opportunity) }}" wire:navigate>{{ $opportunity->name }}</a></flux:table.cell>
                                <flux:table.cell>{{ $opportunity->stage->label() }}</flux:table.cell>
                                <flux:table.cell>{{ $opportunity->value !== null ? $opportunity->currency.' '.number_format((float) $opportunity->value, 2) : '—' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif

        <flux:separator variant="subtle" class="my-8" />

        <flux:heading size="lg" class="mb-4">Log an Activity</flux:heading>
        <form method="POST" action="{{ route('crm.activities.store') }}" class="mb-8 space-y-4">
            @csrf
            <input type="hidden" name="lead_id" value="{{ $lead->id }}" />
            <input type="hidden" name="redirect_to" value="{{ route('crm.leads.show', $lead) }}" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select name="type" label="Type" required>
                    @foreach ($activityTypes as $type)
                        <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input name="subject" label="Subject" />
            </div>
            <flux:textarea name="description" label="Details" rows="2" />
            <flux:button type="submit" variant="primary">Log Activity</flux:button>
        </form>

        <flux:heading size="lg" class="mb-4">Activity Timeline</flux:heading>
        <div class="space-y-4">
            @forelse ($timeline as $activity)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm">{{ $activity->type->label() }}</flux:badge>
                            @if ($activity->communication)
                                <flux:badge size="sm" color="blue" href="{{ route('communications.show', $activity->communication) }}" wire:navigate>
                                    {{ $activity->communication->status->label() }}
                                </flux:badge>
                            @endif
                        </div>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $activity->occurred_at->format('M j, Y g:i A') }} &middot; {{ $activity->user?->name ?? 'System' }}</span>
                    </div>
                    @if ($activity->subject)
                        <div class="mt-2 font-medium">{{ $activity->subject }}</div>
                    @endif
                    @if ($activity->description)
                        <p class="mt-1 whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-400">{{ $activity->description }}</p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No activity recorded yet.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
