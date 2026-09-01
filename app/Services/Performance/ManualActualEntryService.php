<?php

namespace App\Services\Performance;

use App\Enums\ActualLineChangeType;
use App\Enums\PerformanceImportChannel;
use App\Models\PerformanceActualLine;
use App\Models\ReportingUnit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Performance\ActualLineWriteResult;
use App\Support\Performance\ManualEntryState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The MANUAL single-value channel for operational actuals — a controlled
 * correction / fallback, NOT modelled as a one-row CSV import.
 *
 * Shares with the CSV path: the numeric rule (via App\Rules\PerformanceAmount
 * / ActualAmountParser), reporting-unit resolution, the business key, and
 * the authoritative writer (which records the immutable revision + audit).
 * It adds an optimistic lock so a correction can never silently overwrite
 * a value that changed after the form was rendered.
 */
class ManualActualEntryService
{
    public function __construct(private readonly AuthoritativeActualLineWriter $writer) {}

    public function currentState(int $fiscalYear, ReportingUnit $unit, int $periodMonth): ManualEntryState
    {
        return ManualEntryState::for($fiscalYear, $unit->id, $periodMonth, $this->lookup($fiscalYear, $unit, $periodMonth));
    }

    /**
     * Would applying $revenue/$units to this cell change an EXISTING
     * reported value? (Used by the form request to require a reason.)
     */
    public function wouldChangeExisting(int $fiscalYear, ReportingUnit $unit, int $periodMonth, float $revenue, ?float $units): bool
    {
        $line = $this->lookup($fiscalYear, $unit, $periodMonth);

        if ($line === null) {
            return false;
        }

        $currentRevenue = (float) $line->actual_revenue;
        $currentUnits = $line->actual_units === null ? null : (float) $line->actual_units;

        return ! ($this->writer->sameRevenue($currentRevenue, $revenue) && $this->writer->sameUnits($currentUnits, $units));
    }

    /**
     * @throws ValidationException optimistic-lock failure (surfaced on the `lock` field)
     */
    public function save(
        User $actor,
        int $fiscalYear,
        ReportingUnit $unit,
        int $periodMonth,
        float $revenue,
        ?float $units,
        ?string $reason,
        string $submittedLock,
    ): ActualLineWriteResult {
        return DB::transaction(function () use ($actor, $fiscalYear, $unit, $periodMonth, $revenue, $units, $reason, $submittedLock) {
            $line = PerformanceActualLine::query()
                ->where([
                    'fiscal_year' => $fiscalYear,
                    'period_month' => $periodMonth,
                    'team_id' => $unit->team_id,
                    'reporting_unit_id' => $unit->id,
                ])
                ->lockForUpdate()
                ->first();

            $currentToken = ManualEntryState::for($fiscalYear, $unit->id, $periodMonth, $line)->lockToken;

            if (! hash_equals($currentToken, $submittedLock)) {
                throw ValidationException::withMessages([
                    'lock' => 'This value changed since you opened the form. Reload the page and check the current figure before saving.',
                ]);
            }

            $reason = $reason !== null && trim($reason) === '' ? null : $reason;

            $result = $this->writer->write(
                $fiscalYear, $periodMonth, $unit, $revenue, $units,
                PerformanceImportChannel::ManualEntry, $actor, import: null, reason: $reason, skipIfUnchanged: true,
            );

            if ($result->wrote()) {
                AuditLogger::record('performance.actuals.edited', $actor, [
                    'reporting_unit_id' => $unit->id,
                    'fiscal_year' => $fiscalYear,
                    'period_month' => $periodMonth,
                    'change_type' => $result->changeType->value,
                    'previous_revenue' => $result->previousRevenue,
                    'new_revenue' => $revenue,
                    'has_reason' => $reason !== null,
                ]);
            }

            return $result;
        });
    }

    private function lookup(int $fiscalYear, ReportingUnit $unit, int $periodMonth): ?PerformanceActualLine
    {
        return PerformanceActualLine::query()->where([
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'team_id' => $unit->team_id,
            'reporting_unit_id' => $unit->id,
        ])->first();
    }

    public function isNoop(ActualLineWriteResult $result): bool
    {
        return $result->changeType === ActualLineChangeType::Unchanged;
    }
}
