@php
    use App\Support\Money;
    $fmt = fn ($v) => $v === null ? '—' : Money::format((float) $v, 'PHP', 2);
@endphp
<x-layouts.app>
    <div class="w-full max-w-2xl">
        <div class="mb-1 flex items-center gap-2 text-sm text-zinc-500">
            <a class="underline" href="{{ route('performance.fiscal.actuals.index') }}" wire:navigate>Manage Actuals</a>
            <span>/</span><span>Enter / correct one value</span>
        </div>
        <flux:heading size="xl" level="1">Enter or correct one actual</flux:heading>
        <flux:subheading size="lg" class="mb-4">The controlled fallback to bulk import — for a single branch/month figure.</flux:subheading>

        @if (session('status'))
            <flux:callout icon="check-circle" variant="success" class="mb-4">{{ session('status') }}</flux:callout>
        @endif

        <form method="GET" class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <flux:select name="fiscal_year" label="Fiscal year" onchange="this.form.submit()">
                @foreach ($fiscalYears as $fy)
                    <flux:select.option value="{{ $fy }}" :selected="$fy === $fiscalYear">FY{{ $fy }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select name="reporting_unit_id" label="Reporting unit" onchange="this.form.submit()">
                <flux:select.option value="">Select…</flux:select.option>
                @foreach ($reportingUnits as $u)
                    <flux:select.option value="{{ $u->id }}" :selected="$selectedUnit && $u->id === $selectedUnit->id">{{ $u->team?->name }} — {{ $u->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select name="period_month" label="Fiscal month" onchange="this.form.submit()">
                <flux:select.option value="">Select…</flux:select.option>
                @foreach ($fiscalMonths as $m)
                    <flux:select.option value="{{ $m['ordinal'] }}" :selected="$m['ordinal'] === $selectedMonth">{{ $m['ordinal'] }}. {{ $m['name'] }} {{ $m['calendar_year'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </form>

        @if ($state === null)
            <flux:callout icon="information-circle" variant="secondary">Choose a reporting unit and fiscal month above to enter or correct its actual.</flux:callout>
        @else
            <flux:callout :icon="$state->exists ? 'pencil-square' : 'plus-circle'" variant="secondary" class="mb-4">
                @if ($state->exists)
                    <strong>Currently recorded:</strong> revenue {{ $fmt($state->revenue) }},
                    units {{ $state->units === null ? 'not reported' : number_format($state->units, 2) }}.
                    @if ($state->lastRevision)
                        <span class="block text-xs text-zinc-500">Last changed {{ $state->lastRevision->created_at?->format('Y-m-d H:i') }} by {{ $state->lastRevision->changedBy?->name ?? 'system' }}.</span>
                    @endif
                @else
                    <strong>No actual has been reported for this branch and month.</strong> Entering a value here will create it.
                @endif
            </flux:callout>

            <form method="POST" action="{{ route('performance.fiscal.actuals.entry.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="fiscal_year" value="{{ $fiscalYear }}" />
                <input type="hidden" name="reporting_unit_id" value="{{ $selectedUnit->id }}" />
                <input type="hidden" name="period_month" value="{{ $selectedMonth }}" />
                <input type="hidden" name="lock" value="{{ $state->lockToken }}" />

                <flux:input name="actual_revenue" label="Actual revenue (PHP)" inputmode="decimal"
                            value="{{ old('actual_revenue', $state->revenue !== null ? number_format($state->revenue, 2, '.', '') : '') }}"
                            required />
                @error('actual_revenue')<flux:error>{{ $message }}</flux:error>@enderror

                <flux:input name="actual_units" label="Actual units (optional — leave blank if not tracked)" inputmode="decimal"
                            value="{{ old('actual_units', $state->units !== null ? number_format($state->units, 2, '.', '') : '') }}" />
                @error('actual_units')<flux:error>{{ $message }}</flux:error>@enderror

                <flux:textarea name="reason" label="Reason for the change{{ $state->exists ? ' (required when changing a reported value)' : ' (optional)' }}"
                               rows="2">{{ old('reason') }}</flux:textarea>
                @error('reason')<flux:error>{{ $message }}</flux:error>@enderror
                @error('lock')<flux:callout icon="exclamation-triangle" variant="warning">{{ $message }}</flux:callout>@enderror

                <flux:button type="submit" variant="primary" icon="check">Save</flux:button>
            </form>
        @endif
    </div>
</x-layouts.app>
