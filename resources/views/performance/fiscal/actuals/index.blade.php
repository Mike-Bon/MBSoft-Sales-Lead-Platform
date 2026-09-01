@php use App\Support\FiscalYear; @endphp
<x-layouts.app>
    <div class="w-full">
        <div class="mb-1 flex items-center gap-2 text-sm text-zinc-500">
            <a class="underline" href="{{ route('performance.fiscal.index') }}" wire:navigate>Fiscal Year Performance</a>
            <span>/</span><span>Manage Actuals</span>
        </div>
        <flux:heading size="xl" level="1">Manage Operational Actuals</flux:heading>
        <flux:subheading size="lg" class="mb-4">Import or correct the monthly operational revenue &amp; units behind the Fiscal Year Performance dashboard.</flux:subheading>

        @if (session('status'))
            <flux:callout icon="check-circle" variant="success" class="mb-4">{{ session('status') }}</flux:callout>
        @endif

        <div class="mb-6 flex flex-wrap gap-3">
            <flux:button icon="arrow-up-tray" variant="primary" :href="route('performance.fiscal.actuals.import.create')" wire:navigate>Import actuals (CSV)</flux:button>
            <flux:button icon="pencil-square" :href="route('performance.fiscal.actuals.entry.create')" wire:navigate>Enter / correct one value</flux:button>
            <flux:button icon="clock" variant="ghost" :href="route('performance.fiscal.actuals.history')" wire:navigate>Full change history</flux:button>
        </div>

        <form method="GET" class="mb-4 max-w-xs">
            <flux:select name="fiscal_year" label="Fiscal year" onchange="this.form.submit()">
                @foreach ($fiscalYears as $fy)
                    <flux:select.option value="{{ $fy }}" :selected="$fy === $fiscalYear">FY{{ $fy }} (Dec {{ $fy - 1 }} – Nov {{ $fy }})</flux:select.option>
                @endforeach
            </flux:select>
        </form>

        <flux:heading size="lg" class="mb-2">Reporting coverage — FY{{ $fiscalYear }}</flux:heading>
        <p class="mb-3 text-sm text-zinc-500">How many of the {{ $activeUnits }} active reporting units have a reported actual for each fiscal month. A blank month is "not reported", never zero.</p>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Fiscal month</flux:table.column>
                <flux:table.column>Units reported</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($coverage as $m)
                    <flux:table.row>
                        <flux:table.cell>{{ $m['ordinal'] }}. {{ $m['name'] }} {{ $m['calendar_year'] }}</flux:table.cell>
                        <flux:table.cell>{{ $m['units_reported'] }} / {{ $m['active_units'] }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($m['units_reported'] === 0)
                                <flux:badge color="zinc" size="sm">Not started</flux:badge>
                            @elseif ($m['units_reported'] < $m['active_units'])
                                <flux:badge color="amber" size="sm">Partial</flux:badge>
                            @else
                                <flux:badge color="green" size="sm">Complete</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <a class="text-sm underline" href="{{ route('performance.fiscal.actuals.import.create', ['fiscal_year' => $fiscalYear, 'period_month' => $m['ordinal']]) }}" wire:navigate>Import this month</a>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:heading size="lg" class="mb-2">Recent changes</flux:heading>
        @include('performance.fiscal.actuals._revisions', ['revisions' => $recentChanges, 'paginated' => false])
    </div>
</x-layouts.app>
