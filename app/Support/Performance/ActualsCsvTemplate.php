<?php

namespace App\Support\Performance;

use App\Models\ReportingUnit;
use App\Support\FiscalYear;
use Illuminate\Support\Carbon;

/**
 * Builds the MONTH-SCOPED "Import Actuals" CSV template: one pre-filled
 * row per ACTIVE reporting unit for a single fiscal year + fiscal month.
 *
 * The Manager normally fills only `actual_revenue` (and optionally
 * `actual_units`); the reporting-unit code, team and month context are
 * generated from the live catalog so nobody types a branch code. Rows
 * left blank (both value cells empty) are treated as "not reported" and
 * skipped by the importer.
 *
 * The first six columns ARE the importer contract
 * (App\Services\Performance\PerformanceImportService ACTUAL_COLUMNS). The
 * trailing `team_name` / `reporting_unit_name` / `calendar_month` columns
 * are human helpers and are ignored by the importer.
 */
final class ActualsCsvTemplate
{
    public const HEADER = [
        'fiscal_year', 'period_month', 'team_code', 'reporting_unit_code',
        'actual_units', 'actual_revenue',
        'team_name', 'reporting_unit_name', 'calendar_month',
    ];

    public static function filename(int $fiscalYear, int $periodMonth): string
    {
        $fy = FiscalYear::of($fiscalYear);
        $calendar = strtolower($fy->ordinalName($periodMonth));

        return "fy{$fiscalYear}-actuals-{$periodMonth}-{$calendar}-template.csv";
    }

    public static function build(int $fiscalYear, int $periodMonth): string
    {
        $fy = FiscalYear::of($fiscalYear);
        $c = $fy->calendarForOrdinal($periodMonth);
        $calendarLabel = Carbon::create($c['year'], $c['month'], 1)->format('F Y');

        $units = ReportingUnit::query()
            ->active()
            ->with('team:id,name,code')
            ->orderBy('team_id')
            ->orderByRaw('COALESCE(sort_order, 2147483647)')
            ->orderBy('name')
            ->get()
            ->filter(fn (ReportingUnit $u) => $u->team?->code !== null && $u->team->code !== '');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::HEADER);

        foreach ($units as $unit) {
            fputcsv($out, [
                $fiscalYear,
                $periodMonth,
                $unit->team->code,
                $unit->code,
                '',                       // actual_units  — fill if reported
                '',                       // actual_revenue — fill if reported
                $unit->team->name,
                $unit->name,
                $calendarLabel,
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
