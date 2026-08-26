@php($org = $organization ?? null)

<flux:input name="name" label="Name" value="{{ old('name', $org?->name) }}" required autofocus />

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:input name="industry" label="Industry" value="{{ old('industry', $org?->industry) }}" />
    <flux:input name="website" label="Website" value="{{ old('website', $org?->website) }}" />
    <flux:input name="email" label="Email" type="email" value="{{ old('email', $org?->email) }}" />
    <flux:input name="phone" label="Phone" value="{{ old('phone', $org?->phone) }}" />
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:input name="address" label="Address" value="{{ old('address', $org?->address) }}" />
    <flux:input name="city" label="City" value="{{ old('city', $org?->city) }}" />
    <flux:input name="state_province" label="State / Province" value="{{ old('state_province', $org?->state_province) }}" />
    <flux:input name="country" label="Country" value="{{ old('country', $org?->country) }}" />
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:input name="source" label="Source" value="{{ old('source', $org?->source) }}" />

    <flux:select name="status" label="Status">
        @foreach (\App\Enums\RecordStatus::cases() as $status)
            <flux:select.option value="{{ $status->value }}" :selected="old('status', $org?->status?->value ?? 'active') === $status->value">{{ $status->label() }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:select name="team_id" label="Team" placeholder="Organisation-wide (no team)">
        @foreach ($teams as $team)
            <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id', $org?->team_id) === (string) $team->id">{{ $team->name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="owner_id" label="Owner" placeholder="Unassigned">
        @foreach ($users as $user)
            <flux:select.option value="{{ $user->id }}" :selected="(string) old('owner_id', $org?->owner_id) === (string) $user->id">{{ $user->name }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<flux:textarea name="notes" label="Notes" rows="4">{{ old('notes', $org?->notes) }}</flux:textarea>
