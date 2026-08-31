<?php

namespace Database\Factories;

use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformancePlanLine>
 */
class PerformancePlanLineFactory extends Factory
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
            'target_units' => fake()->randomFloat(2, 50, 500),
            'target_revenue' => fake()->randomFloat(2, 100000, 2000000),
            'currency' => 'PHP',
            'source' => 'plan_fy2026.csv',
            'imported_at' => now(),
        ];
    }

    public function teamLevel(): static
    {
        return $this->state(fn () => ['reporting_unit_id' => null]);
    }
}
