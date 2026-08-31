<?php

namespace App\Console\Commands;

use App\Enums\PerformanceImportType;
use App\Models\User;
use App\Services\Performance\PerformanceImportService;
use Illuminate\Console\Command;

/**
 * Imports the corporate workbook's monthly OPERATIONAL ACTUALS from a
 * normalised CSV:
 *
 *   fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue
 *
 * period_month is the FISCAL ordinal (1 = December … 12 = November).
 * reporting_unit_code is REQUIRED (every actual comes from a branch row).
 *
 * These figures are the authoritative OPERATIONAL revenue/units — they
 * are never turned into CRM Opportunities and never affect CRM
 * Closed-Won pipeline performance.
 *
 * Validate-first + idempotent, same as performance:import-plan.
 */
class ImportPerformanceActuals extends Command
{
    protected $signature = 'performance:import-actuals
        {file : path to the actuals CSV}
        {--dry-run : validate only, write nothing}
        {--as= : email of the Manager to attribute this import to}';

    protected $description = 'Import monthly operational-actuals lines from a normalised CSV.';

    public function handle(PerformanceImportService $service): int
    {
        $importer = $this->resolveImporter();
        if ($importer === false) {
            return self::FAILURE;
        }

        $result = $service->import(
            PerformanceImportType::Actual,
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

        $this->info("Imported {$result->acceptedRows} actual line(s) — {$result->stats['created']} new, {$result->stats['updated']} updated. Batch #{$result->import?->id}.");

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
