@php($l = $lead ?? null)

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:select name="organization_id" label="Organization" placeholder="No organization">
        @foreach ($organizations as $organization)
            <flux:select.option value="{{ $organization->id }}" :selected="(string) old('organization_id', $l?->organization_id ?? $selectedOrganizationId ?? '') === (string) $organization->id">{{ $organization->name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="contact_id" label="Contact" placeholder="No contact">
        @foreach ($contacts as $contact)
            <flux:select.option value="{{ $contact->id }}" :selected="(string) old('contact_id', $l?->contact_id ?? $selectedContactId ?? '') === (string) $contact->id">{{ $contact->fullName() }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    @if ($l)
        <flux:select name="status" label="Status" required>
            @foreach ($statuses as $status)
                <flux:select.option value="{{ $status->value }}" :selected="old('status', $l->status->value) === $status->value">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    @endif

    <flux:select name="priority" label="Priority" required>
        @foreach ($priorities as $priority)
            <flux:select.option value="{{ $priority->value }}" :selected="old('priority', $l?->priority?->value ?? 'medium') === $priority->value">{{ $priority->label() }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:input name="source" label="Source" value="{{ old('source', $l?->source) }}" />
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
    <flux:input name="estimated_value" label="Estimated value" type="number" step="0.01" min="0" value="{{ old('estimated_value', $l?->estimated_value) }}" />
    <flux:input name="currency" label="Currency" value="{{ old('currency', $l?->currency ?? \App\Support\Money::defaultCurrency()) }}" maxlength="3" />
    <flux:input name="expected_close_date" label="Expected close date" type="date" value="{{ old('expected_close_date', optional($l?->expected_close_date)->format('Y-m-d')) }}" />
</div>

<flux:input name="next_follow_up_at" label="Next follow-up" type="datetime-local" value="{{ old('next_follow_up_at', optional($l?->next_follow_up_at)->format('Y-m-d\TH:i')) }}" />

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:select name="team_id" label="Team" placeholder="Organisation-wide (no team)">
        @foreach ($teams as $team)
            <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id', $l?->team_id) === (string) $team->id">{{ $team->name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="owner_id" label="Owner" placeholder="Unassigned">
        @foreach ($users as $user)
            <flux:select.option value="{{ $user->id }}" :selected="(string) old('owner_id', $l?->owner_id) === (string) $user->id">{{ $user->name }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<flux:textarea name="description" label="Description" rows="3">{{ old('description', $l?->description) }}</flux:textarea>
<flux:textarea name="notes" label="Notes" rows="4">{{ old('notes', $l?->notes) }}</flux:textarea>
