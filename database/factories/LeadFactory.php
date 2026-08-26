<?php

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'contact_id' => null,
            'owner_id' => User::factory(),
            'team_id' => null,
            'source' => fake()->randomElement(['Referral', 'Website', 'Trade Show', 'Cold Outreach']),
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'estimated_value' => fake()->randomFloat(2, 500, 50000),
            'currency' => 'USD',
            'expected_close_date' => fake()->dateTimeBetween('now', '+3 months'),
            'next_follow_up_at' => null,
            'description' => fake()->sentence(),
            'notes' => null,
        ];
    }

    public function forTeam(Team $team, ?User $owner = null): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
            'owner_id' => $owner?->id ?? User::factory()->teamMember($team),
        ]);
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_id' => $owner->id,
            'team_id' => $owner->team_id,
        ]);
    }

    public function withFollowUp(\DateTimeInterface|string $when): static
    {
        return $this->state(fn (array $attributes) => [
            'next_follow_up_at' => $when,
        ]);
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    public function priority(LeadPriority $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }
}
