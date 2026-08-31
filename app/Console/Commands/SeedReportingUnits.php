<?php

namespace App\Console\Commands;

use App\Enums\ReportingUnitStatus;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Support\Performance\ReportingUnitCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Master-data importer for the 44 FY2026 operational reporting units
 * (App\Support\Performance\ReportingUnitCatalog).
 *
 * Idempotent: upserts on the natural key (team_id + code), so re-running
 * never creates duplicates and never renames a unit that already matches.
 * Fails closed — the whole run is a single transaction and aborts (writing
 * nothing) if any team code is missing, if the resolved rows would not
 * total exactly 44, or if a code somehow collides across teams.
 *
 * Prerequisite: every team code in the catalog must already exist in
 * `teams.code` (run `performance:show-teams`, verify, then backfill).
 *
 * Never touches organizations, opportunities, targets or any CRM table.
 */
class SeedReportingUnits extends Command
{
    protected $signature = 'performance:seed-reporting-units {--dry-run : resolve and validate only, write nothing}';

    protected $description = 'Create/refresh the 44 FY2026 operational reporting units (idempotent master data).';

    public function handle(): int
    {
        $catalog = ReportingUnitCatalog::fy2026();
        $dryRun = (bool) $this->option('dry-run');

        // Resolve every team code up front.
        $teamsByCode = Team::query()->whereNotNull('code')->get(['id', 'code'])
            ->keyBy(fn (Team $t) => (string) $t->code);

        $missing = [];
        foreach (ReportingUnitCatalog::teamCodes() as $code) {
            if (! $teamsByCode->has($code)) {
                $missing[] = $code;
            }
        }
        if ($missing !== []) {
            $this->error('Missing team code(s) in `teams.code`: '.implode(', ', $missing).'. Run the backfill first — nothing written.');

            return self::FAILURE;
        }

        if (count($catalog) !== ReportingUnitCatalog::EXPECTED_COUNT) {
            $this->error('Catalog holds '.count($catalog).' rows, expected '.ReportingUnitCatalog::EXPECTED_COUNT.'. Aborting.');

            return self::FAILURE;
        }

        $seenCodes = [];
        foreach ($catalog as $row) {
            if (isset($seenCodes[$row['code']])) {
                $this->error("Duplicate reporting-unit code in catalog: {$row['code']}. Aborting.");

                return self::FAILURE;
            }
            $seenCodes[$row['code']] = true;
        }

        try {
            [$created, $updated, $unchanged] = DB::transaction(function () use ($catalog, $teamsByCode, $dryRun) {
                $created = $updated = $unchanged = 0;

                foreach ($catalog as $row) {
                    $team = $teamsByCode->get($row['team_code']);

                    $unit = ReportingUnit::query()->firstOrNew([
                        'team_id' => $team->id,
                        'code' => $row['code'],
                    ]);

                    $target = [
                        'name' => $row['name'],
                        'sort_order' => $row['sort_order'],
                        'status' => ReportingUnitStatus::Active,
                    ];

                    if (! $unit->exists) {
                        $created++;
                    } elseif ($unit->name !== $target['name']
                        || $unit->sort_order !== $target['sort_order']
                        || $unit->status !== $target['status']) {
                        $updated++;
                    } else {
                        $unchanged++;
                    }

                    if (! $dryRun) {
                        $unit->fill($target);
                        $unit->team()->associate($team);
                        $unit->save();
                    }
                }

                $total = ReportingUnit::query()
                    ->whereIn('team_id', $teamsByCode->pluck('id'))
                    ->count();

                if (! $dryRun && $total !== ReportingUnitCatalog::EXPECTED_COUNT) {
                    throw new \RuntimeException("Post-seed reporting_units count for these teams is {$total}, expected ".ReportingUnitCatalog::EXPECTED_COUNT.'. Rolled back.');
                }

                return [$created, $updated, $unchanged];
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $verb = $dryRun ? 'Would apply' : 'Applied';
        $this->info("{$verb}: {$created} new, {$updated} updated, {$unchanged} unchanged (44 total).".($dryRun ? ' [dry run — nothing written]' : ''));

        return self::SUCCESS;
    }
}
