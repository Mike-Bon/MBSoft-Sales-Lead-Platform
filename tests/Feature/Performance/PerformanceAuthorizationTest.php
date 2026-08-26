<?php

namespace Tests\Feature\Performance;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_access_the_performance_page(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/performance')->assertOk();
    }

    public function test_team_head_can_access_the_performance_page_for_their_own_team(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $response = $this->actingAs($head)->get('/performance');

        $response->assertOk();
        $response->assertViewHas('teams', fn ($teams) => count($teams) === 1 && $teams[0]['team']->is($team));
    }

    public function test_team_head_cannot_access_another_teams_individual_performance(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        $otherTeamMember = User::factory()->teamMember($otherTeam)->create();

        // STEP 19's exact scenario, applied to performance: guessing a
        // valid user id belonging to another team must not work by
        // directly hitting the URL.
        $this->actingAs($head)->get("/performance/users/{$otherTeamMember->id}")->assertForbidden();
    }

    public function test_team_head_can_access_their_own_team_members_individual_performance(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $member = User::factory()->teamMember($team)->create();

        $this->actingAs($head)->get("/performance/users/{$member->id}")->assertOk();
    }

    public function test_team_member_cannot_access_another_users_individual_performance(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $teammate = User::factory()->teamMember($team)->create();

        $this->actingAs($member)->get("/performance/users/{$teammate->id}")->assertForbidden();
    }

    public function test_user_cannot_manipulate_ids_to_access_another_users_performance(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $memberA = User::factory()->teamMember($team)->create();
        $memberB = User::factory()->teamMember($team)->create();

        // memberA is authorized for their own performance...
        $this->actingAs($memberA)->get("/performance/users/{$memberA->id}")->assertOk();
        // ...but not for memberB's, just by changing the id in the URL.
        $this->actingAs($memberA)->get("/performance/users/{$memberB->id}")->assertForbidden();

        // A Manager can still reach both.
        $this->actingAs($manager)->get("/performance/users/{$memberA->id}")->assertOk();
        $this->actingAs($manager)->get("/performance/users/{$memberB->id}")->assertOk();
    }

    public function test_everyone_can_view_their_own_individual_performance(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        $this->actingAs($member)->get("/performance/users/{$member->id}")->assertOk();
    }
}
