<?php

namespace Database\Factories;

use App\Enums\ProspectResearchStatus;
use App\Models\ProspectResearchRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProspectResearchRun>
 */
class ProspectResearchRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'conversation_key' => (string) Str::uuid(),
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            'message' => 'Find 3 cosmetics sellers in Cebu that sell online.',
            'status' => ProspectResearchStatus::Queued,
            'result' => null,
            'tools_used' => null,
            'agent_interaction_id' => null,
            'error_summary' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => ProspectResearchStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ProspectResearchStatus::Completed,
            'result' => 'Here are 3 prospects scored against public web evidence…',
            'tools_used' => ['discover_prospects', 'score_prospects'],
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ProspectResearchStatus::Failed,
            'result' => null,
            'error_summary' => 'Market Intelligence research could not be completed. Please try again.',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
    }
}
