<?php

namespace Tests\Feature\Organisation;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-side, request-level authorization tests for team access and
 * isolation. Every assertion goes through a real HTTP request (never
 * Volt::test/UI helpers), matching the constitution's "test authorization
 * at the application/request level, not only through the UI" requirement.
 */
class TeamAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_view_all_teams(): void
    {
        $manager = User::factory()->manager()->create();
        $teamA = Team::factory()->create(['name' => 'Team A']);
        $teamB = Team::factory()->create(['name' => 'Team B']);

        $response = $this->actingAs($manager)->get('/teams');

        $response->assertOk();
        $response->assertSee('Team A');
        $response->assertSee('Team B');
    }

    public function test_manager_can_view_any_single_team(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $this->actingAs($manager)->get("/teams/{$team->id}")->assertOk();
    }

    public function test_team_head_can_view_their_own_team(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $this->actingAs($head)->get("/teams/{$team->id}")->assertOk();
    }

    public function test_team_head_cannot_view_another_teams_protected_information(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $this->actingAs($head)->get("/teams/{$otherTeam->id}")->assertForbidden();
    }

    public function test_team_head_cannot_list_all_teams(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $this->actingAs($head)->get('/teams')->assertForbidden();
    }

    public function test_team_member_cannot_view_a_team_management_page(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        // Team Members get their team context via /profile, not a full
        // team+roster management page.
        $this->actingAs($member)->get("/teams/{$team->id}")->assertForbidden();
        $this->actingAs($member)->get('/teams')->assertForbidden();
    }

    public function test_only_manager_can_create_a_team(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $this->actingAs($head)->get('/teams/create')->assertForbidden();
        $this->actingAs($head)->post('/teams', ['name' => 'New Team'])->assertForbidden();

        $this->assertDatabaseMissing('teams', ['name' => 'New Team']);
    }

    public function test_team_head_cannot_update_any_team(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $response = $this->actingAs($head)->put("/teams/{$team->id}", [
            'name' => 'Renamed By Head',
            'status' => 'active',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('teams', ['name' => 'Renamed By Head']);
    }

    public function test_team_head_cannot_assign_a_team_head(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $otherMember = User::factory()->teamMember($team)->create();

        $response = $this->actingAs($head)->put("/teams/{$team->id}/head", [
            'team_head_id' => $otherMember->id,
        ]);

        $response->assertForbidden();
    }
}
