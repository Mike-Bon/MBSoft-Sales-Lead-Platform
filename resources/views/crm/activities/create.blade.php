<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Log Activity</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('crm.activities.store') }}" class="space-y-6">
            @csrf
            @if ($redirectTo)
                <input type="hidden" name="redirect_to" value="{{ $redirectTo }}" />
            @endif

            <flux:select name="type" label="Type" required>
                @foreach ($types as $type)
                    <flux:select.option value="{{ $type->value }}" :selected="old('type') === $type->value">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:select name="organization_id" label="Organization" placeholder="None">
                    @foreach ($organizations as $organization)
                        <flux:select.option value="{{ $organization->id }}" :selected="(string) old('organization_id', $context['organization_id'] ?? '') === (string) $organization->id">{{ $organization->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select name="contact_id" label="Contact" placeholder="None">
                    @foreach ($contacts as $contact)
                        <flux:select.option value="{{ $contact->id }}" :selected="(string) old('contact_id', $context['contact_id'] ?? '') === (string) $contact->id">{{ $contact->fullName() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select name="lead_id" label="Lead" placeholder="None">
                    @foreach ($leads as $lead)
                        <flux:select.option value="{{ $lead->id }}" :selected="(string) old('lead_id', $context['lead_id'] ?? '') === (string) $lead->id">
                            {{ $lead->organization?->name ?? $lead->contact?->fullName() ?? "Lead #{$lead->id}" }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select name="opportunity_id" label="Opportunity" placeholder="None">
                    @foreach ($opportunities as $opportunity)
                        <flux:select.option value="{{ $opportunity->id }}" :selected="(string) old('opportunity_id', $context['opportunity_id'] ?? '') === (string) $opportunity->id">{{ $opportunity->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input name="subject" label="Subject" value="{{ old('subject') }}" />
            <flux:textarea name="description" label="Details" rows="3">{{ old('description') }}</flux:textarea>
            <flux:input name="occurred_at" label="Occurred at" type="datetime-local" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" />

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Log Activity</flux:button>
                <flux:button href="{{ route('crm.activities.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
