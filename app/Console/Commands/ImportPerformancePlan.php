<?php

namespace App\Console\Commands;

use App\Enums\PerformanceImportType;
use App\Models\User;
use App\Services\Performance\PerformanceImportService;
use Illuminate\Console\Command;

/**
 * Imports the corporate workbook's monthly PHASED BUDGET (plan) from a
 * normalised CSV:
 *
 *   fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue
 *
 * period_month is the FISCAL ordinal (1 = December … 12 = November).
 * reporting_unit_code may be blank for a team-level budget line.
 *
 * Validate-first: one bad row rejects the whole file and writes nothing.
 * Idempotent: re-running updates existing lines, never duplicates.
 *
 * CLI-only. Run on the server by the operator; --as attributes the batch
 * to a Manager for the audit trail (it must be a Manager account).
 */
class ImportPerformancePlan extends Command
{
    protected $signature = 'performance:import-plan
        {file : path to the plan CSV}
        {--dry-run : validate only, write nothing}
        {--as= : email of the Manager to attribute this import to}';

    protected $description = 'Import monthly phased-budget (plan) lines from a normalised CSV.';

    public function handle(PerformanceImportService $service): int
    {
        $importer = $this->resolveImporter();
        if ($importer === false) {
            return self::FAILURE;
        }

        $result = $service->import(
            PerformanceImportType::Plan,
            $this->argument('file'),
            $importer,
            (bool) $this->option('dry-run'),
        );

        foreach ($result->errors as $error) {
            $this->line('  <fg=red>✗</> '.$error);
        }

        if (! $result->ok) {
            $this->error("Rejected: {$result->rejectedRows} row(s) had ".count($result->errors).' error(s). Nothing was written.');

            return self::FAILURE;
        }

        if ($result->dryRun) {
            $this->info("Dry run OK: {$result->acceptedRows} valid row(s). Nothing was written.");

            return self::SUCCESS;
        }

        $this->info("Imported {$result->acceptedRows} plan line(s) — {$result->stats['created']} new, {$result->stats['updated']} updated. Batch #{$result->import?->id}.");

        return self::SUCCESS;
    }

    private function resolveImporter(): User|false|null
    {
        $email = $this->option('as');
        if ($email === null) {
            return null;
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error("No user with email {$email}.");

            return false;
        }
        if (! $user->isManager()) {
            $this->error("{$email} is not a Manager — performance imports are a Manager/admin action.");

            return false;
        }

        return $user;
    }
}
