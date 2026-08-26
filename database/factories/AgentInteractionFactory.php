<?php

namespace Database\Factories;

use App\Enums\AgentInteractionStatus;
use App\Models\AgentInteraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentInteraction>
 */
class AgentInteractionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now();

        return [
            'user_id' => User::factory(),
            'agent' => 'crm-assistant',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5-20250929',
            'status' => AgentInteractionStatus::Completed,
            'request' => fake()->sentence(),
            'response' => fake()->paragraph(),
            'tool_calls' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'error_summary' => null,
            'started_at' => $startedAt,
            'completed_at' => $startedAt->copy()->addSeconds(2),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentInteractionStatus::Failed,
            'response' => null,
            'error_summary' => 'The assistant provider was unavailable.',
        ]);
    }
}
