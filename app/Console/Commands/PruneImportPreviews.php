<?php

namespace App\Console\Commands;

use App\Enums\PerformanceImportStatus;
use App\Models\PerformanceImport;
use Illuminate\Console\Command;

/**
 * Optional housekeeping: delete expired / discarded web "Import Actuals"
 * staged-preview rows. These records are tiny and expiring one is already
 * harmless (an expired preview is non-actionable), so this is maintenance
 * — NOT a correctness dependency and NOT required in a scheduler. GET
 * requests never prune.
 */
class PruneImportPreviews extends Command
{
    protected $signature = 'performance:prune-import-previews {--days=7 : delete previewing/cancelled rows older than this}';

    protected $description = 'Delete stale web import preview rows (housekeeping only).';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));

        $deleted = PerformanceImport::query()
            ->whereIn('status', [PerformanceImportStatus::Previewing, PerformanceImportStatus::Cancelled])
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} stale import preview row(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
