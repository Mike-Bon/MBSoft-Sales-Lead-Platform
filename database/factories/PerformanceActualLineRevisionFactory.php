<?php

namespace Database\Factories;

use App\Enums\ActualLineChangeType;
use App\Enums\PerformanceImportChannel;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceActualLineRevision;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceActualLineRevision>
 */
class PerformanceActualLineRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $team = Team::factory();
        $unit = ReportingUnit::factory()->for($team);

        return [
            'performance_actual_line_id' => PerformanceActualLine::factory(),
            'fiscal_year' => 2026,
            'period_month' => fake()->numberBetween(1, 12),
            'team_id' => $team,
            'reporting_unit_id' => $unit,
            'previous_revenue' => null,
            'previous_units' => null,
            'new_revenue' => fake()->randomFloat(2, 1000, 500000),
            'new_units' => fake()->randomFloat(2, 10, 500),
            'change_type' => ActualLineChangeType::Created,
            'channel' => PerformanceImportChannel::ManualEntry,
            'performance_import_id' => null,
            'changed_by' => User::factory()->manager(),
            'reason' => null,
            'created_at' => now(),
        ];
    }

    public function updated(): static
    {
        return $this->state(fn () => [
            'change_type' => ActualLineChangeType::Updated,
            'previous_revenue' => fake()->randomFloat(2, 1000, 500000),
            'previous_units' => fake()->randomFloat(2, 10, 500),
        ]);
    }
}
