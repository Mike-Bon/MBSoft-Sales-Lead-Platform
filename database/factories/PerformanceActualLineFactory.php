<?php

namespace Database\Factories;

use App\Models\PerformanceActualLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceActualLine>
 */
class PerformanceActualLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $team = Team::factory();

        return [
            'fiscal_year' => 2026,
            'period_month' => fake()->numberBetween(1, 12),
            'team_id' => $team,
            'reporting_unit_id' => ReportingUnit::factory()->for($team),
            'actual_units' => fake()->randomFloat(2, 40, 520),
            'actual_revenue' => fake()->randomFloat(2, 80000, 2100000),
            'currency' => 'PHP',
            'source' => 'actuals_fy2026.csv',
            'imported_at' => now(),
        ];
    }
}
