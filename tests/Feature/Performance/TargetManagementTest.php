<?php

namespace Tests\Feature\Performance;

use App\Enums\TargetType;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TargetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_a_team_target(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Team->value,
            'team_id' => $team->id,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => 50000,
            'currency' => 'USD',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('targets', ['team_id' => $team->id, 'target_type' => 'team']);
    }

    public function test_manager_can_create_an_individual_target_and_team_is_derived(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Individual->value,
            'owner_id' => $member->id,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => 10000,
            'currency' => 'USD',
        ])->assertRedirect();

        $target = Target::where('owner_id', $member->id)->firstOrFail();
        $this->assertSame($team->id, $target->team_id);
    }

    public function test_target_amount_cannot_be_negative(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Team->value,
            'team_id' => $team->id,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => -500,
            'currency' => 'USD',
        ]);

        $response->assertSessionHasErrors('target_amount');
        $this->assertDatabaseCount('targets', 0);
    }

    public function test_target_period_end_cannot_precede_period_start(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Team->value,
            'team_id' => $team->id,
            'period_type' => 'monthly',
            'period_start' => '2026-01-31',
            'period_end' => '2026-01-01',
            'target_amount' => 10000,
            'currency' => 'USD',
        ]);

        $response->assertSessionHasErrors('period_end');
        $this->assertDatabaseCount('targets', 0);
    }

    public function test_duplicate_active_team_target_for_the_same_period_is_rejected(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        Target::factory()->team($team)->monthly(Carbon::parse('2026-01-15'))->create();

        $response = $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Team->value,
            'team_id' => $team->id,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => 99999,
            'currency' => 'USD',
        ]);

        $response->assertSessionHasErrors('period_start');
        $this->assertDatabaseCount('targets', 1);
    }

    public function test_a_new_active_target_is_allowed_after_the_previous_one_is_deactivated(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $existing = Target::factory()->team($team)->monthly(Carbon::parse('2026-01-15'))->create();
        $this->actingAs($manager)->delete("/targets/{$existing->id}")->assertRedirect();

        $response = $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Team->value,
            'team_id' => $team->id,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => 60000,
            'currency' => 'USD',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('targets', 2);
    }

    public function test_team_target_must_reference_an_existing_team(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Team->value,
            'team_id' => 999999,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => 10000,
            'currency' => 'USD',
        ]);

        $response->assertSessionHasErrors('team_id');
    }

    public function test_individual_target_must_reference_an_existing_user(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Individual->value,
            'owner_id' => 999999,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => 10000,
            'currency' => 'USD',
        ]);

        $response->assertSessionHasErrors('owner_id');
    }

    public function test_manager_target_must_reference_a_manager(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $notAManager = User::factory()->teamHead($team)->create();

        $response = $this->actingAs($manager)->post('/targets', [
            'target_type' => TargetType::Manager->value,
            'owner_id' => $notAManager->id,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => 200000,
            'currency' => 'USD',
        ]);

        $response->assertSessionHasErrors('owner_id');
        $this->assertDatabaseCount('targets', 0);
    }
}
