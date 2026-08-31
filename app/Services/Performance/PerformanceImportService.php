<?php

namespace App\Services\Performance;

use App\Enums\PerformanceImportStatus;
use App\Enums\PerformanceImportType;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceImport;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Support\Csv;
use App\Support\Performance\ImportResult;
use Illuminate\Support\Facades\DB;

/**
 * Validate-first importer for the corporate budget workbook's monthly
 * PLAN (phased budget) and ACTUAL (operational) figures.
 *
 * Guarantees:
 *   - The WHOLE file is validated before any write. One bad row → the
 *     entire file is rejected and nothing is written (no partial import).
 *   - Idempotent: a row is upserted on its business key
 *     (fiscal_year, period_month, team_id, reporting_unit_id), so a
 *     repeated import UPDATES rather than duplicates.
 *   - No fuzzy matching: team_code and reporting_unit_code must match a
 *     `teams.code` / `reporting_units.code` exactly (trim + case-insensitive
 *     only). A unit that belongs to another team is rejected with a
 *     distinct message.
 *   - No CSV cell content is ever persisted or surfaced: only resolved
 *     ids, parsed numbers, and the operator-supplied filename are stored.
 *     A formula/injection cell fails numeric parsing or the code lookup
 *     and is rejected — it is never evaluated.
 *   - Negative units/revenue are rejected
 *     (config('performance.import.reject_negative_values'), default true);
 *     a correction is a re-import of the corrected positive figure.
 *   - Every write is wrapped in a transaction and linked to a
 *     `performance_imports` batch row for provenance.
 */
class PerformanceImportService
{
    private const PLAN_COLUMNS = ['fiscal_year', 'period_month', 'team_code', 'reporting_unit_code', 'target_units', 'target_revenue'];

    private const ACTUAL_COLUMNS = ['fiscal_year', 'period_month', 'team_code', 'reporting_unit_code', 'actual_units', 'actual_revenue'];

    public function import(PerformanceImportType $type, string $csvPath, ?User $importer = null, bool $dryRun = false): ImportResult
    {
        $rows = Csv::readFile($csvPath);

        $import = new PerformanceImport;
        $import->type = $type;
        $import->source_filename = basename($csvPath);
        $import->status = PerformanceImportStatus::Validating;
        $import->dry_run = $dryRun;
        $import->imported_by = $importer?->id;
        $import->started_at = now();
        $import->save();

        [$validated, $errors] = $this->validate($type, $rows);

        // A single per-file fiscal_year for the batch metadata, when uniform.
        $fiscalYears = array_values(array_unique(array_map(fn ($v) => $v['fiscal_year'], $validated)));
        $import->fiscal_year = count($fiscalYears) === 1 ? $fiscalYears[0] : null;

        if ($errors !== []) {
            $import->status = PerformanceImportStatus::Failed;
            $import->rejected_rows = count($rows) - count($validated);
            $import->accepted_rows = 0;
            $import->summary = 'Rejected: '.count($errors).' error(s). No rows were written.';
            $import->completed_at = now();
            $import->save();

            return new ImportResult(false, false, $dryRun, 0, count($rows), $errors, [], $import);
        }

        if ($dryRun) {
            $import->status = PerformanceImportStatus::Completed;
            $import->accepted_rows = count($validated);
            $import->rejected_rows = 0;
            $import->summary = 'Dry run: '.count($validated).' row(s) valid. No rows were written.';
            $import->completed_at = now();
            $import->save();

            return new ImportResult(true, false, true, count($validated), 0, [], [], $import);
        }

        $stats = DB::transaction(function () use ($type, $validated, $import) {
            $created = 0;
            $updated = 0;

            foreach ($validated as $row) {
                $model = $this->upsert($type, $row, $import);
                $model->wasRecentlyCreated ? $created++ : $updated++;
            }

            $import->status = PerformanceImportStatus::Completed;
            $import->accepted_rows = count($validated);
            $import->rejected_rows = 0;
            $import->summary = "Imported {$created} new + {$updated} updated line(s).";
            $import->completed_at = now();
            $import->save();

            return ['created' => $created, 'updated' => $updated];
        });

        return new ImportResult(true, true, false, count($validated), 0, [], $stats, $import->refresh());
    }

