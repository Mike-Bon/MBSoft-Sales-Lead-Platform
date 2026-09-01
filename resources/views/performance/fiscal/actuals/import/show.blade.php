@php
    use App\Support\Money;
    $fmt = fn ($v) => $v === null ? '—' : Money::format((float) $v, 'PHP', 2);
    $units = fn ($v) => $v === null ? 'not reported' : number_format((float) $v, 2);
@endphp
<x-layouts.app>
    <div class="w-full">
        <div class="mb-1 flex items-center gap-2 text-sm text-zinc-500">
            <a class="underline" href="{{ route('performance.fiscal.actuals.index') }}" wire:navigate>Manage Actuals</a>
            <span>/</span><span>Review import</span>
        </div>
        <flux:heading size="xl" level="1">Review before importing</flux:heading>
        <flux:subheading size="lg" class="mb-4">File: <span class="font-mono text-sm">{{ $import->original_filename }}</span></flux:subheading>

        @if (session('import_error'))
            <flux:callout icon="exclamation-triangle" variant="danger" class="mb-4">{{ session('import_error') }}</flux:callout>
        @endif

        @if (! empty($validationErrors))
            <flux:callout icon="x-circle" variant="danger" class="mb-4">
                <strong>This file was not accepted — {{ count($validationErrors) }} problem(s). Nothing was saved.</strong>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($validationErrors as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </flux:callout>
            <flux:button icon="arrow-uturn-left" :href="route('performance.fiscal.actuals.import.create')" wire:navigate>Fix the file and upload again</flux:button>
        @elseif ($expired)
            <flux:callout icon="clock" variant="warning" class="mb-4">This preview has expired. Please upload the file again.</flux:callout>
            <flux:button :href="route('performance.fiscal.actuals.import.create')" wire:navigate>Upload again</flux:button>
        @else
            <flux:callout icon="information-circle" variant="secondary" class="mb-4">
                <strong>{{ $stats['created'] ?? 0 }} new</strong> value(s),
                <strong>{{ $stats['updated'] ?? 0 }} change(s)</strong> to existing reported value(s)@if (($stats['unchanged'] ?? 0) > 0), {{ $stats['unchanged'] }} unchanged @endif.
                Existing values are kept in the change history. Nothing is saved until you confirm below.
            </flux:callout>

            <flux:table class="mb-6">
                <flux:table.columns>
                    <flux:table.column>Reporting unit</flux:table.column>
                    <flux:table.column>Team</flux:table.column>
                    <flux:table.column>Fiscal month</flux:table.column>
                    <flux:table.column>Revenue (current → new)</flux:table.column>
                    <flux:table.column>Units (current → new)</flux:table.column>
                    <flux:table.column>Effect</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($rows as $r)
                        <flux:table.row>
                            <flux:table.cell>{{ $r['unit_name'] }}</flux:table.cell>
                            <flux:table.cell>{{ $r['team_name'] }}</flux:table.cell>
                            <flux:table.cell>{{ $r['month_name'] }} {{ $r['calendar_year'] }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($r['change'] === 'created')
                                    {{ $fmt($r['revenue']) }}
                                @elseif ($r['change'] === 'updated')
                                    <span class="text-zinc-400 line-through">{{ $fmt($r['current_revenue']) }}</span> → <strong>{{ $fmt($r['revenue']) }}</strong>
                                @else
                                    {{ $fmt($r['revenue']) }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($r['change'] === 'updated')
                                    <span class="text-zinc-400 line-through">{{ $units($r['current_units']) }}</span> → {{ $units($r['units']) }}
                                @else
                                    {{ $units($r['units']) }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($r['change'] === 'created')<flux:badge color="green" size="sm">CREATE</flux:badge>
                                @elseif ($r['change'] === 'updated')<flux:badge color="amber" size="sm">UPDATE</flux:badge>
                                @else<flux:badge color="zinc" size="sm">no change</flux:badge>@endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="flex gap-3">
                <form method="POST" action="{{ route('performance.fiscal.actuals.import.confirm', $import) }}">
                    @csrf
                    <input type="hidden" name="fingerprint" value="{{ $import->preview_fingerprint }}" />
                    <flux:button type="submit" variant="primary" icon="check">Confirm &amp; import {{ ($stats['created'] ?? 0) + ($stats['updated'] ?? 0) }} value(s)</flux:button>
                </form>
                <form method="POST" action="{{ route('performance.fiscal.actuals.import.cancel', $import) }}">
                    @csrf
                    <flux:button type="submit" variant="ghost">Discard</flux:button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
