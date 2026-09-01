@php
    use App\Support\Money;
    $s = $snapshot;
    $money0 = fn ($v) => Money::format((float) $v, $s->currency, 0);
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1).'%';
    $signed = fn ($v) => ($v < 0 ? '' : '+').$money0($v);
@endphp
<x-layouts.app>
    <div class="w-full">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="xl" level="1">Fiscal Year Performance</flux:heading>
                <flux:subheading size="lg" class="mb-4">Operational revenue &amp; units vs the corporate budget workbook</flux:subheading>
            </div>
            @if (auth()->user()->isManager())
                <flux:button size="sm" icon="adjustments-horizontal" :href="route('performance.fiscal.actuals.index')" wire:navigate>Manage Actuals</flux:button>
            @endif
        </div>

        <flux:callout icon="information-circle" variant="secondary" class="mb-6">
            <strong>Operational Performance</strong> — monthly phased targets vs monthly actuals imported from the corporate
            budget workbook. This is <em>not</em> the CRM Closed-Won / sales-pipeline figure on the
            <a class="underline" href="{{ route('performance.index') }}" wire:navigate>Performance</a> screen; the two are
            separate sources of truth and are never mixed.
        </flux:callout>

        <form method="GET" action="{{ route('performance.fiscal.index') }}" class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-4">
            <flux:select name="fiscal_year" label="Fiscal year" onchange="this.form.submit()">
                @foreach ($fiscalYears as $fy)
                    <flux:select.option value="{{ $fy }}" :selected="$fy === $fiscalYear">FY{{ $fy }} (Dec {{ $fy - 1 }} – Nov {{ $fy }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" name="as_of" label="As of" value="{{ $asOf->toDateString() }}" onchange="this.form.submit()" />
            <flux:select name="team_id" label="Team" onchange="this.form.submit()">
                <flux:select.option value="">All teams</flux:select.option>
                @foreach ($teams as $team)
                    <flux:select.option value="{{ $team->id }}" :selected="$team->id === $selectedTeamId">{{ $team->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select name="reporting_unit_id" label="Reporting unit" onchange="this.form.submit()" :disabled="$reportingUnits->isEmpty()">
                <flux:select.option value="">All units</flux:select.option>
                @foreach ($reportingUnits as $unit)
                    <flux:select.option value="{{ $unit->id }}" :selected="$unit->id === $selectedUnitId">{{ $unit->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </form>

        <div class="mb-2 text-sm text-zinc-500 dark:text-zinc-400">
            {{ $s->fiscalYearLabel }} · {{ ucfirst(str_replace('_', ' ', $s->scopeType)) }}{{ $s->scopeName ? ' — '.$s->scopeName : '' }} ·
            through fiscal month {{ $s->throughFiscalMonth }} of 12 (as of {{ $asOf->toDateString() }})
            @if (! $s->actualsComplete && $s->throughFiscalMonth > 0)
                · <span class="text-amber-600 dark:text-amber-400">actuals loaded for {{ $s->actualMonthsLoaded }} of {{ $s->throughFiscalMonth }} expected months</span>
            @endif
        </div>

        <div class="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-performance.kpi label="Full FY target" :value="$money0($s->fyTargetRevenue)" />
            <x-performance.kpi label="YTD phased target" :value="$money0($s->ytdPhasedTargetRevenue)" />
            <x-performance.kpi label="YTD actual" :value="$money0($s->ytdActualRevenue)" />
            <x-performance.kpi label="YTD revenue variance" :value="$signed($s->ytdRevenueVariance)" />
            <x-performance.kpi label="YTD Target Attainment" :value="$pct($s->ytdTargetAttainmentPct)" />
            <x-performance.kpi label="FY Attainment to Date" :value="$pct($s->fyAttainmentToDatePct)" />
            <x-performance.kpi label="Remaining FY target" :value="$money0($s->remainingFyRevenueTarget)" />
            <x-performance.kpi
                label="Required / remaining month"
                :value="$s->requiredMonthlyRevenue === null ? 'N/A' : $money0($s->requiredMonthlyRevenue)"
            />
        </div>

        <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-performance.kpi label="FY target units" :value="$s->fyTargetUnits === null ? '—' : number_format($s->fyTargetUnits, 2)" />
            <x-performance.kpi label="YTD actual units" :value="$s->ytdActualUnits === null ? '—' : number_format($s->ytdActualUnits, 2)" />
            <x-performance.kpi label="YTD unit variance" :value="$s->ytdUnitVariance === null ? '—' : ($s->ytdUnitVariance < 0 ? '' : '+').number_format($s->ytdUnitVariance, 2)" />
            <x-performance.kpi label="Revenue / unit (actual)" :value="$s->revenuePerUnitActual === null ? '—' : $money0($s->revenuePerUnitActual)" />
        </div>

        <p class="mb-6 text-xs text-zinc-500 dark:text-zinc-400">
            <strong>YTD Target Attainment</strong> = YTD actual revenue ÷ the phased target for the same fiscal months.
            <strong>FY Attainment to Date</strong> = YTD actual revenue ÷ the full-year target. They answer different questions
            and are never labelled the same.
        </p>

        @if (! empty($s->teamTotals))
            <flux:heading size="lg" class="mb-2">Team breakdown <span class="text-sm font-normal text-zinc-500">(most behind first)</span></flux:heading>
            <flux:table class="mb-8">
                <flux:table.columns>
                    <flux:table.column>Team</flux:table.column>
                    <flux:table.column>FY target</flux:table.column>
                    <flux:table.column>YTD phased target</flux:table.column>
                    <flux:table.column>YTD actual</flux:table.column>
                    <flux:table.column>YTD variance</flux:table.column>
                    <flux:table.column>YTD Target Attain.</flux:table.column>
                    <flux:table.column>FY Attain. to Date</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($s->teamTotals as $t)
                        <flux:table.row>
                            <flux:table.cell>
                                <a class="underline" href="{{ route('performance.fiscal.index', ['fiscal_year' => $fiscalYear, 'as_of' => $asOf->toDateString(), 'team_id' => $t['team_id']]) }}" wire:navigate>{{ $t['team_name'] }}</a>
                            </flux:table.cell>
                            <flux:table.cell>{{ $money0($t['fy_target_revenue']) }}</flux:table.cell>
                            <flux:table.cell>{{ $money0($t['ytd_phased_target_revenue']) }}</flux:table.cell>
                            <flux:table.cell>{{ $money0($t['ytd_actual_revenue']) }}</flux:table.cell>
                            <flux:table.cell @class(['text-red-600 dark:text-red-400' => $t['ytd_revenue_variance'] < 0])>{{ $signed($t['ytd_revenue_variance']) }}</flux:table.cell>
                            <flux:table.cell>{{ $pct($t['ytd_target_attainment_pct']) }}</flux:table.cell>
                            <flux:table.cell>{{ $pct($t['fy_attainment_to_date_pct']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        @if (! empty($s->reportingUnitBreakdown))
            <flux:heading size="lg" class="mb-2">Reporting-unit breakdown <span class="text-sm font-normal text-zinc-500">(most behind first)</span></flux:heading>
            <flux:table class="mb-8">
                <flux:table.columns>
                    <flux:table.column>Reporting unit</flux:table.column>
                    @if ($s->scopeType === 'organisation')<flux:table.column>Team</flux:table.column>@endif
                    <flux:table.column>FY target</flux:table.column>
                    <flux:table.column>YTD phased target</flux:table.column>
                    <flux:table.column>YTD actual</flux:table.column>
                    <flux:table.column>YTD variance</flux:table.column>
                    <flux:table.column>YTD Target Attain.</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($s->reportingUnitBreakdown as $u)
                        <flux:table.row>
                            <flux:table.cell>{{ $u['reporting_unit_name'] }} <span class="text-xs text-zinc-400">{{ $u['reporting_unit_code'] }}</span></flux:table.cell>
                            @if ($s->scopeType === 'organisation')<flux:table.cell>{{ $u['team_name'] }}</flux:table.cell>@endif
                            <flux:table.cell>{{ $money0($u['fy_target_revenue']) }}</flux:table.cell>
                            <flux:table.cell>{{ $money0($u['ytd_phased_target_revenue']) }}</flux:table.cell>
                            <flux:table.cell>{{ $money0($u['ytd_actual_revenue']) }}</flux:table.cell>
                            <flux:table.cell @class(['text-red-600 dark:text-red-400' => $u['below_phased_target']])>{{ $signed($u['ytd_revenue_variance']) }}</flux:table.cell>
                            <flux:table.cell>{{ $pct($u['ytd_target_attainment_pct']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        <flux:heading size="lg" class="mb-2">Monthly plan vs actual</flux:heading>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Fiscal month</flux:table.column>
                <flux:table.column>Target revenue</flux:table.column>
                <flux:table.column>Actual revenue</flux:table.column>
                <flux:table.column>Variance</flux:table.column>
                <flux:table.column>Target units</flux:table.column>
                <flux:table.column>Actual units</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($s->monthlyTrend as $m)
                    <flux:table.row>
                        <flux:table.cell>{{ $m['ordinal'] }}. {{ $m['name'] }} {{ $m['calendar_year'] }}</flux:table.cell>
                        <flux:table.cell>{{ $money0($m['target_revenue']) }}</flux:table.cell>
                        <flux:table.cell>{{ $m['has_actual'] ? $money0($m['actual_revenue']) : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $m['has_actual'] ? $signed($m['actual_revenue'] - $m['target_revenue']) : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $m['target_units'] === null ? '—' : number_format($m['target_units'], 2) }}</flux:table.cell>
                        <flux:table.cell>{{ $m['has_actual'] && $m['actual_units'] !== null ? number_format($m['actual_units'], 2) : '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if ($s->priorYear)
            <flux:heading size="lg" class="mb-2">Vs {{ $s->priorYear->fiscalYearLabel }} (same fiscal-month horizon)</flux:heading>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <x-performance.kpi label="{{ $s->priorYear->fiscalYearLabel }} YTD actual" :value="$money0($s->priorYear->ytdActualRevenue)" />
                <x-performance.kpi label="{{ $s->fiscalYearLabel }} YTD actual" :value="$money0($s->ytdActualRevenue)" />
                <x-performance.kpi
                    label="YoY change"
                    :value="$signed($s->ytdActualRevenue - $s->priorYear->ytdActualRevenue)"
                />
            </div>
        @endif
    </div>
</x-layouts.app>
