<?php

namespace App\Services;

use App\Models\PerformanceActualLine;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Support\FiscalYear;
use App\Support\Money;
use App\Support\Performance\FiscalPerformanceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single authoritative implementation of every OPERATIONAL fiscal
 * performance figure, computed only from performance_plan_lines +
 * performance_actual_lines. Entirely separate from PerformanceService
 * (which is CRM Closed-Won pipeline performance) — the two never mix and
 * neither touches the other's data.
 *
 * Fiscal year is Dec–Nov (config('performance.fiscal_year_start_month')).
 * period_month is the fiscal ordinal 1 = December … 12 = November.
 *
 * "YTD" always means "through the reporting month", i.e. fiscal months
 * 1..N where N = FiscalYear::monthsElapsedAsOf($asOf). Actual YTD sums
 * ONLY the actual lines that exist — a month with no imported actuals
 * contributes nothing and is NOT invented.
 *
 * NOT called "run rate" (PerformanceService's is day-based): the
 * remaining-effort figures here are explicitly per fiscal month —
 * requiredMonthlyRevenue / requiredMonthlyUnits.
 */
class FiscalPerformanceService
{
    /**
     * @param  Carbon|null  $asOf  explicit; defaults to now() only when the caller supplies nothing
     */
    public function forOrganisation(int $fiscalYear, ?Carbon $asOf = null, bool $withPriorYear = true): FiscalPerformanceSnapshot
    {
        return $this->build($fiscalYear, $asOf ?? Carbon::now(), null, null, 'organisation', null, $withPriorYear);
    }

    public function forTeam(Team $team, int $fiscalYear, ?Carbon $asOf = null, bool $withPriorYear = true): FiscalPerformanceSnapshot
    {
        return $this->build($fiscalYear, $asOf ?? Carbon::now(), $team, null, 'team', $team->name, $withPriorYear);
    }

    public function forReportingUnit(ReportingUnit $unit, int $fiscalYear, ?Carbon $asOf = null, bool $withPriorYear = true): FiscalPerformanceSnapshot
    {
        return $this->build($fiscalYear, $asOf ?? Carbon::now(), $unit->team, $unit, 'reporting_unit', $unit->name, $withPriorYear);
    }

    private function build(int $fiscalYear, Carbon $asOf, ?Team $team, ?ReportingUnit $unit, string $scopeType, ?string $scopeName, bool $withPriorYear): FiscalPerformanceSnapshot
    {
        $fy = FiscalYear::of($fiscalYear);
        $through = $fy->monthsElapsedAsOf($asOf);
        $teamId = $team?->id;
        $unitId = $unit?->id;

        $fyTarget = $this->sum(PerformancePlanLine::class, $fiscalYear, null, $teamId, $unitId, 'target_units', 'target_revenue');
        $ytdTarget = $this->sum(PerformancePlanLine::class, $fiscalYear, $through, $teamId, $unitId, 'target_units', 'target_revenue');
        $ytdActual = $this->sum(PerformanceActualLine::class, $fiscalYear, $through, $teamId, $unitId, 'actual_units', 'actual_revenue');

        $ytdRevenueVariance = round($ytdActual['revenue'] - $ytdTarget['revenue'], 2);
        $ytdUnitVariance = $this->unitVariance($ytdActual['units'], $ytdTarget['units']);

        $ytdTargetAttainment = $this->pct($ytdActual['revenue'], $ytdTarget['revenue']);
        $fyAttainmentToDate = $this->pct($ytdActual['revenue'], $fyTarget['revenue']);

        $remainingRevenue = max(round($fyTarget['revenue'] - $ytdActual['revenue'], 2), 0.0);
        $remainingUnits = $fyTarget['units'] !== null
            ? max(round($fyTarget['units'] - ($ytdActual['units'] ?? 0.0), 2), 0.0)
            : null;
        $remainingMonths = $fy->remainingMonthsAfter($through);

        $requiredMonthlyRevenue = $remainingMonths > 0 ? round($remainingRevenue / $remainingMonths, 2) : null;
        // Average fractional units still needed per remaining fiscal month —
        // NOT rounded up to a whole number (units are a weighted measure).
        $requiredMonthlyUnits = ($remainingMonths > 0 && $remainingUnits !== null)
            ? round($remainingUnits / $remainingMonths, 2)
            : null;

        [$lastActualMonth, $actualMonthsLoaded] = $this->actualCoverage($fiscalYear, $through, $teamId, $unitId);

        return new FiscalPerformanceSnapshot(
            fiscalYear: $fiscalYear,
            fiscalYearLabel: $fy->label(),
            asOf: $asOf->toDateString(),
            throughFiscalMonth: $through,
            scopeType: $scopeType,
            scopeName: $scopeName,
            currency: Money::defaultCurrency(),

            fyTargetUnits: $fyTarget['units'],
            fyTargetRevenue: $fyTarget['revenue'],
            ytdPhasedTargetUnits: $ytdTarget['units'],
            ytdPhasedTargetRevenue: $ytdTarget['revenue'],
            ytdActualUnits: $ytdActual['units'],
            ytdActualRevenue: $ytdActual['revenue'],

            ytdUnitVariance: $ytdUnitVariance,
            ytdRevenueVariance: $ytdRevenueVariance,
            ytdTargetAttainmentPct: $ytdTargetAttainment,
            fyAttainmentToDatePct: $fyAttainmentToDate,

            remainingFyUnitTarget: $remainingUnits,
            remainingFyRevenueTarget: $remainingRevenue,
            remainingFiscalMonths: $remainingMonths,
            requiredMonthlyUnits: $requiredMonthlyUnits,
            requiredMonthlyRevenue: $requiredMonthlyRevenue,

            lastActualPeriodMonth: $lastActualMonth,
            actualMonthsLoaded: $actualMonthsLoaded,
            actualsComplete: $through > 0 && $actualMonthsLoaded === $through,

            revenuePerUnitActual: $this->perUnit($ytdActual['revenue'], $ytdActual['units']),
            revenuePerUnitTarget: $this->perUnit($ytdTarget['revenue'], $ytdTarget['units']),

            monthlyTrend: $this->monthlyTrend($fy, $fiscalYear, $teamId, $unitId),
            teamTotals: $scopeType === 'organisation' ? $this->teamTotals($fiscalYear, $asOf, $through) : [],
            reportingUnitBreakdown: $unit === null ? $this->reportingUnitBreakdown($fiscalYear, $through, $teamId) : [],
            priorYear: $withPriorYear ? $this->priorYearIfAny($fy, $asOf, $team, $unit, $scopeType, $scopeName) : null,
        );
    }

    /**
     * @param  class-string<PerformancePlanLine|PerformanceActualLine>  $model
     * @return array{units: ?float, revenue: float}
     */
    private function sum(string $model, int $fiscalYear, ?int $throughMonth, ?int $teamId, ?int $unitId, string $unitsColumn, string $revenueColumn): array
    {
        $row = $model::query()
            ->where('fiscal_year', $fiscalYear)
            ->when($throughMonth !== null, fn ($q) => $q->where('period_month', '<=', $throughMonth))
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->when($unitId !== null, fn ($q) => $q->where('reporting_unit_id', $unitId))
            ->selectRaw("COALESCE(SUM({$revenueColumn}), 0) as revenue, SUM({$unitsColumn}) as units, COUNT({$unitsColumn}) as unit_rows")
            ->first();

        if ($throughMonth === 0) {
            return ['units' => 0.0, 'revenue' => 0.0];
        }

        return [
            // null only when NO row in scope carries a units value at all;
            // otherwise the fractional sum is preserved (rounded to 2 dp,
            // never cast to int).
            'units' => ($row->unit_rows ?? 0) > 0 ? round((float) $row->units, 2) : null,
            'revenue' => round((float) $row->revenue, 2),
        ];
    }

    /**
     * @return array{0: ?int, 1: int} [last fiscal month with an actual line, distinct months loaded within the horizon]
     */
    private function actualCoverage(int $fiscalYear, int $throughMonth, ?int $teamId, ?int $unitId): array
    {
        if ($throughMonth === 0) {
            return [null, 0];
        }

        $months = PerformanceActualLine::query()
            ->where('fiscal_year', $fiscalYear)
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->when($unitId !== null, fn ($q) => $q->where('reporting_unit_id', $unitId))
            ->distinct()
            ->pluck('period_month')
            ->sort()
            ->values();

        $withinHorizon = $months->filter(fn ($m) => $m <= $throughMonth);

        return [$months->max(), $withinHorizon->count()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthlyTrend(FiscalYear $fy, int $fiscalYear, ?int $teamId, ?int $unitId): array
    {
        $plan = $this->byMonth(PerformancePlanLine::class, $fiscalYear, $teamId, $unitId, 'target_units', 'target_revenue');
        $actual = $this->byMonth(PerformanceActualLine::class, $fiscalYear, $teamId, $unitId, 'actual_units', 'actual_revenue');

        return array_map(function (array $m) use ($plan, $actual) {
            $ordinal = $m['ordinal'];
            $a = $actual->get($ordinal);

            return [
                'ordinal' => $ordinal,
                'name' => $m['name'],
                'calendar_year' => $m['calendar_year'],
                'calendar_month' => $m['calendar_month'],
                'target_units' => $plan->get($ordinal)['units'] ?? null,
                'target_revenue' => round((float) ($plan->get($ordinal)['revenue'] ?? 0), 2),
                'actual_units' => $a['units'] ?? null,
                'actual_revenue' => $a !== null ? round((float) $a['revenue'], 2) : null,
                'has_actual' => $a !== null,
            ];
        }, $fy->months());
    }

    /**
     * @param  class-string<PerformancePlanLine|PerformanceActualLine>  $model
     * @return Collection<int, array{units: ?float, revenue: float}>
     */
    private function byMonth(string $model, int $fiscalYear, ?int $teamId, ?int $unitId, string $unitsColumn, string $revenueColumn): Collection
    {
        return $model::query()
            ->where('fiscal_year', $fiscalYear)
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->when($unitId !== null, fn ($q) => $q->where('reporting_unit_id', $unitId))
            ->groupBy('period_month')
            ->selectRaw("period_month, COALESCE(SUM({$revenueColumn}),0) as revenue, SUM({$unitsColumn}) as units, COUNT({$unitsColumn}) as unit_rows")
            ->get()
            ->keyBy('period_month')
            ->map(fn ($r) => ['units' => $r->unit_rows > 0 ? round((float) $r->units, 2) : null, 'revenue' => (float) $r->revenue]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function teamTotals(int $fiscalYear, Carbon $asOf, int $throughMonth): array
    {
        return Team::query()->orderBy('name')->get()->map(function (Team $team) use ($fiscalYear, $throughMonth) {
            $fyT = $this->sum(PerformancePlanLine::class, $fiscalYear, null, $team->id, null, 'target_units', 'target_revenue');
            $ytdT = $this->sum(PerformancePlanLine::class, $fiscalYear, $throughMonth, $team->id, null, 'target_units', 'target_revenue');
            $ytdA = $this->sum(PerformanceActualLine::class, $fiscalYear, $throughMonth, $team->id, null, 'actual_units', 'actual_revenue');

            return [
                'team_id' => $team->id,
                'team_name' => $team->name,
                'fy_target_revenue' => $fyT['revenue'],
                'ytd_phased_target_revenue' => $ytdT['revenue'],
                'ytd_actual_revenue' => $ytdA['revenue'],
                'ytd_revenue_variance' => round($ytdA['revenue'] - $ytdT['revenue'], 2),
                'ytd_target_attainment_pct' => $this->pct($ytdA['revenue'], $ytdT['revenue']),
                'fy_attainment_to_date_pct' => $this->pct($ytdA['revenue'], $fyT['revenue']),
            ];
        })
            ->filter(fn ($t) => $t['fy_target_revenue'] > 0 || $t['ytd_actual_revenue'] > 0)
            ->sortBy('ytd_revenue_variance') // most-behind first
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportingUnitBreakdown(int $fiscalYear, int $throughMonth, ?int $teamId): array
    {
        return ReportingUnit::query()
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->with('team:id,name')
            ->orderBy('name')
            ->get()
            ->map(function (ReportingUnit $unit) use ($fiscalYear, $throughMonth) {
                $ytdT = $this->sum(PerformancePlanLine::class, $fiscalYear, $throughMonth, $unit->team_id, $unit->id, 'target_units', 'target_revenue');
                $ytdA = $this->sum(PerformanceActualLine::class, $fiscalYear, $throughMonth, $unit->team_id, $unit->id, 'actual_units', 'actual_revenue');
                $fyT = $this->sum(PerformancePlanLine::class, $fiscalYear, null, $unit->team_id, $unit->id, 'target_units', 'target_revenue');

                return [
                    'reporting_unit_id' => $unit->id,
                    'reporting_unit_code' => $unit->code,
                    'reporting_unit_name' => $unit->name,
                    'team_id' => $unit->team_id,
                    'team_name' => $unit->team?->name,
                    'fy_target_revenue' => $fyT['revenue'],
                    'ytd_phased_target_revenue' => $ytdT['revenue'],
                    'ytd_actual_revenue' => $ytdA['revenue'],
                    'ytd_revenue_variance' => round($ytdA['revenue'] - $ytdT['revenue'], 2),
                    'ytd_target_attainment_pct' => $this->pct($ytdA['revenue'], $ytdT['revenue']),
                    'below_phased_target' => $ytdA['revenue'] < $ytdT['revenue'],
                ];
            })
            ->filter(fn ($u) => $u['fy_target_revenue'] > 0 || $u['ytd_actual_revenue'] > 0)
            ->sortBy('ytd_revenue_variance')
            ->values()
            ->all();
    }

    private function priorYearIfAny(FiscalYear $fy, Carbon $asOf, ?Team $team, ?ReportingUnit $unit, string $scopeType, ?string $scopeName): ?FiscalPerformanceSnapshot
    {
        $prior = $fy->previous();

        $hasData = PerformancePlanLine::where('fiscal_year', $prior->year)->exists()
            || PerformanceActualLine::where('fiscal_year', $prior->year)->exists();

        if (! $hasData) {
            return null; // never fabricate prior-year data
        }

        // Compare like-for-like: the same fiscal-month horizon a year earlier.
        $priorAsOf = $prior->ordinalEnd(max($fy->monthsElapsedAsOf($asOf), 1));

        return $this->build($prior->year, $priorAsOf, $team, $unit, $scopeType, $scopeName, withPriorYear: false);
    }

    private function pct(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : null;
    }

    private function perUnit(float $revenue, ?float $units): ?float
    {
        return ($units !== null && $units > 0) ? round($revenue / $units, 2) : null;
    }

    /**
     * Fractional-safe unit variance (actual − target). null only when
     * neither side carries any units figure at all.
     */
    private function unitVariance(?float $actual, ?float $target): ?float
    {
        if ($actual === null && $target === null) {
            return null;
        }

        return round(($actual ?? 0.0) - ($target ?? 0.0), 2);
    }
}
