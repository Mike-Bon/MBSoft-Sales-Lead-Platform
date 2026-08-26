<?php

namespace Tests\Feature\Organisation;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_a_team(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->post('/teams', [
            'name' => 'Team 07',
            'code' => 'T07',
        ]);

        $response->assertRedirect(route('organisation.teams.index'));
        $this->assertDatabaseHas('teams', ['name' => 'Team 07', 'code' => 'T07']);
    }

    public function test_manager_can_update_a_team(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($manager)->put("/teams/{$team->id}", [
            'name' => 'New Name',
            'status' => 'inactive',
        ]);

        $response->assertRedirect(route('organisation.teams.index'));
        $this->assertSame('New Name', $team->fresh()->name);
    }

    public function test_manager_can_assign_a_team_head(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $candidate = User::factory()->teamMember($team)->create();

        $response = $this->actingAs($manager)->put("/teams/{$team->id}/head", [
            'team_head_id' => $candidate->id,
        ]);

        $response->assertRedirect(route('organisation.teams.index'));

        $team->refresh();
        $candidate->refresh();

        $this->assertSame($candidate->id, $team->team_head_id);
        $this->assertSame(UserRole::TeamHead, $candidate->role);
    }

    public function test_reassigning_team_head_demotes_the_previous_head_to_team_member(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $originalHead = User::factory()->teamHead($team)->create();
        // team_head_id is deliberately excluded from Team::$fillable (see
        // Team model), so it must be set directly here rather than via
        // update()/fill() when preparing test fixtures.
        $team->team_head_id = $originalHead->id;
        $team->save();
        $newCandidate = User::factory()->teamMember($team)->create();

        $this->actingAs($manager)->put("/teams/{$team->id}/head", [
            'team_head_id' => $newCandidate->id,
        ])->assertRedirect(route('organisation.teams.index'));

        $team->refresh();
        $originalHead->refresh();
        $newCandidate->refresh();

        $this->assertSame($newCandidate->id, $team->team_head_id);
        $this->assertSame(UserRole::TeamHead, $newCandidate->role);

        // The outgoing head is not orphaned: they remain a Team Member of
        // the same team rather than being silently removed.
        $this->assertSame(UserRole::TeamMember, $originalHead->role);
        $this->assertSame($team->id, $originalHead->team_id);
    }
}
