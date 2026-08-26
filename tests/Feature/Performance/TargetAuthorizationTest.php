<?php

namespace Tests\Feature\Performance;

use App\Enums\TargetType;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_head_cannot_create_a_target(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $this->actingAs($head)->get('/targets/create')->assertForbidden();
        $this->actingAs($head)->post('/targets', [
            'target_type' => TargetType::Team->value,
            'team_id' => $team->id,
            'period_type' => 'monthly',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'target_amount' => 10000,
            'currency' => 'USD',
        ])->assertForbidden();

        $this->assertDatabaseCount('targets', 0);
    }

    public function test_team_member_cannot_create_or_modify_targets(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $target = Target::factory()->team($team)->create();

        $this->actingAs($member)->post('/targets', [
            'target_type' => TargetType::Team->value,
            'team_id' => $team->id,
            'period_type' => 'monthly',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'target_amount' => 10000,
            'currency' => 'USD',
        ])->assertForbidden();

        $this->actingAs($member)->get("/targets/{$target->id}/edit")->assertForbidden();
        $this->actingAs($member)->put("/targets/{$target->id}", ['target_amount' => 1])->assertForbidden();
    }

    public function test_team_head_cannot_manipulate_another_teams_target(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        $otherTeamTarget = Target::factory()->team($otherTeam)->create();

        $this->actingAs($head)->get("/targets/{$otherTeamTarget->id}")->assertForbidden();
        $this->actingAs($head)->get("/targets/{$otherTeamTarget->id}/edit")->assertForbidden();
        $this->actingAs($head)->put("/targets/{$otherTeamTarget->id}", ['target_amount' => 1])->assertForbidden();
    }

    public function test_team_head_can_view_their_own_teams_target(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $target = Target::factory()->team($team)->create();

        $this->actingAs($head)->get("/targets/{$target->id}")->assertOk();
    }

    public function test_team_head_can_view_their_team_members_individual_target(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $member = User::factory()->teamMember($team)->create();
        $target = Target::factory()->individual($member)->create();

        $this->actingAs($head)->get("/targets/{$target->id}")->assertOk();
    }

    public function test_team_member_cannot_view_another_members_individual_target(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $teammate = User::factory()->teamMember($team)->create();
        $target = Target::factory()->individual($teammate)->create();

        $this->actingAs($member)->get("/targets/{$target->id}")->assertForbidden();
    }

    public function test_manager_only_target_is_not_visible_to_non_managers(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $target = Target::factory()->manager($manager)->create();

        $this->actingAs($head)->get("/targets/{$target->id}")->assertForbidden();
    }

    public function test_manager_can_update_any_target(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $target = Target::factory()->team($team)->create();

        $response = $this->actingAs($manager)->put("/targets/{$target->id}", [
            'target_type' => TargetType::Team->value,
            'team_id' => $team->id,
            'period_type' => 'monthly',
            'period_start' => $target->period_start->format('Y-m-d'),
            'period_end' => $target->period_end->format('Y-m-d'),
            'target_amount' => 77777,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $this->assertSame('77777.00', $target->fresh()->target_amount);
    }
}
