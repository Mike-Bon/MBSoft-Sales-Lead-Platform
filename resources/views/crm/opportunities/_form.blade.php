@php($o = $opportunity ?? null)

<flux:input name="name" label="Name" value="{{ old('name', $o?->name) }}" required autofocus />

<div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
    <flux:select name="organization_id" label="Organization" placeholder="No organization">
        @foreach ($organizations as $organization)
            <flux:select.option value="{{ $organization->id }}" :selected="(string) old('organization_id', $o?->organization_id) === (string) $organization->id">{{ $organization->name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="contact_id" label="Contact" placeholder="No contact">
        @foreach ($contacts as $contact)
            <flux:select.option value="{{ $contact->id }}" :selected="(string) old('contact_id', $o?->contact_id) === (string) $contact->id">{{ $contact->fullName() }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="lead_id" label="Lead" placeholder="No related lead">
        @foreach ($leads as $lead)
            <flux:select.option value="{{ $lead->id }}" :selected="(string) old('lead_id', $o?->lead_id ?? $selectedLeadId ?? '') === (string) $lead->id">
                {{ $lead->organization?->name ?? $lead->contact?->fullName() ?? "Lead #{$lead->id}" }}
            </flux:select.option>
        @endforeach
    </flux:select>
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:select name="stage" label="Stage" required>
        @foreach ($stages as $stage)
            <flux:select.option value="{{ $stage->value }}" :selected="old('stage', $o?->stage?->value ?? 'qualification') === $stage->value">{{ $stage->label() }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:input name="probability" label="Probability (%)" type="number" min="0" max="100" value="{{ old('probability', $o?->probability) }}" />
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
    <flux:input name="value" label="Value" type="number" step="0.01" min="0" value="{{ old('value', $o?->value) }}" />
    <flux:input name="currency" label="Currency" value="{{ old('currency', $o?->currency ?? \App\Support\Money::defaultCurrency()) }}" maxlength="3" />
    <flux:input name="expected_close_date" label="Expected close date" type="date" value="{{ old('expected_close_date', optional($o?->expected_close_date)->format('Y-m-d')) }}" />
</div>

@if ($o)
    <flux:input
        name="closed_at"
        label="Actual close date"
        type="date"
        value="{{ old('closed_at', optional($o->closed_at)->format('Y-m-d')) }}"
        description="Set automatically when the stage becomes Closed Won/Closed Lost. Only change this to backdate a historical or imported deal — this is the date used for target/performance calculations."
    />
@endif

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:select name="team_id" label="Team" placeholder="Organisation-wide (no team)">
        @foreach ($teams as $team)
            <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id', $o?->team_id) === (string) $team->id">{{ $team->name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="owner_id" label="Owner" placeholder="Unassigned">
        @foreach ($users as $user)
            <flux:select.option value="{{ $user->id }}" :selected="(string) old('owner_id', $o?->owner_id) === (string) $user->id">{{ $user->name }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<flux:textarea name="description" label="Description" rows="3">{{ old('description', $o?->description) }}</flux:textarea>
<flux:textarea name="notes" label="Notes" rows="4">{{ old('notes', $o?->notes) }}</flux:textarea>
