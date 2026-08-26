<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => null,
            'organization_id' => null,
            'contact_id' => null,
            'lead_id' => null,
            'opportunity_id' => null,
            'type' => ActivityType::Note,
            'subject' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'team_id' => $user->team_id,
        ]);
    }

    public function type(ActivityType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }
}
