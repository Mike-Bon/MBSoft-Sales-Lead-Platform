@php($c = $contact ?? null)

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:input name="first_name" label="First name" value="{{ old('first_name', $c?->first_name) }}" required autofocus />
    <flux:input name="last_name" label="Last name" value="{{ old('last_name', $c?->last_name) }}" required />
</div>

<flux:select name="organization_id" label="Organization" placeholder="No organization">
    @foreach ($organizations as $organization)
        <flux:select.option value="{{ $organization->id }}" :selected="(string) old('organization_id', $c?->organization_id ?? $selectedOrganizationId ?? '') === (string) $organization->id">{{ $organization->name }}</flux:select.option>
    @endforeach
</flux:select>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:input name="job_title" label="Job title" value="{{ old('job_title', $c?->job_title) }}" />
    <flux:input name="email" label="Email" type="email" value="{{ old('email', $c?->email) }}" />
    <flux:input name="phone" label="Phone" value="{{ old('phone', $c?->phone) }}" />
    <flux:input name="mobile" label="Mobile" value="{{ old('mobile', $c?->mobile) }}" />
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:select name="status" label="Status">
        @foreach (\App\Enums\RecordStatus::cases() as $status)
            <flux:select.option value="{{ $status->value }}" :selected="old('status', $c?->status?->value ?? 'active') === $status->value">{{ $status->label() }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:select name="team_id" label="Team" placeholder="Organisation-wide (no team)">
        @foreach ($teams as $team)
            <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id', $c?->team_id) === (string) $team->id">{{ $team->name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="owner_id" label="Owner" placeholder="Unassigned">
        @foreach ($users as $user)
            <flux:select.option value="{{ $user->id }}" :selected="(string) old('owner_id', $c?->owner_id) === (string) $user->id">{{ $user->name }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<flux:textarea name="notes" label="Notes" rows="4">{{ old('notes', $c?->notes) }}</flux:textarea>
