<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fiscal year
    |--------------------------------------------------------------------------
    |
    | The calendar month a fiscal year STARTS on. 12 = December, so
    | "FY2026" runs 2025-12-01 → 2026-11-30 and its fiscal-month ordinals
    | are 1 = December … 12 = November. Consumed only by
    | App\Support\FiscalYear and App\Services\FiscalPerformanceService —
    | the CRM's own targets/PerformanceService are untouched by this.
    |
    */

    'fiscal_year_start_month' => (int) env('PERFORMANCE_FISCAL_YEAR_START_MONTH', 12),

    /*
    |--------------------------------------------------------------------------
    | Operational performance import
    |--------------------------------------------------------------------------
    |
    | Whether the plan/actuals importer rejects negative units/revenue
    | values (the documented default policy — a correction is made by
    | re-importing the corrected positive figure, which upserts).
    |
    */

    'import' => [
        'reject_negative_values' => true,
    ],

];
