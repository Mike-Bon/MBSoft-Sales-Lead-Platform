@php
    $stageColor = match ($opportunity->stage) {
        \App\Enums\OpportunityStage::ClosedWon => 'green',
        \App\Enums\OpportunityStage::ClosedLost => 'red',
        default => null,
    };
@endphp
<x-layouts.app>
    <div class="w-full max-w-4xl">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">{{ $opportunity->name }}</flux:heading>
                <flux:subheading size="lg">
                    <flux:badge size="sm" :color="$stageColor">{{ $opportunity->stage->label() }}</flux:badge>
                    @if ($opportunity->isClosed())
                        <flux:badge size="sm">{{ $opportunity->isWon() ? 'Won' : 'Lost' }}</flux:badge>
                    @endif
                </flux:subheading>
            </div>
            <div class="flex gap-2">
                @can('create', \App\Models\Communication::class)
                    <flux:button href="{{ route('communications.compose-email', ['opportunity_id' => $opportunity->id]) }}" wire:navigate>Send Email</flux:button>
                    <flux:button href="{{ route('communications.compose-whatsapp', ['opportunity_id' => $opportunity->id]) }}" wire:navigate>Send WhatsApp</flux:button>
                @endcan
                @can('update', $opportunity)
                    <flux:button href="{{ route('crm.opportunities.edit', $opportunity) }}" wire:navigate>Edit</flux:button>
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
                    @if ($opportunity->organization)
                        <a class="underline" href="{{ route('crm.organizations.show', $opportunity->organization) }}" wire:navigate>{{ $opportunity->organization->name }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Contact</div>
                <div>
                    @if ($opportunity->contact)
                        <a class="underline" href="{{ route('crm.contacts.show', $opportunity->contact) }}" wire:navigate>{{ $opportunity->contact->fullName() }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Originating Lead</div>
                <div>
                    @if ($opportunity->lead)
                        <a class="underline" href="{{ route('crm.leads.show', $opportunity->lead) }}" wire:navigate>Lead #{{ $opportunity->lead->id }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Owner</div>
                <div>{{ $opportunity->owner?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Team</div>
                <div>{{ $opportunity->team?->name ?? '— organisation-wide —' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Value</div>
                <div>{{ $opportunity->value !== null ? \App\Support\Money::format((float) $opportunity->value, $opportunity->currency, 2) : '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Probability</div>
                <div>{{ $opportunity->probability !== null ? $opportunity->probability.'%' : '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Expected Close</div>
                <div>{{ optional($opportunity->expected_close_date)->format('M j, Y') ?? '—' }}</div>
            </div>
        </div>

        @if ($opportunity->description)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-1">Description</flux:heading>
                <p class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-400">{{ $opportunity->description }}</p>
            </div>
        @endif

        @if ($opportunity->notes)
            <div class="mb-8">
                <flux:heading size="lg" class="mb-1">Notes</flux:heading>
                <p class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-400">{{ $opportunity->notes }}</p>
            </div>
        @endif

        <flux:separator variant="subtle" class="my-8" />

        <flux:heading size="lg" class="mb-4">Log an Activity</flux:heading>
        <form method="POST" action="{{ route('crm.activities.store') }}" class="mb-8 space-y-4">
            @csrf
            <input type="hidden" name="opportunity_id" value="{{ $opportunity->id }}" />
            <input type="hidden" name="redirect_to" value="{{ route('crm.opportunities.show', $opportunity) }}" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select name="type" label="Type" required>
                    @foreach (\App\Enums\ActivityType::cases() as $type)
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
