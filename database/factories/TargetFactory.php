<?php

namespace Database\Factories;

use App\Enums\TargetPeriodType;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Target>
 */
class TargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->startOfMonth();

        return [
            'target_type' => TargetType::Individual,
            'owner_id' => User::factory(),
            'team_id' => null,
            'period_type' => TargetPeriodType::Monthly,
            'period_start' => $start,
            'period_end' => $start->copy()->endOfMonth(),
            'target_amount' => 100000,
            'currency' => 'USD',
            'status' => TargetStatus::Active,
            'notes' => null,
        ];
    }

    public function manager(User $manager): static
    {
        return $this->state(fn (array $attributes) => [
            'target_type' => TargetType::Manager,
            'owner_id' => $manager->id,
            'team_id' => null,
        ]);
    }

    public function team(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'target_type' => TargetType::Team,
            'owner_id' => null,
            'team_id' => $team->id,
        ]);
    }

    public function individual(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'target_type' => TargetType::Individual,
            'owner_id' => $user->id,
            'team_id' => $user->team_id,
        ]);
    }

    public function monthly(?Carbon $month = null): static
    {
        $start = ($month ?? Carbon::now())->copy()->startOfMonth();

        return $this->state(fn (array $attributes) => [
            'period_type' => TargetPeriodType::Monthly,
            'period_start' => $start,
            'period_end' => $start->copy()->endOfMonth(),
        ]);
    }

    public function quarterly(?Carbon $anchor = null): static
    {
        $start = ($anchor ?? Carbon::now())->copy()->startOfQuarter();

        return $this->state(fn (array $attributes) => [
            'period_type' => TargetPeriodType::Quarterly,
            'period_start' => $start,
            'period_end' => $start->copy()->endOfQuarter(),
        ]);
    }

    public function annual(?Carbon $anchor = null): static
    {
        $start = ($anchor ?? Carbon::now())->copy()->startOfYear();

        return $this->state(fn (array $attributes) => [
            'period_type' => TargetPeriodType::Annual,
            'period_start' => $start,
            'period_end' => $start->copy()->endOfYear(),
        ]);
    }

    public function amount(float $amount): static
    {
        return $this->state(fn (array $attributes) => ['target_amount' => $amount]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => TargetStatus::Inactive]);
    }
}
