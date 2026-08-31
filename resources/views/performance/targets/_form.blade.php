@php($t = $target ?? null)

<flux:select name="target_type" label="Target type" required>
    @foreach ($types as $type)
        <flux:select.option value="{{ $type->value }}" :selected="old('target_type', $t?->target_type?->value) === $type->value">{{ $type->label() }}</flux:select.option>
    @endforeach
</flux:select>

<flux:callout variant="secondary" class="my-4">
    <strong>Manager</strong>: set Owner to the Manager. <strong>Team</strong>: set Team. <strong>Individual</strong>: set Owner to the salesperson (their team is derived automatically). Only the field that applies to the chosen type needs a value.
</flux:callout>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:select name="owner_id" label="Owner (Manager or Individual target)" placeholder="Select a user…">
        @foreach ($users as $user)
            <flux:select.option value="{{ $user->id }}" :selected="(string) old('owner_id', $t?->owner_id) === (string) $user->id">{{ $user->name }} ({{ $user->role->label() }})</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select name="team_id" label="Team (Team target)" placeholder="Select a team…">
        @foreach ($teams as $team)
            <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id', $t?->team_id) === (string) $team->id">{{ $team->name }}</flux:select.option>
        @endforeach
    </flux:select>
</div>

<flux:select name="period_type" label="Period type" required>
    @foreach ($periodTypes as $periodType)
        <flux:select.option value="{{ $periodType->value }}" :selected="old('period_type', $t?->period_type?->value ?? 'monthly') === $periodType->value">{{ $periodType->label() }}</flux:select.option>
    @endforeach
</flux:select>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:input name="period_start" label="Period start" type="date" value="{{ old('period_start', optional($t?->period_start)->format('Y-m-d')) }}" required />
    <flux:input name="period_end" label="Period end" type="date" value="{{ old('period_end', optional($t?->period_end)->format('Y-m-d')) }}" required />
</div>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <flux:input name="target_amount" label="Target amount" type="number" step="0.01" min="0" value="{{ old('target_amount', $t?->target_amount) }}" required />
    <flux:input name="currency" label="Currency" value="{{ old('currency', $t?->currency ?? \App\Support\Money::defaultCurrency()) }}" maxlength="3" required />
</div>

@if ($t)
    <flux:select name="status" label="Status" required>
        @foreach ($statuses as $status)
            <flux:select.option value="{{ $status->value }}" :selected="old('status', $t->status->value) === $status->value">{{ $status->label() }}</flux:select.option>
        @endforeach
    </flux:select>
@endif

<flux:textarea name="notes" label="Notes" rows="3">{{ old('notes', $t?->notes) }}</flux:textarea>
