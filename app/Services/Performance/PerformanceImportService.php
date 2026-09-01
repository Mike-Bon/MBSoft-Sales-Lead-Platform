<?php

namespace App\Services\Performance;

use App\Enums\ActualLineChangeType;
use App\Enums\PerformanceImportChannel;
use App\Enums\PerformanceImportStatus;
use App\Enums\PerformanceImportType;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceImport;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Csv;
use App\Support\Performance\ActualAmountParser;
use App\Support\Performance\ImportPreviewOutcome;
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
 *   - ACTUAL lines are written only through AuthoritativeActualLineWriter,
 *     which records an immutable revision for every real value change.
 *   - Every write is wrapped in a transaction and linked to a
 *     `performance_imports` batch row for provenance.
 *
 * Three entry points:
 *   - import()        : one-shot validate + commit (the CLI commands).
 *   - preview()       : web upload — validate + stage a Previewing batch,
 *                       write NOTHING.
 *   - commitPreview() : web confirm — apply a reviewed, fingerprinted
 *                       staged preview.
 */
class PerformanceImportService
{
    private const PLAN_COLUMNS = ['fiscal_year', 'period_month', 'team_code', 'reporting_unit_code', 'target_units', 'target_revenue'];

    private const ACTUAL_COLUMNS = ['fiscal_year', 'period_month', 'team_code', 'reporting_unit_code', 'actual_units', 'actual_revenue'];

    public function __construct(private readonly AuthoritativeActualLineWriter $actualWriter) {}

    // ── one-shot import (CLI) ───────────────────────────────────────────

    public function import(PerformanceImportType $type, string $csvPath, ?User $importer = null, bool $dryRun = false): ImportResult
    {
        $rows = Csv::readFile($csvPath);

        $import = new PerformanceImport;
        $import->type = $type;
        $import->channel = PerformanceImportChannel::CsvImport;
        $import->source_filename = basename($csvPath);
        $import->status = PerformanceImportStatus::Validating;
        $import->dry_run = $dryRun;
        $import->imported_by = $importer?->id;
        $import->data_row_count = count($rows);
        $import->started_at = now();
        $import->save();

        [$validated, $errors] = $this->validate($type, $rows);

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

        $stats = DB::transaction(function () use ($type, $validated, $import, $importer) {
            $stats = $type === PerformanceImportType::Actual
                ? $this->writeActualRows($validated, $import, $importer, skipIfUnchanged: false)
                : $this->writePlanRows($validated, $import);

            $import->status = PerformanceImportStatus::Completed;
            $import->accepted_rows = count($validated);
            $import->rejected_rows = 0;
            $import->summary = $this->summaryLine($stats);
            $import->completed_at = now();
            $import->save();

            return $stats;
        });

        return new ImportResult(true, true, false, count($validated), 0, [], $stats, $import->refresh());
    }

    // ── web: upload → staged preview ────────────────────────────────────

    public function preview(
        PerformanceImportType $type,
        string $csvPath,
        User $actor,
        string $originalFilename,
        string $fileSha256,
        int $fileSizeBytes,
    ): ImportResult {
        $rows = Csv::readFile($csvPath);

        $import = new PerformanceImport;
        $import->type = $type;
        $import->channel = PerformanceImportChannel::CsvImport;
        $import->status = PerformanceImportStatus::Previewing;
        $import->dry_run = false;
        $import->source_filename = $originalFilename;
        $import->original_filename = $originalFilename;
        $import->file_sha256 = $fileSha256;
        $import->file_size_bytes = $fileSizeBytes;
        $import->data_row_count = count($rows);
        $import->imported_by = $actor->id;
        $import->started_at = now();
        $import->save();

        [$validated, $errors] = $this->validate($type, $rows);

        $fiscalYears = array_values(array_unique(array_map(fn ($v) => $v['fiscal_year'], $validated)));
        $import->fiscal_year = count($fiscalYears) === 1 ? $fiscalYears[0] : null;

        if ($errors !== []) {
            $import->status = PerformanceImportStatus::Failed;
            $import->rejected_rows = count($rows) - count($validated);
            $import->accepted_rows = 0;
            $import->summary = 'Rejected: '.count($errors).' error(s). No rows were written.';
            $import->preview_payload = ['errors' => $errors];
            $import->completed_at = now();
            $import->save();

            return new ImportResult(false, false, false, 0, count($rows), $errors, [], $import);
        }

        $classified = $this->classify($validated);
        $stats = $this->classifiedStats($classified);

        $import->data_row_count = count($validated);
        $import->accepted_rows = count($validated);
        $import->preview_payload = ['rows' => $classified, 'stats' => $stats];
        $import->preview_fingerprint = self::fingerprint($classified, $actor->id);
        $import->preview_expires_at = now()->addMinutes((int) config('performance.import.preview_ttl_minutes', 30));
        $import->summary = $this->summaryLine($stats).' — awaiting confirmation.';
        $import->save();

        return new ImportResult(true, false, false, count($validated), 0, [], $stats, $import->refresh());
    }

