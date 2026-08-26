<?php

namespace Database\Factories;

use App\Enums\TeamStatus;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Team '.fake()->unique()->numerify('##'),
            'code' => null,
            'status' => TeamStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TeamStatus::Inactive,
        ]);
    }
}
