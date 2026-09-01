<?php

namespace App\Enums;

/**
 * How an operational-performance write reached the system. CSV import
 * (the CLI `performance:import-*` commands and the web "Import Actuals"
 * flow) and a manual single-value correction are deliberately DIFFERENT
 * input channels — they share the numeric rules, reporting-unit
 * resolution and the authoritative actual-line writer, but a manual
 * correction is never modelled as a one-row CSV.
 */
enum PerformanceImportChannel: string
{
    case CsvImport = 'csv_import';
    case ManualEntry = 'manual_entry';

    public function label(): string
    {
        return match ($this) {
            self::CsvImport => 'CSV import',
            self::ManualEntry => 'Manual entry',
        };
    }
}