    // ── web: confirm a staged preview ──────────────────────────────────

    public function commitPreview(PerformanceImport $import, User $actor, string $submittedFingerprint): ImportPreviewOutcome
    {
        return DB::transaction(function () use ($import, $actor, $submittedFingerprint) {
            /** @var PerformanceImport $locked */
            $locked = PerformanceImport::query()->whereKey($import->id)->lockForUpdate()->first();

            if ($locked->status === PerformanceImportStatus::Completed) {
                return new ImportPreviewOutcome('already_completed', $locked, $this->payloadStats($locked),
                    'This import was already confirmed.');
            }

            if (! $locked->isPreviewActionable()) {
                return new ImportPreviewOutcome($locked->isPreviewExpired() ? 'expired' : 'not_actionable', $locked, [],
                    'This preview is no longer valid. Upload the file again.');
            }

            $rows = $locked->preview_payload['rows'] ?? [];

            if (! hash_equals(self::fingerprint($rows, $locked->imported_by), $submittedFingerprint)) {
                return new ImportPreviewOutcome('fingerprint_mismatch', $locked, [],
                    'This does not match the preview you reviewed. Reload the preview and check it again.');
            }

            // Re-classify against the CURRENT database state.
            $freshRows = $this->classify(array_map(fn ($r) => [
                'fiscal_year' => $r['fiscal_year'],
                'period_month' => $r['period_month'],
                'team_id' => $r['team_id'],
                'reporting_unit_id' => $r['reporting_unit_id'],
                'units' => $r['units'],
                'revenue' => $r['revenue'],
            ], $rows));

            if ($this->previewDrifted($rows, $freshRows)) {
                $stats = $this->classifiedStats($freshRows);
                $locked->preview_payload = ['rows' => $freshRows, 'stats' => $stats];
                $locked->preview_fingerprint = self::fingerprint($freshRows, $locked->imported_by);
                $locked->preview_expires_at = now()->addMinutes((int) config('performance.import.preview_ttl_minutes', 30));
                $locked->summary = $this->summaryLine($stats).' — data changed, re-review required.';
                $locked->save();

                return new ImportPreviewOutcome('data_changed', $locked->refresh(), $stats,
                    'An actual value changed since you reviewed this file. The preview has been refreshed — check it again before confirming.');
            }

            $units = ReportingUnit::query()
                ->whereIn('id', array_values(array_unique(array_map(fn ($r) => $r['reporting_unit_id'], $freshRows))))
                ->get()->keyBy('id');

            $created = $updated = $unchanged = 0;

            foreach ($freshRows as $r) {
                if ($r['change'] === ActualLineChangeType::Unchanged->value) {
                    $unchanged++;

                    continue;
                }

                $result = $this->actualWriter->write(
                    $r['fiscal_year'], $r['period_month'], $units[$r['reporting_unit_id']],
                    (float) $r['revenue'], $r['units'] === null ? null : (float) $r['units'],
                    PerformanceImportChannel::CsvImport, $actor, $locked, reason: null, skipIfUnchanged: true,
                );

                $result->changeType === ActualLineChangeType::Created ? $created++ : $updated++;
            }

            $stats = ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];

            $locked->status = PerformanceImportStatus::Completed;
            $locked->confirmed_by = $actor->id;
            $locked->confirmed_at = now();
            $locked->completed_at = now();
            $locked->accepted_rows = $created + $updated + $unchanged;
            $locked->summary = $this->summaryLine($stats);
            $locked->save();

            AuditLogger::record('performance.actuals.imported', $actor, [
                'import_id' => $locked->id,
                'file_sha256' => $locked->file_sha256,
                'fiscal_year' => $locked->fiscal_year,
                'stats' => $stats,
            ]);

            return new ImportPreviewOutcome('committed', $locked->refresh(), $stats,
                $this->summaryLine($stats));
        });
    }

    public function cancelPreview(PerformanceImport $import, User $actor): void
    {
        if ($import->status !== PerformanceImportStatus::Previewing) {
            return;
        }

        $import->forceFill([
            'status' => PerformanceImportStatus::Cancelled->value,
            'confirmed_by' => $actor->id,
            'confirmed_at' => now(),
            'completed_at' => now(),
            'summary' => 'Discarded by the Manager. Nothing was written.',
            'preview_payload' => null,
            'preview_fingerprint' => null,
        ])->save();
    }

    // ── row classification (create / update / unchanged) ───────────────

    /**
     * @param  list<array<string, mixed>>  $validated
     * @return list<array<string, mixed>>
     */
    private function classify(array $validated): array
    {
        if ($validated === []) {
            return [];
        }

        $fiscalYears = array_values(array_unique(array_map(fn ($r) => $r['fiscal_year'], $validated)));
        $unitIds = array_values(array_unique(array_map(fn ($r) => $r['reporting_unit_id'], $validated)));

        $existing = PerformanceActualLine::query()
            ->whereIn('fiscal_year', $fiscalYears)
            ->whereIn('reporting_unit_id', $unitIds)
            ->get()
            ->keyBy(fn (PerformanceActualLine $l) => $l->fiscal_year.'|'.$l->period_month.'|'.$l->team_id.'|'.$l->reporting_unit_id);

        return array_map(function (array $r) use ($existing) {
            $key = $r['fiscal_year'].'|'.$r['period_month'].'|'.$r['team_id'].'|'.$r['reporting_unit_id'];
            $line = $existing->get($key);

            $currentRevenue = $line !== null ? (float) $line->actual_revenue : null;
            $currentUnits = $line?->actual_units === null ? null : (float) $line->actual_units;
            $newRevenue = (float) $r['revenue'];
            $newUnits = $r['units'] === null ? null : (float) $r['units'];

            $change = $line === null
                ? ActualLineChangeType::Created
                : ($this->actualWriter->sameRevenue($currentRevenue, $newRevenue)
                    && $this->actualWriter->sameUnits($currentUnits, $newUnits)
                        ? ActualLineChangeType::Unchanged
                        : ActualLineChangeType::Updated);

            // All monetary/unit values are stored as fixed 2dp strings (or
            // null) so the JSON payload round-trips identically regardless
            // of this PHP's serialize_precision.
            return [
                'fiscal_year' => $r['fiscal_year'],
                'period_month' => $r['period_month'],
                'team_id' => $r['team_id'],
                'reporting_unit_id' => $r['reporting_unit_id'],
                'units' => self::money($newUnits),
                'revenue' => self::money($newRevenue),
                'change' => $change->value,
                'current_revenue' => self::money($currentRevenue),
                'current_units' => self::money($currentUnits),
            ];
        }, $validated);
    }

    private static function money(?float $v): ?string
    {
        return $v === null ? null : number_format($v, 2, '.', '');
    }

    /**
     * Did the LIVE data (classification / current stored value) drift away
     * from what the reviewer saw? Compares only the DB-derived fields.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    private function previewDrifted(array $before, array $after): bool
    {
        $shape = fn (array $rows) => array_map(fn ($r) => [
            (int) $r['fiscal_year'], (int) $r['period_month'], (int) $r['team_id'], (int) $r['reporting_unit_id'],
            (string) $r['change'], $r['current_revenue'], $r['current_units'],
        ], $rows);

        return $shape($before) !== $shape($after);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function classifiedStats(array $rows): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach ($rows as $r) {
            $stats[$r['change']]++;
        }

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    private function payloadStats(PerformanceImport $import): array
    {
        return $import->preview_payload['stats'] ?? [];
    }

    /**
     * A confirmation token over EXACTLY what the reviewer saw — the parsed
     * rows AND their classification against the then-current data. If a
     * live value changed, a regenerated preview produces a different
     * token, so a stale confirm can never match.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function fingerprint(array $rows, ?int $userId): string
    {
        $canonical = array_map(fn ($r) => [
            'fy' => (int) $r['fiscal_year'],
            'm' => (int) $r['period_month'],
            't' => (int) $r['team_id'],
            'u' => (int) $r['reporting_unit_id'],
            'units' => self::money(self::toFloatOrNull($r['units'])),
            'revenue' => self::money(self::toFloatOrNull($r['revenue'])),
            'change' => (string) $r['change'],
            'cur_revenue' => self::money(self::toFloatOrNull($r['current_revenue'] ?? null)),
            'cur_units' => self::money(self::toFloatOrNull($r['current_units'] ?? null)),
        ], $rows);

        return hash('sha256', json_encode([
            'user' => $userId,
            'rows' => $canonical,
        ], JSON_THROW_ON_ERROR));
    }

    private static function toFloatOrNull(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    // ── shared write helpers ───────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $validated
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function writeActualRows(array $validated, PerformanceImport $import, ?User $actor, bool $skipIfUnchanged): array
    {
        $units = ReportingUnit::query()
            ->whereIn('id', array_values(array_unique(array_map(fn ($r) => $r['reporting_unit_id'], $validated))))
            ->get()->keyBy('id');

        $created = $updated = $unchanged = 0;

        foreach ($validated as $row) {
            $result = $this->actualWriter->write(
                $row['fiscal_year'], $row['period_month'], $units[$row['reporting_unit_id']],
                (float) $row['revenue'], $row['units'] === null ? null : (float) $row['units'],
                PerformanceImportChannel::CsvImport, $actor, $import, reason: null, skipIfUnchanged: $skipIfUnchanged,
            );

            match ($result->changeType) {
                ActualLineChangeType::Created => $created++,
                ActualLineChangeType::Updated => $updated++,
                ActualLineChangeType::Unchanged => $unchanged++,
            };
        }

        return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];
    }

    /**
     * @param  list<array<string, mixed>>  $validated
     * @return array{created: int, updated: int}
     */
    private function writePlanRows(array $validated, PerformanceImport $import): array
    {
        $created = 0;
        $updated = 0;

        foreach ($validated as $row) {
            $key = [
                'fiscal_year' => $row['fiscal_year'],
                'period_month' => $row['period_month'],
                'team_id' => $row['team_id'],
                'reporting_unit_id' => $row['reporting_unit_id'],
            ];

            $model = (new PerformancePlanLine)->newQuery()->updateOrCreate($key, [
                'target_units' => $row['units'],
                'target_revenue' => $row['revenue'],
                'currency' => 'PHP',
                'source' => $import->source_filename,
                'imported_at' => now(),
                'performance_import_id' => $import->id,
            ]);

            $model->wasRecentlyCreated ? $created++ : $updated++;
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function summaryLine(array $stats): string
    {
        $parts = ["{$stats['created']} new", "{$stats['updated']} updated"];
        if (($stats['unchanged'] ?? 0) > 0) {
            $parts[] = "{$stats['unchanged']} unchanged";
        }

        return 'Imported '.implode(', ', $parts).' line(s).';
    }

    // ── validation (unchanged rules) ───────────────────────────────────

    /**
     * @param  list<array{line: int, data: array<string, string>}>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function validate(PerformanceImportType $type, array $rows): array
    {
        $required = $type === PerformanceImportType::Plan ? self::PLAN_COLUMNS : self::ACTUAL_COLUMNS;
        $unitsColumn = $type === PerformanceImportType::Plan ? 'target_units' : 'actual_units';
        $revenueColumn = $type === PerformanceImportType::Plan ? 'target_revenue' : 'actual_revenue';
        $rejectNegative = ActualAmountParser::rejectsNegatives();

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

            // An ACTUALS row with BOTH the units and revenue cells blank
            // means "no actual reported for this reporting unit / month" —
            // no row, not an error. This is what lets a Manager download
            // the month-scoped template, fill in only the branches that
            // reported, and upload it. (Units-without-revenue is still an
            // error below: a reported actual always carries revenue.)
            if ($type === PerformanceImportType::Actual
                && trim($d[$unitsColumn] ?? '') === ''
                && trim($d[$revenueColumn] ?? '') === '') {
                continue;
            }

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
            $units = ActualAmountParser::parse($d[$unitsColumn] ?? '', allowBlank: true);
            if ($units === false) {
                $rowErrors[] = "line {$line}: {$unitsColumn} \"{$d[$unitsColumn]}\" is not a valid".($rejectNegative ? ' non-negative' : '').' number.';
                $units = null;
            }

            $revenue = ActualAmountParser::parse($d[$revenueColumn] ?? '', allowBlank: false);
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

        if ($validated === [] && $errors === []) {
            $errors[] = 'No values to import — every data row was blank.';
        }

        return [$validated, $errors];
    }

    private function parseInt(string $raw): ?int
    {
        $raw = trim($raw);

        return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : null;
    }
}
