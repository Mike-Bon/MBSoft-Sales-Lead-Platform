<?php

namespace App\Support\Performance;

use App\Models\PerformanceActualLine;
use App\Models\PerformanceActualLineRevision;

/**
 * A read-only snapshot of one reporting-unit/fiscal-month actual, for the
 * manual entry/correction form: the current value (if any), an optimistic
 * lock token binding the form to that exact state, and the last change.
 */
final readonly class ManualEntryState
{
    public function __construct(
        public bool $exists,
        public ?float $revenue,
        public ?float $units,
        public string $lockToken,
        public ?PerformanceActualLineRevision $lastRevision,
    ) {}

    public static function for(int $fiscalYear, int $reportingUnitId, int $periodMonth, ?PerformanceActualLine $line): self
    {
        $revenue = $line !== null ? (float) $line->actual_revenue : null;
        $units = $line?->actual_units === null ? null : (float) $line->actual_units;

        return new self(
            exists: $line !== null,
            revenue: $revenue,
            units: $units,
            lockToken: self::token($fiscalYear, $reportingUnitId, $periodMonth, $revenue, $units, $line?->updated_at?->toIso8601String()),
            lastRevision: $line === null ? null : PerformanceActualLineRevision::query()
                ->where('performance_actual_line_id', $line->id)
                ->latest('id')
                ->with('changedBy:id,name')
                ->first(),
        );
    }

    public static function token(int $fiscalYear, int $reportingUnitId, int $periodMonth, ?float $revenue, ?float $units, ?string $updatedAt): string
    {
        return hash('sha256', implode('|', [
            $fiscalYear,
            $reportingUnitId,
            $periodMonth,
            $revenue === null ? 'null' : number_format($revenue, 2, '.', ''),
            $units === null ? 'null' : number_format($units, 2, '.', ''),
            $updatedAt ?? 'none',
        ]));
    }
}
