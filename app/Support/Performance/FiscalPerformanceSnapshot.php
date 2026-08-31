<?php

namespace App\Support\Performance;

/**
 * The complete operational-performance picture for one scope
 * (organisation / team / reporting unit) of one fiscal year, as of an
 * explicit date. Every figure is computed by FiscalPerformanceService
 * from performance_plan_lines + performance_actual_lines — never from
 * CRM opportunities. The LLM only ever explains these numbers.
 *
 * TWO distinct attainment metrics are always carried, never conflated:
 *   - ytdTargetAttainmentPct  = YTD actual revenue / YTD PHASED target
 *                               revenue (through the reporting month)
 *   - fyAttainmentToDatePct   = YTD actual revenue / the FULL FY target
 *                               revenue
 */
final readonly class FiscalPerformanceSnapshot
{
    /**
     * @param  list<array<string, mixed>>  $monthlyTrend
     * @param  list<array<string, mixed>>  $teamTotals
     * @param  list<array<string, mixed>>  $reportingUnitBreakdown
     */
    public function __construct(
        public int $fiscalYear,
        public string $fiscalYearLabel,
        public string $asOf,
        public int $throughFiscalMonth,
        public string $scopeType,          // organisation | team | reporting_unit
        public ?string $scopeName,
        public string $currency,

        // "units" figures are decimal — the corporate budget treats units
        // as a weighted / fractional business measure, never an int count.
        public ?float $fyTargetUnits,
        public float $fyTargetRevenue,
        public ?float $ytdPhasedTargetUnits,
        public float $ytdPhasedTargetRevenue,
        public ?float $ytdActualUnits,
        public float $ytdActualRevenue,

        public ?float $ytdUnitVariance,
        public float $ytdRevenueVariance,
        public ?float $ytdTargetAttainmentPct,
        public ?float $fyAttainmentToDatePct,

        public ?float $remainingFyUnitTarget,
        public float $remainingFyRevenueTarget,
        public int $remainingFiscalMonths,
        public ?float $requiredMonthlyUnits,
        public ?float $requiredMonthlyRevenue,

        public ?int $lastActualPeriodMonth,
        public int $actualMonthsLoaded,
        public bool $actualsComplete,

        public ?float $revenuePerUnitActual,
        public ?float $revenuePerUnitTarget,

        public array $monthlyTrend,
        public array $teamTotals,
        public array $reportingUnitBreakdown,
        public ?self $priorYear,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fiscal_year' => $this->fiscalYear,
            'fiscal_year_label' => $this->fiscalYearLabel,
            'as_of' => $this->asOf,
            'through_fiscal_month' => $this->throughFiscalMonth,
            'scope_type' => $this->scopeType,
            'scope_name' => $this->scopeName,
            'currency' => $this->currency,

            'fy_target_units' => $this->fyTargetUnits,
            'fy_target_revenue' => $this->fyTargetRevenue,
            'ytd_phased_target_units' => $this->ytdPhasedTargetUnits,
            'ytd_phased_target_revenue' => $this->ytdPhasedTargetRevenue,
            'ytd_actual_units' => $this->ytdActualUnits,
            'ytd_actual_revenue' => $this->ytdActualRevenue,

            'ytd_unit_variance' => $this->ytdUnitVariance,
            'ytd_revenue_variance' => $this->ytdRevenueVariance,
            'ytd_target_attainment_pct' => $this->ytdTargetAttainmentPct,
            'fy_attainment_to_date_pct' => $this->fyAttainmentToDatePct,

            'remaining_fy_unit_target' => $this->remainingFyUnitTarget,
            'remaining_fy_revenue_target' => $this->remainingFyRevenueTarget,
            'remaining_fiscal_months' => $this->remainingFiscalMonths,
            'required_monthly_units' => $this->requiredMonthlyUnits,
            'required_monthly_revenue' => $this->requiredMonthlyRevenue,

            'last_actual_period_month' => $this->lastActualPeriodMonth,
            'actual_months_loaded' => $this->actualMonthsLoaded,
            'actuals_complete' => $this->actualsComplete,

            'revenue_per_unit_actual' => $this->revenuePerUnitActual,
            'revenue_per_unit_target' => $this->revenuePerUnitTarget,

            'monthly_trend' => $this->monthlyTrend,
            'team_totals' => $this->teamTotals,
            'reporting_unit_breakdown' => $this->reportingUnitBreakdown,
            'prior_year' => $this->priorYear?->toArray(),
        ];
    }
}
