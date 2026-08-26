<x-layouts.app>
    <div class="w-full">
        <flux:heading size="xl" level="1">Performance</flux:heading>
        <flux:subheading size="lg" class="mb-6">Verification view for the target/performance calculation engine — not the final dashboard</flux:subheading>

        <form method="GET" action="{{ route('performance.index') }}" class="mb-8 flex flex-wrap items-end gap-4">
            <flux:input name="period_start" label="Period start" type="date" value="{{ $periodStart->format('Y-m-d') }}" />
            <flux:input name="period_end" label="Period end" type="date" value="{{ $periodEnd->format('Y-m-d') }}" />
            <flux:button type="submit">Apply</flux:button>
        </form>

        @if ($organisation)
            <flux:heading size="lg" class="mb-2">Organisation</flux:heading>
            <div class="mb-8 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                @include('performance._snapshot', ['snapshot' => $organisation])
            </div>
        @endif

        @if (! empty($teams))
            <flux:heading size="lg" class="mb-2">Teams</flux:heading>
            <flux:table class="mb-8">
                <flux:table.columns>
                    <flux:table.column>Team</flux:table.column>
                    <flux:table.column>Target</flux:table.column>
                    <flux:table.column>Actual</flux:table.column>
                    <flux:table.column>Achievement</flux:table.column>
                    <flux:table.column>Gap</flux:table.column>
                    <flux:table.column>Pipeline</flux:table.column>
                    <flux:table.column>Coverage</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($teams as $row)
                        @php($snapshot = $row['snapshot'])
                        <flux:table.row>
                            <flux:table.cell>{{ $row['team']->name }}</flux:table.cell>
                            <flux:table.cell>{{ $snapshot->hasTarget ? $snapshot->currency.' '.number_format($snapshot->target, 2) : '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $snapshot->currency }} {{ number_format($snapshot->actual, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ $snapshot->achievementPercent !== null ? number_format($snapshot->achievementPercent, 1).'%' : '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $snapshot->hasTarget ? ($snapshot->gap < 0 ? '+' : '').number_format(abs($snapshot->gap), 2) : '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $snapshot->currency }} {{ number_format($snapshot->pipeline, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ $snapshot->pipelineCoverage !== null ? number_format($snapshot->pipelineCoverage, 2).'×' : '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        @if ($individual)
            <flux:heading size="lg" class="mb-2">My Performance</flux:heading>
            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                @include('performance._snapshot', ['snapshot' => $individual['snapshot']])
            </div>
        @endif
    </div>
</x-layouts.app>
