<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'industry' => fake()->randomElement(['Technology', 'Manufacturing', 'Retail', 'Healthcare', 'Finance', 'Education']),
            'website' => fake()->domainName(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state_province' => fake()->state(),
            'country' => fake()->country(),
            'status' => RecordStatus::Active,
            'source' => fake()->randomElement(['Referral', 'Website', 'Trade Show', 'Cold Outreach', null]),
            'owner_id' => null,
            'team_id' => null,
            'notes' => null,
        ];
    }

    public function forTeam(Team $team, ?User $owner = null): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
            'owner_id' => $owner?->id,
        ]);
    }
}
