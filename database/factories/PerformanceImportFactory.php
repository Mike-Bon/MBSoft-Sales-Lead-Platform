<?php

namespace Database\Factories;

use App\Enums\PerformanceImportStatus;
use App\Enums\PerformanceImportType;
use App\Models\PerformanceImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceImport>
 */
class PerformanceImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => PerformanceImportType::Actual,
            'source_filename' => 'actuals_fy2026.csv',
            'fiscal_year' => 2026,
            'status' => PerformanceImportStatus::Completed,
            'accepted_rows' => 0,
            'rejected_rows' => 0,
            'dry_run' => false,
            'summary' => null,
            'imported_by' => null,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ];
    }
}
