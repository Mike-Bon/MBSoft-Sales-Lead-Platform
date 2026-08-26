<?php

namespace Database\Factories;

use App\Enums\WhatsAppNumberStatus;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppBusinessNumber>
 */
class WhatsAppBusinessNumberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => null,
            'created_by' => User::factory(),
            'display_name' => fake()->company(),
            'phone_number' => '+1'.fake()->numerify('##########'),
            'phone_number_id' => fake()->unique()->numerify('##############'),
            'waba_id' => fake()->numerify('##############'),
            'status' => WhatsAppNumberStatus::Connected,
        ];
    }

    public function forTeam(int $teamId): static
    {
        return $this->state(fn (array $attributes) => ['team_id' => $teamId]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WhatsAppNumberStatus::Disconnected,
        ]);
    }
}
