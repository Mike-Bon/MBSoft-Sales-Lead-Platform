@php use App\Support\FiscalYear; @endphp
<x-layouts.app>
    <div class="w-full max-w-3xl">
        <div class="mb-1 flex items-center gap-2 text-sm text-zinc-500">
            <a class="underline" href="{{ route('performance.fiscal.actuals.index') }}" wire:navigate>Manage Actuals</a>
            <span>/</span><span>Import actuals</span>
        </div>
        <flux:heading size="xl" level="1">Import actuals (CSV)</flux:heading>
        <flux:subheading size="lg" class="mb-4">Download a template for one fiscal month, fill in the revenue (and units) for the branches that reported, then upload it for review.</flux:subheading>

        @if (session('import_error'))
            <flux:callout icon="exclamation-triangle" variant="danger" class="mb-4">{{ session('import_error') }}</flux:callout>
        @endif
        @error('file')<flux:callout icon="exclamation-triangle" variant="danger" class="mb-4">{{ $message }}</flux:callout>@enderror

        <flux:heading size="lg" class="mb-2">1. Download the template</flux:heading>
        <form method="GET" action="{{ route('performance.fiscal.actuals.template') }}" class="mb-8 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
            <flux:select name="fiscal_year" label="Fiscal year">
                @foreach ($fiscalYears as $fy)
                    <flux:select.option value="{{ $fy }}" :selected="$fy === (int) old('fiscal_year', $defaultFiscalYear)">FY{{ $fy }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select name="period_month" label="Fiscal month">
                @foreach ($fiscalMonths as $m)
                    <flux:select.option value="{{ $m['ordinal'] }}" :selected="$m['ordinal'] === (int) request('period_month')">{{ $m['ordinal'] }}. {{ $m['name'] }} {{ $m['calendar_year'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" icon="arrow-down-tray">Download template</flux:button>
        </form>
        <p class="mb-8 -mt-6 text-xs text-zinc-500">The template lists every active reporting unit for that month with the codes pre-filled. Fill only <code>actual_revenue</code> (and <code>actual_units</code> if you track them). Leave a branch blank if it has not reported — blank is never treated as zero.</p>

        <flux:heading size="lg" class="mb-2">2. Upload the completed file</flux:heading>
        <form method="POST" action="{{ route('performance.fiscal.actuals.import.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="file" accept=".csv,text/csv" required
                   class="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm dark:file:bg-zinc-700" />
            <flux:button type="submit" variant="primary" icon="arrow-up-tray">Upload &amp; review</flux:button>
            <p class="text-xs text-zinc-500">Nothing is saved on upload — you will see a full preview of what will be created or updated and must confirm it.</p>
        </form>
    </div>
</x-layouts.app>
