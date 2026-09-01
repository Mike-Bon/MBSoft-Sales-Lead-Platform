<?php

namespace App\Services\Performance;

use App\Enums\ActualLineChangeType;
use App\Enums\PerformanceImportChannel;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceActualLineRevision;
use App\Models\PerformanceImport;
use App\Models\ReportingUnit;
use App\Models\User;
use App\Support\Performance\ActualLineWriteResult;

/**
 * THE authoritative writer for operational actual lines.
 *
 * Every create or value change of a performance_actual_lines row — from
 * a confirmed bulk CSV import OR a manual correction — goes through here,
 * so that:
 *   - the same idempotent business-key upsert is used
 *     (fiscal_year, period_month, team_id, reporting_unit_id),
 *   - an immutable App\Models\PerformanceActualLineRevision is recorded
 *     with the previous and new revenue/units, the actor, the channel
 *     and (bulk) the PerformanceImport,
 *   - a genuine no-op writes NOTHING and records NO revision.
 *
 * It does NOT parse CSV, resolve codes or authorize — callers hand it
 * fully-resolved, already-authorized, already-validated values. It does
 * NOT open its own transaction: the caller owns transaction + row locks
 * (batch atomicity for an import; a single locked row for a manual
 * edit).
 *
 * NEVER writes an Organization, Opportunity, Lead or any CRM record.
 */
class AuthoritativeActualLineWriter
{
    /**
     * @param  float  $revenue  a reported actual always has a revenue figure ("not reported" = no row)
     * @param  float|null  $units  null = "units not reported" (kept NULL, never coerced to 0); 0.0 = a reported zero
     */
    public function write(
        int $fiscalYear,
        int $periodMonth,
        ReportingUnit $unit,
        float $revenue,
        ?float $units,
        PerformanceImportChannel $channel,
        ?User $changedBy,
        ?PerformanceImport $import = null,
        ?string $reason = null,
        bool $skipIfUnchanged = true,
    ): ActualLineWriteResult {
        $key = [
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'team_id' => $unit->team_id,
            'reporting_unit_id' => $unit->id,
        ];

        $existing = PerformanceActualLine::query()->where($key)->first();

        $previousRevenue = $existing !== null ? (float) $existing->actual_revenue : null;
        $previousUnits = $existing?->actual_units === null ? null : (float) $existing->actual_units;

        $changeType = $existing === null
            ? ActualLineChangeType::Created
            : ($this->sameRevenue($previousRevenue, $revenue) && $this->sameUnits($previousUnits, $units)
                ? ActualLineChangeType::Unchanged
                : ActualLineChangeType::Updated);

        if ($changeType === ActualLineChangeType::Unchanged && $skipIfUnchanged) {
            return new ActualLineWriteResult($existing, $changeType, null, $previousRevenue, $previousUnits);
        }

        // Keep `source` human-readable and consistent with the pre-existing
        // CLI importer (the filename). The full batch link is
        // `performance_import_id`; manual edits carry no batch.
        $sourceLabel = match ($channel) {
            PerformanceImportChannel::ManualEntry => 'manual:user#'.($changedBy?->id ?? 'unknown'),
            PerformanceImportChannel::CsvImport => $import?->source_filename ?? 'csv',
        };

        $line = PerformanceActualLine::query()->updateOrCreate($key, [
            'actual_units' => $units,
            'actual_revenue' => $revenue,
            'currency' => 'PHP',
            'source' => $sourceLabel,
            'imported_at' => now(),
            'performance_import_id' => $import?->id,
        ]);

        $revision = null;

        if ($changeType !== ActualLineChangeType::Unchanged) {
            $revision = PerformanceActualLineRevision::query()->create([
                'performance_actual_line_id' => $line->id,
                'fiscal_year' => $fiscalYear,
                'period_month' => $periodMonth,
                'team_id' => $unit->team_id,
                'reporting_unit_id' => $unit->id,
                'previous_revenue' => $previousRevenue,
                'previous_units' => $previousUnits,
                'new_revenue' => $revenue,
                'new_units' => $units,
                'change_type' => $changeType,
                'channel' => $channel,
                'performance_import_id' => $import?->id,
                'changed_by' => $changedBy?->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        }

        return new ActualLineWriteResult($line, $changeType, $revision, $previousRevenue, $previousUnits);
    }

    public function sameRevenue(?float $a, ?float $b): bool
    {
        return $this->q($a) === $this->q($b);
    }

    public function sameUnits(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $this->q($a) === $this->q($b);
    }

    private function q(?float $v): ?string
    {
        return $v === null ? null : number_format($v, 2, '.', '');
    }
}
