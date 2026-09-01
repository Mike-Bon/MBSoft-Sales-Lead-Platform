<x-layouts.app>
    <div class="w-full">
        <div class="mb-1 flex items-center gap-2 text-sm text-zinc-500">
            <a class="underline" href="{{ route('performance.fiscal.actuals.index') }}" wire:navigate>Manage Actuals</a>
            <span>/</span><span>Change history</span>
        </div>
        <flux:heading size="xl" level="1">Actuals change history</flux:heading>
        <flux:subheading size="lg" class="mb-4">Every create and correction of an operational actual — previous value, new value, who and when.</flux:subheading>

        <form method="GET" class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <flux:select name="fiscal_year" label="Fiscal year" onchange="this.form.submit()">
                <flux:select.option value="">All</flux:select.option>
                @foreach ($fiscalYears as $fy)
                    <flux:select.option value="{{ $fy }}" :selected="$fy === $selectedFiscalYear">FY{{ $fy }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select name="reporting_unit_id" label="Reporting unit" onchange="this.form.submit()">
                <flux:select.option value="">All</flux:select.option>
                @foreach ($reportingUnits as $u)
                    <flux:select.option value="{{ $u->id }}" :selected="$u->id === $selectedUnitId">{{ $u->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </form>

        @include('performance.fiscal.actuals._revisions', ['revisions' => $revisions, 'paginated' => true])
    </div>
</x-layouts.app>
