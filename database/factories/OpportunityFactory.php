<?php

namespace Database\Factories;

use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'contact_id' => null,
            'lead_id' => null,
            'owner_id' => User::factory(),
            'team_id' => null,
            'name' => fake()->catchPhrase(),
            'stage' => OpportunityStage::Qualification,
            'value' => fake()->randomFloat(2, 1000, 100000),
            'currency' => 'USD',
            'probability' => 20,
            'expected_close_date' => fake()->dateTimeBetween('now', '+3 months'),
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

    public function stage(OpportunityStage $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => $stage,
            'probability' => match ($stage) {
                OpportunityStage::Qualification => 20,
                OpportunityStage::Proposal => 50,
                OpportunityStage::Negotiation => 75,
                OpportunityStage::ClosedWon => 100,
                OpportunityStage::ClosedLost => 0,
            },
        ]);
    }
}
