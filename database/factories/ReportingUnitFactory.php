<?php

namespace Database\Factories;

use App\Enums\ReportingUnitStatus;
use App\Models\ReportingUnit;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReportingUnit>
 */
class ReportingUnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'team_id' => Team::factory(),
            'code' => strtoupper(Str::of($name)->slug('_')->limit(20, '')),
            'name' => $name,
            'status' => ReportingUnitStatus::Active,
            'sort_order' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ReportingUnitStatus::Inactive]);
    }
}