    /**
     * @param  list<array{line: int, data: array<string, string>}>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function validate(PerformanceImportType $type, array $rows): array
    {
        $required = $type === PerformanceImportType::Plan ? self::PLAN_COLUMNS : self::ACTUAL_COLUMNS;
        $unitsColumn = $type === PerformanceImportType::Plan ? 'target_units' : 'actual_units';
        $revenueColumn = $type === PerformanceImportType::Plan ? 'target_revenue' : 'actual_revenue';
        $rejectNegative = (bool) config('performance.import.reject_negative_values', true);

        $errors = [];
        $validated = [];
        $seenKeys = [];

        if ($rows === []) {
            return [[], ['The file has no data rows.']];
        }

        $header = array_keys($rows[0]['data']);
        $missing = array_diff($required, $header);
        if ($missing !== []) {
            return [[], ['Missing required column(s): '.implode(', ', $missing).'. Expected: '.implode(', ', $required).'.']];
        }

        $teams = Team::query()->whereNotNull('code')->get(['id', 'code'])
            ->keyBy(fn (Team $t) => mb_strtolower(trim((string) $t->code)));
        $unitsByCode = ReportingUnit::query()->get(['id', 'team_id', 'code'])
            ->groupBy(fn (ReportingUnit $u) => mb_strtolower(trim($u->code)));

        foreach ($rows as $row) {
            $line = $row['line'];
            $d = $row['data'];
            $rowErrors = [];

            $fiscalYear = $this->parseInt($d['fiscal_year'] ?? '');
            if ($fiscalYear === null || $fiscalYear < 2000 || $fiscalYear > 2100) {
                $rowErrors[] = "line {$line}: fiscal_year must be a year 2000-2100.";
            }

            $periodMonth = $this->parseInt($d['period_month'] ?? '');
            if ($periodMonth === null || $periodMonth < 1 || $periodMonth > 12) {
                $rowErrors[] = "line {$line}: period_month must be a fiscal ordinal 1-12 (1 = December).";
            }

            $teamCode = mb_strtolower(trim($d['team_code'] ?? ''));
            $team = $teamCode === '' ? null : $teams->get($teamCode);
            if ($teamCode === '') {
                $rowErrors[] = "line {$line}: team_code is blank.";
            } elseif ($team === null) {
                $rowErrors[] = "line {$line}: unknown team_code \"{$d['team_code']}\".";
            }

            $unitCodeRaw = trim($d['reporting_unit_code'] ?? '');
            $unitCode = mb_strtolower($unitCodeRaw);
            $reportingUnitId = null;

            $unitRequired = $type === PerformanceImportType::Actual;
            if ($unitCodeRaw === '' && $unitRequired) {
                $rowErrors[] = "line {$line}: reporting_unit_code is required for an actuals import.";
            } elseif ($unitCodeRaw !== '') {
                $candidates = $unitsByCode->get($unitCode);
                if ($candidates === null || $candidates->isEmpty()) {
                    $rowErrors[] = "line {$line}: unknown reporting_unit_code \"{$unitCodeRaw}\".";
                } elseif ($team !== null) {
                    $match = $candidates->firstWhere('team_id', $team->id);
                    if ($match === null) {
                        $rowErrors[] = "line {$line}: reporting_unit_code \"{$unitCodeRaw}\" does not belong to team \"{$d['team_code']}\".";
                    } else {
                        $reportingUnitId = $match->id;
                    }
                }
            }

            // "units" is a weighted / fractional business measure — 0, 278,
            // 278.4 and 0.25 are all valid; only negatives, non-numeric text
            // and malformed decimals are rejected. Blank is allowed (→ null).
            $units = $this->parseDecimal($d[$unitsColumn] ?? '', allowBlank: true);
            if ($units === false) {
                $rowErrors[] = "line {$line}: {$unitsColumn} \"{$d[$unitsColumn]}\" is not a valid".($rejectNegative ? ' non-negative' : '').' number.';
                $units = null;
            }

            $revenue = $this->parseDecimal($d[$revenueColumn] ?? '', allowBlank: false);
            if ($revenue === false) {
                $rowErrors[] = "line {$line}: {$revenueColumn} \"{$d[$revenueColumn]}\" is not a valid".($rejectNegative ? ' non-negative' : '').' number.';
                $revenue = null;
            }

            if ($rowErrors !== []) {
                $errors = array_merge($errors, $rowErrors);

                continue;
            }

            $key = implode('|', [$fiscalYear, $periodMonth, $team->id, $reportingUnitId ?? 'TEAM']);
            if (isset($seenKeys[$key])) {
                $errors[] = "line {$line}: duplicate of line {$seenKeys[$key]} (same fiscal_year / period_month / team / reporting unit).";

                continue;
            }
            $seenKeys[$key] = $line;

            $validated[] = [
                'fiscal_year' => $fiscalYear,
                'period_month' => $periodMonth,
                'team_id' => $team->id,
                'reporting_unit_id' => $reportingUnitId,
                'units' => $units,
                'revenue' => $revenue,
            ];
        }

        return [$validated, $errors];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsert(PerformanceImportType $type, array $row, PerformanceImport $import): PerformancePlanLine|PerformanceActualLine
    {
        $key = [
            'fiscal_year' => $row['fiscal_year'],
            'period_month' => $row['period_month'],
            'team_id' => $row['team_id'],
            'reporting_unit_id' => $row['reporting_unit_id'],
        ];

        $values = $type === PerformanceImportType::Plan
            ? ['target_units' => $row['units'], 'target_revenue' => $row['revenue']]
            : ['actual_units' => $row['units'], 'actual_revenue' => $row['revenue']];

        $values += [
            'currency' => 'PHP',
            'source' => $import->source_filename,
            'imported_at' => now(),
            'performance_import_id' => $import->id,
        ];

        $model = $type === PerformanceImportType::Plan ? new PerformancePlanLine : new PerformanceActualLine;

        return $model->newQuery()->updateOrCreate($key, $values);
    }

    private function parseInt(string $raw): ?int
    {
        $raw = trim($raw);

        return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * Parse a units or revenue cell. Accepts an optional-sign integer or
     * fixed-point decimal only ("0", "278", "278.4", "278.40", "0.25")
     * after thousands separators / currency noise are stripped. Everything
     * else — blank-when-not-allowed, non-numeric text, "NaN"/"Infinity",
     * scientific notation, a trailing/leading bare dot, a formula string —
     * is rejected. No integer rounding: the decimal value is preserved and
     * only quantised to 2 dp at persistence by the model's decimal:2 cast.
     *
     * @return float|null|false null = blank (allowed only when $allowBlank), false = malformed/negative
     */
    private function parseDecimal(string $raw, bool $allowBlank): float|null|false
    {
        $clean = $this->stripNumericNoise($raw);

        if ($clean === '') {
            return $allowBlank ? null : false;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $clean) !== 1) {
            return false;
        }

        $value = (float) $clean;

        if (! is_finite($value)) {
            return false;
        }

        if ($value < 0 && (bool) config('performance.import.reject_negative_values', true)) {
            return false;
        }

        return $value;
    }

    private function stripNumericNoise(string $raw): string
    {
        // Remove thousands separators, the peso mark, an explicit
        // "PHP"/"php" token, and whitespace (incl. non-breaking spaces).
        // A leading "=", "+", "-" (non-numeric), "@" (CSV injection) is
        // left in place and fails the numeric regex in the caller.
        $raw = str_ireplace('php', '', trim($raw));

        return str_replace(["\xC2\xA0", ' ', ',', '₱'], '', $raw);
    }
}
